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
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$normalizePodcastDescription = static function ($value): string {
    $text = str_replace(["\r\n", "\r"], "\n", trim((string)$value));
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    return (string)$text;
};

$podcastId = (int)($input['podcast_id'] ?? 0);
$title = trim((string)($input['title'] ?? ''));
$description = $normalizePodcastDescription($input['description'] ?? '');
$audience = strtolower(trim((string)($input['audience'] ?? 'public')));
$youtubeUrl = trim((string)($input['youtube_url'] ?? ''));
$tags = trim((string)($input['tags'] ?? ''));
$isFeatured = filter_var($input['is_featured'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
$isPublished = filter_var($input['is_published'] ?? true, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
$sortOrder = max(0, (int)($input['sort_order'] ?? 0));

if ($title === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Video title is required.']);
    exit;
}
if (!in_array($audience, ['public', 'staff', 'pensioner'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid audience.']);
    exit;
}
$youtubeId = extractPodcastYouTubeId($youtubeUrl);
if ($youtubeId === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid YouTube link or video ID.']);
    exit;
}

$userId = (string)($_SESSION['userId'] ?? 'system');
$userName = (string)($_SESSION['userName'] ?? 'Administrator');
$userRole = (string)($_SESSION['userRole'] ?? 'admin');

if ($podcastId > 0) {
    $existingStmt = $conn->prepare('SELECT title, description, audience, youtube_url, tags, is_featured, is_published, sort_order FROM tb_podcast_videos WHERE podcast_id = ? LIMIT 1');
    if (!$existingStmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load existing video.']);
        exit;
    }
    $existingStmt->bind_param('i', $podcastId);
    $existingStmt->execute();
    $existingVideo = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if (!$existingVideo) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Podcast video not found.']);
        exit;
    }

    $replaceAllowed = getAppSettingBool($conn, 'podcast_allow_video_replace', true);
    $metadataEditAllowed = getAppSettingBool($conn, 'podcast_allow_metadata_edit', true);
    $sourceChanged = trim((string)$existingVideo['youtube_url']) !== $youtubeUrl;
    $existingDescription = $normalizePodcastDescription($existingVideo['description'] ?? '');
    $metadataChanged =
        trim((string)$existingVideo['title']) !== $title ||
        $existingDescription !== $description ||
        trim((string)$existingVideo['audience']) !== $audience ||
        trim((string)$existingVideo['tags']) !== $tags ||
        (int)$existingVideo['is_featured'] !== $isFeatured ||
        (int)$existingVideo['is_published'] !== $isPublished ||
        (int)$existingVideo['sort_order'] !== $sortOrder;

    if ($sourceChanged && !$replaceAllowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Video replacement is disabled in podcast settings.']);
        exit;
    }

    if ($metadataChanged && !$metadataEditAllowed) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Podcast metadata editing is disabled in settings.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE tb_podcast_videos SET title = ?, description = ?, audience = ?, youtube_url = ?, youtube_id = ?, tags = ?, is_featured = ?, is_published = ?, sort_order = ?, updated_by = ? WHERE podcast_id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update video.']);
        exit;
    }
    $stmt->bind_param('ssssssiiisi', $title, $description, $audience, $youtubeUrl, $youtubeId, $tags, $isFeatured, $isPublished, $sortOrder, $userId, $podcastId);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update video.']);
        exit;
    }
    logAuditEvent($conn, [
        'actor_id' => $userId,
        'actor_name' => $userName,
        'actor_role' => $userRole,
        'action' => 'podcast_video_updated',
        'entity_type' => 'podcast_video',
        'entity_id' => (string)$podcastId,
        'details' => ['title' => $title, 'audience' => $audience, 'published' => (bool)$isPublished, 'video_replaced' => $sourceChanged]
    ]);
    echo json_encode(['success' => true, 'message' => 'Podcast video updated successfully.']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO tb_podcast_videos (title, description, audience, youtube_url, youtube_id, tags, is_featured, is_published, sort_order, created_by, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save video.']);
    exit;
}
$stmt->bind_param('ssssssiiiss', $title, $description, $audience, $youtubeUrl, $youtubeId, $tags, $isFeatured, $isPublished, $sortOrder, $userId, $userId);
$ok = $stmt->execute();
$newId = $stmt->insert_id;
$stmt->close();
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save video.']);
    exit;
}
logAuditEvent($conn, [
    'actor_id' => $userId,
    'actor_name' => $userName,
    'actor_role' => $userRole,
    'action' => 'podcast_video_created',
    'entity_type' => 'podcast_video',
    'entity_id' => (string)$newId,
    'details' => ['title' => $title, 'audience' => $audience, 'published' => (bool)$isPublished]
]);
echo json_encode(['success' => true, 'message' => 'Podcast video saved successfully.', 'podcast_id' => $newId]);
$conn->close();
?>
