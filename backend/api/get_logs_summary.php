<?php
/**
 * 
 * get_logs_summary.php
 * Purpose: Get summary statistics for user logs
 * Access: Admin only
 * 
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Verify admin access
if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

try {
    // Ensure current session is represented in tb_user_sessions
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
    // Get date range (default to last 30 days)
    $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
    $startDate = date('Y-m-d', strtotime("-$days days"));

    // Total logs count
    $totalStmt = $conn->prepare("
        SELECT COUNT(*) as total_logs 
        FROM tb_user_logs 
        WHERE created_at >= ?
    ");
    $totalStmt->bind_param("s", $startDate);
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    $totalLogs = $totalResult->fetch_assoc()['total_logs'];
    $totalStmt->close();

    // All-time logs count
    $allStmt = $conn->prepare("
        SELECT COUNT(*) as total_logs_all 
        FROM tb_user_logs
    ");
    $allStmt->execute();
    $allResult = $allStmt->get_result();
    $totalLogsAll = $allResult->fetch_assoc()['total_logs_all'];
    $allStmt->close();

    // Logs by activity type
    $typeStmt = $conn->prepare("
        SELECT 
            activity_type,
            COUNT(*) as count
        FROM tb_user_logs 
        WHERE created_at >= ?
        GROUP BY activity_type 
        ORDER BY count DESC
    ");
    $typeStmt->bind_param("s", $startDate);
    $typeStmt->execute();
    $typeResult = $typeStmt->get_result();

    $logsByType = [];
    while ($row = $typeResult->fetch_assoc()) {
        $logsByType[] = $row;
    }
    $typeStmt->close();

    // Logs by user role
    $roleStmt = $conn->prepare("
        SELECT 
            user_role,
            COUNT(*) as count
        FROM tb_user_logs 
        WHERE created_at >= ?
        GROUP BY user_role 
        ORDER BY count DESC
    ");
    $roleStmt->bind_param("s", $startDate);
    $roleStmt->execute();
    $roleResult = $roleStmt->get_result();

    $logsByRole = [];
    while ($row = $roleResult->fetch_assoc()) {
        $logsByRole[] = $row;
    }
    $roleStmt->close();

    // Today's activity
    $todayStmt = $conn->prepare("
        SELECT 
            COUNT(*) as today_logs,
            COUNT(DISTINCT user_id) as active_users
        FROM tb_user_logs 
        WHERE DATE(created_at) = CURDATE()
    ");
    $todayStmt->execute();
    $todayResult = $todayStmt->get_result();
    $todayData = $todayResult->fetch_assoc() ?: ['today_logs' => 0, 'active_users' => 0];
    $todayStmt->close();

    // Current active sessions/users
    $activeSessions = 0;
    $activeUsersCurrent = 0;
    if (tableExists($conn, 'tb_user_sessions')) {
        $activeUsersStmt = $conn->prepare("
            SELECT COUNT(DISTINCT user_id) as active_users
            FROM tb_user_sessions
            WHERE is_active = 1
        ");

        $activeSessionsStmt = $conn->prepare("
            SELECT COUNT(*) as active_sessions
            FROM tb_user_sessions
            WHERE is_active = 1
        ");

        if ($activeUsersStmt) {
            $activeUsersStmt->execute();
            $activeUsersResult = $activeUsersStmt->get_result();
            $activeUsersCurrent = (int)($activeUsersResult->fetch_assoc()['active_users'] ?? 0);
            $activeUsersStmt->close();
        }

        if ($activeSessionsStmt) {
            $activeSessionsStmt->execute();
            $activeSessionsResult = $activeSessionsStmt->get_result();
            $activeSessions = (int)($activeSessionsResult->fetch_assoc()['active_sessions'] ?? 0);
            $activeSessionsStmt->close();
        }
    }

    if (($activeUsersCurrent === 0 || $activeSessions === 0) && isset($_SESSION['userId'])) {
        $activeUsersCurrent = max($activeUsersCurrent, 1);
        $activeSessions = max($activeSessions, 1);
    }

    // Failed login attempts (last 7 days)
    $failedStmt = $conn->prepare("
        SELECT COUNT(*) as failed_logins
        FROM tb_user_logs 
        WHERE (
            activity_type = 'login_failed'
            OR (activity_type = 'login' AND details LIKE '%Failed login%')
        )
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $failedStmt->execute();
    $failedResult = $failedStmt->get_result();
    $failedLogins = $failedResult->fetch_assoc()['failed_logins'];
    $failedStmt->close();

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_logs' => (int)$totalLogs,
            'total_logs_all' => (int)$totalLogsAll,
            'today_logs' => (int)$todayData['today_logs'],
            'active_users_today' => (int)$todayData['active_users'],
            'active_users_current' => (int)$activeUsersCurrent,
            'active_sessions_current' => (int)$activeSessions,
            'failed_logins_week' => (int)$failedLogins,
            'date_range_days' => $days
        ],
        'by_activity_type' => $logsByType,
        'by_user_role' => $logsByRole
    ]);

} catch (Exception $e) {
    error_log("Error fetching logs summary: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching logs summary: ' . $e->getMessage()
    ]);
}
$conn->close();

