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

if (!currentUserHasPermission($conn, 'staff_due.delete_queue.process')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureStaffDueDeleteQueueTable($conn);
ensureStaffDueSoftDeleteColumns($conn);
ensureApplicationQueueTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$requestId = (int)($payload['request_id'] ?? 0);
$action = strtolower(trim((string)($payload['action'] ?? '')));
$note = trim((string)($payload['note'] ?? ''));
if ($requestId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}
if ($action === 'reject' && $note === '') {
    echo json_encode(['success' => false, 'message' => 'Rejection note is required']);
    exit;
}

$processorId = (string)($_SESSION['userId'] ?? '');
$processorName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown');
$processorRole = strtolower((string)($_SESSION['userRole'] ?? ''));

$conn->begin_transaction();

try {
    $selectStmt = $conn->prepare("
        SELECT request_id, staffdue_id, regNo, reason, status
        FROM tb_staff_due_delete_requests
        WHERE request_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    if (!$selectStmt) {
        throw new RuntimeException('Failed to prepare request query');
    }
    $selectStmt->bind_param('i', $requestId);
    $selectStmt->execute();
    $request = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();

    if (!$request) {
        throw new RuntimeException('Delete request not found');
    }
    if (($request['status'] ?? '') !== 'pending') {
        throw new RuntimeException('This delete request has already been processed');
    }

    $staffId = (int)($request['staffdue_id'] ?? 0);
    $updateStatus = $action === 'approve' ? 'approved' : 'rejected';
    $message = $action === 'approve' ? 'Delete request approved and record removed' : 'Delete request rejected';

    if ($action === 'approve') {
        $deleteResult = softDeleteStaffDueRecord(
            $conn,
            $staffId,
            $processorId,
            $processorName,
            $processorRole,
            (string)($request['reason'] ?? '')
        );
        if (empty($deleteResult['success'])) {
            throw new RuntimeException($deleteResult['message'] ?? 'Failed to delete staff due record');
        }
    }

    $updateStmt = $conn->prepare("
        UPDATE tb_staff_due_delete_requests
        SET status = ?,
            processed_by = ?,
            processed_by_name = ?,
            processed_by_role = ?,
            processed_note = ?,
            processed_at = NOW()
        WHERE request_id = ?
    ");
    if (!$updateStmt) {
        throw new RuntimeException('Failed to prepare status update query');
    }
    $updateStmt->bind_param('sssssi', $updateStatus, $processorId, $processorName, $processorRole, $note, $requestId);
    if (!$updateStmt->execute()) {
        $error = $updateStmt->error;
        $updateStmt->close();
        throw new RuntimeException($error ?: 'Failed to update delete request');
    }
    $updateStmt->close();

    logAuditEvent($conn, [
        'actor_id' => $processorId,
        'actor_name' => $processorName,
        'actor_role' => $processorRole,
        'action' => $action === 'approve' ? 'staff_due_delete_approved' : 'staff_due_delete_rejected',
        'entity_type' => 'tb_staffdue',
        'entity_id' => (string)$staffId,
        'details' => [
            'request_id' => $requestId,
            'regNo' => (string)($request['regNo'] ?? ''),
            'reason' => (string)($request['reason'] ?? ''),
            'note' => $note
        ]
    ]);

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => $message
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
