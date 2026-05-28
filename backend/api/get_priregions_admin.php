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

$stmt = $conn->prepare("
    SELECT r.Id AS region_id, r.priRegion, COUNT(u.Id) AS unit_count
    FROM tb_priregions r
    LEFT JOIN tb_priunits u ON u.priRegion = r.priRegion
    GROUP BY r.Id, r.priRegion
    ORDER BY r.priRegion ASC
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$regions = [];

while ($row = $result->fetch_assoc()) {
    $regions[] = [
        'region_id' => (int)$row['region_id'],
        'priRegion' => (string)$row['priRegion'],
        'unit_count' => (int)($row['unit_count'] ?? 0)
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'regions' => $regions]);
$conn->close();
?>
