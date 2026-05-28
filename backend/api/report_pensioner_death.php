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

if (!currentUserHasPermission($conn, 'registry.edit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureFileMovementTables($conn);
ensurePensionerDeathReportingTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$registryId = (int)($payload['registry_id'] ?? $payload['registryId'] ?? 0);
$regNo = trim((string)($payload['regNo'] ?? ''));
$dateOfDeath = trim((string)($payload['date_of_death'] ?? $payload['dateOfDeath'] ?? ''));
$notifierName = trim((string)($payload['notifier_name'] ?? $payload['notifierName'] ?? ''));
$notifierContact = trim((string)($payload['notifier_contact'] ?? $payload['notifierContact'] ?? ''));
$notificationDate = trim((string)($payload['notification_date'] ?? $payload['notificationDate'] ?? ''));
$notes = trim((string)($payload['notes'] ?? ''));

if ($registryId <= 0 && $regNo === '') {
    echo json_encode(['success' => false, 'message' => 'Select a pensioner record first.']);
    exit;
}
if ($dateOfDeath === '') {
    echo json_encode(['success' => false, 'message' => 'Enter the date of death.']);
    exit;
}
if ($notifierName === '') {
    echo json_encode(['success' => false, 'message' => 'Enter the name of the notifying person.']);
    exit;
}
if ($notifierContact === '') {
    echo json_encode(['success' => false, 'message' => 'Enter the notifying person contact.']);
    exit;
}
if ($notificationDate === '') {
    echo json_encode(['success' => false, 'message' => 'Enter the notification date.']);
    exit;
}

$normalizedNotifierContact = normalizePhoneNumber($notifierContact);
if ($normalizedNotifierContact === null) {
    echo json_encode(['success' => false, 'message' => 'The notifying person contact number is invalid.']);
    exit;
}
$notifierContact = $normalizedNotifierContact;

$lookupSql = "
    SELECT id, regNo, title, sName, fName, retirementType, retirementDate, enlistmentDate, livingStatus, payType, dateOfDeath
    FROM tb_fileregistry
    WHERE COALESCE(is_deleted, 0) = 0
";
$params = [];
$types = '';
if ($registryId > 0) {
    $lookupSql .= " AND id = ?";
    $params[] = $registryId;
    $types .= 'i';
} else {
    $lookupSql .= " AND regNo = ?";
    $params[] = $regNo;
    $types .= 's';
}
$lookupSql .= " LIMIT 1";

$stmt = $conn->prepare($lookupSql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load the pensioner record.']);
    exit;
}
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$record = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Pensioner record not found.']);
    exit;
}

$registryId = (int)($record['id'] ?? 0);
$regNo = trim((string)($record['regNo'] ?? ''));
$retirementType = normalizeBenefitsRetirementTypeKey((string)($record['retirementType'] ?? ''));
$retirementDate = trim((string)($record['retirementDate'] ?? ''));
$payType = deriveRegistryPayTypeFromProfile(
    $retirementType,
    (string)($record['enlistmentDate'] ?? ''),
    $retirementDate,
    (string)($record['payType'] ?? '')
);

if ($retirementType === 'death') {
    echo json_encode(['success' => false, 'message' => 'Use this death-report workflow only for pensioners whose retirement label is not Death.']);
    exit;
}

if (normalizeRegistryPayType($payType) !== 'Pensioner') {
    echo json_encode(['success' => false, 'message' => 'Only pensioner records can be reported through this death-report workflow.']);
    exit;
}

if (trim((string)($record['dateOfDeath'] ?? '')) !== '') {
    echo json_encode(['success' => false, 'message' => 'Death has already been reported for this pensioner record.']);
    exit;
}

if ($retirementDate === '') {
    echo json_encode(['success' => false, 'message' => 'The registry record is missing the retirement date required for estate validation.']);
    exit;
}

$deathTs = strtotime($dateOfDeath);
$retireTs = strtotime($retirementDate);
$notificationTs = strtotime($notificationDate);
if ($deathTs === false || $retireTs === false || $notificationTs === false) {
    echo json_encode(['success' => false, 'message' => 'Provide valid death and notification dates.']);
    exit;
}
if ($deathTs < $retireTs) {
    echo json_encode(['success' => false, 'message' => 'The date of death cannot be earlier than the retirement date.']);
    exit;
}
if ($notificationTs < $deathTs) {
    echo json_encode(['success' => false, 'message' => 'The notification date cannot be earlier than the reported date of death.']);
    exit;
}

$estate = evaluatePensionEstateLifecycle($retirementDate, $payType, 'Deceased', $dateOfDeath, $notificationDate);
$estateExpiryDate = $estate['estateExpiryDate'] ?? null;
$estateStatus = $estate['label'] ?? 'Estate Active';

$conn->begin_transaction();

$insertStmt = $conn->prepare("
    INSERT INTO tb_pensioner_death_reports
    (
        registry_id,
        regNo,
        date_of_death,
        notifier_name,
        notifier_contact,
        notification_date,
        notes,
        recorded_by,
        recorded_by_name,
        recorded_by_role
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$insertStmt) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Unable to prepare the death report record.']);
    exit;
}
$recordedBy = (string)($_SESSION['userId'] ?? '');
$recordedByName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'System');
$recordedByRole = (string)($_SESSION['userRole'] ?? '');
$insertStmt->bind_param(
    "isssssssss",
    $registryId,
    $regNo,
    $dateOfDeath,
    $notifierName,
    $notifierContact,
    $notificationDate,
    $notes,
    $recordedBy,
    $recordedByName,
    $recordedByRole
);
if (!$insertStmt->execute()) {
    $error = $insertStmt->error ?: 'Unable to save the death report.';
    $insertStmt->close();
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}
$insertStmt->close();

$lifeCertificate = isLifeCertificateExemptRecord('Deceased', $payType) ? 'Exempt' : 'Not Submitted';
$updateStmt = $conn->prepare("
    UPDATE tb_fileregistry
    SET livingStatus = 'Deceased',
        dateOfDeath = ?,
        deathNotificationDate = ?,
        deathNotifierName = ?,
        deathNotifierContact = ?,
        estateExpiryDate = ?,
        estateStatus = ?,
        lifeCertificate = ?
    WHERE id = ?
    LIMIT 1
");
if (!$updateStmt) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Unable to update the registry estate state.']);
    exit;
}
$updateStmt->bind_param(
    "sssssssi",
    $dateOfDeath,
    $notificationDate,
    $notifierName,
    $notifierContact,
    $estateExpiryDate,
    $estateStatus,
    $lifeCertificate,
    $registryId
);
if (!$updateStmt->execute()) {
    $error = $updateStmt->error ?: 'Unable to update the registry estate state.';
    $updateStmt->close();
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $error]);
    exit;
}
$updateStmt->close();

$conn->commit();

try {
    autoRebuildFullPensionArrears($conn, $regNo);
} catch (Throwable $arrearsError) {
    error_log('report_pensioner_death full pension arrears rebuild failed: ' . $arrearsError->getMessage());
}

echo json_encode([
    'success' => true,
    'message' => $estateStatus === '15 Years Elapsed'
        ? 'Death recorded. The 15-year earning cap had already elapsed for this pensioner record.'
        : 'Death recorded successfully. The estate remains within the 15-year earning window.',
    'record' => [
        'registryId' => $registryId,
        'regNo' => $regNo,
        'name' => formatTitleName((string)($record['title'] ?? ''), (string)($record['sName'] ?? ''), (string)($record['fName'] ?? '')),
        'dateOfDeath' => $dateOfDeath,
        'estateExpiryDate' => (string)$estateExpiryDate,
        'estateStatus' => $estateStatus,
        'deathWithinCap' => $estate['deathWithinCap']
    ]
]);

$conn->close();
