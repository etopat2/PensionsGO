<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

ensureTasksTable($conn);
ensureTaskCommentsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$taskId = (int)($payload['taskId'] ?? 0);
$action = trim((string)($payload['action'] ?? ''));

function resolveTaskHandlerRoleForAdmin(array $task): string {
    $assignedRole = normalizeWorkflowRoleKey((string)($task['assigned_role'] ?? ''));
    if ($assignedRole !== '') {
        return $assignedRole;
    }

    $metadata = [];
    if (!empty($task['metadata'])) {
        $decoded = json_decode((string)$task['metadata'], true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }

    $taskType = trim((string)($task['task_type'] ?? ''));
    $workflowRoleMap = [
        'authorize_writeup' => 'oc_pen',
        'writeup' => 'writeup_officer',
        'authorize_file_creation' => 'oc_pen',
        'file_creation' => 'file_creator',
        'authorize_data_entry' => 'oc_pen',
        'data_entry' => 'data_entry',
        'assessment' => 'assessor',
        'audit' => 'auditor',
        'approval' => 'approver',
        'review_return' => 'oc_pen'
    ];

    if ($taskType === 'review_return') {
        return 'oc_pen';
    }

    return $workflowRoleMap[$taskType] ?? '';
}

if ($taskId <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$taskStmt = $conn->prepare("SELECT taskId, task_type, assigned_to, assigned_role, metadata, due_at, status FROM tb_tasks WHERE taskId = ? LIMIT 1");
if (!$taskStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load task']);
    exit;
}
$taskStmt->bind_param("i", $taskId);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();

if (!$task) {
    echo json_encode(['success' => false, 'message' => 'Task not found']);
    exit;
}

$priority = strtolower(trim((string)($payload['priority'] ?? 'normal')));
if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
    $priority = 'normal';
}

$note = trim((string)($payload['note'] ?? ''));

if ($action === 'reassign' || $action === 'realign') {
    if (getAppSettingBool($conn, 'task_delegation_require_reason', true) && $note === '') {
        echo json_encode(['success' => false, 'message' => 'Provide a delegation reason before reassigning this task.']);
        exit;
    }
    $assignedTo = trim((string)($payload['assigned_to'] ?? ''));
    $assignedRole = trim((string)($payload['assigned_role'] ?? ''));
    $taskType = trim((string)($payload['task_type'] ?? ''));
    $taskTitle = trim((string)($payload['task_title'] ?? ''));
    $taskDescription = trim((string)($payload['task_description'] ?? ''));
    $dueAt = trim((string)($payload['due_at'] ?? ''));
    $status = trim((string)($payload['status'] ?? 'assigned'));

    if (!in_array($status, ['pending', 'assigned', 'in_progress', 'deferred', 'returned', 'completed', 'declined', 'cancelled'], true)) {
        $status = 'assigned';
    }

    if ($assignedTo !== '') {
        $roleStmt = $conn->prepare("SELECT userRole FROM tb_users WHERE userId = ? LIMIT 1");
        if ($roleStmt) {
            $roleStmt->bind_param("s", $assignedTo);
            $roleStmt->execute();
            $roleRow = $roleStmt->get_result()->fetch_assoc();
            $roleStmt->close();
            if (!$roleRow) {
                echo json_encode(['success' => false, 'message' => 'Selected assignee does not exist']);
                exit;
            }
            $assignedRole = strtolower((string)($roleRow['userRole'] ?? $assignedRole));
            if ($assignedRole === 'pensioner') {
                echo json_encode(['success' => false, 'message' => 'Pensioner accounts cannot be assigned workflow tasks']);
                exit;
            }
        }
    }

    $requiredRole = resolveTaskHandlerRoleForAdmin($task);
    if ($requiredRole !== '' && $assignedRole !== '' && !rolesAreWorkflowEquivalent($assignedRole, $requiredRole)) {
        echo json_encode([
            'success' => false,
            'message' => 'Selected assignee does not have the permitted role for this workflow step.'
        ]);
        exit;
    }

    if ($assignedTo === '' && $assignedRole === '') {
        echo json_encode(['success' => false, 'message' => 'Provide assigned user or assigned role']);
        exit;
    }

    $taskType = $taskType !== '' ? $taskType : ($task['task_type'] ?? null);
    $taskTitle = $taskTitle !== '' ? $taskTitle : null;
    $taskDescription = $taskDescription !== '' ? $taskDescription : null;
    $dueAtValue = $dueAt !== '' ? $dueAt : calculateTaskDueDateTime($conn);
    $assignedToValue = $assignedTo !== '' ? $assignedTo : null;
    $assignedRoleValue = $requiredRole !== '' ? $requiredRole : ($assignedRole !== '' ? $assignedRole : null);

    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET assigned_to = ?,
            assigned_role = ?,
            task_type = ?,
            task_title = COALESCE(?, task_title),
            task_description = COALESCE(?, task_description),
            status = ?,
            priority = ?,
            due_at = ?,
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to update task']);
        exit;
    }

    $stmt->bind_param(
        "ssssssssi",
        $assignedToValue,
        $assignedRoleValue,
        $taskType,
        $taskTitle,
        $taskDescription,
        $status,
        $priority,
        $dueAtValue,
        $taskId
    );
    $stmt->execute();
    $stmt->close();

    if (function_exists('recordTaskDelegationLog')) {
        recordTaskDelegationLog($conn, [
            'task_id' => $taskId,
            'from_user_id' => $_SESSION['userId'] ?? '',
            'from_user_name' => $_SESSION['userName'] ?? 'Administrator',
            'from_user_role' => $_SESSION['userRole'] ?? 'admin',
            'to_user_id' => $assignedToValue,
            'to_user_name' => '',
            'to_user_role' => $assignedRoleValue,
            'note' => $note,
            'priority' => $priority
        ]);
    }

    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'regNo' => (string)($task['related_reg_no'] ?? ''),
            'action' => 'task_reassigned',
            'from_status' => (string)($task['status'] ?? ''),
            'to_status' => $status,
            'actor_id' => $_SESSION['userId'] ?? '',
            'actor_name' => $_SESSION['userName'] ?? 'Administrator',
            'actor_role' => $_SESSION['userRole'] ?? 'admin',
            'note' => $note,
            'metadata' => [
                'assigned_to' => $assignedToValue,
                'assigned_role' => $assignedRoleValue,
                'priority' => $priority
            ]
        ]);
    }

    if (getAppSettingBool($conn, 'task_delegation_escalation_enabled', true)) {
        $dueAtValue = $task['due_at'] ?? null;
        $overdue = $dueAtValue ? (strtotime((string)$dueAtValue) !== false && strtotime((string)$dueAtValue) < time()) : false;
        if ($overdue) {
            recordSystemLog($conn, [
                'log_level' => 'warning',
                'log_category' => 'task_delegation',
                'event_code' => 'delegation_escalation',
                'message' => 'Admin reassigned an overdue task.',
                'context' => ['task_id' => $taskId, 'overdue' => $overdue],
                'actor_id' => $_SESSION['userId'] ?? null,
                'actor_name' => $_SESSION['userName'] ?? null,
                'actor_role' => $_SESSION['userRole'] ?? null
            ]);
        }
    }

    if ($note !== '') {
        $commentStmt = $conn->prepare("
            INSERT INTO tb_task_comments (task_id, author_id, author_name, author_role, comment)
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($commentStmt) {
            $authorId = $_SESSION['userId'];
            $authorName = $_SESSION['userName'] ?? 'Administrator';
            $authorRole = $_SESSION['userRole'] ?? 'admin';
            $commentStmt->bind_param("issss", $taskId, $authorId, $authorName, $authorRole, $note);
            $commentStmt->execute();
            $commentStmt->close();
        }
    }

    echo json_encode(['success' => true, 'message' => 'Task realigned successfully.']);
    $conn->close();
    exit;
}

if ($action === 'reprioritize') {
    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET priority = ?, updated_at = NOW()
        WHERE taskId = ?
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to update priority']);
        exit;
    }
    $stmt->bind_param("si", $priority, $taskId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Task priority updated.']);
    $conn->close();
    exit;
}

if ($action === 'extend_schedule') {
    $terminalStatuses = ['completed', 'declined', 'cancelled'];
    if (in_array($task['status'] ?? '', $terminalStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Cannot extend schedule for a closed task.']);
        exit;
    }

    $days = (int)($payload['days'] ?? getTaskDueBusinessDays($conn));
    if ($days <= 0) {
        $days = getTaskDueBusinessDays($conn);
    }
    if ($days > 365) {
        $days = 365;
    }

    $dueAtInput = trim((string)($payload['due_at'] ?? ''));
    if ($dueAtInput !== '') {
        $parsed = strtotime($dueAtInput);
        if ($parsed === false) {
            echo json_encode(['success' => false, 'message' => 'Invalid due date format.']);
            exit;
        }
        $newDueAt = date('Y-m-d H:i:s', $parsed);
    } else {
        $baseTs = !empty($task['due_at']) ? strtotime((string)$task['due_at']) : false;
        if ($baseTs === false) {
            $baseTs = time();
        }
        $baseDate = date('Y-m-d H:i:s', $baseTs);
        $newDueAt = addTaskBusinessDays($conn, $baseDate, $days);
    }

    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET due_at = ?,
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to extend schedule']);
        exit;
    }
    $stmt->bind_param("si", $newDueAt, $taskId);
    $stmt->execute();
    $stmt->close();

    if ($note !== '') {
        $commentStmt = $conn->prepare("
            INSERT INTO tb_task_comments (task_id, author_id, author_name, author_role, comment)
            VALUES (?, ?, ?, ?, ?)
        ");
        if ($commentStmt) {
            $authorId = $_SESSION['userId'];
            $authorName = $_SESSION['userName'] ?? 'Administrator';
            $authorRole = $_SESSION['userRole'] ?? 'admin';
            $comment = "Schedule extended: {$note}";
            $commentStmt->bind_param("issss", $taskId, $authorId, $authorName, $authorRole, $comment);
            $commentStmt->execute();
            $commentStmt->close();
        }
    }

    echo json_encode(['success' => true, 'message' => 'Task schedule extended.', 'due_at' => $newDueAt]);
    $conn->close();
    exit;
}

if ($action === 'cancel') {
    $reason = trim((string)($payload['reason'] ?? 'Cancelled by administrator'));
    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET status = 'cancelled',
            declined_reason = ?,
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to cancel task']);
        exit;
    }
    $stmt->bind_param("si", $reason, $taskId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true, 'message' => 'Task cancelled.']);
    $conn->close();
    exit;
}

if ($action === 'remove') {
    $deleteComments = $conn->prepare("DELETE FROM tb_task_comments WHERE task_id = ?");
    if ($deleteComments) {
        $deleteComments->bind_param("i", $taskId);
        $deleteComments->execute();
        $deleteComments->close();
    }

    $deleteTask = $conn->prepare("DELETE FROM tb_tasks WHERE taskId = ? LIMIT 1");
    if (!$deleteTask) {
        echo json_encode(['success' => false, 'message' => 'Failed to remove task']);
        exit;
    }
    $deleteTask->bind_param("i", $taskId);
    $deleteTask->execute();
    $affected = $deleteTask->affected_rows;
    $deleteTask->close();

    echo json_encode([
        'success' => $affected > 0,
        'message' => $affected > 0 ? 'Task removed successfully.' : 'Task was not removed.'
    ]);
    $conn->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unsupported admin action']);
$conn->close();
?>
