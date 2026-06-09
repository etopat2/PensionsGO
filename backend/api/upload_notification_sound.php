<?php

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../notification_sound_library.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required.'
    ]);
    exit;
}

if (!isset($_FILES['sound']) || !is_array($_FILES['sound'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Choose a sound file to upload.'
    ]);
    exit;
}

try {
    $file = $_FILES['sound'];
    $upload = assertUploadedFileIsSafe($conn, $file, notificationAllowedSoundExtensions(), notificationAllowedSoundMimeTypes(), 'Notification sound');
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'The sound upload could not be completed.'
    ]);
    exit;
}

$originalName = (string)$upload['original_name'];
$tmpPath = (string)$upload['tmp_name'];
$sizeBytes = (int)$upload['file_size'];
$maxUploadBytes = 5 * 1024 * 1024;
if ($sizeBytes > $maxUploadBytes) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Sound files must be 5 MB or smaller.'
    ]);
    exit;
}

$scanResult = runVirusScanOnFile($conn, $tmpPath, [
    'storage_context' => 'notification_sound',
    'file_name' => $originalName,
    'file_path' => null,
    'mime_type' => (string)$upload['mime_type'],
    'scanned_by' => $_SESSION['userId'] ?? null,
    'scanned_by_name' => $_SESSION['userName'] ?? null,
    'scanned_by_role' => $_SESSION['userRole'] ?? null
]);
if (($scanResult['enabled'] ?? true) && in_array(($scanResult['status'] ?? ''), ['infected', 'suspicious', 'error'], true)) {
    http_response_code(422);
    $reason = trim((string)($scanResult['findings'] ?? 'Sound upload failed the configured virus scan.'));
    echo json_encode([
        'success' => false,
        'message' => $reason !== '' ? $reason : 'Sound upload failed the configured virus scan.'
    ]);
    exit;
}

$targetDirectory = notificationEnsureCustomSoundDirectory();
$targetFileName = notificationGenerateUploadFilename($originalName);
$targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $targetFileName;

if (!move_uploaded_file($tmpPath, $targetPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to store the uploaded sound file.'
    ]);
    exit;
}

$soundPath = notificationCustomSoundWebDirectory() . '/' . $targetFileName;
$sound = notificationFindSoundByPath($soundPath);
$library = notificationGetSoundLibrary();

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'Administrator',
        'actor_role' => $_SESSION['userRole'] ?? 'admin',
        'action' => 'notification_sound_uploaded',
        'entity_type' => 'notification_sound',
        'entity_id' => $soundPath,
        'details' => [
            'file_name' => basename($targetFileName),
            'original_name' => $originalName,
            'size_bytes' => $sizeBytes
        ]
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Notification sound uploaded successfully.',
    'sound' => $sound,
    'sounds' => $library
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (isset($conn)) {
    $conn->close();
}
