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

if (!currentUserHasPermission($conn, 'payroll.upload')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$filename = 'payroll_upload_template_' . date('Ymd_His') . '.xlsx';
sendUploadTemplateXlsx(
    ['Supplier Number', 'Beneficiary Name', 'Amount'],
    [['SUP-10021', 'ASP EXAMPLE BENEFICIARY', '1450000']],
    'Payroll Upload',
    $filename
);

$conn->close();
