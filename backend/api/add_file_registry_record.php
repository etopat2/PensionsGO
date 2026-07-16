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
        return 'Identity Profile is missing the file number.';
    }
    if ($value === 'PEN/') {
        return 'File number must continue after the "PEN/" prefix.';
    }
    if (!preg_match('/^PEN\/(?:[1-9][0-9]{0,4}|[A-Z]\/[1-9][0-9]{0,3})$/', $value)) {
        return 'File number must use "PEN/1" or "PEN/A/1" format. Use only one capital letter when present; numbers must start from 1, have no leading zeroes, and must not exceed 99999 without a letter or 9999 with a letter.';
    }
    return null;
}

$regNo = normalizePensionFileNumberInput((string)($payload['regNo'] ?? ''));
$ippsNo = trim((string)($payload['ippsNo'] ?? ($payload['computerNo'] ?? '')));
$firstName = trim((string)($payload['firstName'] ?? ''));
$middleName = trim((string)($payload['middleName'] ?? ''));
$lastName = trim((string)($payload['lastName'] ?? ($payload['sName'] ?? '')));
$legacyGivenNames = trim((string)($payload['fName'] ?? ''));
if ($firstName === '' && $legacyGivenNames !== '') { $parts=preg_split('/\s+/', $legacyGivenNames, 2); $firstName=$parts[0]??''; $middleName=$middleName ?: ($parts[1]??''); }
$sName = $lastName;
$fName = trim($firstName . ' ' . $middleName);
$payload['sName']=$sName; $payload['fName']=$fName;
$requiredMessages = [
    'regNo' => 'Identity Profile is missing the file number.',
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

$regNoError = validatePensionFileNumberInput($regNo);
if ($regNoError !== null) {
    echo json_encode(['success' => false, 'message' => $regNoError]);
    exit;
}

$duplicateStmt = $conn->prepare("SELECT id FROM tb_fileregistry WHERE regNo = ? LIMIT 1");
if (!$duplicateStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to validate file number uniqueness.']);
    exit;
}
$duplicateStmt->bind_param('s', $regNo);
$duplicateStmt->execute();
$duplicateResult = $duplicateStmt->get_result();
$duplicateRow = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
$duplicateStmt->close();
if ($duplicateRow) {
    echo json_encode(['success' => false, 'message' => 'File number already exists. Please use a unique file number.']);
    exit;
}

$computerNo = $ippsNo;
if ($computerNo !== '') {
    $computerStmt = $conn->prepare("SELECT id FROM tb_fileregistry WHERE computerNo = ? LIMIT 1");
    if ($computerStmt) {
        $computerStmt->bind_param('s', $computerNo);
        $computerStmt->execute();
        $computerResult = $computerStmt->get_result();
        $computerRow = $computerResult ? $computerResult->fetch_assoc() : null;
        $computerStmt->close();
        if ($computerRow) {
            echo json_encode(['success' => false, 'message' => 'IPPS number already exists. Use a unique IPPS Number or leave it blank.']);
            exit;
        }
    }
}

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

$gender = trim((string)($payload['gender'] ?? ''));
if ($gender !== '' && !in_array($gender, ['Male', 'Female'], true)) {
    $gender = null;
}

$retirementType = normalizeBenefitsRetirementTypeKey((string)($payload['retirementType'] ?? ''));
if (!isBenefitsRetirementTypeSupported($retirementType)) {
    echo json_encode(['success' => false, 'message' => 'Select a valid mode of retirement.']);
    exit;
}
$livingStatus = trim((string)($payload['livingStatus'] ?? ''));
$livingStatus = deriveLivingStatusFromRetirementType($retirementType, $livingStatus === '' ? 'Alive' : $livingStatus);

$availabilityStatus = trim((string)($payload['availability_status'] ?? 'in_shelf'));
if (!in_array($availabilityStatus, ['in_shelf', 'out_of_shelf'], true)) {
    $availabilityStatus = 'in_shelf';
}

$payrollStatus = trim((string)($payload['payrollStatus'] ?? 'Not on Payroll'));
if (!in_array($payrollStatus, ['On Payroll', 'Not on Payroll'], true)) {
    $payrollStatus = 'Not on Payroll';
}

$birthDate = trim((string)($payload['birthDate'] ?? ''));
$enlistmentDate = trim((string)($payload['enlistmentDate'] ?? ''));
$retirementDate = trim((string)($payload['retirementDate'] ?? ''));
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
$payType = deriveRegistryPayTypeFromProfile(
    $retirementType,
    $enlistmentDate !== '' ? $enlistmentDate : null,
    $retirementDate !== '' ? $retirementDate : null,
    (string)($payload['payType'] ?? '')
);
$supplierNo = trim((string)($payload['supplierNo'] ?? ''));
$boxNo = trim((string)($payload['boxNo'] ?? ''));
if ($boxNo === '') {
    $boxNo = allocateRegistryBoxNumber($conn, $livingStatus, $payType);
}
$lifeCertificateRaw = isset($payload['lifeCertificate']) ? trim((string)$payload['lifeCertificate']) : '';
$lifeCertificate = $lifeCertificateRaw;
if (!in_array($lifeCertificate, ['Submitted', 'Not Submitted', 'Exempt'], true)) {
    $lifeCertificate = isLifeCertificateExemptRecord($livingStatus, $payType) ? 'Exempt' : 'Not Submitted';
}
if (isLifeCertificateExemptRecord($livingStatus, $payType)) {
    $lifeCertificate = 'Exempt';
}
$tin = trim((string)($payload['TIN'] ?? ''));
$nin = trim((string)($payload['NIN'] ?? ''));
$address = trim((string)($payload['address'] ?? ''));
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
    $monthlySalary = is_numeric($monthlySalary) ? number_format(max(0.0, (float)$monthlySalary), 2, '.', '') : $monthlySalary;
}

if ($address !== '') {
    $resolvedDistrict = resolvePoliticalDistrictName($conn, $address);
    if ($resolvedDistrict === null) {
        echo json_encode(['success' => false, 'message' => 'Select a valid district of residence.']);
        exit;
    }
    $address = $resolvedDistrict;
}

if ($applicantEmail !== '' && !filter_var($applicantEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Applicant email is invalid.']);
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

$computerNo = $computerNo === '' ? null : $computerNo;
$supplierNo = $supplierNo === '' ? null : $supplierNo;
$title = $title === '' ? null : $title;
$boxNo = $boxNo === '' ? null : $boxNo;
$birthDate = $birthDate === '' ? null : $birthDate;
$enlistmentDate = $enlistmentDate === '' ? null : $enlistmentDate;
$retirementDate = $retirementDate === '' ? null : $retirementDate;
$retirementType = $retirementType === '' ? null : $retirementType;
$tin = $tin === '' ? null : $tin;
$nin = $nin === '' ? null : $nin;
$address = $address === '' ? null : $address;
$telNo = $telNo === '' ? null : $telNo;
$applicantEmail = $applicantEmail === '' ? null : $applicantEmail;
$nextOfKin = $nextOfKin === '' ? null : $nextOfKin;
$nextOfKinContact = $nextOfKinContact === '' ? null : $nextOfKinContact;
$bankName = $bankName === '' ? null : $bankName;
$bankAccount = $bankAccount === '' ? null : $bankAccount;
$bankBranch = $bankBranch === '' ? null : $bankBranch;
$monthlySalary = $monthlySalary === '' ? null : $monthlySalary;
$lengthOfService = $lengthOfService === '' ? null : $lengthOfService;
$annualSalary = $annualSalary === '' ? null : $annualSalary;
$reducedPension = $reducedPension === '' ? null : $reducedPension;
$fullPension = $fullPension === '' ? null : $fullPension;
$gratuity = $gratuity === '' ? null : $gratuity;
$availabilityReason = $availabilityReason === '' ? null : $availabilityReason;
$other = $other === '' ? null : $other;
$dateOn15yrs = computeDateOn15Years($retirementDate);
$estateLifecycle = evaluatePensionEstateLifecycle($retirementDate, $payType, $livingStatus);
$estateExpiryDate = $estateLifecycle['estateExpiryDate'] ?? null;
$estateStatus = $estateLifecycle['label'] ?? null;

$stmt = $conn->prepare("
    INSERT INTO tb_fileregistry (
        regNo,
        computerNo,
        supplierNo,
        title,
        boxNo,
        sName,
        fName,
        gender,
        livingStatus,
        lifeCertificate,
        birthDate,
        enlistmentDate,
        retirementDate,
        retirementType,
        TIN,
        NIN,
        address,
        telNo,
        applicant_email,
        next_of_kin,
        next_of_kin_contact,
        bank_name,
        bank_account,
        bank_branch,
        monthlySalary,
        lengthOfService,
        annualSalary,
        reducedPension,
        fullPension,
        gratuity,
        payrollStatus,
        payType,
        dateOn15yrs,
        estateExpiryDate,
        estateStatus,
        availability_status,
        availability_reason,
        other
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare registry record creation.']);
    exit;
}

$stmt->bind_param(
    "ssssssssssssssssssssssssssssssssssssss",
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
    $other
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    $friendlyMessage = $error ?: 'Failed to add registry record.';
    if ($error !== '' && stripos($error, 'Duplicate entry') !== false && stripos($error, 'computerNo') !== false) {
        $friendlyMessage = 'Computer number already exists. Use a unique Computer Number or leave it blank.';
    }
    echo json_encode(['success' => false, 'message' => $friendlyMessage]);
    exit;
}

$newId = (int)$stmt->insert_id;
$stmt->close();
$canonicalStmt=$conn->prepare("UPDATE tb_fileregistry SET employeeNo=NULL,pensionNo=?,ippsNo=?,firstName=?,middleName=?,lastName=? WHERE id=?");
if($canonicalStmt){$canonicalStmt->bind_param('sssssi',$regNo,$ippsNo,$firstName,$middleName,$lastName,$newId);$canonicalStmt->execute();$canonicalStmt->close();}

if (function_exists('upsertPensionerUserFromRegistry')) {
    try {
        upsertPensionerUserFromRegistry($conn, $regNo, 'Pensioner123', $userId);
    } catch (Throwable $syncError) {
        error_log('add_file_registry_record pensioner sync failed: ' . $syncError->getMessage());
    }
}

if (function_exists('maybeReconcileAllActivePayrollCycles')) {
    try {
        maybeReconcileAllActivePayrollCycles($conn);
    } catch (Throwable $syncError) {
        error_log('add_file_registry_record payroll reconciliation failed: ' . $syncError->getMessage());
    }
}
if (function_exists('clearRegistryBoxNumberOptionsCache')) {
    clearRegistryBoxNumberOptionsCache();
}

echo json_encode([
    'success' => true,
    'message' => 'Pension file added to the registry successfully.',
    'record' => [
        'id' => $newId,
        'regNo' => $regNo
    ]
]);

$conn->close();
