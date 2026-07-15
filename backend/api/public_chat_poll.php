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
    $actor = publicChatResolveActor($conn, $sessionId, '', true, true, true);
    $agentId = (string)$actor['sender_id'];
    $agentProfile = $actor['profile'];
} else {
    $actor = publicChatResolveActor($conn, $sessionId, $token, false, false);
    $agentId = '';
    $agentProfile = [];
}
publicChatReleaseSessionLock();
$session = $actor['session'];
$viewerType = $asAgent ? 'agent' : 'visitor';

publicChatMarkSeen($conn, $sessionId, $viewerType);
$messages = publicChatFetchMessages($conn, $sessionId, $lastId, $viewerType);
$messages = publicChatAttachMessageFiles($conn, $messages, $asAgent, $asAgent ? null : $token);
$canReply = $asAgent
    ? publicChatAgentCanAccessSession($session, $agentId, $agentProfile, false) && ($session['status'] ?? '') !== 'closed'
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
    'receipts' => publicChatReceiptRows($conn, $sessionId, $viewerType),
    'typing' => publicChatTypingRows($conn, $sessionId, $asAgent ? 'visitor' : 'agent')
]);
?>
