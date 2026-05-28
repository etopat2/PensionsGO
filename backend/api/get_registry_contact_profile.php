<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
$canUseLifeCertTools = currentUserHasPermission($conn, 'registry.life_certificate.submit');
$canEditRegistry = currentUserHasPermission($conn, 'registry.edit');
if (!$canUseLifeCertTools && !$canEditRegistry) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}
if (function_exists('ensureStaffDueExtendedColumns')) {
    ensureStaffDueExtendedColumns($conn);
}

$regNo = trim((string)($_GET['regNo'] ?? ''));
if ($regNo === '') {
    echo json_encode(['success' => false, 'message' => 'File number is required']);
    exit;
}

$stmt = $conn->prepare("\n    SELECT\n        fr.id,\n        fr.regNo,\n        TRIM(CONCAT_WS(' ',\n            COALESCE(NULLIF(fr.sName, ''), NULLIF(sd.sName, ''), ''),\n            COALESCE(NULLIF(fr.fName, ''), NULLIF(sd.fName, ''), '')\n        )) AS pensioner_name,\n        COALESCE(NULLIF(fr.telNo, ''), sd.telNo, '') AS telNo,\n        COALESCE(NULLIF(fr.address, ''), sd.address, '') AS address,\n        COALESCE(NULLIF(fr.next_of_kin, ''), sd.next_of_kin, '') AS next_of_kin,\n        COALESCE(NULLIF(fr.next_of_kin_contact, ''), sd.next_of_kin_contact, '') AS next_of_kin_contact,\n        COALESCE(NULLIF(fr.bank_name, ''), sd.bank_name, '') AS bank_name,\n        COALESCE(NULLIF(fr.bank_account, ''), sd.bank_account, '') AS bank_account,\n        COALESCE(NULLIF(fr.bank_branch, ''), sd.bank_branch, '') AS bank_branch\n    FROM tb_fileregistry fr\n    LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo\n    WHERE fr.regNo = ?\n    LIMIT 1\n");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load beneficiary profile']);
    exit;
}

$stmt->bind_param('s', $regNo);
$stmt->execute();
$result = $stmt->get_result();
$record = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Registry record not found']);
    exit;
}

echo json_encode([
    'success' => true,
    'record' => $record
]);

$conn->close();
?>
