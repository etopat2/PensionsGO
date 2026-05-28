<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
if ($role !== 'pensioner') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureStaffDueExtendedColumns($conn);
ensureFileMovementTables($conn);
ensureLifeCertificateTables($conn);
ensureStaffDocumentsTable($conn);
ensureAppnStatusTrackingColumns($conn);
ensureArrearsAndBudgetTables($conn);
ensurePayrollManagementTables($conn);
ensurePensionerLookupColumns($conn);
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
if (function_exists('maybeApplyDocumentRetentionRules')) {
    maybeApplyDocumentRetentionRules($conn);
} else {
    applyDocumentRetentionRules($conn);
}

function pensionerDashboardNormalizePayType(string $value): string
{
    $normalized = strtolower(str_replace(['-', '_', ' '], '', trim($value)));
    return in_array($normalized, ['oneoffpayment', 'oneoff', 'oneoffpayout', 'oneoffpay', 'gratuityonly'], true)
        ? 'One-off Payment'
        : 'Pensioner';
}

function pensionerDashboardCurrentStep(array $steps): array
{
    $latest = ['label' => 'Application Received', 'status' => 'Pending', 'time' => null, 'comment' => 'Your application is being tracked in the system.'];
    foreach ($steps as $step) {
        $status = strtolower(trim((string)($step['status'] ?? '')));
        if ($status === '' || in_array($status, ['pending', 'not started', 'waiting'], true)) {
            continue;
        }
        $latest = $step;
    }
    return $latest;
}

function pensionerDashboardLifeCertificateTone(string $status, int $month, bool $previousYearSubmitted = true): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === 'submitted') {
        return 'success';
    }
    if ($normalized === 'exempt') {
        return 'info';
    }
    if ($normalized === 'not submitted') {
        if (!$previousYearSubmitted) {
            return 'danger';
        }
        if ($month >= 9) {
            return 'danger';
        }
        if ($month >= 5) {
            return 'warning';
        }
        return 'info';
    }
    return 'neutral';
}

$portalSettings = [
    'showClaims' => getAppSettingBool($conn, 'pensioner_dashboard_enable_claims', true),
    'showDocuments' => getAppSettingBool($conn, 'pensioner_dashboard_enable_documents', true),
    'showStatusHelp' => getAppSettingBool($conn, 'pensioner_dashboard_enable_status_explanations', true),
    'logDashboardView' => getAppSettingBool($conn, 'pensioner_dashboard_enable_activity_log', true),
    'lookupEnabled' => getAppSettingBool($conn, 'pensioner_lookup_enabled', true),
    'lookupRequireConsent' => getAppSettingBool($conn, 'pensioner_lookup_require_consent', true)
];
$docSettings = getDocumentStorageSettings($conn);
if (empty($docSettings['enabled'])) {
    $portalSettings['showDocuments'] = false;
}

$userId = (string)($_SESSION['userId'] ?? '');
$userStmt = $conn->prepare("
    SELECT userId, userTitle, userName, userEmail, phoneNo, userPhoto, other
    FROM tb_users
    WHERE userId = ?
    LIMIT 1
");
if (!$userStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load pensioner profile.']);
    exit;
}
$userStmt->bind_param('s', $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User account not found.']);
    exit;
}

$userMeta = [];
$rawMeta = (string)($user['other'] ?? '');
if ($rawMeta !== '') {
    $decodedMeta = json_decode($rawMeta, true);
    if (is_array($decodedMeta)) {
        $userMeta = $decodedMeta;
    }
}

$metaRegNo = trim((string)($userMeta['regNo'] ?? ''));
$metaStaffId = (int)($userMeta['staffdue_id'] ?? 0);
$email = strtolower(trim((string)($user['userEmail'] ?? '')));
$phone = trim((string)($user['phoneNo'] ?? ''));
$phoneCandidates = $phone !== '' ? buildPhoneLookupCandidates($phone) : [];

$dateOn15Expr = "COALESCE(fr.dateOn15yrs, CASE WHEN fr.retirementDate IS NOT NULL THEN DATE_ADD(fr.retirementDate, INTERVAL 15 YEAR) ELSE NULL END)";
$periodToExpr = "
    CASE
        WHEN {$dateOn15Expr} IS NULL THEN ''
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
        WHEN {$dateOn15Expr} IS NULL THEN ''
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

$registry = null;
if ($metaRegNo !== '' || $metaStaffId > 0) {
    $registrySql = "
        SELECT
            fr.id,
            fr.regNo,
            fr.computerNo,
            fr.supplierNo,
            fr.boxNo,
            COALESCE(fr.lookup_contact_opt_in, 0) AS lookup_contact_opt_in,
            fr.title,
            fr.sName,
            fr.fName,
            COALESCE(fr.telNo, sd.telNo) AS telNo,
            COALESCE(fr.applicant_email, sd.applicant_email) AS applicant_email,
            COALESCE(fr.address, sd.address) AS address,
            COALESCE(fr.next_of_kin, sd.next_of_kin) AS next_of_kin,
            COALESCE(fr.next_of_kin_contact, sd.next_of_kin_contact) AS next_of_kin_contact,
            COALESCE(fr.bank_name, sd.bank_name) AS bank_name,
            COALESCE(fr.bank_account, sd.bank_account) AS bank_account,
            COALESCE(fr.bank_branch, sd.bank_branch) AS bank_branch,
            COALESCE(fr.TIN, sd.TIN) AS TIN,
            COALESCE(fr.NIN, sd.NIN) AS NIN,
            COALESCE(fr.monthlySalary, sd.monthlySalary) AS monthlySalary,
            COALESCE(fr.lengthOfService, sd.lengthOfService) AS lengthOfService,
            COALESCE(fr.annualSalary, sd.annualSalary) AS annualSalary,
            COALESCE(fr.reducedPension, sd.reducedPension) AS reducedPension,
            COALESCE(fr.fullPension, sd.fullPension) AS fullPension,
            COALESCE(fr.gratuity, sd.gratuity) AS gratuity,
            COALESCE(NULLIF(fr.payType, ''), NULLIF(sd.payType, ''), 'Pensioner') AS payType,
            COALESCE(
                NULLIF(fr.livingStatus, ''),
                NULLIF(sd.livingStatus, ''),
                CASE
                    WHEN {$deathTypeExpr} THEN 'Deceased'
                    ELSE 'Alive'
                END
            ) AS livingStatus,
            COALESCE(fr.payrollStatus, 'Not on Payroll') AS payrollStatus,
            fr.birthDate,
            fr.enlistmentDate,
            fr.retirementDate,
            COALESCE(NULLIF(fr.retirementType, ''), NULLIF(sd.retirementType, '')) AS retirementType,
            COALESCE(sd.prisonUnit, '') AS station,
            {$dateOn15Expr} AS dateOn15yrs,
            {$periodToExpr} AS periodTo15yrs,
            {$periodFromExpr} AS periodFrom15yrs
        FROM tb_fileregistry fr
        LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
        WHERE (fr.regNo = ? OR ? <> 0 AND sd.id = ?)
        LIMIT 1
    ";
    $registryStmt = $conn->prepare($registrySql);
    if ($registryStmt) {
        $registryStmt->bind_param('sii', $metaRegNo, $metaStaffId, $metaStaffId);
        $registryStmt->execute();
        $registry = $registryStmt->get_result()->fetch_assoc() ?: null;
        $registryStmt->close();
    }
}

if (!$registry && ($email !== '' || !empty($phoneCandidates))) {
    $conditions = [];
    $types = '';
    $params = [];
    if ($email !== '') {
        $conditions[] = "LOWER(COALESCE(fr.applicant_email, sd.applicant_email, '')) = ?";
        $types .= 's';
        $params[] = $email;
    }
    foreach ($phoneCandidates as $candidate) {
        $conditions[] = "COALESCE(fr.telNo, sd.telNo, '') = ?";
        $types .= 's';
        $params[] = $candidate;
    }
    if (!empty($conditions)) {
        $registrySql = "
            SELECT
                fr.id,
                fr.regNo,
                fr.computerNo,
                fr.supplierNo,
                fr.boxNo,
                COALESCE(fr.lookup_contact_opt_in, 0) AS lookup_contact_opt_in,
                fr.title,
                fr.sName,
                fr.fName,
                COALESCE(fr.telNo, sd.telNo) AS telNo,
                COALESCE(fr.applicant_email, sd.applicant_email) AS applicant_email,
                COALESCE(fr.address, sd.address) AS address,
                COALESCE(fr.next_of_kin, sd.next_of_kin) AS next_of_kin,
                COALESCE(fr.next_of_kin_contact, sd.next_of_kin_contact) AS next_of_kin_contact,
                COALESCE(fr.bank_name, sd.bank_name) AS bank_name,
                COALESCE(fr.bank_account, sd.bank_account) AS bank_account,
                COALESCE(fr.bank_branch, sd.bank_branch) AS bank_branch,
                COALESCE(fr.TIN, sd.TIN) AS TIN,
                COALESCE(fr.NIN, sd.NIN) AS NIN,
                COALESCE(fr.monthlySalary, sd.monthlySalary) AS monthlySalary,
                COALESCE(fr.lengthOfService, sd.lengthOfService) AS lengthOfService,
                COALESCE(fr.annualSalary, sd.annualSalary) AS annualSalary,
                COALESCE(fr.reducedPension, sd.reducedPension) AS reducedPension,
                COALESCE(fr.fullPension, sd.fullPension) AS fullPension,
                COALESCE(fr.gratuity, sd.gratuity) AS gratuity,
                COALESCE(NULLIF(fr.payType, ''), NULLIF(sd.payType, ''), 'Pensioner') AS payType,
                COALESCE(
                    NULLIF(fr.livingStatus, ''),
                    NULLIF(sd.livingStatus, ''),
                    CASE
                        WHEN {$deathTypeExpr} THEN 'Deceased'
                        ELSE 'Alive'
                    END
                ) AS livingStatus,
                COALESCE(fr.payrollStatus, 'Not on Payroll') AS payrollStatus,
                fr.birthDate,
                fr.enlistmentDate,
                fr.retirementDate,
                COALESCE(NULLIF(fr.retirementType, ''), NULLIF(sd.retirementType, '')) AS retirementType,
                COALESCE(sd.prisonUnit, '') AS station,
                {$dateOn15Expr} AS dateOn15yrs,
                {$periodToExpr} AS periodTo15yrs,
                {$periodFromExpr} AS periodFrom15yrs
            FROM tb_fileregistry fr
            LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
            WHERE " . implode(' OR ', $conditions) . "
            ORDER BY fr.id DESC
            LIMIT 1
        ";
        $registryStmt = $conn->prepare($registrySql);
        if ($registryStmt) {
            $bind = [$types];
            foreach ($params as $index => $value) {
                $bind[] = &$params[$index];
            }
            call_user_func_array([$registryStmt, 'bind_param'], $bind);
            $registryStmt->execute();
            $registry = $registryStmt->get_result()->fetch_assoc() ?: null;
            $registryStmt->close();
        }
    }
}

$staff = null;
$regNo = trim((string)($registry['regNo'] ?? $metaRegNo));
if ($metaStaffId > 0 || $regNo !== '' || $email !== '' || !empty($phoneCandidates)) {
    $conditions = [];
    $types = '';
    $params = [];
    if ($metaStaffId > 0) {
        $conditions[] = 's.id = ?';
        $types .= 'i';
        $params[] = $metaStaffId;
    }
    if ($regNo !== '') {
        $conditions[] = 's.regNo = ?';
        $types .= 's';
        $params[] = $regNo;
    }
    if ($email !== '') {
        $conditions[] = "LOWER(COALESCE(s.applicant_email, '')) = ?";
        $types .= 's';
        $params[] = $email;
    }
    foreach ($phoneCandidates as $candidate) {
        $conditions[] = "COALESCE(s.telNo, '') = ?";
        $types .= 's';
        $params[] = $candidate;
    }
    if (!empty($conditions)) {
        $staffSql = "
            SELECT s.*
            FROM tb_staffdue s
            WHERE " . implode(' OR ', $conditions) . "
            ORDER BY s.id DESC
            LIMIT 1
        ";
        $staffStmt = $conn->prepare($staffSql);
        if ($staffStmt) {
            $bind = [$types];
            foreach ($params as $index => $value) {
                $bind[] = &$params[$index];
            }
            call_user_func_array([$staffStmt, 'bind_param'], $bind);
            $staffStmt->execute();
            $staff = $staffStmt->get_result()->fetch_assoc() ?: null;
            $staffStmt->close();
        }
    }
}

if ($regNo === '' && $staff) {
    $regNo = trim((string)($staff['regNo'] ?? ''));
}

$hasRegistryRecord = is_array($registry) && !empty($registry['id']);
$registryStatus = $hasRegistryRecord
    ? [
        'hasRegistryRecord' => true,
        'status' => 'Linked',
        'tone' => 'success',
        'message' => 'Your portal account is linked to a pensioner file in the pension file registry.',
        'shortMessage' => 'Registry-linked pensioner record available.'
    ]
    : [
        'hasRegistryRecord' => false,
        'status' => 'Awaiting Registry Link',
        'tone' => 'info',
        'message' => 'No pensioner data is currently available in the pension file registry for this account. Payroll, compliance, claims, and indexed documents will appear after the registry record is created or linked.',
        'shortMessage' => 'No pensioner data available in the registry yet.'
    ];

$applicationSteps = [];
$applicationSummary = [
    'submissionStatus' => (string)($staff['submissionStatus'] ?? 'Pending'),
    'applicationStatus' => (string)($staff['appnStatus'] ?? 'Pending'),
    'currentStep' => ['label' => 'Application Received', 'status' => 'Pending', 'time' => null, 'comment' => 'Your application is registered and awaiting processing.']
];
if ($regNo !== '') {
    $statusStmt = $conn->prepare("
        SELECT verification, writeUp, fileCreation, entrantAllocation, dataCapture, assessment, audit, approval,
               verification_at, writeUp_at, fileCreation_at, entrantAllocation_at, dataCapture_at, assessment_at, audit_at, approval_at,
               verification_comment, writeUp_comment, fileCreation_comment, entrantAllocation_comment, dataCapture_comment, assessment_comment, audit_comment, approval_comment
        FROM tb_appnstatus
        WHERE regNo = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($statusStmt) {
        $statusStmt->bind_param('s', $regNo);
        $statusStmt->execute();
        $statusRow = $statusStmt->get_result()->fetch_assoc() ?: null;
        $statusStmt->close();
        if ($statusRow) {
            $applicationSteps = [
                ['label' => 'Verification', 'status' => (string)($statusRow['verification'] ?? 'Pending'), 'time' => $statusRow['verification_at'] ?? null, 'comment' => (string)($statusRow['verification_comment'] ?? '')],
                ['label' => 'Write Up', 'status' => (string)($statusRow['writeUp'] ?? 'Pending'), 'time' => $statusRow['writeUp_at'] ?? null, 'comment' => (string)($statusRow['writeUp_comment'] ?? '')],
                ['label' => 'File Creation', 'status' => (string)($statusRow['fileCreation'] ?? 'Pending'), 'time' => $statusRow['fileCreation_at'] ?? null, 'comment' => (string)($statusRow['fileCreation_comment'] ?? '')],
                ['label' => 'Allocation', 'status' => (string)($statusRow['entrantAllocation'] ?? 'Pending'), 'time' => $statusRow['entrantAllocation_at'] ?? null, 'comment' => (string)($statusRow['entrantAllocation_comment'] ?? '')],
                ['label' => 'Data Capture', 'status' => (string)($statusRow['dataCapture'] ?? 'Pending'), 'time' => $statusRow['dataCapture_at'] ?? null, 'comment' => (string)($statusRow['dataCapture_comment'] ?? '')],
                ['label' => 'Assessment', 'status' => (string)($statusRow['assessment'] ?? 'Pending'), 'time' => $statusRow['assessment_at'] ?? null, 'comment' => (string)($statusRow['assessment_comment'] ?? '')],
                ['label' => 'Audit', 'status' => (string)($statusRow['audit'] ?? 'Pending'), 'time' => $statusRow['audit_at'] ?? null, 'comment' => (string)($statusRow['audit_comment'] ?? '')],
                ['label' => 'Approval', 'status' => (string)($statusRow['approval'] ?? 'Pending'), 'time' => $statusRow['approval_at'] ?? null, 'comment' => (string)($statusRow['approval_comment'] ?? '')]
            ];
            $applicationSummary['currentStep'] = pensionerDashboardCurrentStep($applicationSteps);
        }
    }
}

$currentYear = (int)date('Y');
$previousYear = $currentYear - 1;
$currentMonth = (int)date('n');
$lifeStatus = $hasRegistryRecord ? 'Not Submitted' : 'Unavailable';
$lifeSubmittedAt = null;
$lifeAdvice = $hasRegistryRecord
    ? 'Please submit your life certificate for the current year as required by the pensions office.'
    : $registryStatus['message'];
$previousYearLifeSubmitted = true;
$previousYearLifeSubmittedAt = null;
$livingStatus = (string)($registry['livingStatus'] ?? $staff['livingStatus'] ?? 'Alive');
$payType = pensionerDashboardNormalizePayType((string)($registry['payType'] ?? $staff['payType'] ?? 'Pensioner'));
if (!$hasRegistryRecord) {
    $lifeStatus = 'Unavailable';
    $lifeAdvice = $registryStatus['message'];
} elseif (strtolower(trim($livingStatus)) === 'deceased' || $payType === 'One-off Payment') {
    $lifeStatus = 'Exempt';
    $lifeAdvice = $payType === 'One-off Payment'
        ? 'Life certificate submission does not apply to one-off payment accounts.'
        : 'Life certificate submission does not apply to deceased records.';
} elseif ($regNo !== '') {
    $lifeStmt = $conn->prepare("
        SELECT submission_year, submitted_at
        FROM tb_life_certificate_submissions
        WHERE regNo = ? AND submission_year IN (?, ?)
        ORDER BY submission_year DESC, submission_id DESC
    ");
    if ($lifeStmt) {
        $lifeStmt->bind_param('sii', $regNo, $currentYear, $previousYear);
        $lifeStmt->execute();
        $lifeResult = $lifeStmt->get_result();
        $lifeStmt->close();

        $currentYearLifeRow = null;
        $previousYearLifeRow = null;
        while ($lifeResult && ($lifeRow = $lifeResult->fetch_assoc())) {
            $submissionYear = (int)($lifeRow['submission_year'] ?? 0);
            if ($submissionYear === $currentYear && $currentYearLifeRow === null) {
                $currentYearLifeRow = $lifeRow;
            } elseif ($submissionYear === $previousYear && $previousYearLifeRow === null) {
                $previousYearLifeRow = $lifeRow;
            }
        }

        if ($currentYearLifeRow) {
            $lifeStatus = 'Submitted';
            $lifeSubmittedAt = $currentYearLifeRow['submitted_at'] ?? null;
            $lifeAdvice = 'Your life certificate for the current year is already on file.';
        }

        $previousYearLifeSubmitted = $previousYearLifeRow !== null;
        $previousYearLifeSubmittedAt = $previousYearLifeRow['submitted_at'] ?? null;
    }
}
if ($hasRegistryRecord && $lifeStatus === 'Not Submitted' && !$previousYearLifeSubmitted) {
    $lifeAdvice = 'Please submit your life certificate for the current year as required by the pensions office. Our records also show that the previous year was missed, so the current year submission now needs urgent attention.';
}
$lifeTone = $hasRegistryRecord
    ? pensionerDashboardLifeCertificateTone($lifeStatus, $currentMonth, $previousYearLifeSubmitted)
    : 'info';
$lifePending = strtolower(trim($lifeStatus)) === 'not submitted';

$claims = [
    'totalOutstanding' => 0.0,
    'openEntries' => 0,
    'items' => []
];
if ($portalSettings['showClaims'] && $hasRegistryRecord && $regNo !== '') {
    $claimsStmt = $conn->prepare("
        SELECT claim_type,
               COUNT(*) AS entry_count,
               SUM(COALESCE(expected_amount, 0)) AS expected_total,
               SUM(COALESCE(paid_amount, 0)) AS paid_total,
               SUM(COALESCE(balance_amount, 0)) AS balance_total,
               MAX(recorded_at) AS last_recorded_at
        FROM tb_arrears_ledger
        WHERE regNo = ?
          AND LOWER(TRIM(COALESCE(source_type, ''))) NOT LIKE 'suspension%'
        GROUP BY claim_type
        ORDER BY balance_total DESC, claim_type ASC
    ");
    if ($claimsStmt) {
        $claimsStmt->bind_param('s', $regNo);
        $claimsStmt->execute();
        $claimsResult = $claimsStmt->get_result();
        while ($claimRow = $claimsResult->fetch_assoc()) {
            $balance = (float)($claimRow['balance_total'] ?? 0);
            $claims['items'][] = [
                'claimType' => (string)($claimRow['claim_type'] ?? 'Claim'),
                'entries' => (int)($claimRow['entry_count'] ?? 0),
                'expectedTotal' => (float)($claimRow['expected_total'] ?? 0),
                'paidTotal' => (float)($claimRow['paid_total'] ?? 0),
                'balanceTotal' => $balance,
                'lastRecordedAt' => $claimRow['last_recorded_at'] ?? null
            ];
            $claims['totalOutstanding'] += $balance;
            if ($balance > 0) {
                $claims['openEntries']++;
            }
        }
        $claimsStmt->close();
    }
}

$payroll = [
    'status' => $hasRegistryRecord ? (string)($registry['payrollStatus'] ?? 'Not on Payroll') : 'Unavailable',
    'amount' => is_numeric($registry['monthlySalary'] ?? null) ? (float)$registry['monthlySalary'] : 0.0,
    'financialYear' => '',
    'quarter' => '',
    'periodLabel' => '',
    'updatedAt' => null,
    'tone' => $hasRegistryRecord ? '' : 'info'
];
if ($hasRegistryRecord && $regNo !== '') {
    $payrollStmt = $conn->prepare("
        SELECT payroll_status, amount, financial_year_label, quarter_label, payroll_year, payroll_month, updated_at
        FROM tb_registry_payroll_monthly_status
        WHERE regNo = ?
        ORDER BY payroll_year DESC, payroll_month DESC, updated_at DESC
        LIMIT 1
    ");
    if ($payrollStmt) {
        $payrollStmt->bind_param('s', $regNo);
        $payrollStmt->execute();
        $payrollRow = $payrollStmt->get_result()->fetch_assoc() ?: null;
        $payrollStmt->close();
        if ($payrollRow) {
            $month = (int)($payrollRow['payroll_month'] ?? 0);
            $year = (int)($payrollRow['payroll_year'] ?? 0);
            $payroll = [
                'status' => (string)($payrollRow['payroll_status'] ?? 'Not on Payroll'),
                'amount' => (float)($payrollRow['amount'] ?? 0),
                'financialYear' => (string)($payrollRow['financial_year_label'] ?? ''),
                'quarter' => (string)($payrollRow['quarter_label'] ?? ''),
                'periodLabel' => ($month >= 1 && $month <= 12 && $year > 0) ? date('M', mktime(0, 0, 0, $month, 1)) . '/' . $year : '',
                'updatedAt' => $payrollRow['updated_at'] ?? null,
                'tone' => ''
            ];
        }
    }
}

$latestSuspension = null;
if ($hasRegistryRecord && ($regNo !== '' || trim((string)($registry['supplierNo'] ?? '')) !== '')) {
    $supplierNo = trim((string)($registry['supplierNo'] ?? ''));
    $suspensionSql = "
        SELECT
            c.reason_label,
            c.financial_year_label,
            c.quarter_label,
            c.suspension_year,
            c.suspension_month,
            e.reason AS entry_reason,
            e.amount
        FROM tb_suspension_upload_entries e
        INNER JOIN tb_suspension_upload_cycles c ON c.suspension_cycle_id = e.suspension_cycle_id
        WHERE COALESCE(c.is_deleted, 0) = 0
          AND (
                e.matched_regNo = ?
                OR e.regNo = ?
                " . ($supplierNo !== '' ? " OR e.supplierNo = ? " : "") . "
              )
        ORDER BY c.suspension_year DESC, c.suspension_month DESC, e.entry_id DESC
        LIMIT 1
    ";
    $suspensionStmt = $conn->prepare($suspensionSql);
    if ($suspensionStmt) {
        if ($supplierNo !== '') {
            $suspensionStmt->bind_param('sss', $regNo, $regNo, $supplierNo);
        } else {
            $suspensionStmt->bind_param('ss', $regNo, $regNo);
        }
        $suspensionStmt->execute();
        $latestSuspension = $suspensionStmt->get_result()->fetch_assoc() ?: null;
        $suspensionStmt->close();
    }
}

$accountStatus = $hasRegistryRecord ? 'Active' : 'Awaiting Registry Link';
$accountReason = $hasRegistryRecord ? 'Your account is active.' : $registryStatus['message'];
$accountTone = $hasRegistryRecord ? 'success' : 'info';
if (!$hasRegistryRecord) {
    $accountStatus = 'Awaiting Registry Link';
    $accountReason = $registryStatus['message'];
    $accountTone = 'info';
} elseif ($payType === 'One-off Payment') {
    $accountStatus = 'Suspended';
    $accountReason = 'One-off payment account: monthly pension is not payable after settlement.';
    $accountTone = 'warning';
} elseif (strcasecmp((string)($payroll['status'] ?? ''), 'On Payroll') === 0) {
    $accountStatus = 'Active';
    $accountReason = 'Your account is currently active on the latest payroll cycle.';
    $accountTone = 'success';
} elseif ($latestSuspension) {
    $accountStatus = 'Suspended';
    $accountReason = trim((string)($latestSuspension['entry_reason'] ?? $latestSuspension['reason_label'] ?? 'Suspension saved amount recorded.'));
    $accountTone = 'warning';
} elseif ($regNo !== '') {
    $accountStatus = 'Suspended';
    $accountReason = 'Your account is not currently matched on the latest payroll cycle.';
    $accountTone = 'warning';
}

$documentCount = 0;
$documentItems = [];
if ($portalSettings['showDocuments'] && $hasRegistryRecord && $regNo !== '') {
    $docStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tb_staff_documents WHERE regNo = ?");
    if ($docStmt) {
        $docStmt->bind_param('s', $regNo);
        $docStmt->execute();
        $documentCount = (int)(($docStmt->get_result()->fetch_assoc()['total'] ?? 0));
        $docStmt->close();
    }

    $docListStmt = $conn->prepare("
        SELECT document_id, doc_type, file_name, uploaded_at
        FROM tb_staff_documents
        WHERE regNo = ?
        ORDER BY uploaded_at DESC, document_id DESC
        LIMIT 20
    ");
    if ($docListStmt) {
        $docListStmt->bind_param('s', $regNo);
        $docListStmt->execute();
        $docResult = $docListStmt->get_result();
        while ($docRow = $docResult->fetch_assoc()) {
            $documentItems[] = [
                'documentId' => (int)($docRow['document_id'] ?? 0),
                'docType' => (string)($docRow['doc_type'] ?? 'Document'),
                'fileName' => (string)($docRow['file_name'] ?? 'Indexed Document'),
                'uploadedAt' => $docRow['uploaded_at'] ?? null
            ];
        }
        $docListStmt->close();
    }
}

$profileName = formatTitleName(
    (string)($registry['title'] ?? $staff['title'] ?? $user['userTitle'] ?? ''),
    (string)($registry['sName'] ?? $staff['sName'] ?? ''),
    (string)($registry['fName'] ?? $staff['fName'] ?? '')
);
if ($profileName === '') {
    $profileName = formatTitleName((string)($user['userTitle'] ?? ''), (string)($user['userName'] ?? ''), '');
}

if ($portalSettings['logDashboardView'] && getAppSettingBool($conn, 'enable_activity_logs', true)) {
    logUserActivity($conn, [
        'user_id' => $userId,
        'user_name' => (string)($user['userName'] ?? 'Pensioner'),
        'user_role' => 'pensioner',
        'activity_type' => 'pensioner_dashboard_view',
        'details' => 'Opened pensioner dashboard',
        'status' => 'success'
    ]);
}

echo json_encode([
    'success' => true,
    'portalSettings' => $portalSettings,
    'registryStatus' => $registryStatus,
    'profile' => [
        'name' => $profileName,
        'title' => (string)($registry['title'] ?? $staff['title'] ?? $user['userTitle'] ?? ''),
        'displayName' => (string)($user['userName'] ?? ''),
        'email' => (string)($registry['applicant_email'] ?? $staff['applicant_email'] ?? $user['userEmail'] ?? ''),
        'phone' => (string)($registry['telNo'] ?? $staff['telNo'] ?? $user['phoneNo'] ?? ''),
        'address' => (string)($registry['address'] ?? $staff['address'] ?? ''),
        'photo' => (string)($user['userPhoto'] ?? ''),
        'regNo' => $regNo,
        'canEditContact' => $hasRegistryRecord && $regNo !== '',
        'registryLinked' => $hasRegistryRecord,
        'computerNo' => (string)($registry['computerNo'] ?? ''),
        'supplierNo' => (string)($registry['supplierNo'] ?? ''),
        'boxNo' => (string)($registry['boxNo'] ?? ''),
        'lookupVisibilityEnabled' => $hasRegistryRecord && ((int)($registry['lookup_contact_opt_in'] ?? 0)) === 1,
        'station' => (string)($registry['station'] ?? $staff['prisonUnit'] ?? ''),
        'nextOfKin' => (string)($registry['next_of_kin'] ?? $staff['next_of_kin'] ?? ''),
        'nextOfKinContact' => (string)($registry['next_of_kin_contact'] ?? $staff['next_of_kin_contact'] ?? ''),
        'bankName' => (string)($registry['bank_name'] ?? $staff['bank_name'] ?? ''),
        'bankAccount' => (string)($registry['bank_account'] ?? $staff['bank_account'] ?? ''),
        'bankBranch' => (string)($registry['bank_branch'] ?? $staff['bank_branch'] ?? '')
    ],
    'benefits' => [
        'monthlySalary' => (float)($registry['monthlySalary'] ?? $staff['monthlySalary'] ?? 0),
        'annualSalary' => (float)($registry['annualSalary'] ?? $staff['annualSalary'] ?? 0),
        'reducedPension' => (float)($registry['reducedPension'] ?? $staff['reducedPension'] ?? 0),
        'fullPension' => (float)($registry['fullPension'] ?? $staff['fullPension'] ?? 0),
        'commutedGratuity' => (float)($registry['gratuity'] ?? $staff['gratuity'] ?? 0),
        'lengthOfServiceMonths' => (int)($registry['lengthOfService'] ?? $staff['lengthOfService'] ?? 0),
        'dateOn15Years' => (string)($registry['dateOn15yrs'] ?? ''),
        'periodTo15Years' => (string)($registry['periodTo15yrs'] ?? ''),
        'periodFrom15Years' => (string)($registry['periodFrom15yrs'] ?? '')
    ],
    'lifecycle' => [
        'retirementType' => (string)($registry['retirementType'] ?? $staff['retirementType'] ?? ''),
        'retirementDate' => (string)($registry['retirementDate'] ?? $staff['retirementDate'] ?? ''),
        'birthDate' => (string)($registry['birthDate'] ?? $staff['birthDate'] ?? ''),
        'enlistmentDate' => (string)($registry['enlistmentDate'] ?? $staff['enlistmentDate'] ?? ''),
        'livingStatus' => $livingStatus,
        'payType' => $payType
    ],
    'payroll' => $payroll,
    'lifeCertificate' => [
        'year' => $currentYear,
        'status' => $lifeStatus,
        'submittedAt' => $lifeSubmittedAt,
        'advice' => $lifeAdvice,
        'tone' => $lifeTone,
        'isPending' => $lifePending,
        'previousYear' => $previousYear,
        'previousYearSubmitted' => $previousYearLifeSubmitted,
        'previousYearSubmittedAt' => $previousYearLifeSubmittedAt
    ],
    'accountStatus' => [
        'status' => $accountStatus,
        'reason' => $accountReason,
        'tone' => $accountTone,
        'latestSuspension' => $latestSuspension ? [
            'financialYear' => (string)($latestSuspension['financial_year_label'] ?? ''),
            'quarter' => (string)($latestSuspension['quarter_label'] ?? ''),
            'periodLabel' => ((int)($latestSuspension['suspension_month'] ?? 0) >= 1 && (int)($latestSuspension['suspension_month'] ?? 0) <= 12)
                ? date('M', mktime(0, 0, 0, (int)$latestSuspension['suspension_month'], 1)) . '/' . (int)($latestSuspension['suspension_year'] ?? 0)
                : '',
            'reason' => (string)($latestSuspension['entry_reason'] ?? $latestSuspension['reason_label'] ?? ''),
            'amount' => (float)($latestSuspension['amount'] ?? 0)
        ] : null
    ],
    'application' => [
        'submissionStatus' => $applicationSummary['submissionStatus'],
        'applicationStatus' => $applicationSummary['applicationStatus'],
        'currentStep' => $applicationSummary['currentStep'],
        'steps' => $applicationSteps
    ],
    'claims' => $claims,
    'documents' => [
        'count' => $documentCount,
        'items' => $documentItems
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$conn->close();
?>
