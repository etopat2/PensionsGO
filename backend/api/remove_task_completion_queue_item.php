<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/task_workflow_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureTaskCompletionQueueTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$queueId = (int)($payload['queue_id'] ?? 0);
if ($queueId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid queue item.']);
    exit;
}

$ownerUserId = (string)$_SESSION['userId'];
$ownerRole = (string)($_SESSION['userRole'] ?? '');

$stmt = $conn->prepare("
    UPDATE tb_task_completion_queue
    SET queue_status = 'removed',
        processed_at = NOW(),
        updated_at = NOW()
    WHERE queue_id = ?
      AND owner_user_id = ?
      AND queue_status IN ('queued','failed')
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to remove queued task.']);
    exit;
}
$stmt->bind_param("is", $queueId, $ownerUserId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if ($affected < 1) {
    echo json_encode(['success' => false, 'message' => 'Queued task not found or cannot be removed.']);
    exit;
}

logAuditEvent($conn, [
    'actor_id' => $ownerUserId,
    'actor_name' => $_SESSION['userName'] ?? 'User',
    'actor_role' => $ownerRole,
    'action' => 'task_completion_queue_removed',
    'entity_type' => 'workflow_task_queue',
    'entity_id' => (string)$queueId
]);

echo json_encode(['success' => true, 'message' => 'Queued task removed.']);
$conn->close();
?>
