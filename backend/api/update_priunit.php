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

$payload = json_decode(file_get_contents('php://input'), true);
$id = isset($payload['Id']) ? (int)$payload['Id'] : 0;
$priUnit = isset($payload['priUnit']) ? trim($payload['priUnit']) : '';
$polDistrict = isset($payload['polDistrict']) ? trim($payload['polDistrict']) : '';
$priDistrict = isset($payload['priDistrict']) ? trim($payload['priDistrict']) : '';
$priRegion = isset($payload['priRegion']) ? trim($payload['priRegion']) : '';
$polRegion = isset($payload['polRegion']) ? trim($payload['polRegion']) : '';

if ($id <= 0 || $priUnit === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid unit']);
    exit;
}

$stmt = $conn->prepare("
    UPDATE tb_priunits
    SET priUnit = ?, polDistrict = ?, priDistrict = ?, priRegion = ?, polRegion = ?
    WHERE Id = ?
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare update']);
    exit;
}

$stmt->bind_param("sssssi", $priUnit, $polDistrict, $priDistrict, $priRegion, $polRegion, $id);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Unit updated.']);
$conn->close();
?>
