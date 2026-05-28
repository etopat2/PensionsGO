<?php
// 
// get_storage_usage.php
// Purpose: Calculate user's storage usage for messages and attachments
// 
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

try {
    $userId = $_SESSION['userId'];
    $quotaRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'message_storage_quota_mb') : null;
    $quotaMb = is_numeric($quotaRaw) ? (int)$quotaRaw : 100;
    if ($quotaMb <= 0) {
        $quotaMb = 100;
    }
    $maxStorage = $quotaMb * 1024 * 1024;
    
    // Calculate storage used by attachments
    $attachmentStmt = $conn->prepare("
        SELECT COALESCE(SUM(file_size), 0) as attachment_size
        FROM tb_message_attachments att
        INNER JOIN tb_messages m ON att.message_id = m.message_id
        WHERE m.sender_id = ?
        AND (m.is_deleted_by_sender = FALSE OR m.is_deleted_by_sender IS NULL)
    ");
    $attachmentStmt->bind_param("s", $userId);
    $attachmentStmt->execute();
    $attachmentResult = $attachmentStmt->get_result();
    $attachmentData = $attachmentResult->fetch_assoc();
    $attachmentStmt->close();
    
    $attachmentSize = $attachmentData['attachment_size'] ?? 0;
    
    // Calculate storage used by message text (estimate ~1 byte per character)
    $messageStmt = $conn->prepare("
        SELECT COALESCE(SUM(LENGTH(message_text)), 0) as message_size
        FROM tb_messages 
        WHERE sender_id = ?
        AND (is_deleted_by_sender = FALSE OR is_deleted_by_sender IS NULL)
    ");
    $messageStmt->bind_param("s", $userId);
    $messageStmt->execute();
    $messageResult = $messageStmt->get_result();
    $messageData = $messageResult->fetch_assoc();
    $messageStmt->close();
    
    $messageSize = $messageData['message_size'] ?? 0;
    
    // Total storage used (attachments + message text)
    $totalUsed = $attachmentSize + $messageSize;
    $usagePercentage = min(100, ($totalUsed / $maxStorage) * 100);
    
    echo json_encode([
        'success' => true,
        'storage' => [
            'used_bytes' => (int)$totalUsed,
            'max_bytes' => $maxStorage,
            'used_mb' => round($totalUsed / (1024 * 1024), 2),
            'max_mb' => $quotaMb,
            'percentage' => round($usagePercentage, 1),
            'remaining_mb' => round(($maxStorage - $totalUsed) / (1024 * 1024), 2)
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Storage usage error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error calculating storage usage'
    ]);
}

$conn->close();
?>

