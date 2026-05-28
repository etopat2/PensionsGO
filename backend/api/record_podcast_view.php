<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

ensurePodcastTables($conn);
if (!getAppSettingBool($conn, 'podcast_log_views', true)) {
    echo json_encode(['success' => true, 'message' => 'View logging disabled.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$podcastId = (int)($input['podcast_id'] ?? 0);
if ($podcastId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid podcast video.']);
    exit;
}

$viewerId = null;
$viewerRole = 'public';
$sessionId = session_id();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['userId'])) {
    $viewerId = (string)$_SESSION['userId'];
    $viewerRole = normalizeRoleKey((string)($_SESSION['userRole'] ?? 'user')) ?: 'user';
}
$stmt = $conn->prepare('INSERT INTO tb_podcast_views (podcast_id, viewer_id, viewer_role, session_id) VALUES (?, ?, ?, ?)');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to record podcast view.']);
    exit;
}
$stmt->bind_param('isss', $podcastId, $viewerId, $viewerRole, $sessionId);
$stmt->execute();
$stmt->close();

if ($viewerId && getAppSettingBool($conn, 'enable_activity_logs', true)) {
    logUserActivity($conn, [
        'user_id' => $viewerId,
        'user_name' => (string)($_SESSION['userName'] ?? 'User'),
        'user_role' => $viewerRole,
        'activity_type' => 'podcast_view',
        'details' => 'Opened podcast video #' . $podcastId,
        'status' => 'success'
    ]);
}

echo json_encode(['success' => true]);
$conn->close();
?>

