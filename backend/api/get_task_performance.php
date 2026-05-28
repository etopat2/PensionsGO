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

$role = $_SESSION['userRole'] ?? '';
$normalizedRole = normalizeWorkflowRoleKey($role);
if (!in_array($normalizedRole, ['admin', 'oc_pen'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureTasksTable($conn);
ensureTaskAlertsTable($conn);
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
    SELECT COUNT(*) AS overdue_total
    FROM tb_task_alerts
    WHERE alert_type = 'overdue'
      AND alert_status IN ('open', 'acknowledged')
");
if ($overdueSummaryResult && ($overdueRow = $overdueSummaryResult->fetch_assoc())) {
    $summary['overdue_open'] = (int)($overdueRow['overdue_total'] ?? 0);
}

$roles = [];

$roleStmt = $conn->query("
    SELECT DISTINCT LOWER(TRIM(COALESCE(role_key, ''))) AS role_key
    FROM tb_roles
    WHERE TRIM(COALESCE(role_key, '')) <> ''
      AND LOWER(TRIM(COALESCE(role_key, ''))) NOT IN ('admin', 'pensioner')
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
      AND LOWER(TRIM(COALESCE(assigned_role, ''))) NOT IN ('admin', 'pensioner')
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
            COUNT(t.taskId) AS assigned_total,
            SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_total,
            SUM(CASE WHEN a.alert_id IS NOT NULL
                     AND a.alert_type = 'overdue'
                     AND a.alert_status IN ('open','acknowledged')
                     THEN 1 ELSE 0 END) AS overdue_open,
            SUM(CASE WHEN t.status IN ('pending','assigned','in_progress','deferred','returned') THEN 1 ELSE 0 END) AS active_open,
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

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'staff_performance' => $performance
]);

$conn->close();
?>
