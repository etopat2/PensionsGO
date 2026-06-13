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

$stmt = $conn->prepare("
    UPDATE tb_staffdue
    SET appnStatus = ?,
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

$stmt->bind_param("sssi", $statusNormalized, $_SESSION['userId'], $reason, $id);
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

$conn->close();
?>
