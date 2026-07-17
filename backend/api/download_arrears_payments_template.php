<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/xlsx_upload_template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'claims.arrears.manage')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$sampleSupplierNo = 'SUP-10021';
$supplierResult = $conn->query("SELECT supplierNo FROM tb_fileregistry WHERE supplierNo IS NOT NULL AND TRIM(supplierNo) <> '' ORDER BY id ASC LIMIT 1");
if ($supplierResult) {
    $row = $supplierResult->fetch_assoc();
    if (!empty($row['supplierNo'])) {
        $sampleSupplierNo = (string)$row['supplierNo'];
    }
}

$filename = 'arrears_payments_template_' . date('Ymd_His') . '.xlsx';
sendUploadTemplateXlsx(
    ['Supplier Number', 'Claim Type', 'Amount', 'Payment Date', 'Reference Number', 'Notes'],
    [[$sampleSupplierNo, 'Pension Arrears', '1450000', date('Y-m-d'), 'PAY-APR-001', 'Optional payment batch note']],
    'Arrears Payments Upload',
    $filename
);

$conn->close();
