<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $csrfToken = function_exists('getSessionCsrfToken') ? getSessionCsrfToken() : '';
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatPromoteRealtimeDefaults($conn);
    liveChatTouchPresence($conn, $userId);
    liveChatReleaseSessionLock();

    $currentUserName = 'You';
    $currentStmt = $conn->prepare("SELECT userName FROM tb_users WHERE userId = ? LIMIT 1");
    if ($currentStmt) {
        $currentStmt->bind_param('s', $userId);
        $currentStmt->execute();
        $currentRow = $currentStmt->get_result()->fetch_assoc();
        $currentUserName = trim((string)($currentRow['userName'] ?? '')) ?: 'You';
        $currentStmt->close();
    }

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
          AND LOWER(REPLACE(REPLACE(COALESCE(u.userRole, ''), ' ', '_'), '-', '_')) NOT IN ('super_admin', 'superadministrator', 'system_administrator')
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

    $deliveredStmt = $conn->prepare("
        UPDATE tb_live_chat_messages
        SET delivered_at = COALESCE(delivered_at, NOW())
        WHERE recipient_id = ?
          AND sender_id <> ?
          AND delivered_at IS NULL
          AND deleted_at IS NULL
          AND admin_deleted_at IS NULL
    ");
    if ($deliveredStmt) {
        $deliveredStmt->bind_param('ss', $userId, $userId);
        $deliveredStmt->execute();
        $deliveredStmt->close();
    }

    $unreadSnapshot = liveChatUnreadSnapshot($conn, $userId);
    $unreadByUser = $unreadSnapshot['users'];
    $unreadByGroup = $unreadSnapshot['groups'];

    foreach ($users as &$user) {
        $user['unreadCount'] = $unreadByUser[(string)$user['userId']] ?? 0;
    }
    unset($user);
    foreach ($groups as &$group) {
        $group['unreadCount'] = $unreadByGroup[(string)$group['groupId']] ?? 0;
    }
    unset($group);

    $totalUnread = (int)$unreadSnapshot['total'];

    liveChatRespond([
        'success' => true,
        'currentUserId' => $userId,
        'currentUserName' => $currentUserName,
        'csrfToken' => $csrfToken,
        'users' => $users,
        'groups' => liveChatFeatureEnabled($conn, 'live_chat_group_chats_enabled', true) ? $groups : [],
        'unreadTotal' => $totalUnread,
        'featureSettings' => [
            'enabled' => true,
            'groupsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_group_chats_enabled', true),
            'audioCallsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_audio_calls_enabled', true),
            'videoCallsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_video_calls_enabled', true),
            'addParticipantsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_add_participants_enabled', true),
            'attachmentsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_attachments_enabled', true),
            'voiceNotesEnabled' => liveChatFeatureEnabled($conn, 'live_chat_voice_notes_enabled', true),
            'pollsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_polls_enabled', true),
            'typingPresenceEnabled' => liveChatFeatureEnabled($conn, 'live_chat_typing_presence_enabled', true),
            'readReceiptsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_read_receipts_enabled', true),
            'draftsEnabled' => liveChatFeatureEnabled($conn, 'live_chat_drafts_enabled', true),
            'typingIdleSeconds' => liveChatSettingInt($conn, 'live_chat_typing_idle_seconds', 5, 2, 30),
            'messagePollMs' => liveChatSettingInt($conn, 'live_chat_message_poll_ms', 1000, 750, 5000),
            'receiptPollMs' => liveChatSettingInt($conn, 'live_chat_receipt_poll_ms', 1500, 1000, 5000),
            'callPollMs' => liveChatSettingInt($conn, 'live_chat_call_poll_ms', 1000, 750, 10000),
            'signalPollMs' => liveChatSettingInt($conn, 'live_chat_signal_poll_ms', 350, 200, 5000)
        ],
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
