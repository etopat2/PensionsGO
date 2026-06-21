<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$sessionId = (int)($input['session_id'] ?? 0);
$token = publicChatClean($input['token'] ?? '', 128);
$rating = max(1, min(5, (int)($input['rating'] ?? 0)));
$comments = publicChatClean($input['comments'] ?? '', 2000);
if ($sessionId <= 0 || $rating <= 0) {
    publicChatJson(['success' => false, 'message' => 'Chat session and rating are required.'], 400);
}
publicChatVerifyVisitorSession($conn, $sessionId, $token);
$stmt = $conn->prepare("
    INSERT INTO public_chat_feedback (session_id, rating, comments)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), comments = VALUES(comments), created_at = NOW()
");
$stmt->bind_param('iis', $sessionId, $rating, $comments);
$stmt->execute();
$stmt->close();
publicChatAudit($conn, $sessionId, 'Feedback received', ['rating' => $rating]);
publicChatJson(['success' => true]);
?>
