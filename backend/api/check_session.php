<?php
/**
 * check_session.php
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SessionManager.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['session_id'], $_SESSION['userId'])) {
    echo json_encode(['active' => false, 'reason' => 'not_authenticated']);
    exit;
}

try {
    $sessionId = (string)$_SESSION['session_id'];
    $userId = (string)$_SESSION['userId'];
    $userName = (string)($_SESSION['userName'] ?? '');
    $userRole = (string)($_SESSION['userRole'] ?? '');
    $userPhoto = (string)($_SESSION['userPhoto'] ?? '');

    $requestDeviceId = null;
    if (!validateSessionDeviceBinding($conn, $sessionId, $userId, $requestDeviceId)) {
        echo json_encode([
            'active' => false,
            'expired' => false,
            'in_grace' => false,
            'time_until_timeout' => 0,
            'reason' => 'device_conflict',
            'message' => 'This session is not trusted on the current device. Please login again.'
        ]);
        session_unset();
        session_destroy();
        exit;
    }

    $_SESSION['last_activity'] = time();
    session_write_close();

    $sm = SessionManager::getInstance($conn);
    $sm->cleanupExpiredSessionsThrottled(60);
    $state = $sm->validateSession($sessionId, $userId);
    
    $effectiveRole = function_exists('resolveRoleAccessKey')
        ? resolveRoleAccessKey($conn, $userRole)
        : $userRole;

    if (empty($state['active'])) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_unset();
        session_destroy();
    }

    echo json_encode([
        'active'              => $state['active'],
        'expired'             => $state['expired'],
        'in_grace'            => $state['in_grace'],
        'time_until_timeout'  => $state['seconds_left'],
        'reason'              => $state['reason'] ?? null,
        'message'             => $state['message'] ?? null,
        'userId'              => $userId,
        'userName'            => $userName,
        'userRole'            => $userRole,
        'userRoleEffective'   => $effectiveRole,
        'userPhoto'           => $userPhoto
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'active' => false,
        'reason' => 'invalid',
        'message' => 'Session invalid or expired. Please login again.'
    ]);
}
?>
