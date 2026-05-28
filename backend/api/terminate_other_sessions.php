<?php
/**
 * terminate_other_sessions.php
 * Resolve device-conflict confirmation by terminating all other active sessions.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

ob_start();
$responseSent = false;

$sendJsonResponse = static function (array $payload, int $statusCode = 200) use (&$responseSent): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $responseSent = true;
    exit;
};

register_shutdown_function(static function () use (&$responseSent, $sendJsonResponse): void {
    if ($responseSent) {
        return;
    }

    $lastError = error_get_last();
    if (!$lastError) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$lastError['type'], $fatalTypes, true)) {
        return;
    }

    error_log('TERMINATE OTHER SESSIONS FATAL: ' . ($lastError['message'] ?? 'Unknown fatal error'));
    $sendJsonResponse([
        'success' => false,
        'message' => 'Failed to terminate other devices due to a server error.'
    ], 500);
});

try {
    require_once __DIR__ . '/../config.php';
    applyApiCorsPolicy($conn, ['POST', 'OPTIONS']);

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $sendJsonResponse(['success' => true, 'message' => 'Preflight acknowledged.'], 200);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Invalid request method.', 405);
    }

    if (!isset($_SESSION['userId'], $_SESSION['session_id'])) {
        throw new RuntimeException('Not logged in.', 401);
    }

    $userId = (string)$_SESSION['userId'];
    $currentSessionId = (string)$_SESSION['session_id'];
    $requestDeviceId = null;

    if (!validateSessionDeviceBinding($conn, $currentSessionId, $userId, $requestDeviceId)) {
        throw new RuntimeException('Device verification failed.', 403);
    }

    $sessionManager = SessionManager::getInstance($conn);
    $terminatedCount = $sessionManager->terminateAllOtherSessions(
        $userId,
        $currentSessionId,
        'user_confirmed_device_conflict'
    );

    logUserActivity($conn, [
        'user_id' => $userId,
        'user_name' => $_SESSION['userName'] ?? 'Unknown User',
        'user_role' => $_SESSION['userRole'] ?? 'guest',
        'activity_type' => 'device_conflict_resolved',
        'ip_address' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'location' => getLocationFromIP(getClientIP()),
        'session_id' => $currentSessionId,
        'details' => "User confirmed login on this device, terminated {$terminatedCount} other session(s)"
    ]);

    $sendJsonResponse([
        'success' => true,
        'message' => "Terminated {$terminatedCount} other session(s)",
        'terminatedCount' => $terminatedCount,
        'currentSessionId' => $currentSessionId
    ], 200);
} catch (Throwable $e) {
    error_log('Error terminating sessions: ' . $e->getMessage());

    if (isset($conn) && isset($_SESSION['userId'])) {
        try {
            logUserActivity($conn, [
                'user_id' => $_SESSION['userId'],
                'user_name' => $_SESSION['userName'] ?? 'Unknown User',
                'user_role' => $_SESSION['userRole'] ?? 'guest',
                'activity_type' => 'session_termination_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => $_SESSION['session_id'] ?? session_id(),
                'details' => 'Failed to terminate other sessions: ' . $e->getMessage()
            ]);
        } catch (Throwable $logError) {
            error_log('Failed to log termination error: ' . $logError->getMessage());
        }
    }

    $statusCode = (int)$e->getCode();
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    $sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage()
    ], $statusCode);
} finally {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
