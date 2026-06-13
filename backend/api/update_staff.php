<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$normalizedRole = getSessionEffectiveRoleKey($conn);
if ($normalizedRole === '' || !currentUserHasPermission($conn, 'staff_due.edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID.']);
    exit;
}

if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}
if (function_exists('ensureStaffDueBaseColumns')) {
    ensureStaffDueBaseColumns($conn);
}
if (function_exists('ensureTitlesTable')) {
    ensureTitlesTable($conn);
}

function computeFinancialYearLabel(?string $retirementDate): string
{
    if (!$retirementDate) {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $retirementDate);
    if (!$date) {
        return '';
    }

    $year = (int)$date->format('Y');
    $month = (int)$date->format('n');
    $startYear = ($month <= 6) ? $year - 1 : $year;
    $endYear = ($month <= 6) ? $year : $year + 1;

    return "FY {$startYear}/{$endYear}";
}

function computeServiceMonths(?string $enlistmentDate, ?string $retirementDate): int
{
    if (!$enlistmentDate || !$retirementDate) {
        return 0;
    }

    if (function_exists('calculateServicePeriodMonthsAndDays')) {
        $period = calculateServicePeriodMonthsAndDays($enlistmentDate, $retirementDate);
        return (int)($period['rounded_months'] ?? 0);
    }

    $start = DateTime::createFromFormat('Y-m-d', $enlistmentDate);
    $end = DateTime::createFromFormat('Y-m-d', $retirementDate);
    if (!$start || !$end || $end < $start) {
        return 0;
    }

    $months = ((int)$end->format('Y') - (int)$start->format('Y')) * 12;
    $months += ((int)$end->format('n') - (int)$start->format('n'));
    $extraDays = (int)$end->format('j') - (int)$start->format('j');
    if ($extraDays >= 15) {
        $months++;
    }

    return max(0, $months);
}

function formatMoney(float $value): string
{
    return number_format($value, 2, '.', '');
}

// Sanitize inputs
$regNo = trim($_POST['regNo'] ?? '');
$computerNo = trim($_POST['computerNo'] ?? ($_POST['supplierNo'] ?? ''));
$title = trim($_POST['title'] ?? '');
$sName = trim($_POST['sName'] ?? '');
$fName = trim($_POST['fName'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$prisonUnit = trim($_POST['prisonUnit'] ?? '');
$nin = trim($_POST['NIN'] ?? '');
$telNo = trim($_POST['telNo'] ?? '');
$birthDate = trim($_POST['birthDate'] ?? '');
$enlistmentDate = trim($_POST['enlistmentDate'] ?? '');
$retirementDate = trim($_POST['retirementDate'] ?? '');
$financialYear = trim($_POST['financialYear'] ?? '');
$retirementType = trim($_POST['retirementType'] ?? '');
$monthlySalary = trim($_POST['monthlySalary'] ?? '');
$lengthOfService = trim($_POST['lengthOfService'] ?? '');
$annualSalary = trim($_POST['annualSalary'] ?? '');
$reducedPension = trim($_POST['reducedPension'] ?? '');
$fullPension = trim($_POST['fullPension'] ?? '');
$gratuity = trim($_POST['gratuity'] ?? '');
$address = trim($_POST['address'] ?? '');
$tin = trim($_POST['TIN'] ?? '');
$nextOfKin = trim($_POST['next_of_kin'] ?? '');
$nextOfKinContact = trim($_POST['next_of_kin_contact'] ?? '');
$bankName = trim($_POST['bank_name'] ?? '');
$bankAccount = trim($_POST['bank_account'] ?? '');
$bankBranch = trim($_POST['bank_branch'] ?? '');
$applicantEmail = trim($_POST['applicant_email'] ?? '');

$ninValidation = validateNationalIdNumber(
    $nin,
    $birthDate !== '' ? $birthDate : null,
    $gender !== '' ? $gender : null
);
if (!$ninValidation['valid']) {
    echo json_encode(['success' => false, 'message' => (string)($ninValidation['message'] ?? 'NIN is invalid.')]);
    exit;
}
$nin = (string)($ninValidation['normalized'] ?? '');

// Force-compute derived fields on the server to prevent manual tampering.
$retirementType = normalizeBenefitsRetirementTypeKey($retirementType);
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
$monthlySalaryValue = is_numeric($monthlySalary) ? max(0.0, (float)$monthlySalary) : 0.0;
$benefitSnapshot = calculateBenefitSnapshotFromInputs(
    $retirementType,
    $enlistmentDate !== '' ? $enlistmentDate : null,
    $retirementDate !== '' ? $retirementDate : null,
    $monthlySalaryValue,
    $birthDate !== '' ? $birthDate : null
);

$computedMonths = (int)($benefitSnapshot['lengthOfService'] ?? computeServiceMonths($enlistmentDate, $retirementDate));
$computedAnnual = (float)($benefitSnapshot['annualSalary'] ?? round($monthlySalaryValue * 12, 2));
$computedReduced = (float)($benefitSnapshot['reducedPension'] ?? 0.0);
$computedFull = (float)($benefitSnapshot['fullPension'] ?? 0.0);
$computedGratuity = (float)($benefitSnapshot['gratuity'] ?? 0.0);

$monthlySalary = formatMoney($monthlySalaryValue);
$lengthOfService = (string)$computedMonths;
$annualSalary = formatMoney($computedAnnual);
$reducedPension = formatMoney($computedReduced);
$fullPension = formatMoney($computedFull);
$gratuity = formatMoney($computedGratuity);
$financialYear = computeFinancialYearLabel($retirementDate);

$currentStmt = $conn->prepare("SELECT regNo, address, livingStatus, payType FROM tb_staffdue WHERE id = ? LIMIT 1");
if (!$currentStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load current record.']);
    exit;
}
$currentStmt->bind_param('i', $id);
$currentStmt->execute();
$currentRes = $currentStmt->get_result();
$currentRow = $currentRes->fetch_assoc();
$currentStmt->close();

if (!$currentRow) {
    echo json_encode(['success' => false, 'message' => 'Record not found.']);
    exit;
}

$existingRegNo = (string)($currentRow['regNo'] ?? '');
$existingAddress = trim((string)($currentRow['address'] ?? ''));
$existingLivingStatus = (string)($currentRow['livingStatus'] ?? '');
$existingPayType = (string)($currentRow['payType'] ?? '');
$userRole = $normalizedRole;
$isAdmin = roleHasAdminAccess($conn, $userRole);
$canEditRegNo = $isAdmin || isOcPenEquivalentRole($userRole);

$requiredMessages = [
    'title' => 'Bio Data is missing the title or rank.',
    'sName' => 'Bio Data is missing the surname.',
    'fName' => 'Bio Data is missing the first name.',
    'gender' => 'Bio Data is missing gender.',
    'retirementType' => 'Bio Data is missing the mode of retirement.'
];

foreach ($requiredMessages as $field => $message) {
    $value = match ($field) {
        'title' => $title,
        'sName' => $sName,
        'fName' => $fName,
        'gender' => $gender,
        'retirementType' => $retirementType,
        default => ''
    };
    if (trim((string)$value) === '') {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

$normalizedTitle = normalizeRegistryTitle($conn, $title);
if ($normalizedTitle === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid title selected. Ask Admin to add this title in settings first.'
    ]);
    exit;
}
$title = $normalizedTitle;

if (!$canEditRegNo) {
    // Only admin/OC/Deputy can edit file numbers.
    $regNo = $existingRegNo;
}

if ($regNo === '') {
    echo json_encode(['success' => false, 'message' => 'File number is required.']);
    exit;
}

if ($address !== '') {
    $resolvedDistrict = resolvePoliticalDistrictName($conn, $address);
    if ($resolvedDistrict !== null) {
        $address = $resolvedDistrict;
    } elseif (normalizePoliticalDistrictName($address) !== normalizePoliticalDistrictName($existingAddress)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid district of residence.']);
        exit;
    }
}

if ($applicantEmail !== '' && !filter_var($applicantEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Applicant email is invalid.']);
    exit;
}

// Validate + normalize phone number.
if ($telNo === '') {
    echo json_encode(['success' => false, 'message' => 'Phone number is required.']);
    exit;
}
$normalizedTelNo = normalizePhoneNumber($telNo);
if ($normalizedTelNo === null) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format. Use international or Uganda local format (e.g. +256700123456, 0770123456, 0312123456, 0800123456).']);
    exit;
}
$telNo = $normalizedTelNo;

if ($retirementType === 'death' && ($nextOfKin === '' || $nextOfKinContact === '')) {
    echo json_encode(['success' => false, 'message' => 'Next of kin name and contact are required for Death retirements.']);
    exit;
}

if ($nextOfKinContact !== '') {
    $normalizedNextOfKinContact = normalizePhoneNumber($nextOfKinContact);
    if ($normalizedNextOfKinContact === null) {
        echo json_encode(['success' => false, 'message' => 'Next of kin contact must be a valid phone number.']);
        exit;
    }
    $nextOfKinContact = $normalizedNextOfKinContact;
}

$payType = deriveRegistryPayTypeFromProfile(
    $retirementType,
    $enlistmentDate !== '' ? $enlistmentDate : null,
    $retirementDate !== '' ? $retirementDate : null,
    $existingPayType
);
$livingStatus = deriveLivingStatusFromRetirementType($retirementType, $existingLivingStatus);

if ($canEditRegNo && $regNo !== $existingRegNo) {
    $dupStmt = $conn->prepare("SELECT id FROM tb_staffdue WHERE regNo = ? AND id <> ? LIMIT 1");
    if (!$dupStmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to validate file number uniqueness.']);
        exit;
    }
    $dupStmt->bind_param('si', $regNo, $id);
    $dupStmt->execute();
    $dupResult = $dupStmt->get_result();
    $duplicate = $dupResult->fetch_assoc();
    $dupStmt->close();

    if ($duplicate) {
        echo json_encode(['success' => false, 'message' => 'File number already exists. Please use a unique file number.']);
        exit;
    }
}

$stmt = $conn->prepare("UPDATE tb_staffdue 
    SET regNo = ?,
        computerNo = ?,
        title = ?,
        sName = ?,
        fName = ?,
        gender = ?,
        prisonUnit = ?,
        NIN = ?,
        telNo = ?,
        birthDate = ?,
        enlistmentDate = ?,
        retirementDate = ?,
        financialYear = ?,
        retirementType = ?,
        monthlySalary = ?,
        lengthOfService = ?,
        annualSalary = ?,
        reducedPension = ?,
        fullPension = ?,
        gratuity = ?,
        payType = ?,
        address = ?,
        TIN = ?,
        next_of_kin = ?,
        next_of_kin_contact = ?,
        bank_name = ?,
        bank_account = ?,
        bank_branch = ?,
        applicant_email = ?,
        livingStatus = ?
    WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update.']);
    exit;
}

$types = str_repeat('s', 30) . 'i';
$stmt->bind_param(
    $types,
    $regNo,
    $computerNo,
    $title,
    $sName,
    $fName,
    $gender,
    $prisonUnit,
    $nin,
    $telNo,
    $birthDate,
    $enlistmentDate,
    $retirementDate,
    $financialYear,
    $retirementType,
    $monthlySalary,
    $lengthOfService,
    $annualSalary,
    $reducedPension,
    $fullPension,
    $gratuity,
    $payType,
    $address,
    $tin,
    $nextOfKin,
    $nextOfKinContact,
    $bankName,
    $bankAccount,
    $bankBranch,
    $applicantEmail,
    $livingStatus,
    $id
);

$oldRegNo = $existingRegNo;
$newRegNo = $regNo;
$regNoChangedByAdmin = $canEditRegNo && ($newRegNo !== $oldRegNo);

$conn->begin_transaction();
try {
    if ($regNoChangedByAdmin) {
        $conn->query("SET FOREIGN_KEY_CHECKS=0");
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Database update failed.');
    }

    if ($regNoChangedByAdmin) {
        // Keep downstream records aligned when a privileged user corrects file number.
        updateRegNoAcrossTables($conn, $oldRegNo, $newRegNo, []);
        updatePensionerUserMetaRegNo($conn, $oldRegNo, $newRegNo);
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Record updated successfully.']);
} catch (Throwable $e) {
    $conn->rollback();
    if ($regNoChangedByAdmin) {
        $conn->query("SET FOREIGN_KEY_CHECKS=1");
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Database update failed.']);
}

$stmt->close();
$conn->close();
?>
