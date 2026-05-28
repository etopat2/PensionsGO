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

ensureFileMovementTables($conn);

$summary = [
    'total_movements' => 0,
    'open_movements' => 0,
    'returned_movements' => 0,
    'overdue_open' => 0,
    'due_soon_open' => 0,
    'moved_today' => 0,
    'moved_this_week' => 0,
    'avg_turnaround_seconds' => 0,
    'longest_open_seconds' => 0
];

$summarySql = "
    SELECT
        COUNT(*) AS total_movements,
        COUNT(DISTINCT CASE WHEN returned_at IS NULL THEN regNo END) AS open_movements,
        SUM(CASE WHEN returned_at IS NOT NULL THEN 1 ELSE 0 END) AS returned_movements,
        COUNT(DISTINCT CASE WHEN returned_at IS NULL AND expected_return_at IS NOT NULL AND expected_return_at < NOW() THEN regNo END) AS overdue_open,
        COUNT(DISTINCT CASE WHEN returned_at IS NULL AND expected_return_at IS NOT NULL AND expected_return_at >= NOW() AND expected_return_at < DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN regNo END) AS due_soon_open,
        SUM(CASE WHEN DATE(moved_at) = CURDATE() THEN 1 ELSE 0 END) AS moved_today,
        SUM(CASE WHEN YEARWEEK(moved_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS moved_this_week,
        AVG(CASE WHEN returned_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, moved_at, returned_at) END) AS avg_turnaround_seconds,
        MAX(CASE WHEN returned_at IS NULL THEN TIMESTAMPDIFF(SECOND, moved_at, NOW()) END) AS longest_open_seconds
    FROM tb_file_movements
";

$summaryRes = $conn->query($summarySql);
if ($summaryRes && $summaryRes->num_rows > 0) {
    $row = $summaryRes->fetch_assoc();
    $summary = [
        'total_movements' => (int)($row['total_movements'] ?? 0),
        'open_movements' => (int)($row['open_movements'] ?? 0),
        'returned_movements' => (int)($row['returned_movements'] ?? 0),
        'overdue_open' => (int)($row['overdue_open'] ?? 0),
        'due_soon_open' => (int)($row['due_soon_open'] ?? 0),
        'moved_today' => (int)($row['moved_today'] ?? 0),
        'moved_this_week' => (int)($row['moved_this_week'] ?? 0),
        'avg_turnaround_seconds' => (int)round((float)($row['avg_turnaround_seconds'] ?? 0)),
        'longest_open_seconds' => (int)($row['longest_open_seconds'] ?? 0)
    ];
}

$byOffice = [];
$officeSql = "
    SELECT
        COALESCE(NULLIF(TRIM(to_office), ''), 'Unspecified') AS to_office,
        COUNT(*) AS total,
        COUNT(DISTINCT CASE WHEN returned_at IS NULL THEN regNo END) AS open_count,
        SUM(CASE WHEN returned_at IS NOT NULL THEN 1 ELSE 0 END) AS returned_count
    FROM tb_file_movements
    GROUP BY COALESCE(NULLIF(TRIM(to_office), ''), 'Unspecified')
    ORDER BY total DESC, to_office ASC
    LIMIT 12
";
$officeRes = $conn->query($officeSql);
if ($officeRes) {
    while ($row = $officeRes->fetch_assoc()) {
        $byOffice[] = [
            'to_office' => $row['to_office'],
            'total' => (int)$row['total'],
            'open_count' => (int)$row['open_count'],
            'returned_count' => (int)$row['returned_count']
        ];
    }
}

$openCustody = [];
$custodySql = "
    SELECT
        COALESCE(NULLIF(TRIM(to_office), ''), 'Unspecified') AS to_office,
        COUNT(DISTINCT regNo) AS open_files,
        MAX(TIMESTAMPDIFF(SECOND, moved_at, NOW())) AS longest_seconds
    FROM tb_file_movements
    WHERE returned_at IS NULL
    GROUP BY COALESCE(NULLIF(TRIM(to_office), ''), 'Unspecified')
    ORDER BY open_files DESC, longest_seconds DESC, to_office ASC
    LIMIT 12
";
$custodyRes = $conn->query($custodySql);
if ($custodyRes) {
    while ($row = $custodyRes->fetch_assoc()) {
        $openCustody[] = [
            'to_office' => $row['to_office'],
            'open_files' => (int)$row['open_files'],
            'longest_seconds' => (int)($row['longest_seconds'] ?? 0)
        ];
    }
}

$recentMovements = [];
$recentSql = "
    SELECT
        m.movement_id,
        m.regNo,
        m.from_office,
        m.to_office,
        m.reason,
        m.delivered_by,
        u.userName AS delivered_by_name,
        m.moved_at,
        m.returned_at,
        m.expected_return_at,
        TIMESTAMPDIFF(SECOND, m.moved_at, COALESCE(m.returned_at, NOW())) AS duration_seconds
    FROM tb_file_movements m
    LEFT JOIN tb_users u ON u.userId = m.delivered_by
    ORDER BY m.moved_at DESC, m.movement_id DESC
    LIMIT 12
";
$recentRes = $conn->query($recentSql);
if ($recentRes) {
    while ($row = $recentRes->fetch_assoc()) {
        $recentMovements[] = [
            'movement_id' => (int)$row['movement_id'],
            'regNo' => $row['regNo'] ?? '',
            'from_office' => $row['from_office'] ?? '',
            'to_office' => $row['to_office'] ?? '',
            'reason' => $row['reason'] ?? '',
            'delivered_by' => $row['delivered_by_name'] ?: ($row['delivered_by'] ?? ''),
            'moved_at' => $row['moved_at'] ?? '',
            'returned_at' => $row['returned_at'] ?? null,
            'expected_return_at' => $row['expected_return_at'] ?? null,
            'duration_seconds' => (int)($row['duration_seconds'] ?? 0)
        ];
    }
}

echo json_encode([
    'success' => true,
    'summary' => $summary,
    'by_office' => $byOffice,
    'open_custody' => $openCustody,
    'recent_movements' => $recentMovements
]);

$conn->close();
?>
