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
$titleName = isset($payload['title_name']) ? trim($payload['title_name']) : '';
$category = isset($payload['category']) ? trim($payload['category']) : 'uniformed';
$level = isset($payload['level']) ? trim($payload['level']) : 'junior';
$isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;

if ($titleId <= 0 || $titleName === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid title']);
    exit;
}

$stmt = $conn->prepare("
    UPDATE tb_titles
    SET title_name = ?, category = ?, level = ?, is_active = ?
    WHERE title_id = ?
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param("sssii", $titleName, $category, $level, $isActive, $titleId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Title updated.']);
$conn->close();
?>
