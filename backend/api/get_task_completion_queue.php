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

$ownerUserId = (string)$_SESSION['userId'];
$items = [];
$summary = ['queued' => 0, 'failed' => 0, 'processed_recent' => 0];

$stmt = $conn->prepare("
    SELECT
        q.queue_id,
        q.task_id,
        q.task_type,
        q.task_title,
        q.related_reg_no,
        q.required_assignment_role,
        q.next_assigned_to,
        q.next_assigned_role,
        assignee.userName AS next_assigned_to_name,
        q.next_priority,
        q.action_note,
        q.queue_status,
        q.processed_task_id,
        q.last_error,
        q.created_at,
        q.updated_at,
        q.processed_at,
        t.status AS current_task_status,
        t.assigned_to AS current_assigned_to,
        t.assigned_role AS current_assigned_role
    FROM tb_task_completion_queue q
    LEFT JOIN tb_users assignee ON assignee.userId = q.next_assigned_to
    LEFT JOIN tb_tasks t ON t.taskId = q.task_id
    WHERE q.owner_user_id = ?
      AND q.queue_status IN ('queued','failed','processed')
    ORDER BY
      CASE q.queue_status
        WHEN 'queued' THEN 0
        WHEN 'failed' THEN 1
        ELSE 2
      END,
      q.created_at ASC
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load task completion queue.']);
    exit;
}

$stmt->bind_param("s", $ownerUserId);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $status = (string)($row['queue_status'] ?? 'queued');
    if ($status === 'queued') {
        $summary['queued']++;
    } elseif ($status === 'failed') {
        $summary['failed']++;
    } elseif ($status === 'processed') {
        $summary['processed_recent']++;
    }

    $items[] = [
        'queue_id' => (int)($row['queue_id'] ?? 0),
        'task_id' => (int)($row['task_id'] ?? 0),
        'task_type' => (string)($row['task_type'] ?? ''),
        'task_title' => (string)($row['task_title'] ?? ''),
        'related_reg_no' => (string)($row['related_reg_no'] ?? ''),
        'required_assignment_role' => (string)($row['required_assignment_role'] ?? ''),
        'next_assigned_to' => (string)($row['next_assigned_to'] ?? ''),
        'next_assigned_role' => (string)($row['next_assigned_role'] ?? ''),
        'next_assigned_to_name' => (string)($row['next_assigned_to_name'] ?? ''),
        'next_priority' => (string)($row['next_priority'] ?? 'normal'),
        'action_note' => (string)($row['action_note'] ?? ''),
        'queue_status' => $status,
        'processed_task_id' => (int)($row['processed_task_id'] ?? 0),
        'last_error' => (string)($row['last_error'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? ''),
        'processed_at' => (string)($row['processed_at'] ?? ''),
        'current_task_status' => (string)($row['current_task_status'] ?? ''),
        'current_assigned_to' => (string)($row['current_assigned_to'] ?? ''),
        'current_assigned_role' => (string)($row['current_assigned_role'] ?? '')
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'items' => $items
]);
$conn->close();
?>
