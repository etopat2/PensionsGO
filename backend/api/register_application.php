<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
  echo json_encode(['success' => false, 'message' => 'Authentication required']);
  exit;
}

if (!sessionRoleIn($conn, ['admin', 'clerk', 'data_entry'])) {
  echo json_encode(['success' => false, 'message' => 'Access denied']);
  exit;
}

$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Invalid ID']);
  exit;
}

if (function_exists('ensureStaffDueWorkflowColumns')) {
  ensureStaffDueWorkflowColumns($conn);
}
if (function_exists('ensureAppnStatusTrackingColumns')) {
  ensureAppnStatusTrackingColumns($conn);
}
if (function_exists('ensureStaffDueExtendedColumns')) {
  ensureStaffDueExtendedColumns($conn);
}

$conn->begin_transaction();

try {
  $recordStmt = $conn->prepare("
    SELECT regNo, retirementType, next_of_kin, next_of_kin_contact
    FROM tb_staffdue
    WHERE id = ?
    LIMIT 1
  ");
  if (!$recordStmt) {
    throw new Exception('Unable to validate the application record.');
  }
  $recordStmt->bind_param('i', $id);
  $recordStmt->execute();
  $record = $recordStmt->get_result()->fetch_assoc();
  $recordStmt->close();

  if (!$record) {
    throw new Exception('Application record not found.');
  }

  $retirementType = normalizeBenefitsRetirementTypeKey((string)($record['retirementType'] ?? ''));
  $nextOfKin = trim((string)($record['next_of_kin'] ?? ''));
  $nextOfKinContact = trim((string)($record['next_of_kin_contact'] ?? ''));
  if ($retirementType === 'death' && ($nextOfKin === '' || $nextOfKinContact === '')) {
    throw new Exception('Death retirements require next of kin name and contact before submission. Edit the record and complete Contact & Bank first.');
  }
  if ($nextOfKinContact !== '' && normalizePhoneNumber($nextOfKinContact) === null) {
    throw new Exception('Next of kin contact must be a valid phone number before submission.');
  }

  // Update submissionStatus
  $stmt1 = $conn->prepare("
    UPDATE tb_staffdue
    SET submissionStatus = 'submitted',
        submission_at = NOW(),
        submission_by = ?
    WHERE id = ?
  ");
  $userId = $_SESSION['userId'] ?? null;
  $stmt1->bind_param('si', $userId, $id);
  $stmt1->execute();

  // Insert into tb_appnsubmissions
  $stmt2 = $conn->prepare("INSERT INTO tb_appnsubmissions (Id, submissionDate, comment) VALUES (?, NOW(), '')");
  $stmt2->bind_param('i', $id);
  $stmt2->execute();

  $regNo = $record['regNo'] ?? null;

  if ($regNo) {
    updateAppnStatusStep($conn, $regNo, 'verification', 'Pending', 'Submitted for verification', $_SESSION['userId'] ?? null);
  }

  $accountResult = upsertPensionerUserFromStaffDue($conn, $id, 'Pensioner123', $_SESSION['userId'] ?? null);
  if (empty($accountResult['success'])) {
    throw new Exception($accountResult['message'] ?? 'Failed to create pensioner account');
  }

  $conn->commit();
  echo json_encode(['success' => true, 'message' => 'Application registered successfully. Pensioner account synchronized.']);
} catch (Exception $e) {
  $conn->rollback();
  echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
$conn->close();
?>
