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
ensureTaskCommentsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$taskId = (int)($payload['taskId'] ?? 0);
$note = trim((string)($payload['note'] ?? ''));
$nextAssignedTo = trim((string)($payload['next_assigned_to'] ?? ''));
$nextPriority = strtolower(trim((string)($payload['next_priority'] ?? 'normal')));
if (!in_array($nextPriority, ['low', 'normal', 'high', 'urgent'], true)) {
    $nextPriority = 'normal';
}

if ($taskId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid task selected.']);
    exit;
}

$task = getWorkflowTaskById($conn, $taskId);
if (!$task) {
    echo json_encode(['success' => false, 'message' => 'Task not found.']);
    exit;
}

$actorUserId = (string)$_SESSION['userId'];
$actorUserRole = (string)($_SESSION['userRole'] ?? '');

if (!canActorManageWorkflowTask($task, $actorUserId, $actorUserRole)) {
    echo json_encode(['success' => false, 'message' => 'You are not allowed to queue this task.']);
    exit;
}

$currentStatus = strtolower((string)($task['status'] ?? ''));
if ($currentStatus !== 'in_progress') {
    echo json_encode(['success' => false, 'message' => 'Only in-progress tasks can be queued for completion.']);
    exit;
}

$requiredRole = getWorkflowTaskRequiredAssignmentRole((string)($task['task_type'] ?? ''), $task['_metadata_array'] ?? []);
$nextAssignedRole = '';
if ($nextAssignedTo !== '') {
    $nextAssignedRole = normalizeWorkflowRoleKey((string)(getWorkflowUserRoleById($conn, $nextAssignedTo) ?? ''));
    if ($nextAssignedRole === 'pensioner') {
        echo json_encode(['success' => false, 'message' => 'Pensioner accounts cannot be assigned workflow tasks.']);
        exit;
    }
}

if ($requiredRole !== null && $nextAssignedTo === '') {
    echo json_encode(['success' => false, 'message' => 'Select the next assignee before queueing this task.']);
    exit;
}

if ($requiredRole !== null && $nextAssignedRole !== '' && !rolesAreWorkflowEquivalent($nextAssignedRole, $requiredRole)) {
    echo json_encode(['success' => false, 'message' => 'Selected assignee does not have the permitted role for this workflow step.']);
    exit;
}

$snapshotTitle = trim((string)($task['task_title'] ?? ''));
$snapshotType = trim((string)($task['task_type'] ?? ''));
$snapshotRegNo = trim((string)($task['related_reg_no'] ?? ''));

$existingStmt = $conn->prepare("
    SELECT queue_id
    FROM tb_task_completion_queue
    WHERE owner_user_id = ?
      AND task_id = ?
      AND queue_status IN ('queued','failed')
    LIMIT 1
");
$existingStmt->bind_param("si", $actorUserId, $taskId);
$existingStmt->execute();
$existingRow = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

if ($existingRow) {
    $queueId = (int)($existingRow['queue_id'] ?? 0);
    $updateStmt = $conn->prepare("
        UPDATE tb_task_completion_queue
        SET owner_role = ?,
            task_type = ?,
            task_title = ?,
            related_reg_no = ?,
            required_assignment_role = ?,
            next_assigned_to = ?,
            next_assigned_role = ?,
            next_priority = ?,
            action_note = ?,
            queue_status = 'queued',
            processed_task_id = NULL,
            last_error = NULL,
            processed_at = NULL,
            updated_at = NOW()
        WHERE queue_id = ?
    ");
    if (!$updateStmt) {
        echo json_encode(['success' => false, 'message' => 'Unable to update queued task.']);
        exit;
    }
    $updateStmt->bind_param(
        "sssssssssi",
        $actorUserRole,
        $snapshotType,
        $snapshotTitle,
        $snapshotRegNo,
        $requiredRole,
        $nextAssignedTo,
        $nextAssignedRole,
        $nextPriority,
        $note,
        $queueId
    );
    $updateStmt->execute();
    $updateStmt->close();
} else {
    $insertStmt = $conn->prepare("
        INSERT INTO tb_task_completion_queue (
            owner_user_id,
            owner_role,
            task_id,
            task_type,
            task_title,
            related_reg_no,
            required_assignment_role,
            next_assigned_to,
            next_assigned_role,
            next_priority,
            action_note,
            queue_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'queued')
    ");
    if (!$insertStmt) {
        echo json_encode(['success' => false, 'message' => 'Unable to queue task completion.']);
        exit;
    }
    $insertStmt->bind_param(
        "ssissssssss",
        $actorUserId,
        $actorUserRole,
        $taskId,
        $snapshotType,
        $snapshotTitle,
        $snapshotRegNo,
        $requiredRole,
        $nextAssignedTo,
        $nextAssignedRole,
        $nextPriority,
        $note
    );
    $insertStmt->execute();
    $queueId = (int)$insertStmt->insert_id;
    $insertStmt->close();
}

logAuditEvent($conn, [
    'actor_id' => $actorUserId,
    'actor_name' => $_SESSION['userName'] ?? 'User',
    'actor_role' => $actorUserRole,
    'action' => 'task_completion_queued',
    'entity_type' => 'workflow_task_queue',
    'entity_id' => (string)$queueId,
    'details' => [
        'task_id' => $taskId,
        'task_type' => $snapshotType,
        'related_reg_no' => $snapshotRegNo,
        'next_assigned_to' => $nextAssignedTo,
        'required_assignment_role' => $requiredRole,
        'priority' => $nextPriority
    ]
]);

if (function_exists('recordWorkflowLog')) {
    recordWorkflowLog($conn, [
        'task_id' => $taskId,
        'staffdue_id' => (int)($task['related_staff_id'] ?? 0),
        'regNo' => (string)($task['related_reg_no'] ?? ''),
        'action' => 'task_completion_queued',
        'from_status' => (string)($task['status'] ?? ''),
        'to_status' => 'queued',
        'actor_id' => $actorUserId,
        'actor_name' => $_SESSION['userName'] ?? 'User',
        'actor_role' => $actorUserRole,
        'note' => $note,
        'metadata' => [
            'queue_id' => $queueId,
            'next_assigned_to' => $nextAssignedTo,
            'required_assignment_role' => $requiredRole,
            'priority' => $nextPriority
        ]
    ]);
}

echo json_encode(['success' => true, 'message' => 'Task queued for batch forwarding.', 'queue_id' => $queueId]);
$conn->close();
?>
