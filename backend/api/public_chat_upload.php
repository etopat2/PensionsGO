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

if ($asAgent) {
    publicChatRequireCapability($conn, 'can_accept_chat', 'You are not permitted to upload public chat attachments.');
    $actor = publicChatResolveActor($conn, $sessionId, '', true, false);
} else {
    publicChatRateLimit($conn, 'upload', 6, 600);
    $actor = publicChatResolveActor($conn, $sessionId, $token, false, false);
}
publicChatReleaseSessionLock();
$session = $actor['session'];
$senderType = (string)$actor['sender_type'];
$senderId = $actor['sender_id'];
$senderName = (string)$actor['sender_name'];
if (($session['status'] ?? '') === 'closed') {
    publicChatJson(['success' => false, 'message' => 'This chat has been closed.'], 409);
}

$upload = null;
try {
    $upload = publicChatStoreUpload($conn, $_FILES['attachment'], $uploadKind, $sessionId, $senderType, $senderId, $senderName);
} catch (Throwable $e) {
    $message = $e->getMessage() ?: 'Unable to store attachment.';
    $status = preg_match('/store|storage|prepare upload/i', $message) ? 500 : 400;
    publicChatJson(['success' => false, 'message' => $message], $status);
}

$originalName = (string)$upload['file_name'];
$relativePath = (string)$upload['file_path'];
$mime = (string)$upload['mime_type'];
$size = (int)$upload['file_size'];
$messageKind = $isVoice ? 'voice' : 'attachment';
$messageText = $isVoice ? 'Voice note' : 'Attachment uploaded';

$conn->begin_transaction();
try {
    $messageId = publicChatInsertMessage($conn, $sessionId, $senderType, $senderId, $senderName, $messageText, $messageKind);

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
    if (!empty($upload['absolute_path'])) {
        @unlink((string)$upload['absolute_path']);
    }
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
    'created_at' => date('Y-m-d H:i:s'),
    'delivered_at' => null,
    'is_read' => false,
    'read_at' => null,
    'receiptStatus' => 'sent',
    'isOwn' => true
]];
$message = publicChatAttachMessageFiles($conn, $message, $asAgent, $asAgent ? null : $token);
publicChatJson(['success' => true, 'attachment_id' => $attachmentId, 'message_id' => $messageId, 'message' => $message[0] ?? null]);
?>
