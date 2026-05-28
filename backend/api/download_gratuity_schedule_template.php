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

if (!currentUserHasPermission($conn, 'claims.arrears.manage')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$filename = 'monthly_gratuity_schedule_template_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$output = fopen('php://output', 'w');
fputcsv($output, ['Reg No', 'Supplier Number', 'Beneficiary Name', 'Scheduled Amount', 'Notes']);
fputcsv($output, ['PA/001234', 'SUP-10021', 'ASP EXAMPLE BENEFICIARY', '1450000', 'Optional remarks for the monthly schedule']);
fclose($output);

$conn->close();
