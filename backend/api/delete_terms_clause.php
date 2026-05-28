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

$clauseId = isset($payload['clause_id']) ? (int)$payload['clause_id'] : 0;
if ($clauseId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid clause.']);
    exit;
}

$title = '';
$lookup = $conn->prepare('SELECT title FROM tb_terms_clauses WHERE clause_id = ?');
if ($lookup) {
    $lookup->bind_param('i', $clauseId);
    $lookup->execute();
    $result = $lookup->get_result();
    if ($row = $result->fetch_assoc()) {
        $title = (string)$row['title'];
    }
    $lookup->close();
}

$stmt = $conn->prepare('DELETE FROM tb_terms_clauses WHERE clause_id = ?');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param('i', $clauseId);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete clause.']);
    exit;
}

logAuditEvent($conn, [
    'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
    'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
    'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
    'action' => 'terms_clause_deleted',
    'entity_type' => 'terms_clause',
    'entity_id' => (string)$clauseId,
    'details' => [
        'title' => $title
    ]
]);

echo json_encode(['success' => true, 'message' => 'Clause deleted.']);
$conn->close();
?>
