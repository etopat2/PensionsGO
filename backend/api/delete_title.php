<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

ensureTitlesTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$titleId = isset($payload['title_id']) ? (int)$payload['title_id'] : 0;

if ($titleId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid title']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM tb_titles WHERE title_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param("i", $titleId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Title deleted.']);
$conn->close();
?>
