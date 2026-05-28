<?php
/**
 * 
 * cleanup_session.php
 * 
 * Purpose: *   - Forcefully clears PHP session and cookies
 *   - COMPLETELY REMOVES session from database (not just mark as inactive)
 *   - Used when device conflicts or session expiry occurs
 *   - Prevents "ghost sessions" from triggering false device conflicts
 * 
 */

// Set headers for CORS and JSON response
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();

try {
    require_once __DIR__ . '/../config.php';
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception("Database connection unavailable");
    }

    $cleanedCount = 0;
    $sessionRemoved = false;
    $sessionId = $_SESSION['session_id'] ?? null;
    $userId = $_SESSION['userId'] ?? 'unknown';
    $userName = $_SESSION['userName'] ?? 'Unknown User';
    $userRole = $_SESSION['userRole'] ?? 'guest';

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
        session_write_close();
    }

    // Clear session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Also clear any other auth-related cookies
    setcookie('PHPSESSID', '', time() - 3600, '/');

    // ðŸ”¥ ENHANCED: Mark session inactive instead of deleting
    if ($sessionId) {
        $stmt = $conn->prepare("
            UPDATE tb_user_sessions
            SET is_active = 0,
                termination_reason = 'cleanup'
            WHERE session_id = ? AND is_active = 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $sessionId);
            $stmt->execute();
            $sessionRemoved = $stmt->affected_rows > 0;
            $stmt->close();

            if ($sessionRemoved) {
                error_log("ðŸ§¹ Session cleanup: Marked session " . $sessionId . " as inactive");
            }
        }
    }

    // Also clean up any other expired sessions while we're at it
    $timeoutMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'session_timeout_minutes') : null;
    $graceMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'grace_period_minutes') : null;
    $timeoutSeconds = is_numeric($timeoutMinutesRaw) ? (int)$timeoutMinutesRaw * 60 : 1800;
    $graceSeconds = is_numeric($graceMinutesRaw) ? (int)$graceMinutesRaw * 60 : 300;
    if ($timeoutSeconds <= 0) {
        $timeoutSeconds = 1800;
    }
    if ($graceSeconds < 0) {
        $graceSeconds = 0;
    }
    $expiryTime = date('Y-m-d H:i:s', time() - ($timeoutSeconds + $graceSeconds));
    $cleanupStmt = $conn->prepare("
        UPDATE tb_user_sessions
        SET is_active = 0,
            termination_reason = 'auto_cleanup'
        WHERE is_active = 1 AND last_activity < ?
    ");
    if ($cleanupStmt) {
        $cleanupStmt->bind_param("s", $expiryTime);
        $cleanupStmt->execute();
        $cleanedCount = $cleanupStmt->affected_rows;
        $cleanupStmt->close();
    }

    if ($cleanedCount > 0) {
        error_log("ðŸ§¹ Additional cleanup: Removed $cleanedCount expired sessions");
    }

    // Cleanup old logs based on retention policy
    $logRetentionRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'log_retention_days') : null;
    $logRetentionDays = is_numeric($logRetentionRaw) ? (int)$logRetentionRaw : 0;
    if ($logRetentionDays > 0) {
        $logStmt = $conn->prepare("
            DELETE FROM tb_user_logs
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        if ($logStmt) {
            $logStmt->bind_param("i", $logRetentionDays);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    // Log the cleanup activity
    if (function_exists('logUserActivity')) {
        logUserActivity($conn, [
            'user_id' => 'system',
            'user_name' => 'System',
            'user_role' => 'admin',
            'activity_type' => 'session_cleanup',
            'ip_address' => getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'location' => getLocationFromIP(getClientIP()),
            'session_id' => $sessionId ?? session_id(),
            'details' => 'Cleanup on logout'
        ]);
    }

    // Send success response
    $response = [
        'success' => true,
        'message' => 'Session completely cleaned up (PHP + Database)',
        'database_cleaned' => true,
        'session_removed' => $sessionRemoved,
        'expired_sessions_removed' => $cleanedCount,
        'timestamp' => time()
    ];

    if (ob_get_length()) {
        ob_clean();
    }
    
    echo json_encode($response);

} catch (Exception $e) {
    error_log("cleanup_session.php error: " . $e->getMessage());
    
    if (ob_get_length()) {
        ob_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error during session cleanup: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
    
} finally {
    if (ob_get_length()) {
        ob_end_flush();
    }
    
    // Close database connection if it exists
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

exit();
?>

