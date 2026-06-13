<?php
header('Content-Type: application/json');
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

ensureFileMovementTables($conn);
ensureLifeCertificateTables($conn);
if (function_exists('ensureFileRegistryPerformanceIndexes')) {
    ensureFileRegistryPerformanceIndexes($conn);
}
if (function_exists('ensureStaffDuePerformanceIndexes')) {
    ensureStaffDuePerformanceIndexes($conn);
}
if (function_exists('maybeSyncCurrentYearLifeCertificateStatus')) {
    maybeSyncCurrentYearLifeCertificateStatus($conn);
} else {
    syncCurrentYearLifeCertificateStatus($conn);
}

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$statusFilter = trim((string)($_GET['status'] ?? ''));
$statusMap = [
    'submitted' => 'Submitted',
    'not_submitted' => 'Not Submitted',
    'exempt' => 'Exempt'
];
$statusValue = $statusMap[$statusFilter] ?? '';

$search = trim((string)($_GET['search'] ?? ''));
$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 20);
if ($page < 1) {
    $page = 1;
}
if ($limit < 5 || $limit > 200) {
    $limit = 20;
}
$offset = ($page - 1) * $limit;

$statusExpr = "
    CASE
        WHEN LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = 'deceased'
          OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
            THEN 'Exempt'
        WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
        ELSE 'Not Submitted'
    END
";

$baseWhere = " WHERE fr.regNo IS NOT NULL AND TRIM(fr.regNo) <> '' AND COALESCE(fr.is_deleted, 0) = 0 ";
$types = "i";
$params = [$year];

if ($search !== '') {
    $baseWhere .= "
        AND (
            fr.regNo LIKE ?
            OR fr.supplierNo LIKE ?
            OR fr.computerNo LIKE ?
            OR fr.sName LIKE ?
            OR fr.fName LIKE ?
            OR COALESCE(fr.telNo, '') LIKE ?
            OR COALESCE(sd.prisonUnit, '') LIKE ?
        )
    ";
    $like = '%' . $search . '%';
    $types .= str_repeat('s', 7);
    for ($i = 0; $i < 7; $i++) {
        $params[] = $like;
    }
}

$summarySql = "
    SELECT
        COUNT(*) AS total_records,
        SUM(CASE WHEN {$statusExpr} <> 'Exempt' THEN 1 ELSE 0 END) AS eligible_count,
        SUM(CASE WHEN {$statusExpr} = 'Submitted' THEN 1 ELSE 0 END) AS submitted_count,
        SUM(CASE WHEN {$statusExpr} = 'Not Submitted' THEN 1 ELSE 0 END) AS not_submitted_count,
        SUM(CASE WHEN {$statusExpr} = 'Exempt' THEN 1 ELSE 0 END) AS exempt_count
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    LEFT JOIN tb_life_certificate_submissions lcs
      ON lcs.regNo = fr.regNo
     AND lcs.submission_year = ?
    {$baseWhere}
";

$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare life certificate summary']);
    exit;
}

$summaryBind = [$types];
foreach ($params as $k => $v) {
    $summaryBind[] = &$params[$k];
}
call_user_func_array([$summaryStmt, 'bind_param'], $summaryBind);
$summaryStmt->execute();
$summaryRow = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$dataWhere = $baseWhere;
$dataTypes = $types;
$dataParams = $params;
if ($statusValue !== '') {
    $dataWhere .= " AND {$statusExpr} = ? ";
    $dataTypes .= "s";
    $dataParams[] = $statusValue;
}

$countSql = "
    SELECT COUNT(*) AS total
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    LEFT JOIN tb_life_certificate_submissions lcs
      ON lcs.regNo = fr.regNo
     AND lcs.submission_year = ?
    {$dataWhere}
";

$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare count query']);
    exit;
}
$countBind = [$dataTypes];
foreach ($dataParams as $k => $v) {
    $countBind[] = &$dataParams[$k];
}
call_user_func_array([$countStmt, 'bind_param'], $countBind);
$countStmt->execute();
$totalRows = (int)(($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$dataSql = "
    SELECT
        fr.regNo,
        fr.title,
        fr.sName,
        fr.fName,
        fr.supplierNo,
        COALESCE(fr.telNo, '') AS telNo,
        COALESCE(fr.payType, 'Pensioner') AS payType,
        COALESCE(fr.livingStatus, 'Alive') AS livingStatus,
        COALESCE(sd.prisonUnit, '') AS station,
        {$statusExpr} AS life_certificate_status,
        lcs.submitted_at,
        lcs.submitted_by
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    LEFT JOIN tb_life_certificate_submissions lcs
      ON lcs.regNo = fr.regNo
     AND lcs.submission_year = ?
    {$dataWhere}
    ORDER BY
      CASE {$statusExpr}
        WHEN 'Not Submitted' THEN 1
        WHEN 'Submitted' THEN 2
        ELSE 3
      END ASC,
      fr.sName ASC, fr.fName ASC
    LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare list query']);
    exit;
}
$listTypes = $dataTypes . "ii";
$listParams = $dataParams;
$listParams[] = $limit;
$listParams[] = $offset;
$listBind = [$listTypes];
foreach ($listParams as $k => $v) {
    $listBind[] = &$listParams[$k];
}
call_user_func_array([$dataStmt, 'bind_param'], $listBind);
$dataStmt->execute();
$res = $dataStmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = [
        'regNo' => $row['regNo'],
        'name' => trim((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? '')),
        'title' => $row['title'] ?? '',
        'supplierNo' => $row['supplierNo'] ?? '',
        'station' => $row['station'] ?? '',
        'telNo' => $row['telNo'] ?? '',
        'payType' => $row['payType'] ?? 'Pensioner',
        'livingStatus' => $row['livingStatus'] ?? 'Alive',
        'lifeCertificateStatus' => $row['life_certificate_status'] ?? 'Not Submitted',
        'submittedAt' => $row['submitted_at'] ?? null,
        'submittedBy' => $row['submitted_by'] ?? null
    ];
}
$dataStmt->close();

echo json_encode([
    'success' => true,
    'year' => $year,
    'summary' => [
        'total' => (int)($summaryRow['total_records'] ?? 0),
        'eligible' => (int)($summaryRow['eligible_count'] ?? 0),
        'submitted' => (int)($summaryRow['submitted_count'] ?? 0),
        'notSubmitted' => (int)($summaryRow['not_submitted_count'] ?? 0),
        'exempt' => (int)($summaryRow['exempt_count'] ?? 0)
    ],
    'canMarkSubmission' => roleHasAdminAccess($conn, $role) || in_array($role, ['clerk', 'data_entry', 'oc_pen'], true),
    'page' => $page,
    'limit' => $limit,
    'totalPages' => $totalPages,
    'totalRows' => $totalRows,
    'rows' => $rows
]);

$conn->close();
?>
