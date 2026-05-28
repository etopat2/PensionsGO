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

ensureFaqTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$question = trim((string)($payload['question'] ?? ''));
$answer = trim((string)($payload['answer'] ?? ''));
$category = trim((string)($payload['category'] ?? 'applications'));
$audienceLabel = trim((string)($payload['audience_label'] ?? 'Pensioners, staff, and supervisors'));
$bulletsInput = $payload['bullets'] ?? [];
$featured = !empty($payload['is_featured']) ? 1 : 0;
$active = array_key_exists('is_active', $payload) ? (!empty($payload['is_active']) ? 1 : 0) : 1;
$sortOrder = isset($payload['sort_order']) ? (int)$payload['sort_order'] : 0;

$allowedCategories = ['applications', 'benefits', 'registry', 'claims', 'pensioners', 'security'];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'applications';
}

if ($question === '' || $answer === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Question and answer are required.']);
    exit;
}

if ($audienceLabel === '') {
    $audienceLabel = 'Pensioners, staff, and supervisors';
}

$bullets = [];
if (is_array($bulletsInput)) {
    foreach ($bulletsInput as $bullet) {
        $trimmed = trim((string)$bullet);
        if ($trimmed !== '') {
            $bullets[] = $trimmed;
        }
    }
} elseif (is_string($bulletsInput)) {
    $parts = preg_split('/\r\n|\r|\n/', $bulletsInput);
    foreach ($parts as $part) {
        $trimmed = trim((string)$part);
        if ($trimmed !== '') {
            $bullets[] = $trimmed;
        }
    }
}

$bulletsText = $bullets ? implode("\n", $bullets) : null;

if ($sortOrder <= 0) {
    $result = $conn->query("SELECT MAX(sort_order) AS max_sort FROM tb_faq_entries");
    $row = $result ? $result->fetch_assoc() : null;
    $sortOrder = ((int)($row['max_sort'] ?? 0)) + 1;
}

$stmt = $conn->prepare("
    INSERT INTO tb_faq_entries (question, answer, bullets, category, audience_label, is_featured, sort_order, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param(
    'sssssiii',
    $question,
    $answer,
    $bulletsText,
    $category,
    $audienceLabel,
    $featured,
    $sortOrder,
    $active
);

$ok = $stmt->execute();
$faqId = $stmt->insert_id;
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save FAQ entry.']);
    exit;
}

logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
    'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
    'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
    'action' => 'faq_entry_created',
    'entity_type' => 'faq_entry',
    'entity_id' => (string)$faqId,
    'details' => [
        'question' => $question,
        'category' => $category,
        'featured' => $featured ? 'yes' : 'no',
        'active' => $active ? 'yes' : 'no'
    ]
]);

echo json_encode(['success' => true, 'message' => 'FAQ entry added.', 'faq_id' => $faqId]);
$conn->close();
?>
