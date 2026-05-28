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

$faqId = isset($payload['faq_id']) ? (int)$payload['faq_id'] : 0;
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

if ($faqId <= 0 || $question === '' || $answer === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid FAQ entry.']);
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

$stmt = $conn->prepare("
    UPDATE tb_faq_entries
    SET question = ?, answer = ?, bullets = ?, category = ?, audience_label = ?,
        is_featured = ?, sort_order = ?, is_active = ?
    WHERE faq_id = ?
");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param(
    'sssssiiii',
    $question,
    $answer,
    $bulletsText,
    $category,
    $audienceLabel,
    $featured,
    $sortOrder,
    $active,
    $faqId
);

$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update FAQ entry.']);
    exit;
}

logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
    'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
    'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
    'action' => 'faq_entry_updated',
    'entity_type' => 'faq_entry',
    'entity_id' => (string)$faqId,
    'details' => [
        'question' => $question,
        'category' => $category,
        'featured' => $featured ? 'yes' : 'no',
        'active' => $active ? 'yes' : 'no'
    ]
]);

echo json_encode(['success' => true, 'message' => 'FAQ entry updated.']);
$conn->close();
?>
