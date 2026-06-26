<?php
require_once __DIR__ . '/public_chat_common.php';

publicChatEnsureTables($conn);

$attachmentId = (int)($_GET['attachment_id'] ?? 0);
$sessionId = (int)($_GET['session_id'] ?? 0);
$token = publicChatClean($_GET['token'] ?? '', 128);

function publicChatPreviewError(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;padding:24px;color:#6d1116;background:#fff8f8}</style></head><body><strong>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</strong></body></html>';
    exit;
}

function publicChatDocxTextFromNode(DOMXPath $xpath, DOMNode $node): string
{
    $parts = [];
    foreach ($xpath->query('.//w:t|.//w:tab|.//w:br', $node) ?: [] as $child) {
        if ($child->localName === 'tab') {
            $parts[] = ' ';
            continue;
        }
        if ($child->localName === 'br') {
            $parts[] = "\n";
            continue;
        }
        $parts[] = $child->textContent;
    }
    return trim(preg_replace('/[ \t]+/', ' ', implode('', $parts)) ?? '');
}

function publicChatDocxPreviewHtml(string $absolutePath, string $displayName): string
{
    if (!class_exists('ZipArchive')) {
        publicChatPreviewError('DOCX preview is not available because ZipArchive is not enabled.', 500);
    }
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
        publicChatPreviewError('DOCX preview is not available because XML support is not enabled.', 500);
    }
    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        publicChatPreviewError('Unable to open this DOCX file.', 422);
    }
    $xml = (string)$zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === '') {
        publicChatPreviewError('This DOCX file does not contain previewable document text.', 422);
    }

    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) {
        publicChatPreviewError('Unable to read this DOCX preview.', 422);
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $body = $xpath->query('//w:body')->item(0);
    if (!$body) {
        publicChatPreviewError('This DOCX file has no previewable body.', 422);
    }

    $content = '';
    foreach ($body->childNodes as $child) {
        if (!$child instanceof DOMElement) {
            continue;
        }
        if ($child->localName === 'p') {
            $text = publicChatDocxTextFromNode($xpath, $child);
            if ($text !== '') {
                $content .= '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            continue;
        }
        if ($child->localName === 'tbl') {
            $rows = '';
            foreach ($xpath->query('.//w:tr', $child) ?: [] as $row) {
                $cells = '';
                foreach ($xpath->query('./w:tc', $row) ?: [] as $cell) {
                    $cells .= '<td>' . nl2br(htmlspecialchars(publicChatDocxTextFromNode($xpath, $cell), ENT_QUOTES, 'UTF-8')) . '</td>';
                }
                if ($cells !== '') {
                    $rows .= '<tr>' . $cells . '</tr>';
                }
            }
            if ($rows !== '') {
                $content .= '<table>' . $rows . '</table>';
            }
        }
    }

    if ($content === '') {
        $content = '<p>No previewable text was found in this DOCX file.</p>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</title><style>body{margin:0;background:#f8fafc;color:#1f2937;font-family:Arial,sans-serif}.docx-preview{max-width:900px;margin:0 auto;padding:28px 32px;background:#fff;min-height:100vh;box-shadow:0 0 0 1px #e5e7eb}.docx-preview h1{font-size:18px;margin:0 0 18px;color:#6d1116}.docx-preview p{font-size:14px;line-height:1.65;margin:0 0 12px}.docx-preview table{width:100%;border-collapse:collapse;margin:14px 0}.docx-preview td{border:1px solid #d1d5db;padding:8px;vertical-align:top;font-size:13px;line-height:1.45}@media(max-width:640px){.docx-preview{padding:18px}}</style></head><body><main class="docx-preview"><h1>' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . '</h1>' . $content . '</main></body></html>';
}

if ($attachmentId <= 0) {
    publicChatPreviewError('Invalid attachment request.');
}

$asAgent = !empty($_SESSION['userId']) && publicChatCanManage($conn);
if (!$asAgent) {
    if ($sessionId <= 0 || $token === '') {
        publicChatPreviewError('Attachment access requires an active chat token.', 401);
    }
    publicChatVerifyVisitorSession($conn, $sessionId, $token);
}

$stmt = $conn->prepare("
    SELECT attachment_id, session_id, message_id, file_name, file_path, mime_type
    FROM public_chat_attachments
    WHERE attachment_id = ?
    LIMIT 1
");
if (!$stmt) {
    publicChatPreviewError('Unable to prepare attachment lookup.', 500);
}
$stmt->bind_param('i', $attachmentId);
$stmt->execute();
$attachment = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$attachment || (!$asAgent && (int)$attachment['session_id'] !== $sessionId)) {
    publicChatPreviewError('Attachment not found or access denied.', 404);
}

$relativePath = trim((string)($attachment['file_path'] ?? ''));
$absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
$allowedRoot = realpath(__DIR__ . '/../uploads/public_chat');
if ($relativePath === '' || $absolutePath === false || $allowedRoot === false || strpos($absolutePath, $allowedRoot) !== 0 || !is_file($absolutePath)) {
    publicChatPreviewError('Attachment file was not found.', 404);
}

$displayName = basename((string)($attachment['file_name'] ?? basename($absolutePath)));
$ext = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
if ($ext !== 'docx') {
    publicChatPreviewError('Only DOCX files use this secure preview.', 415);
}

publicChatAudit($conn, (int)$attachment['session_id'], 'Attachment previewed', [
    'attachment_id' => $attachmentId,
    'message_id' => (int)($attachment['message_id'] ?? 0),
    'file_name' => $displayName
]);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, max-age=120');
header('X-Content-Type-Options: nosniff');
echo publicChatDocxPreviewHtml($absolutePath, $displayName);
?>
