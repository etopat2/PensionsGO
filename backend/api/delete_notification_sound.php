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

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$soundPath = notificationNormalizeSoundPath((string)($input['path'] ?? ''));
$sound = notificationFindSoundByPath($soundPath);

if (!$sound) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Notification sound not found.'
    ]);
    exit;
}

if (!empty($sound['is_builtin'])) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Built-in notification sounds cannot be deleted.'
    ]);
    exit;
}

if (!notificationDeleteCustomSound($soundPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to delete the selected sound file.'
    ]);
    exit;
}

$library = notificationGetSoundLibrary();
$storedPath = getAppSetting($conn, 'notify_broadcast_sound_path');
$currentSelectedPath = $storedPath ?? 'audio/notification.mp3';
$selectedPath = notificationResolveSelectedSoundPath($currentSelectedPath, $library);
if ($selectedPath !== $currentSelectedPath) {
    setAppSetting($conn, 'notify_broadcast_sound_path', $selectedPath);
}

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'Administrator',
        'actor_role' => $_SESSION['userRole'] ?? 'admin',
        'action' => 'notification_sound_deleted',
        'entity_type' => 'notification_sound',
        'entity_id' => $soundPath,
        'details' => [
            'file_name' => $sound['file_name'] ?? basename($soundPath)
        ]
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Notification sound deleted successfully.',
    'sounds' => $library,
    'selected_path' => $selectedPath
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (isset($conn)) {
    $conn->close();
}
