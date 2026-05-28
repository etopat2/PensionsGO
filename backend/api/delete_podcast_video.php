<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

ensurePodcastTables($conn);
if (!getAppSettingBool($conn, 'podcast_allow_delete', true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Podcast deletion is disabled in settings.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$podcastId = (int)($input['podcast_id'] ?? 0);
if ($podcastId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid video to delete.']);
    exit;
}

$conn->query('DELETE FROM tb_podcast_views WHERE podcast_id = ' . $podcastId);
$stmt = $conn->prepare('DELETE FROM tb_podcast_videos WHERE podcast_id = ? LIMIT 1');
$stmt->bind_param('i', $podcastId);
$ok = $stmt->execute();
$stmt->close();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to delete video.']);
    exit;
}
logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
    'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
    'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
    'action' => 'podcast_video_deleted',
    'entity_type' => 'podcast_video',
    'entity_id' => (string)$podcastId,
    'details' => []
]);
echo json_encode(['success' => true, 'message' => 'Podcast video deleted successfully.']);
$conn->close();
?>
