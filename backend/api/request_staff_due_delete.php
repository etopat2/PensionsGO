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

if (!currentUserHasPermission($conn, 'staff_due.delete_request')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureStaffDueSoftDeleteColumns($conn);
ensureStaffDueDeleteQueueTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$staffId = (int)($payload['staffdue_id'] ?? 0);
$reason = trim((string)($payload['reason'] ?? ''));
if ($staffId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid staff due record']);
    exit;
}
if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Delete reason is required']);
    exit;
}
if (strlen($reason) > 1000) {
    $reason = substr($reason, 0, 1000);
}

$lookupStmt = $conn->prepare("
    SELECT id, regNo, title, sName, fName
    FROM tb_staffdue
    WHERE id = ?
      AND COALESCE(is_deleted, 0) = 0
    LIMIT 1
");
if (!$lookupStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare lookup query']);
    exit;
}
$lookupStmt->bind_param('i', $staffId);
$lookupStmt->execute();
$record = $lookupStmt->get_result()->fetch_assoc();
$lookupStmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Staff due record not found']);
    exit;
}

$pendingStmt = $conn->prepare("
    SELECT request_id
    FROM tb_staff_due_delete_requests
    WHERE staffdue_id = ?
      AND status = 'pending'
    LIMIT 1
");
if (!$pendingStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare pending query']);
    exit;
}
$pendingStmt->bind_param('i', $staffId);
$pendingStmt->execute();
$pending = $pendingStmt->get_result()->fetch_assoc();
$pendingStmt->close();

if ($pending) {
    echo json_encode(['success' => false, 'message' => 'A pending delete request already exists for this record']);
    exit;
}

$requestedBy = (string)($_SESSION['userId'] ?? '');
$requestedByName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown');
$requestedByRole = strtolower((string)($_SESSION['userRole'] ?? ''));
$regNo = (string)($record['regNo'] ?? '');
$staffName = trim(trim((string)($record['sName'] ?? '')) . ' ' . trim((string)($record['fName'] ?? '')));
$staffTitle = trim((string)($record['title'] ?? ''));

$insertStmt = $conn->prepare("
    INSERT INTO tb_staff_due_delete_requests (
        staffdue_id, regNo, staff_name, staff_title, requested_by, requested_by_name, requested_by_role, reason, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
if (!$insertStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete request']);
    exit;
}
$insertStmt->bind_param(
    'isssssss',
    $staffId,
    $regNo,
    $staffName,
    $staffTitle,
    $requestedBy,
    $requestedByName,
    $requestedByRole,
    $reason
);

if (!$insertStmt->execute()) {
    $error = $insertStmt->error;
    $insertStmt->close();
    echo json_encode(['success' => false, 'message' => $error ?: 'Failed to queue delete request']);
    exit;
}
$requestId = (int)$insertStmt->insert_id;
$insertStmt->close();

logAuditEvent($conn, [
    'actor_id' => $requestedBy,
    'actor_name' => $requestedByName,
    'actor_role' => $requestedByRole,
    'action' => 'staff_due_delete_requested',
    'entity_type' => 'tb_staffdue',
    'entity_id' => (string)$staffId,
    'details' => [
        'request_id' => $requestId,
        'regNo' => $regNo,
        'reason' => $reason
    ]
]);

echo json_encode([
    'success' => true,
    'message' => 'Delete request queued for approval'
]);
$conn->close();
?>
