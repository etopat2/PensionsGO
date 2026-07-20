<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/life_certificate_followup_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if (!currentUserHasPermission($conn, 'registry.life_certificate.submit')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureFileMovementTables($conn);
ensureLifeCertificateTables($conn);
ensureLifeCertificateFollowupTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$regNo = trim((string)($payload['regNo'] ?? ''));
$year = (int)($payload['year'] ?? date('Y'));
$notes = trim((string)($payload['notes'] ?? ''));

if ($regNo === '') {
    echo json_encode(['success' => false, 'message' => 'File number is required']);
    exit;
}
if ($year < 2000 || $year > 2100) {
    $year = (int)date('Y');
}

$recordStmt = $conn->prepare("
    SELECT regNo, payType, livingStatus
    FROM tb_fileregistry
    WHERE regNo = ?
    LIMIT 1
");
if (!$recordStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to verify registry record']);
    exit;
}
$recordStmt->bind_param("s", $regNo);
$recordStmt->execute();
$record = $recordStmt->get_result()->fetch_assoc();
$recordStmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Registry record not found']);
    exit;
}

if (isLifeCertificateExemptRecord($record['livingStatus'] ?? null, $record['payType'] ?? null)) {
    $updateExempt = $conn->prepare("UPDATE tb_fileregistry SET lifeCertificate = 'Exempt' WHERE regNo = ?");
    if ($updateExempt) {
        $updateExempt->bind_param("s", $regNo);
        $updateExempt->execute();
        $updateExempt->close();
    }
    echo json_encode([
        'success' => false,
        'message' => 'This beneficiary is exempt from yearly life certificate submission.'
    ]);
    exit;
}

$userId = $_SESSION['userId'];
$upsertStmt = $conn->prepare("
    INSERT INTO tb_life_certificate_submissions
    (regNo, submission_year, status, submitted_at, submitted_by, notes)
    VALUES (?, ?, 'Submitted', NOW(), ?, ?)
    ON DUPLICATE KEY UPDATE
        status = 'Submitted',
        submitted_at = NOW(),
        submitted_by = VALUES(submitted_by),
        notes = VALUES(notes),
        updated_at = NOW()
");
if (!$upsertStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to record life certificate submission']);
    exit;
}
$upsertStmt->bind_param("siss", $regNo, $year, $userId, $notes);
$ok = $upsertStmt->execute();
$upsertStmt->close();

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Unable to save life certificate submission']);
    exit;
}

if ($year === (int)date('Y')) {
    $updateCurrent = $conn->prepare("UPDATE tb_fileregistry SET lifeCertificate = 'Submitted' WHERE regNo = ?");
    if ($updateCurrent) {
        $updateCurrent->bind_param("s", $regNo);
        $updateCurrent->execute();
        $updateCurrent->close();
    }
}

$closeCase = $conn->prepare("
    UPDATE tb_life_certificate_followup_cases
    SET status = 'Complied',
        suspension_status = CASE WHEN suspension_status IN ('Submitted','Suspended') THEN 'Reinstated' ELSE suspension_status END,
        closed_at = NOW()
    WHERE reg_no = ? AND compliance_year = ?
");
if ($closeCase) {
    $closeCase->bind_param('si', $regNo, $year);
    $closeCase->execute();
    $closeCase->close();
}

$actor = lifeCertificateFollowupActor();
logAuditEvent($conn, [
    'actor_id' => $actor['id'], 'actor_name' => $actor['name'], 'actor_role' => $actor['role'],
    'action' => 'life_certificate_submission_recorded', 'entity_type' => 'life_certificate',
    'entity_id' => $regNo . ':' . $year,
    'details' => ['reg_no' => $regNo, 'year' => $year, 'notes' => $notes]
]);

echo json_encode([
    'success' => true,
    'message' => 'Life certificate marked as submitted.'
]);

$conn->close();
?>
