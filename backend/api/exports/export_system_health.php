<?php
/**
 * 
 * export_system_health.php
 * Purpose: Export system health data to CSV with enhanced display
 * Access: Admin only
 * 
 */

session_start();

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

// Fix the config path
require_once __DIR__ . '/../../config.php';

try {
    // Set CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=enhanced-system-health-' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM

    // 
    // System Health Report Header //
    fputcsv($output, ['UPS PensionsGo - System Health Report']);
    fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
    fputcsv($output, ['']); // Empty row

    // 
    // System Information Section //
    fputcsv($output, ['SYSTEM INFORMATION']);
    fputcsv($output, ['S/N', 'PARAMETER', 'VALUE', 'STATUS']);
    
    // Server information
    $systemInfoSerial = 1;
    fputcsv($output, [$systemInfoSerial++, 'Server Time', date('Y-m-d H:i:s'), 'Current']);
    fputcsv($output, [$systemInfoSerial++, 'Server Name', $_SERVER['SERVER_NAME'] ?? 'Unknown', 'Info']);
    fputcsv($output, [$systemInfoSerial++, 'PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=') ? 'Supported' : 'Update Recommended']);
    fputcsv($output, [$systemInfoSerial++, 'PHP Memory Limit', ini_get('memory_limit'), 'Configuration']);
    fputcsv($output, [$systemInfoSerial++, 'Max Execution Time', ini_get('max_execution_time') . ' seconds', 'Configuration']);
    
    // Database information
    $dbStatus = $conn->ping() ? 'Connected' : 'Disconnected';
    $dbStatusLevel = $conn->ping() ? 'Good' : 'Error';
    fputcsv($output, [$systemInfoSerial++, 'Database Server', $conn->server_info ?? 'Unknown', $dbStatusLevel]);
    fputcsv($output, [$systemInfoSerial++, 'Database Connection', $dbStatus, $dbStatusLevel]);
    
    fputcsv($output, ['']); // Empty row

    // 
    // Database Statistics Section //
    fputcsv($output, ['DATABASE STATISTICS']);
    fputcsv($output, ['S/N', 'TABLE', 'RECORD COUNT', 'LAST UPDATED', 'STATUS']);
    
    // Table counts - only include tables that exist
    $tables = ['tb_users', 'tb_user_logs'];
    
    $tableSerial = 1;
    foreach ($tables as $table) {
        try {
            $countQuery = "SELECT COUNT(*) as count FROM $table";
            $countResult = $conn->query($countQuery);
            if ($countResult) {
                $countData = $countResult->fetch_assoc();
                $count = $countData['count'] ?? 0;
                
                // Get last update time if possible
                $lastUpdateQuery = "SELECT MAX(created_at) as last_update FROM $table";
                $lastUpdateResult = $conn->query($lastUpdateQuery);
                $lastUpdateData = $lastUpdateResult->fetch_assoc();
                $lastUpdate = $lastUpdateData['last_update'] ?? 'N/A';
                
                $status = $count > 0 ? 'Active' : 'Empty';
                
                fputcsv($output, [
                    $tableSerial++,
                    ucfirst(str_replace('tb_', '', $table)),
                    $count,
                    $lastUpdate,
                    $status
                ]);
            }
        } catch (Exception $e) {
            // Tables that don't exist
            fputcsv($output, [
                $tableSerial++,
                ucfirst(str_replace('tb_', '', $table)),
                '0',
                'N/A',
                'Table Not Found'
            ]);
        }
    }

    fputcsv($output, ['']); // Empty row

    // 
    // System Metrics Section //
    fputcsv($output, ['SYSTEM METRICS']);
    fputcsv($output, ['S/N', 'METRIC', 'VALUE', 'STATUS', 'THRESHOLD']);
    
    // Memory usage
    $memoryUsage = memory_get_usage(true);
    $memoryUsageMB = round($memoryUsage / 1024 / 1024, 2);
    $memoryLimit = ini_get('memory_limit');
    $memoryStatus = $memoryUsageMB < 50 ? 'Good' : ($memoryUsageMB < 100 ? 'Warning' : 'Critical');
    $metricSerial = 1;
    fputcsv($output, [$metricSerial++, 'Memory Usage', $memoryUsageMB . ' MB', $memoryStatus, '< 50 MB Optimal']);
    
    // Peak memory usage
    $peakMemoryUsage = memory_get_peak_usage(true);
    $peakMemoryUsageMB = round($peakMemoryUsage / 1024 / 1024, 2);
    $peakMemoryStatus = $peakMemoryUsageMB < 100 ? 'Good' : ($peakMemoryUsageMB < 200 ? 'Warning' : 'Critical');
    fputcsv($output, [$metricSerial++, 'Peak Memory Usage', $peakMemoryUsageMB . ' MB', $peakMemoryStatus, '< 100 MB Optimal']);
    
    // System load (if available)
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $loadStatus = $load[0] < 1 ? 'Good' : ($load[0] < 3 ? 'Warning' : 'Critical');
        fputcsv($output, [$metricSerial++, 'System Load (1min)', $load[0], $loadStatus, '< 1.0 Optimal']);
    }
    
    // Disk usage
    $diskFree = disk_free_space(__DIR__);
    $diskTotal = disk_total_space(__DIR__);
    $diskUsed = $diskTotal - $diskFree;
    $diskUsagePercent = $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 1) : 0;
    $diskStatus = $diskUsagePercent < 80 ? 'Good' : ($diskUsagePercent < 90 ? 'Warning' : 'Critical');
    fputcsv($output, [$metricSerial++, 'Disk Usage', $diskUsagePercent . '%', $diskStatus, '< 80% Optimal']);

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

$conn->close();
?>
