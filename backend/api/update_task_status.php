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

ensureTasksTable($conn);
ensureApplicationQueueTable($conn);
ensureAppnStatusTrackingColumns($conn);
ensureFileMovementTables($conn);
ensureStaffDueExtendedColumns($conn);
ensureStaffDueWorkflowColumns($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$taskId = isset($payload['taskId']) ? (int)$payload['taskId'] : 0;
$action = isset($payload['action']) ? trim($payload['action']) : '';
$reason = isset($payload['reason']) ? trim($payload['reason']) : '';
$dueAt = isset($payload['due_at']) ? trim($payload['due_at']) : '';
$daysOverride = isset($payload['days']) ? (int)$payload['days'] : 0;
$nextAssignedTo = isset($payload['next_assigned_to']) ? trim($payload['next_assigned_to']) : '';
$nextPriority = strtolower(trim($payload['next_priority'] ?? 'normal'));
if (!in_array($nextPriority, ['low', 'normal', 'high', 'urgent'], true)) {
    $nextPriority = 'normal';
}

if ($taskId <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$taskStmt = $conn->prepare("
    SELECT taskId, task_type, related_staff_id, related_reg_no, metadata, assigned_to, assigned_role, created_by, status
    FROM tb_tasks
    WHERE taskId = ?
    LIMIT 1
");
if (!$taskStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load task']);
    exit;
}
$taskStmt->bind_param("i", $taskId);
$taskStmt->execute();
$taskResult = $taskStmt->get_result();
$taskData = $taskResult->fetch_assoc();
$taskStmt->close();

if (!$taskData) {
    echo json_encode(['success' => false, 'message' => 'Task not found']);
    exit;
}

$taskMetadata = [];
if (!empty($taskData['metadata'])) {
    $decodedMetadata = json_decode((string)$taskData['metadata'], true);
    if (is_array($decodedMetadata)) {
        $taskMetadata = $decodedMetadata;
    }
}

$currentTaskStatus = strtolower((string)($taskData['status'] ?? ''));
if (in_array($action, ['accept', 'resume', 'defer', 'return_to_oc', 'decline', 'complete'], true)
    && in_array($currentTaskStatus, ['completed', 'declined', 'cancelled'], true)) {
    echo json_encode(['success' => false, 'message' => 'This task is already closed.']);
    exit;
}

$currentUserId = $_SESSION['userId'];
$currentUserRole = $_SESSION['userRole'] ?? '';
$normalizedCurrentUserRole = normalizeWorkflowRoleKey($currentUserRole);
$isAdmin = in_array($normalizedCurrentUserRole, ['super_admin', 'admin'], true);

$assignedTo = $taskData['assigned_to'] ?? null;
$assignedRole = $taskData['assigned_role'] ?? null;
$pendingDelegation = is_array($taskMetadata['pending_delegation'] ?? null) ? $taskMetadata['pending_delegation'] : null;
$isDelegationRecipient = $pendingDelegation
    && trim((string)($pendingDelegation['to_user_id'] ?? '')) === $currentUserId;
$isEffectiveAssignee = (!empty($assignedTo) && $assignedTo === $currentUserId)
    || (empty($assignedTo) && !empty($assignedRole) && rolesAreWorkflowEquivalent($assignedRole, $currentUserRole));
$isAuthorizedActor = $isAdmin
    || $isEffectiveAssignee;

if (in_array($action, ['accept_delegation', 'decline_delegation'], true)) {
    if (!$isDelegationRecipient) {
        echo json_encode(['success' => false, 'message' => 'This delegation request is not assigned to you.']);
        exit;
    }

    $decisionHistory = is_array($taskMetadata['delegation_decisions'] ?? null) ? $taskMetadata['delegation_decisions'] : [];
    $decisionHistory[] = [
        'action' => $action === 'accept_delegation' ? 'accepted' : 'declined',
        'user_id' => $currentUserId,
        'user_name' => $_SESSION['userName'] ?? '',
        'note' => $reason,
        'decided_at' => date('Y-m-d H:i:s')
    ];
    $taskMetadata['delegation_decisions'] = $decisionHistory;
    unset($taskMetadata['pending_delegation']);
    $metadataJson = json_encode($taskMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($action === 'accept_delegation') {
        $dueAtValue = calculateTaskDueDateTime($conn);
        $acceptedRole = normalizeWorkflowRoleKey((string)($pendingDelegation['to_user_role'] ?? $currentUserRole));
        $acceptedPriority = strtolower(trim((string)($pendingDelegation['priority'] ?? $nextPriority)));
        if (!in_array($acceptedPriority, ['low', 'normal', 'high', 'urgent'], true)) {
            $acceptedPriority = 'normal';
        }
        $stmt = $conn->prepare("
            UPDATE tb_tasks
            SET assigned_to = ?,
                assigned_role = ?,
                status = 'in_progress',
                priority = ?,
                due_at = ?,
                assigned_at = NOW(),
                metadata = ?,
                updated_at = NOW()
            WHERE taskId = ?
        ");
        if ($stmt) {
            $stmt->bind_param("sssssi", $currentUserId, $acceptedRole, $acceptedPriority, $dueAtValue, $metadataJson, $taskId);
            $stmt->execute();
            $stmt->close();
        }
        if (function_exists('recordWorkflowLog')) {
            recordWorkflowLog($conn, [
                'task_id' => $taskId,
                'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
                'regNo' => (string)($taskData['related_reg_no'] ?? ''),
                'action' => 'task_delegation_accepted',
                'from_status' => $currentTaskStatus,
                'to_status' => 'in_progress',
                'actor_id' => $currentUserId,
                'actor_name' => $_SESSION['userName'] ?? 'System',
                'actor_role' => $_SESSION['userRole'] ?? '',
                'note' => $reason,
                'metadata' => ['delegated_by' => (string)($pendingDelegation['from_user_id'] ?? ''), 'due_at' => $dueAtValue]
            ]);
        }
        echo json_encode(['success' => true, 'message' => 'Delegation accepted. The task is now assigned to you.']);
        $conn->close();
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET metadata = ?,
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $stmt->bind_param("si", $metadataJson, $taskId);
        $stmt->execute();
        $stmt->close();
    }
    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_delegation_declined',
            'from_status' => $currentTaskStatus,
            'to_status' => $currentTaskStatus,
            'actor_id' => $currentUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'note' => $reason,
            'metadata' => ['delegated_by' => (string)($pendingDelegation['from_user_id'] ?? '')]
        ]);
    }
    echo json_encode(['success' => true, 'message' => 'Delegation declined. The original task owner remains unchanged.']);
    $conn->close();
    exit;
}

// Only the effective assignee/role actor (or admin override) can transition.
if (!$isAuthorizedActor) {
    echo json_encode(['success' => false, 'message' => 'You are not allowed to update this task.']);
    exit;
}

if (in_array($action, ['accept', 'resume', 'decline'], true) && !$isEffectiveAssignee) {
    echo json_encode(['success' => false, 'message' => 'Only the assigned task owner can start or decline this task.']);
    exit;
}

$getUserRoleById = static function (mysqli $conn, string $userId): ?string {
    if ($userId === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT userRole FROM tb_users WHERE userId = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return $row['userRole'] ?? null;
};

$createFollowUpTask = static function (
    mysqli $conn,
    array $taskData,
    array $baseMetadata,
    string $createdBy,
    string $taskType,
    string $taskTitle,
    string $taskDescription,
    string $priority,
    ?string $assignedTo,
    ?string $assignedRole
): array {
    $parentTaskId = (int)($taskData['taskId'] ?? 0);
    if ($parentTaskId <= 0) {
        return ['success' => false, 'message' => 'Invalid parent task.'];
    }

    $existingStmt = $conn->prepare("
        SELECT taskId
        FROM tb_tasks
        WHERE parent_task_id = ?
        LIMIT 1
    ");
    if ($existingStmt) {
        $existingStmt->bind_param("i", $parentTaskId);
        $existingStmt->execute();
        $existingTask = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($existingTask) {
            return ['success' => false, 'message' => 'This task has already been forwarded.'];
        }
    }

    if (!empty($assignedTo) && !empty($taskData['assigned_to']) && $assignedTo === $taskData['assigned_to']) {
        return ['success' => false, 'message' => 'Task cannot be forwarded to the same user.'];
    }

    $metadata = is_array($baseMetadata) ? $baseMetadata : [];
    $metadata['previous_task'] = (string)($taskData['task_type'] ?? '');
    $metadata['previous_task_id'] = $parentTaskId;
    $metadata['forwarded_by'] = $createdBy;

    $newTaskId = createWorkflowTask($conn, [
        'created_by' => $createdBy,
        'assigned_to' => $assignedTo,
        'assigned_role' => $assignedRole,
        'task_type' => $taskType,
        'task_title' => $taskTitle,
        'task_description' => $taskDescription,
        'status' => 'pending',
        'priority' => $priority,
        'related_staff_id' => $taskData['related_staff_id'] ?? null,
        'related_reg_no' => $taskData['related_reg_no'] ?? null,
        'parent_task_id' => $parentTaskId,
        'metadata' => $metadata
    ]);

    if (!$newTaskId) {
        return ['success' => false, 'message' => 'Unable to create follow-up task.'];
    }

    return ['success' => true, 'task_id' => (int)$newTaskId];
};

if ($action === 'accept') {
    $dueAtValue = calculateTaskDueDateTime($conn);
    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET assigned_to = ?,
            status = 'in_progress',
            due_at = ?,
            assigned_at = COALESCE(assigned_at, NOW()),
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ssi", $_SESSION['userId'], $dueAtValue, $taskId);
        $stmt->execute();
        $stmt->close();
    }
    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_accepted',
            'from_status' => $currentTaskStatus,
            'to_status' => 'in_progress',
            'actor_id' => $currentUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'metadata' => ['due_at' => $dueAtValue]
        ]);
    }
    echo json_encode(['success' => true, 'message' => 'Task accepted.']);
    $conn->close();
    exit;
}

if ($action === 'resume') {
    $dueAtValue = calculateTaskDueDateTime($conn);
    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET status = 'in_progress',
            due_at = ?,
            assigned_at = COALESCE(assigned_at, NOW()),
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $stmt->bind_param("si", $dueAtValue, $taskId);
        $stmt->execute();
        $stmt->close();
    }
    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_resumed',
            'from_status' => $currentTaskStatus,
            'to_status' => 'in_progress',
            'actor_id' => $currentUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'metadata' => ['due_at' => $dueAtValue]
        ]);
    }
    echo json_encode(['success' => true, 'message' => 'Task resumed.']);
    $conn->close();
    exit;
}

if ($action === 'defer') {
    $overrideDays = $daysOverride > 0 ? $daysOverride : null;
    $dueAtValue = $dueAt !== '' ? $dueAt : calculateTaskDueDateTime($conn, null, $overrideDays);
    $reasonValue = $reason !== '' ? $reason : 'Deferred';
    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET status = 'deferred',
            due_at = ?,
            declined_reason = ?,
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $stmt->bind_param("ssi", $dueAtValue, $reasonValue, $taskId);
        $stmt->execute();
        $stmt->close();
    }
    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_deferred',
            'from_status' => $currentTaskStatus,
            'to_status' => 'deferred',
            'actor_id' => $currentUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'note' => $reasonValue,
            'metadata' => ['due_at' => $dueAtValue]
        ]);
    }
    if (($taskData['task_type'] ?? '') === 'feedback_followup') {
        $assignerId = trim((string)($taskData['created_by'] ?? ''));
        $assignerEmail = '';
        $assignerName = '';
        if ($assignerId !== '') {
            $assignerStmt = $conn->prepare("SELECT userName, userEmail FROM tb_users WHERE userId = ? LIMIT 1");
            if ($assignerStmt) {
                $assignerStmt->bind_param("s", $assignerId);
                $assignerStmt->execute();
                $assignerRow = $assignerStmt->get_result()->fetch_assoc();
                $assignerStmt->close();
                $assignerEmail = trim((string)($assignerRow['userEmail'] ?? ''));
                $assignerName = trim((string)($assignerRow['userName'] ?? ''));
            }
        }

        if ($assignerId !== '' && $assignerId !== $currentUserId) {
            ensureTaskCommentsTable($conn);
            $comment = "Feedback task rescheduled to {$dueAtValue}.";
            if ($reasonValue !== '') {
                $comment .= " Note: {$reasonValue}";
            }
            $commentStmt = $conn->prepare("
                INSERT INTO tb_task_comments (task_id, author_id, author_name, author_role, comment)
                VALUES (?, ?, ?, ?, ?)
            ");
            if ($commentStmt) {
                $authorId = $currentUserId;
                $authorName = $_SESSION['userName'] ?? 'System';
                $authorRole = $_SESSION['userRole'] ?? '';
                $commentStmt->bind_param("issss", $taskId, $authorId, $authorName, $authorRole, $comment);
                $commentStmt->execute();
                $commentStmt->close();
            }

            if (getAppSettingBool($conn, 'notify_email_enabled', true)
                && getAppSettingBool($conn, 'feedback_email_notifications_enabled', true)
                && $assignerEmail !== '' && filter_var($assignerEmail, FILTER_VALIDATE_EMAIL)
            ) {
                $subject = 'Feedback task rescheduled';
                $message = implode("\n", [
                    'A feedback follow-up task has been rescheduled.',
                    'Task: ' . (string)($taskData['task_title'] ?? 'Feedback follow-up'),
                    'New due date: ' . $dueAtValue,
                    $reasonValue !== '' ? ('Note: ' . $reasonValue) : '',
                    $assignerName !== '' ? ('Assigner: ' . $assignerName) : ''
                ]);
                queueNotification(
                    $conn,
                    'email',
                    $assignerEmail,
                    $subject,
                    $message,
                    [
                        'source' => 'feedback_task_reschedule',
                        'task_id' => $taskId,
                        'html_body' => '<p>A feedback follow-up task has been rescheduled.</p>'
                            . '<p><strong>Task:</strong> ' . htmlspecialchars((string)($taskData['task_title'] ?? 'Feedback follow-up'), ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>New due date:</strong> ' . htmlspecialchars($dueAtValue, ENT_QUOTES, 'UTF-8') . '</p>'
                            . ($reasonValue !== '' ? '<p><strong>Note:</strong> ' . nl2br(htmlspecialchars($reasonValue, ENT_QUOTES, 'UTF-8')) . '</p>' : '')
                    ]
                );
            }
        }
    }
    echo json_encode(['success' => true, 'message' => 'Task deferred.']);
    $conn->close();
    exit;
}

if ($action === 'return_to_oc') {
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'Reason is required.']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET status = 'returned',
            declined_reason = ?,
            updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $stmt->bind_param("si", $reason, $taskId);
        $stmt->execute();
        $stmt->close();
    }

    $returnToUserId = trim((string)($taskMetadata['last_assigned_by'] ?? ''));
    if ($returnToUserId === '') {
        $returnToUserId = trim((string)($taskData['created_by'] ?? ''));
    }

    if ($returnToUserId === '' || $returnToUserId === $currentUserId) {
        echo json_encode(['success' => false, 'message' => 'Unable to determine who to return this task to.']);
        exit;
    }

    $returnToRole = $getUserRoleById($conn, $returnToUserId);
    if ($returnToRole === null) {
        echo json_encode(['success' => false, 'message' => 'Return target account is no longer available.']);
        exit;
    }

    $returnRoleKey = normalizeWorkflowRoleKey($returnToRole);
    $returnTaskType = 'review_return';
    $returnTaskTitle = 'Review Returned Task';

    $roleTaskTypeMap = [
        'writeup_officer' => ['type' => 'writeup', 'title' => 'Write-up Rework'],
        'file_creator' => ['type' => 'file_creation', 'title' => 'File Creation Rework'],
        'data_entry' => ['type' => 'data_entry', 'title' => 'Data Entry Rework'],
        'assessor' => ['type' => 'assessment', 'title' => 'Assessment Rework'],
        'auditor' => ['type' => 'audit', 'title' => 'Audit Rework'],
        'approver' => ['type' => 'approval', 'title' => 'Approval Rework']
    ];

    if (isset($roleTaskTypeMap[$returnRoleKey])) {
        $returnTaskType = $roleTaskTypeMap[$returnRoleKey]['type'];
        $returnTaskTitle = $roleTaskTypeMap[$returnRoleKey]['title'];
    }

    $followUp = $createFollowUpTask(
        $conn,
        $taskData,
        [
            'returned_from' => $taskData['task_type'] ?? '',
            'reason' => $reason,
            'return_to_user' => $returnToUserId,
            'returned_by' => $currentUserId
        ],
        $_SESSION['userId'],
        $returnTaskType,
        $returnTaskTitle,
        "Task returned for rework: {$reason}",
        $nextPriority,
        $returnToUserId,
        $returnRoleKey
    );

    if (empty($followUp['success'])) {
        echo json_encode(['success' => false, 'message' => $followUp['message'] ?? 'Unable to return task.']);
        exit;
    }

    if (!empty($taskData['related_staff_id'])) {
        $stage = $returnTaskType;
        if ($returnRoleKey === 'oc_pen') {
            $returnedFromType = (string)($taskData['task_type'] ?? '');
            if ($returnedFromType === 'writeup') {
                $stage = 'authorize_writeup';
            } elseif ($returnedFromType === 'file_creation') {
                $stage = 'authorize_file_creation';
            } elseif ($returnedFromType === 'data_entry') {
                $stage = 'authorize_data_entry';
            } else {
                $stage = 'review_return';
            }
        }
        $queueStmt = $conn->prepare("
            UPDATE tb_application_queue
            SET current_stage = ?, status = 'in_progress'
            WHERE staffdue_id = ?
        ");
        if ($queueStmt) {
            $queueStmt->bind_param("si", $stage, $taskData['related_staff_id']);
            $queueStmt->execute();
            $queueStmt->close();
        }
    }

    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_returned',
            'from_status' => $currentTaskStatus,
            'to_status' => 'returned',
            'actor_id' => $currentUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'note' => $reason,
            'metadata' => [
                'return_to' => $returnToUserId ?? null,
                'return_role' => $returnRoleKey ?? null,
                'follow_up_task_id' => $followUp['task_id'] ?? null
            ]
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Task returned to previous sender.']);
    $conn->close();
    exit;
}

if ($action === 'decline') {
    if ($reason === '') {
        echo json_encode(['success' => false, 'message' => 'Decline reason is required.']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE tb_tasks
        SET status = 'declined', declined_reason = ?, updated_at = NOW()
        WHERE taskId = ?
    ");
    if ($stmt) {
        $stmt->bind_param("si", $reason, $taskId);
        $stmt->execute();
        $stmt->close();
    }
    if (function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => (int)($taskData['related_staff_id'] ?? 0),
            'regNo' => (string)($taskData['related_reg_no'] ?? ''),
            'action' => 'task_declined',
            'from_status' => $currentTaskStatus,
            'to_status' => 'declined',
            'actor_id' => $currentUserId,
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? '',
            'note' => $reason
        ]);
    }
    echo json_encode(['success' => true, 'message' => 'Task declined.']);
    $conn->close();
    exit;
}

if ($action === 'complete') {
    $result = completeWorkflowTask(
        $conn,
        $taskData,
        $currentUserId,
        $currentUserRole,
        $reason,
        $nextAssignedTo,
        $nextPriority
    );

    echo json_encode($result);
    $conn->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unsupported action']);
$conn->close();
?>
