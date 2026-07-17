<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if ($role === 'pensioner') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensurePayrollManagementTables($conn);

$cycleId = (int)($_GET['cycle_id'] ?? 0);
$docType = strtolower(trim((string)($_GET['type'] ?? 'source')));
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';

if ($cycleId <= 0 || !in_array($docType, ['source', 'register'], true)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit;
}

$stmt = $conn->prepare("
    SELECT
        source_file,
        source_file_original_name,
        source_file_mime,
        payment_register_file,
        payment_register_original_name,
        payment_register_mime
    FROM tb_payroll_upload_cycles
    WHERE cycle_id = ?
      AND COALESCE(is_deleted, 0) = 0
    LIMIT 1
");
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unable to prepare document query']);
    exit;
}
$stmt->bind_param("i", $cycleId);
$stmt->execute();
$cycle = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cycle) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Payroll cycle not found']);
    exit;
}

if ($docType === 'register') {
    $relativePath = trim((string)($cycle['payment_register_file'] ?? ''));
    $displayName = trim((string)($cycle['payment_register_original_name'] ?? 'payment_register.pdf'));
    $storedMime = trim((string)($cycle['payment_register_mime'] ?? 'application/pdf'));
    $auditAction = 'view_payment_register';
} else {
    $relativePath = trim((string)($cycle['source_file'] ?? ''));
    $displayName = trim((string)($cycle['source_file_original_name'] ?? 'payroll_file'));
    $storedMime = trim((string)($cycle['source_file_mime'] ?? 'application/octet-stream'));
    $auditAction = 'view_source_payroll_file';
}

if ($relativePath === '') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Requested document is not available for this cycle']);
    exit;
}

$absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
$allowedRoot = realpath(__DIR__ . '/../uploads/payroll');

if ($absolutePath === false || $allowedRoot === false || strpos($absolutePath, $allowedRoot) !== 0 || !is_file($absolutePath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Document file was not found']);
    exit;
}

$mime = $storedMime;
if ($mime === '' || stripos($mime, 'octet-stream') !== false) {
    $detected = @mime_content_type($absolutePath);
    if ($detected) {
        $mime = $detected;
    } else {
        $mime = 'application/octet-stream';
    }
}

$safeName = basename($displayName !== '' ? $displayName : basename($absolutePath));
$previewable = str_starts_with(strtolower($mime), 'image/')
    || in_array(strtolower($mime), ['application/pdf', 'text/plain', 'text/csv'], true);
$previewEnabled = getAppSettingBool($conn, 'document_preview_enabled', true);
$disposition = (!$download && $previewEnabled && $previewable) ? 'inline' : 'attachment';

logPayrollAudit($conn, [
    'cycle_id' => $cycleId,
    'action' => $auditAction,
    'actor_user_id' => $_SESSION['userId'] ?? '',
    'actor_role' => $_SESSION['userRole'] ?? '',
    'details' => [
        'type' => $docType,
        'path' => $relativePath,
        'download' => $download ? 1 : 0
    ]
]);

// Binary Office/PDF files must start at byte zero. Some shared bootstrap files or
// server extensions may leave output buffering active; even one leading newline
// makes Excel report an otherwise valid XLSX package as corrupt.
while (ob_get_level() > 0) {
    ob_end_clean();
}
if (function_exists('ini_set')) {
    @ini_set('zlib.output_compression', '0');
}
session_write_close();

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($absolutePath));
header('X-Content-Type-Options: nosniff');
header('Content-Transfer-Encoding: binary');
header(
    'Content-Disposition: ' . $disposition
    . '; filename="' . str_replace(['"', "\r", "\n"], ['', '', ''], $safeName) . '"'
    . "; filename*=UTF-8''" . rawurlencode($safeName)
);
header('Cache-Control: private, max-age=120');

$stream = fopen($absolutePath, 'rb');
if ($stream === false) {
    http_response_code(500);
    exit;
}
fpassthru($stream);
fclose($stream);
exit;
