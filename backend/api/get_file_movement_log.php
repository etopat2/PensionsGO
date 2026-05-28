<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    requireDataManagementAccess($conn);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $status = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

ensureFileMovementTables($conn);

function fileMovementBindParams(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }
    $refs = [];
    $refs[] = &$types;
    foreach ($params as $idx => $value) {
        $refs[] = &$params[$idx];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(100, (int)($_GET['limit'] ?? 12)));
$offset = ($page - 1) * $limit;
$search = trim((string)($_GET['search'] ?? ''));
$movementType = trim((string)($_GET['movement_type'] ?? ''));
$fromOffice = trim((string)($_GET['from_office'] ?? ''));
$toOffice = trim((string)($_GET['to_office'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$where = ['1=1'];
$types = '';
$params = [];

if ($search !== '') {
    $pattern = '%' . $search . '%';
    $where[] = "(m.regNo LIKE ? OR COALESCE(m.file_id, '') LIKE ? OR COALESCE(m.from_office, '') LIKE ? OR COALESCE(m.to_office, '') LIKE ? OR COALESCE(m.reason, '') LIKE ? OR COALESCE(u.userName, '') LIKE ? OR COALESCE(m.delivered_by, '') LIKE ?)";
    array_push($params, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern, $pattern);
    $types .= 'sssssss';
}
if ($movementType === 'Returned') {
    $where[] = 'm.returned_at IS NOT NULL';
} elseif ($movementType === 'Moved Out') {
    $where[] = 'm.returned_at IS NULL';
}
if ($fromOffice !== '') {
    $where[] = 'm.from_office = ?';
    $params[] = $fromOffice;
    $types .= 's';
}
if ($toOffice !== '') {
    $where[] = 'm.to_office = ?';
    $params[] = $toOffice;
    $types .= 's';
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'DATE(m.moved_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'DATE(m.moved_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

$whereSql = implode(' AND ', $where);

$summarySql = "
    SELECT
        COUNT(*) AS total_rows,
        SUM(CASE WHEN m.returned_at IS NULL THEN 1 ELSE 0 END) AS open_rows,
        SUM(CASE WHEN m.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS returned_rows,
        SUM(CASE WHEN m.returned_at IS NULL AND m.expected_return_at IS NOT NULL AND m.expected_return_at < NOW() THEN 1 ELSE 0 END) AS overdue_rows,
        SUM(CASE WHEN DATE(m.moved_at) = CURDATE() THEN 1 ELSE 0 END) AS moved_today
    FROM tb_file_movements m
    LEFT JOIN tb_users u ON u.userId = m.delivered_by
    WHERE {$whereSql}
";
$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare movement summary query']);
    exit;
}
fileMovementBindParams($summaryStmt, $types, $params);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$countSql = "
    SELECT COUNT(*) AS total_rows
    FROM tb_file_movements m
    LEFT JOIN tb_users u ON u.userId = m.delivered_by
    WHERE {$whereSql}
";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare movement count query']);
    exit;
}
fileMovementBindParams($countStmt, $types, $params);
$countStmt->execute();
$countData = $countStmt->get_result()->fetch_assoc() ?: [];
$countStmt->close();
$totalRows = (int)($countData['total_rows'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$rowsSql = "
    SELECT
        m.movement_id,
        m.regNo,
        m.file_id,
        m.from_office,
        m.to_office,
        m.delivered_by,
        m.received_by,
        m.reason,
        m.moved_at,
        m.expected_return_at,
        m.returned_at,
        TIMESTAMPDIFF(SECOND, m.moved_at, COALESCE(m.returned_at, NOW())) AS duration_seconds,
        COALESCE(u.userName, m.delivered_by, '') AS delivered_by_name
    FROM tb_file_movements m
    LEFT JOIN tb_users u ON u.userId = m.delivered_by
    WHERE {$whereSql}
    ORDER BY m.moved_at DESC, m.movement_id DESC
    LIMIT ? OFFSET ?
";
$rowsStmt = $conn->prepare($rowsSql);
if (!$rowsStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare movement list query']);
    exit;
}
$rowParams = $params;
$rowTypes = $types . 'ii';
$rowParams[] = $limit;
$rowParams[] = $offset;
fileMovementBindParams($rowsStmt, $rowTypes, $rowParams);
$rowsStmt->execute();
$result = $rowsStmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'movement_id' => (int)($row['movement_id'] ?? 0),
        'regNo' => (string)($row['regNo'] ?? ''),
        'file_id' => (string)($row['file_id'] ?? ''),
        'movement_type' => empty($row['returned_at']) ? 'Moved Out' : 'Returned',
        'from_office' => (string)($row['from_office'] ?? ''),
        'to_office' => (string)($row['to_office'] ?? ''),
        'delivered_by_name' => (string)($row['delivered_by_name'] ?? ''),
        'received_by' => (string)($row['received_by'] ?? ''),
        'reason' => (string)($row['reason'] ?? ''),
        'moved_at' => (string)($row['moved_at'] ?? ''),
        'expected_return_at' => (string)($row['expected_return_at'] ?? ''),
        'returned_at' => (string)($row['returned_at'] ?? ''),
        'duration_seconds' => (int)($row['duration_seconds'] ?? 0)
    ];
}
$rowsStmt->close();

$officeOptions = [];
$officeResult = $conn->query("
    SELECT office_name
    FROM (
        SELECT DISTINCT TRIM(from_office) AS office_name FROM tb_file_movements
        UNION
        SELECT DISTINCT TRIM(to_office) AS office_name FROM tb_file_movements
    ) offices
    WHERE office_name IS NOT NULL AND office_name <> ''
    ORDER BY office_name ASC
");
if ($officeResult) {
    while ($officeRow = $officeResult->fetch_assoc()) {
        $officeOptions[] = (string)($officeRow['office_name'] ?? '');
    }
    $officeResult->close();
}

echo json_encode([
    'success' => true,
    'summary' => [
        'total' => (int)($summary['total_rows'] ?? 0),
        'open' => (int)($summary['open_rows'] ?? 0),
        'returned' => (int)($summary['returned_rows'] ?? 0),
        'overdue' => (int)($summary['overdue_rows'] ?? 0),
        'moved_today' => (int)($summary['moved_today'] ?? 0)
    ],
    'rows' => $rows,
    'pagination' => [
        'page' => $page,
        'limit' => $limit,
        'totalRows' => $totalRows,
        'totalPages' => $totalPages
    ],
    'options' => [
        'offices' => $officeOptions
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();
