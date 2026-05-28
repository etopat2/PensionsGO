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
ensureTaskAlertsTable($conn);
if (function_exists('ensureTaskPerformanceIndexes')) {
    ensureTaskPerformanceIndexes($conn);
}

$syncStats = function_exists('maybeSyncTaskAlerts')
    ? maybeSyncTaskAlerts($conn)
    : syncTaskAlerts($conn);

$userId = (string)($_SESSION['userId'] ?? '');
$userRole = (string)($_SESSION['userRole'] ?? '');
$normalizedRole = normalizeWorkflowRoleKey($userRole);
$canViewGlobal = $normalizedRole === 'admin' || $normalizedRole === 'oc_pen';

$scope = strtolower(trim((string)($_GET['scope'] ?? 'mine')));
if (!in_array($scope, ['mine', 'all'], true)) {
    $scope = 'mine';
}
if ($scope === 'all' && !$canViewGlobal) {
    $scope = 'mine';
}

$includeResolved = isset($_GET['include_resolved']) && $_GET['include_resolved'] === '1';
$requestedStatus = strtolower(trim((string)($_GET['status'] ?? '')));
$requestedType = strtolower(trim((string)($_GET['type'] ?? '')));

$validStatuses = ['open', 'acknowledged', 'resolved', 'dismissed'];
$validTypes = ['due_soon', 'overdue', 'stalled'];

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
$offset = ($page - 1) * $limit;

$buildScopeClause = static function () use ($scope, $userId, $userRole): array {
    if ($scope !== 'mine') {
        return ['', '', []];
    }

    $inboxRoles = getWorkflowRoleKeysForInbox($userRole);
    $params = [$userId];
    $types = 's';
    $parts = ["a.assigned_to = ?"];

    if (!empty($inboxRoles)) {
        $placeholders = implode(',', array_fill(0, count($inboxRoles), '?'));
        $parts[] = "(a.assigned_to IS NULL AND a.assigned_role IN ($placeholders))";
        foreach ($inboxRoles as $roleKey) {
            $params[] = $roleKey;
            $types .= 's';
        }
    }

    return ['(' . implode(' OR ', $parts) . ')', $types, $params];
};

[$scopeSql, $scopeTypes, $scopeParams] = $buildScopeClause();

$where = [];
$types = $scopeTypes;
$params = $scopeParams;
if ($scopeSql !== '') {
    $where[] = $scopeSql;
}

if ($requestedStatus !== '' && in_array($requestedStatus, $validStatuses, true)) {
    $where[] = 'a.alert_status = ?';
    $params[] = $requestedStatus;
    $types .= 's';
} elseif (!$includeResolved) {
    $where[] = "a.alert_status IN ('open', 'acknowledged')";
}

if ($requestedType !== '' && in_array($requestedType, $validTypes, true)) {
    $where[] = 'a.alert_type = ?';
    $params[] = $requestedType;
    $types .= 's';
}

$whereSql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$countSql = "SELECT COUNT(*) AS total FROM tb_task_alerts a $whereSql";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load task alerts.']);
    exit;
}
if ($types !== '') {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$totalRows = (int)(($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$listSql = "
    SELECT
        a.alert_id,
        a.task_id,
        a.alert_type,
        a.severity,
        a.alert_status,
        a.assigned_to,
        a.assigned_role,
        a.related_reg_no,
        a.due_at,
        a.triggered_at,
        a.acknowledged_at,
        a.acknowledged_by,
        a.resolved_at,
        a.resolved_by,
        a.last_evaluated_at,
        a.metadata,
        t.task_title,
        t.task_type,
        t.status AS task_status,
        t.priority AS task_priority,
        t.timeStamp AS task_created_at,
        t.updated_at AS task_updated_at,
        assignee.userName AS assigned_name,
        creator.userName AS created_by_name,
        TRIM(CONCAT(COALESCE(sd.sName, ''), ' ', COALESCE(sd.fName, ''))) AS applicant_name
    FROM tb_task_alerts a
    LEFT JOIN tb_tasks t ON t.taskId = a.task_id
    LEFT JOIN tb_users assignee ON assignee.userId = a.assigned_to
    LEFT JOIN tb_users creator ON creator.userId = t.created_by
    LEFT JOIN tb_staffdue sd
        ON (t.related_staff_id IS NOT NULL AND sd.id = t.related_staff_id)
        OR (t.related_staff_id IS NULL AND t.related_reg_no IS NOT NULL AND sd.regNo = t.related_reg_no)
    $whereSql
    ORDER BY
        FIELD(a.alert_status, 'open', 'acknowledged', 'resolved', 'dismissed'),
        FIELD(a.severity, 'critical', 'warning', 'info'),
        a.triggered_at DESC
    LIMIT ?, ?
";

$listStmt = $conn->prepare($listSql);
if (!$listStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load task alerts list.']);
    exit;
}

$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $offset;
$listParams[] = $limit;
$listStmt->bind_param($listTypes, ...$listParams);
$listStmt->execute();
$listResult = $listStmt->get_result();
$alerts = [];
while ($row = $listResult->fetch_assoc()) {
    $metadata = [];
    if (!empty($row['metadata'])) {
        $decoded = json_decode((string)$row['metadata'], true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }
    $alerts[] = [
        'alert_id' => (int)$row['alert_id'],
        'task_id' => (int)$row['task_id'],
        'alert_type' => (string)$row['alert_type'],
        'severity' => (string)$row['severity'],
        'alert_status' => (string)$row['alert_status'],
        'assigned_to' => $row['assigned_to'],
        'assigned_role' => $row['assigned_role'],
        'assigned_role_label' => getRoleLabel($conn, (string)($row['assigned_role'] ?? '')),
        'assigned_name' => $row['assigned_name'],
        'related_reg_no' => $row['related_reg_no'],
        'due_at' => $row['due_at'],
        'triggered_at' => $row['triggered_at'],
        'acknowledged_at' => $row['acknowledged_at'],
        'resolved_at' => $row['resolved_at'],
        'last_evaluated_at' => $row['last_evaluated_at'],
        'metadata' => $metadata,
        'task_title' => $row['task_title'],
        'task_type' => $row['task_type'],
        'task_status' => $row['task_status'],
        'task_priority' => $row['task_priority'],
        'task_created_at' => $row['task_created_at'],
        'task_updated_at' => $row['task_updated_at'],
        'created_by_name' => $row['created_by_name'],
        'applicant_name' => trim((string)($row['applicant_name'] ?? ''))
    ];
}
$listStmt->close();

// Scope-only summary for quick UI badges.
$summaryWhere = [];
$summaryTypes = $scopeTypes;
$summaryParams = $scopeParams;
if ($scopeSql !== '') {
    $summaryWhere[] = $scopeSql;
}
$summaryWhereSql = !empty($summaryWhere) ? ('WHERE ' . implode(' AND ', $summaryWhere)) : '';
$summarySql = "
    SELECT
        SUM(CASE WHEN alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS open_total,
        SUM(CASE WHEN alert_status = 'acknowledged' THEN 1 ELSE 0 END) AS acknowledged_total,
        SUM(CASE WHEN alert_status = 'resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS resolved_7d,
        SUM(CASE WHEN alert_type = 'overdue' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS overdue_open,
        SUM(CASE WHEN alert_type = 'due_soon' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS due_soon_open,
        SUM(CASE WHEN alert_type = 'stalled' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS stalled_open,
        SUM(CASE WHEN severity = 'critical' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS critical_open
    FROM tb_task_alerts a
    $summaryWhereSql
";

$summaryStmt = $conn->prepare($summarySql);
$summary = [
    'open_total' => 0,
    'acknowledged_total' => 0,
    'resolved_7d' => 0,
    'overdue_open' => 0,
    'due_soon_open' => 0,
    'stalled_open' => 0,
    'critical_open' => 0
];
if ($summaryStmt) {
    if ($summaryTypes !== '') {
        $summaryStmt->bind_param($summaryTypes, ...$summaryParams);
    }
    $summaryStmt->execute();
    $summaryRow = $summaryStmt->get_result()->fetch_assoc();
    if ($summaryRow) {
        foreach ($summary as $key => $default) {
            $summary[$key] = (int)($summaryRow[$key] ?? 0);
        }
    }
    $summaryStmt->close();
}

echo json_encode([
    'success' => true,
    'scope' => $scope,
    'can_view_global' => $canViewGlobal,
    'sync' => $syncStats,
    'summary' => $summary,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'total' => $totalRows,
        'total_pages' => max(1, (int)ceil($totalRows / max(1, $limit)))
    ],
    'alerts' => $alerts
]);

$conn->close();
?>
