<?php
// backend/api/get_msg_image.php
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['userId'])) {
    http_response_code(401);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    http_response_code(403);
    exit;
}

$file = trim((string)($_GET['file'] ?? ''));
$type = $_GET['type'] ?? 'attachment';

$allowedTypes = ['attachment', 'profile'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    exit;
}

if ($type === 'profile') {
    if ($file === '' || $file === 'images/default-user.png' || str_ends_with($file, '/default-user.png') || str_ends_with($file, '\\default-user.png')) {
        $file = 'default-user.png';
    } else {
        $file = basename(str_replace('\\', '/', $file));
        if ($file === '') {
            $file = 'default-user.png';
        }
    }
} else {
    // Basic checks for attachments.
    if ($file === '') {
        http_response_code(400);
        exit;
    }
    if (strpos($file, '..') !== false || strpos($file, '/') !== false || strpos($file, '\\') !== false) {
        http_response_code(400);
        exit;
    }
}

// determine content-type by extension
$lc = strtolower($file);
if (str_ends_with($lc, '.png')) header('Content-Type: image/png');
elseif (str_ends_with($lc, '.jpg') || str_ends_with($lc, '.jpeg')) header('Content-Type: image/jpeg');
elseif (str_ends_with($lc, '.gif')) header('Content-Type: image/gif');
elseif (str_ends_with($lc, '.pdf')) header('Content-Type: application/pdf');
elseif (str_ends_with($lc, '.doc') || str_ends_with($lc, '.docx')) header('Content-Type: application/msword');
elseif (str_ends_with($lc, '.xls') || str_ends_with($lc, '.xlsx')) header('Content-Type: application/vnd.ms-excel');
elseif (str_ends_with($lc, '.txt')) header('Content-Type: text/plain');
else header('Content-Type: application/octet-stream');

// choose directory
if ($type === 'profile') {
    $uploadDir = __DIR__ . '/../uploads/profiles/';
} else {
    $uploadDir = __DIR__ . '/../uploads/messages/';
}

$filePath = $uploadDir . $file;

// If file exists, check permission
if (file_exists($filePath) && is_file($filePath)) {
    if ($type === 'attachment') {
        // check that the user is allowed to access this attachment
        // match by file_path containing the filename (the DB stores file_path like 'uploads/messages/123_name.ext')
        $like = '%' . $conn->real_escape_string($file) . '%';
        $sql = "
            SELECT 1 FROM tb_message_attachments att
            INNER JOIN tb_messages m ON att.message_id = m.message_id
            LEFT JOIN tb_message_recipients mr ON m.message_id = mr.message_id
            LEFT JOIN tb_broadcast_messages bm ON m.message_id = bm.message_id
            LEFT JOIN tb_user_broadcast_status ubs ON (bm.broadcast_id = ubs.broadcast_id AND ubs.user_id = ?)
            WHERE att.file_path LIKE ? 
            AND (m.sender_id = ? OR mr.recipient_user_id = ? OR ubs.user_id = ?)
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) { http_response_code(500); exit; }
        $userId = $_SESSION['userId'];
        $stmt->bind_param("sssss", $userId, $like, $userId, $userId, $userId);
        $stmt->execute();
        $hasAccess = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$hasAccess) { http_response_code(403); exit; }

        $retentionRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'attachment_retention_days') : null;
        $retentionDays = is_numeric($retentionRaw) ? (int)$retentionRaw : 0;
        if ($retentionDays > 0) {
            $retStmt = $conn->prepare("
                SELECT uploaded_at
                FROM tb_message_attachments
                WHERE file_path LIKE ?
                LIMIT 1
            ");
            if ($retStmt) {
                $retStmt->bind_param("s", $like);
                $retStmt->execute();
                $retRow = $retStmt->get_result()->fetch_assoc();
                $retStmt->close();

                if (!empty($retRow['uploaded_at'])) {
                    $cutoff = strtotime("-{$retentionDays} days");
                    $uploadedAt = strtotime($retRow['uploaded_at']);
                    if ($uploadedAt && $uploadedAt < $cutoff) {
                        http_response_code(410);
                        exit;
                    }
                }
            }
        }
    }

    // serve the file
    readfile($filePath);
    exit;
}

// fallback default asset
$defaultPath = $type === 'profile'
    ? __DIR__ . '/../../frontend/images/default-user.png'
    : __DIR__ . '/../../frontend/images/default-file.png';
if (file_exists($defaultPath)) {
    readfile($defaultPath);
} else {
    http_response_code(404);
}
