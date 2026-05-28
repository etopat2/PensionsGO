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

ensurePrisonRegionsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$priRegion = trim((string)($payload['priRegion'] ?? ''));
if ($priRegion === '') {
    echo json_encode(['success' => false, 'message' => 'Region name is required']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO tb_priregions (priRegion) VALUES (?)");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("s", $priRegion);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Prison region added.']);
$conn->close();
?>
