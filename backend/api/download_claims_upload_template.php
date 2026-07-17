<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/xlsx_upload_template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'claims.arrears.manage')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$columns = [
    'Pension Number', 'Claim Type', 'Period Year', 'Period Month', 'Expected Amount',
    'Start Period', 'End Period', 'Reason', 'Notes', 'Source Type', 'Claim Status'
];

$sampleRegNo = 'UPS/RET/0001';
$regResult = $conn->query("SELECT regNo FROM tb_fileregistry ORDER BY id ASC LIMIT 1");
if ($regResult) {
    $row = $regResult->fetch_assoc();
    if (!empty($row['regNo'])) {
        $sampleRegNo = (string)$row['regNo'];
    }
}

$rows = [
    [$sampleRegNo, 'Pension Arrears', '2025', '7', '200000', '', '', 'Missed payment for July', 'Imported batch', 'missed_payment', 'Incomplete']
];

$timestamp = date('Ymd_His');
$filename = 'claims_upload_template_' . $timestamp . '.xlsx';
sendUploadTemplateXlsx($columns, $rows, 'Claims Upload', $filename);
$conn->close();
