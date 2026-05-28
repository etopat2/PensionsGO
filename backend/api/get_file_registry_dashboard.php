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

if (function_exists('ensureLifeCertificateTables')) {
    ensureLifeCertificateTables($conn);
}
if (function_exists('ensureFileRegistryPerformanceIndexes')) {
    ensureFileRegistryPerformanceIndexes($conn);
}
if (function_exists('ensureStaffDuePerformanceIndexes')) {
    ensureStaffDuePerformanceIndexes($conn);
}
if (function_exists('maybeReconcileAllActivePayrollCycles')) {
    try {
        maybeReconcileAllActivePayrollCycles($conn);
    } catch (Throwable $syncError) {
        error_log('get_file_registry_dashboard payroll reconciliation failed: ' . $syncError->getMessage());
    }
}

function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '' || empty($params)) {
        return;
    }
    $bindArgs = [];
    $bindArgs[] = &$types;
    foreach ($params as $idx => $value) {
        $bindArgs[] = &$params[$idx];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

try {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = max(10, min(200, (int)($_GET['limit'] ?? 12)));
    $offset = ($page - 1) * $limit;
    $boxNumberOptions = function_exists('getRegistryBoxNumberOptions') ? getRegistryBoxNumberOptions($conn) : [];

    $search = trim((string)($_GET['search'] ?? ''));
    $boxNumber = trim((string)($_GET['box_number'] ?? ''));
    $gender = trim((string)($_GET['gender'] ?? ''));
    $livingStatus = trim((string)($_GET['living_status'] ?? ''));
    $payType = trim((string)($_GET['pay_type'] ?? ''));
    $payrollStatus = trim((string)($_GET['payroll_status'] ?? ''));
    $availability = trim((string)($_GET['availability_status'] ?? ''));
    $lifeCert = trim((string)($_GET['life_certificate_status'] ?? ''));

    $deathTypeExpr = buildBenefitsRetirementTypeMatchSql(
        $conn,
        "COALESCE(NULLIF(fr.retirementType, ''), NULLIF(sd.retirementType, ''))",
        'death'
    );
    $livingExpr = "COALESCE(fr.livingStatus, sd.livingStatus, CASE WHEN {$deathTypeExpr} THEN 'Deceased' ELSE 'Alive' END)";
    $payTypeExpr = "CASE
        WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
            THEN 'One-off Payment'
        ELSE 'Pensioner'
    END";
    $payrollExpr = "COALESCE(NULLIF(fr.payrollStatus, ''), 'Not on Payroll')";
    $availabilityExpr = "COALESCE(fr.availability_status, 'in_shelf')";
    $lifeCertExpr = "CASE
        WHEN LOWER(TRIM({$livingExpr})) = 'deceased'
          OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
            THEN 'Exempt'
        WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
        ELSE 'Not Submitted'
    END";

    $where = ["COALESCE(fr.is_deleted, 0) = 0"];
    $params = [];
    $types = '';

    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $where[] = "(
            fr.regNo LIKE ?
            OR fr.computerNo LIKE ?
            OR fr.supplierNo LIKE ?
            OR fr.boxNo LIKE ?
            OR fr.title LIKE ?
            OR fr.sName LIKE ?
            OR fr.fName LIKE ?
            OR fr.NIN LIKE ?
            OR fr.TIN LIKE ?
            OR COALESCE(fr.telNo, sd.telNo) LIKE ?
            OR COALESCE(fr.applicant_email, sd.applicant_email) LIKE ?
            OR sd.prisonUnit LIKE ?
        )";
        $params = array_merge($params, array_fill(0, 12, $pattern));
        $types .= str_repeat('s', 12);
    }

    if ($boxNumber !== '') {
        $where[] = "TRIM(COALESCE(fr.boxNo, '')) = ?";
        $params[] = $boxNumber;
        $types .= 's';
    }

    if ($gender !== '' && in_array($gender, ['Male', 'Female'], true)) {
        $where[] = "LOWER(TRIM(COALESCE(fr.gender, ''))) = ?";
        $params[] = strtolower($gender);
        $types .= 's';
    }

    if ($livingStatus !== '' && in_array($livingStatus, ['Alive', 'Deceased'], true)) {
        $where[] = "LOWER(TRIM({$livingExpr})) = ?";
        $params[] = strtolower($livingStatus);
        $types .= 's';
    }

    if ($payType !== '' && in_array($payType, ['Pensioner', 'One-off Payment'], true)) {
        $where[] = "{$payTypeExpr} = ?";
        $params[] = $payType;
        $types .= 's';
    }

    if ($payrollStatus !== '' && in_array($payrollStatus, ['On Payroll', 'Not on Payroll'], true)) {
        $where[] = "{$payrollExpr} = ?";
        $params[] = $payrollStatus;
        $types .= 's';
    }

    if ($availability !== '' && in_array($availability, ['in_shelf', 'out_of_shelf'], true)) {
        $where[] = "LOWER(TRIM({$availabilityExpr})) = ?";
        $params[] = strtolower($availability);
        $types .= 's';
    }

    if ($lifeCert !== '' && in_array($lifeCert, ['Submitted', 'Not Submitted', 'Exempt'], true)) {
        $where[] = "{$lifeCertExpr} = ?";
        $params[] = $lifeCert;
        $types .= 's';
    }

    $whereSql = implode(' AND ', $where);
    $joinSql = "
        FROM tb_fileregistry fr
        LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
        LEFT JOIN tb_life_certificate_submissions lcs
          ON lcs.regNo = fr.regNo
         AND lcs.submission_year = YEAR(CURDATE())
    ";

    $countSql = "SELECT COUNT(*) AS total_rows {$joinSql} WHERE {$whereSql}";
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new RuntimeException('Failed to prepare registry count query');
    }
    $countParams = $params;
    $countTypes = $types;
    bindDynamic($countStmt, $countTypes, $countParams);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $countStmt->close();
    $totalRows = (int)($countRow['total_rows'] ?? 0);
    $totalPages = max(1, (int)ceil($totalRows / $limit));
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

    $summarySql = "
        SELECT
            COUNT(*) AS total_records,
            SUM(CASE WHEN LOWER(TRIM({$livingExpr})) = 'alive' THEN 1 ELSE 0 END) AS alive_count,
            SUM(CASE WHEN LOWER(TRIM({$livingExpr})) = 'deceased' THEN 1 ELSE 0 END) AS deceased_count,
            SUM(CASE WHEN {$payrollExpr} = 'On Payroll' THEN 1 ELSE 0 END) AS on_payroll_count,
            SUM(CASE WHEN {$payrollExpr} <> 'On Payroll' THEN 1 ELSE 0 END) AS off_payroll_count,
            SUM(CASE WHEN LOWER(TRIM({$availabilityExpr})) = 'out_of_shelf' THEN 1 ELSE 0 END) AS out_of_shelf_count,
            SUM(CASE WHEN {$lifeCertExpr} = 'Not Submitted' THEN 1 ELSE 0 END) AS life_not_submitted_count,
            SUM(CASE WHEN {$lifeCertExpr} = 'Submitted' THEN 1 ELSE 0 END) AS life_submitted_count,
            SUM(CASE WHEN {$lifeCertExpr} = 'Exempt' THEN 1 ELSE 0 END) AS life_exempt_count
        {$joinSql}
        WHERE {$whereSql}
    ";
    $summaryStmt = $conn->prepare($summarySql);
    if (!$summaryStmt) {
        throw new RuntimeException('Failed to prepare registry summary query');
    }
    $summaryParams = $params;
    $summaryTypes = $types;
    bindDynamic($summaryStmt, $summaryTypes, $summaryParams);
    $summaryStmt->execute();
    $summaryRow = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    $rowsSql = "
        SELECT
            fr.id,
            fr.regNo,
            fr.computerNo,
            fr.supplierNo,
            fr.title,
            fr.sName,
            fr.fName,
            fr.gender,
            {$livingExpr} AS living_status,
            {$payTypeExpr} AS pay_type,
            {$payrollExpr} AS payroll_status,
            {$availabilityExpr} AS availability_status,
            {$lifeCertExpr} AS life_certificate_status,
            COALESCE(fr.retirementType, sd.retirementType) AS retirement_type,
            fr.retirementDate,
            sd.prisonUnit AS station,
            COALESCE(fr.telNo, sd.telNo) AS phone
        {$joinSql}
        WHERE {$whereSql}
        ORDER BY fr.regNo ASC
        LIMIT ? OFFSET ?
    ";
    $rowsStmt = $conn->prepare($rowsSql);
    if (!$rowsStmt) {
        throw new RuntimeException('Failed to prepare registry data query');
    }
    $rowsParams = $params;
    $rowsTypes = $types . 'ii';
    $rowsParams[] = $limit;
    $rowsParams[] = $offset;
    bindDynamic($rowsStmt, $rowsTypes, $rowsParams);
    $rowsStmt->execute();
    $rowsRes = $rowsStmt->get_result();
    $rows = [];
    while ($row = $rowsRes->fetch_assoc()) {
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'regNo' => (string)($row['regNo'] ?? ''),
            'computerNo' => (string)($row['computerNo'] ?? ''),
            'supplierNo' => (string)($row['supplierNo'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'sName' => (string)($row['sName'] ?? ''),
            'fName' => (string)($row['fName'] ?? ''),
            'gender' => (string)($row['gender'] ?? ''),
            'livingStatus' => (string)($row['living_status'] ?? ''),
            'payType' => (string)($row['pay_type'] ?? ''),
            'payrollStatus' => (string)($row['payroll_status'] ?? ''),
            'availabilityStatus' => (string)($row['availability_status'] ?? ''),
            'lifeCertificateStatus' => (string)($row['life_certificate_status'] ?? ''),
            'retirementType' => (string)($row['retirement_type'] ?? ''),
            'retirementDate' => (string)($row['retirementDate'] ?? ''),
            'station' => (string)($row['station'] ?? ''),
            'phone' => (string)($row['phone'] ?? '')
        ];
    }
    $rowsStmt->close();

    echo json_encode([
        'success' => true,
        'summary' => [
            'total' => (int)($summaryRow['total_records'] ?? 0),
            'alive' => (int)($summaryRow['alive_count'] ?? 0),
            'deceased' => (int)($summaryRow['deceased_count'] ?? 0),
            'onPayroll' => (int)($summaryRow['on_payroll_count'] ?? 0),
            'offPayroll' => (int)($summaryRow['off_payroll_count'] ?? 0),
            'outOfShelf' => (int)($summaryRow['out_of_shelf_count'] ?? 0),
            'lifeNotSubmitted' => (int)($summaryRow['life_not_submitted_count'] ?? 0),
            'lifeSubmitted' => (int)($summaryRow['life_submitted_count'] ?? 0),
            'lifeExempt' => (int)($summaryRow['life_exempt_count'] ?? 0)
        ],
        'boxNumberOptions' => $boxNumberOptions,
        'rows' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'totalRows' => $totalRows,
            'totalPages' => $totalPages
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('get_file_registry_dashboard error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to load file registry data']);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
