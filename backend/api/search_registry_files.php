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

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}

$term = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 15);
if ($limit <= 0 || $limit > 50) {
    $limit = 15;
}

if ($term === '') {
    echo json_encode(['success' => true, 'files' => []]);
    exit;
}

$like = '%' . $term . '%';
$sql = "
    SELECT regNo, sName, fName, computerNo, title, availability_status
    FROM tb_fileregistry
    WHERE COALESCE(is_deleted, 0) = 0
      AND (
       regNo LIKE ?
       OR computerNo LIKE ?
       OR title LIKE ?
       OR sName LIKE ?
       OR fName LIKE ?
       OR CONCAT_WS(' ', sName, fName) LIKE ?
       OR CONCAT_WS(' ', fName, sName) LIKE ?
      )
    ORDER BY regNo ASC
    LIMIT ?
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to search registry']);
    exit;
}

$stmt->bind_param("sssssssi", $like, $like, $like, $like, $like, $like, $like, $limit);
$stmt->execute();
$result = $stmt->get_result();
$files = [];
while ($row = $result->fetch_assoc()) {
    $files[] = [
        'regNo' => $row['regNo'],
        'name' => trim(($row['sName'] ?? '') . ' ' . ($row['fName'] ?? '')),
        'computerNo' => $row['computerNo'],
        'title' => $row['title'] ?? '',
        'availability_status' => $row['availability_status'] ?? 'in_shelf'
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'files' => $files]);
$conn->close();
?>
