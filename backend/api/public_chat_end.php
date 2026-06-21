<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sessionId = (int)($input['session_id'] ?? 0);
$token = publicChatClean($input['token'] ?? '', 128);
$reason = publicChatClean($input['reason'] ?? 'Ended by visitor', 255);
if ($sessionId <= 0) {
    publicChatJson(['success' => false, 'message' => 'Chat session is required.'], 400);
}

publicChatRateLimit($conn, 'end', 10, 300);
publicChatVerifyVisitorSession($conn, $sessionId, $token);
$stmt = $conn->prepare("UPDATE public_chat_sessions SET status = 'closed', closed_at = COALESCE(closed_at, NOW()), close_reason = ? WHERE session_id = ?");
if (!$stmt) {
    publicChatJson(['success' => false, 'message' => 'Unable to close chat.'], 500);
}
$stmt->bind_param('si', $reason, $sessionId);
$stmt->execute();
$stmt->close();
publicChatAudit($conn, $sessionId, 'Chat closed', ['reason' => $reason, 'closed_by' => 'visitor']);
publicChatJson(['success' => true]);
?>
