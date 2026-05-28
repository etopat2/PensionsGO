<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
        throw new RuntimeException('Authentication required');
    }

    ensureFeedbackWorkflowTables($conn);

    $submissionId = (int)($_GET['submission_id'] ?? 0);
    if ($submissionId <= 0) {
        throw new RuntimeException('Invalid feedback submission.');
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM tb_feedback_submissions
        WHERE submission_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to load feedback submission.');
    }
    $stmt->bind_param("i", $submissionId);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$submission) {
        throw new RuntimeException('Feedback submission was not found.');
    }

    $actorId = (string)($_SESSION['userId'] ?? '');
    $canView = currentUserHasPermission($conn, 'feedback.view');
    $canManage = currentUserHasPermission($conn, 'feedback.manage');
    $isAssigned = $actorId !== '' && trim((string)($submission['assigned_to_user_id'] ?? '')) === $actorId;
    if (!$canView && !$isAssigned) {
        throw new RuntimeException('Access denied');
    }

    $activity = [];
    $activityStmt = $conn->prepare("
        SELECT activity_id, action, actor_id, actor_name, actor_role, from_status, to_status, note, field_changes, created_at
        FROM tb_feedback_activity
        WHERE submission_id = ?
        ORDER BY created_at DESC, activity_id DESC
    ");
    if ($activityStmt) {
        $activityStmt->bind_param("i", $submissionId);
        $activityStmt->execute();
        $result = $activityStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $changes = [];
            if (!empty($row['field_changes'])) {
                $decoded = json_decode((string)$row['field_changes'], true);
                if (is_array($decoded)) {
                    $changes = $decoded;
                }
            }
            $activity[] = [
                'activity_id' => (int)($row['activity_id'] ?? 0),
                'action' => (string)($row['action'] ?? ''),
                'actor_name' => (string)($row['actor_name'] ?? ''),
                'actor_role' => (string)($row['actor_role'] ?? ''),
                'actor_role_label' => formatRoleLabel($conn, normalizeRoleKey((string)($row['actor_role'] ?? ''))),
                'from_status' => (string)($row['from_status'] ?? ''),
                'from_status_label' => getFeedbackStatusLabel((string)($row['from_status'] ?? '')),
                'to_status' => (string)($row['to_status'] ?? ''),
                'to_status_label' => getFeedbackStatusLabel((string)($row['to_status'] ?? '')),
                'note' => (string)($row['note'] ?? ''),
                'field_changes' => $changes,
                'created_at' => (string)($row['created_at'] ?? '')
            ];
        }
        $activityStmt->close();
    }

    $slaDays = max(1, getAppSettingInt($conn, 'feedback_response_sla_days', 5));
    $submittedAt = strtotime((string)($submission['submitted_at'] ?? '')) ?: time();
    $dueAt = strtotime('+' . $slaDays . ' days', $submittedAt);

    echo json_encode([
        'success' => true,
        'permissions' => [
            'can_manage' => $canManage
        ],
        'config' => [
            'sla_days' => $slaDays,
            'allow_assignment' => getAppSettingBool($conn, 'feedback_allow_assignment', true)
        ],
        'submission' => [
            'submission_id' => (int)($submission['submission_id'] ?? 0),
            'reference_no' => (string)($submission['reference_no'] ?? ''),
            'feedback_type' => (string)($submission['feedback_type'] ?? ''),
            'feedback_type_label' => ucwords(str_replace('_', ' ', (string)($submission['feedback_type'] ?? ''))),
            'audience' => (string)($submission['audience'] ?? ''),
            'audience_label' => getFeedbackAudienceLabel((string)($submission['audience'] ?? '')),
            'full_name' => (string)($submission['full_name'] ?? ''),
            'email_address' => (string)($submission['email_address'] ?? ''),
            'phone_number' => (string)($submission['phone_number'] ?? ''),
            'subject' => (string)($submission['subject'] ?? ''),
            'message' => (string)($submission['message'] ?? ''),
            'page_context' => (string)($submission['page_context'] ?? ''),
            'submitted_by_user_id' => (string)($submission['submitted_by_user_id'] ?? ''),
            'submitted_by_role' => (string)($submission['submitted_by_role'] ?? ''),
            'submitted_by_role_label' => formatRoleLabel($conn, normalizeRoleKey((string)($submission['submitted_by_role'] ?? ''))),
            'status' => (string)($submission['status'] ?? 'new'),
            'status_label' => getFeedbackStatusLabel((string)($submission['status'] ?? 'new')),
            'priority' => (string)($submission['priority'] ?? 'normal'),
            'priority_label' => ucwords((string)($submission['priority'] ?? 'normal')),
            'assigned_to_user_id' => (string)($submission['assigned_to_user_id'] ?? ''),
            'assigned_to_name' => (string)($submission['assigned_to_name'] ?? ''),
            'assigned_to_role' => (string)($submission['assigned_to_role'] ?? ''),
            'assigned_to_role_label' => formatRoleLabel($conn, normalizeRoleKey((string)($submission['assigned_to_role'] ?? ''))),
            'assigned_at' => (string)($submission['assigned_at'] ?? ''),
            'submitted_at' => (string)($submission['submitted_at'] ?? ''),
            'updated_at' => (string)($submission['updated_at'] ?? ''),
            'reviewed_at' => (string)($submission['reviewed_at'] ?? ''),
            'resolved_at' => (string)($submission['resolved_at'] ?? ''),
            'closed_at' => (string)($submission['closed_at'] ?? ''),
            'resolution_summary' => (string)($submission['resolution_summary'] ?? ''),
            'due_at' => date('Y-m-d H:i:s', $dueAt),
            'is_overdue' => feedbackSubmissionRequiresAttention($submission, $slaDays)
        ],
        'activity' => $activity,
        'assignees' => $canManage ? getFeedbackManagementAssignableUsers($conn) : []
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $statusCode = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
