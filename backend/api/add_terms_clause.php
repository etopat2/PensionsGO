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

ensureTermsTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$title = trim((string)($payload['title'] ?? ''));
$body = trim((string)($payload['body'] ?? ''));
$topics = trim((string)($payload['topics'] ?? 'operations'));
$sectionKey = trim((string)($payload['section_key'] ?? 'operational'));
$sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : 0;
$active = array_key_exists('is_active', $payload) ? (!empty($payload['is_active']) ? 1 : 0) : 1;

if ($title === '' || $body === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Title and clause body are required.']);
    exit;
}

if ($topics === '') {
    $topics = 'operations';
}

if ($sectionKey === '') {
    $sectionKey = 'operational';
}

if ($sortOrder <= 0) {
    $result = $conn->query("SELECT MAX(sort_order) AS max_sort FROM tb_terms_clauses");
    $row = $result ? $result->fetch_assoc() : null;
    $sortOrder = ((int)($row['max_sort'] ?? 0)) + 1;
}

$stmt = $conn->prepare("
    INSERT INTO tb_terms_clauses (title, body, topics, section_key, sort_order, is_active)
    VALUES (?, ?, ?, ?, ?, ?)
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param('ssssii', $title, $body, $topics, $sectionKey, $sortOrder, $active);
$ok = $stmt->execute();
$clauseId = $stmt->insert_id;
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save clause.']);
    exit;
}

logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
    'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
    'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
    'action' => 'terms_clause_created',
    'entity_type' => 'terms_clause',
    'entity_id' => (string)$clauseId,
    'details' => [
        'title' => $title,
        'section_key' => $sectionKey,
        'active' => $active ? 'yes' : 'no'
    ]
]);

echo json_encode(['success' => true, 'message' => 'Clause added.', 'clause_id' => $clauseId]);
$conn->close();
?>
