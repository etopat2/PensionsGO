<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$input = publicChatJsonInput();
if (!is_array($input)) {
    $input = $_POST;
}

$sessionId = (int)($input['session_id'] ?? 0);
$token = publicChatClean($input['token'] ?? '', 128);
$message = publicChatCleanMessage($input['message'] ?? '', publicChatSettingInt($conn, 'public_chat_max_message_length', 2000, 250, 5000));
$asAgent = !empty($input['as_agent']);
$clientNonce = publicChatClean($input['client_nonce'] ?? '', 80);
if ($clientNonce !== '' && !preg_match('/^[a-zA-Z0-9._:-]{1,80}$/', $clientNonce)) {
    $clientNonce = '';
}

if ($sessionId <= 0 || $message === '') {
    publicChatJson(['success' => false, 'message' => 'Chat session and message are required.'], 400);
}

if ($asAgent) {
    publicChatRequireCapability($conn, 'can_accept_chat', 'You are not permitted to reply to public chats.');
    $actor = publicChatResolveActor($conn, $sessionId, '', true, false);
} else {
    publicChatRateLimit($conn, 'message', publicChatSettingInt($conn, 'public_chat_rate_limit_messages_per_5min', 20, 1, 120), 300);
    $actor = publicChatResolveActor($conn, $sessionId, $token, false, false);
}
$session = $actor['session'];
$senderType = (string)$actor['sender_type'];
$senderId = $actor['sender_id'];
$senderName = (string)$actor['sender_name'];

if (($session['status'] ?? '') === 'closed') {
    publicChatJson(['success' => false, 'message' => 'This chat has been closed.'], 409);
}

$messageId = publicChatInsertMessage($conn, $sessionId, $senderType, $senderId, $senderName, $message, 'text', $clientNonce);

if ($asAgent) {
    $firstStmt = $conn->prepare("UPDATE public_chat_sessions SET first_response_at = COALESCE(first_response_at, NOW()), status = IF(status IN ('waiting', 'assigned'), 'active', status) WHERE session_id = ?");
    if ($firstStmt) {
        $firstStmt->bind_param('i', $sessionId);
        $firstStmt->execute();
        $firstStmt->close();
    }
}

publicChatAudit($conn, $sessionId, 'Message sent', ['sender_type' => $senderType, 'message_id' => $messageId]);
$messages = publicChatFetchMessages($conn, $sessionId, $messageId - 1, $senderType);
$messages = publicChatAttachMessageFiles($conn, $messages, $asAgent, $asAgent ? null : $token);
publicChatJson([
    'success' => true,
    'message_id' => $messageId,
    'client_nonce' => $clientNonce,
    'message' => $messages[0] ?? null,
    'chatMessage' => $messages[0] ?? null
]);
?>
