<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if (!currentUserHasPermission($conn, 'registry.delete_queue.process')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureRegistryRecycleBinTable')) {
    ensureRegistryRecycleBinTable($conn);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$recycleId = (int)($payload['recycle_id'] ?? 0);
if ($recycleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid recycle bin reference']);
    exit;
}

$restoredBy = (string)($_SESSION['userId'] ?? '');
$restoredByName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown');
$restoredByRole = $role;

$conn->begin_transaction();
try {
    $result = restoreRegistryRecordFromRecycleBin($conn, $recycleId, $restoredBy, $restoredByName, $restoredByRole);
    if (empty($result['success'])) {
        throw new RuntimeException($result['message'] ?? 'Failed to restore registry record');
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $restoredBy,
            'actor_name' => $restoredByName,
            'actor_role' => $restoredByRole,
            'action' => 'registry_restored_from_recycle_bin',
            'entity_type' => 'tb_fileregistry',
            'entity_id' => (string)($result['regNo'] ?? ''),
            'details' => [
                'recycle_id' => $recycleId,
                'regNo' => $result['regNo'] ?? ''
            ]
        ]);
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Registry record restored successfully.',
        'regNo' => $result['regNo'] ?? ''
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
