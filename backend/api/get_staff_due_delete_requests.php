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

if (!currentUserHasPermission($conn, 'staff_due.delete_queue.process')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureStaffDueDeleteQueueTable($conn);
ensureStaffDueSoftDeleteColumns($conn);

$status = strtolower(trim((string)($_GET['status'] ?? 'pending')));
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
    $status = 'pending';
}

$sql = "
    SELECT
        r.request_id,
        r.staffdue_id,
        r.regNo,
        r.staff_name,
        r.staff_title,
        r.requested_by,
        r.requested_by_name,
        r.requested_by_role,
        r.reason,
        r.status,
        r.processed_by,
        r.processed_by_name,
        r.processed_by_role,
        r.processed_note,
        r.created_at,
        r.processed_at,
        COALESCE(s.is_deleted, 0) AS is_deleted
    FROM tb_staff_due_delete_requests r
    LEFT JOIN tb_staffdue s ON s.id = r.staffdue_id
";

$params = [];
$types = '';
if ($status !== 'all') {
    $sql .= " WHERE r.status = ? ";
    $params[] = $status;
    $types .= 's';
}

$sql .= " ORDER BY CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END, r.created_at DESC, r.request_id DESC";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load delete requests']);
    exit;
}

if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = [
        'request_id' => (int)($row['request_id'] ?? 0),
        'staffdue_id' => (int)($row['staffdue_id'] ?? 0),
        'regNo' => (string)($row['regNo'] ?? ''),
        'staff_name' => (string)($row['staff_name'] ?? ''),
        'staff_title' => (string)($row['staff_title'] ?? ''),
        'requested_by_name' => (string)($row['requested_by_name'] ?? ''),
        'requested_by_role' => (string)($row['requested_by_role'] ?? ''),
        'reason' => (string)($row['reason'] ?? ''),
        'status' => (string)($row['status'] ?? 'pending'),
        'processed_by_name' => (string)($row['processed_by_name'] ?? ''),
        'processed_by_role' => (string)($row['processed_by_role'] ?? ''),
        'processed_note' => (string)($row['processed_note'] ?? ''),
        'created_at' => (string)($row['created_at'] ?? ''),
        'processed_at' => (string)($row['processed_at'] ?? ''),
        'is_deleted' => ((int)($row['is_deleted'] ?? 0)) === 1
    ];
}
$stmt->close();

echo json_encode([
    'success' => true,
    'records' => $records,
    'pending_count' => count(array_filter($records, static fn($item) => ($item['status'] ?? '') === 'pending'))
]);
$conn->close();
?>
