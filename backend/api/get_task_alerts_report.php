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

$userRole = (string)($_SESSION['userRole'] ?? '');
$normalizedRole = normalizeWorkflowRoleKey($userRole);
if (!in_array($normalizedRole, ['admin', 'oc_pen'], true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
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

$summary = [
    'open_total' => 0,
    'critical_open' => 0,
    'overdue_open' => 0,
    'due_soon_open' => 0,
    'stalled_open' => 0,
    'acknowledged_open' => 0,
    'resolved_7d' => 0,
    'avg_resolution_hours' => 0.0
];

$summarySql = "
    SELECT
        SUM(CASE WHEN alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS open_total,
        SUM(CASE WHEN severity = 'critical' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS critical_open,
        SUM(CASE WHEN alert_type = 'overdue' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS overdue_open,
        SUM(CASE WHEN alert_type = 'due_soon' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS due_soon_open,
        SUM(CASE WHEN alert_type = 'stalled' AND alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS stalled_open,
        SUM(CASE WHEN alert_status = 'acknowledged' THEN 1 ELSE 0 END) AS acknowledged_open,
        SUM(CASE WHEN alert_status = 'resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS resolved_7d,
        AVG(CASE
                WHEN alert_status = 'resolved' AND resolved_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, triggered_at, resolved_at) / 60
            END
        ) AS avg_resolution_hours
    FROM tb_task_alerts
";
$summaryRow = $conn->query($summarySql)->fetch_assoc();
if ($summaryRow) {
    foreach ($summary as $key => $default) {
        if ($key === 'avg_resolution_hours') {
            $summary[$key] = round((float)($summaryRow[$key] ?? 0), 2);
            continue;
        }
        $summary[$key] = (int)($summaryRow[$key] ?? 0);
    }
}

$byRole = [];
$roleResult = $conn->query("
    SELECT
        COALESCE(NULLIF(TRIM(u.userRole), ''), NULLIF(TRIM(a.assigned_role), ''), 'unassigned') AS raw_role,
        COUNT(*) AS total_alerts,
        SUM(CASE WHEN a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS open_alerts,
        SUM(CASE WHEN a.alert_type = 'overdue' AND a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS overdue_open,
        SUM(CASE WHEN a.alert_type = 'stalled' AND a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS stalled_open,
        SUM(CASE WHEN a.alert_status = 'resolved' THEN 1 ELSE 0 END) AS resolved_total,
        SUM(CASE
                WHEN a.acknowledged_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, a.triggered_at, a.acknowledged_at)
                ELSE 0
            END
        ) AS ack_minutes_total,
        SUM(CASE WHEN a.acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) AS ack_count
    FROM tb_task_alerts a
    LEFT JOIN tb_users u ON u.userId = a.assigned_to
    GROUP BY raw_role
    ORDER BY open_alerts DESC, overdue_open DESC, total_alerts DESC
");
if ($roleResult) {
    $roleBuckets = [];
    while ($row = $roleResult->fetch_assoc()) {
        $roleKey = normalizeWorkflowRoleKey((string)($row['raw_role'] ?? ''));
        if ($roleKey === '') {
            $roleKey = 'unassigned';
        }
        if (!isset($roleBuckets[$roleKey])) {
            $roleBuckets[$roleKey] = [
                'role_key' => $roleKey,
                'role_label' => $roleKey === 'unassigned' ? 'Unassigned' : getRoleLabel($conn, $roleKey),
                'total_alerts' => 0,
                'open_alerts' => 0,
                'overdue_open' => 0,
                'stalled_open' => 0,
                'resolved_total' => 0,
                'ack_minutes_total' => 0.0,
                'ack_count' => 0
            ];
        }

        $roleBuckets[$roleKey]['total_alerts'] += (int)($row['total_alerts'] ?? 0);
        $roleBuckets[$roleKey]['open_alerts'] += (int)($row['open_alerts'] ?? 0);
        $roleBuckets[$roleKey]['overdue_open'] += (int)($row['overdue_open'] ?? 0);
        $roleBuckets[$roleKey]['stalled_open'] += (int)($row['stalled_open'] ?? 0);
        $roleBuckets[$roleKey]['resolved_total'] += (int)($row['resolved_total'] ?? 0);
        $roleBuckets[$roleKey]['ack_minutes_total'] += (float)($row['ack_minutes_total'] ?? 0);
        $roleBuckets[$roleKey]['ack_count'] += (int)($row['ack_count'] ?? 0);
    }

    foreach ($roleBuckets as $bucket) {
        $ackCount = (int)($bucket['ack_count'] ?? 0);
        $avgAck = $ackCount > 0
            ? round(((float)($bucket['ack_minutes_total'] ?? 0.0)) / $ackCount, 1)
            : 0.0;

        $byRole[] = [
            'role_key' => $bucket['role_key'],
            'role_label' => $bucket['role_label'],
            'total_alerts' => (int)$bucket['total_alerts'],
            'open_alerts' => (int)$bucket['open_alerts'],
            'overdue_open' => (int)$bucket['overdue_open'],
            'stalled_open' => (int)$bucket['stalled_open'],
            'resolved_total' => (int)$bucket['resolved_total'],
            'avg_ack_minutes' => $avgAck
        ];
    }

    usort($byRole, static function (array $a, array $b): int {
        return [$b['open_alerts'], $b['overdue_open'], $b['total_alerts']]
            <=> [$a['open_alerts'], $a['overdue_open'], $a['total_alerts']];
    });
}

$byUser = [];
$userResult = $conn->query("
    SELECT
        a.assigned_to,
        COALESCE(u.userName, 'Unassigned') AS user_name,
        COALESCE(u.userRole, a.assigned_role, '') AS user_role,
        COUNT(*) AS total_alerts,
        SUM(CASE WHEN a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS open_alerts,
        SUM(CASE WHEN a.alert_type = 'overdue' AND a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS overdue_open,
        SUM(CASE WHEN a.alert_type = 'stalled' AND a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS stalled_open,
        SUM(CASE WHEN a.severity = 'critical' AND a.alert_status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS critical_open,
        AVG(CASE
                WHEN a.acknowledged_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, a.triggered_at, a.acknowledged_at)
            END
        ) AS avg_ack_minutes
    FROM tb_task_alerts a
    LEFT JOIN tb_users u ON u.userId = a.assigned_to
    GROUP BY a.assigned_to, user_name, user_role
    ORDER BY overdue_open DESC, stalled_open DESC, open_alerts DESC, user_name ASC
");
if ($userResult) {
    while ($row = $userResult->fetch_assoc()) {
        $roleKey = normalizeWorkflowRoleKey((string)($row['user_role'] ?? ''));
        $byUser[] = [
            'assigned_to' => $row['assigned_to'],
            'user_name' => $row['user_name'],
            'user_role' => $roleKey,
            'user_role_label' => $roleKey === '' ? 'Unassigned' : getRoleLabel($conn, $roleKey),
            'total_alerts' => (int)($row['total_alerts'] ?? 0),
            'open_alerts' => (int)($row['open_alerts'] ?? 0),
            'overdue_open' => (int)($row['overdue_open'] ?? 0),
            'stalled_open' => (int)($row['stalled_open'] ?? 0),
            'critical_open' => (int)($row['critical_open'] ?? 0),
            'avg_ack_minutes' => round((float)($row['avg_ack_minutes'] ?? 0), 1)
        ];
    }
}

$trendDays = 14;
$labels = [];
$openedMap = [];
$resolvedMap = [];
$criticalMap = [];
for ($i = $trendDays - 1; $i >= 0; $i--) {
    $dateKey = date('Y-m-d', strtotime("-{$i} day"));
    $labels[] = $dateKey;
    $openedMap[$dateKey] = 0;
    $resolvedMap[$dateKey] = 0;
    $criticalMap[$dateKey] = 0;
}

$trendOpened = $conn->query("
    SELECT DATE(triggered_at) AS day_key,
           COUNT(*) AS opened_total,
           SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) AS critical_total
    FROM tb_task_alerts
    WHERE triggered_at >= DATE_SUB(CURDATE(), INTERVAL " . ($trendDays - 1) . " DAY)
    GROUP BY DATE(triggered_at)
");
if ($trendOpened) {
    while ($row = $trendOpened->fetch_assoc()) {
        $day = (string)($row['day_key'] ?? '');
        if (!isset($openedMap[$day])) {
            continue;
        }
        $openedMap[$day] = (int)($row['opened_total'] ?? 0);
        $criticalMap[$day] = (int)($row['critical_total'] ?? 0);
    }
}

$trendResolved = $conn->query("
    SELECT DATE(resolved_at) AS day_key, COUNT(*) AS resolved_total
    FROM tb_task_alerts
    WHERE resolved_at IS NOT NULL
      AND resolved_at >= DATE_SUB(CURDATE(), INTERVAL " . ($trendDays - 1) . " DAY)
    GROUP BY DATE(resolved_at)
");
if ($trendResolved) {
    while ($row = $trendResolved->fetch_assoc()) {
        $day = (string)($row['day_key'] ?? '');
        if (!isset($resolvedMap[$day])) {
            continue;
        }
        $resolvedMap[$day] = (int)($row['resolved_total'] ?? 0);
    }
}

$trend = [
    'labels' => $labels,
    'opened' => array_values($openedMap),
    'resolved' => array_values($resolvedMap),
    'critical' => array_values($criticalMap)
];

$recentAlerts = [];
$recentResult = $conn->query("
    SELECT
        a.alert_id,
        a.task_id,
        a.alert_type,
        a.severity,
        a.alert_status,
        a.triggered_at,
        a.assigned_to,
        a.assigned_role,
        a.related_reg_no,
        t.task_title,
        t.task_type,
        u.userName AS assigned_name,
        COALESCE(NULLIF(TRIM(u.userRole), ''), NULLIF(TRIM(a.assigned_role), ''), '') AS assigned_role_effective
    FROM tb_task_alerts a
    LEFT JOIN tb_tasks t ON t.taskId = a.task_id
    LEFT JOIN tb_users u ON u.userId = a.assigned_to
    WHERE a.alert_status IN ('open', 'acknowledged')
    ORDER BY FIELD(a.severity, 'critical', 'warning', 'info'), a.triggered_at DESC
    LIMIT 12
");
if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $roleKey = normalizeWorkflowRoleKey((string)($row['assigned_role_effective'] ?? ''));
        $recentAlerts[] = [
            'alert_id' => (int)$row['alert_id'],
            'task_id' => (int)$row['task_id'],
            'alert_type' => (string)$row['alert_type'],
            'severity' => (string)$row['severity'],
            'alert_status' => (string)$row['alert_status'],
            'triggered_at' => (string)$row['triggered_at'],
            'related_reg_no' => (string)($row['related_reg_no'] ?? ''),
            'task_title' => (string)($row['task_title'] ?? ''),
            'task_type' => (string)($row['task_type'] ?? ''),
            'assigned_to' => $row['assigned_to'],
            'assigned_name' => (string)($row['assigned_name'] ?? ''),
            'assigned_role' => $roleKey,
            'assigned_role_label' => $roleKey === '' ? 'Unassigned' : getRoleLabel($conn, $roleKey)
        ];
    }
}

echo json_encode([
    'success' => true,
    'sync' => $syncStats,
    'summary' => $summary,
    'thresholds' => [
        'due_soon_hours' => getTaskAlertDueSoonHours($conn),
        'stalled_hours' => getTaskAlertStalledHours($conn),
        'escalation_hours' => getTaskAlertEscalationHours($conn)
    ],
    'trend' => $trend,
    'by_role' => $byRole,
    'by_user' => $byUser,
    'recent_alerts' => $recentAlerts
]);

$conn->close();
?>
