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
$session = $actor['session'];
$senderType = (string)$actor['sender_type'];
$senderId = $actor['sender_id'];
$senderName = (string)$actor['sender_name'];
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

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    publicChatJson(['success' => false, 'message' => 'Attachment upload is not valid.'], 400);
}
$nameParts = array_map('strtolower', array_filter(explode('.', $originalName)));
foreach ($nameParts as $part) {
    if (function_exists('getDangerousUploadExtensions') && in_array($part, getDangerousUploadExtensions(), true)) {
        publicChatJson(['success' => false, 'message' => 'Attachment type is not safe.'], 400);
    }
}

$detectedMime = 'application/octet-stream';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = (string)$finfo->file($tmpPath);
    if ($detected !== '') {
        $detectedMime = $detected;
    }
} elseif (function_exists('mime_content_type')) {
    $detected = (string)@mime_content_type($tmpPath);
    if ($detected !== '') {
        $detectedMime = $detected;
    }
}

$lowerMime = strtolower($detectedMime);
if ($extension === 'docx') {
    if (!class_exists('ZipArchive')) {
        publicChatJson(['success' => false, 'message' => 'DOCX preview support is not enabled on this server.'], 400);
    }
    $zip = new ZipArchive();
    $opened = $zip->open($tmpPath);
    if ($opened !== true || $zip->locateName('word/document.xml') === false || $zip->locateName('[Content_Types].xml') === false) {
        if ($opened === true) {
            $zip->close();
        }
        publicChatJson(['success' => false, 'message' => 'DOCX file is not a valid Word document.'], 400);
    }
    $zip->close();
} elseif ($extension === 'doc') {
    $handle = fopen($tmpPath, 'rb');
    $signature = $handle ? fread($handle, 8) : '';
    if ($handle) {
        fclose($handle);
    }
    if ($signature !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        publicChatJson(['success' => false, 'message' => 'DOC file is not a valid Word document.'], 400);
    }
} elseif (!$isVoice && in_array($extension, ['jpg', 'jpeg', 'png'], true) && !str_starts_with($lowerMime, 'image/')) {
    publicChatJson(['success' => false, 'message' => 'Image attachment content is not valid.'], 400);
} elseif (!$isVoice && $extension === 'pdf' && $lowerMime !== 'application/pdf') {
    publicChatJson(['success' => false, 'message' => 'PDF attachment content is not valid.'], 400);
} elseif ($isVoice && !str_starts_with($lowerMime, 'audio/') && !str_starts_with($lowerMime, 'video/') && $lowerMime !== 'application/octet-stream') {
    publicChatJson(['success' => false, 'message' => 'Voice note content is not valid.'], 400);
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
$detectedMime = publicChatClean($detectedMime, 120);
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
