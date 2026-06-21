<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);
$uploadKind = publicChatClean($_POST['kind'] ?? 'attachment', 30);
$isVoice = $uploadKind === 'voice';
if (!$isVoice && !publicChatSettingBool($conn, 'public_chat_attachments_enabled', false)) {
    publicChatJson(['success' => false, 'message' => 'Public chat attachments are currently disabled.'], 403);
}

$sessionId = (int)($_POST['session_id'] ?? 0);
$token = publicChatClean($_POST['token'] ?? '', 128);
$asAgent = !empty($_POST['as_agent']);
if ($sessionId <= 0 || empty($_FILES['attachment'])) {
    publicChatJson(['success' => false, 'message' => 'Chat session and attachment are required.'], 400);
}

publicChatRateLimit($conn, 'upload', 6, 600);
if ($asAgent) {
    $agentId = publicChatRequireAgent($conn);
    $agentProfile = publicChatAgentProfile($conn, $agentId);
    publicChatRequireCapability($conn, 'can_accept_chat', 'You are not permitted to upload public chat attachments.');
    $sessionStmt = $conn->prepare("SELECT * FROM public_chat_sessions WHERE session_id = ? LIMIT 1");
    $sessionStmt->bind_param('i', $sessionId);
    $sessionStmt->execute();
    $session = $sessionStmt->get_result()->fetch_assoc();
    $sessionStmt->close();
    if (!$session) {
        publicChatJson(['success' => false, 'message' => 'Chat session not found.'], 404);
    }
    publicChatRequireAgentSessionAccess($session, $agentId, $agentProfile, false, 'Accept this chat before uploading, or select a chat assigned to you.');
    $senderType = 'agent';
    $senderId = (string)$agentId;
    $senderName = (string)($_SESSION['userName'] ?? 'Chat Agent');
} else {
    $session = publicChatVerifyVisitorSession($conn, $sessionId, $token);
    $agentId = null;
    $senderType = 'visitor';
    $senderId = null;
    $senderName = (string)($session['visitor_name'] ?? 'Visitor');
}
if (($session['status'] ?? '') === 'closed') {
    publicChatJson(['success' => false, 'message' => 'This chat has been closed.'], 409);
}

$file = $_FILES['attachment'];
if (!empty($file['error'])) {
    publicChatJson(['success' => false, 'message' => 'Attachment upload failed.'], 400);
}

$maxBytes = publicChatSettingInt($conn, 'public_chat_max_attachment_size_mb', 5, 1, 25) * 1024 * 1024;
if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > $maxBytes) {
    publicChatJson(['success' => false, 'message' => 'Attachment must be 5 MB or smaller.'], 400);
}

$originalName = basename((string)($file['name'] ?? 'attachment'));
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedRaw = getAppSettingString($conn, 'public_chat_allowed_attachment_types', 'pdf,jpg,jpeg,png,doc,docx');
$allowed = array_values(array_filter(array_map(static fn($item) => strtolower(trim($item)), explode(',', $allowedRaw))));
$voiceAllowed = ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'mp4'];
if ($isVoice) {
    $allowed = array_values(array_unique(array_merge($allowed, $voiceAllowed)));
}
if (!in_array($extension, $allowed, true)) {
    publicChatJson(['success' => false, 'message' => 'Attachment type is not allowed.'], 400);
}

$safeInfo = null;
if (function_exists('assertUploadedFileIsSafe')) {
    try {
        $mimePrefixes = $isVoice
            ? ['audio/', 'video/', 'application/octet-stream']
            : ['image/', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument'];
        $safeInfo = assertUploadedFileIsSafe($conn, $file, $allowed, $mimePrefixes);
    } catch (Throwable $e) {
        publicChatJson(['success' => false, 'message' => $e->getMessage()], 400);
    }
}

$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'public_chat';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$safeName = ($isVoice ? 'public_chat_voice_' : 'public_chat_') . $sessionId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
$target = $uploadDir . DIRECTORY_SEPARATOR . $safeName;
if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
    publicChatJson(['success' => false, 'message' => 'Unable to store attachment.'], 500);
}

$relativePath = 'uploads/public_chat/' . $safeName;
$clientMime = publicChatClean((string)($file['type'] ?? ''), 120);
$detectedMime = publicChatClean((string)($safeInfo['mime_type'] ?? ''), 120);
$mime = $isVoice && preg_match('/^(audio|video)\//i', $clientMime)
    ? $clientMime
    : ($detectedMime !== '' && stripos($detectedMime, 'octet-stream') === false ? $detectedMime : ($clientMime ?: $detectedMime));
if ($isVoice) {
    $mime = publicChatPlaybackMime($mime, $originalName);
}
$size = (int)$file['size'];
$messageKind = $isVoice ? 'voice' : 'attachment';
$messageText = $isVoice ? 'Voice note' : 'Attachment uploaded';

$conn->begin_transaction();
try {
    $msgStmt = $conn->prepare("
        INSERT INTO public_chat_messages (session_id, sender_type, sender_id, sender_name, message_text, message_kind)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if ($msgStmt) {
        $msgStmt->bind_param('isssss', $sessionId, $senderType, $senderId, $senderName, $messageText, $messageKind);
    } else {
        $msgStmt = $conn->prepare("
            INSERT INTO public_chat_messages (session_id, sender_type, sender_id, sender_name, message_text)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$msgStmt) {
            throw new RuntimeException('Unable to prepare message insert.');
        }
        $msgStmt->bind_param('issss', $sessionId, $senderType, $senderId, $senderName, $messageText);
    }
    $msgStmt->execute();
    $messageId = (int)$msgStmt->insert_id;
    $msgStmt->close();

    $attStmt = $conn->prepare("
        INSERT INTO public_chat_attachments (session_id, message_id, uploaded_by_type, uploaded_by, file_name, file_path, file_size, mime_type)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $attStmt->bind_param('iissssis', $sessionId, $messageId, $senderType, $senderId, $originalName, $relativePath, $size, $mime);
    $attStmt->execute();
    $attachmentId = (int)$attStmt->insert_id;
    $attStmt->close();

    publicChatAudit($conn, $sessionId, 'Message sent', ['sender_type' => $senderType, 'attachment_id' => $attachmentId]);
    if ($asAgent) {
        $firstStmt = $conn->prepare("UPDATE public_chat_sessions SET first_response_at = COALESCE(first_response_at, NOW()), status = IF(status IN ('waiting', 'assigned'), 'active', status) WHERE session_id = ?");
        if ($firstStmt) {
            $firstStmt->bind_param('i', $sessionId);
            $firstStmt->execute();
            $firstStmt->close();
        }
    }
    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($target);
    publicChatJson(['success' => false, 'message' => 'Unable to save attachment.'], 500);
}

$message = [[
    'message_id' => $messageId,
    'sender_type' => $senderType,
    'sender_id' => $senderId,
    'sender_name' => $senderName,
    'message_text' => $messageText,
    'message_kind' => $messageKind,
    'is_internal' => 0,
    'created_at' => date('Y-m-d H:i:s')
]];
$message = publicChatAttachMessageFiles($conn, $message, $asAgent, $asAgent ? null : $token);
publicChatJson(['success' => true, 'attachment_id' => $attachmentId, 'message_id' => $messageId, 'message' => $message[0] ?? null]);
?>
