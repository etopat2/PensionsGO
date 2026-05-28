<?php
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'claims.suspension.upload')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$sampleRegNo = 'PA/001234';
$sampleSupplierNo = 'SUP-10021';
$registryResult = $conn->query("
    SELECT regNo, supplierNo
    FROM tb_fileregistry
    WHERE (regNo IS NOT NULL AND TRIM(regNo) <> '')
       OR (supplierNo IS NOT NULL AND TRIM(supplierNo) <> '')
    ORDER BY id ASC
    LIMIT 1
");
if ($registryResult) {
    $row = $registryResult->fetch_assoc();
    if (!empty($row['regNo'])) {
        $sampleRegNo = (string)$row['regNo'];
    }
    if (!empty($row['supplierNo'])) {
        $sampleSupplierNo = (string)$row['supplierNo'];
    }
}

$filename = 'suspension_upload_template_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$output = fopen('php://output', 'w');
fputcsv($output, ['Reg No', 'Supplier Number', 'Beneficiary Name', 'Amount', 'Reason']);
fputcsv($output, [$sampleRegNo, $sampleSupplierNo, 'ASP EXAMPLE BENEFICIARY', '850000', 'Salary returned for verification']);
fclose($output);

$conn->close();
