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

$districtId = isset($payload['district_id']) ? (int)$payload['district_id'] : 0;
$priDistrict = trim((string)($payload['priDistrict'] ?? ''));
$priRegion = trim((string)($payload['priRegion'] ?? ''));

if ($districtId <= 0 || $priDistrict === '' || $priRegion === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid district']);
    exit;
}

$stmt = $conn->prepare("UPDATE tb_pridistricts SET priDistrict = ?, priRegion = ? WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param("ssi", $priDistrict, $priRegion, $districtId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Prison district updated.']);
$conn->close();
?>
