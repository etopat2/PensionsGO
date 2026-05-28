<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId']) || !currentUserHasPermission($conn, 'staff_due.edit')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (function_exists('ensureTitlesTable')) {
    ensureTitlesTable($conn);
}
if (function_exists('ensureStaffDueBaseColumns')) {
    ensureStaffDueBaseColumns($conn);
}
if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || !is_array($data)) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$fields = [
    'regNo', 'computerNo', 'title', 'sName', 'fName', 'gender',
    'prisonUnit', 'NIN', 'telNo', 'birthDate', 'enlistmentDate',
    'retirementDate', 'financialYear', 'retirementType', 'monthlySalary',
    'lengthOfService', 'annualSalary', 'reducedPension', 'fullPension',
    'gratuity', 'submissionStatus', 'appnStatus'
];

if (!array_key_exists('computerNo', $data) && array_key_exists('supplierNo', $data)) {
    $data['computerNo'] = $data['supplierNo'];
}

foreach ($fields as $field) {
    if (!isset($data[$field]) || $data[$field] === null) {
        $data[$field] = '';
    }
    if (is_string($data[$field])) {
        $data[$field] = trim($data[$field]);
    }
}

$requiredMessages = [
    'regNo' => 'Identity Profile is missing the file number.',
    'title' => 'Identity Profile is missing the title or rank.',
    'sName' => 'Identity Profile is missing the surname.',
    'fName' => 'Identity Profile is missing the first name.',
    'gender' => 'Identity Profile is missing gender.',
    'retirementDate' => 'Service & Benefits is missing the retirement date.',
    'retirementType' => 'Service & Benefits is missing the mode of retirement.'
];

foreach ($requiredMessages as $field => $message) {
    if ($data[$field] === '') {
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

$normalizedTitle = normalizeRegistryTitle($conn, $data['title']);
if ($normalizedTitle === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid title selected. Ask Admin to add this title in settings first.'
    ]);
    exit;
}
$data['title'] = $normalizedTitle;

$duplicateStmt = $conn->prepare("SELECT id FROM tb_staffdue WHERE regNo = ? LIMIT 1");
if (!$duplicateStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to validate file number uniqueness.']);
    exit;
}
$duplicateStmt->bind_param('s', $data['regNo']);
$duplicateStmt->execute();
$duplicateResult = $duplicateStmt->get_result();
$duplicateRow = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
$duplicateStmt->close();
if ($duplicateRow) {
    echo json_encode(['success' => false, 'message' => 'File number already exists. Please use a unique file number.']);
    exit;
}

$rawTel = $data['telNo'];
if ($rawTel !== '') {
    $normalizedTel = normalizePhoneNumber($rawTel);
    if ($normalizedTel === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid phone number format. Use international or Uganda local format (e.g. +256700123456, 0770123456, 0312123456, 0800123456).'
        ]);
        exit;
    }
    $data['telNo'] = $normalizedTel;
}

$ninValidation = validateNationalIdNumber(
    $data['NIN'] ?? '',
    $data['birthDate'] !== '' ? $data['birthDate'] : null,
    $data['gender'] !== '' ? $data['gender'] : null
);
if (!$ninValidation['valid']) {
    echo json_encode(['success' => false, 'message' => (string)($ninValidation['message'] ?? 'NIN is invalid.')]);
    exit;
}
$data['NIN'] = (string)($ninValidation['normalized'] ?? '');

$monthlySalary = is_numeric($data['monthlySalary']) ? max(0.0, (float)$data['monthlySalary']) : 0.0;
$retirementType = normalizeBenefitsRetirementTypeKey($data['retirementType']);
if (!isBenefitsRetirementTypeSupported($retirementType)) {
    echo json_encode(['success' => false, 'message' => 'Select a valid mode of retirement.']);
    exit;
}
$data['retirementType'] = $retirementType;

$policyAssessment = validateRetirementPolicyProfile(
    $retirementType,
    $data['birthDate'] !== '' ? $data['birthDate'] : null,
    $data['enlistmentDate'] !== '' ? $data['enlistmentDate'] : null,
    $data['retirementDate'] !== '' ? $data['retirementDate'] : null
);
if (!empty($policyAssessment['errors'])) {
    echo json_encode(['success' => false, 'message' => (string)($policyAssessment['primary_message'] ?? 'The retirement profile does not satisfy the configured policy checks.')]);
    exit;
}

$retirementDate = $data['retirementDate'];
$retirementTs = strtotime($retirementDate);
if ($retirementTs !== false) {
    $year = (int)date('Y', $retirementTs);
    $month = (int)date('n', $retirementTs);
    $startYear = $month <= 6 ? $year - 1 : $year;
    $endYear = $month <= 6 ? $year : $year + 1;
    $data['financialYear'] = 'FY ' . $startYear . '/' . $endYear;
}

$benefitSnapshot = calculateBenefitSnapshotFromInputs(
    $retirementType,
    $data['enlistmentDate'],
    $data['retirementDate'],
    $monthlySalary,
    $data['birthDate'] !== '' ? $data['birthDate'] : null
);

$lengthOfService = (int)($benefitSnapshot['lengthOfService'] ?? 0);
$annualSalary = (float)($benefitSnapshot['annualSalary'] ?? round($monthlySalary * 12, 2));
$reducedPension = (float)($benefitSnapshot['reducedPension'] ?? 0.0);
$fullPension = (float)($benefitSnapshot['fullPension'] ?? 0.0);
$gratuity = (float)($benefitSnapshot['gratuity'] ?? 0.0);
$payType = deriveRegistryPayTypeFromProfile(
    $retirementType,
    $data['enlistmentDate'] !== '' ? $data['enlistmentDate'] : null,
    $data['retirementDate'] !== '' ? $data['retirementDate'] : null,
    null
);
$livingStatus = deriveLivingStatusFromRetirementType($retirementType, 'Alive');

$submissionStatus = $data['submissionStatus'] !== '' ? $data['submissionStatus'] : 'pending';
$appnStatus = $data['appnStatus'] !== '' ? $data['appnStatus'] : 'pending';

$stmt = $conn->prepare("
    INSERT INTO tb_staffdue (
        regNo, computerNo, title, sName, fName, gender, prisonUnit, NIN, telNo, birthDate,
        enlistmentDate, retirementDate, financialYear, retirementType, monthlySalary,
        lengthOfService, annualSalary, reducedPension, fullPension, gratuity,
        payType, livingStatus, submissionStatus, appnStatus
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $conn->error]);
    exit;
}

$stmt->bind_param(
    str_repeat('s', 14) . 'diddddssss',
    $data['regNo'],
    $data['computerNo'],
    $data['title'],
    $data['sName'],
    $data['fName'],
    $data['gender'],
    $data['prisonUnit'],
    $data['NIN'],
    $data['telNo'],
    $data['birthDate'],
    $data['enlistmentDate'],
    $data['retirementDate'],
    $data['financialYear'],
    $data['retirementType'],
    $monthlySalary,
    $lengthOfService,
    $annualSalary,
    $reducedPension,
    $fullPension,
    $gratuity,
    $payType,
    $livingStatus,
    $submissionStatus,
    $appnStatus
);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'message' => 'Staff added successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to insert record: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
