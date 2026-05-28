<?php

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../notification_sound_library.php';

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

$file = $_FILES['sound'];
$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'The sound upload could not be completed.'
    ]);
    exit;
}

$originalName = (string)($file['name'] ?? 'notification-sound');
$tmpPath = (string)($file['tmp_name'] ?? '');
$sizeBytes = (int)($file['size'] ?? 0);
$maxUploadBytes = 5 * 1024 * 1024;

if ($sizeBytes <= 0 || !is_uploaded_file($tmpPath)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'The uploaded sound file is invalid.'
    ]);
    exit;
}

if ($sizeBytes > $maxUploadBytes) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Sound files must be 5 MB or smaller.'
    ]);
    exit;
}

$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($extension, notificationAllowedSoundExtensions(), true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Supported sound formats are MP3, WAV, OGG, and M4A.'
    ]);
    exit;
}

$detectedMime = '';
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file($tmpPath);
}

if ($detectedMime !== '' && !in_array($detectedMime, notificationAllowedSoundMimeTypes(), true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'That file does not appear to be a supported audio format.'
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

