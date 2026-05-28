<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserCanAccessMessagingModule()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

function isPreviewableMessageAttachment(string $mime, string $fileName): bool
{
    $mime = strtolower(trim($mime));
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (str_starts_with($mime, 'image/')) {
        return true;
    }
    if (in_array($mime, ['application/pdf', 'text/plain', 'text/csv'], true)) {
        return true;
    }
    return in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv'], true);
}

$attachmentId = (int)($_GET['attachment_id'] ?? 0);
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
if ($attachmentId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Invalid attachment request']);
    exit;
}

$userId = (string)($_SESSION['userId'] ?? '');
$stmt = $conn->prepare("
    SELECT
        att.attachment_id,
        att.message_id,
        att.file_name,
        att.file_path,
        att.file_size,
        att.mime_type,
        att.uploaded_at
    FROM tb_message_attachments att
    INNER JOIN tb_messages m ON att.message_id = m.message_id
    LEFT JOIN tb_message_recipients mr
        ON m.message_id = mr.message_id
       AND mr.recipient_user_id = ?
    LEFT JOIN tb_broadcast_messages bm
        ON m.message_id = bm.message_id
    LEFT JOIN tb_user_broadcast_status ubs
        ON bm.broadcast_id = ubs.broadcast_id
       AND ubs.user_id = ?
    WHERE att.attachment_id = ?
      AND (
            m.sender_id = ?
            OR mr.recipient_user_id IS NOT NULL
            OR ubs.user_id IS NOT NULL
      )
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unable to prepare attachment lookup']);
    exit;
}
$stmt->bind_param('ssis', $userId, $userId, $attachmentId, $userId);
$stmt->execute();
$attachment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$attachment) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Attachment not found or access denied']);
    exit;
}

$retentionRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'attachment_retention_days') : null;
$retentionDays = is_numeric($retentionRaw) ? (int)$retentionRaw : 0;
if ($retentionDays > 0 && !empty($attachment['uploaded_at'])) {
    $uploadedAt = strtotime((string)$attachment['uploaded_at']);
    $cutoff = strtotime("-{$retentionDays} days");
    if ($uploadedAt && $uploadedAt < $cutoff) {
        http_response_code(410);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Attachment is no longer available due to retention policy']);
        exit;
    }
}

$relativePath = trim((string)($attachment['file_path'] ?? ''));
$absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
$allowedRoot = realpath(__DIR__ . '/../uploads/messages');
if ($relativePath === '' || $absolutePath === false || $allowedRoot === false || strpos($absolutePath, $allowedRoot) !== 0 || !is_file($absolutePath)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Attachment file was not found']);
    exit;
}

$mime = trim((string)($attachment['mime_type'] ?? ''));
if ($mime === '' || stripos($mime, 'octet-stream') !== false) {
    $detected = @mime_content_type($absolutePath);
    $mime = $detected ?: 'application/octet-stream';
}

$displayName = basename((string)($attachment['file_name'] ?? basename($absolutePath)));
$previewEnabled = getAppSettingBool($conn, 'document_preview_enabled', true);
$disposition = (!$download && $previewEnabled && isPreviewableMessageAttachment($mime, $displayName)) ? 'inline' : 'attachment';

logAuditEvent($conn, [
    'actor_id' => $_SESSION['userId'] ?? 'system',
    'actor_name' => $_SESSION['userName'] ?? 'System',
    'actor_role' => $_SESSION['userRole'] ?? 'system',
    'action' => $disposition === 'inline' ? 'message_attachment_previewed' : 'message_attachment_downloaded',
    'entity_type' => 'message_attachment',
    'entity_id' => (string)$attachmentId,
    'details' => [
        'message_id' => (int)($attachment['message_id'] ?? 0),
        'file_path' => $relativePath
    ]
]);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($displayName) . '"');
header('Cache-Control: private, max-age=120');

readfile($absolutePath);
exit;
?>
