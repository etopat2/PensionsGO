<?php
/**
 * logout.php - terminate session and update logs
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Device-Token, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ob_start();

$warnings = [];
$sessionId = null;
$userId = null;
$userName = 'Unknown';
$userRole = 'guest';

try {
    require_once __DIR__ . '/../config.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException("Database connection unavailable");
    }

    $logoutAll = isset($_POST['logout_all_devices']) && ($_POST['logout_all_devices'] === 'true' || $_POST['logout_all_devices'] === '1');

    if (isset($_SESSION['session_id'], $_SESSION['userId'])) {
        $sessionId = (string)$_SESSION['session_id'];
        $userId = (string)$_SESSION['userId'];
        $userName = (string)($_SESSION['userName'] ?? 'Unknown');
        $userRole = (string)($_SESSION['userRole'] ?? 'guest');

        $requestDeviceId = null;
        $deviceVerified = validateSessionDeviceBinding($conn, $sessionId, $userId, $requestDeviceId);
        if (!$deviceVerified) {
            $warnings[] = 'device_verification_failed';
        }

        require_once __DIR__ . '/SessionManager.php';
        $sm = SessionManager::getInstance($conn);

        try {
            if ($logoutAll) {
                $stmt = $conn->prepare("SELECT session_id FROM tb_user_sessions WHERE user_id = ? AND is_active = 1");
                if ($stmt) {
                    $stmt->bind_param('s', $userId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
                    $stmt->close();
                    foreach ($rows as $r) {
                        try {
                            $sm->terminateSession((string)$r['session_id'], $userId, 'user_initiated_all');
                        } catch (Throwable $e) {
                            $warnings[] = 'terminate_failed:' . ((string)$r['session_id']);
                            error_log("logout.php terminate failure for {$r['session_id']}: " . $e->getMessage());
                        }
                    }
                }
            } else {
                // Logout is a safe action: even if device verification is missing, terminate the current
                // bound session row so stale active sessions do not survive a user-initiated logout.
                $sm->terminateSession($sessionId, $userId, 'user_initiated');
            }
        } catch (Throwable $e) {
            $warnings[] = 'session_terminate_exception';
            error_log("logout.php terminate exception: " . $e->getMessage());
        }

        try {
            if (function_exists('logUserActivity')) {
                logUserActivity($conn, [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'user_role' => $userRole,
                    'activity_type' => 'logout',
                    'ip_address' => getClientIP(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                    'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'location' => getLocationFromIP(getClientIP()),
                    'session_id' => $sessionId,
                    'details' => $deviceVerified ? 'User requested logout' : 'User requested logout (device token missing or changed)'
                ]);
            }
        } catch (Throwable $e) {
            $warnings[] = 'logout_activity_log_failed';
            error_log("logout.php activity log failure: " . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $warnings[] = 'logout_exception';
    error_log("logout.php fatal: " . $e->getMessage() . " in " . ($e->getFile() ?? '') . ":" . ($e->getLine() ?? ''));
}

// Always clear the PHP session locally so logout cannot leave a valid browser session behind.
try {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => !empty($params['secure']),
                'httponly' => !empty($params['httponly']),
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
        } else {
            setcookie(
                session_name(),
                '',
                time() - 42000,
                ($params['path'] ?? '/') . '; samesite=' . ($params['samesite'] ?? 'Lax'),
                $params['domain'] ?? '',
                !empty($params['secure']),
                !empty($params['httponly'])
            );
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
} catch (Throwable $e) {
    $warnings[] = 'session_clear_failed';
    error_log("logout.php session clear failure: " . $e->getMessage());
}

if (ob_get_length()) {
    ob_clean();
}

echo json_encode([
    'success' => true,
    'message' => 'Logged out',
    'warnings' => $warnings
]);
exit;
?>
