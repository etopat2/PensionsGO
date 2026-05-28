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

$stmt = $conn->prepare("
    SELECT d.Id AS pol_id, d.polDistrict, d.polRegion, COUNT(u.Id) AS unit_count
    FROM tb_poldistricts d
    LEFT JOIN tb_priunits u ON u.polDistrict = d.polDistrict
    GROUP BY d.Id, d.polDistrict, d.polRegion
    ORDER BY d.polDistrict ASC
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
        'pol_id' => (int)$row['pol_id'],
        'polDistrict' => (string)$row['polDistrict'],
        'polRegion' => (string)$row['polRegion'],
        'unit_count' => (int)($row['unit_count'] ?? 0)
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'districts' => $districts]);
$conn->close();
?>
