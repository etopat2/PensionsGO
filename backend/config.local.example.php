<?php
/**
 * Deployment secret overrides for UPS PensionsGo.
 * Copy to `backend/config.local.php` on the hosting server and set real values.
 */

define('PENSIONAPP_DB_HOST', 'localhost');
define('PENSIONAPP_DB_USER', 'pension_app_user');
define('PENSIONAPP_DB_PASSWORD', 'change-me');
define('PENSIONAPP_DB_NAME', 'pension_db');
// App timezone used by PHP and the MySQL connection. Keep this as Africa/Kampala for Uganda deployments.
define('PENSIONAPP_TIMEZONE', 'Africa/Kampala');
// Optional override when the app is behind a tunnel / reverse proxy and auto-detection is unreliable.
// Example: define('PENSIONAPP_PUBLIC_URL', 'https://example.ngrok-free.dev');
// Optional override for shared hosts where the default PHP session folder is not writable.
// Example: define('PENSIONAPP_SESSION_SAVE_PATH', __DIR__ . '/cache/php_sessions');
// Recommended on hosted deployments: set a long random value used to sign fallback auth cookies.
// Example: define('PENSIONAPP_COOKIE_AUTH_SECRET', 'replace-with-at-least-32-random-characters');

// Optional override. Keep this aligned with app_version.json unless a deployment needs a temporary override.
define('PENSIONAPP_APP_VERSION', '1.0.0');

define('PENSIONAPP_MAIL_TRANSPORT', 'smtp'); // smtp or mail
define('PENSIONAPP_MAIL_FROM_ADDRESS', 'no-reply@yourdomain.com');
define('PENSIONAPP_MAIL_FROM_NAME', 'UPS PensionsGo');
define('PENSIONAPP_MAIL_SMTP_HOST', 'mail.yourdomain.com');
define('PENSIONAPP_MAIL_SMTP_PORT', 465);
define('PENSIONAPP_MAIL_SMTP_TIMEOUT', 15);
define('PENSIONAPP_MAIL_SMTP_ENCRYPTION', 'ssl'); // ssl on 465, or tls on 587 if your host requires STARTTLS
define('PENSIONAPP_MAIL_SMTP_USERNAME', 'no-reply@yourdomain.com'); // full mailbox address on most cPanel hosts
define('PENSIONAPP_MAIL_SMTP_PASSWORD', 'use-real-mailbox-password');
define('PENSIONAPP_MAIL_SMTP_HELO_DOMAIN', 'yourdomain.com');
define('PENSIONAPP_NOTIFY_QUEUE_WORKER_ENABLED', true);
define('PENSIONAPP_NOTIFY_QUEUE_PROCESS_ON_REQUEST', false); // keep false in production and run the worker from cron
define('PENSIONAPP_CLAMAV_PREFERRED_ENGINE', 'auto'); // auto, clamdscan, or clamscan
define('PENSIONAPP_CLAMAV_CLAMDSCAN_PATH', ''); // e.g. /usr/bin/clamdscan on Linux/cPanel
define('PENSIONAPP_CLAMAV_CLAMSCAN_PATH', '');  // e.g. /usr/bin/clamscan or C:\\Program Files\\ClamAV\\clamscan.exe
?>
