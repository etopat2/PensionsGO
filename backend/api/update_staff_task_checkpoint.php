<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureTasksTable($conn);
ensureStaffDueExtendedColumns($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$taskId = isset($payload['taskId']) ? (int)$payload['taskId'] : 0;
$staffId = isset($payload['staffId']) ? (int)$payload['staffId'] : 0;
$mode = isset($payload['mode']) ? trim((string)$payload['mode']) : '';
$input = isset($payload['payload']) && is_array($payload['payload']) ? $payload['payload'] : [];

if ($taskId <= 0 || $staffId <= 0 || $mode === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$allowedModes = ['writeup_verify', 'assessor_verify', 'data_entry_verify'];
if (!in_array($mode, $allowedModes, true)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported checkpoint mode']);
    exit;
}

$currentUserId = (string)($_SESSION['userId'] ?? '');
$currentUserRole = strtolower((string)($_SESSION['userRole'] ?? ''));
$isAdmin = $currentUserRole === 'admin';

if ($mode === 'writeup_verify' && !$isAdmin && $currentUserRole !== 'writeup_officer') {
    echo json_encode(['success' => false, 'message' => 'Write-up verification is restricted to writeup officers.']);
    exit;
}

if ($mode === 'assessor_verify' && !$isAdmin && $currentUserRole !== 'assessor') {
    echo json_encode(['success' => false, 'message' => 'Assessment verification is restricted to assessors.']);
    exit;
}

if ($mode === 'data_entry_verify' && !$isAdmin && $currentUserRole !== 'data_entry') {
    echo json_encode(['success' => false, 'message' => 'Data-entry verification is restricted to data entrants.']);
    exit;
}

$taskStmt = $conn->prepare("
    SELECT taskId, task_type, assigned_to, assigned_role, related_staff_id
    FROM tb_tasks
    WHERE taskId = ?
    LIMIT 1
");
if (!$taskStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load task']);
    exit;
}
$taskStmt->bind_param("i", $taskId);
$taskStmt->execute();
$taskResult = $taskStmt->get_result();
$task = $taskResult ? $taskResult->fetch_assoc() : null;
$taskStmt->close();

if (!$task) {
    echo json_encode(['success' => false, 'message' => 'Task not found']);
    exit;
}

$taskStaffId = isset($task['related_staff_id']) ? (int)$task['related_staff_id'] : 0;
if ($taskStaffId !== $staffId) {
    echo json_encode(['success' => false, 'message' => 'Task does not match the selected applicant']);
    exit;
}

$taskAssignedTo = (string)($task['assigned_to'] ?? '');
$taskAssignedRole = (string)($task['assigned_role'] ?? '');
$isAuthorizedActor = $isAdmin
    || ($taskAssignedTo !== '' && $taskAssignedTo === $currentUserId)
    || ($taskAssignedTo === '' && $taskAssignedRole !== '' && $taskAssignedRole === $currentUserRole);

if (!$isAuthorizedActor) {
    echo json_encode(['success' => false, 'message' => 'You are not allowed to update this task checkpoint']);
    exit;
}

$taskType = (string)($task['task_type'] ?? '');
if (!$isAdmin) {
    if ($mode === 'writeup_verify' && $taskType !== 'writeup') {
        echo json_encode(['success' => false, 'message' => 'Write-up checkpoint is only valid for write-up tasks']);
        exit;
    }
    if ($mode === 'assessor_verify' && $taskType !== 'assessment') {
        echo json_encode(['success' => false, 'message' => 'Assessment checkpoint is only valid for assessment tasks']);
        exit;
    }
    if ($mode === 'data_entry_verify' && $taskType !== 'data_entry') {
        echo json_encode(['success' => false, 'message' => 'Data-entry checkpoint is only valid for data-entry tasks']);
        exit;
    }
}

$staffStmt = $conn->prepare("SELECT id, retirementType, enlistmentDate, retirementDate, payType FROM tb_staffdue WHERE id = ? LIMIT 1");
if (!$staffStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load applicant record']);
    exit;
}
$staffStmt->bind_param("i", $staffId);
$staffStmt->execute();
$staffResult = $staffStmt->get_result();
$staffRow = $staffResult ? $staffResult->fetch_assoc() : null;
$staffStmt->close();

if (!$staffRow) {
    echo json_encode(['success' => false, 'message' => 'Applicant record not found']);
    exit;
}

function formatMoney(float $value): string
{
    return number_format($value, 2, '.', '');
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

if ($mode === 'writeup_verify') {
    $title = trim((string)($input['title'] ?? ''));
    $retirementType = normalizeBenefitsRetirementTypeKey((string)($input['retirementType'] ?? ''));
    $birthDate = trim((string)($input['birthDate'] ?? ''));
    $enlistmentDate = trim((string)($input['enlistmentDate'] ?? ''));
    $retirementDate = trim((string)($input['retirementDate'] ?? ''));
    $monthlySalaryRaw = trim((string)($input['monthlySalary'] ?? ''));

    if ($title === '' || $retirementType === '' || $enlistmentDate === '' || $retirementDate === '' || $monthlySalaryRaw === '') {
        echo json_encode(['success' => false, 'message' => 'Missing required fields for write-up verification']);
        exit;
    }

    if (!is_numeric($monthlySalaryRaw)) {
        echo json_encode(['success' => false, 'message' => 'Monthly salary must be numeric']);
        exit;
    }
    if (!isBenefitsRetirementTypeSupported($retirementType)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid mode of retirement']);
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

    $monthlySalaryValue = max(0.0, (float)$monthlySalaryRaw);
    $snapshot = calculateBenefitSnapshotFromInputs(
        $retirementType,
        $enlistmentDate !== '' ? $enlistmentDate : null,
        $retirementDate !== '' ? $retirementDate : null,
        $monthlySalaryValue,
        $birthDate !== '' ? $birthDate : null
    );

    $financialYear = computeFinancialYearLabel($retirementDate);
    $monthlySalary = formatMoney($monthlySalaryValue);
    $annualSalary = formatMoney((float)($snapshot['annualSalary'] ?? ($monthlySalaryValue * 12)));
    $reduced = formatMoney((float)($snapshot['reducedPension'] ?? 0.0));
    $full = formatMoney((float)($snapshot['fullPension'] ?? 0.0));
    $gratuity = formatMoney((float)($snapshot['gratuity'] ?? 0.0));
    $lengthOfService = (string)((int)($snapshot['lengthOfService'] ?? computeServiceMonths($enlistmentDate, $retirementDate)));
    $payTypeNormalized = deriveRegistryPayTypeFromProfile(
        $retirementType,
        $enlistmentDate !== '' ? $enlistmentDate : null,
        $retirementDate !== '' ? $retirementDate : null,
        (string)($staffRow['payType'] ?? '')
    );
    $livingStatus = deriveLivingStatusFromRetirementType($retirementType, (string)($staffRow['livingStatus'] ?? 'Alive'));

    $stmt = $conn->prepare("
        UPDATE tb_staffdue
        SET title = ?,
            retirementType = ?,
            birthDate = ?,
            enlistmentDate = ?,
            retirementDate = ?,
            monthlySalary = ?,
            lengthOfService = ?,
            annualSalary = ?,
            reducedPension = ?,
            fullPension = ?,
            gratuity = ?,
            payType = ?,
            financialYear = ?,
            livingStatus = ?
        WHERE id = ?
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to update write-up verification details']);
        exit;
    }
    $stmt->bind_param(
        "ssssssssssssssi",
        $title,
        $retirementType,
        $birthDate,
        $enlistmentDate,
        $retirementDate,
        $monthlySalary,
        $lengthOfService,
        $annualSalary,
        $reduced,
        $full,
        $gratuity,
        $payTypeNormalized,
        $financialYear,
        $livingStatus,
        $staffId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to save write-up verification details']);
        exit;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Write-up verification saved',
        'computed' => [
            'financialYear' => $financialYear,
            'lengthOfService' => $lengthOfService,
            'annualSalary' => $annualSalary,
            'reducedPension' => $reduced,
            'fullPension' => $full,
            'gratuity' => $gratuity,
            'payType' => $payTypeNormalized
        ]
    ]);
    exit;
}

if ($mode === 'data_entry_verify') {
    $livingStatusRaw = trim((string)($input['livingStatus'] ?? ''));
    $payTypeRaw = trim((string)($input['payType'] ?? ''));
    $addressRaw = trim((string)($input['address'] ?? ''));
    $applicantEmailRaw = trim((string)($input['applicant_email'] ?? ''));
    $nextOfKinRaw = trim((string)($input['next_of_kin'] ?? ''));
    $nextOfKinContactRaw = trim((string)($input['next_of_kin_contact'] ?? ''));
    $bankNameRaw = trim((string)($input['bank_name'] ?? ''));
    $bankAccountRaw = trim((string)($input['bank_account'] ?? ''));
    $bankBranchRaw = trim((string)($input['bank_branch'] ?? ''));
    $retirementType = normalizeBenefitsRetirementTypeKey((string)($staffRow['retirementType'] ?? ''));

    if ($retirementType === 'death') {
        $livingStatusRaw = 'Deceased';
    }

    if ($livingStatusRaw === '' || !in_array($livingStatusRaw, ['Alive', 'Deceased'], true)) {
        echo json_encode(['success' => false, 'message' => 'Select a valid living status']);
        exit;
    }

    if ($addressRaw === '') {
        echo json_encode(['success' => false, 'message' => 'District of residence is required']);
        exit;
    }

    $resolvedDistrict = resolvePoliticalDistrictName($conn, $addressRaw);
    if ($resolvedDistrict === null) {
        echo json_encode(['success' => false, 'message' => 'Select a valid district of residence']);
        exit;
    }

    if ($bankNameRaw === '' || $bankAccountRaw === '' || $bankBranchRaw === '') {
        echo json_encode(['success' => false, 'message' => 'Bank name, bank account, and bank branch are required']);
        exit;
    }

    if ($applicantEmailRaw !== '' && !filter_var($applicantEmailRaw, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Applicant email is invalid']);
        exit;
    }

    $normalizedNextOfKinContact = '';
    if ($nextOfKinContactRaw !== '') {
        $normalizedNextOfKinContact = normalizePhoneNumber($nextOfKinContactRaw) ?? '';
        if ($normalizedNextOfKinContact === '') {
            echo json_encode(['success' => false, 'message' => 'Next of kin contact must be a valid phone number']);
            exit;
        }
    }
    if ($retirementType === 'death' && ($nextOfKinRaw === '' || $normalizedNextOfKinContact === '')) {
        echo json_encode(['success' => false, 'message' => 'Next of kin name and contact are required for Death retirements']);
        exit;
    }

    $payTypeNormalized = deriveRegistryPayTypeFromProfile(
        $retirementType,
        (string)($staffRow['enlistmentDate'] ?? ''),
        (string)($staffRow['retirementDate'] ?? ''),
        $payTypeRaw !== '' ? $payTypeRaw : (string)($staffRow['payType'] ?? '')
    );

    $stmt = $conn->prepare("
        UPDATE tb_staffdue
        SET livingStatus = ?,
            payType = ?,
            address = ?,
            applicant_email = ?,
            next_of_kin = ?,
            next_of_kin_contact = ?,
            bank_name = ?,
            bank_account = ?,
            bank_branch = ?
        WHERE id = ?
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to update data-entry verification details']);
        exit;
    }
    $stmt->bind_param(
        "sssssssssi",
        $livingStatusRaw,
        $payTypeNormalized,
        $resolvedDistrict,
        $applicantEmailRaw,
        $nextOfKinRaw,
        $normalizedNextOfKinContact,
        $bankNameRaw,
        $bankAccountRaw,
        $bankBranchRaw,
        $staffId
    );
    if (!$stmt->execute()) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to save data-entry verification details']);
        exit;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Data-entry verification saved',
        'computed' => [
            'livingStatus' => $livingStatusRaw,
            'payType' => $payTypeNormalized,
            'address' => $resolvedDistrict,
            'applicant_email' => $applicantEmailRaw,
            'next_of_kin' => $nextOfKinRaw,
            'next_of_kin_contact' => $normalizedNextOfKinContact,
            'bank_name' => $bankNameRaw,
            'bank_account' => $bankAccountRaw,
            'bank_branch' => $bankBranchRaw
        ]
    ]);
    exit;
}

$reducedRaw = trim((string)($input['reducedPension'] ?? ''));
$fullRaw = trim((string)($input['fullPension'] ?? ''));
$gratuityRaw = trim((string)($input['gratuity'] ?? ''));
$payTypeRaw = trim((string)($input['payType'] ?? ''));

if ($reducedRaw === '' || $fullRaw === '' || $gratuityRaw === '') {
    echo json_encode(['success' => false, 'message' => 'All benefit values are required']);
    exit;
}

if (!is_numeric($reducedRaw) || !is_numeric($fullRaw) || !is_numeric($gratuityRaw)) {
    echo json_encode(['success' => false, 'message' => 'Benefit values must be numeric']);
    exit;
}

$reduced = formatMoney(max(0.0, (float)$reducedRaw));
$full = formatMoney(max(0.0, (float)$fullRaw));
$gratuity = formatMoney(max(0.0, (float)$gratuityRaw));

$payTypeNormalized = deriveRegistryPayTypeFromProfile(
    (string)($staffRow['retirementType'] ?? ''),
    (string)($staffRow['enlistmentDate'] ?? ''),
    (string)($staffRow['retirementDate'] ?? ''),
    $payTypeRaw !== '' ? $payTypeRaw : (string)($staffRow['payType'] ?? '')
);

$stmt = $conn->prepare("
    UPDATE tb_staffdue
    SET reducedPension = ?,
        fullPension = ?,
        gratuity = ?,
        payType = ?
    WHERE id = ?
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to update assessment details']);
    exit;
}
$stmt->bind_param("ssssi", $reduced, $full, $gratuity, $payTypeNormalized, $staffId);

if (!$stmt->execute()) {
    $stmt->close();
    echo json_encode(['success' => false, 'message' => 'Failed to save assessment details']);
    exit;
}
$stmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Assessment verification saved',
    'computed' => [
        'reducedPension' => $reduced,
        'fullPension' => $full,
        'gratuity' => $gratuity,
        'payType' => $payTypeNormalized
    ]
]);
exit;
?>
