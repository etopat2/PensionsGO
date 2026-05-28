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
$note = trim((string)($payload['note'] ?? ''));
$nextAssignedTo = trim((string)($payload['next_assigned_to'] ?? ''));
$nextPriority = strtolower(trim((string)($payload['next_priority'] ?? 'normal')));
if (!in_array($nextPriority, ['low', 'normal', 'high', 'urgent'], true)) {
    $nextPriority = 'normal';
}

if ($queueId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid queue item.']);
    exit;
}

$ownerUserId = (string)$_SESSION['userId'];
$ownerRole = (string)($_SESSION['userRole'] ?? '');

$itemStmt = $conn->prepare("
    SELECT queue_id, task_id, required_assignment_role, queue_status
    FROM tb_task_completion_queue
    WHERE queue_id = ?
      AND owner_user_id = ?
    LIMIT 1
");
if (!$itemStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load queued task.']);
    exit;
}
$itemStmt->bind_param("is", $queueId, $ownerUserId);
$itemStmt->execute();
$item = $itemStmt->get_result()->fetch_assoc();
$itemStmt->close();

if (!$item) {
    echo json_encode(['success' => false, 'message' => 'Queued task not found.']);
    exit;
}

$status = strtolower((string)($item['queue_status'] ?? 'queued'));
if (!in_array($status, ['queued', 'failed'], true)) {
    echo json_encode(['success' => false, 'message' => 'Only active or failed queue items can be edited.']);
    exit;
}

$requiredRole = trim((string)($item['required_assignment_role'] ?? ''));
$nextAssignedRole = '';
if ($nextAssignedTo !== '') {
    $nextAssignedRole = normalizeWorkflowRoleKey((string)(getWorkflowUserRoleById($conn, $nextAssignedTo) ?? ''));
    if ($nextAssignedRole === 'pensioner') {
        echo json_encode(['success' => false, 'message' => 'Pensioner accounts cannot be assigned workflow tasks.']);
        exit;
    }
}

if ($requiredRole !== '' && $nextAssignedTo === '') {
    echo json_encode(['success' => false, 'message' => 'This queued task requires a next assignee before processing.']);
    exit;
}

if ($requiredRole !== '' && $nextAssignedRole !== '' && !rolesAreWorkflowEquivalent($nextAssignedRole, $requiredRole)) {
    echo json_encode(['success' => false, 'message' => 'Selected assignee does not have the permitted role for this workflow step.']);
    exit;
}

$updateStmt = $conn->prepare("
    UPDATE tb_task_completion_queue
    SET next_assigned_to = ?,
        next_assigned_role = ?,
        next_priority = ?,
        action_note = ?,
        queue_status = 'queued',
        last_error = NULL,
        updated_at = NOW()
    WHERE queue_id = ?
");
if (!$updateStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to update queued task.']);
    exit;
}
$updateStmt->bind_param("ssssi", $nextAssignedTo, $nextAssignedRole, $nextPriority, $note, $queueId);
$updateStmt->execute();
$updateStmt->close();

logAuditEvent($conn, [
    'actor_id' => $ownerUserId,
    'actor_name' => $_SESSION['userName'] ?? 'User',
    'actor_role' => $ownerRole,
    'action' => 'task_completion_queue_updated',
    'entity_type' => 'workflow_task_queue',
    'entity_id' => (string)$queueId,
    'details' => [
        'task_id' => (int)($item['task_id'] ?? 0),
        'next_assigned_to' => $nextAssignedTo,
        'next_priority' => $nextPriority
    ]
]);

echo json_encode(['success' => true, 'message' => 'Queued task updated.']);
$conn->close();
?>
