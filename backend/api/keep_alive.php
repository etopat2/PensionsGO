<?php
/**
 * keep_alive.php
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
    echo json_encode(['success' => true, 'noop' => true]);
    exit;
}

try {
    $requestDeviceId = null;
    if (!validateSessionDeviceBinding($conn, $_SESSION['session_id'], $_SESSION['userId'] ?? '', $requestDeviceId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Device verification failed']);
        exit;
    }

    $sm = SessionManager::getInstance($conn);
    $sm->cleanupExpiredSessionsThrottled(60);
    $touched = $sm->touchSession($_SESSION['session_id'], $requestDeviceId);
    if (!$touched) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Session is not active on this device']);
        exit;
    }

    $_SESSION['last_activity'] = time();

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log("keep_alive error: " . $e->getMessage());
    echo json_encode(['success' => true]); // silent success
}
?>
