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
    'gratuity', 'submissionStatus', 'appnStatus', 'employeeNo', 'pensionNo', 'ippsNo',
    'rankPosition', 'firstName', 'middleName', 'lastName', 'next_of_kin_nin',
    'salaryScale', 'employmentStatus', 'service_file_status', 'service_file_location',
    'rankName','positionName','tribe','homeDistrict','homeRegion','religion','country','subCounty','parish','village','alternateTelNo','maritalStatus',
    'applicant_email','TIN','address','next_of_kin','next_of_kin_contact','bank_name','bank_account','bank_branch',
    'livingStatus','payType','dateOfDeath','earning_period_label'
    ,'beneficiary_type','beneficiary_first_name','beneficiary_middle_name','beneficiary_last_name','beneficiary_gender','beneficiary_nin','beneficiary_telephone','beneficiary_email','beneficiary_ipps_no','beneficiary_supplier_no','relationship_to_deceased','administration_reference','earning_start_date','earning_end_date','beneficiary_bank_name','beneficiary_bank_account','beneficiary_bank_branch','beneficiary_notes'
];

// Accept the HRMIS vocabulary while maintaining legacy aliases used downstream.
if (trim((string)($data['employeeNo'] ?? '')) === '' && trim((string)($data['regNo'] ?? '')) !== '') {
    $legacyNumber = trim((string)$data['regNo']);
    $data['employeeNo'] = stripos($legacyNumber, 'PEN/') === 0 ? substr($legacyNumber, 4) : $legacyNumber;
}
$data['employeeNo'] = normalizeEmployeeNumber($data['employeeNo'] ?? '');
if ($data['employeeNo'] !== '' && !preg_match('#^(?:[0-9]+|P/[A-Z]/[0-9]+)$#', $data['employeeNo'])) {
    echo json_encode(['success'=>false,'message'=>'Employee Number must use 123 or P/A/123 format.']);
    exit;
}
if (($data['employeeNo'] ?? '') !== '') {
    $data['pensionNo'] = pensionNumberFromEmployeeNumber($data['employeeNo']);
    $data['regNo'] = $data['pensionNo']; // legacy pension-file compatibility alias
}
if (($data['ippsNo'] ?? '') !== '') $data['computerNo'] = $data['ippsNo'];
$data['rankPosition'] = trim((string)($data['positionName'] ?? '')) ?: (trim((string)($data['title'] ?? '')) ?: trim((string)($data['rankPosition'] ?? '')));
if (($data['rankPosition'] ?? '') !== '') $data['title'] = $data['rankPosition'];
if (($data['firstName'] ?? '') !== '' || ($data['middleName'] ?? '') !== '') $data['fName'] = trim(($data['firstName'] ?? '') . ' ' . ($data['middleName'] ?? ''));
if (($data['lastName'] ?? '') !== '') $data['sName'] = $data['lastName'];

if (trim((string)($data['salaryScale'] ?? '')) !== '') {
    ensureSalaryScalesTable($conn);
    $salaryScale = strtoupper(trim((string)$data['salaryScale']));
    $scaleStmt = $conn->prepare('SELECT 1 FROM tb_salary_scales WHERE scale_code=? AND is_active=1 LIMIT 1');
    $scaleStmt->bind_param('s', $salaryScale);
    $scaleStmt->execute();
    $scaleExists = $scaleStmt->get_result()->num_rows > 0;
    $scaleStmt->close();
    if (!$scaleExists) {
        echo json_encode(['success'=>false,'message'=>'Select an active salary scale from Settings.']);
        exit;
    }
    $data['salaryScale'] = $salaryScale;
}

ensureStaffReferenceTables($conn);
foreach([['employmentStatus','tb_employment_statuses','status_name','employment status'],['tribe','tb_tribes','tribe_name','tribe'],['religion','tb_religions','religion_name','religion']] as [$field,$table,$column,$label]){
    $value=trim((string)($data[$field]??''));if($value==='')continue;$reference=$conn->prepare("SELECT {$column} canonical FROM {$table} WHERE LOWER({$column})=LOWER(?) AND is_active=1 LIMIT 1");$reference->bind_param('s',$value);$reference->execute();$match=$reference->get_result()->fetch_assoc();$reference->close();if(!$match){echo json_encode(['success'=>false,'message'=>"Select a valid {$label} from Settings."]);exit;}$data[$field]=$match['canonical'];
}
if(trim((string)($data['homeDistrict']??''))!==''){$district=resolvePoliticalDistrictName($conn,(string)$data['homeDistrict']);if($district===null){echo json_encode(['success'=>false,'message'=>'Select a valid political district.']);exit;}$data['homeDistrict']=$district;$regionStmt=$conn->prepare('SELECT polRegion FROM tb_poldistricts WHERE polDistrict=? LIMIT 1');$regionStmt->bind_param('s',$district);$regionStmt->execute();$region=$regionStmt->get_result()->fetch_assoc();$regionStmt->close();if($region)$data['homeRegion']=trim((string)$region['polRegion']);}

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
    'title' => 'Identity Profile is missing the officer position.',
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
        'message' => 'Invalid position selected. Ask Admin to add it in Title Settings first.'
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

if ($data['employeeNo'] !== '') {
    $employeeStmt = $conn->prepare("SELECT id FROM tb_staffdue WHERE employeeNo = ? LIMIT 1");
    $employeeStmt->bind_param('s', $data['employeeNo']); $employeeStmt->execute();
    $employeeDuplicate = $employeeStmt->get_result()->fetch_assoc(); $employeeStmt->close();
    if ($employeeDuplicate) { echo json_encode(['success' => false, 'message' => 'Employee number already exists.']); exit; }
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

$provisionalDeceased=normalizeBenefitsRetirementTypeKey((string)($data['retirementType']??''))==='death'||strtolower(trim((string)($data['livingStatus']??'')))==='deceased';
if(!$provisionalDeceased){foreach(['beneficiary_ipps_no','beneficiary_supplier_no','administration_reference','earning_start_date','earning_end_date','earning_period_label','beneficiary_bank_name','beneficiary_bank_account','beneficiary_bank_branch'] as $field)$data[$field]='';}
$beneficiaryNinValidation = validateNationalIdNumber($data['beneficiary_nin'] ?? '', null, $data['beneficiary_gender'] !== '' ? $data['beneficiary_gender'] : null);
if (!$beneficiaryNinValidation['valid']) {
    echo json_encode(['success'=>false,'message'=>'Beneficiary/NOK: '.($beneficiaryNinValidation['message']??'NIN is invalid.')]);exit;
}
$data['beneficiary_nin']=(string)($beneficiaryNinValidation['normalized']??'');
$beneficiaryHasData=false;foreach(['beneficiary_first_name','beneficiary_last_name','beneficiary_nin','beneficiary_telephone','beneficiary_supplier_no','beneficiary_bank_account'] as $field){if($data[$field]!==''){$beneficiaryHasData=true;break;}}
if($beneficiaryHasData && ($data['beneficiary_first_name']==='' || $data['beneficiary_last_name']==='' || $data['beneficiary_nin']==='')){echo json_encode(['success'=>false,'message'=>'Beneficiary first name, last name and NIN are required when beneficiary details are supplied.']);exit;}
if($data['beneficiary_telephone']!==''){$phone=normalizePhoneNumber($data['beneficiary_telephone']);if($phone===null){echo json_encode(['success'=>false,'message'=>'Beneficiary phone number is invalid.']);exit;}$data['beneficiary_telephone']=$phone;}

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
$livingStatus = deriveLivingStatusFromRetirementType($retirementType, $data['livingStatus'] !== '' ? $data['livingStatus'] : 'Alive');
$data['livingStatus']=$livingStatus;
$data['payType']=$payType;
$isDeceased=strtolower($livingStatus)==='deceased';
if($isDeceased&&$data['dateOfDeath']===''&&$retirementType==='death')$data['dateOfDeath']=$data['retirementDate'];
if($isDeceased&&$data['dateOfDeath']===''){echo json_encode(['success'=>false,'message'=>'Date of death is required for a deceased officer.']);exit;}
$earningPolicy=calculateBeneficiaryEarningPeriod($data['retirementDate'],$data['dateOfDeath'],$retirementType==='death');
if($isDeceased){$data['earning_start_date']=$data['dateOfDeath'];$data['earning_end_date']=(string)($earningPolicy['expiry_date']??'');$data['earning_period_label']=(string)($earningPolicy['remaining_label']??'');}

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
    $staffId = (int)$conn->insert_id;
    $extendedColumns=['employeeNo','pensionNo','ippsNo','rankPosition','rankName','positionName','firstName','middleName','lastName','next_of_kin_nin','salaryScale','employmentStatus','service_file_status','service_file_location','tribe','homeDistrict','homeRegion','religion','country','subCounty','parish','village','alternateTelNo','maritalStatus','applicant_email','TIN','address','next_of_kin','next_of_kin_contact','bank_name','bank_account','bank_branch','livingStatus','payType','dateOfDeath'];
    $extendedValues=[];foreach($extendedColumns as $column)$extendedValues[]=(string)($data[$column]??'');
    $extended = $conn->prepare("UPDATE tb_staffdue SET ".implode('=?,',$extendedColumns)."=? WHERE id=?");
    $types=str_repeat('s',count($extendedValues)).'i';$extendedValues[]=$staffId;$extended->bind_param($types,...$extendedValues);
    $extended->execute(); $extended->close();
    if($beneficiaryHasData){
        ensurePensionBeneficiaryTables($conn);
        $sql='INSERT INTO tb_pension_beneficiaries (deceased_staffdue_id,deceased_ipps_no,beneficiary_type,first_name,middle_name,last_name,gender,beneficiary_nin,beneficiary_ipps_no,beneficiary_supplier_no,telephone,email,relationship_to_deceased,administration_reference,earning_start_date,earning_end_date,bank_name,bank_account,bank_branch,is_primary,notes,created_by,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,NOW())';
        $beneficiary=$conn->prepare($sql);$actor=(string)$_SESSION['userId'];$beneficiary->bind_param('issssssssssssssssssss',$staffId,$data['ippsNo'],$data['beneficiary_type'],$data['beneficiary_first_name'],$data['beneficiary_middle_name'],$data['beneficiary_last_name'],$data['beneficiary_gender'],$data['beneficiary_nin'],$data['beneficiary_ipps_no'],$data['beneficiary_supplier_no'],$data['beneficiary_telephone'],$data['beneficiary_email'],$data['relationship_to_deceased'],$data['administration_reference'],$data['earning_start_date'],$data['earning_end_date'],$data['beneficiary_bank_name'],$data['beneficiary_bank_account'],$data['beneficiary_bank_branch'],$data['beneficiary_notes'],$actor);$beneficiary->execute();$beneficiaryId=(int)$conn->insert_id;$beneficiary->close();$period=$conn->prepare('UPDATE tb_pension_beneficiaries SET earning_basis_date=?,earning_expiry_date=?,last_earning_month=?,earning_period_label=? WHERE beneficiary_id=?');$basis=(string)($earningPolicy['basis_date']??'');$expiry=(string)($earningPolicy['expiry_date']??'');$lastMonth=(string)($earningPolicy['last_earning_month']??'');$label=(string)($earningPolicy['remaining_label']??'');$period->bind_param('ssssi',$basis,$expiry,$lastMonth,$label,$beneficiaryId);$period->execute();$period->close();
    }
    echo json_encode(['success' => true, 'message' => 'Staff added successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to insert record: ' . $stmt->error]);
}

$stmt->close();
$conn->close();
