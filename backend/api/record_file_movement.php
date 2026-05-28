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

$existsStmt = $conn->prepare("SELECT regNo FROM tb_fileregistry WHERE regNo = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1");
if (!$existsStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to validate file number']);
    exit;
}
$existsStmt->bind_param("s", $regNo);
$existsStmt->execute();
$exists = $existsStmt->get_result()->fetch_assoc();
$existsStmt->close();

if (!$exists) {
    echo json_encode(['success' => false, 'message' => 'File number does not exist in pension file registry']);
    exit;
}

try {
    $conn->begin_transaction();

    $fromOffice = resolveCurrentFileHolderOffice($conn, $regNo);
    $latestOpen = getLatestOpenFileMovement($conn, $regNo, true);
    if ($latestOpen) {
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
        false
    );

    if (!$movementId) {
        throw new RuntimeException('Failed to record movement.');
    }

    $availabilityStatus = 'out_of_shelf';
    $availabilityReason = $reason !== '' ? $reason : 'Moved to ' . $toOffice;
    $updateStmt = $conn->prepare("
        UPDATE tb_fileregistry
        SET availability_status = ?, availability_reason = ?
        WHERE regNo = ?
    ");
    if (!$updateStmt) {
        throw new RuntimeException('Unable to update registry availability.');
    }
    $updateStmt->bind_param("sss", $availabilityStatus, $availabilityReason, $regNo);
    $updateStmt->execute();
    $updateStmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Movement recorded',
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
