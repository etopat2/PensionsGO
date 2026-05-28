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
if ($regionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid region']);
    exit;
}

$stmt = $conn->prepare("SELECT priRegion FROM tb_priregions WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare lookup']);
    exit;
}
$stmt->bind_param("i", $regionId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

$regionName = trim((string)($row['priRegion'] ?? ''));
if ($regionName !== '') {
    $usageStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tb_priunits WHERE priRegion = ?");
    if ($usageStmt) {
        $usageStmt->bind_param("s", $regionName);
        $usageStmt->execute();
        $usageResult = $usageStmt->get_result();
        $usageRow = $usageResult ? $usageResult->fetch_assoc() : null;
        $usageStmt->close();
        $total = (int)($usageRow['total'] ?? 0);
        if ($total > 0) {
            echo json_encode([
                'success' => false,
                'message' => "Region is linked to {$total} unit(s). Update units before deleting."
            ]);
            exit;
        }
    }
}

$stmt = $conn->prepare("DELETE FROM tb_priregions WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param("i", $regionId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Prison region deleted.']);
$conn->close();
?>
