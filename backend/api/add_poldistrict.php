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

$polDistrict = trim((string)($payload['polDistrict'] ?? ''));
$polRegion = trim((string)($payload['polRegion'] ?? ''));

if ($polDistrict === '' || $polRegion === '') {
    echo json_encode(['success' => false, 'message' => 'District and region are required']);
    exit;
}

$polDistrict = normalizePoliticalDistrictName($polDistrict);
$polRegion = normalizePoliticalDistrictName($polRegion);

$allowedRegions = ['Northern', 'Eastern', 'Central', 'Western'];
if (!in_array($polRegion, $allowedRegions, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid political region']);
    exit;
}

$stmt = $conn->prepare("INSERT INTO tb_poldistricts (polDistrict, polRegion) VALUES (?, ?)");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("ss", $polDistrict, $polRegion);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Political district added.']);
$conn->close();
?>
