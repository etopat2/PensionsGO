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

$stmt = $conn->prepare("
    SELECT d.Id AS district_id, d.priDistrict, d.priRegion, COUNT(u.Id) AS unit_count
    FROM tb_pridistricts d
    LEFT JOIN tb_priunits u ON u.priDistrict = d.priDistrict
    GROUP BY d.Id, d.priDistrict, d.priRegion
    ORDER BY d.priDistrict ASC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$districts = [];

while ($row = $result->fetch_assoc()) {
    $districts[] = [
        'district_id' => (int)$row['district_id'],
        'priDistrict' => (string)$row['priDistrict'],
        'priRegion' => (string)($row['priRegion'] ?? ''),
        'unit_count' => (int)($row['unit_count'] ?? 0)
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'districts' => $districts]);
$conn->close();
?>
