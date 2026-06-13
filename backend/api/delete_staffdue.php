<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
$directDeleteRoles = ['super_admin', 'admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension'];
if (!in_array($role, $directDeleteRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureStaffDueSoftDeleteColumns($conn);
ensureApplicationQueueTable($conn);
ensureStaffDueDeleteQueueTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$ids = array_values(array_filter(array_map('intval', (array)($payload['ids'] ?? [])), static fn($value) => $value > 0));
$reason = trim((string)($payload['reason'] ?? 'Direct deletion by privileged user.'));

if ($id > 0 && !in_array($id, $ids, true)) {
    $ids[] = $id;
}

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'Invalid staff record']);
    exit;
}

$deletedBy = (string)($_SESSION['userId'] ?? '');
$deletedByName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown');
$deletedByRole = $role;

$conn->begin_transaction();

try {
    $deletedCount = 0;
    $alreadyDeleted = 0;
    foreach ($ids as $staffId) {
        $pendingStmt = $conn->prepare("
            UPDATE tb_staff_due_delete_requests
            SET status = 'approved',
                processed_by = ?,
                processed_by_name = ?,
                processed_by_role = ?,
                processed_note = ?,
                processed_at = NOW()
            WHERE staffdue_id = ?
              AND status = 'pending'
        ");
        if ($pendingStmt) {
            $note = 'Direct deletion by privileged user. ' . $reason;
            $pendingStmt->bind_param('ssssi', $deletedBy, $deletedByName, $deletedByRole, $note, $staffId);
            $pendingStmt->execute();
            $pendingStmt->close();
        }

        $result = softDeleteStaffDueRecord($conn, (int)$staffId, $deletedBy, $deletedByName, $deletedByRole, $reason);
        if (empty($result['success'])) {
            throw new RuntimeException($result['message'] ?? 'Failed to delete staff due record.');
        }
        if (!empty($result['already_deleted'])) {
            $alreadyDeleted++;
            continue;
        }
        if (!empty($result['deleted'])) {
            $deletedCount++;
        }

        logAuditEvent($conn, [
            'actor_id' => $deletedBy,
            'actor_name' => $deletedByName,
            'actor_role' => $deletedByRole,
            'action' => 'staff_due_deleted',
            'entity_type' => 'tb_staffdue',
            'entity_id' => (string)$staffId,
            'details' => [
                'regNo' => (string)($result['regNo'] ?? ''),
                'reason' => $reason,
                'mode' => 'direct_privileged_delete'
            ]
        ]);
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => $deletedCount > 0
            ? ($deletedCount === 1 ? 'Record deleted.' : "{$deletedCount} records deleted.")
            : 'No record deleted.',
        'deletedCount' => $deletedCount,
        'alreadyDeleted' => $alreadyDeleted
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
