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

ensureApplicationQueueTable($conn);
ensureStaffDueSoftDeleteColumns($conn);

$stmt = $conn->prepare("
    SELECT 
        q.queue_id,
        q.staffdue_id,
        q.regNo,
        q.current_stage,
        q.status,
        q.verified_by,
        q.verified_at,
        q.submitted_by,
        q.submitted_at,
        q.updated_at,
        s.title,
        s.sName,
        s.fName,
        s.prisonUnit,
        s.retirementDate,
        s.retirementType
    FROM tb_application_queue q
    LEFT JOIN tb_staffdue s ON s.id = q.staffdue_id
    WHERE q.status = 'verified'
      AND COALESCE(s.is_deleted, 0) = 0
      AND (q.verified_by = ? OR q.submitted_by = ?)
    ORDER BY q.updated_at DESC
");

if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->bind_param("ss", $_SESSION['userId'], $_SESSION['userId']);
$stmt->execute();
$result = $stmt->get_result();
$records = [];

while ($row = $result->fetch_assoc()) {
    $records[] = [
        'queue_id' => (int)$row['queue_id'],
        'staffdue_id' => (int)$row['staffdue_id'],
        'regNo' => $row['regNo'],
        'current_stage' => $row['current_stage'],
        'status' => $row['status'],
        'verified_by' => $row['verified_by'],
        'verified_at' => $row['verified_at'],
        'submitted_by' => $row['submitted_by'],
        'submitted_at' => $row['submitted_at'],
        'updated_at' => $row['updated_at'],
        'title' => $row['title'],
        'name' => trim(($row['sName'] ?? '') . ' ' . ($row['fName'] ?? '')),
        'prisonUnit' => $row['prisonUnit'],
        'retirementDate' => $row['retirementDate'],
        'retirementType' => getBenefitsRetirementTypeLabel((string)($row['retirementType'] ?? ''))
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'records' => $records]);
$conn->close();
?>
