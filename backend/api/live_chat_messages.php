<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $peerId = trim((string)($_GET['peer_id'] ?? ''));
    $peerType = trim((string)($_GET['peer_type'] ?? 'user'));
    $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
    $receiptOnly = !empty($_GET['receipt_only']);
    $messageOrderClause = $sinceId > 0 ? 'ASC LIMIT 120' : 'DESC LIMIT 80';

    if ($peerType === 'group') {
        if ($peerId === '' || !liveChatCanAccessGroup($conn, $peerId, $userId)) {
            liveChatRespond(['success' => false, 'message' => 'Select a valid group.', 'messages' => []]);
        }
    } elseif ($peerId === '' || !liveChatCanReachUser($conn, $peerId)) {
        liveChatRespond(['success' => false, 'message' => 'Select a valid staff user.', 'messages' => []]);
    }

    if ($receiptOnly) {
        liveChatRespond([
            'success' => true,
            'messages' => [],
            'receipts' => $peerType !== 'group' ? liveChatDirectReceipts($conn, $userId, $peerId) : [],
            'typing' => [],
            'serverTime' => date('Y-m-d H:i:s')
        ]);
    }

if ($peerType !== 'group') {
    $markStmt = $conn->prepare("
        UPDATE tb_live_chat_messages
        SET delivered_at = COALESCE(delivered_at, NOW())
        WHERE sender_id = ? AND recipient_id = ? AND delivered_at IS NULL
        ORDER BY chat_message_id DESC
        LIMIT 150
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
        m.delivered_at,
        m.is_read,
        m.read_at,
        m.edited_at,
        m.deleted_at,
        m.client_nonce,
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
    LEFT JOIN tb_live_chat_message_deletions md ON md.chat_message_id = m.chat_message_id AND md.user_id = ?
    WHERE m.recipient_id = ?
      AND m.chat_message_id > ?
      AND m.admin_deleted_at IS NULL
      AND md.chat_message_id IS NULL
    ORDER BY m.chat_message_id $messageOrderClause
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
        m.delivered_at,
        m.is_read,
        m.read_at,
        m.edited_at,
        m.deleted_at,
        m.client_nonce,
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
    LEFT JOIN tb_live_chat_message_deletions md ON md.chat_message_id = m.chat_message_id AND md.user_id = ?
    WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
      AND m.chat_message_id > ?
      AND m.admin_deleted_at IS NULL
      AND md.chat_message_id IS NULL
    ORDER BY m.chat_message_id $messageOrderClause
");
}
    if (!$stmt) {
        throw new RuntimeException('Unable to load live chat messages.');
    }
    if ($peerType === 'group') {
        $stmt->bind_param('ssi', $userId, $peerId, $sinceId);
    } else {
        $stmt->bind_param('sssssi', $userId, $userId, $peerId, $peerId, $userId, $sinceId);
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
        'clientNonce' => $row['client_nonce'] ?? '',
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
        'deliveredAt' => $row['delivered_at'] ?? null,
        'isRead' => (int)$row['is_read'] === 1,
        'readAt' => $row['read_at'] ?? null,
        'isEdited' => !empty($row['edited_at']),
        'isDeleted' => !empty($row['deleted_at']),
        'createdAt' => $row['created_at'],
        'canEdit' => $row['message_kind'] !== 'call' && $row['sender_id'] === $userId && empty($row['deleted_at']) && strtotime((string)$row['created_at']) >= (time() - (liveChatSettingInt($conn, 'live_chat_edit_window_minutes', 5, 1, 60) * 60)),
        'senderName' => $row['sender_name'] ?? 'Unknown User',
        'senderPhoto' => $row['sender_photo'] ?: 'images/default-user.png',
        'isOwn' => $row['sender_id'] === $userId,
        'receiptStatus' => ($row['sender_id'] === $userId)
            ? (!empty($row['read_at']) ? 'read' : (!empty($row['delivered_at']) ? 'delivered' : 'sent'))
            : 'received'
    ];
}
    $stmt->close();
    if ($sinceId === 0) {
        $messages = array_reverse($messages);
        $messageIds = array_reverse($messageIds);
    }

    if (!empty($messageIds)) {
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

    if ($sinceId > 0) {
        $knownIds = array_fill_keys(array_map(static fn($message) => (int)$message['id'], $messages), true);
        $hiddenIds = liveChatHiddenMessageIds($conn, $userId, $sinceId);
        $deletedIds = liveChatDeletedMessageIds($conn, $userId, $peerType, $peerId, $sinceId);
        foreach (liveChatReadCacheMessages($peerType, $userId, $peerId, $sinceId) as $cachedMessage) {
            $cachedId = (int)($cachedMessage['id'] ?? 0);
            if ($cachedId > 0 && !isset($knownIds[$cachedId]) && !isset($hiddenIds[$cachedId]) && !isset($deletedIds[$cachedId])) {
                $messages[] = $cachedMessage;
                $knownIds[$cachedId] = true;
            }
        }
        usort($messages, static fn($a, $b) => (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
    }

    $receipts = ($peerType !== 'group' && liveChatFeatureEnabled($conn, 'live_chat_read_receipts_enabled', true)) ? liveChatDirectReceipts($conn, $userId, $peerId) : [];

    $typing = [];
    $typingStmt = null;
    if (!liveChatFeatureEnabled($conn, 'live_chat_typing_presence_enabled', true)) {
        $typing = [];
    } elseif ($peerType === 'group') {
        $typingStmt = $conn->prepare("
            SELECT t.user_id, u.userName
            FROM tb_live_chat_typing t
            LEFT JOIN tb_users u ON u.userId = t.user_id
            WHERE t.peer_type = 'group'
              AND t.peer_id = ?
              AND t.user_id <> ?
              AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 4 SECOND)
            ORDER BY t.updated_at DESC
            LIMIT 3
        ");
        if ($typingStmt) {
            $typingStmt->bind_param('ss', $peerId, $userId);
        }
    } else {
        $typingStmt = $conn->prepare("
            SELECT t.user_id, u.userName
            FROM tb_live_chat_typing t
            LEFT JOIN tb_users u ON u.userId = t.user_id
            WHERE t.peer_type = 'user'
              AND t.peer_id = ?
              AND t.user_id = ?
              AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 4 SECOND)
            LIMIT 1
        ");
        if ($typingStmt) {
            $typingStmt->bind_param('ss', $userId, $peerId);
        }
    }
    if ($typingStmt) {
        $typingStmt->execute();
        $typingResult = $typingStmt->get_result();
        while ($typingRow = $typingResult->fetch_assoc()) {
            $typing[] = [
                'userId' => $typingRow['user_id'],
                'name' => $typingRow['userName'] ?: 'Someone'
            ];
        }
        $typingStmt->close();
    }

    $deletedUpdates = $sinceId > 0 ? liveChatDeletedUpdates($conn, $userId, $peerType, $peerId) : [];

    liveChatRespond(['success' => true, 'messages' => $messages, 'receipts' => $receipts, 'deletedUpdates' => $deletedUpdates, 'typing' => $typing, 'serverTime' => date('Y-m-d H:i:s')]);
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

function liveChatDirectReceipts(mysqli $conn, string $userId, string $peerId): array
{
    $receipts = [];
    $receiptStmt = $conn->prepare("
        SELECT chat_message_id, delivered_at, is_read, read_at
        FROM tb_live_chat_messages
        WHERE sender_id = ? AND recipient_id = ?
          AND admin_deleted_at IS NULL
        ORDER BY chat_message_id DESC
        LIMIT 150
    ");
    if (!$receiptStmt) {
        return $receipts;
    }
    $receiptStmt->bind_param('ss', $userId, $peerId);
    $receiptStmt->execute();
    $receiptResult = $receiptStmt->get_result();
    while ($receipt = $receiptResult->fetch_assoc()) {
        $receipts[] = [
            'id' => (int)$receipt['chat_message_id'],
            'deliveredAt' => $receipt['delivered_at'] ?? null,
            'isRead' => (int)($receipt['is_read'] ?? 0) === 1,
            'readAt' => $receipt['read_at'] ?? null,
            'receiptStatus' => !empty($receipt['read_at']) ? 'read' : (!empty($receipt['delivered_at']) ? 'delivered' : 'sent')
        ];
    }
    $receiptStmt->close();
    return $receipts;
}

function liveChatHiddenMessageIds(mysqli $conn, string $userId, int $sinceId): array
{
    $hidden = [];
    $stmt = $conn->prepare("
        SELECT chat_message_id
        FROM tb_live_chat_message_deletions
        WHERE user_id = ?
          AND chat_message_id > ?
    ");
    if (!$stmt) {
        return $hidden;
    }
    $stmt->bind_param('si', $userId, $sinceId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $hidden[(int)$row['chat_message_id']] = true;
    }
    $stmt->close();
    return $hidden;
}

function liveChatDeletedMessageIds(mysqli $conn, string $userId, string $peerType, string $peerId, int $sinceId): array
{
    $deleted = [];
    if ($peerType === 'group') {
        $stmt = $conn->prepare("
            SELECT m.chat_message_id
            FROM tb_live_chat_messages m
            LEFT JOIN tb_live_chat_message_deletions d
              ON d.chat_message_id = m.chat_message_id AND d.user_id = ?
            WHERE m.recipient_id = ?
              AND m.chat_message_id > ?
              AND m.deleted_at IS NOT NULL
              AND m.admin_deleted_at IS NULL
              AND d.chat_message_id IS NULL
        ");
        if ($stmt) {
            $stmt->bind_param('ssi', $userId, $peerId, $sinceId);
        }
    } else {
        $stmt = $conn->prepare("
            SELECT m.chat_message_id
            FROM tb_live_chat_messages m
            LEFT JOIN tb_live_chat_message_deletions d
              ON d.chat_message_id = m.chat_message_id AND d.user_id = ?
            WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
              AND m.chat_message_id > ?
              AND m.deleted_at IS NOT NULL
              AND m.admin_deleted_at IS NULL
              AND d.chat_message_id IS NULL
        ");
        if ($stmt) {
            $stmt->bind_param('sssssi', $userId, $userId, $peerId, $peerId, $userId, $sinceId);
        }
    }
    if (!$stmt) {
        return $deleted;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $deleted[(int)$row['chat_message_id']] = true;
    }
    $stmt->close();
    return $deleted;
}

function liveChatDeletedUpdates(mysqli $conn, string $userId, string $peerType, string $peerId): array
{
    $updates = [];
    if ($peerType === 'group') {
        $stmt = $conn->prepare("
            SELECT m.chat_message_id, m.deleted_at
            FROM tb_live_chat_messages m
            LEFT JOIN tb_live_chat_message_deletions d
              ON d.chat_message_id = m.chat_message_id AND d.user_id = ?
            WHERE m.recipient_id = ?
              AND m.deleted_at IS NOT NULL
              AND m.admin_deleted_at IS NULL
              AND d.chat_message_id IS NULL
            ORDER BY m.deleted_at DESC
            LIMIT 100
        ");
        if ($stmt) {
            $stmt->bind_param('ss', $userId, $peerId);
        }
    } else {
        $stmt = $conn->prepare("
            SELECT m.chat_message_id, m.deleted_at
            FROM tb_live_chat_messages m
            LEFT JOIN tb_live_chat_message_deletions d
              ON d.chat_message_id = m.chat_message_id AND d.user_id = ?
            WHERE ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
              AND m.deleted_at IS NOT NULL
              AND m.admin_deleted_at IS NULL
              AND d.chat_message_id IS NULL
            ORDER BY m.deleted_at DESC
            LIMIT 100
        ");
        if ($stmt) {
            $stmt->bind_param('sssss', $userId, $userId, $peerId, $peerId, $userId);
        }
    }
    if (!$stmt) {
        return $updates;
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $updates[] = [
            'id' => (int)$row['chat_message_id'],
            'deletedAt' => $row['deleted_at'] ?? null
        ];
    }
    $stmt->close();
    return $updates;
}
