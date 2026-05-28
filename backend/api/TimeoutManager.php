<?php
/**
 * 
 * TimeoutManager.php - Unified Timeout Management
 * 
 * Purpose: *   - Centralized timeout configuration
 *   - Activity-based timeout extension
 *   - Grace period management
 * 
 */

class TimeoutManager {
    
    private $conn;
    private static $defaultTimeout = 1800; // 30 minutes
    private static $defaultGracePeriod = 300; // 5 minutes
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Get timeout for user
     */
    public function getUserTimeout($userId) {
        $stmt = $this->conn->prepare("
            SELECT session_timeout FROM tb_session_settings WHERE user_id = ?
        ");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();
        
        return $settings['session_timeout'] ?? self::$defaultTimeout;
    }
    
    /**
     * Get grace period for user
     */
    public function getUserGracePeriod($userId) {
        $stmt = $this->conn->prepare("
            SELECT grace_period_minutes FROM tb_session_settings WHERE user_id = ?
        ");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();
        
        return ($settings['grace_period_minutes'] ?? 5) * 60; // Convert to seconds
    }
    
    /**
     * Calculate extended timeout based on activity type
     */
    public function getExtendedTimeout($activityType, $currentTimeout) {
        $extensions = [
            'page_load' => 60,           // 1 minute for page loads
            'ajax_request' => 120,       // 2 minutes for AJAX requests
            'form_submit' => 300,        // 5 minutes for form submissions
            'file_upload' => 600,        // 10 minutes for file uploads
            'api_call' => 180,           // 3 minutes for API calls
            'keep_alive' => 300          // 5 minutes for keep-alive
        ];
        
        $extension = $extensions[$activityType] ?? 0;
        return $currentTimeout + $extension;
    }
    
    /**
     * Check if session should timeout
     */
    public function shouldTimeout($lastActivity, $timeout, $gracePeriodEnd = null) {
        $elapsed = time() - $lastActivity;
        
        // Check if within grace period
        if ($gracePeriodEnd && time() < strtotime($gracePeriodEnd)) {
            return false;
        }
        
        return $elapsed > $timeout;
    }
    
    /**
     * Get warning time before timeout
     */
    public function getWarningTime($userId) {
        $stmt = $this->conn->prepare("
            SELECT inactivity_warning_minutes FROM tb_session_settings WHERE user_id = ?
        ");
        $stmt->bind_param("s", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();
        
        return ($settings['inactivity_warning_minutes'] ?? 5) * 60; // Convert to seconds
    }
    
    /**
     * Calculate time until timeout
     */
    public function getTimeUntilTimeout($lastActivity, $timeout) {
        $elapsed = time() - $lastActivity;
        $remaining = $timeout - $elapsed;
        
        return max(0, $remaining);
    }
    
    /**
     * Format time remaining
     */
    public function formatTimeRemaining($seconds) {
        if ($seconds <= 0) return 'Expired';
        
        $minutes = floor($seconds / 60);
        $seconds = $seconds % 60;
        
        if ($minutes > 0) {
            return sprintf('%d min %02d sec', $minutes, $seconds);
        }
        
        return sprintf('%d sec', $seconds);
    }
    
    /**
     * Update session metrics for timeout analysis
     */
    public function logTimeoutEvent($userId, $timeoutType, $sessionDuration) {
        $stmt = $this->conn->prepare("
            INSERT INTO tb_session_metrics (metric_time, timeout_errors)
            VALUES (DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00'), 1)
            ON DUPLICATE KEY UPDATE timeout_errors = timeout_errors + 1
        ");
        $stmt->execute();
        $stmt->close();
        
        // Log detailed timeout event
        $logStmt = $this->conn->prepare("
            INSERT INTO tb_user_logs 
            (user_id, user_name, user_role, activity_type, details)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $userName = $_SESSION['userName'] ?? 'Unknown';
        $userRole = $_SESSION['userRole'] ?? 'guest';
        $details = json_encode([
            'timeout_type' => $timeoutType,
            'session_duration' => $sessionDuration,
            'expected_timeout' => $this->getUserTimeout($userId)
        ]);
        
        $logStmt->bind_param("sssss", 
            $userId, 
            $userName, 
            $userRole, 
            'session_timeout', 
            $details
        );
        $logStmt->execute();
        $logStmt->close();
    }
}
?>
