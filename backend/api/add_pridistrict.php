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

ensurePrisonDistrictsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$priDistrict = trim((string)($payload['priDistrict'] ?? ''));
$priRegion = trim((string)($payload['priRegion'] ?? ''));
if ($priDistrict === '' || $priRegion === '') {
    echo json_encode(['success' => false, 'message' => 'District and region are required']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO tb_pridistricts (priDistrict, priRegion) VALUES (?, ?)");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("ss", $priDistrict, $priRegion);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Prison district added.']);
$conn->close();
?>
