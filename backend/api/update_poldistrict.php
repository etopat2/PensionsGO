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

ensurePoliticalDistrictsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$polId = isset($payload['pol_id']) ? (int)$payload['pol_id'] : 0;
$polDistrict = trim((string)($payload['polDistrict'] ?? ''));
$polRegion = trim((string)($payload['polRegion'] ?? ''));

if ($polId <= 0 || $polDistrict === '' || $polRegion === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid political district']);
    exit;
}

$polDistrict = normalizePoliticalDistrictName($polDistrict);
$polRegion = normalizePoliticalDistrictName($polRegion);

$allowedRegions = ['Northern', 'Eastern', 'Central', 'Western'];
if (!in_array($polRegion, $allowedRegions, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid political region']);
    exit;
}

$stmt = $conn->prepare("UPDATE tb_poldistricts SET polDistrict = ?, polRegion = ? WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param("ssi", $polDistrict, $polRegion, $polId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Political district updated.']);
$conn->close();
?>
