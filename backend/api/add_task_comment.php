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

$payload = json_decode(file_get_contents('php://input'), true);
$taskId = isset($payload['taskId']) ? (int)$payload['taskId'] : 0;
$comment = isset($payload['comment']) ? trim($payload['comment']) : '';

if ($taskId <= 0 || $comment === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$authorId = $_SESSION['userId'];
$authorName = $_SESSION['userName'] ?? 'User';
$authorRole = $_SESSION['userRole'] ?? 'user';

$stmt = $conn->prepare("
    INSERT INTO tb_task_comments (task_id, author_id, author_name, author_role, comment)
    VALUES (?, ?, ?, ?, ?)
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("issss", $taskId, $authorId, $authorName, $authorRole, $comment);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Comment added.']);
$conn->close();
?>
