<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $peerId = trim((string)($_GET['peer_id'] ?? ''));
    $peerType = trim((string)($_GET['peer_type'] ?? 'user'));
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));

    if ($peerType === 'group') {
        if ($peerId === '' || !liveChatCanAccessGroup($conn, $peerId, $userId)) {
            liveChatRespond(['success' => false, 'message' => 'Select a valid group.', 'messages' => []]);
        }
    } elseif ($peerId === '' || !liveChatCanReachUser($conn, $peerId)) {
        liveChatRespond(['success' => false, 'message' => 'Select a valid staff user.', 'messages' => []]);
    }

if ($peerType !== 'group') {
    $markStmt = $conn->prepare("
        UPDATE tb_live_chat_messages
        SET is_read = 1, read_at = COALESCE(read_at, NOW())
        WHERE sender_id = ? AND recipient_id = ? AND is_read = 0
    ");
    if ($markStmt) {
        $markStmt->bind_param('ss', $peerId, $userId);
        $markStmt->execute();
        $markStmt->close();
    }
}

if ($peerType === 'group') {
    $stmt = $conn->prepare("
    SELECT
        m.chat_message_id,
        m.sender_id,
        m.recipient_id,
        m.message_kind,
        m.message_text,
        m.file_name,
        m.file_path,
        m.file_size,
        m.mime_type,
        m.reply_to_message_id,
        m.reaction_emoji,
        m.is_pinned,
        m.is_read,
        m.read_at,
        m.edited_at,
        m.deleted_at,
        m.created_at,
        u.userName AS sender_name,
        u.userPhoto AS sender_photo,
        rm.message_text AS reply_message_text,
        rm.file_name AS reply_file_name,
        ru.userName AS reply_sender_name
    FROM tb_live_chat_messages m
    LEFT JOIN tb_users u ON u.userId = m.sender_id
    LEFT JOIN tb_live_chat_messages rm ON rm.chat_message_id = m.reply_to_message_id
    LEFT JOIN tb_users ru ON ru.userId = rm.sender_id
    WHERE m.recipient_id = ?
      AND m.chat_message_id > ?
    ORDER BY m.chat_message_id ASC
    LIMIT 250
");
} else {
    $stmt = $conn->prepare("
    SELECT
        m.chat_message_id,
        m.sender_id,
        m.recipient_id,
        m.message_kind,
        m.message_text,
        m.file_name,
        m.file_path,
        m.file_size,
        m.mime_type,
        m.reply_to_message_id,
        m.reaction_emoji,
        m.is_pinned,
        m.is_read,
        m.read_at,
        m.edited_at,
        m.deleted_at,
        m.created_at,
        u.userName AS sender_name,
        u.userPhoto AS sender_photo,
        rm.message_text AS reply_message_text,
        rm.file_name AS reply_file_name,
        ru.userName AS reply_sender_name
    FROM tb_live_chat_messages m
    LEFT JOIN tb_users u ON u.userId = m.sender_id
    LEFT JOIN tb_live_chat_messages rm ON rm.chat_message_id = m.reply_to_message_id
    LEFT JOIN tb_users ru ON ru.userId = rm.sender_id
    WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
      AND m.chat_message_id > ?
    ORDER BY m.chat_message_id ASC
    LIMIT 250
");
}
    if (!$stmt) {
        throw new RuntimeException('Unable to load live chat messages.');
    }
    if ($peerType === 'group') {
        $stmt->bind_param('si', $peerId, $sinceId);
    } else {
        $stmt->bind_param('ssssi', $userId, $peerId, $peerId, $userId, $sinceId);
    }
    $stmt->execute();
    $result = $stmt->get_result();

$messages = [];
$messageIds = [];
while ($row = $result->fetch_assoc()) {
    $messageId = (int)$row['chat_message_id'];
    $messageIds[] = $messageId;
    $messages[] = [
        'id' => $messageId,
        'senderId' => $row['sender_id'],
        'recipientId' => $row['recipient_id'],
        'kind' => $row['message_kind'],
        'text' => $row['message_text'] ?? '',
        'fileName' => $row['file_name'] ?? '',
        'filePath' => $row['file_path'] ?? '',
        'fileSize' => (int)($row['file_size'] ?? 0),
        'mimeType' => $row['mime_type'] ?? '',
        'replyToMessageId' => (int)($row['reply_to_message_id'] ?? 0),
        'replyToMessageText' => $row['reply_message_text'] ?? '',
        'replyToFileName' => $row['reply_file_name'] ?? '',
        'replyToSenderName' => $row['reply_sender_name'] ?? '',
        'reactionEmoji' => $row['reaction_emoji'] ?? '',
        'isPinned' => (int)($row['is_pinned'] ?? 0) === 1,
        'isRead' => (int)$row['is_read'] === 1,
        'readAt' => $row['read_at'] ?? null,
        'isEdited' => !empty($row['edited_at']),
        'isDeleted' => !empty($row['deleted_at']),
        'createdAt' => $row['created_at'],
        'senderName' => $row['sender_name'] ?? 'Unknown User',
        'senderPhoto' => $row['sender_photo'] ?: 'images/default-user.png',
        'isOwn' => $row['sender_id'] === $userId
    ];
}
    $stmt->close();

    if (!empty($messageIds)) {
        $readStmt = $conn->prepare("
            INSERT IGNORE INTO tb_live_chat_message_reads (chat_message_id, user_id)
            VALUES (?, ?)
        ");
        if ($readStmt) {
            foreach ($messageIds as $messageId) {
                $ownedByMe = false;
                foreach ($messages as $message) {
                    if ((int)$message['id'] === (int)$messageId && $message['isOwn']) {
                        $ownedByMe = true;
                        break;
                    }
                }
                if ($ownedByMe) continue;
                $readStmt->bind_param('is', $messageId, $userId);
                $readStmt->execute();
            }
            $readStmt->close();
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $types = str_repeat('i', count($messageIds));
        $pollStmt = $conn->prepare("
            SELECT
                p.poll_id,
                p.chat_message_id,
                p.question,
                p.allow_multiple,
                p.priority,
                p.tag,
                p.closes_at,
                o.option_id,
                o.option_text,
                COUNT(v.vote_id) AS vote_count,
                MAX(CASE WHEN v.user_id = ? THEN 1 ELSE 0 END) AS voted_by_me
            FROM tb_live_chat_polls p
            INNER JOIN tb_live_chat_poll_options o ON o.poll_id = p.poll_id
            LEFT JOIN tb_live_chat_poll_votes v ON v.option_id = o.option_id
            WHERE p.chat_message_id IN ($placeholders)
            GROUP BY p.poll_id, p.chat_message_id, p.question, p.allow_multiple, p.priority, p.tag, p.closes_at, o.option_id, o.option_text, o.sort_order
            ORDER BY p.poll_id ASC, o.sort_order ASC
        ");
        if ($pollStmt) {
            $bindTypes = 's' . $types;
            $bindValues = array_merge([$userId], $messageIds);
            liveChatBindParams($pollStmt, $bindTypes, $bindValues);
            $pollStmt->execute();
            $pollResult = $pollStmt->get_result();
            $pollsByMessage = [];
            while ($pollRow = $pollResult->fetch_assoc()) {
                $messageId = (int)$pollRow['chat_message_id'];
                if (!isset($pollsByMessage[$messageId])) {
                    $pollsByMessage[$messageId] = [
                        'pollId' => (int)$pollRow['poll_id'],
                        'question' => $pollRow['question'],
                        'allowMultiple' => (int)$pollRow['allow_multiple'] === 1,
                        'priority' => $pollRow['priority'],
                        'tag' => $pollRow['tag'] ?? '',
                        'closesAt' => $pollRow['closes_at'] ?? null,
                        'totalVotes' => 0,
                        'options' => []
                    ];
                }
                $voteCount = (int)($pollRow['vote_count'] ?? 0);
                $pollsByMessage[$messageId]['totalVotes'] += $voteCount;
                $pollsByMessage[$messageId]['options'][] = [
                    'optionId' => (int)$pollRow['option_id'],
                    'text' => $pollRow['option_text'],
                    'voteCount' => $voteCount,
                    'votedByMe' => (int)($pollRow['voted_by_me'] ?? 0) === 1
                ];
            }
            $pollStmt->close();

            foreach ($messages as &$message) {
                if (isset($pollsByMessage[$message['id']])) {
                    $message['poll'] = $pollsByMessage[$message['id']];
                }
            }
            unset($message);
        }
    }

    liveChatRespond(['success' => true, 'messages' => $messages]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage(), 'messages' => []]);
}

function liveChatBindParams(mysqli_stmt $stmt, string $types, array &$values): bool
{
    $refs = [];
    foreach ($values as $key => &$value) {
        $refs[$key] = &$value;
    }
    return $stmt->bind_param($types, ...$refs);
}
