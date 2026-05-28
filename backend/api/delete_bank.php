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

ensureBanksTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$bankId = isset($payload['bank_id']) ? (int)$payload['bank_id'] : 0;

if ($bankId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid bank']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM tb_banks WHERE bank_id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param("i", $bankId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Bank deleted.']);
$conn->close();
?>
