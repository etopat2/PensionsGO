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
    $requestDeviceId = null;
    if (!validateSessionDeviceBinding($conn, $_SESSION['session_id'], $_SESSION['userId'], $requestDeviceId)) {
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

    $sm = SessionManager::getInstance($conn);
    $sm->cleanupExpiredSessionsThrottled(60);
    $state = $sm->validateSession($_SESSION['session_id'], $_SESSION['userId']);

    // Update session activity only if still active
    if (!empty($state['active'])) {
        $_SESSION['last_activity'] = time();
    }
    
    $effectiveRole = function_exists('resolveRoleAccessKey')
        ? resolveRoleAccessKey($conn, (string)($_SESSION['userRole'] ?? ''))
        : (string)($_SESSION['userRole'] ?? '');

    echo json_encode([
        'active'              => $state['active'],
        'expired'             => $state['expired'],
        'in_grace'            => $state['in_grace'],
        'time_until_timeout'  => $state['seconds_left'],
        'reason'              => $state['reason'] ?? null,
        'message'             => $state['message'] ?? null,
        'userId'              => $_SESSION['userId'] ?? '',
        'userName'            => $_SESSION['userName'] ?? '',
        'userRole'            => $_SESSION['userRole'] ?? '',
        'userRoleEffective'   => $effectiveRole,
        'userPhoto'           => $_SESSION['userPhoto'] ?? ''
    ]);
    
    if (empty($state['active'])) {
        session_unset();
        session_destroy();
    }
} catch (Throwable $e) {
    echo json_encode([
        'active' => false,
        'reason' => 'invalid',
        'message' => 'Session invalid or expired. Please login again.'
    ]);
}
?>
