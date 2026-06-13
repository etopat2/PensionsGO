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

$userId = (string)($_SESSION['userId'] ?? '');
$sessionRole = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
$effectiveRole = $sessionRole !== '' ? getEffectiveRoleKey($conn, $sessionRole) : '';
$role = $sessionRole;
$canEditRegNo = roleHasAdminAccess($conn, $sessionRole) || isOcPenEquivalentRole($effectiveRole);
if (!currentUserHasPermission($conn, 'registry.edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}
if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}
if (function_exists('ensureTitlesTable')) {
    ensureTitlesTable($conn);
}
if (function_exists('ensureBanksTable')) {
    ensureBanksTable($conn);
}
if (function_exists('ensureLifeCertificateTables')) {
    ensureLifeCertificateTables($conn);
}
if (function_exists('syncCurrentYearLifeCertificateStatus')) {
    syncCurrentYearLifeCertificateStatus($conn);
}
if (function_exists('ensurePayrollManagementTables')) {
    ensurePayrollManagementTables($conn);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

function normalizePensionFileNumberInput(string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');
}

function validatePensionFileNumberInput(string $value): ?string
{
    if ($value === '') {
        return 'File number is required.';
    }
    if ($value === 'PEN/') {
        return 'File number must continue after the "PEN/" prefix.';
    }
    if (!preg_match('/^PEN\/(?:[1-9][0-9]{0,4}|[A-Z]\/[1-9][0-9]{0,3})$/', $value)) {
        return 'File number must use "PEN/1" or "PEN/A/1" format. Use only one capital letter when present; numbers must start from 1, have no leading zeroes, and must not exceed 99999 without a letter or 9999 with a letter.';
    }
    return null;
}

$id = (int)($payload['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid registry record']);
    exit;
}

$existingStmt = $conn->prepare("
    SELECT id, regNo, address, livingStatus, dateOfDeath, deathNotificationDate, deathNotifierName, deathNotifierContact,
           monthlySalary, lengthOfService, annualSalary, reducedPension, fullPension, gratuity
    FROM tb_fileregistry
    WHERE id = ?
    LIMIT 1
");
if (!$existingStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load current record.']);
    exit;
}
$existingStmt->bind_param("i", $id);
$existingStmt->execute();
$existingResult = $existingStmt->get_result();
$existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
$existingStmt->close();

if (!$existingRow) {
    echo json_encode(['success' => false, 'message' => 'Registry record not found.']);
    exit;
}

$existingRegNo = trim((string)($existingRow['regNo'] ?? ''));
$canEditMonthlySalary = getEffectiveUserPermission($conn, $userId, $role, 'registry.benefits.monthly_salary.edit');
$canEditLengthOfService = getEffectiveUserPermission($conn, $userId, $role, 'registry.benefits.length_service.edit');
$canEditBenefitAmounts = getEffectiveUserPermission($conn, $userId, $role, 'registry.benefits.amounts.edit');

$regNo = normalizePensionFileNumberInput((string)($payload['regNo'] ?? ''));
if (!$canEditRegNo) {
    $regNo = $existingRegNo;
}
if ($regNo === '') {
    echo json_encode(['success' => false, 'message' => 'File number is required.']);
    exit;
}

if ($canEditRegNo && $regNo !== $existingRegNo) {
    $regNoError = validatePensionFileNumberInput($regNo);
    if ($regNoError !== null) {
        echo json_encode(['success' => false, 'message' => $regNoError]);
        exit;
    }

    $dupStmt = $conn->prepare("SELECT id FROM tb_fileregistry WHERE regNo = ? AND id <> ? LIMIT 1");
    if (!$dupStmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to validate file number uniqueness.']);
        exit;
    }
    $dupStmt->bind_param('si', $regNo, $id);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();
    $duplicate = $dupResult ? $dupResult->fetch_assoc() : null;
    $dupStmt->close();
    if ($duplicate) {
        echo json_encode(['success' => false, 'message' => 'File number already exists. Please use a unique file number.']);
        exit;
    }
}

$sName = trim((string)($payload['sName'] ?? ''));
$fName = trim((string)($payload['fName'] ?? ''));
$requiredMessages = [
    'title' => 'Identity Profile is missing the title or rank.',
    'sName' => 'Identity Profile is missing the surname.',
    'fName' => 'Identity Profile is missing the first name.',
    'retirementType' => 'Service Profile is missing the mode of retirement.'
];
foreach ($requiredMessages as $field => $message) {
    $value = trim((string)($payload[$field] ?? ''));
    if ($value === '') {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

$gender = trim((string)($payload['gender'] ?? ''));
if ($gender !== '' && !in_array($gender, ['Male', 'Female'], true)) {
    $gender = null;
}
$livingStatus = trim((string)($payload['livingStatus'] ?? ''));
$livingStatus = deriveLivingStatusFromRetirementType($payload['retirementType'] ?? '', $livingStatus === '' ? (string)($existingRow['livingStatus'] ?? 'Alive') : $livingStatus);

$availabilityStatus = trim((string)($payload['availability_status'] ?? 'in_shelf'));
if (!in_array($availabilityStatus, ['in_shelf', 'out_of_shelf'], true)) {
    $availabilityStatus = 'in_shelf';
}

$computerNo = trim((string)($payload['computerNo'] ?? ''));
$supplierNo = trim((string)($payload['supplierNo'] ?? ''));
$title = trim((string)($payload['title'] ?? ''));
$normalizedTitle = normalizeRegistryTitle($conn, $title);
if ($title !== '' && $normalizedTitle === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid title selected. Ask Admin to add this title in settings first.'
    ]);
    exit;
}
if ($normalizedTitle !== null) {
    $title = $normalizedTitle;
}
$boxNo = trim((string)($payload['boxNo'] ?? ''));
$lifeCertificateRaw = isset($payload['lifeCertificate']) ? trim((string)$payload['lifeCertificate']) : '';
$lifeCertificate = 'Not Submitted';
if ($lifeCertificateRaw !== '' && in_array($lifeCertificateRaw, ['Submitted', 'Not Submitted'], true)) {
    $lifeCertificate = $lifeCertificateRaw;
}
$birthDate = trim((string)($payload['birthDate'] ?? ''));
$enlistmentDate = trim((string)($payload['enlistmentDate'] ?? ''));
$retirementDate = trim((string)($payload['retirementDate'] ?? ''));
$retirementType = normalizeBenefitsRetirementTypeKey((string)($payload['retirementType'] ?? ''));
if (!isBenefitsRetirementTypeSupported($retirementType)) {
    echo json_encode(['success' => false, 'message' => 'Select a valid mode of retirement.']);
    exit;
}
$policyAssessment = validateRetirementPolicyProfile(
    $retirementType,
    $birthDate !== '' ? $birthDate : null,
    $enlistmentDate !== '' ? $enlistmentDate : null,
    $retirementDate !== '' ? $retirementDate : null
);
if (!empty($policyAssessment['errors'])) {
    echo json_encode(['success' => false, 'message' => (string)($policyAssessment['primary_message'] ?? 'The retirement profile does not satisfy the configured policy checks.')]);
    exit;
}
$tin = trim((string)($payload['TIN'] ?? ''));
$nin = trim((string)($payload['NIN'] ?? ''));
$address = trim((string)($payload['address'] ?? ''));
$payrollStatus = trim((string)($payload['payrollStatus'] ?? 'Not on Payroll'));
if (!in_array($payrollStatus, ['On Payroll', 'Not on Payroll'], true)) {
    $payrollStatus = 'Not on Payroll';
}
$payType = deriveRegistryPayTypeFromProfile(
    $retirementType,
    $enlistmentDate !== '' ? $enlistmentDate : null,
    $retirementDate !== '' ? $retirementDate : null,
    (string)($payload['payType'] ?? '')
);
if ($boxNo === '') {
    $boxNo = allocateRegistryBoxNumber($conn, $livingStatus, $payType);
}
$telNo = trim((string)($payload['telNo'] ?? ''));
$applicantEmail = trim((string)($payload['applicant_email'] ?? ''));
$nextOfKin = trim((string)($payload['next_of_kin'] ?? ''));
$nextOfKinContact = trim((string)($payload['next_of_kin_contact'] ?? ''));
$bankName = trim((string)($payload['bank_name'] ?? ''));
$bankAccount = trim((string)($payload['bank_account'] ?? ''));
$bankBranch = trim((string)($payload['bank_branch'] ?? ''));
$monthlySalary = trim((string)($payload['monthlySalary'] ?? ''));
$lengthOfService = trim((string)($payload['lengthOfService'] ?? ''));
$annualSalary = trim((string)($payload['annualSalary'] ?? ''));
$reducedPension = trim((string)($payload['reducedPension'] ?? ''));
$fullPension = trim((string)($payload['fullPension'] ?? ''));
$gratuity = trim((string)($payload['gratuity'] ?? ''));
$availabilityReason = trim((string)($payload['availability_reason'] ?? ''));
$other = trim((string)($payload['other'] ?? ''));
$existingAddress = trim((string)($existingRow['address'] ?? ''));

if (strtolower($livingStatus) === 'deceased' && ($nextOfKin === '' || $nextOfKinContact === '')) {
    echo json_encode([
        'success' => false,
        'message' => 'Contact & Banking requires next of kin name and contact for death records.'
    ]);
    exit;
}

$ninValidation = validateNationalIdNumber(
    $nin,
    $birthDate !== '' ? $birthDate : null,
    $gender !== null ? $gender : null
);
if (!$ninValidation['valid']) {
    echo json_encode(['success' => false, 'message' => (string)($ninValidation['message'] ?? 'NIN is invalid.')]);
    exit;
}
$nin = (string)($ninValidation['normalized'] ?? '');

if ($bankName !== '') {
    $normalizedBankName = normalizeBankCatalogName($conn, $bankName, false);
    if ($normalizedBankName === null) {
        echo json_encode(['success' => false, 'message' => 'Select a valid bank from Bank Settings.']);
        exit;
    }
    $bankName = $normalizedBankName;
}

if (!$canEditMonthlySalary) {
    $monthlySalary = isset($existingRow['monthlySalary']) ? (string)$existingRow['monthlySalary'] : '';
}
if (!$canEditLengthOfService) {
    $lengthOfService = isset($existingRow['lengthOfService']) ? (string)$existingRow['lengthOfService'] : '';
}
if (!$canEditBenefitAmounts) {
    $annualSalary = isset($existingRow['annualSalary']) ? (string)$existingRow['annualSalary'] : '';
    $reducedPension = isset($existingRow['reducedPension']) ? (string)$existingRow['reducedPension'] : '';
    $fullPension = isset($existingRow['fullPension']) ? (string)$existingRow['fullPension'] : '';
    $gratuity = isset($existingRow['gratuity']) ? (string)$existingRow['gratuity'] : '';
}

$benefitSnapshot = calculateBenefitSnapshotFromInputs(
    $retirementType,
    $enlistmentDate !== '' ? $enlistmentDate : null,
    $retirementDate !== '' ? $retirementDate : null,
    $monthlySalary,
    $birthDate !== '' ? $birthDate : null
);
if ($benefitSnapshot['annualSalary'] !== null) {
    $lengthOfService = (string)((int)($benefitSnapshot['lengthOfService'] ?? 0));
    $annualSalary = number_format((float)($benefitSnapshot['annualSalary'] ?? 0), 2, '.', '');
    $reducedPension = number_format((float)($benefitSnapshot['reducedPension'] ?? 0), 2, '.', '');
    $fullPension = number_format((float)($benefitSnapshot['fullPension'] ?? 0), 2, '.', '');
    $gratuity = number_format((float)($benefitSnapshot['gratuity'] ?? 0), 2, '.', '');
    if ($monthlySalary !== '' && is_numeric($monthlySalary)) {
        $monthlySalary = number_format(max(0.0, (float)$monthlySalary), 2, '.', '');
    }
}

if ($address !== '') {
    $resolvedDistrict = resolvePoliticalDistrictName($conn, $address);
    if ($resolvedDistrict !== null) {
        $address = $resolvedDistrict;
    } elseif (normalizePoliticalDistrictName($address) !== normalizePoliticalDistrictName($existingAddress)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid district of residence']);
        exit;
    }
}

if ($applicantEmail !== '' && !filter_var($applicantEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Applicant email is invalid']);
    exit;
}

if ($telNo !== '') {
    $normalizedTel = normalizePhoneNumber($telNo);
    if ($normalizedTel === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Contact & Banking has an invalid phone number format.'
        ]);
        exit;
    }
    $telNo = $normalizedTel;
}

if ($nextOfKinContact !== '') {
    $normalizedNextOfKinContact = normalizePhoneNumber($nextOfKinContact);
    if ($normalizedNextOfKinContact === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Contact & Banking has an invalid next of kin phone number format.'
        ]);
        exit;
    }
    $nextOfKinContact = $normalizedNextOfKinContact;
}

$birthDate = ($birthDate === '') ? null : $birthDate;
$enlistmentDate = ($enlistmentDate === '') ? null : $enlistmentDate;
$retirementDate = ($retirementDate === '') ? null : $retirementDate;
$computerNo = ($computerNo === '') ? null : $computerNo;
$supplierNo = ($supplierNo === '') ? null : $supplierNo;
$monthlySalary = ($monthlySalary === '') ? null : $monthlySalary;
$lengthOfService = ($lengthOfService === '') ? null : $lengthOfService;
$annualSalary = ($annualSalary === '') ? null : $annualSalary;
$reducedPension = ($reducedPension === '') ? null : $reducedPension;
$fullPension = ($fullPension === '') ? null : $fullPension;
$gratuity = ($gratuity === '') ? null : $gratuity;
$dateOn15yrs = computeDateOn15Years($retirementDate);
$existingDateOfDeath = trim((string)($existingRow['dateOfDeath'] ?? ''));
$estateLifecycle = evaluatePensionEstateLifecycle(
    $retirementDate,
    $payType,
    $livingStatus,
    $existingDateOfDeath !== '' ? $existingDateOfDeath : null
);
$estateExpiryDate = $estateLifecycle['estateExpiryDate'] ?? null;
$estateStatus = $estateLifecycle['label'] ?? null;

if (isLifeCertificateExemptRecord($livingStatus, $payType)) {
    $lifeCertificate = 'Exempt';
}

$stmt = $conn->prepare("
    UPDATE tb_fileregistry
    SET regNo = ?,
        computerNo = ?,
        supplierNo = ?,
        title = ?,
        boxNo = ?,
        sName = ?,
        fName = ?,
        gender = ?,
        livingStatus = ?,
        lifeCertificate = ?,
        birthDate = ?,
        enlistmentDate = ?,
        retirementDate = ?,
        retirementType = ?,
        TIN = ?,
        NIN = ?,
        address = ?,
        telNo = ?,
        applicant_email = ?,
        next_of_kin = ?,
        next_of_kin_contact = ?,
        bank_name = ?,
        bank_account = ?,
        bank_branch = ?,
        monthlySalary = ?,
        lengthOfService = ?,
        annualSalary = ?,
        reducedPension = ?,
        fullPension = ?,
        gratuity = ?,
        payrollStatus = ?,
        payType = ?,
        dateOn15yrs = ?,
        estateExpiryDate = ?,
        estateStatus = ?,
        availability_status = ?,
        availability_reason = ?,
        other = ?
    WHERE id = ?
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param(
    str_repeat('s', 38) . 'i',
    $regNo,
    $computerNo,
    $supplierNo,
    $title,
    $boxNo,
    $sName,
    $fName,
    $gender,
    $livingStatus,
    $lifeCertificate,
    $birthDate,
    $enlistmentDate,
    $retirementDate,
    $retirementType,
    $tin,
    $nin,
    $address,
    $telNo,
    $applicantEmail,
    $nextOfKin,
    $nextOfKinContact,
    $bankName,
    $bankAccount,
    $bankBranch,
    $monthlySalary,
    $lengthOfService,
    $annualSalary,
    $reducedPension,
    $fullPension,
    $gratuity,
    $payrollStatus,
    $payType,
    $dateOn15yrs,
    $estateExpiryDate,
    $estateStatus,
    $availabilityStatus,
    $availabilityReason,
    $other,
    $id
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    $friendlyMessage = $error ?: 'Failed to update registry record';
    if ($error !== '' && stripos($error, 'Duplicate entry') !== false && stripos($error, 'computerNo') !== false) {
        $friendlyMessage = 'Computer number already exists. Use a unique Computer Number or leave it blank.';
    }

    echo json_encode(['success' => false, 'message' => $friendlyMessage]);
    exit;
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($regNo !== $existingRegNo) {
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    updateRegNoAcrossTables($conn, $existingRegNo, $regNo, []);
    updatePensionerUserMetaRegNo($conn, $existingRegNo, $regNo);
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
}

if (function_exists('maybeReconcileAllActivePayrollCycles')) {
    try {
        maybeReconcileAllActivePayrollCycles($conn);
    } catch (Throwable $syncError) {
        error_log('update_file_registry_record payroll reconciliation failed: ' . $syncError->getMessage());
    }
}
if (function_exists('clearRegistryBoxNumberOptionsCache')) {
    clearRegistryBoxNumberOptionsCache();
}

echo json_encode([
    'success' => true,
    'message' => $affected >= 0 ? 'Registry record updated successfully' : 'No changes applied'
]);
$conn->close();
?>
