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
ensureAppSettingsTable($conn);

$search = trim((string)($_GET['search'] ?? ''));
$audience = strtolower(trim((string)($_GET['audience'] ?? '')));
$status = strtolower(trim((string)($_GET['status'] ?? 'all')));

$where = [];
$types = '';
$params = [];

if (in_array($audience, ['public', 'staff', 'pensioner'], true)) {
    $where[] = 'p.audience = ?';
    $types .= 's';
    $params[] = $audience;
}
if ($status === 'published') {
    $where[] = 'p.is_published = 1';
} elseif ($status === 'draft') {
    $where[] = 'p.is_published = 0';
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
";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY p.is_featured DESC, p.sort_order ASC, p.updated_at DESC';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load podcast library.']);
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
    $row['podcast_id'] = (int)$row['podcast_id'];
    $row['is_featured'] = (bool)$row['is_featured'];
    $row['is_published'] = (bool)$row['is_published'];
    $row['sort_order'] = (int)$row['sort_order'];
    $row['view_count'] = (int)$row['view_count'];
    $row['audience_label'] = getPodcastAudienceLabel((string)$row['audience']);
    $row['embed_url'] = buildPodcastEmbedUrl((string)$row['youtube_id']);
    $row['thumbnail_url'] = ((string)$row['youtube_id'] !== '') ? 'https://img.youtube.com/vi/' . rawurlencode((string)$row['youtube_id']) . '/hqdefault.jpg' : '';
    $items[] = $row;
}
$stmt->close();

$stats = [
    'total' => count($items),
    'published' => 0,
    'featured' => 0,
    'public' => 0,
    'staff' => 0,
    'pensioner' => 0,
    'views' => 0
];
foreach ($items as $item) {
    if ($item['is_published']) $stats['published']++;
    if ($item['is_featured']) $stats['featured']++;
    if (isset($stats[$item['audience']])) $stats[$item['audience']]++;
    $stats['views'] += (int)$item['view_count'];
}

$settings = [
    'podcast_enabled' => getAppSettingBool($conn, 'podcast_enabled', true),
    'podcast_public_enabled' => getAppSettingBool($conn, 'podcast_public_enabled', true),
    'podcast_staff_enabled' => getAppSettingBool($conn, 'podcast_staff_enabled', true),
    'podcast_pensioner_enabled' => getAppSettingBool($conn, 'podcast_pensioner_enabled', true),
    'podcast_show_public_about_button' => getAppSettingBool($conn, 'podcast_show_public_about_button', true),
    'podcast_log_views' => getAppSettingBool($conn, 'podcast_log_views', true),
    'podcast_allow_metadata_edit' => getAppSettingBool($conn, 'podcast_allow_metadata_edit', true),
    'podcast_allow_video_replace' => getAppSettingBool($conn, 'podcast_allow_video_replace', true),
    'podcast_allow_delete' => getAppSettingBool($conn, 'podcast_allow_delete', true)
];

echo json_encode(['success' => true, 'items' => $items, 'stats' => $stats, 'settings' => $settings], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$conn->close();
?>
