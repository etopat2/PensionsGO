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
if ($faqId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid FAQ entry.']);
    exit;
}

$question = '';
$lookup = $conn->prepare('SELECT question FROM tb_faq_entries WHERE faq_id = ?');
if ($lookup) {
    $lookup->bind_param('i', $faqId);
    $lookup->execute();
    $result = $lookup->get_result();
    if ($row = $result->fetch_assoc()) {
        $question = (string)$row['question'];
    }
    $lookup->close();
}

$stmt = $conn->prepare('DELETE FROM tb_faq_entries WHERE faq_id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param('i', $faqId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete FAQ entry.']);
    exit;
}

logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
    'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
    'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
    'action' => 'faq_entry_deleted',
    'entity_type' => 'faq_entry',
    'entity_id' => (string)$faqId,
    'details' => [
        'question' => $question
    ]
]);

echo json_encode(['success' => true, 'message' => 'FAQ entry deleted.']);
$conn->close();
?>
