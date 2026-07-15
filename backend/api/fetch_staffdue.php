<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userRole']) || $_SESSION['userRole'] === 'pensioner') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Keep this hot read path free of ALTER/SHOW maintenance work. The required
// schema and indexes are provisioned by migrations and application setup.

$search = trim((string)($_GET['search'] ?? ''));
$retirementType = trim((string)($_GET['retirementType'] ?? ''));
$submissionStatus = strtolower(trim((string)($_GET['submissionStatus'] ?? '')));
$appnStatus = strtolower(trim((string)($_GET['appnStatus'] ?? '')));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(6, (int)($_GET['limit'] ?? 12)));
$offset = ($page - 1) * $limit;
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

$registryCompletionExpr = "CASE WHEN fr_done.regNo IS NULL THEN 0 ELSE 1 END";

$workflowStateExpr = "
    CASE
        WHEN {$registryCompletionExpr}
            OR {$appnStatusExpr} = 'completed'
            OR COALESCE(q.status, '') = 'completed'
            OR (q.queue_id IS NULL AND COALESCE(task_flags.has_completed_approval, 0) = 1)
            THEN 'completed'
        WHEN {$appnStatusExpr} = 'in_process'
            OR COALESCE(q.status, '') IN ('submitted_to_oc', 'in_progress')
            OR (q.queue_id IS NULL AND COALESCE(task_flags.has_live_task, 0) = 1)
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

$effectiveAppnStatusExpr = "
    CASE
        WHEN {$workflowStateExpr} IN ('in_process','completed') THEN {$workflowStateExpr}
        WHEN {$appnStatusExpr} IN ('verified','queried','rejected') THEN {$appnStatusExpr}
        WHEN LOWER(TRIM(COALESCE(sd.submissionStatus, ''))) = 'submitted' THEN 'submitted'
        ELSE 'pending'
    END
";

$sql = "
    SELECT SQL_CALC_FOUND_ROWS
        sd.id,
        sd.regNo,
        sd.employeeNo,
        sd.ippsNo,
        sd.rankPosition,
        sd.rankName,
        sd.positionName,
        sd.firstName,
        sd.middleName,
        sd.lastName,
        sd.next_of_kin_nin,
        sd.salaryScale,
        sd.employmentStatus,
        sd.service_file_status,
        sd.service_file_location,
        COALESCE(sf.availability_status, 'not_availed') AS service_file_availability,
        COALESCE(sf.registry_stage, sd.service_file_status, 'pending_processing') AS service_file_registry_stage,
        sd.computerNo,
        sd.title,
        sd.sName,
        sd.fName,
        sd.prisonUnit,
        sd.NIN,
        sd.gender,
        sd.telNo,
        sd.birthDate,
        sd.enlistmentDate,
        sd.retirementDate,
        sd.financialYear,
        sd.retirementType,
        sd.monthlySalary,
        sd.lengthOfService,
        sd.annualSalary,
        sd.reducedPension,
        sd.fullPension,
        sd.gratuity,
        sd.submissionStatus,
        sd.appnStatus,
        sd.submission_at,
        sd.appn_status_at,
        sd.appn_status_reason,
        q.status AS queue_status,
        q.current_stage,
        q.verified_at,
        q.submitted_at,
        {$registryCompletionExpr} AS registry_record_exists,
        {$appnStatusExpr} AS appn_status_normalized,
        {$effectiveAppnStatusExpr} AS appn_status_effective,
        {$workflowStateExpr} AS workflow_action_state,
        {$workflowInitiationExpr} AS workflow_initiation_state,
        CASE
            WHEN LOWER(TRIM(COALESCE(sd.submissionStatus, ''))) = 'submitted'
             AND sd.submission_at IS NOT NULL
                THEN DATEDIFF(CURDATE(), DATE(sd.submission_at))
            ELSE NULL
        END AS days_since_submission
    FROM tb_staffdue sd
    LEFT JOIN tb_service_files sf ON sf.staffdue_id = sd.id
    LEFT JOIN tb_application_queue q
      ON q.staffdue_id = sd.id
    LEFT JOIN (
        SELECT DISTINCT regNo
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
    ) fr_done
      ON fr_done.regNo = sd.regNo
    LEFT JOIN (
        SELECT
            related_staff_id,
            MAX(CASE WHEN task_type = 'approval' AND status = 'completed' THEN 1 ELSE 0 END) AS has_completed_approval,
            MAX(CASE WHEN status IN ('pending', 'assigned', 'in_progress', 'deferred', 'returned') THEN 1 ELSE 0 END) AS has_live_task
        FROM tb_tasks
        WHERE related_staff_id IS NOT NULL
        GROUP BY related_staff_id
    ) task_flags
      ON task_flags.related_staff_id = sd.id
    WHERE COALESCE(sd.is_deleted, 0) = 0
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (
        sd.regNo LIKE ?
        OR sd.employeeNo LIKE ?
        OR sd.ippsNo LIKE ?
        OR sd.sName LIKE ?
        OR sd.fName LIKE ?
        OR sd.title LIKE ?
        OR sd.prisonUnit LIKE ?
        OR sd.NIN LIKE ?
        OR sd.telNo LIKE ?
    )";
    $searchTerm = '%' . $search . '%';
    $params = array_merge($params, array_fill(0, 9, $searchTerm));
    $types .= 'sssssssss';
}

if ($retirementType !== '') {
    $retirementAliases = getBenefitsRetirementTypeAliasesForFilter($retirementType);
    if (!empty($retirementAliases)) {
        $placeholders = implode(',', array_fill(0, count($retirementAliases), '?'));
        $sql .= " AND LOWER(TRIM(COALESCE(sd.retirementType, ''))) IN ({$placeholders})";
        foreach ($retirementAliases as $alias) {
            $params[] = $alias;
            $types .= 's';
        }
    }
}

if ($submissionStatus !== '') {
    $submissionStatus = ($submissionStatus === 'submitted') ? 'submitted' : 'pending';
    $sql .= " AND LOWER(TRIM(COALESCE(sd.submissionStatus, 'pending'))) = ?";
    $params[] = $submissionStatus;
    $types .= 's';
}

if ($appnStatus !== '') {
    if ($appnStatus === 'querried') {
        $appnStatus = 'queried';
    }

    if (in_array($appnStatus, ['pending', 'submitted', 'verified', 'queried', 'rejected', 'in_process', 'completed'], true)) {
        $sql .= " AND {$effectiveAppnStatusExpr} = ?";
        $params[] = $appnStatus;
        $types .= 's';
    }
}

$sql .= " ORDER BY
    CASE ({$workflowInitiationExpr})
        WHEN 'escalated' THEN 1
        WHEN 'due_soon' THEN 2
        WHEN 'pending' THEN 3
        ELSE 4
    END,
    COALESCE(sd.submission_at, sd.timeStamp) ASC,
    sd.id DESC
    LIMIT ? OFFSET ?
";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare query'
    ]);
    $conn->close();
    exit;
}

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $row['appnStatus'] = (string)($row['appn_status_effective'] ?? $row['appn_status_normalized'] ?? $row['appnStatus'] ?? 'pending');
    $records[] = $row;
}
$totalResult = $conn->query("SELECT FOUND_ROWS() AS total");
$totalRecords = $totalResult ? (int)(($totalResult->fetch_assoc()['total'] ?? 0)) : count($records);
$totalPages = max(1, (int)ceil($totalRecords / $limit));

echo json_encode([
    'success' => true,
    'page' => $page,
    'limit' => $limit,
    'totalRecords' => $totalRecords,
    'totalPages' => $totalPages,
    'records' => $records,
    'settings' => [
        'verificationEscalationWindowDays' => $verificationEscalationDays,
        'verificationDueSoonWindowDays' => $verificationDueSoonDays
    ]
]);
$stmt->close();
$conn->close();
?>
