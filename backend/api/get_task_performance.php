<?php
header('Content-Type: application/json');
$workflowPerformanceOutputLevel = ob_get_level();
ob_start();
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
if (!isset($_SESSION['userId'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = $_SESSION['userRole'] ?? '';
$normalizedRole = normalizeWorkflowRoleKey($role);
if (!in_array($normalizedRole, ['super_admin', 'admin', 'oc_pen'], true)) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureTasksTable($conn);
ensureTaskAlertsTable($conn);
ensureAppnStatusTrackingColumns($conn);
if (function_exists('ensureTaskDelegationLogsTable')) {
    ensureTaskDelegationLogsTable($conn);
}
if (function_exists('ensureWorkflowLogsTable')) {
    ensureWorkflowLogsTable($conn);
}
if (function_exists('ensureTaskPerformanceIndexes')) {
    ensureTaskPerformanceIndexes($conn);
}
if (function_exists('maybeSyncTaskAlerts')) {
    maybeSyncTaskAlerts($conn);
} else {
    syncTaskAlerts($conn);
}

$summary = [
    'total_open' => 0,
    'overdue_open' => 0,
    'completed_7d' => 0,
    'avg_completion_hours' => 0.0
];
$delegationSummary = [
    'requested_total' => 0,
    'pending_total' => 0,
    'accepted_total' => 0,
    'declined_total' => 0,
    'avg_accept_hours' => 0.0
];
$processSummary = [
    'completed_total' => 0,
    'avg_processing_minutes' => 0.0,
    'fastest_minutes' => 0.0,
    'slowest_minutes' => 0.0
];
$processTrends = [];

$summaryStmt = $conn->prepare("
    SELECT
        SUM(CASE WHEN status IN ('pending','assigned','in_progress','deferred','returned') THEN 1 ELSE 0 END) AS total_open,
        0 AS overdue_open,
        SUM(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS completed_7d,
        AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL
                 THEN TIMESTAMPDIFF(MINUTE, timeStamp, completed_at) / 60 END) AS avg_completion_hours
    FROM tb_tasks
");

if ($summaryStmt) {
    $summaryStmt->execute();
    $result = $summaryStmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $summary['total_open'] = (int)($row['total_open'] ?? 0);
        $summary['overdue_open'] = (int)($row['overdue_open'] ?? 0);
        $summary['completed_7d'] = (int)($row['completed_7d'] ?? 0);
        $summary['avg_completion_hours'] = round((float)($row['avg_completion_hours'] ?? 0), 2);
    }
    $summaryStmt->close();
}

$overdueSummaryResult = $conn->query("
    SELECT COUNT(DISTINCT task_id) AS overdue_total
    FROM tb_task_alerts
    WHERE alert_type = 'overdue'
      AND alert_status IN ('open', 'acknowledged')
");
if ($overdueSummaryResult && ($overdueRow = $overdueSummaryResult->fetch_assoc())) {
    $summary['overdue_open'] = (int)($overdueRow['overdue_total'] ?? 0);
}

$delegationResult = $conn->query("
    SELECT
        COUNT(*) AS requested_total,
        SUM(CASE WHEN t.metadata LIKE '%\"pending_delegation\"%' THEN 1 ELSE 0 END) AS pending_total,
        SUM(CASE WHEN t.assigned_to = d.to_user_id AND t.assigned_at IS NOT NULL AND t.assigned_at >= d.created_at THEN 1 ELSE 0 END) AS accepted_total,
        AVG(CASE WHEN t.assigned_to = d.to_user_id AND t.assigned_at IS NOT NULL AND t.assigned_at >= d.created_at
                 THEN TIMESTAMPDIFF(MINUTE, d.created_at, t.assigned_at) / 60 END) AS avg_accept_hours
    FROM tb_task_delegation_logs d
    LEFT JOIN tb_tasks t ON t.taskId = d.task_id
");
if ($delegationResult && ($row = $delegationResult->fetch_assoc())) {
    $delegationSummary['requested_total'] = (int)($row['requested_total'] ?? 0);
    $delegationSummary['pending_total'] = (int)($row['pending_total'] ?? 0);
    $delegationSummary['accepted_total'] = (int)($row['accepted_total'] ?? 0);
    $delegationSummary['avg_accept_hours'] = round((float)($row['avg_accept_hours'] ?? 0), 2);
}
$declinedDelegations = $conn->query("SELECT COUNT(*) AS declined_total FROM tb_workflow_logs WHERE action = 'task_delegation_declined'");
if ($declinedDelegations && ($row = $declinedDelegations->fetch_assoc())) {
    $delegationSummary['declined_total'] = (int)($row['declined_total'] ?? 0);
}

$processResult = $conn->query("
    SELECT
        COUNT(*) AS completed_total,
        AVG(TIMESTAMPDIFF(MINUTE, a.verification_at, a.approval_at)) AS avg_processing_minutes,
        MIN(TIMESTAMPDIFF(MINUTE, a.verification_at, a.approval_at)) AS fastest_minutes,
        MAX(TIMESTAMPDIFF(MINUTE, a.verification_at, a.approval_at)) AS slowest_minutes
    FROM tb_appnstatus a
    INNER JOIN tb_staffdue sd ON sd.regNo = a.regNo
    WHERE a.verification_at IS NOT NULL
      AND a.approval_at IS NOT NULL
      AND LOWER(TRIM(COALESCE(a.approval, ''))) IN ('approved','completed','done')
");
if ($processResult && ($row = $processResult->fetch_assoc())) {
    $processSummary['completed_total'] = (int)($row['completed_total'] ?? 0);
    $processSummary['avg_processing_minutes'] = round((float)($row['avg_processing_minutes'] ?? 0), 2);
    $processSummary['fastest_minutes'] = round((float)($row['fastest_minutes'] ?? 0), 2);
    $processSummary['slowest_minutes'] = round((float)($row['slowest_minutes'] ?? 0), 2);
}
$trendResult = $conn->query("
    SELECT
        DATE(a.approval_at) AS period_date,
        COUNT(*) AS completed_total,
        AVG(TIMESTAMPDIFF(MINUTE, a.verification_at, a.approval_at)) AS avg_processing_minutes
    FROM tb_appnstatus a
    INNER JOIN tb_staffdue sd ON sd.regNo = a.regNo
    WHERE a.verification_at IS NOT NULL
      AND a.approval_at IS NOT NULL
      AND LOWER(TRIM(COALESCE(a.approval, ''))) IN ('approved','completed','done')
      AND a.approval_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(a.approval_at)
    ORDER BY period_date ASC
");
if ($trendResult) {
    while ($row = $trendResult->fetch_assoc()) {
        $processTrends[] = [
            'period_date' => $row['period_date'],
            'completed_total' => (int)($row['completed_total'] ?? 0),
            'avg_processing_minutes' => round((float)($row['avg_processing_minutes'] ?? 0), 2)
        ];
    }
}

$roles = [];

$roleStmt = $conn->query("
    SELECT DISTINCT LOWER(TRIM(COALESCE(role_key, ''))) AS role_key
    FROM tb_roles
    WHERE TRIM(COALESCE(role_key, '')) <> ''
      AND LOWER(TRIM(COALESCE(role_key, ''))) NOT IN ('super_admin', 'admin', 'pensioner')
      AND is_active = 1
    ORDER BY role_key ASC
");
if ($roleStmt) {
    while ($row = $roleStmt->fetch_assoc()) {
        $key = strtolower(trim((string)($row['role_key'] ?? '')));
        if ($key === '') {
            continue;
        }
        $roles[$key] = true;
    }
}

$taskRolesStmt = $conn->query("
    SELECT DISTINCT LOWER(TRIM(COALESCE(assigned_role, ''))) AS role_key
    FROM tb_tasks
    WHERE TRIM(COALESCE(assigned_role, '')) <> ''
      AND LOWER(TRIM(COALESCE(assigned_role, ''))) NOT IN ('super_admin', 'admin', 'pensioner')
");
if ($taskRolesStmt) {
    while ($row = $taskRolesStmt->fetch_assoc()) {
        $key = strtolower(trim((string)($row['role_key'] ?? '')));
        if ($key === '') {
            continue;
        }
        $roles[$key] = true;
    }
}

$roles = array_values(array_keys($roles));
sort($roles);

$performance = [];
if (!empty($roles)) {
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));
    $sql = "
        SELECT
            u.userId,
            u.userName,
            u.userRole,
            COUNT(DISTINCT t.taskId) AS assigned_total,
            COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN t.taskId END) AS completed_total,
            COUNT(DISTINCT CASE WHEN a.alert_id IS NOT NULL
                     AND a.alert_type = 'overdue'
                     AND a.alert_status IN ('open','acknowledged')
                     THEN t.taskId END) AS overdue_open,
            COUNT(DISTINCT CASE WHEN t.status IN ('pending','assigned','in_progress','deferred','returned') THEN t.taskId END) AS active_open,
            AVG(CASE WHEN t.status = 'completed' AND t.completed_at IS NOT NULL
                     THEN TIMESTAMPDIFF(MINUTE, t.timeStamp, t.completed_at) / 60 END) AS avg_completion_hours,
            MAX(t.completed_at) AS last_completed_at
        FROM tb_users u
        LEFT JOIN tb_tasks t ON t.assigned_to = u.userId
        LEFT JOIN tb_task_alerts a
               ON a.task_id = t.taskId
              AND a.alert_type = 'overdue'
        WHERE LOWER(TRIM(COALESCE(u.userRole, ''))) IN ($placeholders)
        GROUP BY u.userId, u.userName, u.userRole
        ORDER BY u.userRole, u.userName
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$roles);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $assignedTotal = (int)($row['assigned_total'] ?? 0);
            $completedTotal = (int)($row['completed_total'] ?? 0);
            $responseRate = $assignedTotal > 0 ? round(($completedTotal / $assignedTotal) * 100, 1) : 0.0;
            $userRole = strtolower(trim((string)($row['userRole'] ?? '')));
            $performance[] = [
                'user_id' => $row['userId'],
                'user_name' => $row['userName'],
                'user_role' => $userRole,
                'user_role_label' => getRoleLabel($conn, $userRole),
                'assigned_total' => $assignedTotal,
                'completed_total' => $completedTotal,
                'overdue_open' => (int)($row['overdue_open'] ?? 0),
                'active_open' => (int)($row['active_open'] ?? 0),
                'response_rate' => $responseRate,
                'avg_completion_hours' => round((float)($row['avg_completion_hours'] ?? 0), 2),
                'last_completed_at' => $row['last_completed_at']
            ];
        }
        $stmt->close();
    }
}

ob_clean();
echo json_encode([
    'success' => true,
    'summary' => $summary,
    'staff_performance' => $performance,
    'delegation_summary' => $delegationSummary,
    'process_summary' => $processSummary,
    'process_trends' => $processTrends
]);
} catch (Throwable $error) {
    error_log('Workflow performance API error: ' . $error->getMessage());
    if (ob_get_level() > $workflowPerformanceOutputLevel) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load workflow performance metrics.'
    ]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
