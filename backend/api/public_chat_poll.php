<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$sessionId = (int)($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$lastId = (int)($_GET['last_id'] ?? $_POST['last_id'] ?? 0);
$token = publicChatClean($_GET['token'] ?? $_POST['token'] ?? '', 128);
$asAgent = !empty($_GET['as_agent']) || !empty($_POST['as_agent']);

if ($sessionId <= 0) {
    publicChatJson(['success' => false, 'message' => 'Chat session is required.'], 400);
}

if ($asAgent) {
    $agentId = publicChatRequireAgent($conn);
    $agentProfile = publicChatAgentProfile($conn, $agentId);
    $stmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        publicChatJson(['success' => false, 'message' => 'Chat session not found.'], 404);
    }
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, true, 'You are not permitted to view this chat.');
} else {
    $session = publicChatVerifyVisitorSession($conn, $sessionId, $token);
}

$stmt = $conn->prepare("
    SELECT message_id, sender_type, sender_id, sender_name, message_text, is_internal, created_at
    FROM public_chat_messages
    WHERE session_id = ? AND message_id > ? AND is_internal = 0
    ORDER BY message_id ASC
");
$stmt->bind_param('ii', $sessionId, $lastId);
$stmt->execute();
$messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$messages = publicChatAttachMessageFiles($conn, $messages, $asAgent, $asAgent ? null : $token);
$canReply = $asAgent
    ? publicChatAgentCanAccessSession($session, $agentId ?? '', $agentProfile ?? [], false) && ($session['status'] ?? '') !== 'closed'
    : false;

publicChatJson([
    'success' => true,
    'session' => [
        'session_id' => (int)$session['session_id'],
        'chat_reference' => (string)$session['chat_reference'],
        'status' => (string)$session['status'],
        'assigned_agent_name' => ''
    ],
    'canReply' => $canReply,
    'messages' => $messages,
    'typing' => publicChatTypingRows($conn, $sessionId, $asAgent ? 'visitor' : 'agent')
]);
?>
