<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $data = liveChatJsonInput();
    $action = trim((string)($data['action'] ?? ''));
    $messageId = max(0, (int)($data['message_id'] ?? 0));
    if ($messageId <= 0) {
        throw new RuntimeException('Select a valid message.');
    }

    $messageStmt = $conn->prepare("SELECT sender_id, recipient_id, deleted_at FROM tb_live_chat_messages WHERE chat_message_id = ? LIMIT 1");
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
