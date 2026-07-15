<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'file_movement.record')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureFileMovementTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$regNo = trim((string)($payload['regNo'] ?? ''));
$fileType = strtolower(trim((string)($payload['file_type'] ?? 'pension')));
$fileType = $fileType === 'service' ? 'service' : 'pension';
$sourceRegistry = trim((string)($payload['source_registry'] ?? ''));
$destinationRegistry = trim((string)($payload['destination_registry'] ?? ''));
$movementAction = strtolower(trim((string)($payload['movement_action'] ?? 'move')));
if (!in_array($movementAction, ['receive','move'], true)) $movementAction = 'move';
$toOffice = trim((string)($payload['to_office'] ?? ''));
$reason = trim((string)($payload['reason'] ?? ''));
$deliveredBy = trim((string)($payload['delivered_by'] ?? ($_SESSION['userName'] ?? $_SESSION['userId'])));
$receivedBy = trim((string)($payload['received_by'] ?? ''));
$expectedReturnAt = trim((string)($payload['expected_return_at'] ?? ''));

if ($regNo === '' || $toOffice === '') {
    echo json_encode(['success' => false, 'message' => 'File number and destination office are required.']);
    exit;
}

if ($deliveredBy === '') {
    echo json_encode(['success' => false, 'message' => 'Delivered By is required.']);
    exit;
}

$existsSql = $fileType === 'service'
    ? "SELECT pensionNo AS regNo FROM tb_service_files WHERE (pensionNo = ? OR employeeNo = ?) LIMIT 1"
    : "SELECT regNo FROM tb_fileregistry WHERE regNo = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1";
$existsStmt = $conn->prepare($existsSql);
if (!$existsStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to validate file number']);
    exit;
}
if ($fileType === 'service') $existsStmt->bind_param("ss", $regNo, $regNo); else $existsStmt->bind_param("s", $regNo);
$existsStmt->execute();
$exists = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'File number does not exist in the selected file registry']);
    exit;
}

try {
    $conn->begin_transaction();

    $registryLabels = ['pending_processing'=>'Service Files Pending Processing','still_in_process'=>'Service Files Still in Process','pension_file_registry'=>'Pension File Registry','archives'=>'Archives','staff_registry'=>'Staff Registry','external_office'=>'External Office / Individual'];
    $latestOpen = getLatestOpenFileMovement($conn, $regNo, true);
    $fromOffice = $movementAction === 'receive' ? ($registryLabels[$sourceRegistry] ?? ($sourceRegistry ?: 'Incoming Source')) : resolveCurrentFileHolderOffice($conn, $regNo);
    if ($movementAction === 'receive') $toOffice = $registryLabels[$destinationRegistry] ?? ($destinationRegistry ?: 'Registry');
    if ($latestOpen && $movementAction === 'move') {
        $fromOffice = trim((string)($latestOpen['to_office'] ?? ''));
    }
    if ($fromOffice === '') {
        $fromOffice = resolveCurrentFileHolderOffice($conn, $regNo);
    }

    if (strcasecmp($fromOffice, $toOffice) === 0) {
        throw new RuntimeException('Destination office must be different from the current file holder.');
    }

    if ($latestOpen) {
        if (!closeFileMovementLeg($conn, (int)$latestOpen['movement_id'])) {
            throw new RuntimeException('Unable to close the current movement leg.');
        }
    }

    $movementId = recordFileMovementLeg(
        $conn,
        $regNo,
        $fromOffice,
        $toOffice,
        $reason,
        $deliveredBy,
        $receivedBy,
        $expectedReturnAt !== '' ? $expectedReturnAt : null,
        $movementAction === 'receive'
    );

    if (!$movementId) {
        throw new RuntimeException('Failed to record movement.');
    }
    $direction = $movementAction === 'receive' ? 'in' : 'out';
    $movementMeta = $conn->prepare("UPDATE tb_file_movements SET file_type=?, source_registry=?, destination_registry=?, movement_direction=? WHERE movement_id=?");
    if ($movementMeta) { $movementMeta->bind_param('ssssi', $fileType, $sourceRegistry, $destinationRegistry, $direction, $movementId); $movementMeta->execute(); $movementMeta->close(); }

    $availabilityStatus = $movementAction === 'receive' ? 'in_shelf' : 'out_of_shelf';
    $availabilityReason = $reason !== '' ? $reason : 'Moved to ' . $toOffice;
    $updateStmt = $fileType === 'service' ? null : $conn->prepare("
        UPDATE tb_fileregistry
        SET availability_status = ?, availability_reason = ?
        WHERE regNo = ?
    ");
    if ($fileType === 'pension' && !$updateStmt) {
        throw new RuntimeException('Unable to update registry availability.');
    }
    if ($updateStmt) { $updateStmt->bind_param("sss", $availabilityStatus, $availabilityReason, $regNo); $updateStmt->execute(); $updateStmt->close(); }
    if ($fileType === 'service') {
        $serviceAvailability = $movementAction === 'receive' ? 'available' : 'out';
        $serviceStage = in_array($destinationRegistry, ['pending_processing','still_in_process','archives'], true) ? $destinationRegistry : null;
        $serviceUpdate=$conn->prepare("UPDATE tb_service_files SET availability_status=?, current_holder=?, registry_stage=COALESCE(?,registry_stage), updated_at=NOW() WHERE pensionNo=? OR employeeNo=?");
        $serviceUpdate->bind_param('sssss',$serviceAvailability,$toOffice,$serviceStage,$regNo,$regNo); $serviceUpdate->execute(); $serviceUpdate->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $movementAction === 'receive' ? 'File received and registered in registry custody.' : 'File movement out recorded.',
        'movement_id' => $movementId,
        'from_office' => $fromOffice,
        'to_office' => $toOffice,
        'moved_at' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $error) {
    if ($conn->errno || $conn->error) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ]);
}

$conn->close();
