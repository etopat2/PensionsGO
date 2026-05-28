<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

try {
    $overview = getDataImportOverview($conn);
    echo json_encode([
        'success' => true,
        'datasets' => $overview['datasets'],
        'runs' => $overview['runs']
    ]);
} catch (Throwable $e) {
    error_log('get_data_import_overview error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load data import overview.']);
}

$conn->close();
