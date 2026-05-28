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
$canManage = ($role === 'admin');

ensurePayrollManagementTables($conn);

$cycleId = (int)($_GET['cycle_id'] ?? 0);
if ($cycleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid cycle_id is required']);
    exit;
}

$status = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($status, ['all', 'matched', 'unmatched'], true)) {
    $status = 'all';
}

$search = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 25);
if ($limit < 5 || $limit > 200) {
    $limit = 25;
}
$offset = ($page - 1) * $limit;

$cycleStmt = $conn->prepare("SELECT
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
WHERE c.cycle_id = ?
  AND COALESCE(c.is_deleted, 0) = 0
GROUP BY c.cycle_id
LIMIT 1");
if (!$cycleStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll cycle query']);
    exit;
}
$cycleStmt->bind_param('i', $cycleId);
$cycleStmt->execute();
$cycle = $cycleStmt->get_result()->fetch_assoc();
$cycleStmt->close();

if (!$cycle) {
    echo json_encode(['success' => false, 'message' => 'Payroll upload cycle not found']);
    exit;
}

$where = " WHERE e.cycle_id = ? ";
$types = 'i';
$params = [$cycleId];

if ($status === 'matched') {
    $where .= " AND e.is_matched = 1 ";
}
if ($status === 'unmatched') {
    $where .= " AND e.is_matched = 0 ";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (
        COALESCE(e.supplierNo, '') LIKE ?
        OR COALESCE(e.beneficiary_name, '') LIKE ?
        OR COALESCE(e.matched_regNo, '') LIKE ?
        OR COALESCE(fr.sName, '') LIKE ?
        OR COALESCE(fr.fName, '') LIKE ?
        OR COALESCE(fr.title, '') LIKE ?
        OR COALESCE(fr.supplierNo, '') LIKE ?
    ) ";
    $types .= 'sssssss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$filteredSummarySql = "
    SELECT
        COUNT(e.entry_id) AS total_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 1 THEN 1 ELSE 0 END), 0) AS matched_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 0 THEN 1 ELSE 0 END), 0) AS unmatched_rows,
        COALESCE(SUM(CASE WHEN e.is_matched = 1 THEN e.amount ELSE 0 END), 0) AS matched_amount,
        COALESCE(SUM(CASE WHEN e.is_matched = 0 THEN e.amount ELSE 0 END), 0) AS unmatched_amount,
        COALESCE(SUM(e.amount), 0) AS total_amount
    FROM tb_payroll_upload_entries e
    LEFT JOIN tb_fileregistry fr ON fr.regNo = e.matched_regNo
    {$where}
";
$summaryStmt = $conn->prepare($filteredSummarySql);
if (!$summaryStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll detail summary query']);
    exit;
}
bindParamsSafe($summaryStmt, $types, $params);
$summaryStmt->execute();
$filteredSummary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$countSql = "
    SELECT COUNT(*) AS total
    FROM tb_payroll_upload_entries e
    LEFT JOIN tb_fileregistry fr ON fr.regNo = e.matched_regNo
    {$where}
";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll details count query']);
    exit;
}
bindParamsSafe($countStmt, $types, $params);
$countStmt->execute();
$totalRows = (int)(($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$listSql = "
    SELECT
        e.entry_id,
        e.supplierNo,
        e.beneficiary_name,
        e.amount,
        e.matched_regNo,
        e.is_matched,
        e.created_at,
        COALESCE(fr.sName, '') AS sName,
        COALESCE(fr.fName, '') AS fName,
        COALESCE(fr.title, '') AS title,
        COALESCE(fr.supplierNo, '') AS registrySupplierNo,
        COALESCE(fr.livingStatus, '') AS livingStatus,
        COALESCE(fr.payType, '') AS payType
    FROM tb_payroll_upload_entries e
    LEFT JOIN tb_fileregistry fr ON fr.regNo = e.matched_regNo
    {$where}
    ORDER BY e.entry_id ASC
    LIMIT ? OFFSET ?
";
$listStmt = $conn->prepare($listSql);
if (!$listStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll details list query']);
    exit;
}
$listTypes = $types . 'ii';
$listParams = $params;
$listParams[] = $limit;
$listParams[] = $offset;
bindParamsSafe($listStmt, $listTypes, $listParams);
$listStmt->execute();
$listResult = $listStmt->get_result();

$rows = [];
while ($row = $listResult->fetch_assoc()) {
    $name = trim((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? ''));
    $rows[] = [
        'entryId' => (int)$row['entry_id'],
        'supplierNo' => (string)($row['supplierNo'] ?? ''),
        'beneficiaryName' => (string)($row['beneficiary_name'] ?? ''),
        'amount' => (float)($row['amount'] ?? 0),
        'isMatched' => (int)($row['is_matched'] ?? 0) === 1,
        'matchedRegNo' => (string)($row['matched_regNo'] ?? ''),
        'matchedName' => $name,
        'title' => (string)($row['title'] ?? ''),
        'registrySupplierNo' => (string)($row['registrySupplierNo'] ?? ''),
        'livingStatus' => (string)($row['livingStatus'] ?? ''),
        'payType' => (string)($row['payType'] ?? ''),
        'createdAt' => (string)($row['created_at'] ?? ''),
        'matchReason' => ((int)($row['is_matched'] ?? 0) === 1)
            ? 'Matched by Supplier Number'
            : 'No registry supplier match'
    ];
}
$listStmt->close();

echo json_encode([
    'success' => true,
    'cycle' => [
        'cycleId' => (int)$cycle['cycle_id'],
        'year' => (int)$cycle['payroll_year'],
        'month' => (int)$cycle['payroll_month'],
        'financialYear' => (string)$cycle['financial_year_label'],
        'quarter' => (string)$cycle['quarter_label'],
        'uploadedBy' => (string)$cycle['uploaded_by'],
        'uploadedByName' => (string)$cycle['uploaded_by_name'],
        'uploadedByEmail' => (string)$cycle['uploaded_by_email'],
        'sourceFile' => (string)($cycle['source_file'] ?? ''),
        'sourceFileName' => (string)($cycle['source_file_original_name'] ?? ''),
        'sourceFileMime' => (string)($cycle['source_file_mime'] ?? ''),
        'paymentRegisterFile' => (string)($cycle['payment_register_file'] ?? ''),
        'paymentRegisterFileName' => (string)($cycle['payment_register_original_name'] ?? ''),
        'paymentRegisterMime' => (string)($cycle['payment_register_mime'] ?? ''),
        'notes' => (string)($cycle['notes'] ?? ''),
        'createdAt' => (string)$cycle['created_at'],
        'summary' => [
            'totalRows' => (int)$cycle['total_rows'],
            'matchedRows' => (int)$cycle['matched_rows'],
            'unmatchedRows' => (int)$cycle['unmatched_rows'],
            'matchedAmount' => (float)$cycle['matched_amount'],
            'unmatchedAmount' => (float)$cycle['unmatched_amount'],
            'totalAmount' => (float)$cycle['total_amount']
        ]
    ],
    'filteredSummary' => [
        'totalRows' => (int)($filteredSummary['total_rows'] ?? 0),
        'matchedRows' => (int)($filteredSummary['matched_rows'] ?? 0),
        'unmatchedRows' => (int)($filteredSummary['unmatched_rows'] ?? 0),
        'matchedAmount' => (float)($filteredSummary['matched_amount'] ?? 0),
        'unmatchedAmount' => (float)($filteredSummary['unmatched_amount'] ?? 0),
        'totalAmount' => (float)($filteredSummary['total_amount'] ?? 0)
    ],
    'rows' => $rows,
    'canManage' => $canManage,
    'status' => $status,
    'page' => $page,
    'limit' => $limit,
    'totalRows' => $totalRows,
    'totalPages' => $totalPages
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
