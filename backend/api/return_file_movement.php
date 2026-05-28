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

if (!currentUserHasPermission($conn, 'file_movement.return')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureFileMovementTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$movementId = (int)($payload['movement_id'] ?? 0);
$regNo = trim((string)($payload['regNo'] ?? ''));
$receiver = trim((string)($payload['received_by'] ?? ($_SESSION['userName'] ?? $_SESSION['userId'])));

if ($movementId <= 0 || $regNo === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid movement reference']);
    exit;
}

try {
    $conn->begin_transaction();

    $latestOpen = getLatestOpenFileMovement($conn, $regNo, true);
    if (!$latestOpen || (int)$latestOpen['movement_id'] !== $movementId) {
        throw new RuntimeException('Only the latest out movement can be returned.');
    }

    if (!closeFileMovementLeg($conn, $movementId)) {
        throw new RuntimeException('Movement was not updated.');
    }

    $lastHolderOffice = trim((string)($latestOpen['to_office'] ?? ''));
    if ($lastHolderOffice === '') {
        $lastHolderOffice = 'Current Holder';
    }

    $deliveredBy = trim((string)($latestOpen['received_by'] ?? ''));
    if ($deliveredBy === '') {
        $deliveredBy = $lastHolderOffice;
    }

    if (strcasecmp($lastHolderOffice, 'Registry') !== 0) {
        $registryMovementId = recordFileMovementLeg(
            $conn,
            $regNo,
            $lastHolderOffice,
            'Registry',
            'Returned to Registry for custody',
            $deliveredBy,
            $receiver,
            null,
            true
        );

        if (!$registryMovementId) {
            throw new RuntimeException('Unable to record registry return movement.');
        }
    }

    $availabilityStatus = 'in_shelf';
    $availabilityReason = 'Returned to Registry for custody';
    $registryStmt = $conn->prepare("
        UPDATE tb_fileregistry
        SET availability_status = ?, availability_reason = ?
        WHERE regNo = ?
    ");
    if (!$registryStmt) {
        throw new RuntimeException('Unable to update registry availability.');
    }
    $registryStmt->bind_param("sss", $availabilityStatus, $availabilityReason, $regNo);
    $registryStmt->execute();
    $registryStmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'File received into registry custody.',
        'receiver' => $receiver
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ]);
}

$conn->close();
?>
