<?php
require_once __DIR__ . '/live_chat_common.php';

function liveChatStoreUpload(array $file, string $prefix): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }

    $uploadDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $chatDir = $uploadDir . DIRECTORY_SEPARATOR . 'live_chat';
    if (!is_dir($chatDir) && !mkdir($chatDir, 0775, true)) {
        throw new RuntimeException('Unable to prepare live chat upload storage.');
    }

    $original = basename((string)$file['name']);
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $original) ?: 'upload.bin';
    $stored = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
    $target = $chatDir . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        throw new RuntimeException('Unable to store uploaded file.');
    }

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $target) ?: $mime;
            finfo_close($finfo);
        }
    }

    return [
        'file_name' => $original,
        'file_path' => 'uploads/live_chat/' . $stored,
        'file_size' => filesize($target) ?: (int)$file['size'],
        'mime_type' => $mime
    ];
}

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $recipientId = trim((string)($_POST['recipient_id'] ?? ''));
    $recipientType = trim((string)($_POST['recipient_type'] ?? 'user'));
    $kind = trim((string)($_POST['message_kind'] ?? 'text'));
    $text = trim((string)($_POST['message_text'] ?? ''));
    $replyToMessageId = max(0, (int)($_POST['reply_to_message_id'] ?? 0));

    if ($recipientType === 'group') {
        if ($recipientId === '' || !liveChatCanAccessGroup($conn, $recipientId, $userId)) {
            throw new RuntimeException('Select a valid group.');
        }
    } elseif ($recipientId === '' || $recipientId === $userId || !liveChatCanReachUser($conn, $recipientId)) {
        throw new RuntimeException('Select a valid staff recipient.');
    }

    if (!in_array($kind, ['text', 'voice', 'attachment'], true)) {
        $kind = 'text';
    }

    $upload = null;
    if (!empty($_FILES['file']) && in_array($kind, ['voice', 'attachment'], true)) {
        $upload = liveChatStoreUpload($_FILES['file'], $kind);
    }

    if ($text === '' && !$upload) {
        throw new RuntimeException('Enter a message or attach a file.');
    }

    $fileName = $upload['file_name'] ?? null;
    $filePath = $upload['file_path'] ?? null;
    $fileSize = isset($upload['file_size']) ? (int)$upload['file_size'] : 0;
    $mimeType = $upload['mime_type'] ?? null;

    $stmt = $conn->prepare("
        INSERT INTO tb_live_chat_messages
        (sender_id, recipient_id, message_kind, message_text, file_name, file_path, file_size, mime_type, reply_to_message_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $replyTo = $replyToMessageId > 0 ? $replyToMessageId : null;
    $stmt->bind_param('ssssssisi', $userId, $recipientId, $kind, $text, $fileName, $filePath, $fileSize, $mimeType, $replyTo);
    $stmt->execute();
    $messageId = (int)$conn->insert_id;
    $stmt->close();

    liveChatRespond([
        'success' => true,
        'message_id' => $messageId,
        'message' => 'Chat message sent.'
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}
