<?php
/**
 * CLI worker for outbound notification delivery.
 *
 * Windows/XAMPP example:
 *   C:\xampp\php\php.exe c:\xampp\htdocs\PROJECTS\PensionApp\backend\workers\process_notification_queue.php
 *
 * cPanel cron example:
 *   /usr/local/bin/php /home/CPANEL_USER/public_html/PROJECTS/PensionApp/backend/workers/process_notification_queue.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

maybeQueueDailyAdminDigest($conn);
maybeQueueAnalyticsDigest($conn);

$result = processNotificationQueue($conn, [
    'force' => true,
    'reason' => 'cli_worker',
    'actor_id' => 'notification_worker',
    'actor_name' => 'Notification Worker',
    'actor_role' => 'system'
]);

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}

exit(!empty($result['success']) ? 0 : 1);
