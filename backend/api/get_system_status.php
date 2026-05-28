<?php
/**
 * 
 * get_system_status.php
 * Purpose: Get system status information for admin dashboard
 * Access: Admin only
 * 
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';
require_once __DIR__ . '/../system_health_tools.php';

// Verify admin access
if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

try {
    if (function_exists('maybeQueueDailyAdminDigest')) {
        maybeQueueDailyAdminDigest($conn);
    }
    if (function_exists('maybeQueueAnalyticsDigest')) {
        maybeQueueAnalyticsDigest($conn);
    }
    if (function_exists('ensureBackupLogsTable')) {
        ensureBackupLogsTable($conn);
    }
    // Ensure current session exists in tb_user_sessions if missing
    if (tableExists($conn, 'tb_user_sessions') && isset($_SESSION['userId'])) {
        $currentSessionId = $_SESSION['session_id'] ?? session_id();
        if (!empty($currentSessionId)) {
        $checkStmt = $conn->prepare("
            SELECT 1
            FROM tb_user_sessions
            WHERE session_id = ? AND user_id = ?
            LIMIT 1
        ");
        if ($checkStmt) {
            $checkStmt->bind_param("ss", $currentSessionId, $_SESSION['userId']);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $exists = $checkResult && $checkResult->num_rows > 0;
            $checkStmt->close();

            if (!$exists) {
                $ipAddress = getClientIP();
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $deviceId = $_SESSION['device_id'] ?? hash('sha256', $userAgent . '|' . $ipAddress);
                $sessionType = 'web';
                $graceUntil = date('Y-m-d H:i:s', time() + 300);

                $insertStmt = $conn->prepare("
                    INSERT IGNORE INTO tb_user_sessions
                    (session_id, user_id, device_id, user_agent, ip_address, session_type, grace_period_until)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                if ($insertStmt) {
                    $insertStmt->bind_param(
                        "sssssss",
                        $currentSessionId,
                        $_SESSION['userId'],
                        $deviceId,
                        $userAgent,
                        $ipAddress,
                        $sessionType,
                        $graceUntil
                    );
                    $insertStmt->execute();
                    $insertStmt->close();
                }
            }
        }
        }
    }

    // Get last backup time (if table exists)
    $lastBackup = null;
    if (tableExists($conn, 'tb_backup_logs')) {
        $backupStmt = $conn->prepare("
            SELECT MAX(backup_time) as last_backup 
            FROM tb_backup_logs 
            WHERE status IN ('success', 'partial', 'restored')
        ");
        if ($backupStmt) {
            $backupStmt->execute();
            $backupResult = $backupStmt->get_result();
            $lastBackup = $backupResult->fetch_assoc()['last_backup'] ?? null;
            $backupStmt->close();
        }
    }

    // Get active users from active sessions table
    $activeUsers = 0;
    if (tableExists($conn, 'tb_user_sessions')) {
        $activeStmt = $conn->prepare("
            SELECT COUNT(DISTINCT user_id) as active_users
            FROM tb_user_sessions
            WHERE is_active = 1
        ");
        if ($activeStmt) {
            $activeStmt->execute();
            $activeResult = $activeStmt->get_result();
            $activeUsers = (int)($activeResult->fetch_assoc()['active_users'] ?? 0);
            $activeStmt->close();
        }
    }
    
    if ($activeUsers === 0 && isset($_SESSION['userId'])) {
        $activeUsers = 1;
    }

    $snapshot = getSystemHealthSnapshot($conn);

    echo json_encode([
        'success' => true,
        'lastBackup' => $lastBackup ? date('M j, Y g:i A', strtotime($lastBackup)) : 'Never',
        'lastBackupRaw' => $lastBackup,
        'activeUsers' => (int)$activeUsers,
        'systemHealth' => [
            'status' => $snapshot['status'] ?? 'healthy',
            'message' => $snapshot['message'] ?? 'All systems operational.',
            'detail' => $snapshot['detail'] ?? '',
            'primary_alert_key' => $snapshot['primary_alert_key'] ?? null,
            'primary_subsystem' => $snapshot['primary_subsystem'] ?? null
        ],
        'diagnostics' => $snapshot['diagnostics'] ?? [],
        'diagnosticSummary' => $snapshot['diagnostic_summary'] ?? [],
        'alerts' => $snapshot['alerts'] ?? []
    ]);

} catch (Exception $e) {
    error_log("Error fetching system status: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching system status',
        'lastBackup' => 'Unknown',
        'activeUsers' => 0,
        'systemHealth' => [
            'status' => 'error',
            'message' => 'Unable to determine system status'
        ]
    ]);
}
$conn->close();
?>

