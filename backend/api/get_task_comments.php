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

ensureTaskCommentsTable($conn);

$taskId = isset($_GET['taskId']) ? (int)$_GET['taskId'] : 0;
if ($taskId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid task']);
    exit;
}

$stmt = $conn->prepare("
    SELECT comment_id, task_id, author_id, author_name, author_role, comment, created_at
    FROM tb_task_comments
    WHERE task_id = ?
    ORDER BY created_at ASC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->bind_param("i", $taskId);
$stmt->execute();
$result = $stmt->get_result();
$comments = [];

while ($row = $result->fetch_assoc()) {
    $comments[] = [
        'comment_id' => (int)$row['comment_id'],
        'task_id' => (int)$row['task_id'],
        'author_id' => $row['author_id'],
        'author_name' => $row['author_name'],
        'author_role' => $row['author_role'],
        'comment' => $row['comment'],
        'created_at' => $row['created_at']
    ];
}

$stmt->close();

echo json_encode(['success' => true, 'comments' => $comments]);
$conn->close();
?>
