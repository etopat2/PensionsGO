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

$library = notificationGetSoundLibrary();
$storedPath = getAppSetting($conn, 'notify_broadcast_sound_path');
$selectedPath = notificationResolveSelectedSoundPath($storedPath ?? 'audio/notification.mp3', $library);

echo json_encode([
    'success' => true,
    'sounds' => $library,
    'selected_path' => $selectedPath,
    'supported_extensions' => notificationAllowedSoundExtensions(),
    'max_upload_mb' => 5
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (isset($conn)) {
    $conn->close();
}
