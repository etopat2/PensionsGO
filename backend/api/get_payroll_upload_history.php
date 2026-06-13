<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower(trim((string)($_SESSION['userRole'] ?? '')));
if ($role === 'pensioner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}
$canManage = roleHasAdminAccess($conn, $role);

ensurePayrollManagementTables($conn);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 5 || $limit > 200) {
    $limit = 20;
}
$offset = ($page - 1) * $limit;

$year = (int)($_GET['year'] ?? 0);
$month = (int)($_GET['month'] ?? 0);
$financialYear = trim((string)($_GET['financial_year'] ?? ''));
$quarter = trim((string)($_GET['quarter'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$where = " WHERE COALESCE(c.is_deleted, 0) = 0 ";
$types = '';
$params = [];

if ($year >= 2000 && $year <= 2100) {
    $where .= " AND c.payroll_year = ? ";
    $types .= 'i';
    $params[] = $year;
}

if ($month >= 1 && $month <= 12) {
    $where .= " AND c.payroll_month = ? ";
    $types .= 'i';
    $params[] = $month;
}

if ($financialYear !== '') {
    $where .= " AND c.financial_year_label = ? ";
    $types .= 's';
    $params[] = $financialYear;
}

if (in_array($quarter, ['Q1', 'Q2', 'Q3', 'Q4'], true)) {
    $where .= " AND c.quarter_label = ? ";
    $types .= 's';
    $params[] = $quarter;
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (
        c.source_file LIKE ?
        OR COALESCE(c.source_file_original_name, '') LIKE ?
        OR COALESCE(c.payment_register_file, '') LIKE ?
        OR COALESCE(c.payment_register_original_name, '') LIKE ?
        OR COALESCE(c.notes, '') LIKE ?
        OR COALESCE(c.uploaded_by, '') LIKE ?
        OR COALESCE(u.userName, '') LIKE ?
        OR COALESCE(u.userEmail, '') LIKE ?
    ) ";
    $types .= 'ssssssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$countSql = "
    SELECT COUNT(*) AS total_cycles
    FROM tb_payroll_upload_cycles c
    LEFT JOIN tb_users u ON u.userId = c.uploaded_by
    {$where}
";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll cycle count query']);
    exit;
}
bindParamsSafe($countStmt, $types, $params);
$countStmt->execute();
$totalCycles = (int)(($countStmt->get_result()->fetch_assoc()['total_cycles'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int)ceil($totalCycles / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$summarySql = "
    SELECT
        COUNT(DISTINCT c.cycle_id) AS total_cycles,
        COUNT(e.entry_id) AS total_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 1 THEN 1 ELSE 0 END), 0) AS matched_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 0 THEN 1 ELSE 0 END), 0) AS unmatched_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 1 THEN e.amount ELSE 0 END), 0) AS matched_amount,
        COALESCE(SUM(CASE WHEN e.is_matched = 0 THEN e.amount ELSE 0 END), 0) AS unmatched_amount,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM tb_payroll_upload_cycles c
    LEFT JOIN tb_users u ON u.userId = c.uploaded_by
    LEFT JOIN tb_payroll_upload_entries e ON e.cycle_id = c.cycle_id
    {$where}
";
$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll summary query']);
    exit;
}
bindParamsSafe($summaryStmt, $types, $params);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$listSql = "
    SELECT
        c.cycle_id,
        c.payroll_year,
        c.payroll_month,
        c.financial_year_label,
        c.quarter_label,
        c.uploaded_by,
        c.source_file,
        c.source_file_original_name,
        c.source_file_mime,
        c.payment_register_file,
        c.payment_register_original_name,
        c.payment_register_mime,
        c.notes,
        c.created_at,
        COALESCE(u.userName, 'Unknown User') AS uploaded_by_name,
        COALESCE(u.userEmail, '') AS uploaded_by_email,
        COUNT(e.entry_id) AS total_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 1 THEN 1 ELSE 0 END), 0) AS matched_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 0 THEN 1 ELSE 0 END), 0) AS unmatched_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 1 THEN e.amount ELSE 0 END), 0) AS matched_amount,
        COALESCE(SUM(CASE WHEN e.is_matched = 0 THEN e.amount ELSE 0 END), 0) AS unmatched_amount,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM tb_payroll_upload_cycles c
    LEFT JOIN tb_users u ON u.userId = c.uploaded_by
    LEFT JOIN tb_payroll_upload_entries e ON e.cycle_id = c.cycle_id
    {$where}
    GROUP BY c.cycle_id
    ORDER BY c.created_at DESC, c.cycle_id DESC
    LIMIT ? OFFSET ?
";

$listStmt = $conn->prepare($listSql);
if (!$listStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll cycle list query']);
    exit;
}

$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $limit;
$listParams[] = $offset;
bindParamsSafe($listStmt, $listTypes, $listParams);
$listStmt->execute();
$listResult = $listStmt->get_result();

$cycles = [];
while ($row = $listResult->fetch_assoc()) {
    $cycles[] = [
        'cycleId' => (int)$row['cycle_id'],
        'year' => (int)$row['payroll_year'],
        'month' => (int)$row['payroll_month'],
        'financialYear' => (string)$row['financial_year_label'],
        'quarter' => (string)$row['quarter_label'],
        'uploadedBy' => (string)$row['uploaded_by'],
        'uploadedByName' => (string)$row['uploaded_by_name'],
        'uploadedByEmail' => (string)$row['uploaded_by_email'],
        'sourceFile' => (string)($row['source_file'] ?? ''),
        'sourceFileName' => (string)($row['source_file_original_name'] ?? ''),
        'sourceFileMime' => (string)($row['source_file_mime'] ?? ''),
        'paymentRegisterFile' => (string)($row['payment_register_file'] ?? ''),
        'paymentRegisterFileName' => (string)($row['payment_register_original_name'] ?? ''),
        'paymentRegisterMime' => (string)($row['payment_register_mime'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'createdAt' => (string)$row['created_at'],
        'totalRows' => (int)$row['total_rows'],
        'matchedRows' => (int)$row['matched_rows'],
        'unmatchedRows' => (int)$row['unmatched_rows'],
        'matchedAmount' => (float)$row['matched_amount'],
        'unmatchedAmount' => (float)$row['unmatched_amount'],
        'totalAmount' => (float)$row['total_amount']
    ];
}
$listStmt->close();

$filterFinancialYears = [];
$fyResult = $conn->query("SELECT DISTINCT financial_year_label FROM tb_payroll_upload_cycles ORDER BY financial_year_label DESC");
if ($fyResult) {
    while ($row = $fyResult->fetch_assoc()) {
        $value = trim((string)($row['financial_year_label'] ?? ''));
        if ($value !== '') {
            $filterFinancialYears[] = $value;
        }
    }
}

echo json_encode([
    'success' => true,
    'summary' => [
        'totalCycles' => (int)($summary['total_cycles'] ?? 0),
        'totalRows' => (int)($summary['total_rows'] ?? 0),
        'matchedRows' => (int)($summary['matched_rows'] ?? 0),
        'unmatchedRows' => (int)($summary['unmatched_rows'] ?? 0),
        'matchedAmount' => (float)($summary['matched_amount'] ?? 0),
        'unmatchedAmount' => (float)($summary['unmatched_amount'] ?? 0),
        'totalAmount' => (float)($summary['total_amount'] ?? 0)
    ],
    'cycles' => $cycles,
    'page' => $page,
    'limit' => $limit,
    'totalPages' => $totalPages,
    'totalCycles' => $totalCycles,
    'canManage' => $canManage,
    'filters' => [
        'financialYears' => $filterFinancialYears,
        'quarters' => ['Q1', 'Q2', 'Q3', 'Q4']
    ]
]);

$conn->close();

function bindParamsSafe(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }

    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}
?>
