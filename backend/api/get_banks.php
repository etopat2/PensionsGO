<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureBanksTable($conn);

$activeOnly = isset($_GET['active_only']) ? (int)$_GET['active_only'] === 1 : false;

$sql = "
    SELECT bank_id, bank_name, short_name, bank_code, display_order, is_active
    FROM tb_banks
";
if ($activeOnly) {
    $sql .= " WHERE is_active = 1 ";
}
$sql .= " ORDER BY display_order ASC, bank_name ASC ";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

$stmt->execute();
$result = $stmt->get_result();
$banks = [];

while ($row = $result->fetch_assoc()) {
    $banks[] = [
        'bank_id' => (int)$row['bank_id'],
        'bank_name' => $row['bank_name'],
        'short_name' => $row['short_name'],
        'bank_code' => $row['bank_code'],
        'display_order' => (int)($row['display_order'] ?? 0),
        'is_active' => (int)$row['is_active'] === 1
    ];
}

$stmt->close();
echo json_encode(['success' => true, 'banks' => $banks]);
$conn->close();
?>
