<?php
// backend/api/get_task_count.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureTasksTable($conn);

$userId = $_SESSION['userId'];
$userRole = $_SESSION['userRole'] ?? '';
$inboxRoles = getWorkflowRoleKeysForInbox($userRole);

if (empty($inboxRoles)) {
    $query = "
        SELECT COUNT(*) as taskCount
        FROM tb_tasks
        WHERE assigned_to = ?
          AND status IN ('pending','assigned','in_progress')
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
        exit;
    }
    $stmt->bind_param("s", $userId);
} else {
    $placeholders = implode(',', array_fill(0, count($inboxRoles), '?'));
    $query = "
        SELECT COUNT(*) as taskCount
        FROM tb_tasks
        WHERE (
            assigned_to = ?
            OR (assigned_to IS NULL AND assigned_role IN ($placeholders))
        )
        AND status IN ('pending','assigned','in_progress')
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
        exit;
    }

    $types = "s" . str_repeat("s", count($inboxRoles));
    $params = array_merge([$userId], $inboxRoles);
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
$count = 0;

if ($row = $result->fetch_assoc()) {
    $count = (int)$row['taskCount'];
}

echo json_encode([
    'success' => true,
    'taskCount' => $count
]);

$stmt->close();
$conn->close();
?>
