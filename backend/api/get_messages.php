<?php
// 
// get_messages.php
// Purpose: Fetch messages for the logged-in user with proper visibility logic
// Fixed: Messages show correctly when only one party has deleted
// 
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

try {
    $bindParams = function ($stmt, string $types, array $params): void {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    };

    $userId = $_SESSION['userId'];
    $messageType = $_GET['type'] ?? 'inbox';
    $page = $_GET['page'] ?? 1;
    $limit = $_GET['limit'] ?? 20;
    $offset = ($page - 1) * $limit;

    $retentionRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'message_retention_days') : null;
    $retentionDays = is_numeric($retentionRaw) ? (int)$retentionRaw : 0;
    if ($retentionDays < 0) {
        $retentionDays = 0;
    }
    $retentionClause = $retentionDays > 0
        ? "AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)"
        : "";

    $archivedFlag = filter_var($_GET['archived'] ?? '0', FILTER_VALIDATE_BOOLEAN);
    $archiveRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'message_archive_after_days') : null;
    $archiveDays = is_numeric($archiveRaw) ? (int)$archiveRaw : 0;
    if ($archiveDays < 0) {
        $archiveDays = 0;
    }
    if ($retentionDays > 0 && $archiveDays >= $retentionDays) {
        $archiveDays = 0;
    }
    $archiveClause = '';
    if ($archiveDays > 0) {
        $archiveClause = $archivedFlag
            ? "AND m.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            : "AND m.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    }

    switch ($messageType) {
        case 'inbox':
            // Show messages where recipient hasn't deleted
            $stmt = $conn->prepare("
                SELECT 
                    m.message_id,
                    m.sender_id,
                    m.subject,
                    m.message_text,
                    m.message_type,
                    m.is_urgent,
                    m.created_at,
                    mr.is_read,
                    mr.read_at,
                    u.userName as sender_name,
                    u.userRole as sender_role,
                    u.userPhoto as sender_photo,
                    COUNT(DISTINCT a.attachment_id) as attachment_count
                FROM tb_messages m
                INNER JOIN tb_message_recipients mr ON m.message_id = mr.message_id
                INNER JOIN tb_users u ON m.sender_id = u.userId
                LEFT JOIN tb_message_attachments a ON m.message_id = a.message_id
                WHERE mr.recipient_user_id = ? 
                AND mr.is_deleted = FALSE
                AND m.message_type IN ('direct', 'group')
                {$retentionClause}
                {$archiveClause}
                GROUP BY m.message_id
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed for inbox: " . $conn->error);
            }
            $types = "s";
            $params = [$userId];
            if ($retentionDays > 0) {
                $types .= "i";
                $params[] = $retentionDays;
            }
            if ($archiveDays > 0) {
                $types .= "i";
                $params[] = $archiveDays;
            }
            $types .= "ii";
            $params[] = $limit;
            $params[] = $offset;
            $bindParams($stmt, $types, $params);
            break;

        case 'sent':
            // FIXED: Proper recipient information even when recipients deleted
            $stmt = $conn->prepare("
                SELECT 
                    m.message_id,
                    m.subject,
                    m.message_text,
                    m.message_type,
                    m.is_urgent,
                    m.created_at,
                    COUNT(DISTINCT mr.recipient_user_id) as total_recipients,
                    COUNT(DISTINCT CASE WHEN mr.is_deleted = FALSE THEN mr.recipient_user_id END) as active_recipients,
                    COUNT(DISTINCT a.attachment_id) as attachment_count,
                    -- Get primary recipient name (show deleted recipients if no active ones)
                    COALESCE(
                        (
                            SELECT u.userName 
                            FROM tb_message_recipients mr2 
                            INNER JOIN tb_users u ON mr2.recipient_user_id = u.userId 
                            WHERE mr2.message_id = m.message_id 
                            AND mr2.is_deleted = FALSE 
                            ORDER BY u.userName 
                            LIMIT 1
                        ),
                        (
                            SELECT u.userName 
                            FROM tb_message_recipients mr2 
                            INNER JOIN tb_users u ON mr2.recipient_user_id = u.userId 
                            WHERE mr2.message_id = m.message_id 
                            ORDER BY u.userName 
                            LIMIT 1
                        ),
                        'Unknown'
                    ) as primary_recipient_name,
                    -- Get recipient photo (show deleted recipients if no active ones)
                    COALESCE(
                        (
                            SELECT u.userPhoto 
                            FROM tb_message_recipients mr2 
                            INNER JOIN tb_users u ON mr2.recipient_user_id = u.userId 
                            WHERE mr2.message_id = m.message_id 
                            AND mr2.is_deleted = FALSE 
                            ORDER BY u.userName 
                            LIMIT 1
                        ),
                        (
                            SELECT u.userPhoto 
                            FROM tb_message_recipients mr2 
                            INNER JOIN tb_users u ON mr2.recipient_user_id = u.userId 
                            WHERE mr2.message_id = m.message_id 
                            ORDER BY u.userName 
                            LIMIT 1
                        )
                    ) as recipient_photo,
                    -- Get all recipient names for display
                    GROUP_CONCAT(DISTINCT 
                        CASE 
                            WHEN mr.is_deleted = FALSE THEN u.userName 
                            ELSE CONCAT(u.userName, ' (deleted)')
                        END 
                        ORDER BY u.userName SEPARATOR ', '
                    ) as all_recipient_names
                FROM tb_messages m
                LEFT JOIN tb_message_recipients mr ON m.message_id = mr.message_id
                LEFT JOIN tb_users u ON mr.recipient_user_id = u.userId
                LEFT JOIN tb_message_attachments a ON m.message_id = a.message_id
                WHERE m.sender_id = ?
                AND (m.is_deleted_by_sender = FALSE OR m.is_deleted_by_sender IS NULL)
                AND m.message_type IN ('direct', 'group')
                {$retentionClause}
                {$archiveClause}
                GROUP BY m.message_id
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed for sent: " . $conn->error);
            }
            $types = "s";
            $params = [$userId];
            if ($retentionDays > 0) {
                $types .= "i";
                $params[] = $retentionDays;
            }
            if ($archiveDays > 0) {
                $types .= "i";
                $params[] = $archiveDays;
            }
            $types .= "ii";
            $params[] = $limit;
            $params[] = $offset;
            $bindParams($stmt, $types, $params);
            break;

        case 'broadcast':
            // Get active broadcasts that aren't deleted by user
            $stmt = $conn->prepare("
                SELECT 
                    m.message_id,
                    m.sender_id,
                    m.subject,
                    m.message_text,
                    m.is_urgent,
                    m.created_at,
                    u.userName as sender_name,
                    u.userRole as sender_role,
                    u.userPhoto as sender_photo,
                    bm.broadcast_id,
                    ubs.is_seen,
                    ubs.seen_at,
                    COUNT(DISTINCT a.attachment_id) as attachment_count
                FROM tb_messages m
                INNER JOIN tb_broadcast_messages bm ON m.message_id = bm.message_id
                INNER JOIN tb_users u ON m.sender_id = u.userId
                LEFT JOIN tb_user_broadcast_status ubs ON (bm.broadcast_id = ubs.broadcast_id AND ubs.user_id = ?)
                LEFT JOIN tb_message_attachments a ON m.message_id = a.message_id
                WHERE bm.is_active = TRUE
                AND (ubs.is_deleted = FALSE OR ubs.is_deleted IS NULL)
                {$retentionClause}
                {$archiveClause}
                GROUP BY m.message_id
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed for broadcast: " . $conn->error);
            }
            $types = "s";
            $params = [$userId];
            if ($retentionDays > 0) {
                $types .= "i";
                $params[] = $retentionDays;
            }
            if ($archiveDays > 0) {
                $types .= "i";
                $params[] = $archiveDays;
            }
            $types .= "ii";
            $params[] = $limit;
            $params[] = $offset;
            $bindParams($stmt, $types, $params);
            break;

        default:
            throw new Exception("Invalid message type: " . $messageType);
    }

    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $messages = [];

    while ($row = $result->fetch_assoc()) {
        $fullText = decodeMessageText($row['message_text'] ?? '');
        if (function_exists('mb_substr')) {
            $row['preview'] = mb_substr($fullText, 0, 200);
        } else {
            $row['preview'] = substr($fullText, 0, 200);
        }
        unset($row['message_text']);
        $messages[] = $row;
    }
    $stmt->close();

    // Get total count for pagination
    if ($messageType === 'inbox') {
        $countStmt = $conn->prepare("
            SELECT COUNT(DISTINCT m.message_id) as total 
            FROM tb_message_recipients mr
            INNER JOIN tb_messages m ON mr.message_id = m.message_id
            WHERE mr.recipient_user_id = ? 
            AND mr.is_deleted = FALSE
            AND m.message_type IN ('direct', 'group')
            {$retentionClause}
            {$archiveClause}
        ");
        if ($countStmt) {
            $types = "s";
            $params = [$userId];
            if ($retentionDays > 0) {
                $types .= "i";
                $params[] = $retentionDays;
            }
            if ($archiveDays > 0) {
                $types .= "i";
                $params[] = $archiveDays;
            }
            $bindParams($countStmt, $types, $params);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $total = $countResult->fetch_assoc()['total'];
            $countStmt->close();
        } else {
            $total = 0;
        }
    } elseif ($messageType === 'sent') {
        $countStmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM tb_messages m
            WHERE m.sender_id = ?
            AND (m.is_deleted_by_sender = FALSE OR m.is_deleted_by_sender IS NULL)
            AND m.message_type IN ('direct', 'group')
            {$retentionClause}
            {$archiveClause}
        ");
        if ($countStmt) {
            $types = "s";
            $params = [$userId];
            if ($retentionDays > 0) {
                $types .= "i";
                $params[] = $retentionDays;
            }
            if ($archiveDays > 0) {
                $types .= "i";
                $params[] = $archiveDays;
            }
            $bindParams($countStmt, $types, $params);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $total = $countResult->fetch_assoc()['total'];
            $countStmt->close();
        } else {
            $total = 0;
        }
    } else {
        $countStmt = $conn->prepare("
            SELECT COUNT(DISTINCT m.message_id) as total 
            FROM tb_broadcast_messages bm
            INNER JOIN tb_messages m ON bm.message_id = m.message_id
            LEFT JOIN tb_user_broadcast_status ubs ON (bm.broadcast_id = ubs.broadcast_id AND ubs.user_id = ?)
            WHERE bm.is_active = TRUE
            AND (ubs.is_deleted = FALSE OR ubs.is_deleted IS NULL)
            {$retentionClause}
            {$archiveClause}
        ");
        if ($countStmt) {
            $types = "s";
            $params = [$userId];
            if ($retentionDays > 0) {
                $types .= "i";
                $params[] = $retentionDays;
            }
            if ($archiveDays > 0) {
                $types .= "i";
                $params[] = $archiveDays;
            }
            $bindParams($countStmt, $types, $params);
            $countStmt->execute();
            $countResult = $countStmt->get_result();
            $total = $countResult->fetch_assoc()['total'];
            $countStmt->close();
        } else {
            $total = 0;
        }
    }

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'pagination' => [
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);

} catch (Exception $e) {
    error_log("Get messages error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching messages: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

