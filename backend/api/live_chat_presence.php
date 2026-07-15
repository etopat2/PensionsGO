<?php
require_once __DIR__ . '/live_chat_common.php';

try {
    $userId = liveChatRequireStaff($conn);
    liveChatEnsureTables($conn);
    liveChatTouchPresence($conn, $userId);
    liveChatReleaseSessionLock();

    $result = $conn->query("
        SELECT user_id, status, last_seen,
               CASE WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 45 SECOND) THEN 1 ELSE 0 END AS is_online
        FROM tb_live_chat_presence
    ");
    if (!$result) {
        throw new RuntimeException('Unable to load live chat presence.');
    }

    $presence = [];
    while ($row = $result->fetch_assoc()) {
        $presence[$row['user_id']] = [
            'status' => ((int)$row['is_online'] === 1) ? ($row['status'] ?: 'online') : 'offline',
            'isOnline' => (int)$row['is_online'] === 1,
            'lastSeen' => $row['last_seen']
        ];
    }

    $unreadSnapshot = liveChatUnreadSnapshot($conn, $userId);

    liveChatRespond([
        'success' => true,
        'presence' => $presence,
        'unreadTotal' => (int)$unreadSnapshot['total'],
        'unreadUsers' => $unreadSnapshot['users'],
        'unreadGroups' => $unreadSnapshot['groups'],
        'serverTime' => date('Y-m-d H:i:s')
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    liveChatRespond(['success' => false, 'message' => $e->getMessage(), 'presence' => []]);
}
