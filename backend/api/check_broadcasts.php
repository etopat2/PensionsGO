<?php
// 
// check_broadcasts.php 
// 
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    echo json_encode([
        'success' => true,
        'has_new' => false,
        'latest_broadcast' => null
    ]);
    exit;
}

try {
    $userId = $_SESSION['userId'];
    $userRole = $_SESSION['userRole'] ?? '';

    $stmt = $conn->prepare("
        SELECT 
            bm.broadcast_id,
            m.message_id,
            m.subject,
            SUBSTRING(m.message_text, 1, 500) AS message_preview,
            m.created_at,
            u.userName AS sender_name,
            bm.target_roles
        FROM tb_broadcast_messages bm
        INNER JOIN tb_messages m ON bm.message_id = m.message_id
        INNER JOIN tb_users u ON m.sender_id = u.userId
        LEFT JOIN tb_user_broadcast_status ubs ON (bm.broadcast_id = ubs.broadcast_id AND ubs.user_id = ?)
        WHERE bm.is_active = TRUE
        AND (ubs.is_seen IS NULL OR ubs.is_seen = FALSE)
        AND m.sender_id <> ?
        AND (bm.target_roles IS NULL OR JSON_CONTAINS(bm.target_roles, ?))
        ORDER BY m.created_at DESC
        LIMIT 1
    ");

    $roleJson = json_encode($userRole);
    $stmt->bind_param("sss", $userId, $userId, $roleJson);
    $stmt->execute();
    $result = $stmt->get_result();

    $broadcast = $result->fetch_assoc();

    echo json_encode([
        'success' => true,
        'has_new' => $broadcast ? true : false,
        'latest_broadcast' => $broadcast
    ]);
} catch (Exception $e) {
    error_log("Error checking broadcasts: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

