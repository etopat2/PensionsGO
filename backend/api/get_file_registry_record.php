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

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}
if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}
if (function_exists('ensureStaffDocumentsTable')) {
    ensureStaffDocumentsTable($conn);
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
if (function_exists('maybeSyncCurrentYearLifeCertificateStatus')) {
    maybeSyncCurrentYearLifeCertificateStatus($conn);
} elseif (function_exists('syncCurrentYearLifeCertificateStatus')) {
    syncCurrentYearLifeCertificateStatus($conn);
}
if (function_exists('maybeReconcileAllActivePayrollCycles')) {
    try {
        maybeReconcileAllActivePayrollCycles($conn);
    } catch (Throwable $syncError) {
        error_log('get_file_registry_record payroll reconciliation failed: ' . $syncError->getMessage());
    }
}

if (function_exists('maybeApplyDocumentRetentionRules')) {
    maybeApplyDocumentRetentionRules($conn);
} else {
    applyDocumentRetentionRules($conn);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
    exit;
}

$dateOn15Expr = "COALESCE(fr.dateOn15yrs, CASE WHEN fr.retirementDate IS NOT NULL THEN DATE_ADD(fr.retirementDate, INTERVAL 15 YEAR) ELSE NULL END)";
$periodToExpr = "
    CASE
        WHEN {$dateOn15Expr} IS NULL THEN 'N/A'
        WHEN CURDATE() < {$dateOn15Expr} THEN CONCAT(
            TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}), ' Years, ',
            TIMESTAMPDIFF(MONTH, DATE_ADD(CURDATE(), INTERVAL TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}) YEAR), {$dateOn15Expr}), ' Months and ',
            TIMESTAMPDIFF(DAY, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}) YEAR), INTERVAL TIMESTAMPDIFF(MONTH, DATE_ADD(CURDATE(), INTERVAL TIMESTAMPDIFF(YEAR, CURDATE(), {$dateOn15Expr}) YEAR), {$dateOn15Expr}) MONTH), {$dateOn15Expr}), ' Day(s)'
        )
        ELSE '15 Years Elapsed'
    END
";
$periodFromExpr = "
    CASE
        WHEN {$dateOn15Expr} IS NULL THEN 'N/A'
        WHEN CURDATE() > {$dateOn15Expr} THEN CONCAT(
            TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()), ' Years, ',
            TIMESTAMPDIFF(MONTH, DATE_ADD({$dateOn15Expr}, INTERVAL TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()) YEAR), CURDATE()), ' Months and ',
            TIMESTAMPDIFF(DAY, DATE_ADD(DATE_ADD({$dateOn15Expr}, INTERVAL TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()) YEAR), INTERVAL TIMESTAMPDIFF(MONTH, DATE_ADD({$dateOn15Expr}, INTERVAL TIMESTAMPDIFF(YEAR, {$dateOn15Expr}, CURDATE()) YEAR), CURDATE()) MONTH), CURDATE()), ' Day(s)'
        )
        ELSE 'Still within 15 Years.'
    END
";

$deathTypeExpr = buildBenefitsRetirementTypeMatchSql(
    $conn,
    "COALESCE(NULLIF(fr.retirementType, ''), NULLIF(sd.retirementType, ''))",
    'death'
);

$stmt = $conn->prepare("
    SELECT
        fr.*,
        COALESCE(NULLIF(fr.pensionNo,''),fr.regNo) AS pensionNo,
        COALESCE(NULLIF(fr.ippsNo,''),fr.computerNo,sd.ippsNo,sd.computerNo) AS ippsNo,
        COALESCE(NULLIF(fr.firstName,''),sd.firstName) AS firstName,
        COALESCE(NULLIF(fr.middleName,''),sd.middleName) AS middleName,
        COALESCE(NULLIF(fr.lastName,''),sd.lastName) AS lastName,
        sd.id AS staffdue_id,
        sd.prisonUnit AS station,
        COALESCE(fr.telNo, sd.telNo) AS telNo,
        COALESCE(fr.address, sd.address) AS address,
        COALESCE(fr.TIN, sd.TIN) AS TIN,
        COALESCE(fr.applicant_email, sd.applicant_email) AS applicant_email,
        COALESCE(fr.next_of_kin, sd.next_of_kin) AS next_of_kin,
        COALESCE(fr.next_of_kin_contact, sd.next_of_kin_contact) AS next_of_kin_contact,
        COALESCE(fr.bank_name, sd.bank_name) AS bank_name,
        COALESCE(fr.bank_account, sd.bank_account) AS bank_account,
        COALESCE(fr.bank_branch, sd.bank_branch) AS bank_branch,
        COALESCE(fr.monthlySalary, sd.monthlySalary) AS monthlySalary,
        COALESCE(fr.lengthOfService, sd.lengthOfService) AS lengthOfService,
        COALESCE(fr.annualSalary, sd.annualSalary) AS annualSalary,
        COALESCE(fr.reducedPension, sd.reducedPension) AS reducedPension,
        COALESCE(fr.fullPension, sd.fullPension) AS fullPension,
        COALESCE(fr.gratuity, sd.gratuity) AS gratuity,
        CASE
            WHEN LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
            THEN 'One-off Payment'
            ELSE 'Pensioner'
        END AS payType,
        COALESCE(fr.livingStatus, sd.livingStatus, CASE WHEN {$deathTypeExpr} THEN 'Deceased' ELSE 'Alive' END) AS livingStatus,
        CASE
            WHEN LOWER(TRIM(COALESCE(fr.livingStatus, sd.livingStatus, CASE WHEN {$deathTypeExpr} THEN 'Deceased' ELSE 'Alive' END))) = 'deceased'
              OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, sd.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                THEN 'Exempt'
            WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
            ELSE 'Not Submitted'
        END AS lifeCertificateStatus,
        COALESCE(NULLIF(fr.payrollStatus, ''), 'Not on Payroll') AS payrollStatus,
        {$dateOn15Expr} AS dateOn15yrs,
        {$periodToExpr} AS periodTo15yrs,
        {$periodFromExpr} AS periodFrom15yrs
    FROM tb_fileregistry fr
    LEFT JOIN tb_staffdue sd ON COALESCE(NULLIF(sd.pensionNo,''),sd.regNo)=COALESCE(NULLIF(fr.pensionNo,''),fr.regNo)
    LEFT JOIN tb_life_certificate_submissions lcs
      ON lcs.regNo = fr.regNo
     AND lcs.submission_year = YEAR(CURDATE())
    WHERE fr.id = ?
      AND COALESCE(fr.is_deleted, 0) = 0
    LIMIT 1
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare details query']);
    exit;
}

$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$record = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Registry record not found']);
    exit;
}

$documents = [];
$documentStmt = null;
$docSettings = getDocumentStorageSettings($conn);
$documentsEnabled = !empty($docSettings['enabled']);
$staffId = (int)($record['staffdue_id'] ?? 0);
$regNo = trim((string)($record['regNo'] ?? ''));

if ($documentsEnabled && $staffId > 0) {
    $documentStmt = $conn->prepare("
        SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, uploaded_at
        FROM tb_staff_documents
        WHERE staffdue_id = ?
        ORDER BY uploaded_at DESC
    ");
    if ($documentStmt) {
        $documentStmt->bind_param("i", $staffId);
    }
} elseif ($documentsEnabled && $regNo !== '') {
    $documentStmt = $conn->prepare("
        SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, uploaded_at
        FROM tb_staff_documents
        WHERE regNo = ?
        ORDER BY uploaded_at DESC
    ");
    if ($documentStmt) {
        $documentStmt->bind_param("s", $regNo);
    }
}

if ($documentStmt) {
    $documentStmt->execute();
    $documentResult = $documentStmt->get_result();
    while ($docRow = $documentResult->fetch_assoc()) {
        $documents[] = $docRow;
    }
    $documentStmt->close();
}

echo json_encode([
    'success' => true,
    'record' => $record,
    'documents' => $documents
]);
$conn->close();
?>
