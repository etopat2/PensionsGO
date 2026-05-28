<?php
header('Content-Type: application/json; charset=UTF-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ensureTermsTables($conn);

$isAdmin = false;
if (isset($_SESSION['userId']) && sessionRoleIn($conn, ['admin'])) {
    $isAdmin = true;
}

$activeOnly = true;
if (isset($_GET['active_only'])) {
    $activeOnly = ((int)$_GET['active_only'] === 1);
}

if (!$isAdmin && !$activeOnly) {
    $activeOnly = true;
}

$where = [];
$types = '';
$params = [];

if ($activeOnly) {
    $where[] = 'is_active = 1';
}

$sectionKey = trim((string)($_GET['section_key'] ?? ''));
if (!$isAdmin) {
    $sectionKey = 'operational';
}
if ($sectionKey !== '') {
    $where[] = 'section_key = ?';
    $types .= 's';
    $params[] = $sectionKey;
}

$sql = "
    SELECT clause_id, title, body, topics, section_key, sort_order, is_active, updated_at
    FROM tb_terms_clauses
";
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY sort_order ASC, updated_at DESC, clause_id ASC ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
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
$clauses = [];

while ($row = $result->fetch_assoc()) {
    $clauses[] = [
        'clause_id' => (int)$row['clause_id'],
        'title' => (string)$row['title'],
        'body' => (string)$row['body'],
        'topics' => (string)$row['topics'],
        'section_key' => (string)$row['section_key'],
        'sort_order' => (int)$row['sort_order'],
        'is_active' => (int)$row['is_active'] === 1,
        'updated_at' => (string)($row['updated_at'] ?? '')
    ];
}

$stmt->close();

echo json_encode(['success' => true, 'clauses' => $clauses], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$conn->close();
?>
