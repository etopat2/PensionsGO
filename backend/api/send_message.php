<?php
// 
// send_message.php
// Admin-only broadcast, exclude sender from recipients, security validation
// Support for custom file names and enhanced recipient handling
// 
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

// Set header first to ensure JSON response
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

function getAttachmentSettings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $maxSizeRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'attachment_max_size_mb') : null;
    $maxSizeMb = is_numeric($maxSizeRaw) ? (int)$maxSizeRaw : 10;
    if ($maxSizeMb <= 0) {
        $maxSizeMb = 10;
    }

    $allowedRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'attachment_allowed_types') : null;
    $allowedList = array_filter(array_map('trim', explode(',', strtolower((string)$allowedRaw))));
    if (empty($allowedList)) {
        $allowedList = ['jpg','jpeg','png','gif','pdf','doc','docx','xls','xlsx','txt','zip'];
    }

    $mimeMap = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'csv' => ['text/csv', 'application/vnd.ms-excel']
    ];

    $cache = [
        'max_bytes' => $maxSizeMb * 1024 * 1024,
        'allowed_extensions' => $allowedList,
        'mime_map' => $mimeMap,
        'dedupe_enabled' => getAppSettingBool($conn, 'attachment_dedupe_enabled', false),
        'compress_enabled' => getAppSettingBool($conn, 'attachment_compress_enabled', false)
    ];

    return $cache;
}

function ensureAttachmentMetadataColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM tb_message_attachments");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
    }

    if (!isset($columns['file_hash'])) {
        $conn->query("ALTER TABLE tb_message_attachments ADD COLUMN file_hash varchar(64) DEFAULT NULL");
    }
    if (!isset($columns['is_compressed'])) {
        $conn->query("ALTER TABLE tb_message_attachments ADD COLUMN is_compressed tinyint(1) DEFAULT 0");
    }

    $checked = true;
}

function compressAttachmentFile(string $filePath, string $mimeType): bool {
    if (!file_exists($filePath)) {
        return false;
    }

    $compressed = false;
    if ($mimeType === 'image/jpeg') {
        $image = imagecreatefromjpeg($filePath);
        if ($image) {
            $compressed = imagejpeg($image, $filePath, 80);
            imagedestroy($image);
        }
    } elseif ($mimeType === 'image/png') {
        $image = imagecreatefrompng($filePath);
        if ($image) {
            $compressed = imagepng($image, $filePath, 6);
            imagedestroy($image);
        }
    }

    return $compressed;
}

function handleFileUpload($file, $messageId, $conn, $customName = null) {
    // Check if file upload exists and has no errors
    if (!isset($file['name']) || empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $uploadDir = __DIR__ . '/../uploads/messages/';
    ensureUploadDirectoryGuard($uploadDir);

    // Security: sanitize filename
    $settings = getAttachmentSettings($conn);
    $allowedExtensions = $settings['allowed_extensions'];
    $mimeMap = $settings['mime_map'];
    $maxSizeBytes = $settings['max_bytes'];
    $dedupeEnabled = $settings['dedupe_enabled'];
    $compressEnabled = $settings['compress_enabled'];
    ensureAttachmentMetadataColumns($conn);
    $validated = assertUploadedFileIsSafe($conn, $file, $allowedExtensions, [], 'Message attachment');
    $originalName = (string)$validated['original_name'];
    $safeName = sanitizeUploadedFileName($originalName, 'attachment.' . (string)$validated['extension']);
    $fileName = time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
    $filePath = $uploadDir . $fileName;

    // Use custom name if provided, otherwise use original name
    $displayName = $customName ?: $originalName;
    
    $fileExtension = (string)$validated['extension'];
    if (!empty($allowedExtensions) && !in_array($fileExtension, $allowedExtensions, true)) {
        throw new Exception("File type not allowed. Allowed: " . implode(', ', $allowedExtensions));
    }

    $mimeType = (string)$validated['mime_type'];

    if (isset($mimeMap[$fileExtension]) && !in_array($mimeType, $mimeMap[$fileExtension], true)) {
        throw new Exception("File type not allowed: " . $mimeType);
    }

    // Check file size (from settings)
    if ($file['size'] > $maxSizeBytes) {
        $maxMb = round($maxSizeBytes / (1024 * 1024), 2);
        throw new Exception("File size too large. Maximum {$maxMb}MB allowed.");
    }

    $fileHash = (string)$validated['file_hash'];
    $scanResult = runVirusScanOnFile($conn, (string)$validated['tmp_name'], [
        'storage_context' => 'message_attachment',
        'file_name' => $originalName,
        'file_path' => null,
        'mime_type' => $mimeType,
        'scanned_by' => $_SESSION['userId'] ?? null,
        'scanned_by_name' => $_SESSION['userName'] ?? null,
        'scanned_by_role' => $_SESSION['userRole'] ?? null
    ]);
    if (($scanResult['enabled'] ?? true) && in_array(($scanResult['status'] ?? ''), ['infected', 'suspicious', 'error'], true)) {
        $reason = trim((string)($scanResult['findings'] ?? 'Upload blocked by file scanning policy.'));
        throw new Exception($reason !== '' ? $reason : 'Attachment failed the configured virus scan.');
    }
    if ($dedupeEnabled && $fileHash) {
        $dupStmt = $conn->prepare("
            SELECT file_path, file_size, mime_type, is_compressed
            FROM tb_message_attachments
            WHERE file_hash = ?
            LIMIT 1
        ");
        if ($dupStmt) {
            $dupStmt->bind_param("s", $fileHash);
            $dupStmt->execute();
            $dupResult = $dupStmt->get_result();
            if ($dupRow = $dupResult->fetch_assoc()) {
                $dupStmt->close();
                $existingPath = $dupRow['file_path'];
                $absolutePath = __DIR__ . '/../' . $existingPath;
                if (file_exists($absolutePath)) {
                    $stmt = $conn->prepare("
                        INSERT INTO tb_message_attachments 
                        (message_id, file_name, file_path, file_size, mime_type, file_hash, is_compressed) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    if (!$stmt) {
                        throw new Exception("Prepare failed: " . $conn->error);
                    }
                    $isCompressed = (int)($dupRow['is_compressed'] ?? 0);
                    $stmt->bind_param(
                        "ississi",
                        $messageId,
                        $displayName,
                        $existingPath,
                        $dupRow['file_size'],
                        $dupRow['mime_type'],
                        $fileHash,
                        $isCompressed
                    );
                    if (!$stmt->execute()) {
                        throw new Exception("Failed to save attachment to database: " . $stmt->error);
                    }
                    $stmt->close();
                    return true;
                }
            } else {
                $dupStmt->close();
            }
        }
    }

    // Move uploaded file
    if (!move_uploaded_file((string)$validated['tmp_name'], $filePath)) {
        throw new Exception("Failed to move uploaded file");
    }

    $isCompressed = 0;
    if ($compressEnabled) {
        if (compressAttachmentFile($filePath, $mimeType)) {
            $isCompressed = 1;
            $file['size'] = filesize($filePath) ?: $file['size'];
        }
    }

    // Insert into database
    $stmt = $conn->prepare("
        INSERT INTO tb_message_attachments 
        (message_id, file_name, file_path, file_size, mime_type, file_hash, is_compressed) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    if (!$stmt) {
        unlink($filePath);
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $relativePath = 'uploads/messages/' . $fileName;
    $stmt->bind_param("ississi", $messageId, $displayName, $relativePath, $file['size'], $mimeType, $fileHash, $isCompressed);
    
    if (!$stmt->execute()) {
        unlink($filePath);
        throw new Exception("Failed to save attachment to database: " . $stmt->error);
    }
    
    return true;
}

try {
    $userId = $_SESSION['userId'];
    $userRole = getSessionEffectiveRoleKey($conn);
    
    // Determine input method
    $messageData = [];
    $fileNames = [];
    
    // Check if it's multipart form data (with files)
    if (!empty($_FILES)) {
        // Form data with potential files
        if (isset($_POST['data'])) {
            $rawData = $_POST['data'];
            $messageData = json_decode($rawData, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("Invalid JSON data in form: " . json_last_error_msg());
            }
            
            // Get custom file names if provided
            if (isset($messageData['fileNames'])) {
                $fileNames = $messageData['fileNames'];
            }
        } else {
            throw new Exception("No message data found in form");
        }
    } else {
        // Regular JSON request (no files)
        $jsonInput = file_get_contents('php://input');
        
        if (empty($jsonInput)) {
            throw new Exception("No data received. Please check your request format.");
        }
        
        $messageData = json_decode($jsonInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON input: " . json_last_error_msg());
        }
    }

    if (empty($messageData)) {
        throw new Exception("No message data received");
    }

    // Extract and validate data
    $subject = trim($messageData['subject'] ?? '');
    $messageText = trim($messageData['message'] ?? '');
    $recipients = $messageData['recipients'] ?? [];
    $isUrgent = filter_var($messageData['isUrgent'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $isBroadcast = filter_var($messageData['isBroadcast'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $targetRoles = $messageData['targetRoles'] ?? [];
    $messageType = $isBroadcast ? 'broadcast' : ($messageData['messageType'] ?? 'direct');

    // Validation
    if (empty($subject)) {
        throw new Exception("Subject is required");
    }
    
    if (empty($messageText)) {
        throw new Exception("Message text is required");
    }

    if (strlen($subject) > 255) {
        throw new Exception("Subject is too long (max 255 characters)");
    }

    $messageCompressEnabled = getAppSettingBool($conn, 'message_compress_enabled', false);
    $storedMessageText = encodeMessageText($messageText, $messageCompressEnabled);
    $messageTextSize = strlen($storedMessageText);

    // Broadcast permissions validation
    if ($isBroadcast) {
        if ($userRole !== 'admin') {
            throw new Exception("Only administrators can send broadcast messages");
        }
        
        // For broadcasts, we'll send to all users except the sender
        $recipients = []; // Clear individual recipients for broadcast
    } else {
        // For direct/group messages, validate recipients
        if (empty($recipients)) {
            throw new Exception("Please select at least one recipient.");
        }
        
        // Filter out sender from recipients to prevent self-messaging
        $recipients = array_filter($recipients, function($recipientId) use ($userId) {
            return $recipientId !== $userId;
        });
        
        if (empty($recipients)) {
            throw new Exception("Cannot send message to yourself. Please select other recipients.");
        }
    }

    // Check storage limit before sending
    $storageCheckStmt = $conn->prepare("
        SELECT
            (SELECT COALESCE(SUM(CHAR_LENGTH(m.message_text)), 0)
             FROM tb_messages m
             WHERE m.sender_id = ?
             AND (m.is_deleted_by_sender = FALSE OR m.is_deleted_by_sender IS NULL)
            ) AS message_usage,
            (SELECT COALESCE(SUM(att.file_size), 0)
             FROM tb_message_attachments att
             INNER JOIN tb_messages m2 ON att.message_id = m2.message_id
             WHERE m2.sender_id = ?
             AND (m2.is_deleted_by_sender = FALSE OR m2.is_deleted_by_sender IS NULL)
            ) AS attachment_usage
    ");
    $storageCheckStmt->bind_param("ss", $userId, $userId);
    $storageCheckStmt->execute();
    $storageResult = $storageCheckStmt->get_result();
    $storageData = $storageResult->fetch_assoc();
    $storageCheckStmt->close();

    $currentUsage = (int)($storageData['message_usage'] ?? 0) + (int)($storageData['attachment_usage'] ?? 0);
    $quotaRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'message_storage_quota_mb') : null;
    $quotaMb = is_numeric($quotaRaw) ? (int)$quotaRaw : 100;
    if ($quotaMb <= 0) {
        $quotaMb = 100;
    }
    $maxStorage = $quotaMb * 1024 * 1024;

    // Calculate new attachments size if any
    $newAttachmentsSize = 0;
    if (!empty($_FILES['attachments'])) {
        $attachments = $_FILES['attachments'];
        if (is_array($attachments['name'])) {
            for ($i = 0; $i < count($attachments['name']); $i++) {
                if ($attachments['error'][$i] === UPLOAD_ERR_OK) {
                    $newAttachmentsSize += $attachments['size'][$i];
                }
            }
        } else {
            if ($attachments['error'] === UPLOAD_ERR_OK) {
                $newAttachmentsSize += $attachments['size'];
            }
        }
    }

    // Check if new message would exceed storage limit
    if (($currentUsage + $newAttachmentsSize + $messageTextSize) > $maxStorage) {
        $remainingMB = round(($maxStorage - $currentUsage) / (1024 * 1024), 2);
        throw new Exception("Storage limit exceeded. You have {$remainingMB}MB remaining. Please delete some messages or attachments.");
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Insert main message
        $stmt = $conn->prepare("
            INSERT INTO tb_messages 
            (sender_id, subject, message_text, message_type, is_urgent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("ssssi", $userId, $subject, $storedMessageText, $messageType, $isUrgent);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to create message: " . $stmt->error);
        }

        $messageId = $conn->insert_id;
        $recipientCount = 0;

        // Handle broadcast
        if ($isBroadcast) {
            $broadcastStmt = $conn->prepare("
                INSERT INTO tb_broadcast_messages (message_id, target_roles) 
                VALUES (?, ?)
            ");
            
            if (!$broadcastStmt) {
                throw new Exception("Prepare broadcast failed: " . $conn->error);
            }
            
            $targetRolesJson = !empty($targetRoles) ? json_encode($targetRoles) : null;
            $broadcastStmt->bind_param("is", $messageId, $targetRolesJson);
            
            if (!$broadcastStmt->execute()) {
                throw new Exception("Failed to create broadcast: " . $broadcastStmt->error);
            }
            
            // For broadcasts, get all eligible staff users except sender and add as recipients
            $getUsersStmt = $conn->prepare("
                SELECT userId FROM tb_users 
                WHERE userId != ? AND userRole NOT IN ('pensioner', 'user')
            ");
            $getUsersStmt->bind_param("s", $userId);
            $getUsersStmt->execute();
            $usersResult = $getUsersStmt->get_result();
            
            $recipientStmt = $conn->prepare("
                INSERT INTO tb_message_recipients (message_id, recipient_user_id) 
                VALUES (?, ?)
            ");
            
            while ($user = $usersResult->fetch_assoc()) {
                // Apply role filtering if specific roles are selected
                if (!empty($targetRoles)) {
                    $userRoleStmt = $conn->prepare("SELECT userRole FROM tb_users WHERE userId = ?");
                    $userRoleStmt->bind_param("s", $user['userId']);
                    $userRoleStmt->execute();
                    $userRoleResult = $userRoleStmt->get_result();
                    $userData = $userRoleResult->fetch_assoc();
                    $userRoleStmt->close();
                    
                    if ($userData && in_array($userData['userRole'], $targetRoles)) {
                        $recipientStmt->bind_param("is", $messageId, $user['userId']);
                        if ($recipientStmt->execute()) {
                            $recipientCount++;
                        }
                    }
                } else {
                    // No role filtering, send to all non-pensioner users
                    $recipientStmt->bind_param("is", $messageId, $user['userId']);
                    if ($recipientStmt->execute()) {
                        $recipientCount++;
                    }
                }
            }
            
            $getUsersStmt->close();
            $recipientStmt->close();
            
        } else {
            // Handle recipients for direct/group messages
            if (!empty($recipients)) {
                $recipientStmt = $conn->prepare("
                    INSERT INTO tb_message_recipients (message_id, recipient_user_id) 
                    VALUES (?, ?)
                ");
                
                if (!$recipientStmt) {
                    throw new Exception("Prepare recipient failed: " . $conn->error);
                }
                
                foreach ($recipients as $recipientId) {
                    if (empty($recipientId) || !is_string($recipientId)) {
                        continue;
                    }
                    
                    // Double-check we're not sending to self
                    if ($recipientId === $userId) {
                        continue;
                    }

                    $recipientUserStmt = $conn->prepare("
                        SELECT userRole
                        FROM tb_users
                        WHERE userId = ?
                        LIMIT 1
                    ");
                    if ($recipientUserStmt) {
                        $recipientUserStmt->bind_param("s", $recipientId);
                        $recipientUserStmt->execute();
                        $recipientUser = $recipientUserStmt->get_result()->fetch_assoc();
                        $recipientUserStmt->close();
                        if (!$recipientUser || !canRoleAccessMessagingModule((string)($recipientUser['userRole'] ?? ''))) {
                            continue;
                        }
                    }
                    
                    $recipientStmt->bind_param("is", $messageId, $recipientId);
                    
                    if (!$recipientStmt->execute()) {
                        error_log("Failed to add recipient $recipientId: " . $recipientStmt->error);
                    } else {
                        $recipientCount++;
                    }
                }

                if ($recipientCount === 0) {
                    throw new Exception("Select at least one eligible staff recipient.");
                }
            }
        }

        // Handle file attachments with custom names
        if (!empty($_FILES['attachments'])) {
            $attachments = $_FILES['attachments'];
            $attachmentNames = $_POST['attachment_names'] ?? [];
            
            if (is_array($attachments['name'])) {
                for ($i = 0; $i < count($attachments['name']); $i++) {
                    if ($attachments['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $attachments['name'][$i],
                            'type' => $attachments['type'][$i],
                            'tmp_name' => $attachments['tmp_name'][$i],
                            'error' => $attachments['error'][$i],
                            'size' => $attachments['size'][$i]
                        ];
                        
                        // Get custom name if available
                        $customName = $attachmentNames[$i] ?? null;
                        handleFileUpload($file, $messageId, $conn, $customName);
                    }
                }
            } else {
                if ($attachments['error'] === UPLOAD_ERR_OK) {
                    $customName = $attachmentNames[0] ?? null;
                    handleFileUpload($attachments, $messageId, $conn, $customName);
                }
            }
        }

        // Commit transaction
        $conn->commit();
        try {
            maybeCreateMessageStorageSnapshot($conn, [
                'notes' => $isBroadcast ? 'Automatic snapshot after broadcast message send.' : 'Automatic snapshot after message send.',
                'created_by' => $_SESSION['userId'] ?? null,
                'created_by_name' => $_SESSION['userName'] ?? null,
                'created_by_role' => $_SESSION['userRole'] ?? null
            ]);
        } catch (Throwable $snapshotError) {
            error_log('Message snapshot warning: ' . $snapshotError->getMessage());
        }

        if (function_exists('logAuditEvent')) {
            logAuditEvent($conn, [
                'actor_id' => $_SESSION['userId'] ?? 'system',
                'actor_name' => $_SESSION['userName'] ?? 'System',
                'actor_role' => $_SESSION['userRole'] ?? 'system',
                'action' => $isBroadcast ? 'broadcast_sent' : 'message_sent',
                'entity_type' => 'message',
                'entity_id' => (string)$messageId,
                'details' => [
                    'message_type' => $messageType,
                    'is_broadcast' => $isBroadcast,
                    'recipient_count' => $recipientCount
                ]
            ]);
        }

        if ($isBroadcast && getAppSettingBool($conn, 'notify_email_enabled', true) && getAppSettingBool($conn, 'notify_broadcast_enabled', true)) {
            $recipientEmailStmt = $conn->prepare("
                SELECT u.userId, u.userName, u.userEmail
                FROM tb_message_recipients mr
                INNER JOIN tb_users u ON u.userId = mr.recipient_user_id
                WHERE mr.message_id = ?
                  AND u.userEmail IS NOT NULL
                  AND TRIM(u.userEmail) <> ''
                ORDER BY u.userName ASC
            ");
            if ($recipientEmailStmt) {
                $recipientEmailStmt->bind_param('i', $messageId);
                $recipientEmailStmt->execute();
                $recipientEmailResult = $recipientEmailStmt->get_result();
                $broadcastSubject = 'Broadcast message: ' . $subject;
                while ($recipientRow = $recipientEmailResult->fetch_assoc()) {
                    $recipientEmail = trim((string)($recipientRow['userEmail'] ?? ''));
                    if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $recipientName = trim((string)($recipientRow['userName'] ?? 'Colleague'));
                    $textBody = implode("\n", [
                        'A new broadcast message has been posted in UPS PensionsGo.',
                        'Subject: ' . $subject,
                        'From: ' . ($_SESSION['userName'] ?? 'System'),
                        '',
                        $messageText,
                        '',
                        'Open the messaging workspace to read the full message and attachments.'
                    ]);
                    $htmlBody = '<p>A new broadcast message has been posted in UPS PensionsGo.</p>'
                        . '<p><strong>To:</strong> ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '<br>'
                        . '<strong>Subject:</strong> ' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '<br>'
                        . '<strong>From:</strong> ' . htmlspecialchars((string)($_SESSION['userName'] ?? 'System'), ENT_QUOTES, 'UTF-8') . '</p>'
                        . '<p>' . nl2br(htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8')) . '</p>'
                        . '<p>Open the messaging workspace to read the full message and attachments.</p>';
                    queueNotification($conn, 'email', $recipientEmail, $broadcastSubject, $textBody, [
                        'source' => 'broadcast_message',
                        'message_id' => $messageId,
                        'recipient_user_id' => (string)($recipientRow['userId'] ?? ''),
                        'html_body' => $htmlBody
                    ]);
                }
                $recipientEmailStmt->close();
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Message sent successfully',
            'message_id' => $messageId
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }

} catch (Exception $e) {
    error_log("Send message error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

if (isset($conn)) {
    $conn->close();
}
?>

