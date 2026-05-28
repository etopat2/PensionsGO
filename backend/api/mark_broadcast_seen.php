<?php
// 
// mark_broadcast_seen.php
// Purpose: Mark a broadcast as seen for the currently logged in user
// 
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['userId'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    $userId = $_SESSION['userId'];
    $input = json_decode(file_get_contents('php://input'), true);

    $broadcastId = $input['broadcast_id'] ?? null;
    $messageId = $input['message_id'] ?? null;

    if (!$broadcastId && !$messageId) {
        throw new Exception('broadcast_id or message_id is required');
    }

    // If message_id provided, resolve broadcast_id
    if (!$broadcastId && $messageId) {
        $stmt = $conn->prepare("SELECT bm.broadcast_id FROM tb_broadcast_messages bm WHERE bm.message_id = ? LIMIT 1");
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$res || empty($res['broadcast_id'])) {
            throw new Exception('Broadcast not found for provided message_id');
        }
        $broadcastId = (int)$res['broadcast_id'];
    } else {
        $broadcastId = (int)$broadcastId;
    }

    // Insert or update seen status
    $stmt = $conn->prepare("
        INSERT INTO tb_user_broadcast_status (user_id, broadcast_id, is_seen, seen_at)
        VALUES (?, ?, TRUE, NOW())
        ON DUPLICATE KEY UPDATE is_seen = TRUE, seen_at = NOW()
    ");
    $stmt->bind_param("si", $userId, $broadcastId);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to mark broadcast as seen: " . $stmt->error);
    }
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Broadcast marked as seen']);
} catch (Exception $e) {
    error_log("mark_broadcast_seen error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>
