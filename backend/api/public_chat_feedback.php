<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$input = publicChatJsonInput();
if (!is_array($input)) {
    $input = $_POST;
}
$sessionId = (int)($input['session_id'] ?? 0);
$token = publicChatClean($input['token'] ?? '', 128);
$rating = (int)($input['rating'] ?? 0);
$comments = publicChatClean($input['comments'] ?? '', 2000);
if ($sessionId <= 0 || $rating < 1 || $rating > 5) {
    publicChatJson(['success' => false, 'message' => 'Chat session and rating are required.'], 400);
}
publicChatVerifyVisitorSession($conn, $sessionId, $token);
$stmt = $conn->prepare("
    INSERT INTO public_chat_feedback (session_id, rating, comments)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE rating = VALUES(rating), comments = VALUES(comments), created_at = NOW()
");
if (!$stmt) {
    publicChatJson(['success' => false, 'message' => 'Unable to prepare feedback submission.'], 500);
}
$stmt->bind_param('iis', $sessionId, $rating, $comments);
if (!$stmt->execute()) {
    $stmt->close();
    publicChatJson(['success' => false, 'message' => 'Unable to submit feedback.'], 500);
}
$stmt->close();
publicChatAudit($conn, $sessionId, 'Feedback received', ['rating' => $rating]);
publicChatJson(['success' => true, 'message' => 'Thank you. Your feedback has been recorded.']);
?>
