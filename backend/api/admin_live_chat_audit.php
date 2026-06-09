<?php
declare(strict_types=1);

require_once __DIR__ . '/live_chat_common.php';

function adminChatAuditRespond(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function adminChatAuditRequireAdmin(mysqli $conn): array
{
    if (!isset($_SESSION['userId'])) {
        adminChatAuditRespond(['success' => false, 'message' => 'Session expired.'], 401);
    }

    if (!function_exists('sessionRoleIn') || !sessionRoleIn($conn, ['admin'])) {
        adminChatAuditRespond(['success' => false, 'message' => 'Admin access required.'], 403);
    }

    $actor = [
        'user_id' => (string)$_SESSION['userId'],
        'user_name' => (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Administrator'),
        'user_role' => (string)($_SESSION['userRole'] ?? 'admin')
    ];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    return $actor;
}

function adminChatAuditBind(mysqli_stmt $stmt, string $types, array $values): void
{
    $refs = [];
    foreach ($values as $index => $value) {
        $refs[$index] = &$values[$index];
    }
    array_unshift($refs, $types);
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function adminChatAuditUserName(?string $name, ?string $fallbackId = null): string
{
    $name = trim((string)$name);
    if ($name !== '') {
        return $name;
    }
    $fallbackId = trim((string)$fallbackId);
    return $fallbackId !== '' ? 'User ' . $fallbackId : 'Unknown User';
}

function adminChatAuditMessagePreview(array $row): string
{
    $kind = (string)($row['message_kind'] ?? 'text');
    $text = trim((string)($row['archive_message_text'] ?? $row['message_text'] ?? ''));
    if ($kind === 'call' && $text !== '') {
        $call = json_decode($text, true);
        if (is_array($call)) {
            $callType = ucfirst((string)($call['callType'] ?? 'audio'));
            $status = strtolower((string)($call['status'] ?? 'logged'));
            $statusLabel = match ($status) {
                'missed' => 'Missed',
                'rejected' => 'Rejected',
                'ended' => 'Ended',
                'accepted' => 'Answered',
                default => ucfirst($status ?: 'Logged')
            };
            $caller = trim((string)($call['callerName'] ?? 'Caller'));
            $callee = trim((string)($call['calleeName'] ?? 'Recipient'));
            return trim($statusLabel . ' ' . strtolower($callType) . ' call: ' . $caller . ' to ' . $callee);
        }
    }
    if ($text !== '') {
        return substr($text, 0, 160);
    }
    $fileName = trim((string)($row['archive_file_name'] ?? $row['file_name'] ?? ''));
    if ($fileName !== '') {
        return ucfirst($kind) . ': ' . $fileName;
    }
    if (!empty($row['admin_deleted_at'])) {
        return 'Deleted by administrator';
    }
    if (!empty($row['deleted_at'])) {
        return 'Deleted message';
    }
    return ucfirst($kind) . ' message';
}

function adminChatAuditDeletionText(array $row): string
{
    $names = trim((string)($row['deleted_by_names'] ?? ''));
    if ($names !== '') {
        return 'Deleted by ' . $names;
    }
    if (!empty($row['deleted_at'])) {
        return 'Deleted by ' . adminChatAuditUserName($row['sender_name'] ?? null, $row['sender_id'] ?? null);
    }
    return '';
}

function adminChatAuditConversationMatches(array $conversation, string $query): bool
{
    if ($query === '') {
        return true;
    }
    $haystack = strtolower(implode(' ', [
        $conversation['title'] ?? '',
        $conversation['subtitle'] ?? '',
        $conversation['lastMessagePreview'] ?? '',
        $conversation['conversationKey'] ?? ''
    ]));
    return strpos($haystack, strtolower($query)) !== false;
}

function adminChatAuditLoadConversations(mysqli $conn): array
{
    $query = trim((string)($_GET['q'] ?? ''));
    $typeFilter = trim(strtolower((string)($_GET['type'] ?? 'all')));
    if (!in_array($typeFilter, ['all', 'direct', 'group'], true)) {
        $typeFilter = 'all';
    }

    $conversations = [];
    $summary = [
        'totalConversations' => 0,
        'directConversations' => 0,
        'groupConversations' => 0,
        'totalMessages' => 0
    ];

    if ($typeFilter === 'all' || $typeFilter === 'direct') {
        $sql = "
            SELECT
                d.user_a,
                d.user_b,
                d.message_count,
                d.deleted_count,
                d.last_message_id,
                d.last_message_at,
                ua.userName AS user_a_name,
                ub.userName AS user_b_name,
                lm.sender_id AS last_sender_id,
                lu.userName AS last_sender_name,
                lm.message_kind,
                lm.message_text,
                lm.file_name,
                lm.deleted_at,
                lm.admin_deleted_at,
                ar.message_text AS archive_message_text,
                ar.file_name AS archive_file_name
            FROM (
                SELECT
                    IF(m.sender_id < m.recipient_id, m.sender_id, m.recipient_id) AS user_a,
                    IF(m.sender_id < m.recipient_id, m.recipient_id, m.sender_id) AS user_b,
                    COUNT(*) AS message_count,
                    SUM(CASE WHEN m.deleted_at IS NULL THEN 0 ELSE 1 END) AS deleted_count,
                    MAX(m.chat_message_id) AS last_message_id,
                    MAX(m.created_at) AS last_message_at
                FROM tb_live_chat_messages m
                LEFT JOIN tb_live_chat_groups g ON g.group_id = m.recipient_id
                WHERE g.group_id IS NULL
                GROUP BY user_a, user_b
            ) d
            LEFT JOIN tb_users ua ON ua.userId = d.user_a
            LEFT JOIN tb_users ub ON ub.userId = d.user_b
            LEFT JOIN tb_live_chat_messages lm ON lm.chat_message_id = d.last_message_id
            LEFT JOIN tb_live_chat_message_audit_archive ar ON ar.chat_message_id = lm.chat_message_id
            LEFT JOIN tb_users lu ON lu.userId = lm.sender_id
            ORDER BY d.last_message_at DESC, d.last_message_id DESC
            LIMIT 250
        ";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $leftName = adminChatAuditUserName($row['user_a_name'] ?? null, $row['user_a'] ?? null);
                $rightName = adminChatAuditUserName($row['user_b_name'] ?? null, $row['user_b'] ?? null);
                $conversation = [
                    'type' => 'direct',
                    'conversationKey' => 'direct:' . $row['user_a'] . ':' . $row['user_b'],
                    'peerA' => (string)$row['user_a'],
                    'peerB' => (string)$row['user_b'],
                    'title' => $leftName . ' / ' . $rightName,
                    'subtitle' => 'Direct conversation',
                    'participants' => [$leftName, $rightName],
                    'messageCount' => (int)$row['message_count'],
                    'deletedCount' => (int)$row['deleted_count'],
                    'lastMessageId' => (int)$row['last_message_id'],
                    'lastMessageAt' => $row['last_message_at'] ?? null,
                    'lastSenderName' => adminChatAuditUserName($row['last_sender_name'] ?? null, $row['last_sender_id'] ?? null),
                    'lastMessagePreview' => adminChatAuditMessagePreview($row)
                ];
                if (adminChatAuditConversationMatches($conversation, $query)) {
                    $conversations[] = $conversation;
                    $summary['directConversations']++;
                    $summary['totalMessages'] += $conversation['messageCount'];
                }
            }
            $result->free();
        }
    }

    if ($typeFilter === 'all' || $typeFilter === 'group') {
        $sql = "
            SELECT
                g.group_id,
                g.group_name,
                g.created_at,
                COALESCE(gm.member_count, 0) AS member_count,
                COALESCE(ma.message_count, 0) AS message_count,
                COALESCE(ma.deleted_count, 0) AS deleted_count,
                ma.last_message_id,
                ma.last_message_at,
                lm.sender_id AS last_sender_id,
                lu.userName AS last_sender_name,
                lm.message_kind,
                lm.message_text,
                lm.file_name,
                lm.deleted_at,
                lm.admin_deleted_at,
                ar.message_text AS archive_message_text,
                ar.file_name AS archive_file_name
            FROM tb_live_chat_groups g
            LEFT JOIN (
                SELECT group_id, COUNT(*) AS member_count
                FROM tb_live_chat_group_members
                GROUP BY group_id
            ) gm ON gm.group_id = g.group_id
            LEFT JOIN (
                SELECT
                    recipient_id AS group_id,
                    COUNT(*) AS message_count,
                    SUM(CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END) AS deleted_count,
                    MAX(chat_message_id) AS last_message_id,
                    MAX(created_at) AS last_message_at
                FROM tb_live_chat_messages
                GROUP BY recipient_id
            ) ma ON ma.group_id = g.group_id
            LEFT JOIN tb_live_chat_messages lm ON lm.chat_message_id = ma.last_message_id
            LEFT JOIN tb_live_chat_message_audit_archive ar ON ar.chat_message_id = lm.chat_message_id
            LEFT JOIN tb_users lu ON lu.userId = lm.sender_id
            ORDER BY COALESCE(ma.last_message_at, g.created_at) DESC, g.group_name ASC
            LIMIT 250
        ";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $conversation = [
                    'type' => 'group',
                    'conversationKey' => 'group:' . $row['group_id'],
                    'groupId' => (string)$row['group_id'],
                    'title' => trim((string)$row['group_name']) ?: 'Unnamed Group',
                    'subtitle' => ((int)$row['member_count']) . ' members',
                    'participants' => [],
                    'memberCount' => (int)$row['member_count'],
                    'messageCount' => (int)$row['message_count'],
                    'deletedCount' => (int)$row['deleted_count'],
                    'lastMessageId' => (int)($row['last_message_id'] ?? 0),
                    'lastMessageAt' => $row['last_message_at'] ?? $row['created_at'] ?? null,
                    'lastSenderName' => adminChatAuditUserName($row['last_sender_name'] ?? null, $row['last_sender_id'] ?? null),
                    'lastMessagePreview' => ((int)$row['message_count'] > 0) ? adminChatAuditMessagePreview($row) : 'No messages yet'
                ];
                if (adminChatAuditConversationMatches($conversation, $query)) {
                    $conversations[] = $conversation;
                    $summary['groupConversations']++;
                    $summary['totalMessages'] += $conversation['messageCount'];
                }
            }
            $result->free();
        }
    }

    usort($conversations, static function (array $a, array $b): int {
        $timeA = strtotime((string)($a['lastMessageAt'] ?? '')) ?: 0;
        $timeB = strtotime((string)($b['lastMessageAt'] ?? '')) ?: 0;
        if ($timeA === $timeB) {
            return (int)($b['lastMessageId'] ?? 0) <=> (int)($a['lastMessageId'] ?? 0);
        }
        return $timeB <=> $timeA;
    });

    $summary['totalConversations'] = count($conversations);
    return ['conversations' => $conversations, 'summary' => $summary];
}

function adminChatAuditLoadMessages(mysqli $conn): array
{
    $type = trim(strtolower((string)($_GET['type'] ?? 'direct')));
    $limit = max(25, min(200, (int)($_GET['limit'] ?? 100)));
    $beforeId = max(0, (int)($_GET['before_id'] ?? 0));
    $messages = [];
    $conversation = null;

    $select = "
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
            m.admin_deleted_at,
            m.admin_deleted_by,
            ad.userName AS admin_deleted_by_name,
            m.created_at,
            ar.message_text AS archive_message_text,
            ar.file_name AS archive_file_name,
            ar.file_path AS archive_file_path,
            ar.file_size AS archive_file_size,
            ar.mime_type AS archive_mime_type,
            u.userName AS sender_name,
            ru.userName AS reply_sender_name,
            rm.message_text AS reply_message_text,
            rm.file_name AS reply_file_name,
            del.deleted_by_names
        FROM tb_live_chat_messages m
        LEFT JOIN tb_users u ON u.userId = m.sender_id
        LEFT JOIN tb_users ad ON ad.userId = m.admin_deleted_by
        LEFT JOIN tb_live_chat_messages rm ON rm.chat_message_id = m.reply_to_message_id
        LEFT JOIN tb_users ru ON ru.userId = rm.sender_id
        LEFT JOIN tb_live_chat_message_audit_archive ar ON ar.chat_message_id = m.chat_message_id
        LEFT JOIN (
            SELECT d.chat_message_id, GROUP_CONCAT(COALESCE(u.userName, d.user_id) ORDER BY d.deleted_at SEPARATOR ', ') AS deleted_by_names
            FROM tb_live_chat_message_deletions d
            LEFT JOIN tb_users u ON u.userId = d.user_id
            GROUP BY d.chat_message_id
        ) del ON del.chat_message_id = m.chat_message_id
    ";

    if ($type === 'group') {
        $groupId = trim((string)($_GET['group_id'] ?? ''));
        if ($groupId === '') {
            adminChatAuditRespond(['success' => false, 'message' => 'Missing group conversation.'], 422);
        }
        $groupStmt = $conn->prepare("
            SELECT g.group_id, g.group_name, COUNT(gm.user_id) AS member_count
            FROM tb_live_chat_groups g
            LEFT JOIN tb_live_chat_group_members gm ON gm.group_id = g.group_id
            WHERE g.group_id = ?
            GROUP BY g.group_id, g.group_name
            LIMIT 1
        ");
        if ($groupStmt) {
            $groupStmt->bind_param('s', $groupId);
            $groupStmt->execute();
            $group = $groupStmt->get_result()->fetch_assoc();
            $groupStmt->close();
            if ($group) {
                $conversation = [
                    'type' => 'group',
                    'title' => trim((string)$group['group_name']) ?: 'Unnamed Group',
                    'subtitle' => ((int)$group['member_count']) . ' members'
                ];
            }
        }
        $where = ' WHERE m.recipient_id = ?';
        $types = 's';
        $values = [$groupId];
    } else {
        $peerA = trim((string)($_GET['peer_a'] ?? ''));
        $peerB = trim((string)($_GET['peer_b'] ?? ''));
        if ($peerA === '' || $peerB === '') {
            adminChatAuditRespond(['success' => false, 'message' => 'Missing direct conversation peers.'], 422);
        }
        $conversation = ['type' => 'direct', 'title' => 'Direct conversation', 'subtitle' => 'Peer to peer'];
        $where = "
            LEFT JOIN tb_live_chat_groups gx ON gx.group_id = m.recipient_id
            WHERE gx.group_id IS NULL
              AND ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
        ";
        $types = 'ssss';
        $values = [$peerA, $peerB, $peerB, $peerA];
    }

    if ($beforeId > 0) {
        $where .= ' AND m.chat_message_id < ?';
        $types .= 'i';
        $values[] = $beforeId;
    }

    $stmt = $conn->prepare($select . $where . " ORDER BY m.chat_message_id DESC LIMIT {$limit}");
    if (!$stmt) {
        throw new RuntimeException('Unable to load chat transcript.');
    }
    adminChatAuditBind($stmt, $types, $values);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            'id' => (int)$row['chat_message_id'],
            'senderId' => (string)$row['sender_id'],
            'recipientId' => (string)$row['recipient_id'],
            'senderName' => adminChatAuditUserName($row['sender_name'] ?? null, $row['sender_id'] ?? null),
            'kind' => (string)$row['message_kind'],
            'text' => (string)(($row['archive_message_text'] ?? '') !== '' ? $row['archive_message_text'] : ($row['message_text'] ?? '')),
            'fileName' => (string)(($row['archive_file_name'] ?? '') !== '' ? $row['archive_file_name'] : ($row['file_name'] ?? '')),
            'filePath' => (string)(($row['archive_file_path'] ?? '') !== '' ? $row['archive_file_path'] : ($row['file_path'] ?? '')),
            'fileSize' => (int)(($row['archive_file_size'] ?? 0) ?: ($row['file_size'] ?? 0)),
            'mimeType' => (string)(($row['archive_mime_type'] ?? '') !== '' ? $row['archive_mime_type'] : ($row['mime_type'] ?? '')),
            'replyToMessageId' => (int)($row['reply_to_message_id'] ?? 0),
            'replyToSenderName' => adminChatAuditUserName($row['reply_sender_name'] ?? null),
            'replyToMessageText' => (string)($row['reply_message_text'] ?? ''),
            'replyToFileName' => (string)($row['reply_file_name'] ?? ''),
            'reactionEmoji' => (string)($row['reaction_emoji'] ?? ''),
            'isPinned' => (int)($row['is_pinned'] ?? 0) === 1,
            'deliveredAt' => $row['delivered_at'] ?? null,
            'isRead' => (int)($row['is_read'] ?? 0) === 1,
            'readAt' => $row['read_at'] ?? null,
            'isEdited' => !empty($row['edited_at']),
            'isDeleted' => !empty($row['deleted_at']),
            'deletedAt' => $row['deleted_at'] ?? null,
            'deletedByLabel' => adminChatAuditDeletionText($row),
            'isAdminDeleted' => !empty($row['admin_deleted_at']),
            'adminDeletedAt' => $row['admin_deleted_at'] ?? null,
            'adminDeletedByName' => adminChatAuditUserName($row['admin_deleted_by_name'] ?? null, $row['admin_deleted_by'] ?? null),
            'createdAt' => $row['created_at'] ?? null
        ];
    }
    $stmt->close();
    $messages = array_reverse($messages);

    return ['conversation' => $conversation, 'messages' => $messages];
}

function adminChatAuditMessagePeerType(mysqli $conn, string $recipientId): string
{
    $stmt = $conn->prepare('SELECT group_id FROM tb_live_chat_groups WHERE group_id = ? LIMIT 1');
    if (!$stmt) {
        return 'user';
    }
    $stmt->bind_param('s', $recipientId);
    $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $exists ? 'group' : 'user';
}

function adminChatAuditLog(mysqli $conn, array $actor, string $action, string $entityId, array $details = []): void
{
    if (!function_exists('logAuditEvent')) {
        return;
    }
    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'] ?? '',
        'actor_name' => $actor['user_name'] ?? 'Administrator',
        'actor_role' => $actor['user_role'] ?? 'admin',
        'action' => $action,
        'entity_type' => 'live_chat_message',
        'entity_id' => $entityId,
        'details' => $details
    ]);
}

function adminChatAuditDeleteMessageIds(mysqli $conn, array $actor, array $messageIds, string $reason = ''): array
{
    $messageIds = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    if (!$messageIds) {
        adminChatAuditRespond(['success' => false, 'message' => 'Select at least one message.'], 422);
    }

    $select = $conn->prepare('SELECT chat_message_id, sender_id, recipient_id, deleted_at, admin_deleted_at FROM tb_live_chat_messages WHERE chat_message_id = ? LIMIT 1');
    $update = $conn->prepare("
        UPDATE tb_live_chat_messages
        SET admin_deleted_at = COALESCE(admin_deleted_at, NOW()),
            admin_deleted_by = COALESCE(admin_deleted_by, ?),
            admin_delete_reason = ?
        WHERE chat_message_id = ?
    ");
    if (!$select || !$update) {
        throw new RuntimeException('Unable to prepare admin delete.');
    }

    $deleted = 0;
    foreach ($messageIds as $messageId) {
        $select->bind_param('i', $messageId);
        $select->execute();
        $message = $select->get_result()->fetch_assoc();
        if (!$message) {
            continue;
        }
        liveChatArchiveMessage($conn, $messageId, (string)($actor['user_id'] ?? ''), 'admin_delete');
        $adminId = (string)($actor['user_id'] ?? '');
        $safeReason = substr(trim($reason) ?: 'Deleted from Chat Oversight', 0, 255);
        $update->bind_param('ssi', $adminId, $safeReason, $messageId);
        $update->execute();
        liveChatRemoveCacheMessage(
            adminChatAuditMessagePeerType($conn, (string)$message['recipient_id']),
            (string)$message['sender_id'],
            (string)$message['recipient_id'],
            $messageId
        );
        $deleted++;
    }
    $select->close();
    $update->close();

    $entityId = count($messageIds) === 1 ? (string)$messageIds[0] : ('bulk:' . count($messageIds));
    adminChatAuditLog($conn, $actor, 'live_chat_admin_delete', $entityId, [
        'message_ids' => $messageIds,
        'deleted_count' => $deleted,
        'reason' => $reason
    ]);

    return ['deleted' => $deleted];
}

function adminChatAuditConversationMessageIds(mysqli $conn, array $data): array
{
    $type = trim(strtolower((string)($data['type'] ?? 'direct')));
    if ($type === 'group') {
        $groupId = trim((string)($data['group_id'] ?? ''));
        if ($groupId === '') {
            adminChatAuditRespond(['success' => false, 'message' => 'Missing group conversation.'], 422);
        }
        $stmt = $conn->prepare('SELECT chat_message_id FROM tb_live_chat_messages WHERE recipient_id = ? ORDER BY chat_message_id ASC');
        $stmt->bind_param('s', $groupId);
    } else {
        $peerA = trim((string)($data['peer_a'] ?? ''));
        $peerB = trim((string)($data['peer_b'] ?? ''));
        if ($peerA === '' || $peerB === '') {
            adminChatAuditRespond(['success' => false, 'message' => 'Missing direct conversation peers.'], 422);
        }
        $stmt = $conn->prepare("
            SELECT m.chat_message_id
            FROM tb_live_chat_messages m
            LEFT JOIN tb_live_chat_groups g ON g.group_id = m.recipient_id
            WHERE g.group_id IS NULL
              AND ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?))
            ORDER BY m.chat_message_id ASC
        ");
        $stmt->bind_param('ssss', $peerA, $peerB, $peerB, $peerA);
    }
    if (!$stmt) {
        throw new RuntimeException('Unable to load conversation messages.');
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['chat_message_id'];
    }
    $stmt->close();
    return $ids;
}

function adminChatAuditHandleDelete(mysqli $conn, array $actor, array $data): array
{
    if (!liveChatFeatureEnabled($conn, 'live_chat_admin_delete_enabled', true)) {
        adminChatAuditRespond(['success' => false, 'message' => 'Chat Oversight delete is disabled in Live Chat settings.'], 403);
    }
    $mode = trim(strtolower((string)($data['mode'] ?? 'selected')));
    $reason = (string)($data['reason'] ?? '');
    if ($mode === 'all') {
        $result = $conn->query('SELECT chat_message_id FROM tb_live_chat_messages ORDER BY chat_message_id ASC');
        $messageIds = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $messageIds[] = (int)$row['chat_message_id'];
            }
            $result->free();
        }
    } elseif ($mode === 'conversation') {
        $messageIds = adminChatAuditConversationMessageIds($conn, $data);
    } else {
        $messageIds = $data['message_ids'] ?? [];
        if (!is_array($messageIds)) {
            $messageIds = [$messageIds];
        }
    }
    return adminChatAuditDeleteMessageIds($conn, $actor, $messageIds, $reason);
}

try {
    $actor = adminChatAuditRequireAdmin($conn);
    liveChatEnsureTables($conn);

    $request = $_SERVER['REQUEST_METHOD'] === 'POST' ? liveChatJsonInput() : $_GET;
    $action = trim(strtolower((string)($request['action'] ?? 'conversations')));
    if ($action === 'delete') {
        $payload = adminChatAuditHandleDelete($conn, $actor, is_array($request) ? $request : []);
    } elseif ($action === 'messages') {
        $payload = adminChatAuditLoadMessages($conn);
    } else {
        $payload = adminChatAuditLoadConversations($conn);
    }

    adminChatAuditRespond(['success' => true] + $payload + ['serverTime' => date('Y-m-d H:i:s')]);
} catch (Throwable $error) {
    adminChatAuditRespond([
        'success' => false,
        'message' => 'Unable to load chat oversight data.',
        'error' => $error->getMessage()
    ], 500);
}
