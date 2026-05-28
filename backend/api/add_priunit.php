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
$priUnit = isset($payload['priUnit']) ? trim($payload['priUnit']) : '';
$polDistrict = isset($payload['polDistrict']) ? trim($payload['polDistrict']) : '';
$priDistrict = isset($payload['priDistrict']) ? trim($payload['priDistrict']) : '';
$priRegion = isset($payload['priRegion']) ? trim($payload['priRegion']) : '';
$polRegion = isset($payload['polRegion']) ? trim($payload['polRegion']) : '';

if ($priUnit === '') {
    echo json_encode(['success' => false, 'message' => 'Unit name is required']);
    exit;
}

$stmt = $conn->prepare("
    INSERT INTO tb_priunits (priUnit, polDistrict, priDistrict, priRegion, polRegion)
    VALUES (?, ?, ?, ?, ?)
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("sssss", $priUnit, $polDistrict, $priDistrict, $priRegion, $polRegion);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Unit added.']);
$conn->close();
?>
