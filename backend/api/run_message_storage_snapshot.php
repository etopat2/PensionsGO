<?php
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

try {
    $result = createMessageStorageSnapshot($conn, [
        'snapshot_type' => 'manual',
        'notes' => 'Manual message storage snapshot requested from Message Storage settings.',
        'created_by' => $_SESSION['userId'] ?? null,
        'created_by_name' => $_SESSION['userName'] ?? null,
        'created_by_role' => $_SESSION['userRole'] ?? null
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Message storage snapshot created successfully.',
        'snapshot' => $result
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to create message storage snapshot.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
