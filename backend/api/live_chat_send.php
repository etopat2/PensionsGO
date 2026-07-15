<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);
    liveChatReleaseSessionLock();

    $recipientId = trim((string)($_POST['recipient_id'] ?? ''));
    $recipientType = trim((string)($_POST['recipient_type'] ?? 'user'));
    $kind = trim((string)($_POST['message_kind'] ?? 'text'));
    $text = trim((string)($_POST['message_text'] ?? ''));
    $replyToMessageId = max(0, (int)($_POST['reply_to_message_id'] ?? 0));
    $clientNonce = trim((string)($_POST['client_nonce'] ?? ''));
    if ($clientNonce !== '' && !preg_match('/^[a-zA-Z0-9._:-]{1,80}$/', $clientNonce)) {
        $clientNonce = '';
    }

    if ($recipientType === 'group' && !liveChatFeatureEnabled($conn, 'live_chat_group_chats_enabled', true)) {
        throw new RuntimeException('Group chats are currently disabled.');
    }

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
    if ($kind === 'voice' && !liveChatFeatureEnabled($conn, 'live_chat_voice_notes_enabled', true)) {
        throw new RuntimeException('Voice notes are currently disabled.');
    }
    if ($kind === 'attachment' && !liveChatFeatureEnabled($conn, 'live_chat_attachments_enabled', true)) {
        throw new RuntimeException('Attachments are currently disabled.');
    }

    $upload = null;
    if (!empty($_FILES['file']) && in_array($kind, ['voice', 'attachment'], true)) {
        $upload = liveChatStoreUpload($conn, $_FILES['file'], $kind);
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
        (sender_id, recipient_id, message_kind, message_text, file_name, file_path, file_size, mime_type, reply_to_message_id, client_nonce)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException($conn->error);
    }
    $replyTo = $replyToMessageId > 0 ? $replyToMessageId : null;
    $nonceValue = $clientNonce !== '' ? $clientNonce : null;
    $stmt->bind_param('ssssssisis', $userId, $recipientId, $kind, $text, $fileName, $filePath, $fileSize, $mimeType, $replyTo, $nonceValue);
    $stmt->execute();
    $messageId = (int)$conn->insert_id;
    $stmt->close();

    $senderName = 'You';
    $senderPhoto = 'images/default-user.png';
    $userStmt = $conn->prepare("SELECT userName, userPhoto FROM tb_users WHERE userId = ? LIMIT 1");
    if ($userStmt) {
        $userStmt->bind_param('s', $userId);
        $userStmt->execute();
        $userRow = $userStmt->get_result()->fetch_assoc();
        $userStmt->close();
        if ($userRow) {
            $senderName = $userRow['userName'] ?: $senderName;
            $senderPhoto = $userRow['userPhoto'] ?: $senderPhoto;
        }
    }

    $replyPreview = [
        'text' => '',
        'fileName' => '',
        'senderName' => ''
    ];
    if ($replyToMessageId > 0) {
        $replyStmt = $conn->prepare("
            SELECT m.message_text, m.file_name, u.userName
            FROM tb_live_chat_messages m
            LEFT JOIN tb_users u ON u.userId = m.sender_id
            WHERE m.chat_message_id = ?
            LIMIT 1
        ");
        if ($replyStmt) {
            $replyStmt->bind_param('i', $replyToMessageId);
            $replyStmt->execute();
            $replyRow = $replyStmt->get_result()->fetch_assoc();
            $replyStmt->close();
            if ($replyRow) {
                $replyPreview['text'] = (string)($replyRow['message_text'] ?? '');
                $replyPreview['fileName'] = (string)($replyRow['file_name'] ?? '');
                $replyPreview['senderName'] = (string)($replyRow['userName'] ?? '');
            }
        }
    }

    $createdAt = date('Y-m-d H:i:s');
    $payloadMessage = [
        'id' => $messageId,
        'clientNonce' => $clientNonce,
        'senderId' => $userId,
        'recipientId' => $recipientId,
        'kind' => $kind,
        'text' => $text,
        'fileName' => $fileName ?? '',
        'filePath' => $filePath ?? '',
        'fileSize' => $fileSize,
        'mimeType' => $mimeType ?? '',
        'replyToMessageId' => $replyToMessageId,
        'replyToMessageText' => $replyPreview['text'],
        'replyToFileName' => $replyPreview['fileName'],
        'replyToSenderName' => $replyPreview['senderName'],
        'reactionEmoji' => '',
        'isPinned' => false,
        'deliveredAt' => null,
        'isRead' => false,
        'readAt' => null,
        'isEdited' => false,
        'isDeleted' => false,
        'createdAt' => $createdAt,
        'canEdit' => true,
        'senderName' => $senderName,
        'senderPhoto' => $senderPhoto,
        'isOwn' => true,
        'receiptStatus' => 'sent'
    ];
    liveChatAppendCacheMessage($recipientType, $userId, $recipientId, $payloadMessage);

    liveChatRespond([
        'success' => true,
        'message_id' => $messageId,
        'client_nonce' => $clientNonce,
        'chatMessage' => $payloadMessage,
        'message' => 'Chat message sent.'
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}
