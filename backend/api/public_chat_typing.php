<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$sessionId = (int)($input['session_id'] ?? 0);
$token = publicChatClean($input['token'] ?? '', 128);
$asAgent = !empty($input['as_agent']);
$typing = filter_var($input['typing'] ?? true, FILTER_VALIDATE_BOOLEAN);

if ($sessionId <= 0) {
    publicChatJson(['success' => false, 'message' => 'Chat session is required.'], 400);
}

if ($asAgent) {
    $agentId = publicChatRequireAgent($conn);
    $agentProfile = publicChatAgentProfile($conn, $agentId);
    $actorType = 'agent';
    $actorId = (string)$agentId;
    $actorName = publicChatClean((string)($_SESSION['userName'] ?? 'Chat Agent'), 160);
    $stmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        publicChatJson(['success' => false, 'message' => 'Chat session not found.'], 404);
    }
    publicChatRequireAgentSessionAccess($exists, $agentId, $agentProfile, false, 'Accept this chat before typing a reply.');
} else {
    $session = publicChatVerifyVisitorSession($conn, $sessionId, $token);
    $actorType = 'visitor';
    $actorId = publicChatSessionToken($sessionId, (string)$session['chat_reference']);
    $actorName = publicChatClean((string)($session['visitor_name'] ?? 'Visitor'), 160);
}

if (!$typing) {
    $stmt = $conn->prepare("DELETE FROM public_chat_typing WHERE session_id = ? AND actor_type = ? AND actor_id = ?");
    $stmt->bind_param('iss', $sessionId, $actorType, $actorId);
    $stmt->execute();
    $stmt->close();
    publicChatJson(['success' => true]);
}

$stmt = $conn->prepare("
    INSERT INTO public_chat_typing (session_id, actor_type, actor_id, actor_name)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE actor_name = VALUES(actor_name), updated_at = NOW()
");
$stmt->bind_param('isss', $sessionId, $actorType, $actorId, $actorName);
$stmt->execute();
$stmt->close();

publicChatJson(['success' => true]);
?>
