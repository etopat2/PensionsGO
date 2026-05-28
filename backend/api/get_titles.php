<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureTitlesTable($conn);

$activeOnly = isset($_GET['active_only']) ? (int)$_GET['active_only'] === 1 : false;

$sql = "
    SELECT title_id, title_name, category, level, is_active
    FROM tb_titles
";
if ($activeOnly) {
    $sql .= " WHERE is_active = 1 ";
}
$sql .= " ORDER BY category ASC, level ASC, title_name ASC ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$titles = [];

while ($row = $result->fetch_assoc()) {
    $titles[] = [
        'title_id' => (int)$row['title_id'],
        'title_name' => $row['title_name'],
        'category' => $row['category'],
        'level' => $row['level'],
        'is_active' => (int)$row['is_active'] === 1
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'titles' => $titles]);
$conn->close();
?>
