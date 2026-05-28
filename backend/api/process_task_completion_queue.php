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

$ownerUserId = (string)$_SESSION['userId'];
$ownerRole = (string)($_SESSION['userRole'] ?? '');
$ownerName = (string)($_SESSION['userName'] ?? 'User');

$stmt = $conn->prepare("
    SELECT queue_id, task_id, next_assigned_to, next_priority, action_note
    FROM tb_task_completion_queue
    WHERE owner_user_id = ?
      AND queue_status = 'queued'
    ORDER BY created_at ASC, queue_id ASC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load the task queue for processing.']);
    exit;
}
$stmt->bind_param("s", $ownerUserId);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = $row;
}
$stmt->close();

if (!$items) {
    echo json_encode(['success' => true, 'message' => 'No queued tasks to process.', 'summary' => ['processed' => 0, 'failed' => 0]]);
    exit;
}

$processed = 0;
$failed = 0;
$failures = [];

foreach ($items as $item) {
    $queueId = (int)($item['queue_id'] ?? 0);
    $taskId = (int)($item['task_id'] ?? 0);
    $note = trim((string)($item['action_note'] ?? ''));
    $nextAssignedTo = trim((string)($item['next_assigned_to'] ?? ''));
    $nextPriority = trim((string)($item['next_priority'] ?? 'normal'));

    $task = getWorkflowTaskById($conn, $taskId);
    if (!$task) {
        $error = 'Task no longer exists.';
    } elseif (!canActorManageWorkflowTask($task, $ownerUserId, $ownerRole)) {
        $error = 'You are no longer allowed to process this task.';
    } elseif (strtolower((string)($task['status'] ?? '')) !== 'in_progress') {
        $error = 'Task is no longer in progress and cannot be batch completed.';
    } else {
        $conn->begin_transaction();
        try {
            $outcome = completeWorkflowTask($conn, $task, $ownerUserId, $ownerRole, $note, $nextAssignedTo, $nextPriority);
            if (empty($outcome['success'])) {
                throw new RuntimeException($outcome['message'] ?? 'Unable to complete queued task.');
            }

            if ($note !== '') {
                $commentStmt = $conn->prepare("
                    INSERT INTO tb_task_comments (task_id, author_id, author_name, author_role, comment)
                    VALUES (?, ?, ?, ?, ?)
                ");
                if ($commentStmt) {
                    $commentStmt->bind_param("issss", $taskId, $ownerUserId, $ownerName, $ownerRole, $note);
                    $commentStmt->execute();
                    $commentStmt->close();
                }
            }

            $processedTaskId = (int)($outcome['follow_up_task_id'] ?? 0);
            $queueUpdate = $conn->prepare("
                UPDATE tb_task_completion_queue
                SET queue_status = 'processed',
                    processed_task_id = ?,
                    last_error = NULL,
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE queue_id = ?
            ");
            if ($queueUpdate) {
                $queueUpdate->bind_param("ii", $processedTaskId, $queueId);
                $queueUpdate->execute();
                $queueUpdate->close();
            }

            $conn->commit();
            $processed++;
            continue;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage() ?: 'Unable to complete queued task.';
        }
    }

    $failed++;
    $failures[] = ['queue_id' => $queueId, 'task_id' => $taskId, 'message' => $error];
    $queueFail = $conn->prepare("
        UPDATE tb_task_completion_queue
        SET queue_status = 'failed',
            last_error = ?,
            updated_at = NOW()
        WHERE queue_id = ?
    ");
    if ($queueFail) {
        $queueFail->bind_param("si", $error, $queueId);
        $queueFail->execute();
        $queueFail->close();
    }
}

logAuditEvent($conn, [
    'actor_id' => $ownerUserId,
    'actor_name' => $ownerName,
    'actor_role' => $ownerRole,
    'action' => 'task_completion_queue_processed',
    'entity_type' => 'workflow_task_queue',
    'entity_id' => $ownerUserId,
    'details' => [
        'processed' => $processed,
        'failed' => $failed
    ]
]);

echo json_encode([
    'success' => true,
    'message' => $failed > 0
        ? "Processed {$processed} queued task(s). {$failed} failed and remain in the queue for review."
        : "Processed {$processed} queued task(s) successfully.",
    'summary' => [
        'processed' => $processed,
        'failed' => $failed
    ],
    'failures' => $failures
]);
$conn->close();
?>
