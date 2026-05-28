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

$payload = json_decode(file_get_contents('php://input'), true);
$id = isset($payload['Id']) ? (int)$payload['Id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid unit']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM tb_priunits WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Unit deleted.']);
$conn->close();
?>
