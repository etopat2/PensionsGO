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
if ($polId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid political district']);
    exit;
}

$stmt = $conn->prepare("SELECT polDistrict FROM tb_poldistricts WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare lookup']);
    exit;
}
$stmt->bind_param("i", $polId);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

$districtName = trim((string)($row['polDistrict'] ?? ''));
if ($districtName !== '') {
    $usageStmt = $conn->prepare("SELECT COUNT(*) AS total FROM tb_priunits WHERE polDistrict = ?");
    if ($usageStmt) {
        $usageStmt->bind_param("s", $districtName);
        $usageStmt->execute();
        $usageResult = $usageStmt->get_result();
        $usageRow = $usageResult ? $usageResult->fetch_assoc() : null;
        $usageStmt->close();
        $total = (int)($usageRow['total'] ?? 0);
        if ($total > 0) {
            echo json_encode([
                'success' => false,
                'message' => "District is linked to {$total} unit(s). Update units before deleting."
            ]);
            exit;
        }
    }
}

$stmt = $conn->prepare("DELETE FROM tb_poldistricts WHERE Id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare delete']);
    exit;
}

$stmt->bind_param("i", $polId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Political district deleted.']);
$conn->close();
?>
