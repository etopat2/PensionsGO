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

$role = $_SESSION['userRole'] ?? '';
$allowedRoles = ['super_admin', 'admin', 'clerk', 'data_entry'];
if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$id = isset($payload['id']) ? (int)$payload['id'] : 0;
$status = isset($payload['status']) ? trim($payload['status']) : '';
$reason = isset($payload['reason']) ? trim($payload['reason']) : '';
$documents = isset($payload['documents']) && is_array($payload['documents']) ? $payload['documents'] : [];
$retirementType = trim((string)($payload['retirementType'] ?? ''));

if ($id <= 0 || $status === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Normalize status
$statusNormalized = strtolower($status);
if ($statusNormalized === 'queried') {
    $statusNormalized = 'querried';
}

$allowedStatuses = ['pending', 'verified', 'querried', 'rejected'];
if (!in_array($statusNormalized, $allowedStatuses, true)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported status']);
    exit;
}

if (($statusNormalized === 'querried' || $statusNormalized === 'rejected') && $reason === '') {
    echo json_encode(['success' => false, 'message' => 'Reason is required for queried or rejected status.']);
    exit;
}

ensureStaffDueWorkflowColumns($conn);
ensureAppnStatusTrackingColumns($conn);
ensureStaffDueSoftDeleteColumns($conn);
ensureStaffVerificationTables($conn);

$mandatoryDocuments = [
    'ap_pf7_ns3' => 'AP(PF7/NS3)', 'ns7' => 'NS7', 'psf18_20' => 'PSF18/20',
    'bank_statement' => 'Original Bank Statement', 'national_id' => 'National ID',
    'tin' => 'TIN', 'payslip' => 'Payslip', 'first_appointment_letter' => 'First Appointment Letter',
    'confirmation_letter' => 'Confirmation Letter', 'last_appointment_letter' => 'Last Appointment Letter'
];
$profileStmt = $conn->prepare("SELECT retirementType FROM tb_staffdue WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1");
$profileStmt->bind_param('i', $id);
$profileStmt->execute();
$profile = $profileStmt->get_result()->fetch_assoc();
$profileStmt->close();
if (!$profile) {
    echo json_encode(['success' => false, 'message' => 'Staff record was not found.']);
    exit;
}
if ($retirementType === '') $retirementType = (string)$profile['retirementType'];
$retirementType = normalizeBenefitsRetirementTypeKey($retirementType);
if (!isBenefitsRetirementTypeSupported($retirementType)) {
    echo json_encode(['success' => false, 'message' => 'Select a valid mode of retirement.']); exit;
}
$mode = strtolower($retirementType);
$requiredDocuments = $mandatoryDocuments;
if (strpos($mode, 'death') !== false) {
    $requiredDocuments += ['death_certificate' => 'Death Certificate', 'letters_of_administration' => 'Letters of Administration'];
} elseif (strpos($mode, 'mandatory') === false) {
    $requiredDocuments += ['discharge_certificate' => 'Discharge Certificate'];
}
if ($statusNormalized === 'verified') {
    $missing = [];
    foreach ($requiredDocuments as $code => $label) {
        if (empty($documents[$code])) $missing[] = $label;
    }
    if ($missing) {
        echo json_encode(['success' => false, 'message' => 'Verification cannot pass. Missing: ' . implode(', ', $missing)]);
        exit;
    }
}

$conn->begin_transaction();
foreach ($requiredDocuments as $code => $label) {
    $present = empty($documents[$code]) ? 0 : 1;
    $docStmt = $conn->prepare("INSERT INTO tb_staff_verification_documents (staffdue_id, document_code, document_label, is_present, verified_by, verified_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE document_label=VALUES(document_label), is_present=VALUES(is_present), verified_by=VALUES(verified_by), verified_at=NOW()");
    $docStmt->bind_param('issis', $id, $code, $label, $present, $_SESSION['userId']);
    $docStmt->execute();
    $docStmt->close();
}

$stmt = $conn->prepare("
    UPDATE tb_staffdue
    SET appnStatus = ?, retirementType = ?,
        appn_status_at = NOW(),
        appn_status_by = ?,
        appn_status_reason = ?
    WHERE id = ?
      AND COALESCE(is_deleted, 0) = 0
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param("ssssi", $statusNormalized, $retirementType, $_SESSION['userId'], $reason, $id);
$stmt->execute();
$updated = $stmt->affected_rows >= 0;
$stmt->close();

if ($statusNormalized === 'verified') {
    ensureApplicationQueueTable($conn);

    // Fetch regNo for queue record
    $regNo = null;
    $regStmt = $conn->prepare("SELECT regNo FROM tb_staffdue WHERE id = ? AND COALESCE(is_deleted, 0) = 0 LIMIT 1");
    if ($regStmt) {
        $regStmt->bind_param("i", $id);
        $regStmt->execute();
        $regResult = $regStmt->get_result();
        if ($row = $regResult->fetch_assoc()) {
            $regNo = $row['regNo'] ?? null;
        }
        $regStmt->close();
    }

    $queueStmt = $conn->prepare("
        INSERT INTO tb_application_queue (staffdue_id, regNo, current_stage, status, verified_by, verified_at)
        VALUES (?, ?, 'verified', 'verified', ?, NOW())
        ON DUPLICATE KEY UPDATE
            status = 'verified',
            current_stage = 'verified',
            verified_by = VALUES(verified_by),
            verified_at = VALUES(verified_at)
    ");
    if ($queueStmt) {
        $queueStmt->bind_param("iss", $id, $regNo, $_SESSION['userId']);
        $queueStmt->execute();
        $queueStmt->close();
    }

    if ($regNo) {
        updateAppnStatusStep($conn, $regNo, 'verification', 'Verified', $reason ?: null, $_SESSION['userId']);
    }
}

echo json_encode([
    'success' => $updated,
    'message' => $updated ? 'Application status updated.' : 'No changes applied.'
]);

if ($updated) {
    $conn->commit();
} else {
    $conn->rollback();
}

$conn->close();
?>
