<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sessionId = (int)($input['session_id'] ?? 0);
$token = publicChatClean($input['token'] ?? '', 128);
$message = publicChatClean($input['message'] ?? '', publicChatSettingInt($conn, 'public_chat_max_message_length', 2000, 250, 5000));
$asAgent = !empty($input['as_agent']);

if ($sessionId <= 0 || $message === '') {
    publicChatJson(['success' => false, 'message' => 'Chat session and message are required.'], 400);
}

if ($asAgent) {
    $agentId = publicChatRequireAgent($conn);
    $agentProfile = publicChatAgentProfile($conn, $agentId);
    publicChatRequireCapability($conn, 'can_accept_chat', 'You are not permitted to reply to public chats.');
    $senderType = 'agent';
    $senderName = (string)($_SESSION['userName'] ?? 'Chat Agent');
    $sessionStmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    $sessionStmt->bind_param('i', $sessionId);
    $sessionStmt->execute();
    $session = $sessionStmt->get_result()->fetch_assoc();
    $sessionStmt->close();
    if (!$session) {
        publicChatJson(['success' => false, 'message' => 'Chat session not found.'], 404);
    }
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Accept this chat before replying, or select a chat assigned to you.');
} else {
    publicChatRateLimit($conn, 'message', publicChatSettingInt($conn, 'public_chat_rate_limit_messages_per_5min', 20, 1, 120), 300);
    $session = publicChatVerifyVisitorSession($conn, $sessionId, $token);
    $agentId = null;
    $senderType = 'visitor';
    $senderName = (string)($session['visitor_name'] ?? 'Visitor');
}

if (($session['status'] ?? '') === 'closed') {
    publicChatJson(['success' => false, 'message' => 'This chat has been closed.'], 409);
}

$stmt = $conn->prepare("
    INSERT INTO public_chat_messages (session_id, sender_type, sender_id, sender_name, message_text)
    VALUES (?, ?, ?, ?, ?)
");
if (!$stmt) {
    publicChatJson(['success' => false, 'message' => 'Unable to save message.'], 500);
}
$stmt->bind_param('issss', $sessionId, $senderType, $agentId, $senderName, $message);
$stmt->execute();
$messageId = (int)$stmt->insert_id;
$stmt->close();

if ($asAgent) {
    $firstStmt = $conn->prepare("UPDATE public_chat_sessions SET first_response_at = COALESCE(first_response_at, NOW()), status = IF(status IN ('waiting', 'assigned'), 'active', status) WHERE session_id = ?");
    if ($firstStmt) {
        $firstStmt->bind_param('i', $sessionId);
        $firstStmt->execute();
        $firstStmt->close();
    }
}

publicChatAudit($conn, $sessionId, 'Message sent', ['sender_type' => $senderType, 'message_id' => $messageId]);
publicChatJson(['success' => true, 'message_id' => $messageId]);
?>
