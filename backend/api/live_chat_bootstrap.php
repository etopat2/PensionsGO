<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);

    $stmt = $conn->prepare("
        SELECT
            u.userId,
            u.userName,
            u.userEmail,
            u.phoneNo,
            u.userRole,
            u.userPhoto,
            p.status,
            p.last_seen,
            CASE WHEN p.last_seen >= DATE_SUB(NOW(), INTERVAL 45 SECOND) THEN 1 ELSE 0 END AS is_online
        FROM tb_users u
        LEFT JOIN tb_live_chat_presence p ON p.user_id = u.userId
        WHERE u.userId <> ?
          AND u.userRole NOT IN ('pensioner', 'user')
        ORDER BY is_online DESC, u.userName ASC
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to load live chat users.');
    }
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'userId' => $row['userId'],
            'userName' => $row['userName'],
            'userEmail' => $row['userEmail'],
            'phoneNo' => $row['phoneNo'] ?? '',
            'userRole' => $row['userRole'],
            'userRoleLabel' => function_exists('formatRoleLabel') ? formatRoleLabel($conn, (string)$row['userRole']) : ucwords(str_replace('_', ' ', (string)$row['userRole'])),
            'userPhoto' => $row['userPhoto'] ?: 'images/default-user.png',
            'isOnline' => (int)$row['is_online'] === 1,
            'lastSeen' => $row['last_seen'] ?? null,
            'status' => $row['status'] ?? 'offline'
        ];
    }
    $stmt->close();

    $groupStmt = $conn->prepare("
        SELECT
            g.group_id,
            g.group_name,
            g.created_by,
            g.created_at,
            COUNT(m.user_id) AS member_count
        FROM tb_live_chat_groups g
        INNER JOIN tb_live_chat_group_members gm ON gm.group_id = g.group_id AND gm.user_id = ?
        LEFT JOIN tb_live_chat_group_members m ON m.group_id = g.group_id
        GROUP BY g.group_id, g.group_name, g.created_by, g.created_at
        ORDER BY g.created_at DESC
    ");
    if (!$groupStmt) {
        throw new RuntimeException('Unable to load live chat groups.');
    }
    $groupStmt->bind_param('s', $userId);
    $groupStmt->execute();
    $groupResult = $groupStmt->get_result();

    $groups = [];
    while ($row = $groupResult->fetch_assoc()) {
        $groups[] = [
            'groupId' => $row['group_id'],
            'groupName' => $row['group_name'],
            'createdBy' => $row['created_by'],
            'isAdmin' => $row['created_by'] === $userId,
            'createdAt' => $row['created_at'],
            'memberCount' => (int)($row['member_count'] ?? 0)
        ];
    }
    $groupStmt->close();

    $unreadByUser = [];
    $directUnreadStmt = $conn->prepare("
        SELECT m.sender_id, COUNT(*) AS unread_count
        FROM tb_live_chat_messages m
        LEFT JOIN tb_live_chat_message_reads r
          ON r.chat_message_id = m.chat_message_id AND r.user_id = ?
        WHERE m.recipient_id = ?
          AND m.sender_id <> ?
          AND m.deleted_at IS NULL
          AND m.is_read = 0
          AND r.chat_message_id IS NULL
        GROUP BY m.sender_id
    ");
    if ($directUnreadStmt) {
        $directUnreadStmt->bind_param('sss', $userId, $userId, $userId);
        $directUnreadStmt->execute();
        $directUnreadResult = $directUnreadStmt->get_result();
        while ($row = $directUnreadResult->fetch_assoc()) {
            $unreadByUser[(string)$row['sender_id']] = (int)$row['unread_count'];
        }
        $directUnreadStmt->close();
    }

    $unreadByGroup = [];
    $groupUnreadStmt = $conn->prepare("
        SELECT m.recipient_id AS group_id, COUNT(*) AS unread_count
        FROM tb_live_chat_messages m
        INNER JOIN tb_live_chat_group_members gm
          ON gm.group_id = m.recipient_id AND gm.user_id = ?
        LEFT JOIN tb_live_chat_message_reads r
          ON r.chat_message_id = m.chat_message_id AND r.user_id = ?
        WHERE m.sender_id <> ?
          AND m.deleted_at IS NULL
          AND r.chat_message_id IS NULL
        GROUP BY m.recipient_id
    ");
    if ($groupUnreadStmt) {
        $groupUnreadStmt->bind_param('sss', $userId, $userId, $userId);
        $groupUnreadStmt->execute();
        $groupUnreadResult = $groupUnreadStmt->get_result();
        while ($row = $groupUnreadResult->fetch_assoc()) {
            $unreadByGroup[(string)$row['group_id']] = (int)$row['unread_count'];
        }
        $groupUnreadStmt->close();
    }

    foreach ($users as &$user) {
        $user['unreadCount'] = $unreadByUser[(string)$user['userId']] ?? 0;
    }
    unset($user);
    foreach ($groups as &$group) {
        $group['unreadCount'] = $unreadByGroup[(string)$group['groupId']] ?? 0;
    }
    unset($group);

    $totalUnread = array_sum($unreadByUser) + array_sum($unreadByGroup);

    liveChatRespond([
        'success' => true,
        'currentUserId' => $userId,
        'users' => $users,
        'groups' => $groups,
        'unreadTotal' => $totalUnread,
        'messageSettings' => [
            'messageSoundEnabled' => getAppSettingBool($conn, 'live_message_sound_enabled', true),
            'desktopAlertsEnabled' => getAppSettingBool($conn, 'live_message_desktop_alerts_enabled', true),
            'messageSoundPath' => getAppSettingString($conn, 'live_message_sound_path', 'audio/notification.mp3'),
            'messageVolume' => max(0, min(100, getAppSettingInt($conn, 'live_message_sound_volume', 70))),
            'messageRepeatCount' => max(1, min(5, getAppSettingInt($conn, 'live_message_sound_repeat_count', 1)))
        ],
        'callSettings' => [
            'incomingSoundEnabled' => getAppSettingBool($conn, 'live_call_incoming_sound_enabled', true),
            'outgoingSoundEnabled' => getAppSettingBool($conn, 'live_call_outgoing_sound_enabled', true),
            'desktopAlertsEnabled' => getAppSettingBool($conn, 'live_call_desktop_alerts_enabled', true),
            'incomingSoundPath' => getAppSettingString($conn, 'live_call_incoming_sound_path', 'audio/notification.mp3'),
            'outgoingSoundPath' => getAppSettingString($conn, 'live_call_outgoing_sound_path', 'audio/notification.mp3'),
            'incomingVolume' => max(0, min(100, getAppSettingInt($conn, 'live_call_incoming_sound_volume', 85))),
            'outgoingVolume' => max(0, min(100, getAppSettingInt($conn, 'live_call_outgoing_sound_volume', 55))),
            'incomingRepeatCount' => max(0, min(10, getAppSettingInt($conn, 'live_call_incoming_sound_repeat_count', 0))),
            'outgoingRepeatCount' => max(0, min(10, getAppSettingInt($conn, 'live_call_outgoing_sound_repeat_count', 0))),
            'ringingTimeoutSeconds' => max(10, min(300, getAppSettingInt($conn, 'live_call_ringing_timeout_seconds', 45)))
        ],
        'serverTime' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage(), 'users' => []]);
}
