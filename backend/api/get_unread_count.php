<?php
// 
// get_unread_count.php
// Purpose: Get unread message count for header display (Admin + Users)
// Fixed: Unread counts now properly track recipient reads instead of sender
// 
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['userId'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    echo json_encode([
        'success' => true,
        'unread_count' => 0,
        'direct_unread' => 0,
        'broadcast_unread' => 0
    ]);
    exit;
}

try {
    $userId = $_SESSION['userId'];
    $userRole = getSessionEffectiveRoleKey($conn);

    // Count unread direct or group messages (FIXED: Only count messages where user is recipient)
    $directStmt = $conn->prepare("
        SELECT COUNT(*) AS count
        FROM tb_message_recipients mr
        INNER JOIN tb_messages m ON mr.message_id = m.message_id
        WHERE mr.recipient_user_id = ?
          AND mr.is_read = FALSE
          AND mr.is_deleted = FALSE
          AND m.message_type IN ('direct', 'group')
    ");
    $directStmt->bind_param("s", $userId);
    $directStmt->execute();
    $directCount = $directStmt->get_result()->fetch_assoc()['count'] ?? 0;
    $directStmt->close();

    // Count unread broadcasts (only if not admin)
    $broadcastCount = 0;
    if (strtolower($userRole) !== 'admin') {
        $broadcastStmt = $conn->prepare("
            SELECT COUNT(*) AS count
            FROM tb_broadcast_messages bm
            INNER JOIN tb_messages m ON bm.message_id = m.message_id
            LEFT JOIN tb_user_broadcast_status ubs
                ON bm.broadcast_id = ubs.broadcast_id AND ubs.user_id = ?
            WHERE bm.is_active = TRUE
              AND (ubs.is_seen IS NULL OR ubs.is_seen = FALSE)
        ");
        $broadcastStmt->bind_param("s", $userId);
        $broadcastStmt->execute();
        $broadcastCount = $broadcastStmt->get_result()->fetch_assoc()['count'] ?? 0;
        $broadcastStmt->close();
    }

    $totalUnread = (int)$directCount + (int)$broadcastCount;

    echo json_encode([
        'success' => true,
        'unread_count' => $totalUnread,
        'direct_unread' => (int)$directCount,
        'broadcast_unread' => (int)$broadcastCount
    ]);
} catch (Exception $e) {
    error_log("Unread count error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error fetching unread count']);
}

$conn->close();
?>
