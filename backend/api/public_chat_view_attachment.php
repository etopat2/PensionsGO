<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);

$attachmentId = (int)($_GET['attachment_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);
$token = publicChatClean($_GET['token'] ?? '', 128);
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';

if ($attachmentId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Invalid attachment request']);
    exit;
}

$asAgent = !empty($_SESSION['userId']) && publicChatCanManage($conn);
if (!$asAgent) {
    if ($sessionId <= 0 || $token === '') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Attachment access requires an active chat token']);
        exit;
    }
    publicChatVerifyVisitorSession($conn, $sessionId, $token);
}

$stmt = $conn->prepare("
    SELECT attachment_id, session_id, message_id, file_name, file_path, file_size, mime_type, created_at
    FROM public_chat_attachments
    WHERE attachment_id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unable to prepare attachment lookup']);
    exit;
}
$stmt->bind_param('i', $attachmentId);
$stmt->execute();
$attachment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$attachment || (!$asAgent && (int)$attachment['session_id'] !== $sessionId)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Attachment not found or access denied']);
    exit;
}

$relativePath = trim((string)($attachment['file_path'] ?? ''));
$absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
$allowedRoot = realpath(__DIR__ . '/../uploads/public_chat');
if ($relativePath === '' || $absolutePath === false || $allowedRoot === false || strpos($absolutePath, $allowedRoot) !== 0 || !is_file($absolutePath)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Attachment file was not found']);
    exit;
}

$mime = trim((string)($attachment['mime_type'] ?? ''));
if ($mime === '' || stripos($mime, 'octet-stream') !== false) {
    $mime = @mime_content_type($absolutePath) ?: 'application/octet-stream';
}

$displayName = basename((string)($attachment['file_name'] ?? basename($absolutePath)));
$mime = publicChatPlaybackMime($mime, $displayName);
$previewEnabled = getAppSettingBool($conn, 'document_preview_enabled', true);
$disposition = (!$download && $previewEnabled && publicChatAttachmentIsPreviewable($mime, $displayName)) ? 'inline' : 'attachment';

publicChatAudit($conn, (int)$attachment['session_id'], $disposition === 'inline' ? 'Attachment previewed' : 'Attachment downloaded', [
    'attachment_id' => $attachmentId,
    'message_id' => (int)($attachment['message_id'] ?? 0),
    'file_name' => $displayName
]);

$fileSize = filesize($absolutePath);
$start = 0;
$end = $fileSize > 0 ? $fileSize - 1 : 0;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
    if ($matches[1] !== '') {
        $start = max(0, (int)$matches[1]);
    }
    if ($matches[2] !== '') {
        $end = min($end, (int)$matches[2]);
    }
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
}

$length = max(0, $end - $start + 1);
header('Content-Type: ' . $mime);
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($displayName) . '"');
header('Cache-Control: private, max-age=120');
header('Content-Transfer-Encoding: binary');

$handle = fopen($absolutePath, 'rb');
if ($handle === false) {
    exit;
}
fseek($handle, $start);
$remaining = $length;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(8192, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) {
        break;
    }
}
fclose($handle);
exit;
?>
