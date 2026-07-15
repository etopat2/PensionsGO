<?php
require_once __DIR__ . '/live_chat_common.php';

function liveChatPollRecipientAllowed(mysqli $conn, string $recipientType, string $recipientId, string $userId): bool
{
    if ($recipientType === 'group') {
        return liveChatCanAccessGroup($conn, $recipientId, $userId);
    }
    return $recipientId !== $userId && liveChatCanReachUser($conn, $recipientId);
}

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);
    liveChatReleaseSessionLock();

    if (!liveChatFeatureEnabled($conn, 'live_chat_polls_enabled', true)) {
        throw new RuntimeException('Polls are currently disabled.');
    }

    $data = liveChatJsonInput();
    $action = trim((string)($data['action'] ?? 'create'));

    if ($action === 'vote') {
        $pollId = max(0, (int)($data['poll_id'] ?? 0));
        $optionIds = $data['option_ids'] ?? [];
        if (!is_array($optionIds)) {
            $optionIds = [$optionIds];
        }
        $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds))));
        if ($pollId <= 0 || empty($optionIds)) {
            throw new RuntimeException('Select a poll option.');
        }

        $pollStmt = $conn->prepare("
            SELECT p.allow_multiple, m.recipient_id, m.sender_id
            FROM tb_live_chat_polls p
            INNER JOIN tb_live_chat_messages m ON m.chat_message_id = p.chat_message_id
            WHERE p.poll_id = ?
            LIMIT 1
        ");
        $pollStmt->bind_param('i', $pollId);
        $pollStmt->execute();
        $poll = $pollStmt->get_result()->fetch_assoc();
        $pollStmt->close();
        if (!$poll) {
            throw new RuntimeException('Poll not found.');
        }

        $allowMultiple = (int)$poll['allow_multiple'] === 1;
        if (!$allowMultiple) {
            $optionIds = [reset($optionIds)];
            $deleteStmt = $conn->prepare("DELETE FROM tb_live_chat_poll_votes WHERE poll_id = ? AND user_id = ?");
            $deleteStmt->bind_param('is', $pollId, $userId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $insert = $conn->prepare("INSERT IGNORE INTO tb_live_chat_poll_votes (poll_id, option_id, user_id) VALUES (?, ?, ?)");
        foreach ($optionIds as $optionId) {
            $insert->bind_param('iis', $pollId, $optionId, $userId);
            $insert->execute();
        }
        $insert->close();
        liveChatRespond(['success' => true, 'message' => 'Vote recorded.']);
    }

    $recipientType = trim((string)($data['recipient_type'] ?? 'user'));
    $recipientId = trim((string)($data['recipient_id'] ?? ''));
    $question = trim((string)($data['question'] ?? ''));
    $options = $data['options'] ?? [];
    $allowMultiple = !empty($data['allow_multiple']) ? 1 : 0;
    $priority = trim((string)($data['priority'] ?? 'normal'));
    $tag = trim((string)($data['tag'] ?? ''));
    $closesAt = trim((string)($data['closes_at'] ?? ''));

    if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
        $priority = 'normal';
    }
    if (!is_array($options)) {
        $options = [];
    }
    $options = array_values(array_filter(array_map(static fn($option) => trim((string)$option), $options)));
    if ($question === '' || count($options) < 2) {
        throw new RuntimeException('A poll needs a question and at least two options.');
    }
    if (!liveChatPollRecipientAllowed($conn, $recipientType, $recipientId, $userId)) {
        throw new RuntimeException('Select a valid poll recipient.');
    }

    $summary = json_encode([
        'type' => 'poll',
        'question' => $question,
        'priority' => $priority,
        'tag' => $tag
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $messageStmt = $conn->prepare("
        INSERT INTO tb_live_chat_messages (sender_id, recipient_id, message_kind, message_text)
        VALUES (?, ?, 'text', ?)
    ");
    $messageStmt->bind_param('sss', $userId, $recipientId, $summary);
    $messageStmt->execute();
    $messageId = (int)$conn->insert_id;
    $messageStmt->close();

    $closeValue = $closesAt !== '' ? date('Y-m-d H:i:s', strtotime($closesAt)) : null;
    $pollStmt = $conn->prepare("
        INSERT INTO tb_live_chat_polls (chat_message_id, question, allow_multiple, priority, tag, closes_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $pollStmt->bind_param('isissss', $messageId, $question, $allowMultiple, $priority, $tag, $closeValue, $userId);
    $pollStmt->execute();
    $pollId = (int)$conn->insert_id;
    $pollStmt->close();

    $optionStmt = $conn->prepare("INSERT INTO tb_live_chat_poll_options (poll_id, option_text, sort_order) VALUES (?, ?, ?)");
    foreach ($options as $index => $optionText) {
        $order = $index + 1;
        $optionStmt->bind_param('isi', $pollId, $optionText, $order);
        $optionStmt->execute();
    }
    $optionStmt->close();

    liveChatRespond(['success' => true, 'message_id' => $messageId, 'poll_id' => $pollId]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage()]);
}
