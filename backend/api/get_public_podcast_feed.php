<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';
ensurePodcastTables($conn);

if (!getAppSettingBool($conn, 'podcast_enabled', true) || !getAppSettingBool($conn, 'podcast_public_enabled', true)) {
    echo json_encode(['success' => true, 'items' => [], 'featured' => null, 'ctaEnabled' => false], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$search = trim((string)($_GET['search'] ?? ''));
$where = ['p.is_published = 1', "p.audience = 'public'"];
$types = '';
$params = [];
if ($search !== '') {
    $where[] = '(p.title LIKE ? OR p.description LIKE ? OR p.tags LIKE ?)';
    $types = 'sss';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term];
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
if ($types !== '') {
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}
$stmt->execute();
$result = $stmt->get_result();
$items = [];
while ($row = $result->fetch_assoc()) {
    $items[] = [
        'id' => (int)$row['podcast_id'],
        'title' => (string)$row['title'],
        'description' => (string)($row['description'] ?? ''),
        'audience' => 'public',
        'audienceLabel' => 'Public',
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
}
$stmt->close();
$featured = null;
foreach ($items as $item) {
    if ($item['isFeatured']) { $featured = $item; break; }
}
if ($featured === null && $items) { $featured = $items[0]; }

echo json_encode([
    'success' => true,
    'items' => $items,
    'featured' => $featured,
    'ctaEnabled' => getAppSettingBool($conn, 'podcast_show_public_about_button', true)
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$conn->close();
?>
