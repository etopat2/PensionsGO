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

$regionId = isset($payload['region_id']) ? (int)$payload['region_id'] : 0;
$priRegion = trim((string)($payload['priRegion'] ?? ''));

if ($regionId <= 0 || $priRegion === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid region']);
    exit;
}

$stmt = $conn->prepare("UPDATE tb_priregions SET priRegion = ? WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param("si", $priRegion, $regionId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Prison region updated.']);
$conn->close();
?>
