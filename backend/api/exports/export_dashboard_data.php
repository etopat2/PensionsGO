<?php
/**
 * 
 * export_dashboard_data.php
 * Purpose: Export dashboard summary data to CSV with enhanced display
 * Access: Admin only
 * 
 */

require_once __DIR__ . '/../../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify admin access
if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

if (!getAppSettingBool($conn, 'analytics_export_enabled', true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Analytics export is disabled.'
    ]);
    exit;
}

try {
    // Set CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=enhanced-dashboard-data-' . date('Y-m-d') . '.csv');

    // Create output stream
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // 
    // Dashboard Summary Section //
    fputcsv($output, ['UPS PensionsGo - Dashboard Summary Report']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['Report Period:', 'Real-time Snapshot']);
    fputcsv($output, ['']); // Empty row

    // Get user statistics
    $userQuery = "SELECT COUNT(*) as total_users FROM tb_users";
    $userResult = $conn->query($userQuery);
    $userData = $userResult->fetch_assoc();

    // Get today's logs count
    $logsQuery = "SELECT COUNT(*) as today_logs FROM tb_user_logs WHERE DATE(created_at) = CURDATE()";
    $logsResult = $conn->query($logsQuery);
    $logsData = $logsResult->fetch_assoc();

    // Get active sessions (users logged in today)
    $sessionsQuery = "SELECT COUNT(DISTINCT user_id) as active_sessions FROM tb_user_logs WHERE DATE(login_time) = CURDATE()";
    $sessionsResult = $conn->query($sessionsQuery);
    $sessionsData = $sessionsResult->fetch_assoc();

    // Get failed logins this week
    $failedLoginsQuery = "SELECT COUNT(*) as failed_logins FROM tb_user_logs 
                         WHERE activity_type = 'login_failed' 
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $failedLoginsResult = $conn->query($failedLoginsQuery);
    $failedLoginsData = $failedLoginsResult->fetch_assoc();

    // Get successful logins today
    $successLoginsQuery = "SELECT COUNT(*) as success_logins FROM tb_user_logs 
                          WHERE activity_type = 'login' 
                          AND DATE(created_at) = CURDATE()";
    $successLoginsResult = $conn->query($successLoginsQuery);
    $successLoginsData = $successLoginsResult->fetch_assoc();

    // Write dashboard summary with enhanced formatting
    fputcsv($output, ['S/N', 'METRIC', 'VALUE', 'DESCRIPTION']);
    fputcsv($output, [1, 'Total Users', $userData['total_users'] ?? 0, 'All registered users in the system']);
    fputcsv($output, [2, "Today's Activity Logs", $logsData['today_logs'] ?? 0, 'All log entries created today']);
    fputcsv($output, [3, 'Active Sessions Today', $sessionsData['active_sessions'] ?? 0, 'Unique users logged in today']);
    fputcsv($output, [4, 'Successful Logins Today', $successLoginsData['success_logins'] ?? 0, 'Successful login attempts today']);
    fputcsv($output, [5, 'Failed Login Attempts (7 Days)', $failedLoginsData['failed_logins'] ?? 0, 'Failed login attempts in the past week']);
    fputcsv($output, ['']); // Empty row

    // 
    // User Activity Breakdown Section //
    fputcsv($output, ['USER ACTIVITY BREAKDOWN (LAST 7 DAYS)']);
    fputcsv($output, ['']); // Empty row

    // Get activity type breakdown
    $activityBreakdownQuery = "
        SELECT activity_type, COUNT(*) as count 
        FROM tb_user_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY activity_type 
        ORDER BY count DESC
    ";
    $breakdownResult = $conn->query($activityBreakdownQuery);
    
    fputcsv($output, ['S/N', 'ACTIVITY TYPE', 'COUNT (7 DAYS)', 'PERCENTAGE']);
    
    $totalActivities = 0;
    $breakdownData = [];
    while ($row = $breakdownResult->fetch_assoc()) {
        $breakdownData[] = $row;
        $totalActivities += $row['count'];
    }
    
    foreach ($breakdownData as $index => $row) {
        $percentage = $totalActivities > 0 ? round(($row['count'] / $totalActivities) * 100, 1) : 0;
        fputcsv($output, [
            $index + 1,
            formatActivityType($row['activity_type']),
            $row['count'],
            $percentage . '%'
        ]);
    }
    
    fputcsv($output, ['']); // Empty row

    // 
    // Recent User Activity Section //
    fputcsv($output, ['RECENT USER ACTIVITY (LAST 50 ENTRIES)']);
    fputcsv($output, ['']); // Empty row
    
    fputcsv($output, ['S/N', 'USER', 'ACTIVITY', 'IP ADDRESS', 'DEVICE', 'LOCATION', 'TIMESTAMP']);

    $activityQuery = "
        SELECT user_name, activity_type, ip_address, device_type, location, created_at 
        FROM tb_user_logs 
        ORDER BY created_at DESC 
        LIMIT 50
    ";
    $activityResult = $conn->query($activityQuery);

    $activityIndex = 1;
    while ($row = $activityResult->fetch_assoc()) {
        // Enhanced Export Display
        $userName = $row['user_name'] ?? 'Unknown';
        if ($userName === 'Unknown User' || empty($userName)) {
            $userName = 'System User';
        }
        
        $ipAddress = $row['ip_address'] ?? '::1';
        if ($ipAddress === '::1') {
            $ipAddress = 'Localhost (127.0.0.1)';
        }
        
        $activityType = $row['activity_type'] ?? 'N/A';
        $deviceType = $row['device_type'] ?? 'Unknown';
        $location = $row['location'] ?? 'Not Available';
        
        fputcsv($output, [
            $activityIndex++,
            $userName,
            ucfirst(str_replace('_', ' ', $activityType)),
            $ipAddress,
            $deviceType,
            $location,
            $row['created_at'] ?? 'N/A'
        ]);
    }

    fputcsv($output, ['']); // Empty row
    fputcsv($output, ['--- END OF UPS PensionsGo REPORT ---']);

    fclose($output);

} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Export failed: ' . $e->getMessage()
    ]);
}

/**
 * Format activity type for better readability
 */
function formatActivityType($activityType) {
    $formatted = str_replace('_', ' ', $activityType);
    return ucwords($formatted);
}

$conn->close();
?>
