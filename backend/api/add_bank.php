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

ensureBanksTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$bankName = trim((string)($payload['bank_name'] ?? ''));
$shortName = trim((string)($payload['short_name'] ?? ''));
$bankCode = strtoupper(trim((string)($payload['bank_code'] ?? '')));
$displayOrder = isset($payload['display_order']) ? (int)$payload['display_order'] : 0;

if ($bankName === '') {
    echo json_encode(['success' => false, 'message' => 'Bank name is required']);
    exit;
}

$dupStmt = $conn->prepare("SELECT bank_id FROM tb_banks WHERE LOWER(bank_name) = LOWER(?) LIMIT 1");
if (!$dupStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to validate bank name uniqueness']);
    exit;
}
$dupStmt->bind_param("s", $bankName);
$dupStmt->execute();
$dupResult = $dupStmt->get_result();
$duplicate = $dupResult ? $dupResult->fetch_assoc() : null;
$dupStmt->close();

if ($duplicate) {
    echo json_encode(['success' => false, 'message' => 'Bank name already exists.']);
    exit;
}

$shortName = $shortName !== '' ? $shortName : null;
$bankCode = $bankCode !== '' ? $bankCode : null;
$displayOrder = max(0, $displayOrder);

$stmt = $conn->prepare("
    INSERT INTO tb_banks (bank_name, short_name, bank_code, display_order, is_active)
    VALUES (?, ?, ?, ?, 1)
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert']);
    exit;
}

$stmt->bind_param("sssi", $bankName, $shortName, $bankCode, $displayOrder);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'message' => 'Bank added.']);
$conn->close();
?>
