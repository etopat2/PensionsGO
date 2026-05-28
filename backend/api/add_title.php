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
$titleName = isset($payload['title_name']) ? trim($payload['title_name']) : '';
$category = isset($payload['category']) ? trim($payload['category']) : 'uniformed';
$level = isset($payload['level']) ? trim($payload['level']) : 'junior';

if ($titleName === '') {
    echo json_encode(['success' => false, 'message' => 'Title name is required']);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO tb_titles (title_name, category, level, is_active)
    VALUES (?, ?, ?, 1)
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("sss", $titleName, $category, $level);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Title added.']);
$conn->close();
?>
