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

$stmt = $conn->prepare("
    SELECT Id, priUnit, polDistrict, priDistrict, priRegion, polRegion
    FROM tb_priunits
    ORDER BY priUnit ASC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$units = [];

while ($row = $result->fetch_assoc()) {
    $units[] = [
        'Id' => (int)$row['Id'],
        'priUnit' => $row['priUnit'],
        'polDistrict' => $row['polDistrict'],
        'priDistrict' => $row['priDistrict'],
        'priRegion' => $row['priRegion'],
        'polRegion' => $row['polRegion']
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'units' => $units]);
$conn->close();
?>
