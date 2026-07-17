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

ensurePayrollManagementTables($conn);
if (function_exists('maybeReconcileAllActivePayrollCycles')) {
    try {
        maybeReconcileAllActivePayrollCycles($conn);
    } catch (Throwable $syncError) {
        error_log('get_payroll_dashboard reconciliation failed: ' . $syncError->getMessage());
    }
}

$rawYear = trim((string)($_GET['year'] ?? ''));
$rawMonth = trim((string)($_GET['month'] ?? ''));
$year = $rawYear !== '' ? (int)$rawYear : 0;
$month = $rawMonth !== '' ? (int)$rawMonth : 0;
$financialYear = trim((string)($_GET['financial_year'] ?? ''));
$quarter = trim((string)($_GET['quarter'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));

$page = (int)($_GET['page'] ?? 1);
$limit = (int)($_GET['limit'] ?? 20);
if ($page < 1) $page = 1;
if ($limit < 5 || $limit > 200) $limit = 20;
$offset = ($page - 1) * $limit;

$latestCycle = function_exists('getLatestActivePayrollCycleInfo') ? getLatestActivePayrollCycleInfo($conn) : null;
$latestCycleYear = (int)($latestCycle['payroll_year'] ?? 0);
$latestCycleMonth = (int)($latestCycle['payroll_month'] ?? 0);
$usedLatestFallback = false;

$yearIsValid = ($year >= 2000 && $year <= 2100);
$monthIsValid = ($month >= 1 && $month <= 12);
if ((!$yearIsValid || !$monthIsValid) && $latestCycleYear > 0 && $latestCycleMonth >= 1 && $latestCycleMonth <= 12) {
    $year = $latestCycleYear;
    $month = $latestCycleMonth;
    $usedLatestFallback = true;
}

if ($year < 2000 || $year > 2100) $year = (int)date('Y');
if ($month < 1 || $month > 12) $month = (int)date('n');
if (!in_array($quarter, ['', 'Q1', 'Q2', 'Q3', 'Q4'], true)) {
    $quarter = '';
}
$statusValue = '';
if ($statusFilter === 'on') $statusValue = 'On Payroll';
if ($statusFilter === 'off') $statusValue = 'Not on Payroll';

$where = " WHERE pms.payroll_year = ? AND pms.payroll_month = ? AND (pms.cycle_id IS NULL OR COALESCE(pc.is_deleted, 0) = 0) ";
$types = "ii";
$params = [$year, $month];

if ($financialYear !== '') {
    $where .= " AND pms.financial_year_label = ? ";
    $types .= "s";
    $params[] = $financialYear;
}
if ($quarter !== '') {
    $where .= " AND pms.quarter_label = ? ";
    $types .= "s";
    $params[] = $quarter;
}
if ($statusValue !== '') {
    $where .= " AND pms.payroll_status = ? ";
    $types .= "s";
    $params[] = $statusValue;
}
if ($search !== '') {
    $where .= " AND (
        pms.regNo LIKE ?
        OR COALESCE(fr.sName, '') LIKE ?
        OR COALESCE(fr.fName, '') LIKE ?
        OR COALESCE(pms.supplierNo, '') LIKE ?
    ) ";
    $like = '%' . $search . '%';
    $types .= "ssss";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$summarySql = "
    SELECT
        COUNT(*) AS total_records,
        SUM(CASE WHEN pms.payroll_status = 'On Payroll' THEN 1 ELSE 0 END) AS on_payroll_count,
        SUM(CASE WHEN pms.payroll_status = 'Not on Payroll' THEN 1 ELSE 0 END) AS off_payroll_count,
        SUM(CASE WHEN pms.payroll_status = 'On Payroll' THEN pms.amount ELSE 0 END) AS on_payroll_amount
    FROM tb_registry_payroll_monthly_status pms
    LEFT JOIN tb_payroll_upload_cycles pc ON pc.cycle_id = pms.cycle_id
    LEFT JOIN tb_fileregistry fr ON fr.regNo = pms.regNo
    {$where}
";

$summaryStmt = $conn->prepare($summarySql);
if (!$summaryStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll summary query']);
    exit;
}
$summaryBind = [$types];
foreach ($params as $k => $v) {
    $summaryBind[] = &$params[$k];
}
call_user_func_array([$summaryStmt, 'bind_param'], $summaryBind);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$countSql = "
    SELECT COUNT(*) AS total
    FROM tb_registry_payroll_monthly_status pms
    LEFT JOIN tb_payroll_upload_cycles pc ON pc.cycle_id = pms.cycle_id
    LEFT JOIN tb_fileregistry fr ON fr.regNo = pms.regNo
    {$where}
";
$countStmt = $conn->prepare($countSql);
if (!$countStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll count query']);
    exit;
}
$countBind = [$types];
foreach ($params as $k => $v) {
    $countBind[] = &$params[$k];
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
        pms.regNo,
        pms.supplierNo,
        pms.payroll_status,
        pms.amount,
        pms.financial_year_label,
        pms.quarter_label,
        pms.payroll_year,
        pms.payroll_month,
        pms.updated_at,
        COALESCE(fr.title, '') AS title,
        COALESCE(fr.sName, '') AS sName,
        COALESCE(fr.fName, '') AS fName,
        COALESCE(fr.payType, '') AS payType,
        COALESCE(fr.livingStatus, '') AS livingStatus
    FROM tb_registry_payroll_monthly_status pms
    LEFT JOIN tb_payroll_upload_cycles pc ON pc.cycle_id = pms.cycle_id
    LEFT JOIN tb_fileregistry fr ON fr.regNo = pms.regNo
    {$where}
    ORDER BY pms.payroll_status ASC, fr.sName ASC, fr.fName ASC
    LIMIT ? OFFSET ?
";
$dataStmt = $conn->prepare($dataSql);
if (!$dataStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to prepare payroll list query']);
    exit;
}
$listTypes = $types . "ii";
$listParams = $params;
$listParams[] = $limit;
$listParams[] = $offset;
$listBind = [$listTypes];
foreach ($listParams as $k => $v) {
    $listBind[] = &$listParams[$k];
}
call_user_func_array([$dataStmt, 'bind_param'], $listBind);
$dataStmt->execute();
$result = $dataStmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'regNo' => $row['regNo'],
        'name' => trim((string)($row['sName'] ?? '') . ' ' . (string)($row['fName'] ?? '')),
        'title' => $row['title'] ?? '',
        'supplierNo' => $row['supplierNo'] ?? '',
        'payrollStatus' => $row['payroll_status'] ?? 'Not on Payroll',
        'amount' => (float)($row['amount'] ?? 0),
        'financialYear' => $row['financial_year_label'] ?? '',
        'quarter' => $row['quarter_label'] ?? '',
        'year' => (int)($row['payroll_year'] ?? 0),
        'month' => (int)($row['payroll_month'] ?? 0),
        'payType' => $row['payType'] ?? '',
        'livingStatus' => $row['livingStatus'] ?? '',
        'updatedAt' => $row['updated_at'] ?? null
    ];
}
$dataStmt->close();

$uploadedAmountWhere = "
    WHERE c.payroll_year = ?
      AND c.payroll_month = ?
      AND COALESCE(c.is_deleted, 0) = 0
";
$uploadedAmountTypes = "ii";
$uploadedAmountParams = [$year, $month];

if ($financialYear !== '') {
    $uploadedAmountWhere .= " AND c.financial_year_label = ? ";
    $uploadedAmountTypes .= "s";
    $uploadedAmountParams[] = $financialYear;
}
if ($quarter !== '') {
    $uploadedAmountWhere .= " AND c.quarter_label = ? ";
    $uploadedAmountTypes .= "s";
    $uploadedAmountParams[] = $quarter;
}
if ($search !== '') {
    $uploadedAmountWhere .= " AND (
        e.supplierNo LIKE ?
        OR COALESCE(e.beneficiary_name, '') LIKE ?
        OR COALESCE(e.matched_regNo, '') LIKE ?
    ) ";
    $searchLike = '%' . $search . '%';
    $uploadedAmountTypes .= "sss";
    $uploadedAmountParams[] = $searchLike;
    $uploadedAmountParams[] = $searchLike;
    $uploadedAmountParams[] = $searchLike;
}

$uploadedAmountSql = "
    SELECT COALESCE(SUM(e.amount), 0) AS payroll_uploaded_amount
    FROM tb_payroll_upload_entries e
    INNER JOIN tb_payroll_upload_cycles c ON c.cycle_id = e.cycle_id
    {$uploadedAmountWhere}
";
$uploadedAmount = 0.0;
$uploadedStmt = $conn->prepare($uploadedAmountSql);
if ($uploadedStmt) {
    $uploadedBind = [$uploadedAmountTypes];
    foreach ($uploadedAmountParams as $k => $v) {
        $uploadedBind[] = &$uploadedAmountParams[$k];
    }
    call_user_func_array([$uploadedStmt, 'bind_param'], $uploadedBind);
    $uploadedStmt->execute();
    $uploadedAmount = (float)(($uploadedStmt->get_result()->fetch_assoc()['payroll_uploaded_amount'] ?? 0));
    $uploadedStmt->close();
}

$paymentReconciliation = ['successfulPayments'=>0,'successfulAmount'=>0.0,'paymentExceptions'=>0,'notInRegister'=>0];
$paymentStmt=$conn->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(amount_paid),0) AS amount,SUM(CASE WHEN reconciliation_status IN ('Partially Paid','Paid with Adjustment','Register Only','Needs Review') THEN 1 ELSE 0 END) AS exceptions,SUM(CASE WHEN reconciliation_status='Not in Register' THEN 1 ELSE 0 END) AS not_in_register FROM tb_payroll_payment_register_entries r INNER JOIN tb_payroll_upload_cycles c ON c.cycle_id=r.cycle_id WHERE c.payroll_year=? AND c.payroll_month=? AND COALESCE(c.is_deleted,0)=0");
if($paymentStmt){$paymentStmt->bind_param('ii',$year,$month);$paymentStmt->execute();$paymentRow=$paymentStmt->get_result()->fetch_assoc();$paymentStmt->close();$paymentReconciliation=['successfulPayments'=>(int)($paymentRow['total']??0)-(int)($paymentRow['not_in_register']??0),'successfulAmount'=>(float)($paymentRow['amount']??0),'paymentExceptions'=>(int)($paymentRow['exceptions']??0),'notInRegister'=>(int)($paymentRow['not_in_register']??0)];}

$cycles = [];
$cycleStmt = $conn->prepare("
    SELECT
        cycle_id,
        payroll_year,
        payroll_month,
        financial_year_label,
        quarter_label,
        source_file,
        source_file_original_name,
        payment_register_file,
        payment_register_original_name,
        created_at
    FROM tb_payroll_upload_cycles
    WHERE COALESCE(is_deleted, 0) = 0
    ORDER BY created_at DESC
    LIMIT 12
");
if ($cycleStmt) {
    $cycleStmt->execute();
    $cycleRes = $cycleStmt->get_result();
    while ($row = $cycleRes->fetch_assoc()) {
        $cycles[] = [
            'cycleId' => (int)$row['cycle_id'],
            'year' => (int)$row['payroll_year'],
            'month' => (int)$row['payroll_month'],
            'financialYear' => $row['financial_year_label'],
            'quarter' => $row['quarter_label'],
            'sourceFile' => $row['source_file'] ?? '',
            'sourceFileName' => $row['source_file_original_name'] ?? '',
            'paymentRegisterFile' => $row['payment_register_file'] ?? '',
            'paymentRegisterFileName' => $row['payment_register_original_name'] ?? '',
            'createdAt' => $row['created_at']
        ];
    }
    $cycleStmt->close();
}

$filterMeta = [
    'financialYears' => [],
    'quarters' => ['Q1', 'Q2', 'Q3', 'Q4']
];
$fyRes = $conn->query("SELECT DISTINCT financial_year_label FROM tb_registry_payroll_monthly_status ORDER BY financial_year_label DESC");
if ($fyRes) {
    while ($row = $fyRes->fetch_assoc()) {
        $value = trim((string)($row['financial_year_label'] ?? ''));
        if ($value !== '') {
            $filterMeta['financialYears'][] = $value;
        }
    }
}

echo json_encode([
    'success' => true,
    'selectedPeriod' => [
        'year' => $year,
        'month' => $month
    ],
    'latestAvailablePeriod' => [
        'year' => $latestCycleYear,
        'month' => $latestCycleMonth
    ],
    'usedLatestFallback' => $usedLatestFallback,
    'summary' => [
        'total' => (int)($summary['total_records'] ?? 0),
        'onPayroll' => (int)($summary['on_payroll_count'] ?? 0),
        'offPayroll' => (int)($summary['off_payroll_count'] ?? 0),
        'onPayrollAmount' => (float)($summary['on_payroll_amount'] ?? 0),
        'payrollUploadedAmount' => (float)$uploadedAmount
        ,'successfulPayments' => $paymentReconciliation['successfulPayments']
        ,'successfulPaymentAmount' => $paymentReconciliation['successfulAmount']
        ,'paymentExceptions' => $paymentReconciliation['paymentExceptions']
        ,'notInPaymentRegister' => $paymentReconciliation['notInRegister']
    ],
    'page' => $page,
    'limit' => $limit,
    'totalPages' => $totalPages,
    'totalRows' => $totalRows,
    'rows' => $rows,
    'recentCycles' => $cycles,
    'filters' => $filterMeta,
    'canUpload' => currentUserHasPermission($conn, 'payroll.upload')
]);

$conn->close();
?>
