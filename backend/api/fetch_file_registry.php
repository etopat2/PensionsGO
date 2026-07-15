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

// Read endpoints must remain read-only and latency-sensitive. Schema migrations,
// index provisioning, and payroll reconciliation run in setup/maintenance flows.

$search = trim((string)($_GET['search'] ?? ''));
$boxNumber = trim((string)($_GET['box_number'] ?? ''));
$availability = trim((string)($_GET['availability'] ?? ''));
$payType = trim((string)($_GET['pay_type'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'recent'));
$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 24);

if ($page <= 0) {
    $page = 1;
}
if ($limit <= 0 || $limit > 100) {
    $limit = 24;
}

$offset = ($page - 1) * $limit;
$boxNumberOptions = function_exists('getRegistryBoxNumberOptions') ? getRegistryBoxNumberOptions($conn) : [];

$allowedSort = [
    'recent' => 'fr.timeStamp DESC, fr.id DESC',
    'name_asc' => 'fr.sName ASC, fr.fName ASC',
    'reg_asc' => 'fr.regNo ASC'
];
$orderBy = $allowedSort[$sort] ?? $allowedSort['recent'];
$payTypeExpr = "CASE
    WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
        THEN 'One-off Payment'
    ELSE 'Pensioner'
END";

$where = " WHERE COALESCE(fr.is_deleted, 0) = 0 ";
$types = '';
$params = [];

if ($search !== '') {
    // Search spans identity, registry identifiers, and assignment station fields.
    $where .= " AND (
        fr.regNo LIKE ?
        OR fr.computerNo LIKE ?
        OR fr.supplierNo LIKE ?
        OR fr.boxNo LIKE ?
        OR fr.sName LIKE ?
        OR fr.fName LIKE ?
        OR fr.title LIKE ?
        OR fr.NIN LIKE ?
        OR sd.prisonUnit LIKE ?
        OR COALESCE(fr.telNo, sd.telNo) LIKE ?
    ) ";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like, $like]);
    $types .= 'ssssssssss';
}

if ($boxNumber !== '') {
    $where .= " AND TRIM(COALESCE(fr.boxNo, '')) = ? ";
    $params[] = $boxNumber;
    $types .= 's';
}

if ($availability !== '' && in_array($availability, ['in_shelf', 'out_of_shelf'], true)) {
    $where .= " AND fr.availability_status = ? ";
    $params[] = $availability;
    $types .= 's';
}

if ($payType !== '' && in_array($payType, ['Pensioner', 'One-off Payment'], true)) {
    $where .= " AND {$payTypeExpr} = ? ";
    $params[] = $payType;
    $types .= 's';
}

$countJoinSql = $search !== '' ? " LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo " : "";

$countSql = "
    SELECT COUNT(*) AS total
    FROM tb_fileregistry fr
    {$countJoinSql}
    {$where}
";

$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare count query']);
    exit;
}
if ($types !== '') {
    $bindParams = [$types];
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }
    call_user_func_array([$countStmt, 'bind_param'], $bindParams);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRecords = (int)(($countResult->fetch_assoc()['total'] ?? 0));
$countStmt->close();

$totalPages = max(1, (int)ceil($totalRecords / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$dataSql = "
    SELECT
        fr.id,
        fr.regNo,
        fr.boxNo,
        fr.computerNo,
        fr.supplierNo,
        fr.title,
        fr.sName,
        fr.fName,
        fr.livingStatus,
        fr.payType,
        fr.lifeCertificate,
        fr.payrollStatus,
        fr.retirementDate,
        fr.retirementType,
        fr.availability_status,
        fr.availability_reason,
        CASE
            WHEN LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = 'deceased'
              OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                THEN 'Exempt'
            WHEN EXISTS (
                SELECT 1 FROM tb_life_certificate_submissions lcs
                WHERE lcs.regNo = fr.regNo AND lcs.submission_year = YEAR(CURDATE())
            ) THEN 'Submitted'
            ELSE 'Not Submitted'
        END AS lifeCertificateStatus,
        fr.timeStamp,
        sd.prisonUnit AS station,
        COALESCE(fr.telNo, sd.telNo) AS phone
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
    {$where}
    ORDER BY {$orderBy}
    LIMIT ? OFFSET ?
";

$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare data query']);
    exit;
}

$dataTypes = $types . 'ii';
$dataParams = $params;
$dataParams[] = $limit;
$dataParams[] = $offset;
$dataBindParams = [$dataTypes];
foreach ($dataParams as $key => $value) {
    $dataBindParams[] = &$dataParams[$key];
}
call_user_func_array([$dataStmt, 'bind_param'], $dataBindParams);
$dataStmt->execute();
$result = $dataStmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = [
        'id' => (int)$row['id'],
        'regNo' => $row['regNo'],
        'boxNo' => $row['boxNo'],
        'computerNo' => $row['computerNo'],
        'supplierNo' => $row['supplierNo'],
        'title' => $row['title'],
        'sName' => $row['sName'],
        'fName' => $row['fName'],
        'name' => formatTitleName((string)($row['title'] ?? ''), (string)($row['sName'] ?? ''), (string)($row['fName'] ?? '')),
        'livingStatus' => $row['livingStatus'] ?? '',
        'payType' => $row['payType'] ?? '',
        'lifeCertificate' => $row['lifeCertificateStatus'] ?? ($row['lifeCertificate'] ?? 'Not Submitted'),
        'payrollStatus' => $row['payrollStatus'] ?? 'Not on Payroll',
        'retirementDate' => $row['retirementDate'] ?? '',
        'retirementType' => $row['retirementType'] ?? '',
        'station' => $row['station'] ?? '',
        'phone' => $row['phone'] ?? '',
        'availability_status' => $row['availability_status'] ?? 'in_shelf',
        'availability_reason' => $row['availability_reason'] ?? '',
        'timeStamp' => $row['timeStamp'] ?? ''
    ];
}
$dataStmt->close();

echo json_encode([
    'success' => true,
    'page' => $page,
    'limit' => $limit,
    'totalRecords' => $totalRecords,
    'totalPages' => $totalPages,
    'boxNumberOptions' => $boxNumberOptions,
    'records' => $records
]);
$conn->close();
?>
