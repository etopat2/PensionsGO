<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!currentUserHasPermission($conn, 'claims.arrears.manage')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureArrearsAndBudgetTables($conn);

$files = [];
if (isset($_FILES['accountability_files'])) {
    $fileField = $_FILES['accountability_files'];
    if (is_array($fileField['name'] ?? null)) {
        $count = count($fileField['name']);
        for ($i = 0; $i < $count; $i++) {
            $files[] = [
                'name' => $fileField['name'][$i] ?? '',
                'type' => $fileField['type'][$i] ?? '',
                'tmp_name' => $fileField['tmp_name'][$i] ?? '',
                'error' => $fileField['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileField['size'][$i] ?? 0
            ];
        }
    } else {
        $files[] = $fileField;
    }
}

$result = submitArrearsAccountability($conn, [
    'payment_id' => (int)($_POST['payment_id'] ?? 0),
    'regNo' => trim((string)($_POST['regNo'] ?? '')),
    'claim_type' => (string)($_POST['claimType'] ?? 'Pension Arrears'),
    'notes' => trim((string)($_POST['notes'] ?? '')),
    'submitted_by' => (string)($_SESSION['userId'] ?? ''),
    'files' => $files
]);

echo json_encode($result);
$conn->close();
