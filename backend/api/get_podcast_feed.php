<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensurePodcastTables($conn);
if (!getAppSettingBool($conn, 'podcast_enabled', true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Podcast is currently unavailable.']);
    exit;
}

$role = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
$allowed = array_values(array_filter(getAllowedPodcastAudiencesForRole($role), function ($audience) use ($conn) {
    return match ($audience) {
        'public' => getAppSettingBool($conn, 'podcast_public_enabled', true),
        'staff' => getAppSettingBool($conn, 'podcast_staff_enabled', true),
        'pensioner' => getAppSettingBool($conn, 'podcast_pensioner_enabled', true),
        default => false,
    };
}));
if (!$allowed) {
    echo json_encode(['success' => true, 'items' => [], 'featured' => null, 'categories' => [], 'settings' => []]);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$requestedAudience = strtolower(trim((string)($_GET['audience'] ?? 'all')));
$where = ['p.is_published = 1'];
$types = str_repeat('s', count($allowed));
$params = $allowed;
$placeholders = implode(',', array_fill(0, count($allowed), '?'));
$where[] = 'p.audience IN (' . $placeholders . ')';
if (in_array($requestedAudience, $allowed, true)) {
    $where[] = 'p.audience = ?';
    $types .= 's';
    $params[] = $requestedAudience;
}
if ($search !== '') {
    $where[] = '(p.title LIKE ? OR p.description LIKE ? OR p.tags LIKE ?)';
    $types .= 'sss';
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql = "
    SELECT p.*, COALESCE(v.view_count, 0) AS view_count
    FROM tb_podcast_videos p
    LEFT JOIN (
        SELECT podcast_id, COUNT(*) AS view_count
        FROM tb_podcast_views
        GROUP BY podcast_id
    ) v ON v.podcast_id = p.podcast_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.is_featured DESC, p.sort_order ASC, p.updated_at DESC
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load podcast feed.']);
    exit;
}
$bind = [$types];
foreach ($params as $index => $value) {
    $bind[] = &$params[$index];
}
call_user_func_array([$stmt, 'bind_param'], $bind);
$stmt->execute();
$result = $stmt->get_result();
$items = [];
$categories = [];
while ($row = $result->fetch_assoc()) {
    $audienceKey = (string)$row['audience'];
    $item = [
        'id' => (int)$row['podcast_id'],
        'title' => (string)$row['title'],
        'description' => (string)($row['description'] ?? ''),
        'audience' => $audienceKey,
        'audienceLabel' => getPodcastAudienceLabel($audienceKey),
        'youtubeUrl' => (string)$row['youtube_url'],
        'youtubeId' => (string)$row['youtube_id'],
        'embedUrl' => buildPodcastEmbedUrl((string)$row['youtube_id']),
        'thumbnailUrl' => 'https://img.youtube.com/vi/' . rawurlencode((string)$row['youtube_id']) . '/hqdefault.jpg',
        'tags' => array_values(array_filter(array_map('trim', explode(',', (string)($row['tags'] ?? ''))))),
        'isFeatured' => (bool)$row['is_featured'],
        'publishedAt' => (string)($row['created_at'] ?? ''),
        'updatedAt' => (string)($row['updated_at'] ?? ''),
        'viewCount' => (int)$row['view_count']
    ];
    $items[] = $item;
    $categories[$audienceKey] = getPodcastAudienceLabel($audienceKey);
}
$stmt->close();

$featured = null;
foreach ($items as $item) {
    if ($item['isFeatured']) {
        $featured = $item;
        break;
    }
}
if ($featured === null && $items) {
    $featured = $items[0];
}

echo json_encode([
    'success' => true,
    'items' => $items,
    'featured' => $featured,
    'categories' => $categories,
    'settings' => [
        'podcast_log_views' => getAppSettingBool($conn, 'podcast_log_views', true),
        'role' => $role
    ]
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$conn->close();
?>
