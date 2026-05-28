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

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            'success' => true,
            'runtime' => getAnalyticsDigestRuntime($conn)
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

    $payload = json_decode(file_get_contents('php://input'), true);
    $action = trim((string)($payload['action'] ?? 'queue_now'));

    if ($action === 'preview') {
        $result = queueAnalyticsDigest($conn, [
            'run_type' => 'preview',
            'notes' => 'Analytics digest preview generated from Analysis & Reporting.',
            'created_by' => $_SESSION['userId'] ?? null,
            'created_by_name' => $_SESSION['userName'] ?? null,
            'created_by_role' => $_SESSION['userRole'] ?? null
        ]);
        echo json_encode([
            'success' => true,
            'message' => 'Analytics digest preview generated.',
            'runtime' => getAnalyticsDigestRuntime($conn),
            'digest' => $result
        ]);
        exit;
    }

    $result = queueAnalyticsDigest($conn, [
        'run_type' => 'manual',
        'notes' => 'Analytics digest queued manually from Analysis & Reporting.',
        'created_by' => $_SESSION['userId'] ?? null,
        'created_by_name' => $_SESSION['userName'] ?? null,
        'created_by_role' => $_SESSION['userRole'] ?? null
    ]);

    echo json_encode([
        'success' => ($result['status'] ?? '') !== 'failed',
        'message' => ($result['status'] ?? '') === 'failed'
            ? 'Analytics digest could not be queued.'
            : 'Analytics digest queued successfully.',
        'runtime' => getAnalyticsDigestRuntime($conn),
        'digest' => $result
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to process analytics digest.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
