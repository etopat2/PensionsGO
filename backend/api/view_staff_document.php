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

$role = strtolower((string)($_SESSION['userRole'] ?? ''));

ensureStaffDocumentsTable($conn);
ensurePensionerLookupColumns($conn);
if (function_exists('maybeApplyDocumentRetentionRules')) {
    maybeApplyDocumentRetentionRules($conn);
} else {
    applyDocumentRetentionRules($conn);
}

$docSettings = getDocumentStorageSettings($conn);
if (empty($docSettings['enabled'])) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Document storage is disabled by settings.']);
    exit;
}

function isPreviewableDocument(string $mime, string $fileName): bool
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

$documentId = (int)($_GET['document_id'] ?? 0);
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';
if ($documentId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Invalid document request']);
    exit;
}

$stmt = $conn->prepare("
    SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, mime_type, uploaded_at
    FROM tb_staff_documents
    WHERE document_id = ?
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Unable to prepare document lookup']);
    exit;
}
$stmt->bind_param('i', $documentId);
$stmt->execute();
$document = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

if (!$document) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

if ($role === 'pensioner') {
    $ownedRegistry = resolvePensionerOwnedRegistry($conn, (string)($_SESSION['userId'] ?? ''));
    $ownedRegNo = trim((string)($ownedRegistry['regNo'] ?? ''));
    $documentRegNo = trim((string)($document['regNo'] ?? ''));
    if ($ownedRegNo === '' || $documentRegNo === '' || strcasecmp($ownedRegNo, $documentRegNo) !== 0) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }
}

$relativePath = trim((string)($document['file_path'] ?? ''));
$absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
$allowedRoot = realpath(__DIR__ . '/../uploads/documents');
if ($relativePath === '' || $absolutePath === false || $allowedRoot === false || strpos($absolutePath, $allowedRoot) !== 0 || !is_file($absolutePath)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Document file was not found']);
    exit;
}

$mime = trim((string)($document['mime_type'] ?? ''));
if ($mime === '' || stripos($mime, 'octet-stream') !== false) {
    $detected = @mime_content_type($absolutePath);
    $mime = $detected ?: 'application/octet-stream';
}

$displayName = basename((string)($document['file_name'] ?? basename($absolutePath)));
$previewEnabled = !empty($docSettings['preview_enabled']);
$disposition = (!$download && $previewEnabled && isPreviewableDocument($mime, $displayName)) ? 'inline' : 'attachment';

if (!empty($docSettings['access_audit_enabled'])) {
    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'System',
        'actor_role' => $_SESSION['userRole'] ?? 'system',
        'action' => $disposition === 'inline' ? 'staff_document_previewed' : 'staff_document_downloaded',
        'entity_type' => 'staff_document',
        'entity_id' => (string)$documentId,
        'details' => [
            'reg_no' => (string)($document['regNo'] ?? ''),
            'doc_type' => (string)($document['doc_type'] ?? ''),
            'file_path' => $relativePath
        ]
    ]);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($displayName) . '"');
header('Cache-Control: private, max-age=120');

readfile($absolutePath);
exit;
?>
