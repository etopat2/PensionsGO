<?php
header('Content-Type: application/json; charset=UTF-8');
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../runtime_admin_tools.php';

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request method'
        ]);
        exit;
    }

    if (function_exists('maybeQueueDailyAdminDigest')) {
        maybeQueueDailyAdminDigest($conn);
    }
    if (function_exists('maybeQueueAnalyticsDigest')) {
        maybeQueueAnalyticsDigest($conn);
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $result = processNotificationQueue($conn, [
        'force' => true,
        'reason' => 'admin_manual',
        'batch_size' => isset($payload['batch_size']) ? (int)$payload['batch_size'] : null,
        'actor_id' => $_SESSION['userId'] ?? 'admin',
        'actor_name' => $_SESSION['userName'] ?? 'Administrator',
        'actor_role' => $_SESSION['userRole'] ?? 'admin'
    ]);

    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Unable to process notification queue.',
        'error' => $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
