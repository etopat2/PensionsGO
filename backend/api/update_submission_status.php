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

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid staff record']);
    exit;
}

ensureStaffDueWorkflowColumns($conn);
ensureAppnStatusTrackingColumns($conn);
ensureStaffDueSoftDeleteColumns($conn);
if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}

$conn->begin_transaction();
try {
    $recordStmt = $conn->prepare("
        SELECT regNo, retirementType, livingStatus, next_of_kin, next_of_kin_contact
        FROM tb_staffdue
        WHERE id = ? AND COALESCE(is_deleted, 0) = 0
        LIMIT 1
    ");
    if (!$recordStmt) {
        throw new RuntimeException('Unable to validate the submission record.');
    }
    $recordStmt->bind_param("i", $id);
    $recordStmt->execute();
    $record = $recordStmt->get_result()->fetch_assoc();
    $recordStmt->close();

    if (!$record) {
        throw new RuntimeException('Staff record not found.');
    }

    $retirementType = normalizeBenefitsRetirementTypeKey((string)($record['retirementType'] ?? ''));
    $nextOfKin = trim((string)($record['next_of_kin'] ?? ''));
    $nextOfKinContact = trim((string)($record['next_of_kin_contact'] ?? ''));
    $isDeceased=$retirementType==='death'||strtolower(trim((string)($record['livingStatus']??'')))==='deceased';
    if($isDeceased){ensurePensionBeneficiaryTables($conn);$beneficiaryStmt=$conn->prepare('SELECT beneficiary_id FROM tb_pension_beneficiaries WHERE deceased_staffdue_id=? AND is_active=1 AND TRIM(first_name)<>\'\' AND TRIM(last_name)<>\'\' AND TRIM(beneficiary_nin)<>\'\' LIMIT 1');$beneficiaryStmt->bind_param('i',$id);$beneficiaryStmt->execute();$hasBeneficiary=(bool)$beneficiaryStmt->get_result()->fetch_assoc();$beneficiaryStmt->close();}else{$hasBeneficiary=true;}
    if ($isDeceased && !$hasBeneficiary && ($nextOfKin === '' || $nextOfKinContact === '')) {
        throw new RuntimeException('A deceased officer requires complete linked beneficiary or next-of-kin details before processing can start.');
    }
    if ($nextOfKinContact !== '' && normalizePhoneNumber($nextOfKinContact) === null) {
        throw new RuntimeException('Next of kin contact must be a valid phone number before submission.');
    }

    $stmt = $conn->prepare("
        UPDATE tb_staffdue
        SET submissionStatus = 'submitted',
            submission_at = NOW(),
            submission_by = ?
        WHERE id = ? AND submissionStatus = 'pending' AND COALESCE(is_deleted, 0) = 0
    ");
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare update');
    }

    $stmt->bind_param("si", $_SESSION['userId'], $id);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    if ($updated) {
        $regNo = $record['regNo'] ?? null;
        if ($regNo) {
            updateAppnStatusStep($conn, $regNo, 'verification', 'Pending', 'Submitted for verification', $_SESSION['userId']);
        }

        $accountResult = upsertPensionerUserFromStaffDue($conn, $id, 'Pensioner123', $_SESSION['userId'] ?? null);
        if (empty($accountResult['success'])) {
            throw new RuntimeException($accountResult['message'] ?? 'Failed to create pensioner account');
        }
    }

    $conn->commit();
    echo json_encode([
        'success' => $updated,
        'message' => $updated ? 'Submission status updated and pensioner account synchronized.' : 'No changes applied.'
    ]);
} catch (Throwable $error) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ]);
}

$conn->close();
?>
