<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureTasksTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$taskId = isset($payload['taskId']) ? (int)$payload['taskId'] : 0;
$assignee = isset($payload['assigned_to']) ? trim($payload['assigned_to']) : '';
$assigneeRole = isset($payload['assigned_role']) ? trim($payload['assigned_role']) : '';
$note = isset($payload['note']) ? trim($payload['note']) : '';
$priority = strtolower(trim($payload['priority'] ?? 'normal'));
if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
    $priority = 'normal';
}
$assigneeName = '';
$assigneeEmail = '';

if ($taskId <= 0 || ($assignee === '' && $assigneeRole === '')) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (getAppSettingBool($conn, 'task_delegation_require_reason', true) && $note === '') {
    echo json_encode(['success' => false, 'message' => 'Provide a delegation reason before assigning this task.']);
    exit;
}

$currentRole = $_SESSION['userRole'] ?? '';
$normalizedCurrentRole = normalizeWorkflowRoleKey($currentRole);

$taskStmt = $conn->prepare("
    SELECT assigned_role, task_type, assigned_to, created_by, metadata, status
    FROM tb_tasks
    WHERE taskId = ?
    LIMIT 1
");
if (!$taskStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load task']);
    exit;
}
$taskStmt->bind_param("i", $taskId);
$taskStmt->execute();
$taskRow = $taskStmt->get_result()->fetch_assoc();
$taskStmt->close();
if (!$taskRow) {
    echo json_encode(['success' => false, 'message' => 'Task not found.']);
    exit;
}

$taskStatus = strtolower((string)($taskRow['status'] ?? ''));
if ($taskStatus === 'completed' || $taskStatus === 'declined' || $taskStatus === 'cancelled') {
    echo json_encode(['success' => false, 'message' => 'This task is already closed.']);
    exit;
}

$taskAssignedRole = $taskRow['assigned_role'] ?? '';
$taskAssignedTo = $taskRow['assigned_to'] ?? null;
$taskCreatedBy = $taskRow['created_by'] ?? null;
$taskMetadata = [];
if (!empty($taskRow['metadata'])) {
    $decoded = json_decode((string)$taskRow['metadata'], true);
    if (is_array($decoded)) {
        $taskMetadata = $decoded;
    }
}

if ($assignee !== '') {
    if ($taskAssignedTo !== null && $taskAssignedTo !== '' && $taskAssignedTo === $assignee) {
        echo json_encode(['success' => false, 'message' => 'Task is already assigned to this user.']);
        exit;
    }
    if ($assignee === ($_SESSION['userId'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Delegation to self is not allowed.']);
        exit;
    }
}

if ($normalizedCurrentRole !== 'admin') {
    $canDelegate = false;
    if (!empty($taskAssignedTo) && $taskAssignedTo === $_SESSION['userId']) {
        $canDelegate = true;
    } elseif (empty($taskAssignedTo) && $taskAssignedRole !== '' && rolesAreWorkflowEquivalent($taskAssignedRole, $currentRole)) {
        $canDelegate = true;
    } elseif (isOcPenEquivalentRole($normalizedCurrentRole) && $taskCreatedBy === $_SESSION['userId']) {
        $canDelegate = true;
    }

    if (!$canDelegate) {
        echo json_encode(['success' => false, 'message' => 'You are not allowed to delegate this task.']);
        exit;
    }
}

if ($assignee !== '') {
    $roleStmt = $conn->prepare("SELECT userRole, userName, userEmail FROM tb_users WHERE userId = ? LIMIT 1");
    if ($roleStmt) {
        $roleStmt->bind_param("s", $assignee);
        $roleStmt->execute();
        $roleRow = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        $assigneeRole = $roleRow['userRole'] ?? $assigneeRole;
        $assigneeName = $roleRow['userName'] ?? '';
        $assigneeEmail = $roleRow['userEmail'] ?? '';
    }
}

// Enforce delegation rules
if (!isOcPenEquivalentRole($normalizedCurrentRole) && $normalizedCurrentRole !== 'admin') {
    if ($taskAssignedRole !== '' && !rolesAreWorkflowEquivalent($taskAssignedRole, $currentRole)) {
        echo json_encode(['success' => false, 'message' => 'You cannot delegate tasks outside your role.']);
        exit;
    }
    if ($assigneeRole !== '' && !rolesAreWorkflowEquivalent($assigneeRole, $currentRole)) {
        echo json_encode(['success' => false, 'message' => 'You can only delegate to users in your role.']);
        exit;
    }
}

$assignmentHistory = [];
if (isset($taskMetadata['assignment_history']) && is_array($taskMetadata['assignment_history'])) {
    foreach ($taskMetadata['assignment_history'] as $historyUserId) {
        $value = trim((string)$historyUserId);
        if ($value !== '') {
            $assignmentHistory[$value] = true;
        }
    }
}
if (!empty($taskAssignedTo)) {
    $assignmentHistory[(string)$taskAssignedTo] = true;
}
if ($assignee !== '' && isset($assignmentHistory[$assignee])) {
    echo json_encode(['success' => false, 'message' => 'This task has already been assigned to the selected user.']);
    exit;
}

$taskMetadata['assignment_history'] = array_keys($assignmentHistory);
$taskMetadata['last_assigned_by'] = $_SESSION['userId'] ?? null;
$taskMetadata['last_assigned_at'] = date('Y-m-d H:i:s');
if ($assignee !== '') {
    $taskMetadata['assignment_history'][] = $assignee;
    $taskMetadata['assignment_history'] = array_values(array_unique(array_filter($taskMetadata['assignment_history'])));
}
$metadataJson = json_encode($taskMetadata, JSON_UNESCAPED_SLASHES);

$stmt = $conn->prepare("
    UPDATE tb_tasks
    SET assigned_to = ?,
        assigned_role = ?,
        status = 'assigned',
        priority = ?,
        due_at = ?,
        metadata = ?,
        updated_at = NOW()
    WHERE taskId = ?
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$assignedTo = $assignee !== '' ? $assignee : null;
$assignedRole = $assigneeRole !== '' ? $assigneeRole : null;
$dueAtValue = calculateTaskDueDateTime($conn);

$stmt->bind_param("sssssi", $assignedTo, $assignedRole, $priority, $dueAtValue, $metadataJson, $taskId);
$stmt->execute();
$stmt->close();

if (function_exists('recordTaskDelegationLog')) {
    recordTaskDelegationLog($conn, [
        'task_id' => $taskId,
        'from_user_id' => $_SESSION['userId'] ?? '',
        'from_user_name' => $_SESSION['userName'] ?? '',
        'from_user_role' => $_SESSION['userRole'] ?? '',
        'to_user_id' => $assignee !== '' ? $assignee : null,
        'to_user_name' => $assigneeName,
        'to_user_role' => $assigneeRole,
        'note' => $note,
        'priority' => $priority
    ]);
}

if (function_exists('recordWorkflowLog')) {
    recordWorkflowLog($conn, [
        'task_id' => $taskId,
        'staffdue_id' => (int)($taskMetadata['staffdue_id'] ?? 0),
        'regNo' => (string)($taskMetadata['reg_no'] ?? $taskMetadata['regNo'] ?? $taskRow['related_reg_no'] ?? ''),
        'action' => 'task_delegated',
        'from_status' => (string)($taskRow['status'] ?? ''),
        'to_status' => 'assigned',
        'actor_id' => $_SESSION['userId'] ?? '',
        'actor_name' => $_SESSION['userName'] ?? 'System User',
        'actor_role' => normalizeRoleKey($_SESSION['userRole'] ?? ''),
        'note' => $note,
        'metadata' => [
            'assigned_to' => $assignee,
            'assigned_role' => $assigneeRole,
            'priority' => $priority
        ]
    ]);
}

if (getAppSettingBool($conn, 'task_delegation_escalation_enabled', true)) {
    $delegationCount = is_array($taskMetadata['assignment_history'] ?? null) ? count($taskMetadata['assignment_history']) : 0;
    $dueAt = $taskRow['due_at'] ?? null;
    $overdue = $dueAt ? (strtotime((string)$dueAt) !== false && strtotime((string)$dueAt) < time()) : false;
    if ($delegationCount > 1 || $overdue) {
        recordSystemLog($conn, [
            'log_level' => 'warning',
            'log_category' => 'task_delegation',
            'event_code' => 'delegation_escalation',
            'message' => 'Delegation escalation signal recorded.',
            'context' => [
                'task_id' => $taskId,
                'overdue' => $overdue,
                'delegation_count' => $delegationCount
            ],
            'actor_id' => $_SESSION['userId'] ?? null,
            'actor_name' => $_SESSION['userName'] ?? null,
            'actor_role' => $_SESSION['userRole'] ?? null
        ]);
    }
}

if ($note !== '') {
    ensureTaskCommentsTable($conn);
    $commentStmt = $conn->prepare("
        INSERT INTO tb_task_comments (task_id, author_id, author_name, author_role, comment)
        VALUES (?, ?, ?, ?, ?)
    ");
    if ($commentStmt) {
        $authorId = $_SESSION['userId'] ?? null;
        $authorName = $_SESSION['userName'] ?? null;
        $authorRole = $_SESSION['userRole'] ?? null;
        $commentStmt->bind_param("issss", $taskId, $authorId, $authorName, $authorRole, $note);
        $commentStmt->execute();
        $commentStmt->close();
    }
}

if (($taskRow['task_type'] ?? '') === 'feedback_followup' && $assignee !== '') {
    $submissionId = (int)($taskMetadata['submission_id'] ?? 0);
    $allowFeedbackAssignment = getAppSettingBool($conn, 'feedback_allow_assignment', true);
    if ($submissionId > 0 && $allowFeedbackAssignment) {
        ensureFeedbackWorkflowTables($conn);

        $submissionStmt = $conn->prepare("SELECT * FROM tb_feedback_submissions WHERE submission_id = ? LIMIT 1");
        $submission = null;
        if ($submissionStmt) {
            $submissionStmt->bind_param("i", $submissionId);
            $submissionStmt->execute();
            $submission = $submissionStmt->get_result()->fetch_assoc() ?: null;
            $submissionStmt->close();
        }

        if ($submission) {
            $updateStmt = $conn->prepare("
                UPDATE tb_feedback_submissions
                SET assigned_to_user_id = ?,
                    assigned_to_name = ?,
                    assigned_to_role = ?,
                    assigned_at = NOW(),
                    updated_at = NOW()
                WHERE submission_id = ?
            ");
            if ($updateStmt) {
                $assignedRoleKey = normalizeRoleKey((string)($assigneeRole ?? ''));
                $updateStmt->bind_param(
                    "sssi",
                    $assignee,
                    $assigneeName,
                    $assignedRoleKey,
                    $submissionId
                );
                $updateStmt->execute();
                $updateStmt->close();
            }

            recordFeedbackActivity($conn, $submissionId, [
                'action' => 'feedback_assigned',
                'actor_id' => $_SESSION['userId'] ?? '',
                'actor_name' => $_SESSION['userName'] ?? 'System User',
                'actor_role' => normalizeRoleKey($_SESSION['userRole'] ?? ''),
                'from_status' => (string)($submission['status'] ?? ''),
                'to_status' => (string)($submission['status'] ?? ''),
                'note' => $note,
                'field_changes' => [
                    'assignment' => [
                        'from' => (string)($submission['assigned_to_user_id'] ?? ''),
                        'to' => (string)$assignee,
                        'to_name' => (string)$assigneeName
                    ]
                ]
            ]);

            logAuditEvent($conn, [
                'actor_id' => $_SESSION['userId'] ?? '',
                'actor_name' => $_SESSION['userName'] ?? 'System User',
                'actor_role' => normalizeRoleKey($_SESSION['userRole'] ?? ''),
                'action' => 'feedback_assignment_delegated',
                'entity_type' => 'feedback_submission',
                'entity_id' => (string)$submissionId,
                'details' => [
                    'reference_no' => (string)($submission['reference_no'] ?? ''),
                    'assignee' => [
                        'user_id' => (string)$assignee,
                        'user_name' => (string)$assigneeName,
                        'user_role' => normalizeRoleKey((string)($assigneeRole ?? ''))
                    ]
                ]
            ]);

            if (getAppSettingBool($conn, 'notify_email_enabled', true)
                && getAppSettingBool($conn, 'feedback_email_notifications_enabled', true)
                && !empty($assigneeEmail) && filter_var($assigneeEmail, FILTER_VALIDATE_EMAIL)
            ) {
                $subject = 'Feedback assigned: ' . (string)($submission['subject'] ?? 'Feedback submission');
                $message = implode("\n", [
                    'A feedback item has been assigned to you.',
                    'Reference: ' . (string)($submission['reference_no'] ?? ''),
                    'Subject: ' . (string)($submission['subject'] ?? ''),
                    'Priority: ' . ucfirst((string)($submission['priority'] ?? 'normal')),
                    'Audience: ' . getFeedbackAudienceLabel((string)($submission['audience'] ?? 'public')),
                    'Submitted by: ' . (string)($submission['full_name'] ?? 'Unknown submitter')
                ]);
                queueNotification(
                    $conn,
                    'email',
                    $assigneeEmail,
                    $subject,
                    $message,
                    [
                        'source' => 'feedback_assignment_delegated',
                        'submission_id' => $submissionId,
                        'reference_no' => (string)($submission['reference_no'] ?? ''),
                        'html_body' => '<p>A feedback item has been assigned to you.</p>'
                            . '<p><strong>Reference:</strong> ' . htmlspecialchars((string)($submission['reference_no'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>Subject:</strong> ' . htmlspecialchars((string)($submission['subject'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>Priority:</strong> ' . htmlspecialchars(ucfirst((string)($submission['priority'] ?? 'normal')), ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>Audience:</strong> ' . htmlspecialchars(getFeedbackAudienceLabel((string)($submission['audience'] ?? 'public')), ENT_QUOTES, 'UTF-8') . '<br>'
                            . '<strong>Submitted by:</strong> ' . htmlspecialchars((string)($submission['full_name'] ?? 'Unknown submitter'), ENT_QUOTES, 'UTF-8') . '</p>'
                    ]
                );
            }
        }
    }
}

echo json_encode(['success' => true, 'message' => 'Task delegated.']);
$conn->close();
?>
