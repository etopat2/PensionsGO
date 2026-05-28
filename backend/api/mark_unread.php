<?php
// 
// mark_unread.php
// Purpose: Mark a message as unread
// 
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

header('Content-Type: application/json');

try {
    $userId = $_SESSION['userId'];
    $input = json_decode(file_get_contents('php://input'), true);
    $messageId = $input['message_id'] ?? null;

    if (!$messageId) {
        throw new Exception("Message ID is required");
    }

    // Update the read status
    $stmt = $conn->prepare("
        UPDATE tb_message_recipients 
        SET is_read = FALSE, read_at = NULL 
        WHERE message_id = ? AND recipient_user_id = ?
    ");
    
    $stmt->bind_param("is", $messageId, $userId);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'message' => 'Message marked as unread'
        ]);
    } else {
        throw new Exception("Failed to mark message as unread");
    }

} catch (Exception $e) {
    error_log("Mark unread error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error marking message as unread'
    ]);
}

$conn->close();
?>
