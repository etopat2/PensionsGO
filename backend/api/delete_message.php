<?php
// 
// delete_message.php
// Purpose: Proper soft deletion that respects foreign key constraints
//          Correct deletion order, proper soft delete logic
// 
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$messageIds = $data["ids"] ?? [];
if (!is_array($messageIds) || empty($messageIds)) {
    echo json_encode(['success' => false, 'message' => 'No messages selected']);
    exit;
}

function ensureMessageDeletionSchema(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $tableColumns = [];
    foreach (['tb_messages', 'tb_message_recipients', 'tb_user_broadcast_status'] as $tableName) {
        $tableColumns[$tableName] = [];
        $result = $conn->query("SHOW COLUMNS FROM {$tableName}");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tableColumns[$tableName][$row['Field']] = true;
            }
            $result->close();
        }
    }

    $requiredColumns = [
        'tb_messages' => [
            'is_deleted_by_sender' => "TINYINT(1) NOT NULL DEFAULT 0",
            'deleted_by_sender_at' => "DATETIME DEFAULT NULL"
        ],
        'tb_message_recipients' => [
            'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
            'deleted_at' => "DATETIME DEFAULT NULL",
            'is_read' => "TINYINT(1) NOT NULL DEFAULT 0",
            'read_at' => "DATETIME DEFAULT NULL"
        ],
        'tb_user_broadcast_status' => [
            'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
            'deleted_at' => "DATETIME DEFAULT NULL",
            'is_seen' => "TINYINT(1) NOT NULL DEFAULT 0",
            'seen_at' => "DATETIME DEFAULT NULL"
        ]
    ];

    foreach ($requiredColumns as $tableName => $columns) {
        foreach ($columns as $column => $definition) {
            if (empty($tableColumns[$tableName][$column])) {
                $conn->query("ALTER TABLE {$tableName} ADD COLUMN {$column} {$definition}");
            }
        }
    }

    $checked = true;
}

function attachmentHashColumnExists($conn): bool {
    static $checked = false;
    static $exists = false;
    if ($checked) {
        return $exists;
    }

    $result = $conn->query("SHOW COLUMNS FROM tb_message_attachments LIKE 'file_hash'");
    $exists = $result && $result->num_rows > 0;
    $checked = true;
    return $exists;
}

// Function to delete physical attachment files
function deleteAttachmentFiles($messageId, $conn) {
    $deletedFiles = 0;
    $dedupeEnabled = function_exists('getAppSettingBool')
        ? getAppSettingBool($conn, 'attachment_dedupe_enabled', false)
        : false;
    $hasHash = attachmentHashColumnExists($conn);
    
    // Get all attachments for this message
    $stmt = $conn->prepare("
        SELECT file_path, file_hash 
        FROM tb_message_attachments 
        WHERE message_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $messageId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $filePath = __DIR__ . '/../' . $row['file_path'];
            $shouldDelete = true;

            if ($dedupeEnabled) {
                if ($hasHash && !empty($row['file_hash'])) {
                    $checkStmt = $conn->prepare("
                        SELECT COUNT(*) AS cnt
                        FROM tb_message_attachments
                        WHERE file_hash = ? AND message_id != ?
                    ");
                    if ($checkStmt) {
                        $checkStmt->bind_param("si", $row['file_hash'], $messageId);
                        $checkStmt->execute();
                        $countRow = $checkStmt->get_result()->fetch_assoc();
                        $checkStmt->close();
                        $shouldDelete = ((int)($countRow['cnt'] ?? 0) === 0);
                    }
                } else {
                    $checkStmt = $conn->prepare("
                        SELECT COUNT(*) AS cnt
                        FROM tb_message_attachments
                        WHERE file_path = ? AND message_id != ?
                    ");
                    if ($checkStmt) {
                        $checkStmt->bind_param("si", $row['file_path'], $messageId);
                        $checkStmt->execute();
                        $countRow = $checkStmt->get_result()->fetch_assoc();
                        $checkStmt->close();
                        $shouldDelete = ((int)($countRow['cnt'] ?? 0) === 0);
                    }
                }
            }

            if ($shouldDelete && file_exists($filePath)) {
                if (unlink($filePath)) {
                    $deletedFiles++;
                }
            }
        }
        $stmt->close();
    }
    
    return $deletedFiles;
}

// Function to completely delete a message and all associated data
function deleteMessageCompletely($messageId, $conn) {
    $deletedFiles = deleteAttachmentFiles($messageId, $conn);
    
    // Delete in correct order to respect foreign key constraints
    // 1. First delete user broadcast status (if broadcast)
    $statusStmt = $conn->prepare("
        DELETE ubs FROM tb_user_broadcast_status ubs
        INNER JOIN tb_broadcast_messages bm ON ubs.broadcast_id = bm.broadcast_id
        WHERE bm.message_id = ?
    ");
    if ($statusStmt) {
        $statusStmt->bind_param("i", $messageId);
        $statusStmt->execute();
        $statusStmt->close();
    }
    
    // 2. Delete broadcast messages record (if exists)
    $broadcastStmt = $conn->prepare("DELETE FROM tb_broadcast_messages WHERE message_id = ?");
    if ($broadcastStmt) {
        $broadcastStmt->bind_param("i", $messageId);
        $broadcastStmt->execute();
        $broadcastStmt->close();
    }
    
    // 3. Delete attachments
    $attachmentStmt = $conn->prepare("DELETE FROM tb_message_attachments WHERE message_id = ?");
    if ($attachmentStmt) {
        $attachmentStmt->bind_param("i", $messageId);
        $attachmentStmt->execute();
        $attachmentStmt->close();
    }
    
    // 4. Delete recipients
    $recipientStmt = $conn->prepare("DELETE FROM tb_message_recipients WHERE message_id = ?");
    if ($recipientStmt) {
        $recipientStmt->bind_param("i", $messageId);
        $recipientStmt->execute();
        $recipientStmt->close();
    }
    
    // 5. Finally delete the message itself
    $messageStmt = $conn->prepare("DELETE FROM tb_messages WHERE message_id = ?");
    if ($messageStmt) {
        $messageStmt->bind_param("i", $messageId);
        $messageStmt->execute();
        $messageStmt->close();
    }
    
    return $deletedFiles;
}

// Function to check if message should be completely deleted
function shouldDeleteCompletely($messageId, $conn) {
    // Check if sender has deleted the message
    $senderStmt = $conn->prepare("
        SELECT is_deleted_by_sender 
        FROM tb_messages 
        WHERE message_id = ?
    ");
    $senderStmt->bind_param("i", $messageId);
    $senderStmt->execute();
    $senderResult = $senderStmt->get_result();
    $senderData = $senderResult->fetch_assoc();
    $senderStmt->close();
    
    $senderDeleted = $senderData['is_deleted_by_sender'] ?? false;
    
    // Check if there are any active recipients left
    $recipientStmt = $conn->prepare("
        SELECT COUNT(*) as active_recipients
        FROM tb_message_recipients 
        WHERE message_id = ? AND is_deleted = FALSE
    ");
    $recipientStmt->bind_param("i", $messageId);
    $recipientStmt->execute();
    $recipientResult = $recipientStmt->get_result();
    $recipientData = $recipientResult->fetch_assoc();
    $recipientStmt->close();
    
    $activeRecipients = $recipientData['active_recipients'] ?? 0;
    
    // Message should be completely deleted only if:
    // 1. Sender has deleted it AND there are no active recipients left
    return ($senderDeleted && $activeRecipients == 0);
}

try {
    $userId = $_SESSION['userId'];
    $userRole = getSessionEffectiveRoleKey($conn);
    $allowSoftDelete = getAppSettingBool($conn, 'message_allow_soft_delete', true);
    ensureMessageDeletionSchema($conn);
    if (getAppSettingBool($conn, 'message_backup_enabled', false)) {
        createMessageStorageSnapshot($conn, [
            'snapshot_type' => 'manual',
            'notes' => 'Snapshot captured before message deletion workflow.',
            'created_by' => $_SESSION['userId'] ?? null,
            'created_by_name' => $_SESSION['userName'] ?? null,
            'created_by_role' => $_SESSION['userRole'] ?? null
        ]);
    }
    $conn->begin_transaction();

    // Validate and cast to integers
    $validIds = [];
    foreach ($messageIds as $mid) {
        $id = intval($mid);
        if ($id > 0) $validIds[] = $id;
    }
    if (empty($validIds)) {
        throw new Exception('No valid message IDs provided');
    }

    // Create placeholders for prepared statement
    $placeholders = str_repeat('?,', count($validIds) - 1) . '?';
    
    // Check message details including broadcast info
    $checkStmt = $conn->prepare("
        SELECT 
            m.message_id, 
            m.sender_id, 
            m.message_type,
            mr.recipient_user_id,
            bm.broadcast_id
        FROM tb_messages m
        LEFT JOIN tb_message_recipients mr ON m.message_id = mr.message_id AND mr.recipient_user_id = ?
        LEFT JOIN tb_broadcast_messages bm ON m.message_id = bm.message_id
        WHERE m.message_id IN ($placeholders)
    ");
    
    $types = str_repeat('i', count($validIds));
    $bindParams = [];
    $bindParams[] = "s" . $types;
    $paramValues = array_merge([$userId], $validIds);
    foreach ($paramValues as $key => $value) {
        $bindParams[] = &$paramValues[$key];
    }
    call_user_func_array([$checkStmt, 'bind_param'], $bindParams);
    $checkStmt->execute();
    $messages = $checkStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $checkStmt->close();

    $completelyDeletedMessages = 0;
    $deletedAttachments = 0;

    foreach ($messages as $message) {
        $messageId = $message['message_id'];
        $isBroadcast = !empty($message['broadcast_id']);
        $isSender = ($message['sender_id'] === $userId);
        
        // Handle broadcast deletion
        if ($isBroadcast) {
            if ($userRole === 'admin') {
                // FIXED: Admin should completely delete broadcast and all associated data
                $attachmentsDeleted = deleteMessageCompletely($messageId, $conn);
                $deletedAttachments += $attachmentsDeleted;
                $completelyDeletedMessages++;
                
            } else {
                if ($allowSoftDelete) {
                    // Non-admin users mark broadcast as deleted for themselves only
                    $userBroadcastStmt = $conn->prepare("
                        INSERT INTO tb_user_broadcast_status (user_id, broadcast_id, is_seen, seen_at, is_deleted)
                        VALUES (?, ?, TRUE, NOW(), TRUE)
                        ON DUPLICATE KEY UPDATE is_deleted = TRUE, deleted_at = NOW()
                    ");
                    $userBroadcastStmt->bind_param("si", $userId, $message['broadcast_id']);
                    $userBroadcastStmt->execute();
                    $userBroadcastStmt->close();
                } else {
                    // Hard delete recipient view for broadcast
                    $deleteRecipientStmt = $conn->prepare("
                        DELETE FROM tb_message_recipients
                        WHERE message_id = ? AND recipient_user_id = ?
                    ");
                    if ($deleteRecipientStmt) {
                        $deleteRecipientStmt->bind_param("is", $messageId, $userId);
                        $deleteRecipientStmt->execute();
                        $deleteRecipientStmt->close();
                    }

                    $deleteStatusStmt = $conn->prepare("
                        DELETE FROM tb_user_broadcast_status
                        WHERE user_id = ? AND broadcast_id = ?
                    ");
                    if ($deleteStatusStmt) {
                        $deleteStatusStmt->bind_param("si", $userId, $message['broadcast_id']);
                        $deleteStatusStmt->execute();
                        $deleteStatusStmt->close();
                    }
                }
            }
        }
        // Handle regular message deletion
        else {
            if ($isSender) {
                // Sender marks message as deleted for themselves
                $senderDelStmt = $conn->prepare("
                    UPDATE tb_messages 
                    SET is_deleted_by_sender = TRUE, deleted_by_sender_at = NOW()
                    WHERE message_id = ? AND sender_id = ?
                ");
                $senderDelStmt->bind_param("is", $messageId, $userId);
                $senderDelStmt->execute();
                
                if ($senderDelStmt->affected_rows > 0) {
                    // Check if all recipients have also deleted this message
                    $checkRecipientsStmt = $conn->prepare("
                        SELECT COUNT(*) as remaining_recipients
                        FROM tb_message_recipients 
                        WHERE message_id = ? AND is_deleted = FALSE
                    ");
                    $checkRecipientsStmt->bind_param("i", $messageId);
                    $checkRecipientsStmt->execute();
                    $result = $checkRecipientsStmt->get_result();
                    $recipientData = $result->fetch_assoc();
                    $checkRecipientsStmt->close();
                    
                    $remainingRecipients = $recipientData['remaining_recipients'] ?? 0;
                    
                    // If no recipients left, delete completely
                    if ($remainingRecipients == 0) {
                        $attachmentsDeleted = deleteMessageCompletely($messageId, $conn);
                        $deletedAttachments += $attachmentsDeleted;
                        $completelyDeletedMessages++;
                    }
                }
                $senderDelStmt->close();
                
            } else {
                if ($allowSoftDelete) {
                    // Recipient marks message as deleted for themselves ONLY
                    // This should NOT affect the sender's view of the message
                    $recipientDelStmt = $conn->prepare("
                        UPDATE tb_message_recipients
                        SET is_deleted = TRUE, deleted_at = NOW()
                        WHERE message_id = ? AND recipient_user_id = ?
                    ");
                    $recipientDelStmt->bind_param("is", $messageId, $userId);
                    $recipientDelStmt->execute();
                    
                    if ($recipientDelStmt->affected_rows > 0) {
                        // FIXED: Check if we should delete the message completely
                        // This should only happen if sender has ALSO deleted AND no other recipients remain
                        if (shouldDeleteCompletely($messageId, $conn)) {
                            $attachmentsDeleted = deleteMessageCompletely($messageId, $conn);
                            $deletedAttachments += $attachmentsDeleted;
                            $completelyDeletedMessages++;
                        }
                    }
                    $recipientDelStmt->close();
                } else {
                    $recipientDelStmt = $conn->prepare("
                        DELETE FROM tb_message_recipients
                        WHERE message_id = ? AND recipient_user_id = ?
                    ");
                    $recipientDelStmt->bind_param("is", $messageId, $userId);
                    $recipientDelStmt->execute();
                    
                    if ($recipientDelStmt->affected_rows > 0) {
                        if (shouldDeleteCompletely($messageId, $conn)) {
                            $attachmentsDeleted = deleteMessageCompletely($messageId, $conn);
                            $deletedAttachments += $attachmentsDeleted;
                            $completelyDeletedMessages++;
                        }
                    }
                    $recipientDelStmt->close();
                }
            }
        }
    }

    $conn->commit();
    
    $message = 'Message(s) deleted successfully';
    if ($completelyDeletedMessages > 0) {
        $message .= " ($completelyDeletedMessages message(s) completely removed from database)";
    }
    if ($deletedAttachments > 0) {
        $message .= " ($deletedAttachments attachment(s) removed)";
    }
    
    echo json_encode(['success' => true, 'message' => $message]);
    
} catch (Exception $e) {
    $conn->rollback();
    error_log("delete_message error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
$conn->close();
?>

