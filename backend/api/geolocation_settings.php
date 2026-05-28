<?php
/**
 * geolocation_settings.php
 * Purpose: Get/update geolocation toggle
 * Access: Admin only
 */

header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../config.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $enabled = isGeoipEnabled();
        ob_clean();
        echo json_encode([
            'success' => true,
            'enabled' => $enabled,
            'provider' => GEOIP_PROVIDER
        ]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $enabledRaw = $input['enabled'] ?? null;
        $enabledBool = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabledBool === null) {
            http_response_code(400);
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Invalid enabled value'
            ]);
            exit;
        }

        $saved = setAppSetting($conn, 'geolocation_enabled', $enabledBool ? '1' : '0');
        ob_clean();
        echo json_encode([
            'success' => $saved,
            'enabled' => $enabledBool
        ]);
        exit;
    }

    http_response_code(405);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    ob_end_flush();
}
?>
