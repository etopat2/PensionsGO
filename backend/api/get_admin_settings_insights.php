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

$section = trim((string)($_GET['section'] ?? ''));

try {
    $payload = adminSettingsInsightsPayload($conn, $section);
    echo json_encode([
        'success' => true,
        'section' => $section,
        'payload' => $payload
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load admin settings insights.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
