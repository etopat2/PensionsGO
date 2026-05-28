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

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if ($role === 'pensioner') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureStaffDueWorkflowColumns')) {
    ensureStaffDueWorkflowColumns($conn);
}
if (function_exists('ensureStaffDueSoftDeleteColumns')) {
    ensureStaffDueSoftDeleteColumns($conn);
}

try {
    ensureApplicationQueueTable($conn);
    ensureTasksTable($conn);
    $verificationEscalationDays = getStaffDueVerificationEscalationDays($conn);
    $verificationDueSoonDays = getStaffDueVerificationDueSoonDays($conn, $verificationEscalationDays);

    $appnStatusExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('queried', 'querried') THEN 'queried'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) = 'rejected' THEN 'rejected'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('in_process', 'in process', 'inprocess') THEN 'in_process'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) IN ('completed', 'approved', 'done') THEN 'completed'
            WHEN LOWER(TRIM(COALESCE(sd.appnStatus, ''))) = 'verified' THEN 'verified'
            ELSE 'pending'
        END
    ";

    $registryCompletionExpr = "
        EXISTS (
            SELECT 1
            FROM tb_fileregistry fr_done
            WHERE fr_done.regNo = sd.regNo
              AND COALESCE(fr_done.is_deleted, 0) = 0
        )
    ";

    $workflowStateExpr = "
        CASE
            WHEN {$registryCompletionExpr}
                OR {$appnStatusExpr} = 'completed'
                OR COALESCE(q.status, '') = 'completed'
                OR (
                    q.queue_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM tb_tasks t_done
                        WHERE t_done.related_staff_id = sd.id
                          AND t_done.task_type = 'approval'
                          AND t_done.status = 'completed'
                    )
                )
                THEN 'completed'
            WHEN {$appnStatusExpr} = 'in_process'
                OR COALESCE(q.status, '') IN ('submitted_to_oc', 'in_progress')
                OR (
                    q.queue_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM tb_tasks t_live
                        WHERE t_live.related_staff_id = sd.id
                          AND t_live.status IN ('pending', 'assigned', 'in_progress', 'deferred', 'returned')
                    )
                )
                THEN 'in_process'
            ELSE ''
        END
    ";

    $workflowInitiationExpr = "
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.submissionStatus, ''))) <> 'submitted' THEN 'not_submitted'
            WHEN {$appnStatusExpr} <> 'pending' THEN 'initiated'
            WHEN sd.submission_at IS NULL THEN 'pending'
            WHEN sd.submission_at <= DATE_SUB(NOW(), INTERVAL {$verificationEscalationDays} DAY) THEN 'escalated'
            WHEN sd.submission_at <= DATE_SUB(NOW(), INTERVAL {$verificationDueSoonDays} DAY) THEN 'due_soon'
            ELSE 'pending'
        END
    ";

    $sql = "
        SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(gender, ''))) = 'male' THEN 1 ELSE 0 END) AS male_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(gender, ''))) = 'female' THEN 1 ELSE 0 END) AS female_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) = 'submitted' THEN 1 ELSE 0 END) AS submitted_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) <> 'submitted' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) = 'submitted'
                      AND LOWER(TRIM(COALESCE(gender, ''))) = 'male' THEN 1 ELSE 0 END) AS submitted_male_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) = 'submitted'
                      AND LOWER(TRIM(COALESCE(gender, ''))) = 'female' THEN 1 ELSE 0 END) AS submitted_female_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) <> 'submitted'
                      AND LOWER(TRIM(COALESCE(gender, ''))) = 'male' THEN 1 ELSE 0 END) AS pending_male_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) <> 'submitted'
                      AND LOWER(TRIM(COALESCE(gender, ''))) = 'female' THEN 1 ELSE 0 END) AS pending_female_count,
            SUM(CASE WHEN {$appnStatusExpr} = 'verified' THEN 1 ELSE 0 END) AS verified_count,
            SUM(CASE WHEN {$appnStatusExpr} = 'queried' THEN 1 ELSE 0 END) AS queried_count,
            SUM(CASE WHEN {$appnStatusExpr} = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
            SUM(CASE WHEN {$workflowStateExpr} = 'in_process' THEN 1 ELSE 0 END) AS in_process_count,
            SUM(CASE WHEN {$workflowStateExpr} = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN {$workflowInitiationExpr} = 'initiated' THEN 1 ELSE 0 END) AS initiated_count,
            SUM(CASE WHEN {$workflowInitiationExpr} = 'pending' THEN 1 ELSE 0 END) AS awaiting_verification_count,
            SUM(CASE WHEN {$workflowInitiationExpr} = 'due_soon' THEN 1 ELSE 0 END) AS verification_due_soon_count,
            SUM(CASE WHEN {$workflowInitiationExpr} = 'escalated' THEN 1 ELSE 0 END) AS verification_escalated_count
        FROM tb_staffdue sd
        LEFT JOIN tb_application_queue q
          ON q.staffdue_id = sd.id
        WHERE COALESCE(sd.is_deleted, 0) = 0
    ";

    $result = $conn->query($sql);
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Failed to load staff due summary']);
        exit;
    }

    $row = $result->fetch_assoc() ?: [];
    $result->free();

    echo json_encode([
        'success' => true,
        'summary' => [
            'total' => (int)($row['total_count'] ?? 0),
            'male' => (int)($row['male_count'] ?? 0),
            'female' => (int)($row['female_count'] ?? 0),
            'submitted' => (int)($row['submitted_count'] ?? 0),
            'notSubmitted' => (int)($row['pending_count'] ?? 0),
            'submittedMale' => (int)($row['submitted_male_count'] ?? 0),
            'submittedFemale' => (int)($row['submitted_female_count'] ?? 0),
            'notSubmittedMale' => (int)($row['pending_male_count'] ?? 0),
            'notSubmittedFemale' => (int)($row['pending_female_count'] ?? 0),
            'verified' => (int)($row['verified_count'] ?? 0),
            'queried' => (int)($row['queried_count'] ?? 0),
            'rejected' => (int)($row['rejected_count'] ?? 0),
            'inProcess' => (int)($row['in_process_count'] ?? 0),
            'completed' => (int)($row['completed_count'] ?? 0),
            'verificationStarted' => (int)($row['initiated_count'] ?? 0),
            'awaitingVerification' => (int)($row['awaiting_verification_count'] ?? 0),
            'verificationDueSoon' => (int)($row['verification_due_soon_count'] ?? 0),
            'verificationEscalated' => (int)($row['verification_escalated_count'] ?? 0),
            'verificationEscalationWindowDays' => $verificationEscalationDays,
            'verificationDueSoonWindowDays' => $verificationDueSoonDays
        ]
    ]);
} catch (Throwable $e) {
    error_log('get_staff_due_summary error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while loading staff due summary']);
}

$conn->close();
?>
