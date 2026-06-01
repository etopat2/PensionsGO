<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $data = liveChatJsonInput();
    $action = trim((string)($data['action'] ?? ''));

    if ($action === 'typing') {
        $peerType = trim((string)($data['peer_type'] ?? 'user'));
        $peerId = trim((string)($data['peer_id'] ?? ''));
        if (!in_array($peerType, ['user', 'group'], true) || $peerId === '') {
            throw new RuntimeException('Select a valid conversation.');
        }
        if ($peerType === 'group') {
            if (!liveChatCanAccessGroup($conn, $peerId, $userId)) {
                throw new RuntimeException('Select a valid group.');
            }
        } elseif ($peerId === $userId || !liveChatCanReachUser($conn, $peerId)) {
            throw new RuntimeException('Select a valid staff recipient.');
        }
        $stmt = $conn->prepare("
            INSERT INTO tb_live_chat_typing (user_id, peer_type, peer_id)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->bind_param('sss', $userId, $peerType, $peerId);
        $stmt->execute();
        liveChatRespond(['success' => true]);
    }

    if ($action === 'typing_stop') {
        $peerType = trim((string)($data['peer_type'] ?? 'user'));
        $peerId = trim((string)($data['peer_id'] ?? ''));
        $stmt = $conn->prepare("DELETE FROM tb_live_chat_typing WHERE user_id = ? AND peer_type = ? AND peer_id = ?");
        if ($stmt) {
            $stmt->bind_param('sss', $userId, $peerType, $peerId);
            $stmt->execute();
            $stmt->close();
        }
        liveChatRespond(['success' => true]);
    }

    if ($action === 'read') {
        $messageIds = $data['message_ids'] ?? [];
        if (!is_array($messageIds)) {
            $messageIds = [$messageIds];
        }
        $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if (empty($messageIds)) {
            liveChatRespond(['success' => true, 'updated' => 0]);
        }
        $updated = 0;
        $stmt = $conn->prepare("
            UPDATE tb_live_chat_messages
            SET delivered_at = COALESCE(delivered_at, NOW()),
                is_read = 1,
                read_at = COALESCE(read_at, NOW())
            WHERE chat_message_id = ?
              AND recipient_id = ?
              AND sender_id <> ?
              AND deleted_at IS NULL
        ");
        if (!$stmt) {
            throw new RuntimeException('Unable to update read receipts.');
        }
        foreach ($messageIds as $id) {
            $stmt->bind_param('iss', $id, $userId, $userId);
            $stmt->execute();
            $updated += max(0, $stmt->affected_rows);
        }
        $stmt->close();
        liveChatRespond(['success' => true, 'updated' => $updated]);
    }

    $messageId = max(0, (int)($data['message_id'] ?? 0));
    if ($messageId <= 0) {
        throw new RuntimeException('Select a valid message.');
    }

    $messageStmt = $conn->prepare("SELECT sender_id, recipient_id, message_text, created_at, deleted_at FROM tb_live_chat_messages WHERE chat_message_id = ? LIMIT 1");
    $messageStmt->bind_param('i', $messageId);
    $messageStmt->execute();
    $message = $messageStmt->get_result()->fetch_assoc();
    $messageStmt->close();
    if (!$message) {
        throw new RuntimeException('Message not found.');
    }

    $isSender = (string)$message['sender_id'] === $userId;
    $isDirectRecipient = (string)$message['recipient_id'] === $userId;
    $isGroupRecipient = liveChatCanAccessGroup($conn, (string)$message['recipient_id'], $userId);
    if (!$isSender && !$isDirectRecipient && !$isGroupRecipient) {
        throw new RuntimeException('Message not found or cannot be modified.');
    }

    if ($action === 'edit') {
        if (!$isSender) {
            throw new RuntimeException('Only the sender can edit this message.');
        }
        $text = trim((string)($data['message_text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('Message cannot be empty.');
        }
        $createdAt = strtotime((string)($message['created_at'] ?? ''));
        if ($createdAt <= 0 || (time() - $createdAt) > 300) {
            throw new RuntimeException('This message can no longer be edited.');
        }
        $stmt = $conn->prepare("UPDATE tb_live_chat_messages SET message_text = ?, edited_at = NOW() WHERE chat_message_id = ? AND sender_id = ? AND deleted_at IS NULL");
        $stmt->bind_param('sis', $text, $messageId, $userId);
        $stmt->execute();
        liveChatRespond(['success' => true]);
    }

    if ($action === 'delete') {
        if (!empty($message['deleted_at'])) {
            $stmt = $conn->prepare("DELETE FROM tb_live_chat_messages WHERE chat_message_id = ?");
            $stmt->bind_param('i', $messageId);
            $stmt->execute();
            liveChatRespond(['success' => true, 'removed' => true]);
        }
        $stmt = $conn->prepare("UPDATE tb_live_chat_messages SET deleted_at = NOW(), message_text = '', file_name = NULL, file_path = NULL, mime_type = NULL WHERE chat_message_id = ?");
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        liveChatRespond(['success' => true]);
    }

    if ($action === 'react') {
        $emoji = trim((string)($data['emoji'] ?? ''));
        $stmt = $conn->prepare("UPDATE tb_live_chat_messages SET reaction_emoji = ? WHERE chat_message_id = ? AND deleted_at IS NULL");
        $stmt->bind_param('si', $emoji, $messageId);
        $stmt->execute();
        liveChatRespond(['success' => true]);
    }

    if ($action === 'pin') {
        $isPinned = !empty($data['is_pinned']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE tb_live_chat_messages SET is_pinned = ? WHERE chat_message_id = ?");
        $stmt->bind_param('ii', $isPinned, $messageId);
        $stmt->execute();
        liveChatRespond(['success' => true]);
    }

    throw new RuntimeException('Unsupported message action.');
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}
