<?php
/**
 * 
 * Configuration File
 * 
 * - Database connection
 * - SessionManager integration
 * - Single timeout source
 * - Enhanced security headers
 * 
 */

if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

function appEnv(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return (string)$value;
}

function getConfiguredAppTimezone(): string {
    $configured = trim((string)appEnv(
        'PENSIONAPP_TIMEZONE',
        defined('PENSIONAPP_TIMEZONE') ? PENSIONAPP_TIMEZONE : 'Africa/Kampala'
    ));

    if ($configured === '') {
        return 'Africa/Kampala';
    }

    try {
        new DateTimeZone($configured);
        return $configured;
    } catch (Throwable $error) {
        error_log('Invalid app timezone configured: ' . $configured);
        return 'Africa/Kampala';
    }
}

function getConfiguredAppTimezoneOffset(): string {
    $timezone = new DateTimeZone(getConfiguredAppTimezone());
    $offsetSeconds = $timezone->getOffset(new DateTimeImmutable('now', $timezone));
    $sign = $offsetSeconds >= 0 ? '+' : '-';
    $offsetSeconds = abs($offsetSeconds);
    $hours = intdiv($offsetSeconds, 3600);
    $minutes = intdiv($offsetSeconds % 3600, 60);
    return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
}

date_default_timezone_set(getConfiguredAppTimezone());

function getFirstHeaderListValue(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }

    foreach (preg_split('/\s*,\s*/', $raw) as $part) {
        $part = trim((string)$part);
        if ($part !== '') {
            return $part;
        }
    }

    return '';
}

function getForwardedRequestMeta(): array {
    static $meta = null;
    if ($meta !== null) {
        return $meta;
    }

    $meta = [];
    $forwarded = getFirstHeaderListValue((string)($_SERVER['HTTP_FORWARDED'] ?? ''));
    if ($forwarded === '') {
        return $meta;
    }

    foreach (preg_split('/\s*;\s*/', $forwarded) as $segment) {
        if (!str_contains($segment, '=')) {
            continue;
        }

        [$key, $value] = array_map('trim', explode('=', $segment, 2));
        $key = strtolower($key);
        $value = trim($value, "\"' ");
        if ($key !== '' && $value !== '') {
            $meta[$key] = $value;
        }
    }

    return $meta;
}

function normalizeSchemeCandidate(?string $value): string {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === '') {
        return '';
    }

    if (in_array($normalized, ['https', 'http'], true)) {
        return $normalized;
    }

    if (in_array($normalized, ['on', 'ssl', '1', 'true', 'yes'], true)) {
        return 'https';
    }

    if (in_array($normalized, ['off', '0', 'false', 'no'], true)) {
        return 'http';
    }

    return '';
}

function parseAuthorityCandidate(string $candidate, string $scheme = 'http'): array {
    $candidate = getFirstHeaderListValue($candidate);
    if ($candidate === '') {
        return ['host' => '', 'port' => null];
    }

    $url = preg_match('#^[a-z][a-z0-9+\-.]*://#i', $candidate)
        ? $candidate
        : ($scheme . '://' . $candidate);

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return ['host' => '', 'port' => null];
    }

    return [
        'host' => strtolower((string)$parts['host']),
        'port' => isset($parts['port']) ? (int)$parts['port'] : null,
    ];
}

function formatAuthority(string $host, ?int $port, string $scheme): string {
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $host = '[' . $host . ']';
    }

    $defaultPort = $scheme === 'https' ? 443 : 80;
    if ($port !== null && $port > 0 && $port !== $defaultPort) {
        return $host . ':' . $port;
    }

    return $host;
}

function getConfiguredPublicOrigin(): string {
    static $origin = null;
    if ($origin !== null) {
        return $origin;
    }

    $configured = trim((string)appEnv(
        'PENSIONAPP_PUBLIC_URL',
        defined('PENSIONAPP_PUBLIC_URL') ? PENSIONAPP_PUBLIC_URL : ''
    ));

    if ($configured === '') {
        $origin = '';
        return $origin;
    }

    $parts = parse_url($configured);
    if ($parts === false || empty($parts['host'])) {
        $origin = '';
        return $origin;
    }

    $scheme = normalizeSchemeCandidate((string)($parts['scheme'] ?? '')) ?: 'http';
    $authority = formatAuthority(
        (string)$parts['host'],
        isset($parts['port']) ? (int)$parts['port'] : null,
        $scheme
    );

    $origin = $authority !== '' ? ($scheme . '://' . $authority) : '';
    return $origin;
}

function getRequestScheme(): string {
    $configuredOrigin = getConfiguredPublicOrigin();
    if ($configuredOrigin !== '') {
        $configuredScheme = normalizeSchemeCandidate((string)(parse_url($configuredOrigin, PHP_URL_SCHEME) ?? ''));
        if ($configuredScheme !== '') {
            return $configuredScheme;
        }
    }

    $forwarded = getForwardedRequestMeta();
    $candidates = [
        $forwarded['proto'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_PROTOCOL'] ?? '',
        $_SERVER['HTTP_X_URL_SCHEME'] ?? '',
        $_SERVER['HTTP_X_SCHEME'] ?? '',
        $_SERVER['HTTP_CLOUDFRONT_FORWARDED_PROTO'] ?? '',
        $_SERVER['REQUEST_SCHEME'] ?? '',
        $_SERVER['HTTPS'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '',
        $_SERVER['HTTP_FRONT_END_HTTPS'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $normalized = normalizeSchemeCandidate(getFirstHeaderListValue((string)$candidate));
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) ? 'https' : 'http';
}

function requestUsesSecureTransport(): bool {
    return getRequestScheme() === 'https';
}

function getRequestPort(?string $scheme = null): ?int {
    $scheme = $scheme ?: getRequestScheme();

    $configuredOrigin = getConfiguredPublicOrigin();
    if ($configuredOrigin !== '') {
        $configuredPort = parse_url($configuredOrigin, PHP_URL_PORT);
        return $configuredPort !== null ? (int)$configuredPort : null;
    }

    $forwarded = getForwardedRequestMeta();
    $authorityCandidates = [
        $forwarded['host'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
        $_SERVER['HTTP_X_ORIGINAL_HOST'] ?? '',
        $_SERVER['HTTP_HOST'] ?? '',
    ];

    foreach ($authorityCandidates as $candidate) {
        $parts = parseAuthorityCandidate((string)$candidate, $scheme);
        if (!empty($parts['host']) && $parts['port'] !== null) {
            return $parts['port'];
        }
    }

    $portCandidates = [
        $forwarded['port'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_PORT'] ?? '',
        $_SERVER['SERVER_PORT'] ?? '',
    ];

    foreach ($portCandidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '' && ctype_digit($value)) {
            $port = (int)$value;
            if ($port > 0) {
                return $port;
            }
        }
    }

    return null;
}

function getRequestAuthority(): string {
    $configuredOrigin = getConfiguredPublicOrigin();
    if ($configuredOrigin !== '') {
        return (string)(parse_url($configuredOrigin, PHP_URL_HOST)
            ? formatAuthority(
                (string)parse_url($configuredOrigin, PHP_URL_HOST),
                (($port = parse_url($configuredOrigin, PHP_URL_PORT)) !== null ? (int)$port : null),
                getRequestScheme()
            )
            : '');
    }

    $scheme = getRequestScheme();
    $forwarded = getForwardedRequestMeta();
    $candidates = [
        ['value' => $forwarded['host'] ?? '', 'allow_fallback_port' => false],
        ['value' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '', 'allow_fallback_port' => false],
        ['value' => $_SERVER['HTTP_X_ORIGINAL_HOST'] ?? '', 'allow_fallback_port' => false],
        ['value' => $_SERVER['HTTP_HOST'] ?? '', 'allow_fallback_port' => true],
        ['value' => $_SERVER['SERVER_NAME'] ?? '', 'allow_fallback_port' => true],
    ];

    foreach ($candidates as $candidate) {
        $parts = parseAuthorityCandidate((string)($candidate['value'] ?? ''), $scheme);
        if (empty($parts['host'])) {
            continue;
        }

        $port = $parts['port'];
        if ($port === null && !empty($candidate['allow_fallback_port'])) {
            $port = getRequestPort($scheme);
        }

        return formatAuthority($parts['host'], $port, $scheme);
    }

    return '';
}

/* 1Ã¯Â¸ÂÃ¢Æ’Â£ DATABASE CONNECTION */
$host = appEnv('PENSIONAPP_DB_HOST', defined('PENSIONAPP_DB_HOST') ? PENSIONAPP_DB_HOST : 'localhost');
$user = appEnv('PENSIONAPP_DB_USER', defined('PENSIONAPP_DB_USER') ? PENSIONAPP_DB_USER : 'root');
$password = appEnv('PENSIONAPP_DB_PASSWORD', defined('PENSIONAPP_DB_PASSWORD') ? PENSIONAPP_DB_PASSWORD : '');
$database = appEnv('PENSIONAPP_DB_NAME', defined('PENSIONAPP_DB_NAME') ? PENSIONAPP_DB_NAME : 'pension_db');

$conn = @new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    error_log(sprintf(
        'DATABASE CONNECTION ERROR: host=%s database=%s errno=%s error=%s',
        (string)$host,
        (string)$database,
        (string)$conn->connect_errno,
        (string)$conn->connect_error
    ));
    http_response_code(500);
    header("Content-Type: application/json");
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

$conn->set_charset('utf8mb4');
$mysqlTimezoneOffset = getConfiguredAppTimezoneOffset();
$tzStmt = $conn->prepare("SET time_zone = ?");
if ($tzStmt) {
    $tzStmt->bind_param('s', $mysqlTimezoneOffset);
    $tzStmt->execute();
    $tzStmt->close();
} else {
    error_log('Unable to prepare MySQL timezone statement: ' . $conn->error);
}

/* 2Ã¯Â¸ÂÃ¢Æ’Â£ SESSION MANAGER INITIALIZATION */
require_once __DIR__ . '/api/SessionManager.php';
require_once __DIR__ . '/api/TimeoutManager.php';

$sessionManager = SessionManager::getInstance($conn);
$timeoutManager = new TimeoutManager($conn);

// Ensure tb_user_logs can store session_started activity type
ensureUserLogsActivityEnum($conn);
// Ensure role governance tables (custom roles + role permission overrides).
ensureRoleGovernanceTables($conn);
// Ensure tb_users.userRole supports dynamic role keys and normalized values.
ensureUsersRoleColumnSupportsDynamicRoles($conn);
// Ensure granular per-user permission overrides table is available.
ensureUserPermissionsTable($conn);
// Ensure the governed demo super administrator exists for controlled deployments.
ensureDemoSuperAdminAccount($conn);

function getSignedSessionCookieSecret(): string
{
    $configured = appEnv(
        'PENSIONAPP_COOKIE_AUTH_SECRET',
        defined('PENSIONAPP_COOKIE_AUTH_SECRET') ? PENSIONAPP_COOKIE_AUTH_SECRET : ''
    );
    if ($configured !== '') {
        return hash('sha256', $configured);
    }

    $dbHost = appEnv('PENSIONAPP_DB_HOST', defined('PENSIONAPP_DB_HOST') ? PENSIONAPP_DB_HOST : 'localhost');
    $dbUser = appEnv('PENSIONAPP_DB_USER', defined('PENSIONAPP_DB_USER') ? PENSIONAPP_DB_USER : 'root');
    $dbPassword = appEnv('PENSIONAPP_DB_PASSWORD', defined('PENSIONAPP_DB_PASSWORD') ? PENSIONAPP_DB_PASSWORD : '');
    $dbName = appEnv('PENSIONAPP_DB_NAME', defined('PENSIONAPP_DB_NAME') ? PENSIONAPP_DB_NAME : 'pension_db');
    return hash('sha256', $dbHost . '|' . $dbUser . '|' . $dbPassword . '|' . $dbName . '|' . __DIR__);
}

function getSignedSessionCookieSignature(string $sessionId, string $userId): string
{
    return hash_hmac('sha256', $sessionId . '|' . $userId, getSignedSessionCookieSecret());
}

function setSignedSessionCookies(string $sessionId, string $userId): void
{
    if (headers_sent()) {
        return;
    }

    $options = [
        'expires' => 0,
        'path' => '/',
        'secure' => requestUsesSecureTransport(),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('PENSION_APP_AUTH_SID', $sessionId, $options);
    setcookie('PENSION_APP_AUTH_UID', $userId, $options);
    setcookie('PENSION_APP_AUTH_SIG', getSignedSessionCookieSignature($sessionId, $userId), $options);
}

function clearSignedSessionCookies(): void
{
    if (headers_sent()) {
        return;
    }

    $options = [
        'expires' => time() - 42000,
        'path' => '/',
        'secure' => requestUsesSecureTransport(),
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('PENSION_APP_AUTH_SID', '', $options);
    setcookie('PENSION_APP_AUTH_UID', '', $options);
    setcookie('PENSION_APP_AUTH_SIG', '', $options);
    setcookie('PENSION_APP_CLIENT_SID', '', $options);
    setcookie('PENSION_APP_CLIENT_UID', '', $options);
}

function restoreSessionFromSignedAuthCookies(mysqli $conn): bool
{
    if (isset($_SESSION['session_id'], $_SESSION['userId'])) {
        return true;
    }

    $sessionId = trim((string)($_SERVER['HTTP_X_PENSIONSGO_SESSION_ID'] ?? $_COOKIE['PENSION_APP_AUTH_SID'] ?? $_COOKIE['PENSION_APP_CLIENT_SID'] ?? ''));
    $userId = trim((string)($_SERVER['HTTP_X_PENSIONSGO_USER_ID'] ?? $_COOKIE['PENSION_APP_AUTH_UID'] ?? $_COOKIE['PENSION_APP_CLIENT_UID'] ?? ''));
    $signature = trim((string)($_COOKIE['PENSION_APP_AUTH_SIG'] ?? ''));
    $usingHeaderFallback = isset($_SERVER['HTTP_X_PENSIONSGO_SESSION_ID'], $_SERVER['HTTP_X_PENSIONSGO_USER_ID']);
    $usingClientCookieFallback = !$usingHeaderFallback && isset($_COOKIE['PENSION_APP_CLIENT_SID'], $_COOKIE['PENSION_APP_CLIENT_UID']);
    if ($sessionId === '' || $userId === '') {
        return false;
    }
    if (!preg_match('/^[a-f0-9]{64}$/i', $sessionId)) {
        clearSignedSessionCookies();
        return false;
    }
    if (!$usingHeaderFallback && !$usingClientCookieFallback) {
        if ($signature === '' || !hash_equals(getSignedSessionCookieSignature($sessionId, $userId), $signature)) {
            clearSignedSessionCookies();
            return false;
        }
    }

    $stmt = $conn->prepare("
        SELECT s.session_id, s.user_id, s.device_id, u.userName, u.userRole, u.userPhoto, u.phoneNo, u.userEmail
        FROM tb_user_sessions s
        INNER JOIN tb_users u ON u.userId = s.user_id
        WHERE s.session_id = ?
          AND s.user_id = ?
          AND s.is_active = 1
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $sessionId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        clearSignedSessionCookies();
        return false;
    }
    if ($usingHeaderFallback || $usingClientCookieFallback) {
        $requestDeviceId = resolveClientDeviceIdentifierHash(getRequestDeviceToken());
        $storedDeviceId = (string)($row['device_id'] ?? '');
        if ($storedDeviceId !== '' && !hash_equals($storedDeviceId, $requestDeviceId)) {
            return false;
        }
    }

    $_SESSION['userId'] = (string)$row['user_id'];
    $_SESSION['userName'] = (string)($row['userName'] ?? '');
    $_SESSION['userRole'] = (string)($row['userRole'] ?? '');
    $_SESSION['userRoleEffective'] = function_exists('resolveRoleAccessKey')
        ? resolveRoleAccessKey($conn, (string)($row['userRole'] ?? ''))
        : (string)($row['userRole'] ?? '');
    $_SESSION['userPhoto'] = $row['userPhoto'] ?? null;
    $_SESSION['phoneNo'] = $row['phoneNo'] ?? null;
    $_SESSION['userEmail'] = $row['userEmail'] ?? null;
    $_SESSION['session_id'] = $sessionId;
    $_SESSION['last_activity'] = time();
    $_SESSION['device_id'] = (string)($row['device_id'] ?? '');
    return true;
}

/* 3Ã¯Â¸ÂÃ¢Æ’Â£ SECURE SESSION INITIALIZATION */
if (session_status() === PHP_SESSION_NONE) {
    // Determine if using HTTPS, including reverse-proxy / tunnel deployments.
    $isSecure = requestUsesSecureTransport();

    $sessionSavePath = appEnv(
        'PENSIONAPP_SESSION_SAVE_PATH',
        defined('PENSIONAPP_SESSION_SAVE_PATH') ? PENSIONAPP_SESSION_SAVE_PATH : ''
    );
    if ($sessionSavePath === '') {
        $sessionSavePath = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'php_sessions';
    }
    if (!is_dir($sessionSavePath)) {
        @mkdir($sessionSavePath, 0775, true);
    }
    if (is_dir($sessionSavePath) && is_writable($sessionSavePath)) {
        ini_set('session.save_path', $sessionSavePath);
    }
    
    // Get a cookie-safe host. Localhost, IP literals, and host:port values should not
    // force a Domain attribute because browsers may reject those cookies entirely.
    $hostHeader = trim(getRequestAuthority());
    $parsedHost = '';
    if ($hostHeader !== '') {
        $parsedHost = (string)(parse_url(
            str_contains($hostHeader, '://') ? $hostHeader : ('http://' . $hostHeader),
            PHP_URL_HOST
        ) ?? '');
    }
    $parsedHost = trim($parsedHost, "[] \t\n\r\0\x0B");
    $normalizedHost = strtolower($parsedHost);

    $domain = '';
    $isLocalCookieHost = $normalizedHost === ''
        || $normalizedHost === 'localhost'
        || str_ends_with($normalizedHost, '.localhost')
        || filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false;

    if (!$isLocalCookieHost) {
        // Keep the explicit domain for real hosts so secure cross-page sessions continue
        // to work on named environments such as ngrok or deployed domains.
        $domain = $parsedHost;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    if ($isSecure) {
        ini_set('session.cookie_secure', '1');
    }
    
    // Set session cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // if ($domain !== '') {
    //     session_set_cookie_params([
    //         'lifetime' => 0,
    //         'path' => '/',
    //         'domain' => $domain,
    //         'secure' => $isSecure,
    //         'httponly' => true,
    //         'samesite' => 'Lax'
    //     ]);
    // }
    
    // Start session
    session_name('PENSION_APP_SESS');
    session_start();
    restoreSessionFromSignedAuthCookies($conn);
}

if (isAppApiRequest()) {
    applyApiCorsPolicy($conn, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
}

applyAppSecurityHeaders();
enforceRequestSecurityControls($conn);

/* Support Functions*/
// 
// Geolocation Settings //
define('GEOIP_ENABLED', true);
define('GEOIP_PROVIDER', 'ipapi'); // 'ipapi' or 'ipinfo'
define('GEOIP_API_KEY', ''); // Optional (used for ipinfo token)
define('GEOIP_CACHE_TTL', 604800); // 7 days
define('GEOIP_TIMEOUT_SECONDS', 2);

//
// MAIL SETTINGS
//
defined('MAIL_TRANSPORT') || define('MAIL_TRANSPORT', appEnv('PENSIONAPP_MAIL_TRANSPORT', defined('PENSIONAPP_MAIL_TRANSPORT') ? PENSIONAPP_MAIL_TRANSPORT : 'smtp')); // 'smtp' or 'mail'
defined('MAIL_FROM_ADDRESS') || define('MAIL_FROM_ADDRESS', appEnv('PENSIONAPP_MAIL_FROM_ADDRESS', defined('PENSIONAPP_MAIL_FROM_ADDRESS') ? PENSIONAPP_MAIL_FROM_ADDRESS : 'no-reply@pensionsgo.local'));
defined('MAIL_FROM_NAME') || define('MAIL_FROM_NAME', appEnv('PENSIONAPP_MAIL_FROM_NAME', defined('PENSIONAPP_MAIL_FROM_NAME') ? PENSIONAPP_MAIL_FROM_NAME : 'PensionsGo'));
defined('MAIL_SMTP_HOST') || define('MAIL_SMTP_HOST', appEnv('PENSIONAPP_MAIL_SMTP_HOST', defined('PENSIONAPP_MAIL_SMTP_HOST') ? PENSIONAPP_MAIL_SMTP_HOST : 'localhost'));
defined('MAIL_SMTP_PORT') || define('MAIL_SMTP_PORT', (int)appEnv('PENSIONAPP_MAIL_SMTP_PORT', defined('PENSIONAPP_MAIL_SMTP_PORT') ? (string)PENSIONAPP_MAIL_SMTP_PORT : '25'));
defined('MAIL_SMTP_TIMEOUT') || define('MAIL_SMTP_TIMEOUT', (int)appEnv('PENSIONAPP_MAIL_SMTP_TIMEOUT', defined('PENSIONAPP_MAIL_SMTP_TIMEOUT') ? (string)PENSIONAPP_MAIL_SMTP_TIMEOUT : '5'));
defined('MAIL_SMTP_ENCRYPTION') || define('MAIL_SMTP_ENCRYPTION', strtolower((string)appEnv('PENSIONAPP_MAIL_SMTP_ENCRYPTION', defined('PENSIONAPP_MAIL_SMTP_ENCRYPTION') ? PENSIONAPP_MAIL_SMTP_ENCRYPTION : 'none'))); // none, tls, ssl
defined('MAIL_SMTP_USERNAME') || define('MAIL_SMTP_USERNAME', appEnv('PENSIONAPP_MAIL_SMTP_USERNAME', defined('PENSIONAPP_MAIL_SMTP_USERNAME') ? PENSIONAPP_MAIL_SMTP_USERNAME : ''));
defined('MAIL_SMTP_PASSWORD') || define('MAIL_SMTP_PASSWORD', appEnv('PENSIONAPP_MAIL_SMTP_PASSWORD', defined('PENSIONAPP_MAIL_SMTP_PASSWORD') ? PENSIONAPP_MAIL_SMTP_PASSWORD : ''));
defined('MAIL_SMTP_HELO_DOMAIN') || define('MAIL_SMTP_HELO_DOMAIN', appEnv('PENSIONAPP_MAIL_SMTP_HELO_DOMAIN', defined('PENSIONAPP_MAIL_SMTP_HELO_DOMAIN') ? PENSIONAPP_MAIL_SMTP_HELO_DOMAIN : ($_SERVER['HTTP_HOST'] ?? 'localhost')));
defined('NOTIFY_QUEUE_WORKER_ENABLED_OVERRIDE') || define('NOTIFY_QUEUE_WORKER_ENABLED_OVERRIDE', appEnv('PENSIONAPP_NOTIFY_QUEUE_WORKER_ENABLED', defined('PENSIONAPP_NOTIFY_QUEUE_WORKER_ENABLED') ? (PENSIONAPP_NOTIFY_QUEUE_WORKER_ENABLED ? '1' : '0') : ''));
defined('NOTIFY_QUEUE_PROCESS_ON_REQUEST_OVERRIDE') || define('NOTIFY_QUEUE_PROCESS_ON_REQUEST_OVERRIDE', appEnv('PENSIONAPP_NOTIFY_QUEUE_PROCESS_ON_REQUEST', defined('PENSIONAPP_NOTIFY_QUEUE_PROCESS_ON_REQUEST') ? (PENSIONAPP_NOTIFY_QUEUE_PROCESS_ON_REQUEST ? '1' : '0') : ''));
defined('CLAMAV_PREFERRED_ENGINE') || define('CLAMAV_PREFERRED_ENGINE', strtolower((string)appEnv('PENSIONAPP_CLAMAV_PREFERRED_ENGINE', defined('PENSIONAPP_CLAMAV_PREFERRED_ENGINE') ? PENSIONAPP_CLAMAV_PREFERRED_ENGINE : 'auto')));
defined('CLAMAV_CLAMDSCAN_PATH') || define('CLAMAV_CLAMDSCAN_PATH', appEnv('PENSIONAPP_CLAMAV_CLAMDSCAN_PATH', defined('PENSIONAPP_CLAMAV_CLAMDSCAN_PATH') ? PENSIONAPP_CLAMAV_CLAMDSCAN_PATH : ''));
defined('CLAMAV_CLAMSCAN_PATH') || define('CLAMAV_CLAMSCAN_PATH', appEnv('PENSIONAPP_CLAMAV_CLAMSCAN_PATH', defined('PENSIONAPP_CLAMAV_CLAMSCAN_PATH') ? PENSIONAPP_CLAMAV_CLAMSCAN_PATH : ''));
defined('CLAMAV_DATABASE_PATH') || define('CLAMAV_DATABASE_PATH', appEnv('PENSIONAPP_CLAMAV_DATABASE_PATH', defined('PENSIONAPP_CLAMAV_DATABASE_PATH') ? PENSIONAPP_CLAMAV_DATABASE_PATH : ''));

// 
// APP SETTINGS (persistent config)
// 
function ensureAppSettingsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_app_settings (
            setting_key varchar(100) NOT NULL,
            setting_value text NOT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function getAppSetting(mysqli $conn, string $key): ?string {
    static $cache = [];

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    ensureAppSettingsTable($conn);
    $stmt = $conn->prepare("SELECT setting_value FROM tb_app_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = null;
    if ($row = $result->fetch_assoc()) {
        $value = $row['setting_value'];
    }
    $stmt->close();

    $cache[$key] = $value;
    return $value;
}

function setAppSetting(mysqli $conn, string $key, string $value): bool {
    ensureAppSettingsTable($conn);
    $stmt = $conn->prepare("
        INSERT INTO tb_app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = NOW()
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $key, $value);
    $ok = $stmt->execute();
    $stmt->close();

    if ($key === 'geolocation_enabled') {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $GLOBALS['__geoip_enabled_setting'] = ($normalized === null) ? ($value === '1') : (bool)$normalized;
    }

    return $ok;
}

//
// User-scoped settings (per-user preferences)
//
function ensureUserSettingsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_user_settings (
            user_id varchar(100) NOT NULL,
            setting_key varchar(120) NOT NULL,
            setting_value longtext NOT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (user_id, setting_key),
            KEY idx_user_settings_key (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function getUserSetting(mysqli $conn, string $userId, string $key): ?string {
    if ($userId === '' || $key === '') {
        return null;
    }
    ensureUserSettingsTable($conn);
    $stmt = $conn->prepare("SELECT setting_value FROM tb_user_settings WHERE user_id = ? AND setting_key = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ss', $userId, $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $value = null;
    if ($row = $result->fetch_assoc()) {
        $value = $row['setting_value'] ?? null;
    }
    $stmt->close();
    return $value;
}

function setUserSetting(mysqli $conn, string $userId, string $key, string $value): bool {
    if ($userId === '' || $key === '') {
        return false;
    }
    ensureUserSettingsTable($conn);
    $stmt = $conn->prepare("
        INSERT INTO tb_user_settings (user_id, setting_key, setting_value)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_at = NOW()
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('sss', $userId, $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function deleteUserSetting(mysqli $conn, string $userId, string $key): bool {
    if ($userId === '' || $key === '') {
        return false;
    }
    ensureUserSettingsTable($conn);
    $stmt = $conn->prepare("DELETE FROM tb_user_settings WHERE user_id = ? AND setting_key = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $userId, $key);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function isGeoipEnabled(): bool {
    if (array_key_exists('__geoip_enabled_setting', $GLOBALS)) {
        return (bool)$GLOBALS['__geoip_enabled_setting'];
    }

    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        $value = getAppSetting($conn, 'geolocation_enabled');
        if ($value !== null) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $enabled = ($normalized === null) ? ($value === '1') : (bool)$normalized;
            $GLOBALS['__geoip_enabled_setting'] = $enabled;
            return $enabled;
        }
    }

    $GLOBALS['__geoip_enabled_setting'] = (bool)GEOIP_ENABLED;
    return (bool)GEOIP_ENABLED;
}

// 
// App Settings Helpers //
function getAppSettingBool(mysqli $conn, string $key, bool $default = false): bool {
    $raw = getAppSetting($conn, $key);
    if ($raw === null) {
        return $default;
    }
    $flag = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return ($flag === null) ? ($raw === '1') : (bool)$flag;
}

function getAppSettingInt(mysqli $conn, string $key, int $default = 0): int {
    $raw = getAppSetting($conn, $key);
    if (!is_numeric($raw)) {
        return $default;
    }
    return (int)$raw;
}

function getAppSettingString(mysqli $conn, string $key, string $default = ''): string {
    $raw = getAppSetting($conn, $key);
    if ($raw === null) {
        return $default;
    }
    return (string)$raw;
}

function pgoRunDebouncedMaintenanceTask(string $taskKey, int $minIntervalSeconds, callable $callback): array {
    $taskKey = preg_replace('/[^a-z0-9_\-]/i', '_', trim($taskKey));
    if ($taskKey === '') {
        $taskKey = 'general_task';
    }

    $minIntervalSeconds = max(15, min(3600, (int)$minIntervalSeconds));
    $cacheDir = __DIR__ . '/cache/maintenance';
    $stateFile = $cacheDir . '/' . $taskKey . '.json';
    $lockFile = $cacheDir . '/' . $taskKey . '.lock';

    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        $result = $callback();
        return ['executed' => true, 'reason' => 'cache_unavailable', 'result' => $result];
    }

    $handle = @fopen($lockFile, 'c+');
    if ($handle === false) {
        $result = $callback();
        return ['executed' => true, 'reason' => 'lock_unavailable', 'result' => $result];
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['executed' => false, 'reason' => 'busy'];
    }

    $lastRun = 0;
    if (is_file($stateFile)) {
        $raw = @file_get_contents($stateFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $lastRun = (int)($decoded['last_success_ts'] ?? 0);
            }
        }
    }

    $now = time();
    if ($lastRun > 0 && ($now - $lastRun) < $minIntervalSeconds) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        return ['executed' => false, 'reason' => 'debounced', 'last_success_ts' => $lastRun];
    }

    $started = microtime(true);
    try {
        $result = $callback();
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        @file_put_contents($stateFile, json_encode([
            'last_success_ts' => $now,
            'duration_ms' => $durationMs
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);

        @flock($handle, LOCK_UN);
        fclose($handle);

        return ['executed' => true, 'reason' => 'ok', 'duration_ms' => $durationMs, 'result' => $result];
    } catch (Throwable $e) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        throw $e;
    }
}

function getStaffDueVerificationEscalationDays(mysqli $conn): int {
    $days = getAppSettingInt($conn, 'staff_due_verification_escalation_days', 60);
    return max(7, min(365, $days));
}

function getStaffDueVerificationDueSoonDays(mysqli $conn, ?int $escalationDays = null): int {
    $threshold = $escalationDays ?? getStaffDueVerificationEscalationDays($conn);
    $leadDays = min(15, max(3, (int)floor($threshold / 4)));
    return max(1, $threshold - $leadDays);
}

function formatBytes($bytes): string {
    $bytes = max(0, (float)$bytes);
    if ($bytes < 1024) {
        return number_format($bytes, 0) . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    return number_format($value, $value >= 100 ? 0 : 1) . ' ' . $units[$unitIndex];
}

function tableExists(mysqli $conn, string $tableName): bool {
    $tableName = trim($tableName);
    if ($tableName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        return false;
    }
    $escaped = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function isAppCliRuntime(): bool {
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

function isAppApiRequest(): bool {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    return strpos($script, '/backend/api/') !== false;
}

function getCurrentRequestScriptName(): string {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    return basename($script);
}

function isStateChangingRequestMethod(?string $method = null): bool {
    $method = strtoupper($method ?? (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function requestHasAuthenticatedSession(): bool {
    return !empty($_SESSION['userId']) && !empty($_SESSION['userRole']);
}

function requestSecurityShouldReturnJson(): bool {
    if (isAppApiRequest()) {
        return true;
    }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    return strpos($accept, 'application/json') !== false || $requestedWith === 'xmlhttprequest';
}

function sendRequestSecurityFailure(int $statusCode, string $message, array $extra = []): void {
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        if (requestSecurityShouldReturnJson()) {
            header('Content-Type: application/json; charset=utf-8');
        } else {
            header('Content-Type: text/plain; charset=utf-8');
        }
    }

    if (requestSecurityShouldReturnJson()) {
        echo json_encode(array_merge(['success' => false, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        echo $message;
    }
    exit;
}

function getRequestOriginBase(): string {
    $configuredOrigin = getConfiguredPublicOrigin();
    if ($configuredOrigin !== '') {
        return $configuredOrigin;
    }

    $scheme = getRequestScheme();
    $authority = getRequestAuthority();
    return $authority !== '' ? ($scheme . '://' . $authority) : '';
}

function normalizeComparableOrigin(string $origin): string {
    $parts = parse_url($origin);
    if ($parts === false || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string)($parts['scheme'] ?? 'http'));
    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? (int)$parts['port'] : null;

    if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
        $port = null;
    }

    return $scheme . '://' . $host . ($port ? ':' . $port : '');
}

function requestOriginMatchesCurrentHost(?mysqli $conn = null): bool {
    $expected = normalizeComparableOrigin(getRequestOriginBase());
    if ($expected === '') {
        return true;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($origin !== '') {
        $normalizedOrigin = normalizeComparableOrigin($origin);
        if ($normalizedOrigin === $expected) {
            return true;
        }
        if ($conn instanceof mysqli && isRequestOriginAllowed($conn, $origin)) {
            return true;
        }
        return false;
    }
    if ($referer !== '') {
        $normalizedReferer = normalizeComparableOrigin($referer);
        if ($normalizedReferer === $expected) {
            return true;
        }
        if ($conn instanceof mysqli && isRequestOriginAllowed($conn, $referer)) {
            return true;
        }
        return false;
    }

    return true;
}

function isCsrfExemptRequest(): bool {
    static $exempt = [
        'login.php',
        'get_csrf_token.php'
    ];

    return in_array(strtolower(getCurrentRequestScriptName()), $exempt, true);
}

function getSessionCsrfToken(bool $forceRefresh = false): string {
    if ($forceRefresh || empty($_SESSION['csrf_token']) || !preg_match('/^[a-f0-9]{64}$/', (string)$_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_created_at'] = time();
    }

    return (string)$_SESSION['csrf_token'];
}

function getSecurityMaxUploadBytes(mysqli $conn): int {
    $configuredMb = max(1, getAppSettingInt($conn, 'security_max_upload_size_mb', 25));
    return $configuredMb * 1024 * 1024;
}

function getSecurityMaxZipUncompressedBytes(mysqli $conn): int {
    $configuredMb = max(1, getAppSettingInt($conn, 'security_max_zip_uncompressed_mb', 64));
    return $configuredMb * 1024 * 1024;
}

function getSecurityMaxImportRows(mysqli $conn): int {
    return max(100, getAppSettingInt($conn, 'security_max_import_rows', 5000));
}

function getSecurityMaxZipEntries(mysqli $conn): int {
    return max(10, getAppSettingInt($conn, 'security_max_zip_entries', 2000));
}

function applyAppSecurityHeaders(): void {
    if (headers_sent() || isAppCliRuntime()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self), fullscreen=(self)');

    if (isAppApiRequest()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

function getDangerousUploadExtensions(): array {
    return [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'cmd', 'bat',
        'com', 'exe', 'dll', 'msi', 'jsp', 'asp', 'aspx', 'cer',
        'js', 'mjs', 'html', 'htm', 'shtml', 'svg'
    ];
}

function sanitizeUploadedFileName(string $fileName, string $fallback = 'upload.bin'): string {
    $base = basename(str_replace('\\', '/', $fileName));
    $base = preg_replace('/[\x00-\x1F\x7F]+/', '', $base);
    $base = preg_replace('/[^a-zA-Z0-9._ -]+/', '_', (string)$base);
    $base = preg_replace('/\s+/', ' ', (string)$base);
    $base = trim((string)$base, " .-_\t\n\r\0\x0B");
    return $base !== '' ? $base : $fallback;
}

function assertUploadedFileIsSafe(mysqli $conn, array $file, array $allowedExtensions = [], array $allowedMimePrefixes = [], string $label = 'Uploaded file'): array {
    enforceUploadedFileSizeLimit($conn, $file, $label);

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException($label . ' is not a valid uploaded file.');
    }

    $originalName = sanitizeUploadedFileName((string)($file['name'] ?? ''), 'upload.bin');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '') {
        throw new RuntimeException($label . ' must have a file extension.');
    }

    $parts = array_map('strtolower', array_filter(explode('.', $originalName)));
    foreach ($parts as $part) {
        if (in_array($part, getDangerousUploadExtensions(), true)) {
            throw new RuntimeException($label . ' uses an unsafe file type.');
        }
    }

    $allowedExtensions = array_values(array_unique(array_map('strtolower', $allowedExtensions)));
    if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException($label . ' uses an unsupported file type.');
    }

    $mimeType = 'application/octet-stream';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = (string)$finfo->file($tmpPath);
        if ($detected !== '') {
            $mimeType = $detected;
        }
    } elseif (function_exists('mime_content_type')) {
        $detected = (string)@mime_content_type($tmpPath);
        if ($detected !== '') {
            $mimeType = $detected;
        }
    }

    if (!empty($allowedMimePrefixes)) {
        $matchesMime = false;
        foreach ($allowedMimePrefixes as $prefix) {
            $prefix = strtolower(trim((string)$prefix));
            if ($prefix !== '' && str_starts_with(strtolower($mimeType), rtrim($prefix, '*'))) {
                $matchesMime = true;
                break;
            }
        }
        if (!$matchesMime) {
            throw new RuntimeException($label . ' content does not match an allowed file type.');
        }
    }

    if (in_array($extension, ['zip', 'xlsx', 'docx'], true)) {
        enforceZipArchiveSafety($conn, $tmpPath, $label);
    }

    return [
        'original_name' => $originalName,
        'extension' => $extension,
        'tmp_name' => $tmpPath,
        'mime_type' => $mimeType,
        'file_size' => (int)($file['size'] ?? 0),
        'file_hash' => @hash_file('sha256', $tmpPath) ?: ''
    ];
}

function ensureUploadDirectoryGuard(string $directory): void {
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare upload storage.');
    }

    $htaccess = rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, implode("\n", [
            'Options -Indexes',
            '<FilesMatch "\\.(php|php[0-9]?|phtml|phar|cgi|pl|py|rb|sh|asp|aspx|jsp|js|mjs|html?|shtml|svg)$">',
            '  Require all denied',
            '</FilesMatch>',
            'AddType text/plain .php .php3 .php4 .php5 .php7 .phtml .phar .cgi .pl .py .rb .sh .asp .aspx .jsp',
            ''
        ]), LOCK_EX);
    }

    $index = rtrim($directory, "/\\") . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '', LOCK_EX);
    }
}

function getSecurityAllowedOrigins(mysqli $conn): array {
    $configured = trim(getAppSettingString($conn, 'security_allowed_origins', ''));
    if ($configured === '') {
        return [];
    }

    $origins = [];
    foreach (preg_split('/[\r\n,]+/', $configured) as $origin) {
        $normalized = normalizeComparableOrigin(trim($origin));
        if ($normalized !== '') {
            $origins[$normalized] = true;
        }
    }

    return array_keys($origins);
}

function isRequestOriginAllowed(mysqli $conn, ?string $origin = null): bool {
    $origin = normalizeComparableOrigin((string)($origin ?? ($_SERVER['HTTP_ORIGIN'] ?? '')));
    if ($origin === '') {
        return true;
    }

    $currentOrigin = normalizeComparableOrigin(getRequestOriginBase());
    if ($currentOrigin !== '' && $origin === $currentOrigin) {
        return true;
    }

    return in_array($origin, getSecurityAllowedOrigins($conn), true);
}

function applyApiCorsPolicy(mysqli $conn, array $methods = ['GET', 'POST', 'OPTIONS'], array $extraHeaders = []): void {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && isRequestOriginAllowed($conn, $origin) && !headers_sent()) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: ' . implode(', ', array_unique($methods)));
        $headers = array_unique(array_merge([
            'Content-Type',
            'X-Requested-With',
            'Accept',
            'X-Device-Token',
            'X-PensionsGo-Session-Id',
            'X-PensionsGo-User-Id',
            'X-CSRF-Token'
        ], $extraHeaders));
        header('Access-Control-Allow-Headers: ' . implode(', ', $headers));
    }
}

function getAdminReauthWindowSeconds(mysqli $conn): int {
    $minutes = max(1, getAppSettingInt($conn, 'security_admin_reauth_window_minutes', 10));
    return $minutes * 60;
}

function markAdminSensitiveActionVerified(mysqli $conn, ?string $actionLabel = null): void {
    $_SESSION['admin_reauth_verified_at'] = time();
    $_SESSION['admin_reauth_action'] = $actionLabel ?: 'admin_sensitive_action';
}

function hasRecentAdminSensitiveVerification(mysqli $conn): bool {
    $verifiedAt = (int)($_SESSION['admin_reauth_verified_at'] ?? 0);
    if ($verifiedAt <= 0) {
        return false;
    }
    return (time() - $verifiedAt) <= getAdminReauthWindowSeconds($conn);
}

function requireRecentAdminSensitiveVerification(mysqli $conn, string $message = 'Admin password verification is required for this action.'): void {
    if (!requestHasAuthenticatedSession() || !currentSessionHasAdminAccess($conn)) {
        sendRequestSecurityFailure(403, 'Admin access required.');
    }

    if (hasRecentAdminSensitiveVerification($conn)) {
        return;
    }

    sendRequestSecurityFailure(428, $message, ['requiresReauth' => true]);
}

function mapUploadErrorToMessage(int $errorCode): string {
    return match ($errorCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the configured size limit.',
        UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially received.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
        default => 'The uploaded file could not be processed.'
    };
}

function enforceUploadedFileSizeLimit(mysqli $conn, array $file, string $label = 'Uploaded file'): void {
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_OK);
    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException($label . ': ' . mapUploadErrorToMessage($errorCode));
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException($label . ' is empty or unreadable.');
    }

    $maxBytes = getSecurityMaxUploadBytes($conn);
    if ($size > $maxBytes) {
        throw new RuntimeException(sprintf('%s exceeds the configured upload limit of %d MB.', $label, (int)round($maxBytes / 1048576)));
    }
}

function enforceZipArchiveSafety(mysqli $conn, string $absolutePath, string $label = 'Archive'): void {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required to inspect ' . strtolower($label) . '.');
    }

    $zip = new ZipArchive();
    if ($zip->open($absolutePath) !== true) {
        throw new RuntimeException('Unable to inspect ' . strtolower($label) . '.');
    }

    $maxEntries = getSecurityMaxZipEntries($conn);
    $maxUncompressedBytes = getSecurityMaxZipUncompressedBytes($conn);
    if ($zip->numFiles > $maxEntries) {
        $zip->close();
        throw new RuntimeException(sprintf('%s contains too many files. Maximum allowed is %d entries.', $label, $maxEntries));
    }

    $totalUncompressed = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = (string)$zip->getNameIndex($i);
        if ($entryName === '') {
            continue;
        }

        $normalizedEntry = str_replace('\\', '/', $entryName);
        if (preg_match('#(^|/)\.\.(?:/|$)#', $normalizedEntry) || preg_match('#^[a-zA-Z]:/#', $normalizedEntry) || str_starts_with($normalizedEntry, '/')) {
            $zip->close();
            throw new RuntimeException($label . ' contains an unsafe file path.');
        }

        $stats = $zip->statIndex($i);
        $entrySize = (int)($stats['size'] ?? 0);
        $totalUncompressed += max(0, $entrySize);
        if ($totalUncompressed > $maxUncompressedBytes) {
            $zip->close();
            throw new RuntimeException(sprintf('%s exceeds the configured extracted size limit of %d MB.', $label, (int)round($maxUncompressedBytes / 1048576)));
        }
    }

    $zip->close();
}

function enforceParsedRowLimit(mysqli $conn, int $rowCount, string $label = 'Imported dataset'): void {
    $maxRows = getSecurityMaxImportRows($conn);
    if ($rowCount > $maxRows) {
        throw new RuntimeException(sprintf('%s contains %d rows, which exceeds the configured limit of %d rows.', $label, $rowCount, $maxRows));
    }
}

function enforceRequestSecurityControls(mysqli $conn): void {
    static $enforced = false;
    if ($enforced || isAppCliRuntime()) {
        return;
    }
    $enforced = true;

    if (!isAppApiRequest() || !isStateChangingRequestMethod()) {
        return;
    }

    if (!requestHasAuthenticatedSession()) {
        return;
    }

    if (getAppSettingBool($conn, 'security_validate_origin', true) && !requestOriginMatchesCurrentHost($conn)) {
        sendRequestSecurityFailure(403, 'Request origin validation failed.');
    }

    if (!getAppSettingBool($conn, 'security_enforce_csrf', true) || isCsrfExemptRequest()) {
        return;
    }

    $providedToken = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? ''));
    $expectedToken = getSessionCsrfToken();
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        sendRequestSecurityFailure(419, 'Security token validation failed. Refresh the page and try again.');
    }
}

function ensurePodcastTables(mysqli $conn): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_podcast_videos (
            podcast_id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text DEFAULT NULL,
            audience enum('public','staff','pensioner') NOT NULL DEFAULT 'public',
            youtube_url varchar(500) NOT NULL,
            youtube_id varchar(32) NOT NULL,
            tags text DEFAULT NULL,
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            is_published tinyint(1) NOT NULL DEFAULT 1,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_by varchar(100) DEFAULT NULL,
            updated_by varchar(100) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (podcast_id),
            KEY idx_podcast_audience (audience),
            KEY idx_podcast_published (is_published),
            KEY idx_podcast_featured (is_featured),
            KEY idx_podcast_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_podcast_views (
            view_id int(11) NOT NULL AUTO_INCREMENT,
            podcast_id int(11) NOT NULL,
            viewer_id varchar(100) DEFAULT NULL,
            viewer_role varchar(50) DEFAULT NULL,
            session_id varchar(128) DEFAULT NULL,
            viewed_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (view_id),
            KEY idx_podcast_view_podcast (podcast_id),
            KEY idx_podcast_view_viewer (viewer_id),
            KEY idx_podcast_viewed_at (viewed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $initialized = true;
}

function extractPodcastYouTubeId(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url)) {
        return $url;
    }

    $parts = @parse_url($url);
    if (!is_array($parts)) {
        return '';
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    $path = trim((string)($parts['path'] ?? ''), '/');
    parse_str((string)($parts['query'] ?? ''), $query);

    if (isset($query['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', (string)$query['v'])) {
        return (string)$query['v'];
    }

    if (str_contains($host, 'youtu.be') && preg_match('/^[A-Za-z0-9_-]{11}$/', $path)) {
        return $path;
    }

    $segments = $path === '' ? [] : explode('/', $path);
    foreach ($segments as $segment) {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $segment)) {
            return $segment;
        }
    }

    return '';
}

function buildPodcastEmbedUrl(string $youtubeId): string {
    return $youtubeId === '' ? '' : 'https://www.youtube.com/embed/' . rawurlencode($youtubeId) . '?rel=0&modestbranding=1';
}

function getPodcastAudienceLabel(string $audience): string {
    return match (strtolower(trim($audience))) {
        'staff' => 'Staff',
        'pensioner' => 'Pensioners',
        default => 'Public'
    };
}

function getAllowedPodcastAudiencesForRole(string $role): array {
    $normalizedRole = normalizeRoleKey($role);
    if ($normalizedRole === 'admin') {
        return ['public', 'staff', 'pensioner'];
    }
    if ($normalizedRole === 'pensioner') {
        return ['public', 'pensioner'];
    }
    return ['public', 'staff'];
}

function ensureFeedbackSubmissionsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_feedback_submissions (
            submission_id int(11) NOT NULL AUTO_INCREMENT,
            reference_no varchar(40) NOT NULL,
            feedback_type varchar(60) NOT NULL DEFAULT 'general_feedback',
            audience enum('public','staff','pensioner') NOT NULL DEFAULT 'public',
            full_name varchar(180) NOT NULL,
            email_address varchar(190) DEFAULT NULL,
            phone_number varchar(50) DEFAULT NULL,
            subject varchar(220) NOT NULL,
            message text NOT NULL,
            page_context varchar(255) DEFAULT NULL,
            submitted_by_user_id varchar(100) DEFAULT NULL,
            submitted_by_role varchar(60) DEFAULT NULL,
            status enum('new','reviewed','resolved','closed') NOT NULL DEFAULT 'new',
            submitted_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (submission_id),
            UNIQUE KEY uniq_feedback_reference (reference_no),
            KEY idx_feedback_type (feedback_type),
            KEY idx_feedback_audience (audience),
            KEY idx_feedback_status (status),
            KEY idx_feedback_submitted (submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function ensureFeedbackWorkflowTables(mysqli $conn): void {
    static $ensured = false;
    ensureFeedbackSubmissionsTable($conn);
    if ($ensured) {
        return;
    }

    $columnMap = [
        'priority' => "varchar(20) NOT NULL DEFAULT 'normal'",
        'assigned_to_user_id' => "varchar(100) DEFAULT NULL",
        'assigned_to_name' => "varchar(180) DEFAULT NULL",
        'assigned_to_role' => "varchar(60) DEFAULT NULL",
        'assigned_at' => "timestamp NULL DEFAULT NULL",
        'reviewed_at' => "timestamp NULL DEFAULT NULL",
        'reviewed_by_user_id' => "varchar(100) DEFAULT NULL",
        'reviewed_by_name' => "varchar(180) DEFAULT NULL",
        'reviewed_by_role' => "varchar(60) DEFAULT NULL",
        'resolved_at' => "timestamp NULL DEFAULT NULL",
        'resolved_by_user_id' => "varchar(100) DEFAULT NULL",
        'resolved_by_name' => "varchar(180) DEFAULT NULL",
        'resolved_by_role' => "varchar(60) DEFAULT NULL",
        'closed_at' => "timestamp NULL DEFAULT NULL",
        'closed_by_user_id' => "varchar(100) DEFAULT NULL",
        'closed_by_name' => "varchar(180) DEFAULT NULL",
        'closed_by_role' => "varchar(60) DEFAULT NULL",
        'resolution_summary' => "text DEFAULT NULL"
    ];

    foreach ($columnMap as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_feedback_submissions LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_feedback_submissions ADD COLUMN {$column} {$definition}");
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_feedback_activity (
            activity_id int(11) NOT NULL AUTO_INCREMENT,
            submission_id int(11) NOT NULL,
            action varchar(80) NOT NULL,
            actor_id varchar(100) DEFAULT NULL,
            actor_name varchar(180) DEFAULT NULL,
            actor_role varchar(60) DEFAULT NULL,
            from_status varchar(40) DEFAULT NULL,
            to_status varchar(40) DEFAULT NULL,
            note text DEFAULT NULL,
            field_changes longtext DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (activity_id),
            KEY idx_feedback_activity_submission (submission_id),
            KEY idx_feedback_activity_action (action),
            KEY idx_feedback_activity_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $ensured = true;
}

function ensureFaqTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_faq_entries (
            faq_id int(11) NOT NULL AUTO_INCREMENT,
            question varchar(255) NOT NULL,
            answer text NOT NULL,
            bullets text DEFAULT NULL,
            category varchar(50) NOT NULL DEFAULT 'applications',
            audience_label varchar(120) NOT NULL DEFAULT 'Pensioners, staff, and supervisors',
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (faq_id),
            KEY idx_faq_category (category),
            KEY idx_faq_active (is_active),
            KEY idx_faq_featured (is_featured)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;

    $countResult = $conn->query("SELECT COUNT(*) AS total FROM tb_faq_entries");
    if (!$countResult) {
        return;
    }
    $countRow = $countResult->fetch_assoc();
    $total = (int)($countRow['total'] ?? 0);
    if ($total > 0) {
        return;
    }

    $seedEntries = [
        [
            'question' => 'What does UPS PensionsGo do?',
            'answer' => 'UPS PensionsGo is a workflow and records platform for pension administration. It supports application handling, benefits estimation, claims tracking, registry control, payroll visibility, reporting, and pensioner-facing access.',
            'bullets' => [],
            'category' => 'applications',
            'audience' => 'Pensioners, staff, and supervisors',
            'featured' => 1
        ],
        [
            'question' => 'Who can use the platform?',
            'answer' => 'The platform serves operational staff, supervisors, administrators, and pensioners. Each user sees only the modules and actions permitted for the assigned role or explicit permissions.',
            'bullets' => [],
            'category' => 'applications',
            'audience' => 'Pensioners, staff, and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'How does an application move through the system?',
            'answer' => 'The workflow begins with submission and verification, then moves through authorization, write-up, file creation, data capture, assessment, audit, and approval. Each stage is delegated, monitored, and logged.',
            'bullets' => [],
            'category' => 'applications',
            'audience' => 'Public, pensioners, and staff',
            'featured' => 1
        ],
        [
            'question' => 'Can application status be tracked after submission?',
            'answer' => 'The platform records status progression and exposes application tracking so authorized users and pensioners follow the stage of a case with comments or messages where applicable.',
            'bullets' => [],
            'category' => 'applications',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'Can the system estimate pension benefits before approval?',
            'answer' => 'The benefits calculator estimates service-related outputs such as reduced monthly pension, full pension reference, gratuity, annual salary, and length of service using the configured pension logic.',
            'bullets' => [],
            'category' => 'benefits',
            'audience' => 'Public, pensioners, and staff',
            'featured' => 1
        ],
        [
            'question' => 'Are imported registry records able to auto-compute benefits?',
            'answer' => 'During pension file registry import, the platform computes benefit snapshot values when required source data is present and target fields are missing.',
            'bullets' => [],
            'category' => 'benefits',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'What types of arrears can be tracked?',
            'answer' => 'The claims area supports pension arrears, full pension arrears, gratuity arrears, combined pension and gratuity arrears, underpayment claims, and related payment-accountability handling.',
            'bullets' => [],
            'category' => 'claims',
            'audience' => 'Pensioners and staff',
            'featured' => 1
        ],
        [
            'question' => 'How does the system treat accountability for arrears paid in a different financial year?',
            'answer' => 'When arrears are paid after the financial year of accrual, the system marks the payment as pending accountability and records supporting accountability forms.',
            'bullets' => [],
            'category' => 'claims',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'Can payroll data be uploaded and matched to pension records?',
            'answer' => 'Uploaded payroll files are matched against registry data using supplier numbers and related identifiers so the platform shows payroll status and monthly linkage results.',
            'bullets' => [],
            'category' => 'claims',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'What is the pension file registry used for?',
            'answer' => 'The registry is the controlled record of approved pension files. It stores identity, benefits snapshot, compliance data, payroll state, file custody information, and linked documents.',
            'bullets' => [],
            'category' => 'registry',
            'audience' => 'Pensioners and staff',
            'featured' => 1
        ],
        [
            'question' => 'Can file movement be tracked across offices?',
            'answer' => 'The file tracking tools record movement in and out of custody, receiving or destination office, movement reasons, timestamps, and return actions for visibility and accountability.',
            'bullets' => [],
            'category' => 'registry',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'How is a pensioner account created?',
            'answer' => 'The system creates or synchronizes a pensioner account when a pension record is created or imported into the pension file registry, using registry-linked pensioner identity data.',
            'bullets' => [],
            'category' => 'pensioners',
            'audience' => 'Pensioners',
            'featured' => 0
        ],
        [
            'question' => 'How does the system protect sensitive pension data?',
            'answer' => 'The platform uses authenticated sessions, role-based access, audit trails, controlled exports, configurable security settings, activity logging, and data governance tools to reduce operational risk.',
            'bullets' => [],
            'category' => 'security',
            'audience' => 'Pensioners, staff, and supervisors',
            'featured' => 1
        ],
        [
            'question' => 'Why might access to some actions or pages be denied?',
            'answer' => 'Some tools are restricted to certain roles or permission overrides. When a page or action is denied, the current account lacks the required authorization for that operation.',
            'bullets' => [],
            'category' => 'security',
            'audience' => 'Pensioners, staff, and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'Which documents commonly support a retirement application?',
            'answer' => 'Supporting documents differ by case, but common evidence includes service-record verification, retirement authority, identity details, and payment details required by the pensions office.',
            'bullets' => [
                'Death-related cases include stronger next-of-kin or beneficiary evidence.',
                'Extra evidence is submitted through the governed workflow path.'
            ],
            'category' => 'applications',
            'audience' => 'Public, pensioners, and staff',
            'featured' => 0
        ],
        [
            'question' => 'What does verification mean before a task is forwarded?',
            'answer' => 'Verification confirms that the current handler has checked the record and that the required checkpoint data for the next workflow stage is present.',
            'bullets' => [
                'Verification is distinct from approval.',
                'The exact checkpoint changes by workflow stage, such as write-up, data capture, or assessment.'
            ],
            'category' => 'applications',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'What is the difference between reduced pension and full pension?',
            'answer' => 'Reduced pension reflects a lower service-based entitlement than the full benchmark amount. The exact result depends on service history, applicable rules, and retirement context.',
            'bullets' => [
                'Reduced pension does not automatically signal an error.',
                'Where the approved benefit differs, the approved administrative decision takes precedence.'
            ],
            'category' => 'benefits',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'What factors affect gratuity?',
            'answer' => 'Gratuity depends on the service and retirement data available to the pension authority, including the retirement context and the inputs used in the applicable pension formula.',
            'bullets' => [
                'Service length and validated records matter.',
                'Incomplete or inconsistent records distort an estimate until corrected.'
            ],
            'category' => 'benefits',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'Why might an estimate differ from the approved pension award?',
            'answer' => 'An estimate uses the information currently available, while an approved award relies on verified records, policy interpretation, and additional documentation reviewed later in the process.',
            'bullets' => [
                'Estimates support planning, not final entitlement determination.',
                'When a result looks unusual, the right step is review and correction, not assumption.'
            ],
            'category' => 'benefits',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'Why does the mode of retirement matter?',
            'answer' => 'Mode of retirement explains the type of case being handled and changes the supporting evidence, follow-up steps, and interpretation of the record.',
            'bullets' => [
                'Ordinary, mandatory, medical, and death-related cases need different handling.',
                'It affects which contact or next-of-kin information must be present.'
            ],
            'category' => 'benefits',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'Why can one file have several claims records for different months?',
            'answer' => 'A single pensioner has several arrears or payment events across multiple months. The system stores the monthly detail and also exports it by file with subtotals and a grand total.',
            'bullets' => [
                'Month-level rows preserve auditability.',
                'Grouped export improves readability for financial review.'
            ],
            'category' => 'claims',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'What does accountability mean for arrears?',
            'answer' => 'Accountability refers to the evidence or reconciliation needed when a payment must still be justified or traced within the required reporting framework, especially when timing crosses financial periods.',
            'bullets' => [
                'It is especially relevant when payment is made later than the accrual period.',
                'The system tracks supporting accountability forms and their status.'
            ],
            'category' => 'claims',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'What do unpaid, partial, or settled claim statuses mean?',
            'answer' => 'These statuses describe how much of the expected claim amount has been paid and whether any balance remains to be addressed.',
            'bullets' => [
                'Unpaid means the expected amount is still outstanding.',
                'Partial means some money has been paid but a balance remains.'
            ],
            'category' => 'claims',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'What does it mean when a file is marked returned?',
            'answer' => 'Marking a file returned means the file has been received back into pension file registry custody. The system records it as a movement from the last holder to registry, not just as a status flag on an older movement row.',
            'bullets' => [
                'The receiver is the user who performs the return action.',
                'This keeps movement history accurate up to final registry receipt.'
            ],
            'category' => 'registry',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'What is the recycle bin for deleted registry records?',
            'answer' => 'The recycle bin keeps a recoverable reference of deleted registry records so administrators review what was removed, restore a record where appropriate, or purge it according to retention rules.',
            'bullets' => [
                'It avoids losing context immediately after deletion.',
                'Restore and clear actions remain governed.'
            ],
            'category' => 'registry',
            'audience' => 'Supervisors and administrators',
            'featured' => 0
        ],
        [
            'question' => 'How are indexed documents viewed or downloaded?',
            'answer' => 'Common file types are previewed through protected in-app viewers, while non-previewable formats are downloaded through governed endpoints.',
            'bullets' => [
                'Protected preview avoids exposing raw storage paths directly.',
                'The same approach runs inside the installed PWA without leaving the app window.'
            ],
            'category' => 'registry',
            'audience' => 'Pensioners and staff',
            'featured' => 0
        ],
        [
            'question' => 'Why is a life certificate requested each year?',
            'answer' => 'A life certificate confirms that the pension record remains current and that payment-related compliance continues under the required administrative controls.',
            'bullets' => [
                'It is a yearly compliance expectation rather than a casual optional request.',
                'The urgency increases through the year if it remains outstanding.'
            ],
            'category' => 'pensioners',
            'audience' => 'Pensioners',
            'featured' => 0
        ],
        [
            'question' => 'Which personal details can a pensioner update directly?',
            'answer' => 'A pensioner updates limited contact-focused fields such as district of residence, phone number, email address, station or retirement location, and next-of-kin details through the governed profile update flow.',
            'bullets' => [
                'The update form is prefilled with the current record values.',
                'District and station fields use searchable governed lists.'
            ],
            'category' => 'pensioners',
            'audience' => 'Pensioners',
            'featured' => 0
        ],
        [
            'question' => 'How does the pensioner search directory protect privacy?',
            'answer' => 'The pensioner directory is governed by consent settings. A pensioner decides whether fellow pensioners see the contact details through the lookup tool.',
            'bullets' => [
                'The pensioner switches visibility on or off from profile settings.',
                'Only pensioners who agree to be visible appear in results.'
            ],
            'category' => 'pensioners',
            'audience' => 'Pensioners',
            'featured' => 0
        ],
        [
            'question' => 'What if a pensioner cannot log in?',
            'answer' => 'A pensioner is blocked from logging in if the portal is disabled administratively, if the account is not properly linked, or if credentials or session paths are invalid.',
            'bullets' => [
                'The login flow explains clearly when pensioner login is disabled.',
                'Linked registry and pensioner user records are checked where an account appears missing.'
            ],
            'category' => 'pensioners',
            'audience' => 'Pensioners',
            'featured' => 0
        ],
        [
            'question' => 'Why does the system sometimes require re-authentication?',
            'answer' => 'Re-authentication confirms that the current user still intends to access a high-sensitivity page or continue from an active session after navigating into a public route or reopening a tab.',
            'bullets' => [
                'It protects against accidental access from stale sessions.',
                'After successful login again, the user is returned to the relevant page.'
            ],
            'category' => 'security',
            'audience' => 'Pensioners, staff, and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'What does virus scanning do for uploaded files?',
            'answer' => 'Virus scanning checks uploaded files for suspicious or infected content before the files are accepted into the platform\'s document or message storage paths.',
            'bullets' => [
                'The app uses native ClamAV where available.',
                'A heuristic fallback still flags suspicious content when a native scanner is unavailable.'
            ],
            'category' => 'security',
            'audience' => 'Operational staff and supervisors',
            'featured' => 0
        ],
        [
            'question' => 'How does messaging protect recipient privacy?',
            'answer' => 'Recipient lists and read receipts stay visible only to the sender where privacy requires it, so co-recipients do not see each other unless the message design explicitly allows that.',
            'bullets' => [
                'Broadcast and group-message recipients do not automatically see co-recipients.',
                'Profile photos and attachments are served through controlled endpoints.'
            ],
            'category' => 'security',
            'audience' => 'Pensioners, staff, and supervisors',
            'featured' => 0
        ]
    ];

    $stmt = $conn->prepare("
        INSERT INTO tb_faq_entries
            (question, answer, bullets, category, audience_label, is_featured, sort_order, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $sortOrder = 1;
    foreach ($seedEntries as $entry) {
        $question = (string)$entry['question'];
        $answer = (string)$entry['answer'];
        $bullets = implode("\n", $entry['bullets'] ?? []);
        $category = (string)$entry['category'];
        $audience = (string)$entry['audience'];
        $featured = (int)($entry['featured'] ?? 0);
        $active = 1;
        $currentSort = $sortOrder++;
        $stmt->bind_param(
            "sssssiii",
            $question,
            $answer,
            $bullets,
            $category,
            $audience,
            $featured,
            $currentSort,
            $active
        );
        $stmt->execute();
    }

    $stmt->close();
}

function ensureTermsTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_terms_clauses (
            clause_id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            body text NOT NULL,
            topics varchar(120) NOT NULL DEFAULT 'operations',
            section_key varchar(50) NOT NULL DEFAULT 'operational',
            sort_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (clause_id),
            KEY idx_terms_section (section_key),
            KEY idx_terms_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;

    $countResult = $conn->query("SELECT COUNT(*) AS total FROM tb_terms_clauses");
    if (!$countResult) {
        return;
    }
    $countRow = $countResult->fetch_assoc();
    $total = (int)($countRow['total'] ?? 0);
    if ($total > 0) {
        return;
    }

    $seedClauses = [
        [
            'title' => 'Platform scope and purpose',
            'body' => "UPS PensionsGo is a controlled pension administration platform used for workflow management, pension file registry, claims, payroll visibility, pensioner self-service, and guided public information. The service exists to improve timeliness, control, accountability, and traceability in pension administration.\n\nThe platform may be accessed by public users, pensioners, staff, supervisors, and administrators, but each user is limited to the role, modules, and actions authorized for that account.",
            'topics' => 'operations'
        ],
        [
            'title' => 'Accounts, roles, and credentials',
            'body' => "Each user is responsible for protecting login credentials, device access, and session activity tied to the account. Users must not share passwords, bypass session controls, or attempt to impersonate another user.\n\nRole-based access applies throughout the platform. A user may see different menus, reports, records, or actions depending on assigned role, explicit permissions, and current account state. Pensioner accounts may be enabled or disabled according to operational settings.\n\nThe service may suspend, restrict, or terminate access where misuse, inactivity, policy breach, or governance action requires it.",
            'topics' => 'accounts security'
        ],
        [
            'title' => 'Workflow and operational use',
            'body' => "Staff users must use workflow actions only for legitimate operational purposes. That includes accurate task handling, appropriate delegation, timely completion, truthful status updates, and correct use of application, claims, registry, payroll, and file tracking modules.\n\nUsers must not create false records, suppress valid workflow activity, manipulate analytics, or circumvent approval paths. Where a tool allows edit, delete, restore, or bulk action, the user remains responsible for ensuring the action is lawful, necessary, and properly documented.",
            'topics' => 'operations accounts'
        ],
        [
            'title' => 'Data quality, records, and documents',
            'body' => "Users must ensure that data entered or imported into the platform is accurate, current, and relevant to the intended process. If a user identifies incomplete or incorrect pension information, the issue should be corrected through the appropriate workflow or support route rather than concealed.\n\nUploaded documents, payroll files, registry records, claims entries, life certificate records, and exported data remain sensitive operational information. They must be handled only for authorized work and should not be disclosed or redistributed without approval.\n\nImports, merges, deletes, recycle-bin restores, and cleanup operations may be logged and may require additional confirmation based on system settings and user role.",
            'topics' => 'data operations'
        ],
        [
            'title' => 'Security, privacy, and monitoring',
            'body' => "The platform may enforce security controls such as session monitoring, device binding, export governance, audit logging, action confirmations, and settings-based restrictions on copy, paste, or developer shortcuts. These controls support operational protection but do not remove the user's own duty of care.\n\nCritical actions may be recorded in audit logs for governance, investigations, and service review. By using the platform, users accept that authorized operational activity may be logged together with timestamps, identity, role, and action context.\n\nUsers must not probe for vulnerabilities, attempt unauthorized access, tamper with application state, or exploit configuration weaknesses. Such conduct may lead to suspension, internal disciplinary action, or other lawful response.",
            'topics' => 'security data'
        ],
        [
            'title' => 'Podcast, public guidance, and content use',
            'body' => "The podcast and public guidance modules are intended for education and operational clarity. Video content and public guidance do not override approved workflow, policy, or formal pension decisions. Users should treat content as guidance and use the relevant governed process where a formal action is required.\n\nPublic-facing content may be viewed without authentication where the system permits, but private or role-targeted content must not be redistributed outside authorized audiences.",
            'topics' => 'content'
        ],
        [
            'title' => 'Service availability and change management',
            'body' => "The platform may be updated, maintained, or temporarily limited to protect data quality, security, or operational continuity. Features, permissions, workflows, or settings may change as administrative requirements evolve.\n\nBackups, exports, restore tooling, and cleanup operations are provided for governance and recovery, but they must be used only by authorized personnel and only for valid operational reasons.",
            'topics' => 'operations security'
        ],
        [
            'title' => 'Questions, support, and updates',
            'body' => "If a user is unsure how these terms apply, the correct next step is to use the feedback page, read the FAQs, or contact the designated support or pensions office. Continued use of the platform after terms updates may be treated as acceptance of the revised conditions where that is operationally appropriate.\n\nThese terms should be read together with the platform's operational processes, access rules, and any official administrative instructions governing pension records and workflow conduct.",
            'topics' => 'accounts content'
        ]
    ];

    $stmt = $conn->prepare("
        INSERT INTO tb_terms_clauses
            (title, body, topics, section_key, sort_order, is_active)
        VALUES (?, ?, ?, 'operational', ?, 1)
    ");
    if (!$stmt) {
        return;
    }

    $sortOrder = 1;
    foreach ($seedClauses as $clause) {
        $title = (string)$clause['title'];
        $body = (string)$clause['body'];
        $topics = (string)$clause['topics'];
        $currentSort = $sortOrder++;
        $stmt->bind_param("sssi", $title, $body, $topics, $currentSort);
        $stmt->execute();
    }

    $stmt->close();
}

function getFeedbackStatusLabel(string $status): string {
    $normalized = strtolower(trim($status));
    return match ($normalized) {
        'new' => 'New',
        'reviewed' => 'In Review',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        default => $status === '' ? 'New' : ucwords(str_replace(['_', '-'], ' ', $status))
    };
}

function getFeedbackAudienceLabel(string $audience): string {
    $normalized = strtolower(trim($audience));
    return match ($normalized) {
        'public' => 'Public',
        'staff' => 'Staff',
        'pensioner' => 'Pensioner',
        default => $audience === '' ? 'Public' : ucwords(str_replace(['_', '-'], ' ', $audience))
    };
}

function feedbackSubmissionRequiresAttention(array $row, int $slaDays): bool {
    $status = strtolower(trim((string)($row['status'] ?? 'new')));
    if (!in_array($status, ['new', 'reviewed'], true)) {
        return false;
    }
    $submittedAt = strtotime((string)($row['submitted_at'] ?? ''));
    if (!$submittedAt || $slaDays <= 0) {
        return false;
    }
    return $submittedAt < strtotime('-' . $slaDays . ' days');
}

function getFeedbackManagementAssignableUsers(mysqli $conn): array {
    ensureRoleGovernanceTables($conn);
    $rows = [];
    $hasActiveColumn = false;
    if ($columnResult = $conn->query("SHOW COLUMNS FROM tb_users LIKE 'is_active'")) {
        $hasActiveColumn = $columnResult->num_rows > 0;
        $columnResult->close();
    }

    $sql = "
        SELECT
            userId AS user_id,
            COALESCE(NULLIF(TRIM(userName), ''), userId, 'User') AS user_name,
            COALESCE(userRole, 'user') AS role_key
        FROM tb_users
        WHERE LOWER(COALESCE(userRole, '')) <> 'pensioner'
    ";
    if ($hasActiveColumn) {
        $sql .= " AND COALESCE(is_active, 1) = 1";
    }
    $sql .= " ORDER BY userName ASC, userId ASC";

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $roleKey = normalizeRoleKey((string)($row['role_key'] ?? ''));
            $rows[] = [
                'user_id' => (string)($row['user_id'] ?? ''),
                'user_name' => (string)($row['user_name'] ?? ''),
                'role_key' => $roleKey,
                'role_label' => formatRoleLabel($conn, $roleKey)
            ];
        }
        $result->close();
    } else {
        recordSystemLog($conn, [
            'level' => 'warning',
            'category' => 'feedback',
            'message' => 'Unable to build feedback assignee list.',
            'context' => ['sql_error' => $conn->error]
        ]);
    }
    return $rows;
}

function recordFeedbackActivity(mysqli $conn, int $submissionId, array $activity): bool {
    ensureFeedbackWorkflowTables($conn);
    if ($submissionId <= 0) {
        return false;
    }

    $action = trim((string)($activity['action'] ?? 'feedback_updated')) ?: 'feedback_updated';
    $actorId = trim((string)($activity['actor_id'] ?? '')) ?: null;
    $actorName = trim((string)($activity['actor_name'] ?? '')) ?: null;
    $actorRole = trim((string)($activity['actor_role'] ?? '')) ?: null;
    $fromStatus = trim((string)($activity['from_status'] ?? '')) ?: null;
    $toStatus = trim((string)($activity['to_status'] ?? '')) ?: null;
    $note = trim((string)($activity['note'] ?? '')) ?: null;
    $fieldChanges = $activity['field_changes'] ?? null;
    if (is_array($fieldChanges)) {
        $fieldChanges = json_encode($fieldChanges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($fieldChanges !== null) {
        $fieldChanges = (string)$fieldChanges;
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_feedback_activity
            (submission_id, action, actor_id, actor_name, actor_role, from_status, to_status, note, field_changes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "issssssss",
        $submissionId,
        $action,
        $actorId,
        $actorName,
        $actorRole,
        $fromStatus,
        $toStatus,
        $note,
        $fieldChanges
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function generateFeedbackReference(): string {
    return 'FDB-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

// 
// Phone Number Helpers //
function sanitizePhoneNumberInput(string $phone): string {
    $phone = trim($phone);
    // Remove common separators but keep leading plus if present.
    $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);
    // Remove any plus signs that are not at the first character.
    $phone = preg_replace('/(?!^)\+/', '', $phone);
    return $phone;
}

function normalizePhoneNumber(string $phone): ?string {
    $phone = sanitizePhoneNumberInput($phone);
    if ($phone === '') {
        return null;
    }

    // International with 00 prefix: 0044... -> +44...
    if (preg_match('/^00([1-9]\d{7,14})$/', $phone, $matches)) {
        return '+' . $matches[1];
    }

    // Standard E.164 format: +256...
    if (preg_match('/^\+([1-9]\d{7,14})$/', $phone, $matches)) {
        return '+' . $matches[1];
    }

    // Uganda local format: 07..., 03..., 0800..., etc (10 digits starting with 0).
    if (preg_match('/^0\d{9}$/', $phone)) {
        return '+256' . substr($phone, 1);
    }

    // International number provided without a leading plus.
    if (preg_match('/^[1-9]\d{7,14}$/', $phone)) {
        return '+' . $phone;
    }

    return null;
}

function isSupportedPhoneNumber(string $phone): bool {
    return normalizePhoneNumber($phone) !== null;
}

function buildPhoneLookupCandidates(string $phone): array {
    $raw = sanitizePhoneNumberInput($phone);
    $normalized = normalizePhoneNumber($phone);
    $candidates = [];

    if ($raw !== '') {
        $candidates[] = $raw;
    }

    if ($normalized !== null) {
        $candidates[] = $normalized;
        $digits = substr($normalized, 1); // remove leading +
        if ($digits !== '') {
            $candidates[] = $digits;
            $candidates[] = '00' . $digits;
        }

        // Include Uganda local candidate for legacy records.
        if (str_starts_with($normalized, '+256')) {
            $localUg = '0' . substr($normalized, 4);
            if (preg_match('/^0\d{9}$/', $localUg)) {
                $candidates[] = $localUg;
            }
        }
    }

    return array_values(array_unique(array_filter($candidates, static function ($item) {
        return is_string($item) && $item !== '';
    })));
}

function normalizePoliticalDistrictName(string $district): string {
    return preg_replace('/\s+/', ' ', trim($district));
}

function ensurePoliticalDistrictsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_poldistricts (
            Id int(11) NOT NULL AUTO_INCREMENT,
            polDistrict text NOT NULL,
            polRegion text NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (Id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;

    $result = $conn->query("SELECT COUNT(*) AS total FROM tb_poldistricts");
    if (!$result) {
        return;
    }
    $row = $result->fetch_assoc();
    $total = (int)($row['total'] ?? 0);
    if ($total > 0) {
        return;
    }

    if (tableExists($conn, 'tb_priunits')) {
        $conn->query("
            INSERT INTO tb_poldistricts (polDistrict, polRegion)
            SELECT DISTINCT
                TRIM(COALESCE(polDistrict, '')) AS polDistrict,
                TRIM(COALESCE(polRegion, '')) AS polRegion
            FROM tb_priunits
            WHERE TRIM(COALESCE(polDistrict, '')) <> ''
        ");
    }
}

function ensurePrisonDistrictsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_pridistricts (
            Id int(11) NOT NULL AUTO_INCREMENT,
            priDistrict text NOT NULL,
            priRegion text NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (Id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;

    $result = $conn->query("SELECT COUNT(*) AS total FROM tb_pridistricts");
    if (!$result) {
        return;
    }
    $row = $result->fetch_assoc();
    $total = (int)($row['total'] ?? 0);
    if ($total > 0) {
        return;
    }

    if (tableExists($conn, 'tb_priunits')) {
        $conn->query("
            INSERT INTO tb_pridistricts (priDistrict, priRegion)
            SELECT DISTINCT
                TRIM(COALESCE(priDistrict, '')) AS priDistrict,
                TRIM(COALESCE(priRegion, '')) AS priRegion
            FROM tb_priunits
            WHERE TRIM(COALESCE(priDistrict, '')) <> ''
        ");
    }
}

function ensurePrisonRegionsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_priregions (
            Id int(11) NOT NULL AUTO_INCREMENT,
            priRegion text NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (Id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;

    $result = $conn->query("SELECT COUNT(*) AS total FROM tb_priregions");
    if (!$result) {
        return;
    }
    $row = $result->fetch_assoc();
    $total = (int)($row['total'] ?? 0);
    if ($total > 0) {
        return;
    }

    if (tableExists($conn, 'tb_priunits')) {
        $conn->query("
            INSERT INTO tb_priregions (priRegion)
            SELECT DISTINCT TRIM(COALESCE(priRegion, '')) AS priRegion
            FROM tb_priunits
            WHERE TRIM(COALESCE(priRegion, '')) <> ''
        ");
    }
}

function ensurePensionerLookupColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'lookup_contact_opt_in' => "ALTER TABLE tb_fileregistry ADD COLUMN lookup_contact_opt_in TINYINT(1) NOT NULL DEFAULT 1 AFTER bank_branch",
        'lookup_contact_updated_at' => "ALTER TABLE tb_fileregistry ADD COLUMN lookup_contact_updated_at DATETIME NULL DEFAULT NULL AFTER lookup_contact_opt_in"
    ];

    foreach ($columns as $column => $sql) {
        $result = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE '{$conn->real_escape_string($column)}'");
        if ($result && $result->num_rows === 0) {
            $conn->query($sql);
        }
    }

    @ $conn->query("ALTER TABLE tb_fileregistry MODIFY lookup_contact_opt_in TINYINT(1) NOT NULL DEFAULT 1");
    @ $conn->query("UPDATE tb_fileregistry SET lookup_contact_opt_in = 1 WHERE lookup_contact_updated_at IS NULL");

    $checked = true;
}

function resolvePensionerOwnedRegistry(mysqli $conn, string $userId): ?array
{
    $userId = trim($userId);
    if ($userId === '') {
        return null;
    }

    ensurePensionerLookupColumns($conn);

    $userStmt = $conn->prepare("
        SELECT userId, userEmail, phoneNo, other
        FROM tb_users
        WHERE userId = ?
        LIMIT 1
    ");
    if (!$userStmt) {
        return null;
    }

    $userStmt->bind_param('s', $userId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc() ?: null;
    $userStmt->close();

    if (!$user) {
        return null;
    }

    $userMeta = [];
    $rawMeta = (string)($user['other'] ?? '');
    if ($rawMeta !== '') {
        $decoded = json_decode($rawMeta, true);
        if (is_array($decoded)) {
            $userMeta = $decoded;
        }
    }

    $metaRegNo = trim((string)($userMeta['regNo'] ?? ''));
    $metaStaffId = (int)($userMeta['staffdue_id'] ?? 0);
    if ($metaRegNo !== '') {
        return [
            'regNo' => $metaRegNo,
            'staffdue_id' => $metaStaffId,
            'user' => $user
        ];
    }

    $email = strtolower(trim((string)($user['userEmail'] ?? '')));
    $phone = trim((string)($user['phoneNo'] ?? ''));
    $phoneCandidates = $phone !== '' ? buildPhoneLookupCandidates($phone) : [];
    $conditions = [];
    $types = '';
    $params = [];

    if ($email !== '') {
        $conditions[] = "LOWER(COALESCE(fr.applicant_email, sd.applicant_email, '')) = ?";
        $types .= 's';
        $params[] = $email;
    }

    foreach ($phoneCandidates as $candidate) {
        $conditions[] = "COALESCE(fr.telNo, sd.telNo, '') = ?";
        $types .= 's';
        $params[] = $candidate;
    }

    if (empty($conditions)) {
        return null;
    }

    $registrySql = "
        SELECT fr.regNo, COALESCE(sd.id, 0) AS staffdue_id
        FROM tb_fileregistry fr
        LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
        WHERE " . implode(' OR ', $conditions) . "
        ORDER BY fr.id DESC
        LIMIT 1
    ";
    $registryStmt = $conn->prepare($registrySql);
    if (!$registryStmt) {
        return null;
    }

    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$registryStmt, 'bind_param'], $bind);
    $registryStmt->execute();
    $row = $registryStmt->get_result()->fetch_assoc() ?: null;
    $registryStmt->close();

    if (!$row || trim((string)($row['regNo'] ?? '')) === '') {
        return null;
    }

    return [
        'regNo' => trim((string)$row['regNo']),
        'staffdue_id' => (int)($row['staffdue_id'] ?? 0),
        'user' => $user
    ];
}

function getPoliticalDistricts(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    ensurePoliticalDistrictsTable($conn);

    $cache = [];
    $result = $conn->query("
        SELECT DISTINCT
            TRIM(COALESCE(polDistrict, '')) AS district,
            TRIM(COALESCE(polRegion, '')) AS region
        FROM tb_poldistricts
        WHERE TRIM(COALESCE(polDistrict, '')) <> ''
        ORDER BY TRIM(COALESCE(polDistrict, '')) ASC
    ");
    if (!$result) {
        return $cache;
    }

    while ($row = $result->fetch_assoc()) {
        $district = normalizePoliticalDistrictName((string)($row['district'] ?? ''));
        if ($district === '') {
            continue;
        }
        $cache[] = [
            'district' => $district,
            'region' => normalizePoliticalDistrictName((string)($row['region'] ?? ''))
        ];
    }

    return $cache;
}

function resolvePoliticalDistrictName(mysqli $conn, string $district): ?string {
    $needle = normalizePoliticalDistrictName($district);
    if ($needle === '') {
        return null;
    }

    $needleLower = strtolower($needle);
    foreach (getPoliticalDistricts($conn) as $row) {
        $candidate = normalizePoliticalDistrictName((string)($row['district'] ?? ''));
        if ($candidate !== '' && strtolower($candidate) === $needleLower) {
            return $candidate;
        }
    }

    return null;
}

function isValidPoliticalDistrict(mysqli $conn, string $district): bool {
    return resolvePoliticalDistrictName($conn, $district) !== null;
}

function getPrisonUnits(mysqli $conn): array {
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    if (!tableExists($conn, 'tb_priunits')) {
        return $cache;
    }

    $result = $conn->query("
        SELECT DISTINCT TRIM(COALESCE(priUnit, '')) AS pri_unit
        FROM tb_priunits
        WHERE TRIM(COALESCE(priUnit, '')) <> ''
        ORDER BY TRIM(COALESCE(priUnit, '')) ASC
    ");
    if (!$result) {
        return $cache;
    }

    while ($row = $result->fetch_assoc()) {
        $unit = trim((string)($row['pri_unit'] ?? ''));
        if ($unit !== '') {
            $cache[] = $unit;
        }
    }

    return $cache;
}

function resolvePrisonUnitName(mysqli $conn, string $unit): ?string {
    $needle = trim((string)$unit);
    if ($needle === '') {
        return null;
    }

    $needleLower = strtolower($needle);
    foreach (getPrisonUnits($conn) as $candidate) {
        if (strtolower($candidate) === $needleLower) {
            return $candidate;
        }
    }

    return null;
}

function canRoleAccessMessagingModule(string $roleKey): bool {
    $normalized = normalizeRoleKey($roleKey);
    return $normalized !== '' && !in_array($normalized, ['user', 'pensioner'], true);
}

function currentUserCanAccessMessagingModule(): bool {
    return isset($_SESSION['userRole']) && canRoleAccessMessagingModule((string)($_SESSION['userRole'] ?? ''));
}

function ensureUserPasswordUpdatedAtColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $result = $conn->query("SHOW COLUMNS FROM tb_users LIKE 'password_updated_at'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE tb_users ADD COLUMN password_updated_at timestamp NULL DEFAULT NULL");
    }

    $checked = true;
}

function ensureUserActiveColumn(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $result = $conn->query("SHOW COLUMNS FROM tb_users LIKE 'is_active'");
    if ($result && $result->num_rows === 0) {
        $conn->query("ALTER TABLE tb_users ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 1");
    }

    $checked = true;
}

// 
// Message Compression Helpers //
function encodeMessageText(string $text, bool $compressEnabled): string {
    if (!$compressEnabled) {
        return $text;
    }

    $compressed = gzencode($text, 6);
    if ($compressed === false) {
        return $text;
    }

    return '__gz__:' . base64_encode($compressed);
}

function decodeMessageText(?string $text): string {
    if (!is_string($text) || $text === '') {
        return '';
    }

    if (str_starts_with($text, '__gz__:')) {
        $payload = substr($text, 7);
        $decoded = base64_decode($payload, true);
        if ($decoded !== false) {
            $inflated = @gzdecode($decoded);
            if ($inflated !== false) {
                return $inflated;
            }
        }
    }

    return $text;
}

// 
// NOTIFICATION + AUDIT HELPERS
// 
function ensureNotificationQueueTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_notification_queue (
            notification_id int(11) NOT NULL AUTO_INCREMENT,
            channel enum('email','sms','push') NOT NULL,
            recipient varchar(255) NOT NULL,
            subject varchar(255) DEFAULT NULL,
            message text NOT NULL,
            status enum('queued','sent','failed') NOT NULL DEFAULT 'queued',
            meta text DEFAULT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            processing_started_at datetime DEFAULT NULL,
            last_attempted_at datetime DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            failed_at datetime DEFAULT NULL,
            last_error text DEFAULT NULL,
            provider_reference varchar(255) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (notification_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        "attempts int(11) NOT NULL DEFAULT 0",
        "processing_started_at datetime DEFAULT NULL",
        "last_attempted_at datetime DEFAULT NULL",
        "sent_at datetime DEFAULT NULL",
        "failed_at datetime DEFAULT NULL",
        "last_error text DEFAULT NULL",
        "provider_reference varchar(255) DEFAULT NULL",
    ];
    foreach ($columns as $name => $definition) {
        if (is_int($name)) {
            $parts = explode(' ', $definition, 2);
            $columnName = $parts[0];
        } else {
            $columnName = $name;
        }
        @ $conn->query("ALTER TABLE tb_notification_queue ADD COLUMN {$definition}");
    }

    $created = true;
}

function queueNotification(mysqli $conn, string $channel, string $recipient, string $subject, string $message, array $meta = []): bool {
    ensureNotificationQueueTable($conn);

    $payload = !empty($meta) ? json_encode($meta, JSON_UNESCAPED_SLASHES) : null;
    $stmt = $conn->prepare("
        INSERT INTO tb_notification_queue (channel, recipient, subject, message, meta)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("sssss", $channel, $recipient, $subject, $message, $payload);
    $ok = $stmt->execute();
    $notificationId = $ok ? (int)$stmt->insert_id : 0;
    $stmt->close();

    if ($ok && $channel === 'email') {
        maybeProcessNotificationQueue($conn, [
            'reason' => 'enqueue',
            'notification_id' => $notificationId
        ]);
    }

    return $ok;
}

function getNotificationQueueWorkerConfig(mysqli $conn): array {
    $workerEnabledOverride = NOTIFY_QUEUE_WORKER_ENABLED_OVERRIDE;
    $processOnRequestOverride = NOTIFY_QUEUE_PROCESS_ON_REQUEST_OVERRIDE;
    return [
        'enabled' => $workerEnabledOverride === ''
            ? getAppSettingBool($conn, 'notify_queue_worker_enabled', true)
            : in_array(strtolower((string)$workerEnabledOverride), ['1', 'true', 'yes', 'on'], true),
        'process_on_request' => $processOnRequestOverride === ''
            ? getAppSettingBool($conn, 'notify_queue_process_on_request', false)
            : in_array(strtolower((string)$processOnRequestOverride), ['1', 'true', 'yes', 'on'], true),
        'batch_size' => max(1, min(100, getAppSettingInt($conn, 'notify_queue_batch_size', 10))),
        'retry_limit' => max(1, min(20, getAppSettingInt($conn, 'notify_queue_retry_limit', 3))),
        'retry_delay_minutes' => max(1, min(1440, getAppSettingInt($conn, 'notify_queue_retry_delay_minutes', 10))),
        'min_interval_seconds' => max(5, min(3600, getAppSettingInt($conn, 'notify_queue_min_interval_seconds', 60))),
    ];
}

function markNotificationDigestRunStatus(mysqli $conn, array $meta, string $status, ?string $error = null): void {
    $digestId = (int)($meta['digest_id'] ?? 0);
    if ($digestId <= 0 || !tableExists($conn, 'tb_notification_digest_runs')) {
        return;
    }
    $status = in_array($status, ['queued', 'previewed', 'sent', 'failed'], true) ? $status : 'queued';
    $notes = null;
    if ($error !== null && $error !== '') {
        $notes = 'Delivery worker update: ' . $error;
    }

    $stmt = $conn->prepare("
        UPDATE tb_notification_digest_runs
        SET status = ?, notes = CASE WHEN ? IS NULL OR ? = '' THEN notes ELSE ? END
        WHERE digest_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ssssi', $status, $notes, $notes, $notes, $digestId);
        $stmt->execute();
        $stmt->close();
    }
}

function processNotificationQueue(mysqli $conn, array $options = []): array {
    ensureNotificationQueueTable($conn);

    $config = getNotificationQueueWorkerConfig($conn);
    if (!$config['enabled']) {
        return [
            'success' => false,
            'message' => 'Notification queue worker is disabled.',
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0
        ];
    }

    $limit = isset($options['batch_size']) ? max(1, min(100, (int)$options['batch_size'])) : $config['batch_size'];
    $retryLimit = isset($options['retry_limit']) ? max(1, (int)$options['retry_limit']) : $config['retry_limit'];
    $retryDelayMinutes = isset($options['retry_delay_minutes']) ? max(1, (int)$options['retry_delay_minutes']) : $config['retry_delay_minutes'];
    $reason = (string)($options['reason'] ?? 'manual');
    $force = !empty($options['force']);
    $specificNotificationId = (int)($options['notification_id'] ?? 0);

    if (!$force) {
        $lastRunRaw = getAppSetting($conn, 'notify_queue_last_run_at');
        if ($lastRunRaw) {
            $lastRunTs = strtotime((string)$lastRunRaw);
            if ($lastRunTs && (time() - $lastRunTs) < $config['min_interval_seconds']) {
                return [
                    'success' => true,
                    'message' => 'Notification queue worker skipped because the minimum run interval has not elapsed.',
                    'processed' => 0,
                    'sent' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ];
            }
        }
    }

    $where = [
        "channel = 'email'",
        "(status = 'queued' OR (status = 'failed' AND attempts < ? AND (last_attempted_at IS NULL OR last_attempted_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))))",
        "(processing_started_at IS NULL OR processing_started_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE))"
    ];
    $types = 'ii';
    $params = [$retryLimit, $retryDelayMinutes];
    if ($specificNotificationId > 0) {
        $where[] = 'notification_id = ?';
        $types .= 'i';
        $params[] = $specificNotificationId;
    }

    $sql = "
        SELECT notification_id, recipient, subject, message, meta, attempts
        FROM tb_notification_queue
        WHERE " . implode(' AND ', $where) . "
        ORDER BY created_at ASC
        LIMIT ?
    ";
    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [
            'success' => false,
            'message' => 'Unable to prepare notification queue query.',
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0
        ];
    }
    bindDynamicParams($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();

    $processed = 0;
    $sent = 0;
    $failed = 0;
    $skipped = 0;

    while ($row = $result->fetch_assoc()) {
        $notificationId = (int)$row['notification_id'];
        $claimStmt = $conn->prepare("
            UPDATE tb_notification_queue
            SET processing_started_at = NOW()
            WHERE notification_id = ?
              AND (processing_started_at IS NULL OR processing_started_at <= DATE_SUB(NOW(), INTERVAL 15 MINUTE))
            LIMIT 1
        ");
        if (!$claimStmt) {
            $skipped++;
            continue;
        }
        $claimStmt->bind_param('i', $notificationId);
        $claimStmt->execute();
        $claimed = $claimStmt->affected_rows > 0;
        $claimStmt->close();
        if (!$claimed) {
            $skipped++;
            continue;
        }

        $processed++;
        $meta = [];
        if (!empty($row['meta'])) {
            $decoded = json_decode((string)$row['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $htmlBody = trim((string)($meta['html_body'] ?? ''));
        $textBody = (string)$row['message'];
        $fromName = trim((string)($meta['from_name'] ?? ''));
        $fromEmail = trim((string)($meta['from_email'] ?? ''));

        $deliveryOk = sendEmail(
            (string)$row['recipient'],
            (string)($row['subject'] ?? ''),
            $htmlBody,
            $textBody,
            $fromName !== '' ? $fromName : null,
            $fromEmail !== '' ? $fromEmail : null
        );

        $lastError = null;
        if (!$deliveryOk) {
            $lastError = 'Mail transport failed to accept the message.';
        }

        $status = $deliveryOk ? 'sent' : (($row['attempts'] + 1) >= $retryLimit ? 'failed' : 'failed');
        $updateStmt = $conn->prepare("
            UPDATE tb_notification_queue
            SET status = ?,
                attempts = attempts + 1,
                last_attempted_at = NOW(),
                processing_started_at = NULL,
                sent_at = CASE WHEN ? = 'sent' THEN NOW() ELSE sent_at END,
                failed_at = CASE WHEN ? = 'failed' THEN NOW() ELSE failed_at END,
                last_error = ?,
                provider_reference = NULL
            WHERE notification_id = ?
            LIMIT 1
        ");
        if ($updateStmt) {
            $updateStmt->bind_param('ssssi', $status, $status, $status, $lastError, $notificationId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        if (($meta['source'] ?? '') === 'daily_digest') {
            markNotificationDigestRunStatus($conn, $meta, $status, $lastError);
        }
        if (($meta['source'] ?? '') === 'analytics_digest' && function_exists('markAnalyticsDigestRunStatus')) {
            markAnalyticsDigestRunStatus($conn, $meta, $status, $lastError);
        }

        if ($deliveryOk) {
            $sent++;
        } else {
            $failed++;
        }
    }

    $stmt->close();
    setAppSetting($conn, 'notify_queue_last_run_at', date('Y-m-d H:i:s'));

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => $failed > 0 ? 'warning' : 'info',
            'log_category' => 'notification_queue',
            'event_code' => 'notification_queue_processed',
            'message' => 'Notification queue worker processed outbound notifications.',
            'context' => [
                'reason' => $reason,
                'processed' => $processed,
                'sent' => $sent,
                'failed' => $failed,
                'skipped' => $skipped
            ],
            'actor_id' => $options['actor_id'] ?? ($_SESSION['userId'] ?? 'system'),
            'actor_name' => $options['actor_name'] ?? ($_SESSION['userName'] ?? 'System'),
            'actor_role' => $options['actor_role'] ?? ($_SESSION['userRole'] ?? 'system')
        ]);
    }

    return [
        'success' => true,
        'message' => sprintf('Processed %d queued email(s): %d sent, %d failed, %d skipped.', $processed, $sent, $failed, $skipped),
        'processed' => $processed,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped
    ];
}

function maybeProcessNotificationQueue(mysqli $conn, array $options = []): ?array {
    $config = getNotificationQueueWorkerConfig($conn);
    if (!$config['enabled'] || !$config['process_on_request']) {
        return null;
    }
    if (!empty($options['notification_id']) && !array_key_exists('force', $options)) {
        $options['force'] = true;
    }
    return processNotificationQueue($conn, $options);
}

function bindDynamicParams(mysqli_stmt $stmt, string $types, array $params): void {
    if ($types === '' || empty($params)) {
        return;
    }
    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function ensureAuditLogsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_audit_logs (
            audit_id int(11) NOT NULL AUTO_INCREMENT,
            actor_id varchar(100) NOT NULL,
            actor_name varchar(100) DEFAULT NULL,
            actor_role varchar(50) DEFAULT NULL,
            action varchar(100) NOT NULL,
            entity_type varchar(100) DEFAULT NULL,
            entity_id varchar(100) DEFAULT NULL,
            details text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (audit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function formatAuditKeyLabel(string $key): string {
    $map = [
        'regNo' => 'File number',
        'reg_no' => 'File number',
        'staff_name' => 'Staff name',
        'reference_no' => 'Reference number',
        'feedback_type' => 'Feedback type',
        'audience' => 'Audience',
        'page_context' => 'Page context',
        'target_user' => 'Target user',
        'target_user_name' => 'Target user',
        'user_email' => 'Email address',
        'user_role' => 'User role',
        'role_label' => 'Role',
        'updated_permissions' => 'Updated permissions',
        'status' => 'Status',
        'priority' => 'Priority',
        'assignment' => 'Assignment',
        'resolution_summary' => 'Resolution summary',
        'visible' => 'Visibility',
        'request_id' => 'Request ID',
        'recycle_id' => 'Recycle ID',
        'purged_count' => 'Purged records',
        'older_than_days' => 'Older than (days)',
        'state' => 'State',
        'actor_role_filter' => 'Actor role filter',
        'updated_records' => 'Updated records',
        'box_count' => 'Box count',
        'message_type' => 'Message type',
        'recipient_count' => 'Recipient count',
        'message_id' => 'Message ID',
        'task_type' => 'Task type',
        'related_reg_no' => 'File number',
        'required_assignment_role' => 'Required role',
        'next_assigned_to' => 'Next assignee',
        'next_priority' => 'Next priority',
        'task_id' => 'Task ID',
        'from_office' => 'From office',
        'to_office' => 'To office',
        'backup_scope' => 'Backup scope',
        'include_uploads' => 'Include uploads',
        'restore_files' => 'Restore files',
        'is_active' => 'Active',
        'custom_password' => 'Custom password',
        'payroll_year' => 'Payroll year',
        'payroll_month' => 'Payroll month',
        'from_year' => 'From year',
        'from_month' => 'From month',
        'to_year' => 'To year',
        'to_month' => 'To month',
        'replacement_cycle_id' => 'Replacement cycle ID',
        'replacement_rows' => 'Replacement rows',
        'deleted_cycle_id' => 'Deleted cycle ID',
        'year' => 'Year',
        'month' => 'Month',
        'financial_year' => 'Financial year',
        'quarter' => 'Quarter',
        'source_file' => 'Source file',
        'payment_register_file' => 'Payment register file',
        'source_file_name' => 'Source file name',
        'payment_register_file_name' => 'Payment register file name',
        'rows_uploaded' => 'Rows uploaded',
        'matched_rows' => 'Matched rows',
        'unmatched_rows' => 'Unmatched rows',
        'processed' => 'Processed',
        'failed' => 'Failed',
        'error' => 'Error',
        'file_name' => 'File name',
        'file_path' => 'File path',
        'file_size_bytes' => 'File size',
        'backup_scope' => 'Backup scope',
        'include_uploads' => 'Include uploads',
        'row_count' => 'Row count',
        'format' => 'Format',
        'mode' => 'Mode',
        'status' => 'Status',
        'reason' => 'Reason',
        'search' => 'Search',
        'year' => 'Year',
        'scope' => 'Scope',
        'channel' => 'Channel',
        'affected_records' => 'Affected records',
        'task_id' => 'Task ID',
        'next_assigned_to' => 'Next assignee',
        'next_priority' => 'Next priority',
        'doc_type' => 'Document type'
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    return ucwords(str_replace('_', ' ', $key));
}

function formatAuditBytes($bytes): string {
    if (!is_numeric($bytes)) {
        return (string)$bytes;
    }
    $bytes = (float)$bytes;
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $idx = 0;
    while ($bytes >= 1024 && $idx < count($units) - 1) {
        $bytes /= 1024;
        $idx++;
    }
    return number_format($bytes, $idx === 0 ? 0 : 2) . ' ' . $units[$idx];
}

function formatAuditValue($value, string $key = ''): string {
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    if ($value === null) {
        return '(empty)';
    }
    if (is_numeric($value)) {
        if (stripos($key, 'size') !== false) {
            return formatAuditBytes($value);
        }
        if (stripos($key, 'count') !== false || stripos($key, 'rows') !== false || stripos($key, 'total') !== false) {
            return number_format((float)$value, 0, '.', ',');
        }
        return (string)$value;
    }
    if (is_array($value)) {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if (!$isAssoc) {
            $flattened = array_map(fn($item) => formatAuditValue($item, $key), $value);
            return implode(', ', array_filter($flattened, fn($item) => $item !== ''));
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $value = trim((string)$value);
    return $value === '' ? '(empty)' : $value;
}

function formatAuditDetails(array $details, string $action = '', ?string $entityType = null, ?string $entityId = null): string {
    $remaining = $details;
    $parts = [];

    $take = function(string $key) use (&$remaining) {
        if (!array_key_exists($key, $remaining)) {
            return null;
        }
        $value = $remaining[$key];
        unset($remaining[$key]);
        return $value;
    };

    $changes = $take('changes');
    if (is_array($changes)) {
        $isAssoc = array_keys($changes) !== range(0, count($changes) - 1);
        if ($isAssoc) {
            foreach ($changes as $field => $changeData) {
                if (is_array($changeData)) {
                    $from = $changeData['from'] ?? null;
                    $to = $changeData['to'] ?? null;
                    $toName = $changeData['to_name'] ?? $changeData['toName'] ?? null;
                    $fromName = $changeData['from_name'] ?? $changeData['fromName'] ?? null;
                    $label = formatAuditKeyLabel((string)$field);
                    if ($to === '' || $to === null) {
                        $fromLabel = $fromName !== null ? formatAuditValue($fromName, (string)$field) : formatAuditValue($from, (string)$field);
                        $parts[] = "Cleared {$label}" . ($fromLabel !== '(empty)' ? " (was '{$fromLabel}')" : '');
                        continue;
                    }
                    $fromLabel = $fromName !== null ? formatAuditValue($fromName, (string)$field) : formatAuditValue($from, (string)$field);
                    $toLabel = $toName !== null ? formatAuditValue($toName, (string)$field) : formatAuditValue($to, (string)$field);
                    if ($fromLabel === '(empty)') {
                        $parts[] = "Set {$label} to '{$toLabel}'";
                    } else {
                        $parts[] = "Changed {$label} from '{$fromLabel}' to '{$toLabel}'";
                    }
                } else {
                    $value = trim((string)$changeData);
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }
            }
        } else {
            foreach ($changes as $change) {
                $change = trim((string)$change);
                if ($change !== '') {
                    $parts[] = $change;
                }
            }
        }
    } elseif (is_string($changes) && trim($changes) !== '') {
        $parts[] = trim($changes);
    }

    $note = $take('note');
    if (is_string($note) && trim($note) !== '') {
        $parts[] = 'Note: ' . trim($note);
    }

    $updatedKeys = $take('updated_keys');
    if (is_array($updatedKeys) && !empty($updatedKeys)) {
        $readableKeys = array_map(fn($key) => formatAuditKeyLabel((string)$key), $updatedKeys);
        $parts[] = 'Updated settings: ' . implode(', ', $readableKeys);
    }

    $updatedFields = $take('updated_fields');
    if (is_array($updatedFields) && !empty($updatedFields)) {
        $fieldLabels = [];
        foreach ($updatedFields as $field) {
            $field = (string)$field;
            $field = trim(preg_replace('/\s*=.*$/', '', $field));
            $fieldLabels[] = formatAuditKeyLabel($field);
        }
        $parts[] = 'Updated fields: ' . implode(', ', $fieldLabels);
    }

    $message = $take('message');
    if (is_string($message) && trim($message) !== '') {
        $parts[] = trim($message);
    }

    if (stripos($action, 'export') !== false) {
        $format = $take('format');
        $rowCount = $take('row_count');
        $title = $take('title');
        $fileName = $take('file_name');
        $label = $title ?: ($entityId ?: ($entityType ?: 'dataset'));
        $exportLine = 'Exported ' . $label;
        if ($format) {
            $exportLine .= ' as ' . formatAuditValue($format, 'format');
        }
        if ($rowCount !== null) {
            $exportLine .= ' (' . formatAuditValue($rowCount, 'row_count') . ' rows)';
        }
        if ($fileName) {
            $exportLine .= ' to ' . formatAuditValue($fileName, 'file_name');
        }
        $parts[] = $exportLine;
    }

    if (stripos($action, 'import') !== false) {
        $dataset = $take('dataset');
        $mode = $take('mode');
        $file = $take('file');
        $summary = $take('summary');
        $importLine = 'Import ' . ($action === 'data_import_preview' ? 'preview' : 'completed');
        if ($dataset) {
            $importLine .= ' for ' . formatAuditValue($dataset, 'dataset');
        }
        if ($mode) {
            $importLine .= ' via ' . formatAuditValue($mode, 'mode');
        }
        if ($file) {
            $importLine .= ' from ' . formatAuditValue($file, 'file');
        }
        if ($summary) {
            $importLine .= ' | Summary: ' . formatAuditValue($summary, 'summary');
        }
        $parts[] = $importLine;
    }

    $actionLower = strtolower((string)$action);
    $summaryLine = '';
    if (stripos($actionLower, 'export') === false && stripos($actionLower, 'import') === false) {
        switch ($actionLower) {
            case 'notification_queue_cleared':
                $affected = $take('affected_records');
                $scope = $take('scope');
                $status = $take('status');
                $channel = $take('channel');
                $search = $take('search');
                $meta = [];
                if ($scope) { $meta[] = 'scope: ' . formatAuditValue($scope, 'scope'); }
                if ($status) { $meta[] = 'status: ' . formatAuditValue($status, 'status'); }
                if ($channel) { $meta[] = 'channel: ' . formatAuditValue($channel, 'channel'); }
                if ($affected !== null) { $meta[] = formatAuditValue($affected, 'affected_records') . ' records'; }
                $summaryLine = 'Cleared notification queue';
                if (!empty($meta)) {
                    $summaryLine .= ' (' . implode(', ', $meta) . ')';
                }
                if ($search) {
                    $parts[] = 'Search: ' . formatAuditValue($search, 'search');
                }
                break;
            case 'registry_delete_requested':
                $regNo = $take('regNo');
                $requestId = $take('request_id');
                $summaryLine = 'Requested deletion of registry file' . ($regNo ? ' ' . formatAuditValue($regNo, 'regNo') : '');
                if ($requestId) {
                    $summaryLine .= ' (Request #' . formatAuditValue($requestId, 'request_id') . ')';
                }
                $reason = $take('reason');
                if ($reason) {
                    $parts[] = 'Reason: ' . formatAuditValue($reason, 'reason');
                }
                break;
            case 'registry_delete_approved':
            case 'registry_delete_rejected':
                $regNo = $take('regNo');
                $requestId = $take('request_id');
                $summaryLine = ($actionLower === 'registry_delete_approved' ? 'Approved' : 'Rejected')
                    . ' registry delete request' . ($regNo ? ' for file ' . formatAuditValue($regNo, 'regNo') : '');
                if ($requestId) {
                    $summaryLine .= ' (Request #' . formatAuditValue($requestId, 'request_id') . ')';
                }
                $reason = $take('reason');
                if ($reason) {
                    $parts[] = 'Reason: ' . formatAuditValue($reason, 'reason');
                }
                break;
            case 'registry_deleted':
                $regNo = $take('regNo');
                $mode = $take('mode');
                $summaryLine = 'Deleted registry file' . ($regNo ? ' ' . formatAuditValue($regNo, 'regNo') : '');
                if ($mode) {
                    $parts[] = 'Mode: ' . formatAuditValue($mode, 'mode');
                }
                $reason = $take('reason');
                if ($reason) {
                    $parts[] = 'Reason: ' . formatAuditValue($reason, 'reason');
                }
                break;
            case 'staff_due_delete_requested':
                $regNo = $take('regNo');
                $requestId = $take('request_id');
                $summaryLine = 'Requested deletion of staff due record' . ($regNo ? ' ' . formatAuditValue($regNo, 'regNo') : '');
                if ($requestId) {
                    $summaryLine .= ' (Request #' . formatAuditValue($requestId, 'request_id') . ')';
                }
                $reason = $take('reason');
                if ($reason) {
                    $parts[] = 'Reason: ' . formatAuditValue($reason, 'reason');
                }
                break;
            case 'staff_due_delete_approved':
            case 'staff_due_delete_rejected':
                $regNo = $take('regNo');
                $requestId = $take('request_id');
                $summaryLine = ($actionLower === 'staff_due_delete_approved' ? 'Approved' : 'Rejected')
                    . ' staff due delete request' . ($regNo ? ' for file ' . formatAuditValue($regNo, 'regNo') : '');
                if ($requestId) {
                    $summaryLine .= ' (Request #' . formatAuditValue($requestId, 'request_id') . ')';
                }
                $reason = $take('reason');
                if ($reason) {
                    $parts[] = 'Reason: ' . formatAuditValue($reason, 'reason');
                }
                break;
            case 'staff_due_deleted':
                $regNo = $take('regNo');
                $summaryLine = 'Deleted staff due record' . ($regNo ? ' ' . formatAuditValue($regNo, 'regNo') : '');
                $reason = $take('reason');
                if ($reason) {
                    $parts[] = 'Reason: ' . formatAuditValue($reason, 'reason');
                }
                break;
            case 'registry_recycle_bin_cleared':
                $regNo = $take('regNo');
                $staffName = $take('staff_name');
                $restored = $take('restored');
                $summaryLine = 'Permanently cleared recycle bin record';
                if ($regNo) {
                    $summaryLine .= ' for file ' . formatAuditValue($regNo, 'regNo');
                }
                if ($staffName) {
                    $summaryLine .= ' (' . formatAuditValue($staffName, 'staff_name') . ')';
                }
                if ($restored !== null) {
                    $parts[] = 'Restored previously: ' . formatAuditValue($restored, 'restored');
                }
                break;
            case 'registry_recycle_bin_purged':
                $purged = $take('purged_count');
                $olderThan = $take('older_than_days');
                $state = $take('state');
                $roleFilter = $take('actor_role_filter');
                $summaryLine = 'Purged recycle bin records';
                if ($purged !== null) {
                    $summaryLine .= ' (' . formatAuditValue($purged, 'purged_count') . ' records)';
                }
                if ($olderThan !== null) {
                    $parts[] = 'Older than: ' . formatAuditValue($olderThan, 'older_than_days') . ' days';
                }
                if ($state) {
                    $parts[] = 'State: ' . formatAuditValue($state, 'state');
                }
                if ($roleFilter) {
                    $parts[] = 'Role filter: ' . formatAuditValue($roleFilter, 'actor_role_filter');
                }
                break;
            case 'registry_restored_from_recycle_bin':
                $regNo = $take('regNo');
                $recycleId = $take('recycle_id');
                $summaryLine = 'Restored registry file' . ($regNo ? ' ' . formatAuditValue($regNo, 'regNo') : '');
                if ($recycleId) {
                    $summaryLine .= ' (Recycle #' . formatAuditValue($recycleId, 'recycle_id') . ')';
                }
                break;
            case 'system_backup_created':
                $scope = $take('backup_scope');
                $includeUploads = $take('include_uploads');
                $size = $take('file_size_bytes');
                $summaryLine = 'Created system backup';
                if ($scope) {
                    $summaryLine .= ' (' . formatAuditValue($scope, 'backup_scope') . ')';
                }
                if ($includeUploads !== null) {
                    $parts[] = 'Include uploads: ' . formatAuditValue($includeUploads, 'include_uploads');
                }
                if ($size !== null) {
                    $parts[] = 'Size: ' . formatAuditValue($size, 'file_size_bytes');
                }
                break;
            case 'system_backup_restored':
                $restoreFiles = $take('restore_files');
                $summaryLine = 'Restored system backup';
                if ($restoreFiles !== null) {
                    $parts[] = 'Restore files: ' . formatAuditValue($restoreFiles, 'restore_files');
                }
                $metadata = $take('metadata');
                if ($metadata) {
                    $parts[] = 'Metadata: ' . formatAuditValue($metadata, 'metadata');
                }
                break;
            case 'registry_box_rebalanced':
                $updatedRecords = $take('updated_records');
                $boxCount = $take('box_count');
                $summaryLine = 'Rebalanced registry box allocation';
                if ($updatedRecords !== null) {
                    $parts[] = 'Updated records: ' . formatAuditValue($updatedRecords, 'updated_records');
                }
                if ($boxCount !== null) {
                    $parts[] = 'Box count: ' . formatAuditValue($boxCount, 'box_count');
                }
                break;
            case 'data_cleanup_run':
            case 'data_cleanup_preview':
                $cleanupAction = $take('action');
                $affected = $take('affected_records');
                $dryRun = $take('dry_run');
                $summaryLine = $actionLower === 'data_cleanup_preview' ? 'Data cleanup preview' : 'Data cleanup run';
                if ($cleanupAction) {
                    $summaryLine .= ' (' . formatAuditValue($cleanupAction, 'action') . ')';
                }
                if ($affected !== null) {
                    $parts[] = 'Affected records: ' . formatAuditValue($affected, 'affected_records');
                }
                if ($dryRun !== null) {
                    $parts[] = 'Dry run: ' . formatAuditValue($dryRun, 'dry_run');
                }
                break;
            case 'pensioner_password_changed':
            case 'pensioner_password_reset_default':
                $targetName = $take('target_user_name');
                $customPassword = $take('custom_password');
                $summaryLine = $actionLower === 'pensioner_password_changed'
                    ? 'Updated pensioner password'
                    : 'Reset pensioner password to default';
                if ($targetName) {
                    $summaryLine .= ' for ' . formatAuditValue($targetName, 'target_user_name');
                }
                if ($customPassword !== null) {
                    $parts[] = 'Custom password: ' . formatAuditValue($customPassword, 'custom_password');
                }
                break;
            case 'pensioner_account_sync_failed':
                $syncMessage = $take('message');
                $summaryLine = 'Pensioner account sync failed';
                if ($syncMessage) {
                    $parts[] = 'Reason: ' . formatAuditValue($syncMessage, 'message');
                }
                break;
            case 'pensioner_lookup_visibility_updated':
                $visible = $take('visible');
                $summaryLine = 'Updated pensioner lookup visibility';
                if ($visible !== null) {
                    $parts[] = 'Visibility: ' . formatAuditValue($visible, 'visible');
                }
                break;
            case 'role_created':
            case 'role_updated':
            case 'role_deleted':
                $roleLabel = $take('role_label');
                $isActive = $take('is_active');
                $cloneFrom = $take('clone_from_role');
                $summaryLine = ($actionLower === 'role_created' ? 'Created role' : ($actionLower === 'role_deleted' ? 'Deleted role' : 'Updated role'));
                if ($roleLabel) {
                    $summaryLine .= ' ' . formatAuditValue($roleLabel, 'role_label');
                }
                if ($isActive !== null) {
                    $parts[] = 'Active: ' . formatAuditValue($isActive, 'is_active');
                }
                if ($cloneFrom) {
                    $parts[] = 'Cloned from: ' . formatAuditValue($cloneFrom, 'clone_from_role');
                }
                break;
            case 'role_permissions_updated':
                $roleLabel = $take('role_label');
                $updatedCount = $take('updated_permissions');
                $summaryLine = 'Updated role permissions';
                if ($roleLabel) {
                    $summaryLine .= ' for ' . formatAuditValue($roleLabel, 'role_label');
                }
                if ($updatedCount !== null) {
                    $summaryLine .= ' (' . formatAuditValue($updatedCount, 'updated_permissions') . ' updates)';
                }
                break;
            case 'user_permissions_updated':
                $targetUser = $take('target_user');
                $updatedCount = $take('updated_permissions');
                $summaryLine = 'Updated user permissions';
                if ($targetUser) {
                    $summaryLine .= ' for ' . formatAuditValue($targetUser, 'target_user');
                }
                if ($updatedCount !== null) {
                    $summaryLine .= ' (' . formatAuditValue($updatedCount, 'updated_permissions') . ' updates)';
                }
                break;
            case 'user_created':
                $userEmail = $take('user_email');
                $userRole = $take('user_role');
                $summaryLine = 'Created user account';
                if ($userEmail) {
                    $summaryLine .= ' for ' . formatAuditValue($userEmail, 'user_email');
                }
                if ($userRole) {
                    $summaryLine .= ' (' . formatAuditValue($userRole, 'user_role') . ')';
                }
                break;
            case 'user_deleted':
                $summaryLine = 'Deleted user account';
                break;
            case 'upload_cycle':
                $year = $take('year');
                $month = $take('month');
                $rowsUploaded = $take('rows_uploaded');
                $matchedRows = $take('matched_rows');
                $unmatchedRows = $take('unmatched_rows');
                $summaryLine = 'Uploaded payroll cycle';
                if ($year && $month) {
                    $summaryLine .= ' (' . formatAuditValue($year, 'year') . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ')';
                }
                if ($rowsUploaded !== null) {
                    $parts[] = 'Rows uploaded: ' . formatAuditValue($rowsUploaded, 'rows_uploaded');
                }
                if ($matchedRows !== null) {
                    $parts[] = 'Matched rows: ' . formatAuditValue($matchedRows, 'matched_rows');
                }
                if ($unmatchedRows !== null) {
                    $parts[] = 'Unmatched rows: ' . formatAuditValue($unmatchedRows, 'unmatched_rows');
                }
                break;
            case 'upload_cycle_failed':
                $year = $take('year');
                $month = $take('month');
                $error = $take('error');
                $summaryLine = 'Payroll upload failed';
                if ($year && $month) {
                    $summaryLine .= ' (' . formatAuditValue($year, 'year') . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ')';
                }
                if ($error) {
                    $parts[] = 'Error: ' . formatAuditValue($error, 'error');
                }
                break;
            case 'upload_suspension_cycle':
                $year = $take('year');
                $month = $take('month');
                $summaryLine = 'Uploaded suspension saved-amount cycle';
                if ($year && $month) {
                    $summaryLine .= ' (' . formatAuditValue($year, 'year') . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ')';
                }
                break;
            case 'replace_cycle':
                $year = $take('year');
                $month = $take('month');
                $replacementRows = $take('replacement_rows');
                $summaryLine = 'Replaced payroll cycle data';
                if ($year && $month) {
                    $summaryLine .= ' (' . formatAuditValue($year, 'year') . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ')';
                }
                if ($replacementRows !== null) {
                    $parts[] = 'Replacement rows: ' . formatAuditValue($replacementRows, 'replacement_rows');
                }
                break;
            case 'edit_cycle_period':
                $fromYear = $take('from_year');
                $fromMonth = $take('from_month');
                $toYear = $take('to_year');
                $toMonth = $take('to_month');
                $summaryLine = 'Edited payroll cycle period';
                if ($fromYear && $fromMonth && $toYear && $toMonth) {
                    $summaryLine .= ' ('
                        . formatAuditValue($fromYear, 'from_year') . '-' . str_pad((string)$fromMonth, 2, '0', STR_PAD_LEFT)
                        . ' to ' . formatAuditValue($toYear, 'to_year') . '-' . str_pad((string)$toMonth, 2, '0', STR_PAD_LEFT) . ')';
                }
                break;
            case 'delete_cycle':
                $year = $take('payroll_year');
                $month = $take('payroll_month');
                $replacementId = $take('replacement_cycle_id');
                $summaryLine = 'Deleted payroll cycle';
                if ($year && $month) {
                    $summaryLine .= ' (' . formatAuditValue($year, 'payroll_year') . '-' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . ')';
                }
                if ($replacementId) {
                    $parts[] = 'Replacement cycle: ' . formatAuditValue($replacementId, 'replacement_cycle_id');
                }
                break;
            case 'message_sent':
            case 'broadcast_sent':
                $messageType = $take('message_type');
                $recipientCount = $take('recipient_count');
                $summaryLine = $actionLower === 'broadcast_sent' ? 'Sent broadcast message' : 'Sent message';
                if ($recipientCount !== null) {
                    $summaryLine .= ' to ' . formatAuditValue($recipientCount, 'recipient_count') . ' recipient(s)';
                }
                if ($messageType) {
                    $parts[] = 'Message type: ' . formatAuditValue($messageType, 'message_type');
                }
                break;
            case 'message_attachment_previewed':
            case 'message_attachment_downloaded':
                $messageId = $take('message_id');
                $filePath = $take('file_path');
                $summaryLine = $actionLower === 'message_attachment_previewed' ? 'Previewed message attachment' : 'Downloaded message attachment';
                if ($messageId) {
                    $summaryLine .= ' (Message #' . formatAuditValue($messageId, 'message_id') . ')';
                }
                if ($filePath) {
                    $parts[] = 'File: ' . formatAuditValue($filePath, 'file_path');
                }
                break;
            case 'staff_document_previewed':
            case 'staff_document_downloaded':
                $regNo = $take('reg_no');
                $docType = $take('doc_type');
                $filePath = $take('file_path');
                $summaryLine = $actionLower === 'staff_document_previewed' ? 'Previewed staff document' : 'Downloaded staff document';
                if ($regNo) {
                    $summaryLine .= ' for file ' . formatAuditValue($regNo, 'reg_no');
                }
                if ($docType) {
                    $parts[] = 'Document type: ' . formatAuditValue($docType, 'doc_type');
                }
                if ($filePath) {
                    $parts[] = 'File: ' . formatAuditValue($filePath, 'file_path');
                }
                break;
            case 'feedback_submitted':
                $reference = $take('reference_no');
                $feedbackType = $take('feedback_type');
                $audience = $take('audience');
                $summaryLine = 'Feedback submitted';
                if ($reference) {
                    $summaryLine .= ' (' . formatAuditValue($reference, 'reference_no') . ')';
                }
                if ($feedbackType) {
                    $parts[] = 'Type: ' . formatAuditValue($feedbackType, 'feedback_type');
                }
                if ($audience) {
                    $parts[] = 'Audience: ' . formatAuditValue($audience, 'audience');
                }
                $pageContext = $take('page_context');
                if ($pageContext) {
                    $parts[] = 'Context: ' . formatAuditValue($pageContext, 'page_context');
                }
                break;
            case 'feedback_submission_updated':
                $reference = $take('reference_no');
                if ($reference) {
                    $summaryLine = 'Updated feedback submission ' . formatAuditValue($reference, 'reference_no');
                } else {
                    $summaryLine = 'Updated feedback submission';
                }
                break;
            case 'task_completion_queued':
            case 'task_completion_queue_updated':
            case 'task_completion_queue_removed':
                $taskType = $take('task_type');
                $regNo = $take('related_reg_no');
                $nextAssigned = $take('next_assigned_to');
                $requiredRole = $take('required_assignment_role');
                $priority = $take('priority');
                $summaryLine = $actionLower === 'task_completion_queue_removed'
                    ? 'Removed queued task'
                    : ($actionLower === 'task_completion_queue_updated' ? 'Updated queued task' : 'Queued task for batch forwarding');
                if ($taskType) {
                    $summaryLine .= ' (' . formatAuditValue($taskType, 'task_type') . ')';
                }
                if ($regNo) {
                    $summaryLine .= ' for file ' . formatAuditValue($regNo, 'related_reg_no');
                }
                if ($nextAssigned) {
                    $parts[] = 'Next assignee: ' . formatAuditValue($nextAssigned, 'next_assigned_to');
                }
                if ($requiredRole) {
                    $parts[] = 'Required role: ' . formatAuditValue($requiredRole, 'required_assignment_role');
                }
                if ($priority) {
                    $parts[] = 'Priority: ' . formatAuditValue($priority, 'priority');
                }
                break;
            case 'task_completion_queue_processed':
                $processed = $take('processed');
                $failed = $take('failed');
                $summaryLine = 'Processed task completion queue';
                if ($processed !== null || $failed !== null) {
                    $summaryLine .= ' (' . formatAuditValue($processed ?? 0, 'processed') . ' processed, '
                        . formatAuditValue($failed ?? 0, 'failed') . ' failed)';
                }
                break;
            default:
                if (str_starts_with($actionLower, 'task_alert_')) {
                    $taskId = $take('task_id');
                    $statusBefore = $take('status_before');
                    $suffix = str_replace('task_alert_', '', $actionLower);
                    $summaryLine = 'Updated task alert (' . formatAuditValue($suffix, 'status') . ')';
                    if ($taskId) {
                        $summaryLine .= ' for task #' . formatAuditValue($taskId, 'task_id');
                    }
                    if ($statusBefore) {
                        $parts[] = 'Previous status: ' . formatAuditValue($statusBefore, 'status');
                    }
                }
                break;
        }
    }

    if ($summaryLine !== '') {
        array_unshift($parts, $summaryLine);
    }

    foreach ($remaining as $key => $value) {
        $label = formatAuditKeyLabel((string)$key);
        $parts[] = $label . ': ' . formatAuditValue($value, (string)$key);
    }

    return trim(implode('; ', array_filter($parts, fn($part) => trim((string)$part) !== '')));
}

function formatAuditActionLabel(string $action): string {
    if ($action === '') {
        return 'Audit action';
    }
    return ucwords(str_replace('_', ' ', $action));
}

function logAuditEvent(mysqli $conn, array $data): bool {
    if (!getAppSettingBool($conn, 'enable_audit_logs', true)) {
        return false;
    }

    ensureAuditLogsTable($conn);

    $actorId = $data['actor_id'] ?? 'system';
    $actorName = $data['actor_name'] ?? 'System';
    $actorRole = $data['actor_role'] ?? 'system';
    $action = $data['action'] ?? 'audit_event';
    $entityType = $data['entity_type'] ?? null;
    $entityId = $data['entity_id'] ?? null;
    $details = $data['details'] ?? null;
    if (is_array($details)) {
        $details = formatAuditDetails($details, (string)$action, $entityType, $entityId);
    } elseif (is_string($details)) {
        $details = trim($details);
    }
    if ($details === null || $details === '') {
        $actionLabel = formatAuditActionLabel((string)$action);
        if (!empty($entityType) || !empty($entityId)) {
            $entityLabel = trim((string)($entityType ?? ''));
            if ($entityLabel !== '') {
                $entityLabel = ucwords(str_replace('_', ' ', $entityLabel));
            }
            $details = $entityLabel !== ''
                ? "{$actionLabel} on {$entityLabel}" . ($entityId ? " #{$entityId}" : '')
                : "{$actionLabel}" . ($entityId ? " #{$entityId}" : '');
        } else {
            $details = $actionLabel;
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_audit_logs (actor_id, actor_name, actor_role, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "sssssss",
        $actorId,
        $actorName,
        $actorRole,
        $action,
        $entityType,
        $entityId,
        $details
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureBackupLogsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_backup_logs (
            backup_id int(11) NOT NULL AUTO_INCREMENT,
            backup_label varchar(180) DEFAULT NULL,
            backup_type enum('manual','auto','restore_point') NOT NULL DEFAULT 'manual',
            backup_scope enum('full_system','database_only','uploads_only') NOT NULL DEFAULT 'full_system',
            file_name varchar(255) DEFAULT NULL,
            file_path varchar(500) DEFAULT NULL,
            file_size_bytes bigint(20) NOT NULL DEFAULT 0,
            checksum_sha256 varchar(128) DEFAULT NULL,
            include_uploads tinyint(1) NOT NULL DEFAULT 1,
            backup_time timestamp NOT NULL DEFAULT current_timestamp(),
            status enum('success','failed','restored','partial') NOT NULL DEFAULT 'success',
            notes text DEFAULT NULL,
            created_by varchar(100) DEFAULT NULL,
            created_by_name varchar(150) DEFAULT NULL,
            created_by_role varchar(80) DEFAULT NULL,
            restored_at timestamp NULL DEFAULT NULL,
            restored_by varchar(100) DEFAULT NULL,
            PRIMARY KEY (backup_id),
            KEY idx_backup_time (backup_time),
            KEY idx_backup_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function ensureDataExportRunsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_data_export_runs (
            export_id int(11) NOT NULL AUTO_INCREMENT,
            dataset_key varchar(80) NOT NULL,
            dataset_label varchar(180) NOT NULL,
            export_format enum('csv','xlsx','json') NOT NULL DEFAULT 'xlsx',
            file_name varchar(255) DEFAULT NULL,
            file_path varchar(500) DEFAULT NULL,
            file_size_bytes bigint(20) NOT NULL DEFAULT 0,
            filters_json longtext DEFAULT NULL,
            status enum('success','failed') NOT NULL DEFAULT 'success',
            notes text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            created_by varchar(100) DEFAULT NULL,
            created_by_name varchar(150) DEFAULT NULL,
            created_by_role varchar(80) DEFAULT NULL,
            PRIMARY KEY (export_id),
            KEY idx_export_created (created_at),
            KEY idx_export_dataset (dataset_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function getBackupStoragePath(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return $path;
}

function getDataExportStoragePath(): string {
    $path = __DIR__ . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return $path;
}

function recordBackupLog(mysqli $conn, array $payload): bool {
    ensureBackupLogsTable($conn);

    $stmt = $conn->prepare("
        INSERT INTO tb_backup_logs (
            backup_label, backup_type, backup_scope, file_name, file_path, file_size_bytes,
            checksum_sha256, include_uploads, status, notes, created_by, created_by_name,
            created_by_role, restored_at, restored_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $backupLabel = $payload['backup_label'] ?? null;
    $backupType = $payload['backup_type'] ?? 'manual';
    $backupScope = $payload['backup_scope'] ?? 'full_system';
    $fileName = $payload['file_name'] ?? null;
    $filePath = $payload['file_path'] ?? null;
    $fileSize = (int)($payload['file_size_bytes'] ?? 0);
    $checksum = $payload['checksum_sha256'] ?? null;
    $includeUploads = !empty($payload['include_uploads']) ? 1 : 0;
    $status = $payload['status'] ?? 'success';
    $notes = $payload['notes'] ?? null;
    $createdBy = $payload['created_by'] ?? null;
    $createdByName = $payload['created_by_name'] ?? null;
    $createdByRole = $payload['created_by_role'] ?? null;
    $restoredAt = $payload['restored_at'] ?? null;
    $restoredBy = $payload['restored_by'] ?? null;

    $stmt->bind_param(
        'sssssisisssssss',
        $backupLabel,
        $backupType,
        $backupScope,
        $fileName,
        $filePath,
        $fileSize,
        $checksum,
        $includeUploads,
        $status,
        $notes,
        $createdBy,
        $createdByName,
        $createdByRole,
        $restoredAt,
        $restoredBy
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function recordDataExportRun(mysqli $conn, array $payload): bool {
    ensureDataExportRunsTable($conn);

    $stmt = $conn->prepare("
        INSERT INTO tb_data_export_runs (
            dataset_key, dataset_label, export_format, file_name, file_path, file_size_bytes,
            filters_json, status, notes, created_by, created_by_name, created_by_role
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $datasetKey = $payload['dataset_key'] ?? '';
    $datasetLabel = $payload['dataset_label'] ?? '';
    $format = $payload['export_format'] ?? 'xlsx';
    $fileName = $payload['file_name'] ?? null;
    $filePath = $payload['file_path'] ?? null;
    $fileSize = (int)($payload['file_size_bytes'] ?? 0);
    $filters = $payload['filters_json'] ?? null;
    if (is_array($filters)) {
        $filters = json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $status = $payload['status'] ?? 'success';
    $notes = $payload['notes'] ?? null;
    $createdBy = $payload['created_by'] ?? null;
    $createdByName = $payload['created_by_name'] ?? null;
    $createdByRole = $payload['created_by_role'] ?? null;

    $stmt->bind_param(
        'sssssissssss',
        $datasetKey,
        $datasetLabel,
        $format,
        $fileName,
        $filePath,
        $fileSize,
        $filters,
        $status,
        $notes,
        $createdBy,
        $createdByName,
        $createdByRole
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// 
// User Permission Helpers //
function getDefaultRoleCatalog(): array {
    return [
        'super_admin' => ['label' => 'Super Administrator', 'description' => 'Highest platform governance role with unrestricted administration, security, audit, backup, restore, data, role-management, and administrator-account governance authority', 'is_system' => 1],
        'admin' => ['label' => 'Administrator', 'description' => 'Full administration privileges', 'is_system' => 1],
        'clerk' => ['label' => 'Clerk', 'description' => 'Application intake and verification support', 'is_system' => 1],
        'oc_pen' => ['label' => 'OC/Pension', 'description' => 'Workflow control and assignment authority', 'is_system' => 1],
        'writeup_officer' => ['label' => 'Writeup Officer', 'description' => 'Handles pension write-up preparation', 'is_system' => 1],
        'file_creator' => ['label' => 'File Creator', 'description' => 'Creates and updates pension files', 'is_system' => 1],
        'data_entry' => ['label' => 'Data Entrant', 'description' => 'Captures and updates pensioner data', 'is_system' => 1],
        'assessor' => ['label' => 'Assessor', 'description' => 'Assesses pension benefits and calculations', 'is_system' => 1],
        'auditor' => ['label' => 'Auditor', 'description' => 'Audits pension assessment workflow', 'is_system' => 1],
        'approver' => ['label' => 'Approver', 'description' => 'Final approval authority for pension workflow', 'is_system' => 1],
        'user' => ['label' => 'User', 'description' => 'General internal user access', 'is_system' => 1],
        'pensioner' => ['label' => 'Pensioner', 'description' => 'Beneficiary user with limited access', 'is_system' => 1]
    ];
}



function ensureSystemLogsTable(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS tb_system_logs (
        log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        log_level VARCHAR(20) NOT NULL DEFAULT 'info',
        log_category VARCHAR(80) NOT NULL DEFAULT 'general',
        event_code VARCHAR(120) DEFAULT NULL,
        message TEXT NOT NULL,
        context_json LONGTEXT DEFAULT NULL,
        actor_id VARCHAR(50) DEFAULT NULL,
        actor_name VARCHAR(150) DEFAULT NULL,
        actor_role VARCHAR(80) DEFAULT NULL,
        ip_address VARCHAR(64) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_system_logs_level_created (log_level, created_at),
        INDEX idx_system_logs_category_created (log_category, created_at),
        INDEX idx_system_logs_actor_created (actor_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    @$conn->query($sql);
}

function recordSystemLog(mysqli $conn, array $payload): bool {
    ensureSystemLogsTable($conn);
    if (!getAppSettingBool($conn, 'system_logs_enabled', true)) {
        return true;
    }
    static $retentionPruned = false;
    if (!$retentionPruned) {
        $retentionDays = max(1, getAppSettingInt($conn, 'system_logs_retention_days', 365));
        $conn->query("DELETE FROM tb_system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)");
        $retentionPruned = true;
    }

    $levelWeights = [
        'debug' => 10,
        'info' => 20,
        'notice' => 30,
        'warning' => 40,
        'error' => 50,
        'critical' => 60
    ];

    $stmt = $conn->prepare("INSERT INTO tb_system_logs (
        log_level,
        log_category,
        event_code,
        message,
        context_json,
        actor_id,
        actor_name,
        actor_role,
        ip_address
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }

    $logLevel = strtolower(trim((string)($payload['log_level'] ?? 'info')));
    if (!in_array($logLevel, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) {
        $logLevel = 'info';
    }
    $category = trim((string)($payload['log_category'] ?? 'general')) ?: 'general';
    $minLevel = strtolower(getAppSettingString($conn, 'system_logs_min_level', 'info'));
    if (!isset($levelWeights[$minLevel])) {
        $minLevel = 'info';
    }
    if (($levelWeights[$logLevel] ?? 20) < ($levelWeights[$minLevel] ?? 20)) {
        $stmt->close();
        return true;
    }
    if (in_array($logLevel, ['warning'], true) && !getAppSettingBool($conn, 'system_logs_capture_warnings', true)) {
        $stmt->close();
        return true;
    }
    if (in_array($logLevel, ['error', 'critical'], true) && !getAppSettingBool($conn, 'system_logs_capture_errors', true)) {
        $stmt->close();
        return true;
    }
    if (
        (stripos($category, 'security') !== false || stripos((string)($payload['event_code'] ?? ''), 'security') !== false) &&
        !getAppSettingBool($conn, 'system_logs_capture_security_events', true)
    ) {
        $stmt->close();
        return true;
    }
    if (
        in_array($category, ['backup', 'backup_restore', 'data_export', 'data_import'], true) &&
        !getAppSettingBool($conn, 'system_logs_capture_integrations', true)
    ) {
        $stmt->close();
        return true;
    }

    $eventCode = trim((string)($payload['event_code'] ?? '')) ?: null;
    $message = trim((string)($payload['message'] ?? 'System event recorded.')) ?: 'System event recorded.';
    $context = $payload['context'] ?? null;
    $contextJson = $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $actorId = isset($payload['actor_id']) ? (string)$payload['actor_id'] : (isset($_SESSION['userId']) ? (string)$_SESSION['userId'] : null);
    $actorName = isset($payload['actor_name']) ? (string)$payload['actor_name'] : (isset($_SESSION['userName']) ? (string)$_SESSION['userName'] : null);
    $actorRole = isset($payload['actor_role']) ? (string)$payload['actor_role'] : (isset($_SESSION['userRole']) ? (string)$_SESSION['userRole'] : null);
    $ipAddress = isset($payload['ip_address']) ? (string)$payload['ip_address'] : getClientIP();

    $stmt->bind_param(
        'sssssssss',
        $logLevel,
        $category,
        $eventCode,
        $message,
        $contextJson,
        $actorId,
        $actorName,
        $actorRole,
        $ipAddress
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureRoleGovernanceTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_roles (
            role_key varchar(50) NOT NULL,
            role_label varchar(100) NOT NULL,
            role_description text DEFAULT NULL,
            clone_from_role varchar(50) DEFAULT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            is_system tinyint(1) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (role_key),
            KEY idx_role_active (is_active),
            KEY idx_role_clone (clone_from_role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    if ($columnResult = $conn->query("SHOW COLUMNS FROM tb_roles LIKE 'clone_from_role'")) {
        $hasCloneColumn = $columnResult->num_rows > 0;
        $columnResult->close();
        if (!$hasCloneColumn) {
            $conn->query("ALTER TABLE tb_roles ADD COLUMN clone_from_role varchar(50) DEFAULT NULL AFTER role_description");
            $conn->query("ALTER TABLE tb_roles ADD KEY idx_role_clone (clone_from_role)");
        }
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_role_permissions (
            role_permission_id int(11) NOT NULL AUTO_INCREMENT,
            role_key varchar(50) NOT NULL,
            permission_key varchar(120) NOT NULL,
            is_allowed tinyint(1) NOT NULL DEFAULT 1,
            notes text DEFAULT NULL,
            updated_by varchar(100) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (role_permission_id),
            UNIQUE KEY uniq_role_permission (role_key, permission_key),
            KEY idx_role_perm_role (role_key),
            KEY idx_role_perm_permission (permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $defaults = getDefaultRoleCatalog();
    if (!empty($defaults)) {
        $stmt = $conn->prepare("
            INSERT INTO tb_roles (role_key, role_label, role_description, clone_from_role, is_active, is_system)
            VALUES (?, ?, ?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE
                role_label = IF(tb_roles.role_label IS NULL OR tb_roles.role_label = '', VALUES(role_label), tb_roles.role_label),
                role_description = IF(tb_roles.role_description IS NULL OR tb_roles.role_description = '', VALUES(role_description), tb_roles.role_description),
                clone_from_role = IF(VALUES(clone_from_role) IS NOT NULL AND VALUES(clone_from_role) <> '', VALUES(clone_from_role), tb_roles.clone_from_role),
                is_system = GREATEST(tb_roles.is_system, VALUES(is_system))
        ");
        if ($stmt) {
            foreach ($defaults as $key => $meta) {
                $label = (string)($meta['label'] ?? $key);
                $description = (string)($meta['description'] ?? '');
                $cloneFromRole = normalizeRoleKey((string)($meta['clone_from_role'] ?? ''));
                $cloneFromRole = $cloneFromRole !== '' ? $cloneFromRole : null;
                $isSystem = (int)($meta['is_system'] ?? 0);
                $stmt->bind_param("ssssi", $key, $label, $description, $cloneFromRole, $isSystem);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    $conn->query("
        UPDATE tb_roles
        SET
            role_label = 'Super Administrator',
            role_description = 'Highest platform governance role with unrestricted administration, security, audit, backup, restore, data, role-management, and administrator-account governance authority',
            clone_from_role = NULL,
            is_active = 1,
            is_system = 1
        WHERE role_key = 'super_admin'
    ");

    $created = true;
}

function getRoleLabelMap(mysqli $conn, bool $activeOnly = false): array {
    ensureRoleGovernanceTables($conn);
    static $cache = [];
    $cacheKey = $activeOnly ? 'active' : 'all';
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $where = $activeOnly ? " WHERE is_active = 1" : "";
    $result = $conn->query("
        SELECT role_key, role_label
        FROM tb_roles
        {$where}
        ORDER BY is_system DESC, role_label ASC
    ");

    $map = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = strtolower(trim((string)($row['role_key'] ?? '')));
            if ($key === '') {
                continue;
            }
            $label = trim((string)($row['role_label'] ?? ''));
            if ($label === '') {
                $label = ucwords(str_replace('_', ' ', $key));
            }
            $map[$key] = $label;
        }
    }

    if (empty($map)) {
        foreach (getDefaultRoleCatalog() as $key => $meta) {
            $map[$key] = (string)($meta['label'] ?? ucwords(str_replace('_', ' ', $key)));
        }
    }

    $cache[$cacheKey] = $map;
    return $map;
}

function getActiveRoleKeys(mysqli $conn): array {
    return array_keys(getRoleLabelMap($conn, true));
}

function getRoleLabel(mysqli $conn, string $roleKey): string {
    $normalized = strtolower(trim($roleKey));
    if ($normalized === '') {
        return 'User';
    }
    $labels = getRoleLabelMap($conn, false);
    if (isset($labels[$normalized])) {
        return (string)$labels[$normalized];
    }
    return ucwords(str_replace('_', ' ', $normalized));
}

function formatRoleLabel(mysqli $conn, string $roleKey): string {
    return getRoleLabel($conn, $roleKey);
}

function ensureWorkflowLogsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_workflow_logs (
        log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id INT DEFAULT NULL,
        staffdue_id INT DEFAULT NULL,
        regNo VARCHAR(50) DEFAULT NULL,
        action VARCHAR(80) NOT NULL,
        from_status VARCHAR(40) DEFAULT NULL,
        to_status VARCHAR(40) DEFAULT NULL,
        actor_id VARCHAR(100) DEFAULT NULL,
        actor_name VARCHAR(150) DEFAULT NULL,
        actor_role VARCHAR(80) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        metadata_json LONGTEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_workflow_task (task_id),
        KEY idx_workflow_staff (staffdue_id),
        KEY idx_workflow_regno (regNo),
        KEY idx_workflow_action (action),
        KEY idx_workflow_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureTaskDelegationLogsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_task_delegation_logs (
        log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_id INT NOT NULL,
        from_user_id VARCHAR(100) DEFAULT NULL,
        from_user_name VARCHAR(150) DEFAULT NULL,
        from_user_role VARCHAR(80) DEFAULT NULL,
        to_user_id VARCHAR(100) DEFAULT NULL,
        to_user_name VARCHAR(150) DEFAULT NULL,
        to_user_role VARCHAR(80) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        priority VARCHAR(20) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_task_delegation_task (task_id),
        KEY idx_task_delegation_from (from_user_id),
        KEY idx_task_delegation_to (to_user_id),
        KEY idx_task_delegation_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function recordWorkflowLog(mysqli $conn, array $data): bool {
    if (!getAppSettingBool($conn, 'workflow_logs_enabled', true)) {
        return false;
    }
    ensureWorkflowLogsTable($conn);
    static $retentionPruned = false;
    if (!$retentionPruned) {
        $retentionDays = max(1, getAppSettingInt($conn, 'workflow_logs_retention_days', 1825));
        $conn->query("DELETE FROM tb_workflow_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)");
        $retentionPruned = true;
    }

    $includeComments = getAppSettingBool($conn, 'workflow_logs_include_comments', true);
    $captureAssignment = getAppSettingBool($conn, 'workflow_logs_capture_assignment', true);

    $action = trim((string)($data['action'] ?? 'workflow_event')) ?: 'workflow_event';
    $note = $includeComments ? trim((string)($data['note'] ?? '')) : '';
    $metadata = $data['metadata'] ?? null;
    if (is_array($metadata) && !$captureAssignment) {
        foreach ($metadata as $key => $_value) {
            if (stripos((string)$key, 'assign') !== false) {
                unset($metadata[$key]);
            }
        }
    }
    $metadataJson = is_array($metadata)
        ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;

    $stmt = $conn->prepare("
        INSERT INTO tb_workflow_logs (
            task_id, staffdue_id, regNo, action, from_status, to_status,
            actor_id, actor_name, actor_role, note, metadata_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }

    $taskId = isset($data['task_id']) ? (int)$data['task_id'] : null;
    $staffdueId = isset($data['staffdue_id']) ? (int)$data['staffdue_id'] : null;
    $regNo = trim((string)($data['reg_no'] ?? $data['regNo'] ?? '')) ?: null;
    $fromStatus = trim((string)($data['from_status'] ?? '')) ?: null;
    $toStatus = trim((string)($data['to_status'] ?? '')) ?: null;
    $actorId = (string)($data['actor_id'] ?? ($_SESSION['userId'] ?? 'system'));
    $actorName = (string)($data['actor_name'] ?? ($_SESSION['userName'] ?? 'System'));
    $actorRole = (string)($data['actor_role'] ?? ($_SESSION['userRole'] ?? 'system'));

    $stmt->bind_param(
        "iisssssssss",
        $taskId,
        $staffdueId,
        $regNo,
        $action,
        $fromStatus,
        $toStatus,
        $actorId,
        $actorName,
        $actorRole,
        $note,
        $metadataJson
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function recordTaskDelegationLog(mysqli $conn, array $data): bool {
    if (!getAppSettingBool($conn, 'task_delegation_logs_enabled', true)) {
        return false;
    }
    ensureTaskDelegationLogsTable($conn);
    static $retentionPruned = false;
    if (!$retentionPruned) {
        $retentionDays = max(1, getAppSettingInt($conn, 'task_delegation_retention_days', 1095));
        $conn->query("DELETE FROM tb_task_delegation_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)");
        $retentionPruned = true;
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_task_delegation_logs (
            task_id, from_user_id, from_user_name, from_user_role,
            to_user_id, to_user_name, to_user_role, note, priority
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return false;
    }
    $taskId = isset($data['task_id']) ? (int)$data['task_id'] : 0;
    $fromUserId = (string)($data['from_user_id'] ?? ($_SESSION['userId'] ?? ''));
    $fromUserName = (string)($data['from_user_name'] ?? ($_SESSION['userName'] ?? ''));
    $fromUserRole = (string)($data['from_user_role'] ?? ($_SESSION['userRole'] ?? ''));
    $toUserId = (string)($data['to_user_id'] ?? '');
    $toUserName = (string)($data['to_user_name'] ?? '');
    $toUserRole = (string)($data['to_user_role'] ?? '');
    $note = trim((string)($data['note'] ?? ''));
    $priority = trim((string)($data['priority'] ?? ''));
    $stmt->bind_param(
        "issssssss",
        $taskId,
        $fromUserId,
        $fromUserName,
        $fromUserRole,
        $toUserId,
        $toUserName,
        $toUserRole,
        $note,
        $priority
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureAnalyticsSnapshotsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_analytics_snapshots (
        snapshot_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        snapshot_type VARCHAR(80) NOT NULL,
        snapshot_payload LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_analytics_snapshot_type (snapshot_type),
        KEY idx_analytics_snapshot_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function maybeRecordAnalyticsSnapshot(mysqli $conn, string $snapshotType, array $payload): bool {
    if (!getAppSettingBool($conn, 'analytics_dashboard_snapshots_enabled', true)) {
        return false;
    }
    ensureAnalyticsSnapshotsTable($conn);
    static $retentionPruned = false;
    if (!$retentionPruned) {
        $retentionDays = max(1, getAppSettingInt($conn, 'analytics_snapshot_retention_days', 365));
        $conn->query("DELETE FROM tb_analytics_snapshots WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)");
        $retentionPruned = true;
    }

    $snapshotType = trim($snapshotType);
    if ($snapshotType === '') {
        $snapshotType = 'general';
    }

    $refreshMinutes = max(5, getAppSettingInt($conn, 'analytics_refresh_interval_minutes', 15));
    $lastStmt = $conn->prepare("SELECT created_at FROM tb_analytics_snapshots WHERE snapshot_type = ? ORDER BY created_at DESC LIMIT 1");
    if ($lastStmt) {
        $lastStmt->bind_param('s', $snapshotType);
        $lastStmt->execute();
        $row = $lastStmt->get_result()->fetch_assoc();
        $lastStmt->close();
        if (!empty($row['created_at'])) {
            $lastTime = strtotime((string)$row['created_at']);
            if ($lastTime !== false && (time() - $lastTime) < ($refreshMinutes * 60)) {
                return false;
            }
        }
    }

    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO tb_analytics_snapshots (snapshot_type, snapshot_payload) VALUES (?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ss', $snapshotType, $payloadJson);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function formatTitleName(string $title = '', string $sName = '', string $fName = '', string $separator = ' - '): string {
    $cleanTitle = trim((string)$title);
    $name = trim(trim((string)$sName) . ' ' . trim((string)$fName));
    if ($cleanTitle !== '' && $name !== '') {
        return $cleanTitle . $separator . $name;
    }
    return $cleanTitle !== '' ? $cleanTitle : $name;
}

function normalizeRoleKey(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return '';
    }
    $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
    $normalized = preg_replace('/_+/', '_', (string)$normalized);
    return trim((string)$normalized, '_');
}

function getOcPenEquivalentRoleKeys(): array {
    return ['oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension'];
}

function isOcPenEquivalentRole(string $roleKey): bool {
    $normalized = normalizeRoleKey($roleKey);
    if ($normalized === '') {
        return false;
    }
    return in_array($normalized, getOcPenEquivalentRoleKeys(), true);
}

function getRoleCloneMap(mysqli $conn): array {
    ensureRoleGovernanceTables($conn);
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $result = $conn->query("
        SELECT role_key, clone_from_role
        FROM tb_roles
        WHERE clone_from_role IS NOT NULL AND clone_from_role <> ''
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roleKey = normalizeRoleKey((string)($row['role_key'] ?? ''));
            $cloneFrom = normalizeRoleKey((string)($row['clone_from_role'] ?? ''));
            if ($roleKey === '' || $cloneFrom === '') {
                continue;
            }
            $cache[$roleKey] = $cloneFrom;
        }
        $result->close();
    }
    return $cache;
}

function resolveRoleAccessKey(mysqli $conn, string $roleKey): string {
    $normalized = normalizeRoleKey($roleKey);
    if ($normalized === '') {
        return '';
    }
    if ($normalized === 'super_admin') {
        return 'super_admin';
    }

    $cloneMap = getRoleCloneMap($conn);
    $visited = [];
    $current = $normalized;
    $limit = 6;
    while ($limit > 0 && isset($cloneMap[$current])) {
        if (isset($visited[$current])) {
            break;
        }
        $visited[$current] = true;
        $next = $cloneMap[$current];
        if ($next === '' || $next === $current) {
            break;
        }
        $current = $next;
        $limit--;
    }
    return $current;
}

function getEffectiveRoleKey(mysqli $conn, string $roleKey): string {
    $normalized = normalizeRoleKey($roleKey);
    if ($normalized === '') {
        return '';
    }

    if (function_exists('resolveRoleAccessKey')) {
        return resolveRoleAccessKey($conn, $normalized);
    }

    return $normalized;
}

function getSessionEffectiveRoleKey(mysqli $conn): string {
    $cached = normalizeRoleKey((string)($_SESSION['userRoleEffective'] ?? ''));
    if ($cached !== '') {
        return $cached;
    }

    return getEffectiveRoleKey($conn, (string)($_SESSION['userRole'] ?? ''));
}

function sessionRoleIn(mysqli $conn, array $roles): bool {
    $effective = getSessionEffectiveRoleKey($conn);
    $rawRole = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
    if ($effective === '' && $rawRole === '') {
        return false;
    }

    $allowed = [];
    foreach ($roles as $role) {
        $normalized = normalizeRoleKey((string)$role);
        if ($normalized !== '') {
            $allowed[$normalized] = true;
        }
    }

    if ($effective !== '' && isset($allowed[$effective])) {
        return true;
    }
    if ($rawRole !== '' && isset($allowed[$rawRole])) {
        return true;
    }

    return $rawRole === 'super_admin' && isset($allowed['admin']);
}

function roleHasAdminAccess(mysqli $conn, string $roleKey): bool {
    $rawRole = normalizeRoleKey($roleKey);
    if ($rawRole === 'super_admin' || $rawRole === 'admin') {
        return true;
    }

    return getEffectiveRoleKey($conn, $rawRole) === 'admin';
}

function currentSessionHasAdminAccess(mysqli $conn): bool {
    if (!isset($_SESSION['userId'])) {
        return false;
    }

    return sessionRoleIn($conn, ['admin']);
}

function isCurrentSessionSuperAdmin(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return normalizeRoleKey((string)($_SESSION['userRole'] ?? '')) === 'super_admin';
}

function isPrivilegedAdminAccountRole(string $roleKey): bool {
    $normalized = normalizeRoleKey($roleKey);
    return in_array($normalized, ['admin', 'super_admin'], true);
}

function canCurrentSessionManageAdminAccounts(mysqli $conn): bool {
    return isset($_SESSION['userId']) && sessionRoleIn($conn, ['admin']) && isCurrentSessionSuperAdmin();
}

function sessionCanAccessDataManagement(mysqli $conn): bool {
    return sessionRoleIn($conn, [
        'admin',
        'oc_pen',
        'dep_oc',
        'deputy_oc',
        'deputy_oc_pen',
        'deputy_oc_pension'
    ]);
}

function requireDataManagementAccess(mysqli $conn): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
        throw new RuntimeException('Authentication required');
    }

    if (!sessionCanAccessDataManagement($conn)) {
        throw new RuntimeException('Access denied');
    }

    return [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'User'),
        'user_role' => getSessionEffectiveRoleKey($conn)
    ];
}

function updateRegNoAcrossTables(mysqli $conn, string $oldRegNo, string $newRegNo, array $excludeTables = []): array {
    $oldRegNo = trim($oldRegNo);
    $newRegNo = trim($newRegNo);
    if ($oldRegNo === '' || $newRegNo === '' || $oldRegNo === $newRegNo) {
        return [];
    }

    $exclude = [];
    foreach ($excludeTables as $table) {
        $exclude[strtolower(trim((string)$table))] = true;
    }

    $updated = [];
    $result = $conn->query("
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND COLUMN_NAME = 'regNo'
    ");
    if (!$result) {
        return $updated;
    }

    while ($row = $result->fetch_assoc()) {
        $tableName = (string)($row['TABLE_NAME'] ?? '');
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        if ($clean === '' || strtolower($clean) !== strtolower($tableName)) {
            continue;
        }
        if (isset($exclude[strtolower($clean)])) {
            continue;
        }
        if (strpos($clean, 'tb_') !== 0) {
            continue;
        }

        $stmt = $conn->prepare("UPDATE {$clean} SET regNo = ? WHERE regNo = ?");
        if (!$stmt) {
            continue;
        }
        $stmt->bind_param('ss', $newRegNo, $oldRegNo);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $updated[] = $clean;
        }
        $stmt->close();
    }
    $result->close();

    return $updated;
}

function updatePensionerUserMetaRegNo(mysqli $conn, string $oldRegNo, string $newRegNo): int {
    $oldRegNo = trim($oldRegNo);
    $newRegNo = trim($newRegNo);
    if ($oldRegNo === '' || $newRegNo === '' || $oldRegNo === $newRegNo) {
        return 0;
    }

    $pattern = '%"regNo":"' . $oldRegNo . '"%';
    $stmt = $conn->prepare("
        SELECT userId, other
        FROM tb_users
        WHERE LOWER(COALESCE(userRole, '')) = 'pensioner'
          AND other LIKE ?
    ");
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $updatedCount = 0;
    while ($row = $result->fetch_assoc()) {
        $userId = (string)($row['userId'] ?? '');
        if ($userId === '') {
            continue;
        }
        $metaRaw = (string)($row['other'] ?? '');
        $meta = [];
        if ($metaRaw !== '') {
            $decoded = json_decode($metaRaw, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        if (!isset($meta['regNo']) || trim((string)$meta['regNo']) !== $oldRegNo) {
            continue;
        }
        $meta['regNo'] = $newRegNo;
        $updatedMeta = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($updatedMeta === false) {
            continue;
        }
        $updateStmt = $conn->prepare("UPDATE tb_users SET other = ? WHERE userId = ?");
        if (!$updateStmt) {
            continue;
        }
        $updateStmt->bind_param('ss', $updatedMeta, $userId);
        if ($updateStmt->execute()) {
            $updatedCount++;
        }
        $updateStmt->close();
    }
    $stmt->close();

    return $updatedCount;
}

function normalizeWorkflowRoleKey(string $roleKey): string {
    $normalized = normalizeRoleKey($roleKey);
    if ($normalized === '') {
        return '';
    }
    if ($normalized === 'super_admin') {
        return 'admin';
    }
    if (isOcPenEquivalentRole($normalized)) {
        return 'oc_pen';
    }
    return $normalized;
}

function getWorkflowRoleKeysForInbox(string $roleKey): array {
    $normalized = normalizeWorkflowRoleKey($roleKey);
    if ($normalized === '') {
        return [];
    }

    if ($normalized === 'oc_pen') {
        return getOcPenEquivalentRoleKeys();
    }

    return [$normalized];
}

function rolesAreWorkflowEquivalent(string $a, string $b): bool {
    return normalizeWorkflowRoleKey($a) !== '' && normalizeWorkflowRoleKey($a) === normalizeWorkflowRoleKey($b);
}

function resolveRoleKeyFromInput(mysqli $conn, string $roleInput, bool $activeOnly = false): string {
    $raw = trim($roleInput);
    if ($raw === '') {
        return '';
    }

    $candidate = normalizeRoleKey($raw);
    $roleLabels = getRoleLabelMap($conn, $activeOnly);
    if ($candidate !== '' && isset($roleLabels[$candidate])) {
        return $candidate;
    }

    $rawLower = strtolower($raw);
    foreach ($roleLabels as $roleKey => $roleLabel) {
        if (strtolower(trim((string)$roleLabel)) === $rawLower) {
            return (string)$roleKey;
        }
    }

    // Fallback to normalized key; caller performs allow-list validation.
    return $candidate;
}

function ensureUsersRoleColumnSupportsDynamicRoles(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    ensureRoleGovernanceTables($conn);

    // Migrate ENUM/SET role column to VARCHAR for dynamic role governance.
    $columnResult = $conn->query("SHOW COLUMNS FROM tb_users LIKE 'userRole'");
    if ($columnResult) {
        $column = $columnResult->fetch_assoc();
        if (is_array($column)) {
            $type = strtolower((string)($column['Type'] ?? ''));
            if (strpos($type, 'enum(') !== false || strpos($type, 'set(') !== false) {
                $conn->query("ALTER TABLE tb_users MODIFY userRole varchar(100) DEFAULT NULL");
            }
        }
    }

    // Normalize legacy role values once (labels/spaces/case/blank).
    $normalizedFlag = function_exists('getAppSetting') ? getAppSetting($conn, 'user_role_data_normalized_v2') : null;
    if ($normalizedFlag === '1') {
        $ensured = true;
        return;
    }

    $roleLabels = getRoleLabelMap($conn, false);
    $labelToKey = [];
    foreach ($roleLabels as $roleKey => $roleLabel) {
        $labelToKey[strtolower(trim((string)$roleLabel))] = (string)$roleKey;
    }

    $rows = $conn->query("SELECT userId, userRole FROM tb_users");
    if ($rows) {
        $updateStmt = $conn->prepare("UPDATE tb_users SET userRole = ? WHERE userId = ?");
        if ($updateStmt) {
            while ($row = $rows->fetch_assoc()) {
                $userId = (string)($row['userId'] ?? '');
                if ($userId === '') {
                    continue;
                }

                $rawRole = trim((string)($row['userRole'] ?? ''));
                $resolvedRole = '';

                if ($rawRole === '') {
                    $resolvedRole = 'user';
                } else {
                    $rawLower = strtolower($rawRole);
                    if (isset($labelToKey[$rawLower])) {
                        $resolvedRole = $labelToKey[$rawLower];
                    } else {
                        $normalizedRole = normalizeRoleKey($rawRole);
                        $resolvedRole = $normalizedRole !== '' ? $normalizedRole : 'user';
                    }
                }

                if (strtolower($rawRole) !== strtolower($resolvedRole)) {
                    $updateStmt->bind_param("ss", $resolvedRole, $userId);
                    $updateStmt->execute();
                }
            }
            $updateStmt->close();
        }
    }

    if (function_exists('setAppSetting')) {
        setAppSetting($conn, 'user_role_data_normalized_v2', '1');
    }

    $ensured = true;
}

function getRolePermissionOverride(mysqli $conn, string $roleKey, string $permissionKey): ?bool {
    ensureRoleGovernanceTables($conn);

    $roleKey = strtolower(trim($roleKey));
    if ($roleKey === '' || trim($permissionKey) === '') {
        return null;
    }

    static $cache = [];
    $cacheKey = $roleKey . '|' . $permissionKey;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare("
        SELECT is_allowed
        FROM tb_role_permissions
        WHERE role_key = ? AND permission_key = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ss", $roleKey, $permissionKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $cache[$cacheKey] = null;
        return null;
    }

    $value = ((int)($row['is_allowed'] ?? 0)) === 1;
    $cache[$cacheKey] = $value;
    return $value;
}

function getRolePermissionOverrides(mysqli $conn, string $roleKey): array {
    ensureRoleGovernanceTables($conn);
    $roleKey = strtolower(trim($roleKey));
    if ($roleKey === '') {
        return [];
    }

    $overrides = [];
    $stmt = $conn->prepare("
        SELECT permission_key, is_allowed, notes, updated_by, updated_at
        FROM tb_role_permissions
        WHERE role_key = ?
    ");
    if (!$stmt) {
        return $overrides;
    }
    $stmt->bind_param("s", $roleKey);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $permissionKey = (string)($row['permission_key'] ?? '');
        if ($permissionKey === '') {
            continue;
        }
        $overrides[$permissionKey] = [
            'is_allowed' => ((int)($row['is_allowed'] ?? 0)) === 1,
            'notes' => (string)($row['notes'] ?? ''),
            'updated_by' => (string)($row['updated_by'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    $stmt->close();
    return $overrides;
}

function setRolePermissionOverride(mysqli $conn, string $roleKey, string $permissionKey, ?bool $isAllowed, string $updatedBy = '', string $notes = ''): bool {
    $catalog = getPermissionCatalog();
    if (!isset($catalog[$permissionKey])) {
        return false;
    }

    ensureRoleGovernanceTables($conn);
    $roleKey = strtolower(trim($roleKey));
    if ($roleKey === '') {
        return false;
    }

    if ($isAllowed === null) {
        $deleteStmt = $conn->prepare("
            DELETE FROM tb_role_permissions
            WHERE role_key = ? AND permission_key = ?
        ");
        if (!$deleteStmt) {
            return false;
        }
        $deleteStmt->bind_param("ss", $roleKey, $permissionKey);
        $ok = $deleteStmt->execute();
        $deleteStmt->close();
        return $ok;
    }

    $allowed = $isAllowed ? 1 : 0;
    $updatedBy = trim($updatedBy);
    if ($updatedBy === '') {
        $updatedBy = null;
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_role_permissions (role_key, permission_key, is_allowed, notes, updated_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_allowed = VALUES(is_allowed),
            notes = VALUES(notes),
            updated_by = VALUES(updated_by),
            updated_at = NOW()
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssiss", $roleKey, $permissionKey, $allowed, $notes, $updatedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function ensureUserPermissionsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_user_permissions (
            permission_id int(11) NOT NULL AUTO_INCREMENT,
            user_id varchar(100) NOT NULL,
            permission_key varchar(120) NOT NULL,
            is_allowed tinyint(1) NOT NULL DEFAULT 1,
            notes text DEFAULT NULL,
            granted_by varchar(100) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (permission_id),
            UNIQUE KEY uniq_user_permission (user_id, permission_key),
            KEY idx_permission_key (permission_key),
            KEY idx_permission_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function getPermissionCatalog(): array {
    return [
        'registry.edit' => [
            'label' => 'Edit Pension Registry',
            'description' => 'Allow editing pension file registry records.',
            'default_roles' => ['admin', 'clerk', 'data_entry', 'writeup_officer']
        ],
        'staff_due.edit' => [
            'label' => 'Edit Staff Due Records',
            'description' => 'Allow creating and updating staff due for retirement records.',
            'default_roles' => ['admin', 'clerk', 'data_entry', 'writeup_officer']
        ],
        'staff_due.bulk_upload' => [
            'label' => 'Bulk Upload Staff Due',
            'description' => 'Allow bulk upload of staff due for retirement records from CSV or XLSX templates.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'data_entry']
        ],
        'registry.bulk_upload' => [
            'label' => 'Bulk Upload Pension Registry',
            'description' => 'Allow bulk upload of pension file registry records from CSV or XLSX templates.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'data_entry']
        ],
        'registry.life_certificate.submit' => [
            'label' => 'Submit Life Certificate',
            'description' => 'Allow recording life certificate submissions and beneficiary profile updates.',
            'default_roles' => ['admin', 'clerk', 'data_entry', 'oc_pen', 'writeup_officer']
        ],
        'registry.delete_request' => [
            'label' => 'Request Registry Delete',
            'description' => 'Allow submitting file registry deletion requests for approval.',
            'default_roles' => ['admin', 'clerk', 'data_entry', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'registry.delete_queue.process' => [
            'label' => 'Process Registry Delete Queue',
            'description' => 'Approve or reject registry deletion requests.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'staff_due.delete_request' => [
            'label' => 'Request Staff Due Delete',
            'description' => 'Allow submitting staff due deletion requests for approval.',
            'default_roles' => ['admin', 'clerk', 'data_entry', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'staff_due.delete_queue.process' => [
            'label' => 'Process Staff Due Delete Queue',
            'description' => 'Approve or reject staff due deletion requests.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'registry.benefits.monthly_salary.edit' => [
            'label' => 'Edit Monthly Salary',
            'description' => 'Allow editing monthly salary in Benefits Snapshot.',
            'default_roles' => ['admin', 'clerk', 'oc_pen', 'writeup_officer', 'file_creator', 'data_entry', 'assessor', 'auditor', 'approver', 'user']
        ],
        'registry.benefits.length_service.edit' => [
            'label' => 'Edit Length of Service',
            'description' => 'Allow editing length of service (months) in Benefits Snapshot.',
            'default_roles' => ['admin', 'assessor']
        ],
        'registry.benefits.amounts.edit' => [
            'label' => 'Edit Benefits Amounts',
            'description' => 'Allow editing annual salary, reduced/full pension, and commuted gratuity.',
            'default_roles' => ['admin', 'assessor']
        ],
        'file_movement.record' => [
            'label' => 'Record File Movement',
            'description' => 'Allow recording file movement out of registry.',
            'default_roles' => ['admin', 'clerk', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'writeup_officer', 'file_creator', 'data_entry', 'assessor', 'auditor', 'approver']
        ],
        'file_movement.return' => [
            'label' => 'Mark File Returned',
            'description' => 'Allow confirming a file has been received back into registry custody.',
            'default_roles' => ['admin', 'clerk', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'payroll.upload' => [
            'label' => 'Upload Payroll',
            'description' => 'Allow uploading monthly payroll cycles and payment register PDFs.',
            'default_roles' => ['admin', 'oc_pen', 'clerk']
        ],
        'payroll.manage' => [
            'label' => 'Manage Payroll Cycles',
            'description' => 'Allow deleting/replacing payroll cycles and changing payroll period.',
            'default_roles' => ['admin']
        ],
        'claims.arrears.view' => [
            'label' => 'View Claims & Arrears',
            'description' => 'Access arrears tracking, suspension reconciliation and claims analytics.',
            'default_roles' => ['admin', 'clerk', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'writeup_officer', 'file_creator', 'data_entry', 'assessor', 'auditor', 'approver', 'user']
        ],
        'claims.arrears.manage' => [
            'label' => 'Manage Arrears Ledger',
            'description' => 'Create arrears entries, record payments, and reconcile balances.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'data_entry']
        ],
        'claims.suspension.upload' => [
            'label' => 'Upload Suspension Records',
            'description' => 'Upload suspension records as saved amounts, reconcile them to registry files, and preserve row-level suspension reasons.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'data_entry']
        ],
        'feedback.view' => [
            'label' => 'View Feedback Inbox',
            'description' => 'Access dashboard feedback submissions, service signals, and analytics.',
            'default_roles' => ['admin', 'clerk', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'feedback.manage' => [
            'label' => 'Manage Feedback Workflow',
            'description' => 'Assign, review, resolve, close, and export feedback submissions.',
            'default_roles' => ['admin', 'clerk', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ],
        'public_chat.agent' => [
            'label' => 'Public Chat Agent',
            'description' => 'Accept and respond to public live support and pensioner correspondence chats.',
            'default_roles' => ['admin', 'oc_pen']
        ],
        'public_chat.supervise' => [
            'label' => 'Public Chat Supervisor',
            'description' => 'Assign, supervise, escalate, and close public live support conversations.',
            'default_roles' => ['admin', 'oc_pen']
        ],
        'budget.manage' => [
            'label' => 'Manage Budget Forecast',
            'description' => 'Create and update arrears and pension budget forecasts by financial year.',
            'default_roles' => ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension']
        ]
    ];
}

function roleHasDefaultPermission(mysqli $conn, string $role, string $permissionKey): bool {
    $catalog = getPermissionCatalog();
    if (!isset($catalog[$permissionKey])) {
        return false;
    }

    $rawRole = normalizeRoleKey($role);
    if ($rawRole === 'super_admin') {
        return true;
    }

    $roleNormalized = getEffectiveRoleKey($conn, $role);
    if ($roleNormalized === '') {
        return false;
    }
    if ($roleNormalized === 'super_admin') {
        return true;
    }

    $roleOverride = getRolePermissionOverride($conn, $roleNormalized, $permissionKey);
    if ($roleOverride !== null) {
        return $roleOverride;
    }

    return in_array($roleNormalized, $catalog[$permissionKey]['default_roles'], true);
}

function getUserPermissionOverride(mysqli $conn, string $userId, string $permissionKey): ?bool {
    ensureUserPermissionsTable($conn);

    static $cache = [];
    $cacheKey = $userId . '|' . $permissionKey;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $conn->prepare("
        SELECT is_allowed
        FROM tb_user_permissions
        WHERE user_id = ? AND permission_key = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ss", $userId, $permissionKey);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        $cache[$cacheKey] = null;
        return null;
    }

    $allowed = ((int)$row['is_allowed']) === 1;
    $cache[$cacheKey] = $allowed;
    return $allowed;
}

function getUserPermissionOverrides(mysqli $conn, string $userId): array {
    ensureUserPermissionsTable($conn);
    $overrides = [];
    $stmt = $conn->prepare("
        SELECT permission_key, is_allowed, notes, granted_by, updated_at
        FROM tb_user_permissions
        WHERE user_id = ?
    ");
    if (!$stmt) {
        return $overrides;
    }
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $key = (string)($row['permission_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $overrides[$key] = [
            'is_allowed' => ((int)($row['is_allowed'] ?? 0)) === 1,
            'notes' => (string)($row['notes'] ?? ''),
            'granted_by' => (string)($row['granted_by'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? '')
        ];
    }
    $stmt->close();
    return $overrides;
}

function getEffectiveUserPermission(mysqli $conn, string $userId, string $role, string $permissionKey): bool {
    $catalog = getPermissionCatalog();
    if (!isset($catalog[$permissionKey])) {
        return false;
    }

    $override = getUserPermissionOverride($conn, $userId, $permissionKey);
    if ($override !== null) {
        return $override;
    }

    $roleNormalized = normalizeRoleKey($role);
    $effectiveRole = getEffectiveRoleKey($conn, $roleNormalized);
    if ($roleNormalized !== '') {
        $roleOverride = getRolePermissionOverride($conn, $roleNormalized, $permissionKey);
        if ($roleOverride !== null) {
            return $roleOverride;
        }
    }
    if ($effectiveRole !== '' && $effectiveRole !== $roleNormalized) {
        $baseOverride = getRolePermissionOverride($conn, $effectiveRole, $permissionKey);
        if ($baseOverride !== null) {
            return $baseOverride;
        }
    }

    $roleForDefault = $effectiveRole !== '' ? $effectiveRole : $roleNormalized;
    return in_array($roleForDefault, $catalog[$permissionKey]['default_roles'] ?? [], true);
}

function getEffectivePermissionsForUser(mysqli $conn, string $userId, string $role, array $keys = []): array {
    $catalog = getPermissionCatalog();
    $selectedKeys = empty($keys) ? array_keys($catalog) : array_values(array_filter($keys, static function ($key) use ($catalog) {
        return isset($catalog[$key]);
    }));

    $permissions = [];
    foreach ($selectedKeys as $key) {
        $permissions[$key] = getEffectiveUserPermission($conn, $userId, $role, $key);
    }
    return $permissions;
}

function currentUserHasPermission(mysqli $conn, string $permissionKey): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $userId = (string)($_SESSION['userId'] ?? '');
    $role = (string)($_SESSION['userRole'] ?? '');
    if ($role === '') {
        $role = getSessionEffectiveRoleKey($conn);
    }
    if ($userId === '' || $role === '') {
        return false;
    }
    return getEffectiveUserPermission($conn, $userId, $role, $permissionKey);
}

function setUserPermissionOverride(mysqli $conn, string $userId, string $permissionKey, ?bool $isAllowed, string $grantedBy = '', string $notes = ''): bool {
    $catalog = getPermissionCatalog();
    if (!isset($catalog[$permissionKey])) {
        return false;
    }

    ensureUserPermissionsTable($conn);

    if ($isAllowed === null) {
        $deleteStmt = $conn->prepare("DELETE FROM tb_user_permissions WHERE user_id = ? AND permission_key = ?");
        if (!$deleteStmt) {
            return false;
        }
        $deleteStmt->bind_param("ss", $userId, $permissionKey);
        $ok = $deleteStmt->execute();
        $deleteStmt->close();
        return $ok;
    }

    $allowed = $isAllowed ? 1 : 0;
    $grantedBy = trim($grantedBy);
    if ($grantedBy === '') {
        $grantedBy = null;
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_user_permissions (user_id, permission_key, is_allowed, notes, granted_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_allowed = VALUES(is_allowed),
            notes = VALUES(notes),
            granted_by = VALUES(granted_by),
            updated_at = NOW()
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ssiss", $userId, $permissionKey, $allowed, $notes, $grantedBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function formatActivityLabel(string $type): string {
    $clean = str_replace('_', ' ', trim($type));
    return ucwords($clean);
}

function isWithinQuietHours(string $start, string $end): bool {
    $start = trim($start);
    $end = trim($end);
    if ($start === '' || $end === '' || $start === $end) {
        return false;
    }

    $now = date('H:i');
    if ($start < $end) {
        return ($now >= $start && $now <= $end);
    }

    return ($now >= $start || $now <= $end);
}

function dispatchSecurityNotification(mysqli $conn, array $logData): void {
    if (!getAppSettingBool($conn, 'enable_notifications', true)) {
        return;
    }

    $activityType = $logData['activity_type'] ?? '';
    $userId = $logData['user_id'] ?? '';
    if ($activityType === '' || $userId === 'system') {
        return;
    }

    $systemAlertTypes = [
        'login_failed',
        'device_conflict',
        'device_conflict_detected',
        'device_conflict_resolved',
        'session_termination_failed',
        'multiple_sessions_terminated',
        'auto_logout'
    ];

    $userActivityTypes = [
        'login',
        'logout',
        'session_started',
        'session_expiry'
    ];

    $category = null;
    if (in_array($activityType, $systemAlertTypes, true)) {
        $category = 'system';
    } elseif (in_array($activityType, $userActivityTypes, true)) {
        $category = 'activity';
    } else {
        return;
    }

    if ($category === 'system' && !getAppSettingBool($conn, 'notify_system_alerts_enabled', true)) {
        return;
    }
    if ($category === 'activity' && !getAppSettingBool($conn, 'notify_user_activity_enabled', true)) {
        return;
    }

    if ($category === 'activity') {
        $quietStart = getAppSettingString($conn, 'notify_quiet_hours_start', '');
        $quietEnd = getAppSettingString($conn, 'notify_quiet_hours_end', '');
        if (isWithinQuietHours($quietStart, $quietEnd)) {
            return;
        }
    }

    $emailEnabled = getAppSettingBool($conn, 'notify_email_enabled', true);
    $smsEnabled = getAppSettingBool($conn, 'notify_sms_enabled', false);
    $pushEnabled = getAppSettingBool($conn, 'notify_push_enabled', true);

    $appName = getAppSettingString($conn, 'app_name', 'PensionsGo');
    $label = formatActivityLabel($activityType);
    $subject = "[{$appName}] " . ($category === 'system' ? 'Security Alert' : 'User Activity') . ": {$label}";

    $details = $logData['details'] ?? '';
    if (is_array($details)) {
        $details = json_encode($details, JSON_UNESCAPED_SLASHES);
    }

    $meta = [
        'activity_type' => $activityType,
        'user' => $logData['user_name'] ?? '',
        'role' => $logData['user_role'] ?? '',
        'ip' => $logData['ip_address'] ?? '',
        'location' => $logData['location'] ?? '',
        'device' => $logData['device_type'] ?? '',
        'timestamp' => date('Y-m-d H:i:s'),
        'details' => $details
    ];

    $textBody = "Activity: {$label}\nUser: {$meta['user']} ({$meta['role']})\nIP: {$meta['ip']}\nLocation: {$meta['location']}\nDevice: {$meta['device']}\nTime: {$meta['timestamp']}\nDetails: {$meta['details']}";
    $htmlBody = "<h2>{$label}</h2><p><strong>User:</strong> " . htmlspecialchars($meta['user']) . " (" . htmlspecialchars($meta['role']) . ")</p>"
        . "<p><strong>IP:</strong> " . htmlspecialchars($meta['ip']) . "</p>"
        . "<p><strong>Location:</strong> " . htmlspecialchars($meta['location']) . "</p>"
        . "<p><strong>Device:</strong> " . htmlspecialchars($meta['device']) . "</p>"
        . "<p><strong>Time:</strong> " . htmlspecialchars($meta['timestamp']) . "</p>"
        . "<p><strong>Details:</strong> " . htmlspecialchars($meta['details']) . "</p>";

    $recipientEmail = trim(getAppSettingString($conn, 'security_alert_email', ''));
    if ($recipientEmail === '') {
        $recipientEmail = trim(getAppSettingString($conn, 'notify_test_recipient', ''));
    }
    $recipientSms = trim(getAppSettingString($conn, 'security_alert_sms', ''));

    $senderName = trim(getAppSettingString($conn, 'notify_sender_name', ''));
    $senderEmail = trim(getAppSettingString($conn, 'notify_sender_email', ''));
    $fromName = $senderName !== '' ? $senderName : null;
    $fromEmail = $senderEmail !== '' ? $senderEmail : null;

    if ($emailEnabled && $recipientEmail !== '') {
        queueNotification($conn, 'email', $recipientEmail, $subject, $textBody, array_merge($meta, [
            'html_body' => $htmlBody,
            'from_name' => $fromName,
            'from_email' => $fromEmail
        ]));
    }

    if ($smsEnabled && $recipientSms !== '') {
        queueNotification($conn, 'sms', $recipientSms, $subject, $textBody, $meta);
    }

    if ($pushEnabled) {
        queueNotification($conn, 'push', 'admin', $subject, $textBody, $meta);
    }
}

// 
// MAILER HELPERS (local MTA or PHP mail)
// 
function sendEmail(string $to, string $subject, string $htmlBody, string $textBody = '', ?string $fromName = null, ?string $fromEmail = null): bool
{
    $fromEmail = $fromEmail ?: MAIL_FROM_ADDRESS;
    $fromName = $fromName ?: MAIL_FROM_NAME;

    if (MAIL_TRANSPORT === 'smtp') {
        return sendSmtpMail(
            MAIL_SMTP_HOST,
            MAIL_SMTP_PORT,
            MAIL_SMTP_TIMEOUT,
            MAIL_SMTP_ENCRYPTION,
            MAIL_SMTP_USERNAME,
            MAIL_SMTP_PASSWORD,
            MAIL_SMTP_HELO_DOMAIN,
            $fromName,
            $fromEmail,
            $to,
            $subject,
            $htmlBody,
            $textBody
        );
    }

    $headers = buildEmailHeaders($fromName, $fromEmail, $htmlBody !== '');
    $message = $htmlBody !== '' ? $htmlBody : $textBody;
    return @mail($to, $subject, $message, $headers);
}

function buildEmailHeaders(string $fromName, string $fromEmail, bool $isHtml): string
{
    $from = $fromName !== '' ? "\"{$fromName}\" <{$fromEmail}>" : $fromEmail;
    $headers = [
        "From: {$from}",
        "MIME-Version: 1.0",
        $isHtml ? "Content-Type: text/html; charset=UTF-8" : "Content-Type: text/plain; charset=UTF-8"
    ];
    return implode("\r\n", $headers);
}

function sendSmtpMail(
    string $host,
    int $port,
    int $timeout,
    string $encryption,
    string $username,
    string $password,
    string $heloDomain,
    string $fromName,
    string $fromEmail,
    string $to,
    string $subject,
    string $htmlBody,
    string $textBody = ''
): bool {
    if ($host === '') {
        error_log('SMTP host is not configured.');
        return false;
    }

    $transport = strtolower(trim($encryption)) === 'ssl' ? 'ssl://' . $host : $host;
    $fp = @stream_socket_client($transport . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, $timeout);

    $read = function () use ($fp) {
        $data = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $data .= $line;
            if (preg_match('/^\d{3}\s/', $line)) break;
        }
        return $data;
    };

    $send = function ($command) use ($fp) {
        fwrite($fp, $command . "\r\n");
    };

    $expectOk = function ($response) {
        return (bool)preg_match('/^(2|3)\d{2}/', $response);
    };

    $sendCommand = function (string $command, array $expected = [250, 251, 235, 334, 354, 220, 221]) use ($send, $read) {
        $send($command);
        $response = $read();
        foreach ($expected as $code) {
            if (preg_match('/^' . preg_quote((string)$code, '/') . '\b/m', $response)) {
                return $response;
            }
        }
        return false;
    };

    $greeting = $read();
    if (!$expectOk($greeting)) {
        fclose($fp);
        return false;
    }

    $ehloHost = $heloDomain !== '' ? $heloDomain : 'localhost';
    $ehloResponse = $sendCommand("EHLO {$ehloHost}", [250]);
    if ($ehloResponse === false) {
        $heloResponse = $sendCommand("HELO {$ehloHost}", [250]);
        if ($heloResponse === false) {
            fclose($fp);
            return false;
        }
        $ehloResponse = '';
    }

    if (strtolower(trim($encryption)) === 'tls') {
        $tlsResponse = $sendCommand('STARTTLS', [220]);
        if ($tlsResponse === false) {
            error_log('SMTP server does not support STARTTLS.');
            fclose($fp);
            return false;
        }
        $cryptoEnabled = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            error_log('Unable to enable SMTP TLS encryption.');
            fclose($fp);
            return false;
        }
        $ehloResponse = $sendCommand("EHLO {$ehloHost}", [250]);
        if ($ehloResponse === false) {
            fclose($fp);
            return false;
        }
    }

    if ($username !== '') {
        $authResponse = $sendCommand('AUTH LOGIN', [334]);
        if ($authResponse === false) {
            error_log('SMTP AUTH LOGIN is not available on the server.');
            fclose($fp);
            return false;
        }
        $userResponse = $sendCommand(base64_encode($username), [334]);
        if ($userResponse === false) {
            error_log('SMTP username was rejected.');
            fclose($fp);
            return false;
        }
        $passResponse = $sendCommand(base64_encode($password), [235]);
        if ($passResponse === false) {
            error_log('SMTP password was rejected.');
            fclose($fp);
            return false;
        }
    }

    if ($sendCommand("MAIL FROM:<{$fromEmail}>", [250]) === false) { fclose($fp); return false; }

    if ($sendCommand("RCPT TO:<{$to}>", [250, 251]) === false) { fclose($fp); return false; }

    if ($sendCommand('DATA', [354]) === false) { fclose($fp); return false; }

    $fromHeader = $fromName !== '' ? "\"{$fromName}\" <{$fromEmail}>" : $fromEmail;
    $contentType = $htmlBody !== '' ? 'text/html' : 'text/plain';
    $body = $htmlBody !== '' ? $htmlBody : $textBody;
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body);
    $body = str_replace("\n", "\r\n", $body);
    $headers = [
        "From: {$fromHeader}",
        "To: {$to}",
        "Subject: {$subject}",
        "Date: " . date('r'),
        "MIME-Version: 1.0",
        "Content-Type: {$contentType}; charset=UTF-8"
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    fwrite($fp, $message . "\r\n.\r\n");
    $dataResponse = $read();

    $send('QUIT');
    fclose($fp);

    return $expectOk($dataResponse);
}

/**
 * Get client IP address
 */
function getClientIP() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) return $_SERVER['HTTP_X_REAL_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(",", $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    return $_SERVER['REMOTE_ADDR'] ?? "127.0.0.1";
}

/**
 * Accept only the browser-generated token format used for stable device identity.
 */
function normalizeDeviceToken(?string $token): ?string {
    $normalized = strtolower(trim((string)$token));
    if ($normalized === '') {
        return null;
    }

    return preg_match('/^[a-f0-9]{64}$/', $normalized) ? $normalized : null;
}

/**
 * Read the client device token from form submissions or authenticated AJAX headers.
 */
function getRequestDeviceToken(): ?string {
    $token = $_POST['device_token'] ?? ($_SERVER['HTTP_X_DEVICE_TOKEN'] ?? null);
    return normalizeDeviceToken($token);
}

/**
 * Legacy device identifier retained only to migrate old sessions cleanly.
 */
function getLegacyDeviceIdentifierHash(): string {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $userAgent . '|' . getClientIP());
}

/**
 * Hash the browser token before it is stored server-side.
 */
function resolveClientDeviceIdentifierHash(?string $token = null): string {
    $normalized = normalizeDeviceToken($token ?? getRequestDeviceToken());
    if ($normalized !== null) {
        return hash('sha256', $normalized);
    }

    return getLegacyDeviceIdentifierHash();
}

/**
 * Read the stored device binding for the current session from memory or database.
 */
function getStoredSessionDeviceIdentifier(mysqli $conn, string $sessionId, string $userId): ?string {
    $sessionDeviceId = $_SESSION['device_id'] ?? null;
    if (is_string($sessionDeviceId) && $sessionDeviceId !== '') {
        return $sessionDeviceId;
    }

    $stmt = $conn->prepare("
        SELECT device_id
        FROM tb_user_sessions
        WHERE session_id = ? AND user_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $sessionId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row['device_id'] ?? null;
}

/**
 * Upgrade an older IP-based session binding to the newer browser token binding.
 */
function migrateLegacySessionDeviceBinding(mysqli $conn, string $sessionId, string $userId, string $newDeviceId): bool {
    $legacyDeviceId = getLegacyDeviceIdentifierHash();
    if (hash_equals($legacyDeviceId, $newDeviceId)) {
        return false;
    }

    $stmt = $conn->prepare("
        UPDATE tb_user_sessions
        SET device_id = ?
        WHERE session_id = ?
          AND user_id = ?
          AND is_active = 1
          AND device_id = ?
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssss', $newDeviceId, $sessionId, $userId, $legacyDeviceId);
    $stmt->execute();
    $migrated = $stmt->affected_rows > 0;
    $stmt->close();

    if ($migrated) {
        $_SESSION['device_id'] = $newDeviceId;
    }

    return $migrated;
}

/**
 * Confirm that the active PHP session is being used from the bound browser token.
 */
function validateSessionDeviceBinding(mysqli $conn, string $sessionId, string $userId, ?string &$resolvedDeviceId = null): bool {
    $requestToken = getRequestDeviceToken();
    if ($requestToken === null) {
        $resolvedDeviceId = getStoredSessionDeviceIdentifier($conn, $sessionId, $userId) ?? getLegacyDeviceIdentifierHash();
        return true;
    }

    $requestDeviceId = resolveClientDeviceIdentifierHash($requestToken);
    $storedDeviceId = getStoredSessionDeviceIdentifier($conn, $sessionId, $userId);

    if ($storedDeviceId === null || $storedDeviceId === '') {
        $resolvedDeviceId = $requestDeviceId;
        return true;
    }

    if (hash_equals($storedDeviceId, $requestDeviceId)) {
        $resolvedDeviceId = $requestDeviceId;
        return true;
    }

    if (hash_equals($storedDeviceId, getLegacyDeviceIdentifierHash())
        && migrateLegacySessionDeviceBinding($conn, $sessionId, $userId, $requestDeviceId)) {
        $resolvedDeviceId = $requestDeviceId;
        return true;
    }

    $resolvedDeviceId = $requestDeviceId;
    return false;
}

/**
 * Log user activity
 */
function logUserActivity($conn, $logData) {
    if (function_exists('getAppSetting')) {
        $enabledRaw = getAppSetting($conn, 'enable_activity_logs');
        $enabledFlag = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled = ($enabledFlag === null) ? ($enabledRaw === '1') : (bool)$enabledFlag;
        if (!$enabled) {
            return false;
        }
    }

    $activityMap = [
        'login_success' => 'login',
        'sign_in' => 'login',
        'sign_out' => 'logout',
        'session_timeout' => 'session_expiry',
        'timeout' => 'session_expiry',
        'device_conflict' => 'device_conflict',
        'device_conflict_detected' => 'device_conflict_detected',
        'device_conflict_resolved' => 'device_conflict_resolved',
        'multiple_sessions_terminated' => 'multiple_sessions_terminated',
        'auto_logout' => 'auto_logout',
        'session_expiry' => 'session_expiry',
        'session_cleanup' => 'session_cleanup',
        'session_start' => 'session_started',
        'session_started' => 'session_started',
        'session_termination_failed' => 'session_termination_failed',
        'login_failed' => 'login_failed',
        'logout' => 'logout',
        'login' => 'login'
    ];

    $rawType = $logData['activity_type'] ?? 'session_cleanup';
    $logData['activity_type'] = $activityMap[$rawType] ?? 'session_cleanup';
    
    if (empty($logData['location'])) {
        $ipForLocation = $logData['ip_address'] ?? getClientIP();
        $logData['location'] = getLocationFromIP($ipForLocation);
    }

    try {
        $stmt = $conn->prepare("
            INSERT INTO tb_user_logs
            (user_id, user_name, user_role, activity_type, ip_address, user_agent,
             device_type, location, session_id, details, logout_time, duration_seconds)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 0)
        ");

        if (!$stmt) {
            error_log("logUserActivity() prepare failed: " . $conn->error);
            return false;
        }

        $details = is_array($logData['details'])
            ? json_encode($logData['details'], JSON_UNESCAPED_SLASHES)
            : $logData['details'];

        $stmt->bind_param(
            "ssssssssss",
            $logData['user_id'],
            $logData['user_name'],
            $logData['user_role'],
            $logData['activity_type'],
            $logData['ip_address'],
            $logData['user_agent'],
            $logData['device_type'],
            $logData['location'],
            $logData['session_id'],
            $details
        );

        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        try {
            dispatchSecurityNotification($conn, $logData);
        } catch (Throwable $notifyError) {
            error_log("Notification dispatch failed: " . $notifyError->getMessage());
        }

        return $id;

    } catch (Throwable $e) {
        error_log("Logging failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Log session start
 */
function logSessionStart($conn, $userId, $userName, $userRole, $sessionId) {
    if (function_exists('getAppSetting')) {
        $enabledRaw = getAppSetting($conn, 'enable_activity_logs');
        $enabledFlag = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled = ($enabledFlag === null) ? ($enabledRaw === '1') : (bool)$enabledFlag;
        if (!$enabled) {
            return;
        }
    }

    $ip = getClientIP();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $device = detectDeviceType($ua);
    $loc = getLocationFromIP($ip);

    $stmt = $conn->prepare("
        INSERT INTO tb_user_logs
        (user_id, user_name, user_role, activity_type, ip_address, user_agent,
         device_type, location, session_id, details, logout_time, duration_seconds)
        VALUES (?, ?, ?, 'session_started', ?, ?, ?, ?, ?, 'Session Started', NULL, 0)
    ");

    $stmt->bind_param("ssssssss",
        $userId, $userName, $userRole,
        $ip, $ua, $device, $loc, $sessionId
    );

    $stmt->execute();
    $stmt->close();
}

/**
 * Log session end
 */
function logSessionEnd($conn, $userId, $sessionId, $activityType, $reason = '') {
    // Update the existing LOGIN row
    $stmt = $conn->prepare("
        UPDATE tb_user_logs
        SET logout_time = NOW(),
            duration_seconds = TIMESTAMPDIFF(SECOND, login_time, NOW()),
            activity_type = ?
        WHERE user_id = ? 
          AND session_id = ?
          AND logout_time IS NULL
        ORDER BY log_id DESC
        LIMIT 1
    ");

    $stmt->bind_param("sss", $activityType, $userId, $sessionId);
    $stmt->execute();
    $stmt->close();

    // Also add a metadata row
    logUserActivity($conn, [
        'user_id' => $userId,
        'user_name' => $_SESSION['userName'] ?? 'Unknown',
        'user_role' => $_SESSION['userRole'] ?? 'guest',
        'activity_type' => $activityType,
        'ip_address' => getClientIP(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'location' => getLocationFromIP(getClientIP()),
        'session_id' => $sessionId,
        'details' => $reason
    ]);
}

/**
 * Detect device type from user agent
 */
function detectDeviceType($ua) {
    $ua = strtolower($ua);
    if (str_contains($ua, "mobile")) return "Mobile";
    if (str_contains($ua, "android")) return "Android";
    if (str_contains($ua, "iphone")) return "iPhone";
    if (str_contains($ua, "ipad")) return "iPad";
    if (str_contains($ua, "windows")) return "Windows PC";
    if (str_contains($ua, "macintosh")) return "Mac";
    if (str_contains($ua, "linux")) return "Linux";
    return "Unknown Device";
}

/**
 * Get location from IP address
 */
function getLocationFromIP($ip) {
    static $memoryCache = [];
    
    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return "Unknown Location";
    }
    
    if ($ip === "127.0.0.1" || $ip === "::1") {
        return "Local Development Environment";
    }
    
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return "Private Network";
    }
    
    if (isset($memoryCache[$ip]) && $memoryCache[$ip]['expires'] > time()) {
        return $memoryCache[$ip]['location'];
    }
    
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        ensureGeoipCacheTable($conn);
        
        $stmt = $conn->prepare("
            SELECT location_label, UNIX_TIMESTAMP(last_lookup) AS last_lookup
            FROM tb_ip_geolocation
            WHERE ip_address = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $ip);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                $lastLookup = (int)($row['last_lookup'] ?? 0);
                if ($lastLookup > 0 && (time() - $lastLookup) <= GEOIP_CACHE_TTL) {
                    $memoryCache[$ip] = [
                        'location' => $row['location_label'],
                        'expires' => time() + GEOIP_CACHE_TTL
                    ];
                    return $row['location_label'];
                }

                if (!isGeoipEnabled() && !empty($row['location_label'])) {
                    return $row['location_label'];
                }
            } else {
                $stmt->close();
            }
        }
    }
    
    if (!isGeoipEnabled()) {
        return "Geolocation disabled";
    }
    
    $geoData = fetchGeoData($ip);
    if (!$geoData) {
        return "Unknown Location";
    }
    
    $locationLabel = buildLocationLabel(
        $geoData['city'] ?? '',
        $geoData['region'] ?? '',
        $geoData['country'] ?? '',
        $geoData['country_code'] ?? ''
    );
    
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("
            INSERT INTO tb_ip_geolocation
            (ip_address, city, region, country, country_code, latitude, longitude, timezone, org, asn, location_label, raw_json, last_lookup)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                city = VALUES(city),
                region = VALUES(region),
                country = VALUES(country),
                country_code = VALUES(country_code),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                timezone = VALUES(timezone),
                org = VALUES(org),
                asn = VALUES(asn),
                location_label = VALUES(location_label),
                raw_json = VALUES(raw_json),
                last_lookup = NOW()
        ");
        if ($stmt) {
            $rawJson = json_encode($geoData, JSON_UNESCAPED_SLASHES);
            $city = $geoData['city'] ?? null;
            $region = $geoData['region'] ?? null;
            $country = $geoData['country'] ?? null;
            $countryCode = $geoData['country_code'] ?? null;
            $latitude = $geoData['latitude'] ?? null;
            $longitude = $geoData['longitude'] ?? null;
            $timezone = $geoData['timezone'] ?? null;
            $org = $geoData['org'] ?? null;
            $asn = $geoData['asn'] ?? null;
            
            $stmt->bind_param(
                "ssssssssssss",
                $ip,
                $city,
                $region,
                $country,
                $countryCode,
                $latitude,
                $longitude,
                $timezone,
                $org,
                $asn,
                $locationLabel,
                $rawJson
            );
            $stmt->execute();
            $stmt->close();
        }
    }
    
    $memoryCache[$ip] = [
        'location' => $locationLabel,
        'expires' => time() + GEOIP_CACHE_TTL
    ];
    
    return $locationLabel;
}

/**
 * Ensure tb_user_logs.activity_type enum supports session_started
 */
function ensureUserLogsActivityEnum(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $result = $conn->query("SHOW COLUMNS FROM tb_user_logs LIKE 'activity_type'");
    if (!$result) {
        return;
    }
    $column = $result->fetch_assoc();
    $result->close();

    if (!$column || empty($column['Type'])) {
        return;
    }

    $type = $column['Type'];
    if (stripos($type, 'session_started') !== false) {
        return;
    }

    if (!preg_match("/^enum\\((.*)\\)$/i", $type, $matches)) {
        return;
    }

    $values = str_getcsv($matches[1], ',', "'");
    if (!in_array('session_started', $values, true)) {
        $values[] = 'session_started';
    }

    $escaped = array_map(function ($value) use ($conn) {
        return "'" . $conn->real_escape_string($value) . "'";
    }, $values);
    $enumList = implode(',', $escaped);

    $conn->query("ALTER TABLE tb_user_logs MODIFY activity_type ENUM($enumList) NOT NULL");
}

/**
 * Get detailed geolocation info for an IP
 */
function getGeoLocationDetails(string $ip, bool $lookupIfMissing = true): array {
    $details = [
        'city' => null,
        'region' => null,
        'country' => null,
        'country_code' => null,
        'location_label' => null,
        'source' => 'none'
    ];

    if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $details;
    }

    if ($ip === "127.0.0.1" || $ip === "::1") {
        $details['location_label'] = "Local Development Environment";
        $details['source'] = 'local';
        return $details;
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $details['location_label'] = "Private Network";
        $details['source'] = 'private';
        return $details;
    }

    $conn = $GLOBALS['conn'] ?? null;
    if ($conn instanceof mysqli) {
        ensureGeoipCacheTable($conn);
        $stmt = $conn->prepare("
            SELECT city, region, country, country_code, location_label,
                   UNIX_TIMESTAMP(last_lookup) AS last_lookup
            FROM tb_ip_geolocation
            WHERE ip_address = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("s", $ip);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $details['city'] = $row['city'] ?? null;
                $details['region'] = $row['region'] ?? null;
                $details['country'] = $row['country'] ?? null;
                $details['country_code'] = $row['country_code'] ?? null;
                $details['location_label'] = $row['location_label'] ?? null;
                $details['source'] = 'cache';

                $lastLookup = (int)($row['last_lookup'] ?? 0);
                $stmt->close();

                if (!$lookupIfMissing) {
                    return $details;
                }

                if ($lastLookup > 0 && (time() - $lastLookup) <= GEOIP_CACHE_TTL) {
                    return $details;
                }
            } else {
                $stmt->close();
            }
        }
    }

    if (!$lookupIfMissing || !isGeoipEnabled()) {
        $details['location_label'] = $details['location_label'] ?? "Geolocation disabled";
        return $details;
    }

    $geoData = fetchGeoData($ip);
    if (!$geoData) {
        return $details;
    }

    $locationLabel = buildLocationLabel(
        $geoData['city'] ?? '',
        $geoData['region'] ?? '',
        $geoData['country'] ?? '',
        $geoData['country_code'] ?? ''
    );

    $details['city'] = $geoData['city'] ?? null;
    $details['region'] = $geoData['region'] ?? null;
    $details['country'] = $geoData['country'] ?? null;
    $details['country_code'] = $geoData['country_code'] ?? null;
    $details['location_label'] = $locationLabel;
    $details['source'] = 'remote';

    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("
            INSERT INTO tb_ip_geolocation
            (ip_address, city, region, country, country_code, latitude, longitude, timezone, org, asn, location_label, raw_json, last_lookup)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                city = VALUES(city),
                region = VALUES(region),
                country = VALUES(country),
                country_code = VALUES(country_code),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                timezone = VALUES(timezone),
                org = VALUES(org),
                asn = VALUES(asn),
                location_label = VALUES(location_label),
                raw_json = VALUES(raw_json),
                last_lookup = NOW()
        ");
        if ($stmt) {
            $rawJson = json_encode($geoData, JSON_UNESCAPED_SLASHES);
            $city = $geoData['city'] ?? null;
            $region = $geoData['region'] ?? null;
            $country = $geoData['country'] ?? null;
            $countryCode = $geoData['country_code'] ?? null;
            $latitude = $geoData['latitude'] ?? null;
            $longitude = $geoData['longitude'] ?? null;
            $timezone = $geoData['timezone'] ?? null;
            $org = $geoData['org'] ?? null;
            $asn = $geoData['asn'] ?? null;

            $stmt->bind_param(
                "ssssssssssss",
                $ip,
                $city,
                $region,
                $country,
                $countryCode,
                $latitude,
                $longitude,
                $timezone,
                $org,
                $asn,
                $locationLabel,
                $rawJson
            );
            $stmt->execute();
            $stmt->close();
        }
    }

    return $details;
}

function ensureGeoipCacheTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }
    
    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_ip_geolocation (
            ip_address varchar(45) NOT NULL,
            city varchar(100) DEFAULT NULL,
            region varchar(100) DEFAULT NULL,
            country varchar(100) DEFAULT NULL,
            country_code varchar(10) DEFAULT NULL,
            latitude decimal(10,6) DEFAULT NULL,
            longitude decimal(10,6) DEFAULT NULL,
            timezone varchar(50) DEFAULT NULL,
            org varchar(150) DEFAULT NULL,
            asn varchar(50) DEFAULT NULL,
            location_label varchar(255) DEFAULT NULL,
            raw_json text DEFAULT NULL,
            last_lookup timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (ip_address),
            KEY idx_last_lookup (last_lookup)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Align collation with tb_user_logs to avoid join collation errors
    $conn->query("ALTER TABLE tb_ip_geolocation CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    $created = true;
}

function fetchGeoData(string $ip): ?array {
    $provider = strtolower(GEOIP_PROVIDER);
    
    switch ($provider) {
        case 'ipinfo':
            return fetchGeoDataIpinfo($ip);
        case 'ipapi':
        default:
            return fetchGeoDataIpapi($ip);
    }
}

function fetchGeoDataIpapi(string $ip): ?array {
    $url = "https://ipapi.co/" . urlencode($ip) . "/json/";
    $json = geoHttpGet($url);
    if (!$json || empty($json['ip']) || !empty($json['error'])) {
        return null;
    }
    
    return [
        'ip' => $json['ip'] ?? $ip,
        'city' => $json['city'] ?? '',
        'region' => $json['region'] ?? '',
        'country' => $json['country_name'] ?? '',
        'country_code' => $json['country_code'] ?? '',
        'latitude' => isset($json['latitude']) ? (float)$json['latitude'] : null,
        'longitude' => isset($json['longitude']) ? (float)$json['longitude'] : null,
        'timezone' => $json['timezone'] ?? '',
        'org' => $json['org'] ?? '',
        'asn' => $json['asn'] ?? ''
    ];
}

function fetchGeoDataIpinfo(string $ip): ?array {
    $token = trim(GEOIP_API_KEY);
    $url = "https://ipinfo.io/" . urlencode($ip) . "/json";
    if (!empty($token)) {
        $url .= "?token=" . urlencode($token);
    }
    
    $json = geoHttpGet($url);
    if (!$json || empty($json['ip'])) {
        return null;
    }
    
    $loc = isset($json['loc']) ? explode(',', $json['loc']) : [];
    $lat = isset($loc[0]) ? (float)$loc[0] : null;
    $lon = isset($loc[1]) ? (float)$loc[1] : null;
    
    return [
        'ip' => $json['ip'] ?? $ip,
        'city' => $json['city'] ?? '',
        'region' => $json['region'] ?? '',
        'country' => $json['country'] ?? '',
        'country_code' => $json['country'] ?? '',
        'latitude' => $lat,
        'longitude' => $lon,
        'timezone' => $json['timezone'] ?? '',
        'org' => $json['org'] ?? '',
        'asn' => $json['asn'] ?? ''
    ];
}

function geoHttpGet(string $url): ?array {
    $timeout = GEOIP_TIMEOUT_SECONDS;
    $response = null;
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'PensionApp/1.0');
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "User-Agent: PensionApp/1.0\r\n"
            ]
        ]);
        $response = @file_get_contents($url, false, $context);
    }
    
    if (!$response) {
        return null;
    }
    
    $json = json_decode($response, true);
    if (!is_array($json)) {
        return null;
    }
    
    return $json;
}

function buildLocationLabel(string $city, string $region, string $country, string $countryCode): string {
    $parts = [];
    if (!empty($city)) $parts[] = $city;
    if (!empty($region)) $parts[] = $region;
    if (!empty($country)) $parts[] = $country;
    
    if (empty($parts) && !empty($countryCode)) {
        return $countryCode;
    }
    
    if (empty($parts)) {
        return "Unknown Location";
    }
    
    return implode(', ', $parts);
}

/* STAFF DUE WORKFLOW + REFERENCE DATA */
function ensureStaffDueWorkflowColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'submission_at' => "DATETIME NULL DEFAULT NULL",
        'submission_by' => "VARCHAR(100) NULL DEFAULT NULL",
        'appn_status_at' => "DATETIME NULL DEFAULT NULL",
        'appn_status_by' => "VARCHAR(100) NULL DEFAULT NULL",
        'appn_status_reason' => "TEXT NULL DEFAULT NULL"
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_staffdue LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_staffdue ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}

function ensureStaffDueExtendedColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'address' => "TEXT DEFAULT NULL",
        'TIN' => "VARCHAR(50) DEFAULT NULL",
        'livingStatus' => "ENUM('Alive','Deceased') DEFAULT NULL",
        'payType' => "VARCHAR(80) DEFAULT NULL",
        'next_of_kin' => "VARCHAR(120) DEFAULT NULL",
        'next_of_kin_contact' => "VARCHAR(50) DEFAULT NULL",
        'bank_name' => "VARCHAR(120) DEFAULT NULL",
        'bank_account' => "VARCHAR(80) DEFAULT NULL",
        'bank_branch' => "VARCHAR(120) DEFAULT NULL",
        'applicant_email' => "VARCHAR(120) DEFAULT NULL",
        'documents_uploaded' => "TINYINT(1) DEFAULT 0"
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_staffdue LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_staffdue ADD COLUMN {$column} {$definition}");
        }
    }

    $checked = true;
}

function ensureStaffDueBaseColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'regNo' => "VARCHAR(50) DEFAULT NULL",
        'computerNo' => "VARCHAR(50) DEFAULT NULL",
        'title' => "VARCHAR(120) DEFAULT NULL",
        'sName' => "VARCHAR(120) DEFAULT NULL",
        'fName' => "VARCHAR(120) DEFAULT NULL",
        'prisonUnit' => "VARCHAR(120) DEFAULT NULL",
        'NIN' => "VARCHAR(50) DEFAULT NULL",
        'gender' => "VARCHAR(20) DEFAULT NULL",
        'telNo' => "VARCHAR(50) DEFAULT NULL",
        'birthDate' => "DATE DEFAULT NULL",
        'enlistmentDate' => "DATE DEFAULT NULL",
        'retirementDate' => "DATE DEFAULT NULL",
        'financialYear' => "VARCHAR(20) DEFAULT NULL",
        'retirementType' => "VARCHAR(60) DEFAULT NULL",
        'monthlySalary' => "DECIMAL(15,2) DEFAULT NULL",
        'lengthOfService' => "VARCHAR(50) DEFAULT NULL",
        'annualSalary' => "DECIMAL(15,2) DEFAULT NULL",
        'reducedPension' => "DECIMAL(15,2) DEFAULT NULL",
        'fullPension' => "DECIMAL(15,2) DEFAULT NULL",
        'gratuity' => "DECIMAL(15,2) DEFAULT NULL",
        'submissionStatus' => "VARCHAR(30) DEFAULT NULL",
        'appnStatus' => "VARCHAR(30) DEFAULT NULL",
        'timeStamp' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP()"
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_staffdue LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_staffdue ADD COLUMN {$column} {$definition}");
        }
    }

    $hasLegacySupplierColumn = false;
    $legacyColumnMeta = $conn->query("SHOW COLUMNS FROM tb_staffdue LIKE 'supplierNo'");
    if ($legacyColumnMeta && $legacyColumnMeta->num_rows > 0) {
        $hasLegacySupplierColumn = true;
    }
    if ($legacyColumnMeta instanceof mysqli_result) {
        $legacyColumnMeta->close();
    }

    if ($hasLegacySupplierColumn) {
        $conn->query("
            UPDATE tb_staffdue
            SET computerNo = supplierNo
            WHERE COALESCE(TRIM(computerNo), '') = ''
              AND COALESCE(TRIM(supplierNo), '') <> ''
        ");
    }

    $retirementTypeMeta = $conn->query("SHOW COLUMNS FROM tb_staffdue LIKE 'retirementType'");
    if ($retirementTypeMeta && $retirementTypeMeta->num_rows > 0) {
        $retirementTypeColumn = $retirementTypeMeta->fetch_assoc();
        $columnType = strtolower((string)($retirementTypeColumn['Type'] ?? ''));
        if ($columnType === '' || strncmp($columnType, 'varchar(60)', 11) !== 0) {
            $conn->query("ALTER TABLE tb_staffdue MODIFY COLUMN retirementType VARCHAR(60) DEFAULT NULL");
        }
        $retirementTypeMeta->close();
    }

    $checked = true;
}

function ensureStaffDueSoftDeleteColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [
        'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
        'deleted_at' => "DATETIME DEFAULT NULL",
        'deleted_by' => "VARCHAR(100) DEFAULT NULL",
        'deleted_by_name' => "VARCHAR(100) DEFAULT NULL",
        'deleted_by_role' => "VARCHAR(50) DEFAULT NULL",
        'delete_reason' => "TEXT DEFAULT NULL"
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_staffdue LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_staffdue ADD COLUMN {$column} {$definition}");
        }
    }

    $indexResult = $conn->query("SHOW INDEX FROM tb_staffdue WHERE Key_name = 'idx_staffdue_is_deleted'");
    if ($indexResult && $indexResult->num_rows === 0) {
        $conn->query("ALTER TABLE tb_staffdue ADD KEY idx_staffdue_is_deleted (is_deleted)");
    }

    $checked = true;
}

function generateAvailablePensionerEmail(mysqli $conn, string $regNo, int $staffId, string $preferredEmail = ''): string {
    $preferred = strtolower(trim($preferredEmail));
    $base = strtolower(preg_replace('/[^a-z0-9]+/', '.', $regNo));
    $base = trim($base, '.');
    if ($base === '') {
        $base = 'staff' . $staffId;
    }

    $domain = 'pensionsgo.local';
    $candidate = "pensioner.{$base}@{$domain}";
    $emailLocal = "pensioner.{$base}";
    if ($preferred !== '' && filter_var($preferred, FILTER_VALIDATE_EMAIL)) {
        $parts = explode('@', $preferred, 2);
        if (count($parts) === 2) {
            $preferredLocal = trim($parts[0]);
            $preferredDomain = trim($parts[1]);
            if ($preferredLocal !== '' && $preferredDomain !== '') {
                $emailLocal = $preferredLocal;
                $domain = $preferredDomain;
                $candidate = strtolower($preferred);
            }
        }
    }

    $probe = $conn->prepare("SELECT Id FROM tb_users WHERE LOWER(COALESCE(userEmail, '')) = ? LIMIT 1");
    if (!$probe) {
        return $candidate;
    }

    $counter = 1;
    while ($counter <= 1000) {
        $lookup = strtolower($candidate);
        $probe->bind_param('s', $lookup);
        $probe->execute();
        $row = $probe->get_result()->fetch_assoc();
        if (!$row) {
            $probe->close();
            return $candidate;
        }

        $candidate = "{$emailLocal}.{$counter}@{$domain}";
        $counter++;
    }

    $probe->close();
    return "{$emailLocal}." . time() . "@{$domain}";
}

function generateAvailablePensionerPhone(mysqli $conn, string $regNo, int $staffId, string $preferredPhone = ''): string {
    $digits = preg_replace('/\D+/', '', $regNo);
    if ($digits === '') {
        $digits = (string)$staffId;
    }
    $digits = str_pad(substr($digits, -8), 8, '0', STR_PAD_LEFT);

    $probe = $conn->prepare("SELECT Id FROM tb_users WHERE phoneNo = ? LIMIT 1");
    if (!$probe) {
        $normalized = normalizePhoneNumber('07' . $digits);
        return $normalized !== null ? $normalized : '+2567' . $digits;
    }

    $preferredNormalized = $preferredPhone !== '' ? normalizePhoneNumber($preferredPhone) : null;
    if ($preferredNormalized !== null) {
        $probe->bind_param('s', $preferredNormalized);
        $probe->execute();
        $row = $probe->get_result()->fetch_assoc();
        if (!$row) {
            $probe->close();
            return $preferredNormalized;
        }
    }

    $counter = 0;
    while ($counter < 1000) {
        $suffix = str_pad((string)$counter, 3, '0', STR_PAD_LEFT);
        $candidateLocal = '07' . substr($digits, 0, 5) . $suffix;
        $normalized = normalizePhoneNumber($candidateLocal);
        if ($normalized === null) {
            $counter++;
            continue;
        }

        $probe->bind_param('s', $normalized);
        $probe->execute();
        $row = $probe->get_result()->fetch_assoc();
        if (!$row) {
            $probe->close();
            return $normalized;
        }

        $counter++;
    }

    $probe->close();
    $fallback = normalizePhoneNumber('07' . $digits);
    return $fallback !== null ? $fallback : '+2567' . $digits;
}

function findExistingPensionerUserByContacts(mysqli $conn, string $email, string $phone): ?array {
    if ($email !== '') {
        $stmt = $conn->prepare("
            SELECT Id, userId, userEmail, phoneNo, userRole
            FROM tb_users
            WHERE LOWER(COALESCE(userEmail, '')) = ?
            LIMIT 1
        ");
        if ($stmt) {
            $lookup = strtolower($email);
            $stmt->bind_param('s', $lookup);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return $row;
            }
        }
    }

    if ($phone !== '') {
        $candidates = buildPhoneLookupCandidates($phone);
        $stmt = $conn->prepare("
            SELECT Id, userId, userEmail, phoneNo, userRole
            FROM tb_users
            WHERE phoneNo = ?
            LIMIT 1
        ");
        if ($stmt) {
            foreach ($candidates as $candidate) {
                $stmt->bind_param('s', $candidate);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $stmt->close();
                    return $row;
                }
            }
            $stmt->close();
        }
    }

    return null;
}

function findExistingPensionerUserByStaffMeta(mysqli $conn, int $staffId, string $regNo): ?array {
    $patterns = [];
    if ($staffId > 0) {
        $patterns[] = '%"staffdue_id":' . $staffId . '%';
    }
    $regNo = trim($regNo);
    if ($regNo !== '') {
        $patterns[] = '%"regNo":"' . $regNo . '"%';
    }
    if (empty($patterns)) {
        return null;
    }

    $clauses = array_fill(0, count($patterns), "other LIKE ?");
    $sql = "
        SELECT Id, userId, userEmail, phoneNo, userRole
        FROM tb_users
        WHERE LOWER(COALESCE(userRole, '')) = 'pensioner'
          AND (" . implode(' OR ', $clauses) . ")
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $types = str_repeat('s', count($patterns));
    $bind = [$types];
    foreach ($patterns as $index => $pattern) {
        $bind[] = &$patterns[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function upsertPensionerUserFromStaffDue(mysqli $conn, int $staffId, string $defaultPassword = 'Pensioner123', ?string $actorId = null): array {
    ensureStaffDueExtendedColumns($conn);
    ensureRoleGovernanceTables($conn);
    ensureUserPasswordUpdatedAtColumn($conn);

    $roleKey = resolveRoleKeyFromInput($conn, 'pensioner', true);
    if ($roleKey === '') {
        $roleKey = 'pensioner';
    }

    $stmt = $conn->prepare("
        SELECT id, regNo, title, sName, fName, telNo, applicant_email
        FROM tb_staffdue
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to read staff record for pensioner account creation.'];
    }
    $stmt->bind_param('i', $staffId);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        return ['success' => false, 'message' => 'Staff record not found for pensioner account creation.'];
    }

    $regNo = trim((string)($staff['regNo'] ?? ''));
    $displayName = trim(trim((string)($staff['sName'] ?? '')) . ' ' . trim((string)($staff['fName'] ?? '')));
    if ($displayName === '') {
        $displayName = $regNo !== '' ? ('Pensioner ' . $regNo) : ('Pensioner #' . $staffId);
    }

    $title = trim((string)($staff['title'] ?? ''));
    if ($title === '') {
        $title = 'Pensioner';
    }
    $title = substr($title, 0, 20);
    $displayName = substr($displayName, 0, 100);

    $email = strtolower(trim((string)($staff['applicant_email'] ?? '')));
    $phone = trim((string)($staff['telNo'] ?? ''));
    $normalizedPhone = $phone !== '' ? normalizePhoneNumber($phone) : null;

    $existing = findExistingPensionerUserByStaffMeta($conn, $staffId, $regNo);
    if (!$existing) {
        $existing = findExistingPensionerUserByContacts($conn, $email, $normalizedPhone !== null ? $normalizedPhone : '');
    }
    if ($existing && strtolower((string)($existing['userRole'] ?? '')) !== 'pensioner') {
        $existing = null;
    }

    $emailValue = generateAvailablePensionerEmail($conn, $regNo, $staffId, $email);
    $phoneValue = generateAvailablePensionerPhone($conn, $regNo, $staffId, $normalizedPhone !== null ? $normalizedPhone : '');
    if ($existing) {
        $existingEmail = strtolower(trim((string)($existing['userEmail'] ?? '')));
        $existingPhone = trim((string)($existing['phoneNo'] ?? ''));

        $finalEmail = $existingEmail !== '' ? $existingEmail : $emailValue;
        $finalPhone = $existingPhone !== '' ? $existingPhone : $phoneValue;

        $otherMeta = json_encode([
            'source' => 'tb_staffdue',
            'staffdue_id' => $staffId,
            'regNo' => $regNo,
            'auto_provisioned' => true
        ], JSON_UNESCAPED_SLASHES);

        $updateStmt = $conn->prepare("
            UPDATE tb_users
            SET userTitle = ?,
                userName = ?,
                userRole = ?,
                userEmail = ?,
                phoneNo = ?,
                other = ?
            WHERE userId = ?
            LIMIT 1
        ");
        if (!$updateStmt) {
            return ['success' => false, 'message' => 'Unable to update existing pensioner user account.'];
        }
        $userId = (string)$existing['userId'];
        $updateStmt->bind_param('sssssss', $title, $displayName, $roleKey, $finalEmail, $finalPhone, $otherMeta, $userId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        if (!$ok) {
            return ['success' => false, 'message' => 'Failed to update existing pensioner user account.'];
        }

        return [
            'success' => true,
            'created' => false,
            'updated' => true,
            'user_id' => $userId,
            'message' => 'Existing user account aligned as pensioner.'
        ];
    }

    $userId = hash('sha256', strtoupper(substr(base_convert(random_int(100000, 999999), 10, 36), 0, 4)) . '|' . $regNo . '|' . microtime(true));
    $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $photoPath = 'images/default-user.png';
    $otherMeta = json_encode([
        'source' => 'tb_staffdue',
        'staffdue_id' => $staffId,
        'regNo' => $regNo,
        'auto_provisioned' => true
    ], JSON_UNESCAPED_SLASHES);

    $insertStmt = $conn->prepare("
        INSERT INTO tb_users (
            userId, userTitle, userName, userRole, userEmail, phoneNo, userPassword, password_updated_at, userPhoto, other
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?
        )
    ");
    if (!$insertStmt) {
        return ['success' => false, 'message' => 'Unable to prepare pensioner user account insertion.'];
    }
    $insertStmt->bind_param('sssssssss', $userId, $title, $displayName, $roleKey, $emailValue, $phoneValue, $passwordHash, $photoPath, $otherMeta);
    $ok = $insertStmt->execute();
    $insertStmt->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Failed to create pensioner user account.'];
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $actorId ?? 'system',
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? 'system',
            'action' => 'pensioner_account_created',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => [
                'staffdue_id' => $staffId,
                'regNo' => $regNo,
                'default_password_applied' => true
            ]
        ]);
    }

    return [
        'success' => true,
        'created' => true,
        'updated' => false,
        'user_id' => $userId,
        'message' => 'Pensioner user account created.'
    ];
}

function findExistingPensionerUserByRegistryMeta(mysqli $conn, string $regNo): ?array {
    $regNo = trim($regNo);
    if ($regNo === '') {
        return null;
    }

    $pattern = '%"regNo":"' . $regNo . '"%';
    $stmt = $conn->prepare("
        SELECT Id, userId, userEmail, phoneNo, userRole
        FROM tb_users
        WHERE LOWER(COALESCE(userRole, '')) = 'pensioner'
          AND other LIKE ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $pattern);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function upsertPensionerUserFromRegistry(mysqli $conn, string $regNo, string $defaultPassword = 'Pensioner123', ?string $actorId = null): array {
    ensureRoleGovernanceTables($conn);
    ensureUserPasswordUpdatedAtColumn($conn);

    $regNo = trim($regNo);
    if ($regNo === '') {
        return ['success' => false, 'message' => 'File number is required for pensioner account synchronization.'];
    }

    $roleKey = resolveRoleKeyFromInput($conn, 'pensioner', true);
    if ($roleKey === '') {
        $roleKey = 'pensioner';
    }

    $stmt = $conn->prepare("
        SELECT id, regNo, title, sName, fName, telNo, applicant_email
        FROM tb_fileregistry
        WHERE regNo = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Unable to read registry record for pensioner account creation.'];
    }
    $stmt->bind_param('s', $regNo);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$record) {
        return ['success' => false, 'message' => 'Registry record not found for pensioner account creation.'];
    }

    $registryId = (int)($record['id'] ?? 0);
    $displayName = trim(trim((string)($record['sName'] ?? '')) . ' ' . trim((string)($record['fName'] ?? '')));
    if ($displayName === '') {
        $displayName = 'Pensioner ' . $regNo;
    }

    $title = trim((string)($record['title'] ?? ''));
    if ($title === '') {
        $title = 'Pensioner';
    }
    $title = substr($title, 0, 20);
    $displayName = substr($displayName, 0, 100);

    $email = strtolower(trim((string)($record['applicant_email'] ?? '')));
    $phone = trim((string)($record['telNo'] ?? ''));
    $normalizedPhone = $phone !== '' ? normalizePhoneNumber($phone) : null;

    $existing = findExistingPensionerUserByRegistryMeta($conn, $regNo);
    if (!$existing) {
        $existing = findExistingPensionerUserByContacts($conn, $email, $normalizedPhone !== null ? $normalizedPhone : '');
    }
    if ($existing && strtolower((string)($existing['userRole'] ?? '')) !== 'pensioner') {
        $existing = null;
    }

    $emailValue = generateAvailablePensionerEmail($conn, $regNo, $registryId > 0 ? $registryId : random_int(1000, 9999), $email);
    $phoneValue = generateAvailablePensionerPhone($conn, $regNo, $registryId > 0 ? $registryId : random_int(1000, 9999), $normalizedPhone !== null ? $normalizedPhone : '');

    if ($existing) {
        $existingEmail = strtolower(trim((string)($existing['userEmail'] ?? '')));
        $existingPhone = trim((string)($existing['phoneNo'] ?? ''));
        $finalEmail = $existingEmail !== '' ? $existingEmail : $emailValue;
        $finalPhone = $existingPhone !== '' ? $existingPhone : $phoneValue;

        $otherMeta = json_encode([
            'source' => 'tb_fileregistry',
            'registry_id' => $registryId,
            'regNo' => $regNo,
            'auto_provisioned' => true
        ], JSON_UNESCAPED_SLASHES);

        $updateStmt = $conn->prepare("
            UPDATE tb_users
            SET userTitle = ?,
                userName = ?,
                userRole = ?,
                userEmail = ?,
                phoneNo = ?,
                other = ?
            WHERE userId = ?
            LIMIT 1
        ");
        if (!$updateStmt) {
            return ['success' => false, 'message' => 'Unable to update existing pensioner user account from registry.'];
        }
        $userId = (string)$existing['userId'];
        $updateStmt->bind_param('sssssss', $title, $displayName, $roleKey, $finalEmail, $finalPhone, $otherMeta, $userId);
        $ok = $updateStmt->execute();
        $updateStmt->close();

        if (!$ok) {
            return ['success' => false, 'message' => 'Failed to update existing pensioner user account from registry.'];
        }

        return [
            'success' => true,
            'created' => false,
            'updated' => true,
            'user_id' => $userId,
            'message' => 'Existing pensioner account aligned from registry.'
        ];
    }

    $userId = hash('sha256', strtoupper(substr(base_convert(random_int(100000, 999999), 10, 36), 0, 4)) . '|registry|' . $regNo . '|' . microtime(true));
    $passwordHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
    $photoPath = 'images/default-user.png';
    $otherMeta = json_encode([
        'source' => 'tb_fileregistry',
        'registry_id' => $registryId,
        'regNo' => $regNo,
        'auto_provisioned' => true
    ], JSON_UNESCAPED_SLASHES);

    $insertStmt = $conn->prepare("
        INSERT INTO tb_users (
            userId, userTitle, userName, userRole, userEmail, phoneNo, userPassword, password_updated_at, userPhoto, other
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?
        )
    ");
    if (!$insertStmt) {
        return ['success' => false, 'message' => 'Unable to prepare pensioner user insertion from registry.'];
    }
    $insertStmt->bind_param('sssssssss', $userId, $title, $displayName, $roleKey, $emailValue, $phoneValue, $passwordHash, $photoPath, $otherMeta);
    $ok = $insertStmt->execute();
    $insertStmt->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Failed to create pensioner user account from registry.'];
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $actorId ?? 'system',
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? 'system',
            'action' => 'pensioner_account_created',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => [
                'source' => 'tb_fileregistry',
                'registry_id' => $registryId,
                'regNo' => $regNo,
                'default_password_applied' => true
            ]
        ]);
    }

    return [
        'success' => true,
        'created' => true,
        'updated' => false,
        'user_id' => $userId,
        'message' => 'Pensioner user account created from registry.'
    ];
}

function deletePensionerUsersByRegistryRegNo(mysqli $conn, string $regNo, ?string $actorId = null, ?string $actorName = null, ?string $actorRole = null): array {
    $regNo = trim($regNo);
    if ($regNo === '') {
        return ['success' => true, 'deleted' => 0];
    }

    $pattern = '%"regNo":"' . $regNo . '"%';
    $selectStmt = $conn->prepare("
        SELECT userId
        FROM tb_users
        WHERE LOWER(COALESCE(userRole, '')) = 'pensioner'
          AND other LIKE ?
    ");
    if (!$selectStmt) {
        return ['success' => false, 'message' => 'Unable to prepare pensioner delete lookup.'];
    }
    $selectStmt->bind_param('s', $pattern);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    $userIds = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $id = trim((string)($row['userId'] ?? ''));
        if ($id !== '') {
            $userIds[] = $id;
        }
    }
    $selectStmt->close();

    if (empty($userIds)) {
        return ['success' => true, 'deleted' => 0];
    }

    $deleteStmt = $conn->prepare("DELETE FROM tb_users WHERE userId = ? LIMIT 1");
    if (!$deleteStmt) {
        return ['success' => false, 'message' => 'Unable to prepare pensioner delete statement.'];
    }

    $deleted = 0;
    foreach ($userIds as $userId) {
        $deleteStmt->bind_param('s', $userId);
        if (!$deleteStmt->execute()) {
            $error = $deleteStmt->error;
            $deleteStmt->close();
            return ['success' => false, 'message' => $error !== '' ? $error : 'Failed to delete linked pensioner account.'];
        }
        if ($deleteStmt->affected_rows > 0) {
            $deleted++;
            if (function_exists('logAuditEvent')) {
                logAuditEvent($conn, [
                    'actor_id' => $actorId ?? ($_SESSION['userId'] ?? 'system'),
                    'actor_name' => $actorName ?? ($_SESSION['userName'] ?? 'System'),
                    'actor_role' => $actorRole ?? ($_SESSION['userRole'] ?? 'system'),
                    'action' => 'pensioner_account_deleted',
                    'entity_type' => 'user',
                    'entity_id' => $userId,
                    'details' => [
                        'source' => 'tb_fileregistry',
                        'regNo' => $regNo,
                        'cascade_delete' => true
                    ]
                ]);
            }
        }
    }
    $deleteStmt->close();

    return ['success' => true, 'deleted' => $deleted];
}

function ensureStaffDocumentsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_staff_documents (
            document_id int(11) NOT NULL AUTO_INCREMENT,
            staffdue_id int(11) NOT NULL,
            regNo varchar(50) DEFAULT NULL,
            doc_type varchar(120) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_size int(11) DEFAULT NULL,
            mime_type varchar(120) DEFAULT NULL,
            uploaded_by varchar(100) DEFAULT NULL,
            uploaded_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (document_id),
            KEY idx_staff_documents_staff (staffdue_id),
            KEY idx_staff_documents_regno (regNo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [];
    if ($result = $conn->query("SHOW COLUMNS FROM tb_staff_documents")) {
        while ($row = $result->fetch_assoc()) {
            $columns[$row['Field']] = true;
        }
        $result->close();
    }

    if (!isset($columns['file_hash'])) {
        $conn->query("ALTER TABLE tb_staff_documents ADD COLUMN file_hash VARCHAR(64) DEFAULT NULL");
        $conn->query("ALTER TABLE tb_staff_documents ADD KEY idx_staff_documents_hash (file_hash)");
    }
    if (!isset($columns['is_archived'])) {
        $conn->query("ALTER TABLE tb_staff_documents ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!isset($columns['archived_at'])) {
        $conn->query("ALTER TABLE tb_staff_documents ADD COLUMN archived_at DATETIME DEFAULT NULL");
    }

    $created = true;
}

function getDocumentStorageSettings(mysqli $conn): array {
    $allowedRaw = trim((string)getAppSetting($conn, 'document_allowed_types', ''));
    $allowed = array_filter(array_map('trim', explode(',', strtolower($allowedRaw))));
    if (empty($allowed)) {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
    }

    return [
        'enabled' => getAppSettingBool($conn, 'document_storage_enabled', true),
        'max_size_mb' => max(1, getAppSettingInt($conn, 'document_max_size_mb', 25)),
        'allowed_types' => $allowed,
        'retention_days' => max(1, getAppSettingInt($conn, 'document_retention_days', 3650)),
        'archive_after_days' => max(1, getAppSettingInt($conn, 'document_archive_after_days', 730)),
        'classification_required' => getAppSettingBool($conn, 'document_classification_required', true),
        'dedupe_enabled' => getAppSettingBool($conn, 'document_dedupe_enabled', true),
        'preview_enabled' => getAppSettingBool($conn, 'document_preview_enabled', true),
        'access_audit_enabled' => getAppSettingBool($conn, 'document_access_audit_enabled', true),
        'link_registry_required' => getAppSettingBool($conn, 'document_link_registry_required', true),
        'naming_scheme' => strtolower(trim((string)getAppSetting($conn, 'document_naming_scheme', 'regno_doc_type_timestamp'))),
    ];
}

function getStandardDocumentTypeOptions(): array {
    return [
        'First Appointment Letter',
        'Confirmation Letter',
        'Last Appointment Letter',
        'Retirement Notice',
        'Retirement Approval',
        'Discharge Certificate',
        'Death Certificate',
        'Medical Board Report',
        'Marriage Approval',
        'Contract Clearance',
        'PF7/NS3',
        'Form NS7',
        'Form PSF18',
        'National ID',
        'NIN Slip',
        'TIN Certificate',
        'Passport Photo',
        'Bank Verification',
        'Bank Statement',
        'Nominee Form',
        'Payslip',
        'Payroll Extract',
        'Application Letter',
        'Service Record',
        'Pension Computation',
        'Gratuity Computation',
        'Life Certificate',
        'Court Order',
        'LC Introduction Letter',
        'Other'
    ];
}

function normalizeStandardDocumentType(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    foreach (getStandardDocumentTypeOptions() as $option) {
        if (strcasecmp($option, $value) === 0) {
            return $option;
        }
    }

    return null;
}

function pruneStaffDocumentFile(?string $relativePath): void {
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '') {
        return;
    }

    $absolutePath = realpath(__DIR__ . '/' . ltrim($relativePath, '/\\'));
    $allowedRoot = realpath(__DIR__ . '/uploads/documents');
    if ($absolutePath === false || $allowedRoot === false) {
        return;
    }
    if (strpos($absolutePath, $allowedRoot) !== 0) {
        return;
    }
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function applyDocumentRetentionRules(mysqli $conn): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (!tableExists($conn, 'tb_staff_documents')) {
        return;
    }

    $retentionDays = max(1, getAppSettingInt($conn, 'document_retention_days', 3650));
    $archiveAfterDays = max(1, getAppSettingInt($conn, 'document_archive_after_days', 730));

    // Archive older documents in small batches to avoid heavy writes.
    if ($archiveAfterDays > 0) {
        $conn->query("
            UPDATE tb_staff_documents
            SET is_archived = 1, archived_at = NOW()
            WHERE is_archived = 0
              AND uploaded_at < DATE_SUB(NOW(), INTERVAL {$archiveAfterDays} DAY)
            LIMIT 50
        ");
    }

    // Purge documents beyond retention in small batches and delete files.
    if ($retentionDays > 0) {
        $result = $conn->query("
            SELECT document_id, file_path
            FROM tb_staff_documents
            WHERE uploaded_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
            LIMIT 25
        ");
        $rows = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
        if ($result instanceof mysqli_result) {
            $result->close();
        }
        foreach ($rows as $row) {
            pruneStaffDocumentFile($row['file_path'] ?? null);
            $docId = (int)($row['document_id'] ?? 0);
            if ($docId > 0) {
                $deleteStmt = $conn->prepare("DELETE FROM tb_staff_documents WHERE document_id = ? LIMIT 1");
                if ($deleteStmt) {
                    $deleteStmt->bind_param('i', $docId);
                    $deleteStmt->execute();
                    $deleteStmt->close();
                }
            }
        }
    }
}

function getDemoSuperAdminUserId(): string {
    return hash('sha256', 'PENSIONSGO_SUPER_ADMIN_DEMO');
}

function ensureDemoSuperAdminAccount(mysqli $conn): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    ensureRoleGovernanceTables($conn);
    if (function_exists('ensureUserPasswordUpdatedAtColumn')) {
        ensureUserPasswordUpdatedAtColumn($conn);
    }

    $userId = getDemoSuperAdminUserId();
    $title = 'Mr.';
    $name = 'Demo Super Administrator';
    $role = 'super_admin';
    $email = 'etopat2@gmail.com';
    $phone = '+256791170164';
    $plainPassword = 'SuperAdmin123';
    $photo = 'images/default-user.png';
    $other = 'Seeded demo super administrator account for controlled system governance and exhibition/testing access.';

    $existing = null;
    $stmt = $conn->prepare("
        SELECT userId, userPassword
        FROM tb_users
        WHERE userId = ? OR userEmail = ? OR phoneNo = ?
        ORDER BY CASE WHEN userId = ? THEN 0 ELSE 1 END
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ssss', $userId, $email, $phone, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    }

    if ($existing) {
        $targetUserId = (string)($existing['userId'] ?? $userId);
        $currentHash = (string)($existing['userPassword'] ?? '');
        $passwordHash = password_verify($plainPassword, $currentHash)
            ? $currentHash
            : password_hash($plainPassword, PASSWORD_DEFAULT);
        $passwordTimestampSql = password_verify($plainPassword, $currentHash)
            ? 'password_updated_at = COALESCE(password_updated_at, NOW())'
            : 'password_updated_at = NOW()';

        $update = $conn->prepare("
            UPDATE tb_users
            SET userTitle = ?,
                userName = ?,
                userRole = ?,
                userEmail = ?,
                phoneNo = ?,
                userPassword = ?,
                userPhoto = IF(userPhoto IS NULL OR userPhoto = '', ?, userPhoto),
                other = IF(other IS NULL OR other = '', ?, other),
                {$passwordTimestampSql}
            WHERE userId = ?
            LIMIT 1
        ");
        if ($update) {
            $update->bind_param('sssssssss', $title, $name, $role, $email, $phone, $passwordHash, $photo, $other, $targetUserId);
            $update->execute();
            $update->close();
        }
    } else {
        $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $insert = $conn->prepare("
            INSERT INTO tb_users
                (userId, userTitle, userName, userRole, userEmail, phoneNo, userPassword, userPhoto, other, password_updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        if ($insert) {
            $insert->bind_param('sssssssss', $userId, $title, $name, $role, $email, $phone, $passwordHash, $photo, $other);
            $insert->execute();
            $insert->close();
        }
    }

    $ensured = true;
}

function getDocumentRetentionDebounceSeconds(mysqli $conn): int {
    $configured = getAppSettingInt($conn, 'document_retention_debounce_seconds', 300);
    return max(60, min(3600, (int)$configured));
}

function maybeApplyDocumentRetentionRules(mysqli $conn, ?int $minIntervalSeconds = null): array {
    if ($minIntervalSeconds === null || $minIntervalSeconds <= 0) {
        $minIntervalSeconds = getDocumentRetentionDebounceSeconds($conn);
    }
    return pgoRunDebouncedMaintenanceTask(
        'document_retention_rules',
        $minIntervalSeconds,
        static function () use ($conn): array {
            applyDocumentRetentionRules($conn);
            return ['applied' => true];
        }
    );
}

function ensureFileMovementTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_file_movements (
            movement_id int(11) NOT NULL AUTO_INCREMENT,
            regNo varchar(50) NOT NULL,
            file_id int(11) DEFAULT NULL,
            from_office varchar(120) DEFAULT NULL,
            to_office varchar(120) DEFAULT NULL,
            reason text DEFAULT NULL,
            delivered_by varchar(100) DEFAULT NULL,
            received_by varchar(100) DEFAULT NULL,
            moved_at datetime NOT NULL DEFAULT current_timestamp(),
            expected_return_at datetime DEFAULT NULL,
            returned_at datetime DEFAULT NULL,
            PRIMARY KEY (movement_id),
            KEY idx_file_movements_regno (regNo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        'availability_status' => "VARCHAR(40) DEFAULT 'in_shelf'",
        'availability_reason' => "TEXT DEFAULT NULL"
    ];
    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_fileregistry ADD COLUMN {$column} {$definition}");
        }
    }

    $registryColumns = [
        'telNo' => "VARCHAR(50) DEFAULT NULL",
        'applicant_email' => "VARCHAR(120) DEFAULT NULL",
        'next_of_kin' => "VARCHAR(120) DEFAULT NULL",
        'next_of_kin_contact' => "VARCHAR(50) DEFAULT NULL",
        'bank_name' => "VARCHAR(120) DEFAULT NULL",
        'bank_account' => "VARCHAR(80) DEFAULT NULL",
        'bank_branch' => "VARCHAR(120) DEFAULT NULL",
        'monthlySalary' => "DECIMAL(12,2) DEFAULT NULL",
        'lengthOfService' => "INT(11) DEFAULT NULL",
        'annualSalary' => "DECIMAL(12,2) DEFAULT NULL",
        'reducedPension' => "DECIMAL(12,2) DEFAULT NULL",
        'fullPension' => "DECIMAL(12,2) DEFAULT NULL",
        'gratuity' => "DECIMAL(12,2) DEFAULT NULL",
        'workflow_auto_arrears_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
        'workflow_auto_arrears_enabled_at' => "DATETIME DEFAULT NULL",
        'workflow_auto_arrears_source' => "VARCHAR(40) DEFAULT NULL",
        'dateOn15yrs' => "DATE DEFAULT NULL",
        'periodTo15yrs' => "VARCHAR(120) DEFAULT NULL",
        'periodFrom15yrs' => "VARCHAR(120) DEFAULT NULL",
        'dateOfDeath' => "DATE DEFAULT NULL",
        'deathNotificationDate' => "DATE DEFAULT NULL",
        'deathNotifierName' => "VARCHAR(160) DEFAULT NULL",
        'deathNotifierContact' => "VARCHAR(80) DEFAULT NULL",
        'estateExpiryDate' => "DATE DEFAULT NULL",
        'estateStatus' => "VARCHAR(50) DEFAULT NULL"
    ];

    foreach ($registryColumns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_fileregistry ADD COLUMN {$column} {$definition}");
        }
    }

    // Period fields are displayed as descriptive text (e.g. "2 Years, 3 Months and 1 Day(s)").
    $periodTo = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE 'periodTo15yrs'");
    if ($periodTo && ($row = $periodTo->fetch_assoc())) {
        $type = strtolower((string)($row['Type'] ?? ''));
        if (strpos($type, 'char') === false && strpos($type, 'text') === false) {
            $conn->query("ALTER TABLE tb_fileregistry MODIFY periodTo15yrs VARCHAR(120) DEFAULT NULL");
        }
    }

    $periodFrom = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE 'periodFrom15yrs'");
    if ($periodFrom && ($row = $periodFrom->fetch_assoc())) {
        $type = strtolower((string)($row['Type'] ?? ''));
        if (strpos($type, 'char') === false && strpos($type, 'text') === false) {
            $conn->query("ALTER TABLE tb_fileregistry MODIFY periodFrom15yrs VARCHAR(120) DEFAULT NULL");
        }
    }

    $lifeCertColumn = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE 'lifeCertificate'");
    if ($lifeCertColumn && $lifeCertColumn->num_rows === 0) {
        $conn->query("ALTER TABLE tb_fileregistry ADD COLUMN lifeCertificate VARCHAR(20) DEFAULT 'Not Submitted'");
    } else {
        $lifeType = '';
        if ($lifeCertColumn && ($row = $lifeCertColumn->fetch_assoc())) {
            $lifeType = strtolower((string)($row['Type'] ?? ''));
        }
        if (strpos($lifeType, 'enum') === false) {
            $conn->query("ALTER TABLE tb_fileregistry MODIFY lifeCertificate VARCHAR(20) DEFAULT 'Not Submitted'");
        }
    }
    $conn->query("
        UPDATE tb_fileregistry
        SET lifeCertificate = CASE
            WHEN LOWER(TRIM(COALESCE(livingStatus, ''))) = 'deceased'
              OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                THEN 'Exempt'
            WHEN LOWER(TRIM(COALESCE(CAST(lifeCertificate AS CHAR), ''))) IN ('1','yes','submitted','true')
                THEN 'Submitted'
            WHEN LOWER(TRIM(COALESCE(CAST(lifeCertificate AS CHAR), ''))) = 'exempt'
                THEN 'Exempt'
            ELSE 'Not Submitted'
        END
    ");
    $conn->query("
        ALTER TABLE tb_fileregistry
        MODIFY lifeCertificate ENUM('Submitted','Not Submitted','Exempt') DEFAULT 'Not Submitted'
    ");

    $payrollStatusColumn = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE 'payrollStatus'");
    if ($payrollStatusColumn && $payrollStatusColumn->num_rows === 0) {
        $conn->query("ALTER TABLE tb_fileregistry ADD COLUMN payrollStatus VARCHAR(20) DEFAULT 'Not on Payroll'");
    } else {
        $payrollType = '';
        if ($payrollStatusColumn && ($row = $payrollStatusColumn->fetch_assoc())) {
            $payrollType = strtolower((string)($row['Type'] ?? ''));
        }
        if (strpos($payrollType, 'enum') === false) {
            $conn->query("ALTER TABLE tb_fileregistry MODIFY payrollStatus VARCHAR(20) DEFAULT 'Not on Payroll'");
        }
    }
    $conn->query("
        UPDATE tb_fileregistry
        SET payrollStatus = CASE
            WHEN LOWER(TRIM(COALESCE(CAST(payrollStatus AS CHAR), ''))) IN ('on payroll','onpayroll','on')
                THEN 'On Payroll'
            ELSE 'Not on Payroll'
        END
    ");
    $conn->query("
        ALTER TABLE tb_fileregistry
        MODIFY payrollStatus ENUM('On Payroll','Not on Payroll') DEFAULT 'Not on Payroll'
    ");

    $softDeleteColumns = [
        'is_deleted' => "TINYINT(1) NOT NULL DEFAULT 0",
        'deleted_at' => "DATETIME DEFAULT NULL",
        'deleted_by' => "VARCHAR(100) DEFAULT NULL",
        'deleted_by_name' => "VARCHAR(100) DEFAULT NULL",
        'deleted_by_role' => "VARCHAR(50) DEFAULT NULL",
        'delete_reason' => "TEXT DEFAULT NULL"
    ];
    foreach ($softDeleteColumns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_fileregistry LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_fileregistry ADD COLUMN {$column} {$definition}");
        }
    }

    $softDeleteIndex = $conn->query("SHOW INDEX FROM tb_fileregistry WHERE Key_name = 'idx_fileregistry_is_deleted'");
    if ($softDeleteIndex && $softDeleteIndex->num_rows === 0) {
        $conn->query("ALTER TABLE tb_fileregistry ADD KEY idx_fileregistry_is_deleted (is_deleted)");
    }

    $dateOfDeathIndex = $conn->query("SHOW INDEX FROM tb_fileregistry WHERE Key_name = 'idx_fileregistry_date_of_death'");
    if ($dateOfDeathIndex && $dateOfDeathIndex->num_rows === 0) {
        $conn->query("ALTER TABLE tb_fileregistry ADD KEY idx_fileregistry_date_of_death (dateOfDeath)");
    }

    $estateExpiryIndex = $conn->query("SHOW INDEX FROM tb_fileregistry WHERE Key_name = 'idx_fileregistry_estate_expiry'");
    if ($estateExpiryIndex && $estateExpiryIndex->num_rows === 0) {
        $conn->query("ALTER TABLE tb_fileregistry ADD KEY idx_fileregistry_estate_expiry (estateExpiryDate)");
    }

    $estateStatusIndex = $conn->query("SHOW INDEX FROM tb_fileregistry WHERE Key_name = 'idx_fileregistry_estate_status'");
    if ($estateStatusIndex && $estateStatusIndex->num_rows === 0) {
        $conn->query("ALTER TABLE tb_fileregistry ADD KEY idx_fileregistry_estate_status (estateStatus)");
    }

    $created = true;
}

function ensurePensionerDeathReportingTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    ensureFileMovementTables($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_pensioner_death_reports (
            report_id int(11) NOT NULL AUTO_INCREMENT,
            registry_id int(11) NOT NULL,
            regNo varchar(50) NOT NULL,
            date_of_death date NOT NULL,
            notifier_name varchar(160) NOT NULL,
            notifier_contact varchar(80) NOT NULL,
            notification_date date NOT NULL,
            notes text DEFAULT NULL,
            recorded_by varchar(100) NOT NULL,
            recorded_by_name varchar(120) DEFAULT NULL,
            recorded_by_role varchar(60) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (report_id),
            KEY idx_pensioner_death_registry (registry_id),
            KEY idx_pensioner_death_regno (regNo),
            KEY idx_pensioner_death_date (date_of_death),
            KEY idx_pensioner_death_notification_date (notification_date),
            KEY idx_pensioner_death_recorded_by (recorded_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        'report_id' => 'int(11) NOT NULL AUTO_INCREMENT',
        'registry_id' => 'int(11) NOT NULL',
        'regNo' => 'varchar(50) NOT NULL',
        'date_of_death' => 'date NOT NULL',
        'notifier_name' => 'varchar(160) NOT NULL',
        'notifier_contact' => 'varchar(80) NOT NULL',
        'notification_date' => 'date NOT NULL',
        'notes' => 'text DEFAULT NULL',
        'recorded_by' => 'varchar(100) NOT NULL',
        'recorded_by_name' => 'varchar(120) DEFAULT NULL',
        'recorded_by_role' => 'varchar(60) DEFAULT NULL',
        'created_at' => 'timestamp NOT NULL DEFAULT current_timestamp()',
        'updated_at' => 'timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()'
    ];
    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_pensioner_death_reports LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_pensioner_death_reports ADD COLUMN {$column} {$definition}");
        }
    }

    $indexDefinitions = [
        'idx_pensioner_death_registry' => '(registry_id)',
        'idx_pensioner_death_regno' => '(regNo)',
        'idx_pensioner_death_date' => '(date_of_death)',
        'idx_pensioner_death_notification_date' => '(notification_date)',
        'idx_pensioner_death_recorded_by' => '(recorded_by)'
    ];
    foreach ($indexDefinitions as $indexName => $definition) {
        $indexResult = $conn->query("SHOW INDEX FROM tb_pensioner_death_reports WHERE Key_name = '{$indexName}'");
        if ($indexResult && $indexResult->num_rows === 0) {
            $conn->query("ALTER TABLE tb_pensioner_death_reports ADD KEY {$indexName} {$definition}");
        }
    }

    $fkRegistry = $conn->query("
        SELECT COUNT(*) AS total
        FROM information_schema.key_column_usage
        WHERE table_schema = DATABASE()
          AND table_name = 'tb_pensioner_death_reports'
          AND column_name = 'registry_id'
          AND referenced_table_name = 'tb_fileregistry'
          AND referenced_column_name = 'id'
    ");
    $fkRegistryCount = $fkRegistry ? (int)(($fkRegistry->fetch_assoc()['total'] ?? 0)) : 0;
    if ($fkRegistryCount === 0) {
        $conn->query("
            ALTER TABLE tb_pensioner_death_reports
            ADD CONSTRAINT fk_pensioner_death_registry
            FOREIGN KEY (registry_id) REFERENCES tb_fileregistry(id)
            ON UPDATE CASCADE
            ON DELETE CASCADE
        ");
    }

    $created = true;
}

function ensureRegistryDeleteQueueTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_file_registry_delete_requests (
            request_id int(11) NOT NULL AUTO_INCREMENT,
            registry_id int(11) NOT NULL,
            regNo varchar(50) DEFAULT NULL,
            staff_name varchar(160) DEFAULT NULL,
            staff_title varchar(50) DEFAULT NULL,
            requested_by varchar(100) NOT NULL,
            requested_by_name varchar(100) DEFAULT NULL,
            requested_by_role varchar(50) DEFAULT NULL,
            reason text NOT NULL,
            status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            processed_by varchar(100) DEFAULT NULL,
            processed_by_name varchar(100) DEFAULT NULL,
            processed_by_role varchar(50) DEFAULT NULL,
            processed_note text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (request_id),
            KEY idx_registry_delete_registry (registry_id),
            KEY idx_registry_delete_status (status),
            KEY idx_registry_delete_requested_by (requested_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        'staff_name' => "VARCHAR(160) DEFAULT NULL",
        'staff_title' => "VARCHAR(50) DEFAULT NULL"
    ];
    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_file_registry_delete_requests LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_file_registry_delete_requests ADD COLUMN {$column} {$definition}");
        }
    }

    $created = true;
}

function ensureStaffDueDeleteQueueTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_staff_due_delete_requests (
            request_id int(11) NOT NULL AUTO_INCREMENT,
            staffdue_id int(11) NOT NULL,
            regNo varchar(50) DEFAULT NULL,
            staff_name varchar(160) DEFAULT NULL,
            staff_title varchar(50) DEFAULT NULL,
            requested_by varchar(100) NOT NULL,
            requested_by_name varchar(100) DEFAULT NULL,
            requested_by_role varchar(50) DEFAULT NULL,
            reason text NOT NULL,
            status enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            processed_by varchar(100) DEFAULT NULL,
            processed_by_name varchar(100) DEFAULT NULL,
            processed_by_role varchar(50) DEFAULT NULL,
            processed_note text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY (request_id),
            KEY idx_staffdue_delete_staff (staffdue_id),
            KEY idx_staffdue_delete_status (status),
            KEY idx_staffdue_delete_requested_by (requested_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function softDeleteStaffDueRecord(
    mysqli $conn,
    int $staffId,
    string $deletedBy,
    string $deletedByName,
    string $deletedByRole,
    string $reason = ''
): array {
    ensureStaffDueSoftDeleteColumns($conn);
    ensureApplicationQueueTable($conn);

    if ($staffId <= 0) {
        return ['success' => false, 'message' => 'Invalid staff due record.'];
    }

    $lookupStmt = $conn->prepare("
        SELECT id, regNo, title, sName, fName, is_deleted
        FROM tb_staffdue
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    if (!$lookupStmt) {
        return ['success' => false, 'message' => 'Failed to prepare staff due lookup.'];
    }
    $lookupStmt->bind_param('i', $staffId);
    $lookupStmt->execute();
    $record = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();

    if (!$record) {
        return ['success' => false, 'message' => 'Staff due record not found.'];
    }
    if ((int)($record['is_deleted'] ?? 0) === 1) {
        return ['success' => true, 'already_deleted' => true, 'message' => 'Staff due record is already deleted.'];
    }

    $deleteStmt = $conn->prepare("
        UPDATE tb_staffdue
        SET is_deleted = 1,
            deleted_at = NOW(),
            deleted_by = ?,
            deleted_by_name = ?,
            deleted_by_role = ?,
            delete_reason = ?
        WHERE id = ?
          AND COALESCE(is_deleted, 0) = 0
    ");
    if (!$deleteStmt) {
        return ['success' => false, 'message' => 'Failed to prepare staff due delete update.'];
    }
    $deleteStmt->bind_param('ssssi', $deletedBy, $deletedByName, $deletedByRole, $reason, $staffId);
    $deleteStmt->execute();
    $affected = (int)$deleteStmt->affected_rows;
    $deleteStmt->close();

    $queueStmt = $conn->prepare("
        UPDATE tb_application_queue
        SET status = 'dropped',
            current_stage = 'dropped',
            notes = CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE ' | ' END, 'Removed because staff due record was deleted')
        WHERE staffdue_id = ?
          AND status IN ('verified','submitted_to_oc','in_progress')
    ");
    if ($queueStmt) {
        $queueStmt->bind_param('i', $staffId);
        $queueStmt->execute();
        $queueStmt->close();
    }

    return [
        'success' => true,
        'deleted' => $affected > 0,
        'staffdue_id' => $staffId,
        'regNo' => (string)($record['regNo'] ?? '')
    ];
}

function getLatestOpenFileMovement(mysqli $conn, string $regNo, bool $forUpdate = false): ?array {
    ensureFileMovementTables($conn);

    $sql = "
        SELECT movement_id, regNo, from_office, to_office, reason, delivered_by, received_by, moved_at, expected_return_at, returned_at
        FROM tb_file_movements
        WHERE regNo = ? AND returned_at IS NULL
        ORDER BY moved_at DESC, movement_id DESC
        LIMIT 1
    ";
    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $regNo);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row ?: null;
}

function getCurrentFileMovementActorLabel(mysqli $conn): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $roleKey = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
    $roleLabel = $roleKey !== '' ? formatRoleLabel($conn, $roleKey) : '';
    if ($roleLabel !== '' && strtolower($roleLabel) !== 'user') {
        return $roleLabel;
    }

    $userName = trim((string)($_SESSION['userName'] ?? $_SESSION['name'] ?? ''));
    return $userName !== '' ? $userName : 'Current Holder';
}

function resolveCurrentFileHolderOffice(mysqli $conn, string $regNo): string {
    $latestOpen = getLatestOpenFileMovement($conn, $regNo, false);
    if ($latestOpen) {
        $holderOffice = trim((string)($latestOpen['to_office'] ?? ''));
        if ($holderOffice !== '') {
            return $holderOffice;
        }
    }

    if (currentUserHasPermission($conn, 'file_movement.return')) {
        return 'Front Desk';
    }

    return getCurrentFileMovementActorLabel($conn);
}

function closeFileMovementLeg(mysqli $conn, int $movementId): bool {
    $stmt = $conn->prepare("
        UPDATE tb_file_movements
        SET returned_at = NOW()
        WHERE movement_id = ? AND returned_at IS NULL
    ");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("i", $movementId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    return $updated;
}

function recordFileMovementLeg(
    mysqli $conn,
    string $regNo,
    string $fromOffice,
    string $toOffice,
    string $reason,
    string $deliveredBy,
    string $receivedBy = '',
    ?string $expectedReturnAt = null,
    bool $markReturnedImmediately = false
): ?int {
    $movedAtSql = $markReturnedImmediately ? "NOW(), NOW()" : "NOW(), NULL";
    $stmt = $conn->prepare("
        INSERT INTO tb_file_movements
        (regNo, from_office, to_office, reason, delivered_by, received_by, expected_return_at, moved_at, returned_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, {$movedAtSql})
    ");
    if (!$stmt) {
        return null;
    }

    $expectedValue = ($expectedReturnAt !== null && trim($expectedReturnAt) !== '') ? $expectedReturnAt : null;
    $receivedValue = trim($receivedBy) !== '' ? $receivedBy : null;
    $stmt->bind_param("sssssss", $regNo, $fromOffice, $toOffice, $reason, $deliveredBy, $receivedValue, $expectedValue);
    $ok = $stmt->execute();
    $insertId = $ok ? (int)$stmt->insert_id : null;
    $stmt->close();

    return $insertId ?: null;
}



function adminSettingsInsightsMetric(string $label, $value, string $helper = ''): array {
    return [
        'label' => $label,
        'value' => $value,
        'helper' => $helper,
    ];
}

function adminSettingsInsightsPayload(mysqli $conn, string $section): array {
    $section = trim(strtolower($section));
    switch ($section) {
        case 'notification-settings':
            $runtime = function_exists('getNotificationDigestRuntime')
                ? getNotificationDigestRuntime($conn)
                : ['enabled' => false, 'delivery_time' => '07:30', 'recipient' => null, 'history' => [], 'preview' => ['summary' => []]];
            $queueWorker = getNotificationQueueWorkerConfig($conn);
            $lastQueueRun = trim((string)getAppSetting($conn, 'notify_queue_last_run_at'));
            $lastQueueFailure = null;
            if (tableExists($conn, 'tb_notification_queue')) {
                $failureResult = $conn->query("
                    SELECT recipient, subject, last_error, failed_at, last_attempted_at
                    FROM tb_notification_queue
                    WHERE status = 'failed'
                    ORDER BY COALESCE(failed_at, last_attempted_at, created_at) DESC
                    LIMIT 1
                ");
                if ($failureResult && ($failureRow = $failureResult->fetch_assoc())) {
                    $lastQueueFailure = [
                        'recipient' => $failureRow['recipient'] ?? '',
                        'subject' => $failureRow['subject'] ?? '',
                        'error' => $failureRow['last_error'] ?? '',
                        'failed_at' => $failureRow['failed_at'] ?? ($failureRow['last_attempted_at'] ?? null),
                    ];
                }
                if ($failureResult instanceof mysqli_result) {
                    $failureResult->close();
                }
            }
            $preview = $runtime['preview'] ?? ['summary' => []];
            $history = is_array($runtime['history'] ?? null) ? $runtime['history'] : [];
            $digestSummaryRaw = $preview['summary'] ?? [];
            $digestSummary = [];
            if (is_array($digestSummaryRaw)) {
                $isAssoc = array_keys($digestSummaryRaw) !== range(0, count($digestSummaryRaw) - 1);
                if ($isAssoc) {
                    foreach ($digestSummaryRaw as $label => $value) {
                        $digestSummary[] = [
                            'label' => ucwords(str_replace('_', ' ', (string)$label)),
                            'value' => $value,
                        ];
                    }
                } else {
                    foreach ($digestSummaryRaw as $item) {
                        if (is_array($item)) {
                            $digestSummary[] = [
                                'label' => $item['label'] ?? 'Metric',
                                'value' => $item['value'] ?? 0,
                            ];
                        }
                    }
                }
            }
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Daily digest', !empty($runtime['enabled']) ? 'Enabled' : 'Disabled', 'Scheduled for ' . ($runtime['delivery_time'] ?? '07:30')),
                    adminSettingsInsightsMetric('Digest recipient', $runtime['recipient'] ?: 'Not configured', 'Primary destination for administrator digest'),
                    adminSettingsInsightsMetric('Digest runs logged', count($history), 'Recent preview and queue actions captured'),
                    adminSettingsInsightsMetric('Queued notifications', tableExists($conn, 'tb_notification_queue') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_notification_queue WHERE status = 'queued'")->fetch_assoc()['total'] ?? 0) : 0, 'Current outbound notifications waiting delivery'),
                    adminSettingsInsightsMetric('Queue worker', $queueWorker['enabled'] ? 'Enabled' : 'Disabled', 'Batch ' . $queueWorker['batch_size'] . ', retry limit ' . $queueWorker['retry_limit']),
                    adminSettingsInsightsMetric('Last queue run', $lastQueueRun !== '' ? date('M j, Y g:i A', strtotime($lastQueueRun)) : 'No run yet', 'Most recent notification worker execution')
                ],
                'transport_runtime' => [
                    'transport' => strtoupper(MAIL_TRANSPORT),
                    'smtp_host' => MAIL_TRANSPORT === 'smtp' ? MAIL_SMTP_HOST : 'Not applicable',
                    'smtp_port' => MAIL_TRANSPORT === 'smtp' ? (string)MAIL_SMTP_PORT : 'Not applicable',
                    'encryption' => MAIL_TRANSPORT === 'smtp' ? strtoupper(MAIL_SMTP_ENCRYPTION ?: 'none') : 'Not applicable',
                    'last_queue_run' => $lastQueueRun !== '' ? date('M j, Y g:i A', strtotime($lastQueueRun)) : 'No run yet',
                    'last_failure_summary' => $lastQueueFailure
                        ? trim(($lastQueueFailure['error'] ?? 'Delivery failed') . (($lastQueueFailure['failed_at'] ?? '') ? ' - ' . date('M j, Y g:i A', strtotime((string)$lastQueueFailure['failed_at'])) : ''))
                        : 'No failed deliveries recorded',
                    'last_failure_meta' => $lastQueueFailure,
                ],
                'digest_summary' => $digestSummary,
                'recent_runs' => $history,
                'insights' => [
                    !empty($runtime['recipient']) ? 'A digest recipient is configured and ready for scheduled summaries.' : 'Configure a test or support email to activate digest delivery.',
                    'Daily digest should summarise operational workload, overdue tasks, and unresolved issues for administrators.',
                    'Queue a manual digest after major imports, cleanup routines, or backup operations when an immediate executive briefing is required.',
                    $queueWorker['process_on_request']
                        ? 'Queue worker will also run opportunistically during normal requests, which is suitable for low-to-moderate traffic environments.'
                        : 'Queue worker opportunistic processing is disabled. Use the CLI worker or manual processing to deliver queued emails.'
                ]
            ];

        case 'message-storage':
            $runtime = function_exists('getMessageStorageRuntimeSummary')
                ? getMessageStorageRuntimeSummary($conn)
                : [];
            $snapshots = $runtime['snapshots'] ?? [];
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Active messages', (int)($runtime['message_count'] ?? 0), 'Current messages retained in the platform'),
                    adminSettingsInsightsMetric('Soft-deleted views', (int)($runtime['soft_deleted_sender'] ?? 0) + (int)($runtime['soft_deleted_recipient'] ?? 0), 'Deleted sender and recipient views still held for recovery'),
                    adminSettingsInsightsMetric('Attachment records', (int)($runtime['attachment_count'] ?? 0), 'Message attachments currently stored'),
                    adminSettingsInsightsMetric('Latest snapshot', !empty($runtime['last_snapshot_at']) ? date('M j, Y g:i A', strtotime((string)$runtime['last_snapshot_at'])) : 'No snapshot yet', ((int)($runtime['snapshot_count'] ?? 0)) . ' snapshots captured')
                ],
                'runtime' => $runtime,
                'snapshots' => $snapshots,
                'breakdown' => [
                    ['label' => 'Messages', 'count' => (int)($runtime['message_count'] ?? 0)],
                    ['label' => 'Attachments', 'count' => (int)($runtime['attachment_count'] ?? 0)],
                    ['label' => 'Sender soft delete', 'count' => (int)($runtime['soft_deleted_sender'] ?? 0)],
                    ['label' => 'Recipient soft delete', 'count' => (int)($runtime['soft_deleted_recipient'] ?? 0)],
                ],
                'insights' => [
                    'Soft delete preserves deleted views for recovery until retention cleanup removes them.',
                    getAppSettingBool($conn, 'message_backup_enabled', false)
                        ? 'Automatic message snapshots are enabled and can be generated manually before sensitive maintenance.'
                        : 'Enable backup snapshots to preserve message evidence before destructive maintenance.',
                    'Retention and archive windows should remain aligned with operational and audit requirements.'
                ]
            ];

        case 'attachment-storage':
            $runtime = function_exists('getAttachmentScanRuntime')
                ? getAttachmentScanRuntime($conn)
                : ['engine' => 'unknown', 'native_available' => false, 'total_scans' => 0, 'infected_count' => 0, 'suspicious_count' => 0, 'last_scan_at' => null, 'recent' => []];
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Scan engine', !empty($runtime['native_available']) ? 'ClamAV' : 'Heuristic', !empty($runtime['native_available']) ? 'Native scanner is available.' : 'Fallback heuristic scanning is active.'),
                    adminSettingsInsightsMetric('Files scanned', (int)($runtime['total_scans'] ?? 0), 'Uploads evaluated under the current storage policy'),
                    adminSettingsInsightsMetric('Suspicious files', (int)($runtime['suspicious_count'] ?? 0), 'Uploads flagged for suspicious patterns'),
                    adminSettingsInsightsMetric('Infected files', (int)($runtime['infected_count'] ?? 0), 'Uploads explicitly blocked by virus scanning')
                ],
                'runtime' => $runtime,
                'recent_scans' => $runtime['recent'] ?? [],
                'breakdown' => [
                    ['label' => 'Clean or skipped', 'count' => max(0, (int)($runtime['total_scans'] ?? 0) - (int)($runtime['infected_count'] ?? 0) - (int)($runtime['suspicious_count'] ?? 0))],
                    ['label' => 'Suspicious', 'count' => (int)($runtime['suspicious_count'] ?? 0)],
                    ['label' => 'Infected', 'count' => (int)($runtime['infected_count'] ?? 0)],
                ],
                'insights' => [
                    !empty($runtime['native_available']) ? 'ClamAV is available and should remain preferred for upload scanning.' : 'No native scanner was detected. The system is relying on heuristic checks and should be hardened with a native engine if possible.',
                    'Virus scanning is applied to message attachments and workflow document uploads before storage.',
                    'Review suspicious uploads promptly to prevent risky files from entering long-term storage.'
                ]
            ];

        case 'storage-overview':
            ensureBackupLogsTable($conn);
            $messageBytes = 0;
            $attachmentBytes = 0;
            $documentBytes = 0;
            $messageCount = 0;
            $attachmentCount = 0;
            $documentCount = 0;
            if (tableExists($conn, 'tb_messages')) {
                $row = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(CHAR_LENGTH(message_text)), 0) AS bytes_used FROM tb_messages")->fetch_assoc();
                $messageCount = (int)($row['total'] ?? 0);
                $messageBytes = (int)($row['bytes_used'] ?? 0);
            }
            if (tableExists($conn, 'tb_message_attachments')) {
                $row = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(file_size), 0) AS bytes_used FROM tb_message_attachments")->fetch_assoc();
                $attachmentCount = (int)($row['total'] ?? 0);
                $attachmentBytes = (int)($row['bytes_used'] ?? 0);
            }
            if (tableExists($conn, 'tb_staff_documents')) {
                $row = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(file_size), 0) AS bytes_used FROM tb_staff_documents")->fetch_assoc();
                $documentCount = (int)($row['total'] ?? 0);
                $documentBytes = (int)($row['bytes_used'] ?? 0);
            }
            $backupCount = 0;
            $lastBackup = null;
            if (tableExists($conn, 'tb_backup_logs')) {
                $row = $conn->query("SELECT COUNT(*) AS total, MAX(backup_time) AS last_backup FROM tb_backup_logs")->fetch_assoc();
                $backupCount = (int)($row['total'] ?? 0);
                $lastBackup = $row['last_backup'] ?? null;
            }
            $totals = [
                'messages' => $messageBytes,
                'attachments' => $attachmentBytes,
                'documents' => $documentBytes,
                'combined' => $messageBytes + $attachmentBytes + $documentBytes,
            ];
            $warningMb = max(256, getAppSettingInt($conn, 'storage_warning_threshold_mb', 5120));
            $criticalMb = max($warningMb, getAppSettingInt($conn, 'storage_critical_threshold_mb', 10240));
            $combinedMb = $totals['combined'] / (1024 * 1024);
            $healthState = $combinedMb >= $criticalMb ? 'critical' : ($combinedMb >= $warningMb ? 'warning' : 'ok');
            $healthLabel = $healthState === 'critical'
                ? 'Critical usage'
                : ($healthState === 'warning' ? 'Approaching limit' : 'Within limits');
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Combined managed storage', formatBytes($totals['combined']), 'Messages, attachments and indexed documents'),
                    adminSettingsInsightsMetric('Document library', formatBytes($documentBytes), $documentCount . ' indexed documents'),
                    adminSettingsInsightsMetric('Message payloads', formatBytes($messageBytes), $messageCount . ' messages tracked'),
                    adminSettingsInsightsMetric('Latest backup', $lastBackup ? date('M j, Y g:i A', strtotime($lastBackup)) : 'No backup yet', $backupCount . ' backup runs recorded'),
                    adminSettingsInsightsMetric('Storage health', $healthLabel, sprintf('%.1f MB used | Warn %d MB | Critical %d MB', $combinedMb, $warningMb, $criticalMb)),
                ],
                'thresholds' => [
                    'combined_mb' => round($combinedMb, 2),
                    'warning_mb' => $warningMb,
                    'critical_mb' => $criticalMb,
                    'state' => $healthState
                ],
                'breakdown' => [
                    ['label' => 'Messages', 'value' => $messageBytes, 'display' => formatBytes($messageBytes), 'count' => $messageCount],
                    ['label' => 'Attachments', 'value' => $attachmentBytes, 'display' => formatBytes($attachmentBytes), 'count' => $attachmentCount],
                    ['label' => 'Document storage', 'value' => $documentBytes, 'display' => formatBytes($documentBytes), 'count' => $documentCount],
                ],
                'insights' => [
                    'Largest managed area is ' . adminSettingsInsightsLargestLabel($totals) . '.',
                    $backupCount > 0 ? 'Backups are being recorded and can support cleanup decisions.' : 'No backups have been recorded yet. Enable backup routines before aggressive cleanup.',
                    'Use warning and critical thresholds to trigger operational review before storage pressure affects uploads.'
                ]
            ];

        case 'document-storage':
            $docTypes = [];
            $largestDocs = [];
            $orphanCount = 0;
            if (tableExists($conn, 'tb_staff_documents')) {
                $typeResult = $conn->query("SELECT COALESCE(doc_type, 'Unclassified') AS doc_type, COUNT(*) AS total, COALESCE(SUM(file_size), 0) AS bytes_used FROM tb_staff_documents GROUP BY COALESCE(doc_type, 'Unclassified') ORDER BY total DESC, bytes_used DESC LIMIT 8");
                while ($typeResult && ($row = $typeResult->fetch_assoc())) {
                    $docTypes[] = [
                        'label' => $row['doc_type'],
                        'count' => (int)$row['total'],
                        'size' => formatBytes((int)$row['bytes_used'])
                    ];
                }
                $largestResult = $conn->query("SELECT regNo, file_name, doc_type, file_size, uploaded_at FROM tb_staff_documents ORDER BY file_size DESC, uploaded_at DESC LIMIT 6");
                while ($largestResult && ($row = $largestResult->fetch_assoc())) {
                    $largestDocs[] = $row;
                }
                $orphanRow = $conn->query("SELECT COUNT(*) AS total FROM tb_staff_documents d LEFT JOIN tb_fileregistry r ON r.regNo = d.regNo LEFT JOIN tb_staffdue s ON s.id = d.staffdue_id WHERE r.id IS NULL AND s.id IS NULL")->fetch_assoc();
                $orphanCount = (int)($orphanRow['total'] ?? 0);
            }
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Indexed document types', count($docTypes), 'Distinct groupings represented in the current sample'),
                    adminSettingsInsightsMetric('Potential orphan documents', $orphanCount, 'Documents not linked to staff due or registry records'),
                    adminSettingsInsightsMetric('Largest file tracked', isset($largestDocs[0]) ? formatBytes((int)$largestDocs[0]['file_size']) : '0 B', isset($largestDocs[0]) ? ($largestDocs[0]['file_name'] ?? 'Top file') : 'No documents uploaded yet'),
                    adminSettingsInsightsMetric('Registry-linked coverage', tableExists($conn, 'tb_staff_documents') ? ((int)$conn->query("SELECT COUNT(DISTINCT regNo) AS total FROM tb_staff_documents WHERE regNo IS NOT NULL AND regNo <> ''")->fetch_assoc()['total']) : 0, 'Distinct registration numbers represented')
                ],
                'breakdown' => $docTypes,
                'largest' => $largestDocs,
                'insights' => [
                    $orphanCount > 0 ? 'Orphaned document links exist. Cleanup tooling should review and either relink or archive them.' : 'All indexed documents appear linked to known workflow or registry records.',
                    'Require classification and registry linking to preserve document retrieval quality as payroll and pension file scans grow.',
                    'Preview and access audit settings should remain enabled for sensitive pension documentation.'
                ]
            ];

        case 'storage-cleanup':
            ensureBackupLogsTable($conn);
            $sessionDays = max(1, getAppSettingInt($conn, 'storage_cleanup_sessions_days', 30));
            $notificationDays = max(1, getAppSettingInt($conn, 'storage_cleanup_notification_days', 30));
            $exportDays = max(1, getAppSettingInt($conn, 'storage_cleanup_exports_days', 90));
            $backupDays = max(1, getAppSettingInt($conn, 'storage_cleanup_backups_days', 180));
            $orphanDays = max(1, getAppSettingInt($conn, 'storage_cleanup_orphan_documents_days', 30));
            $stats = [
                'inactive_sessions' => tableExists($conn, 'tb_user_sessions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_user_sessions WHERE is_active = 0 OR (last_activity IS NOT NULL AND last_activity < DATE_SUB(NOW(), INTERVAL {$sessionDays} DAY))")->fetch_assoc()['total'] ?? 0) : 0,
                'pending_notifications' => tableExists($conn, 'tb_notification_queue') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_notification_queue WHERE status IN ('sent','failed') AND created_at < DATE_SUB(NOW(), INTERVAL {$notificationDays} DAY)")->fetch_assoc()['total'] ?? 0) : 0,
                'old_exports' => tableExists($conn, 'tb_data_export_runs') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_data_export_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$exportDays} DAY)")->fetch_assoc()['total'] ?? 0) : 0,
                'old_backups' => tableExists($conn, 'tb_backup_logs') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_backup_logs WHERE backup_time < DATE_SUB(NOW(), INTERVAL {$backupDays} DAY)")->fetch_assoc()['total'] ?? 0) : 0,
                'orphan_documents' => 0,
            ];
            if (tableExists($conn, 'tb_staff_documents')) {
                $stats['orphan_documents'] = (int)($conn->query("SELECT COUNT(*) AS total FROM tb_staff_documents d LEFT JOIN tb_fileregistry r ON r.regNo = d.regNo LEFT JOIN tb_staffdue s ON s.id = d.staffdue_id WHERE r.id IS NULL AND s.id IS NULL AND d.uploaded_at < DATE_SUB(NOW(), INTERVAL {$orphanDays} DAY)")->fetch_assoc()['total'] ?? 0);
            }
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Inactive sessions', $stats['inactive_sessions'], 'Candidate session rows for archival cleanup'),
                    adminSettingsInsightsMetric('Queued notifications', $stats['pending_notifications'], 'Delivery queue rows that may need purging'),
                    adminSettingsInsightsMetric('Old exports', $stats['old_exports'], 'Export artifacts older than the default retention window'),
                    adminSettingsInsightsMetric('Old backups', $stats['old_backups'], 'Backups older than the default retention window')
                ],
                'cleanup_matrix' => [
                    ['label' => 'Inactive sessions', 'count' => $stats['inactive_sessions'], 'recommended_action' => 'Purge expired or inactive session rows'],
                    ['label' => 'Queued notifications', 'count' => $stats['pending_notifications'], 'recommended_action' => 'Clear stale queue entries after backup confirmation'],
                    ['label' => 'Old exports', 'count' => $stats['old_exports'], 'recommended_action' => 'Purge aged export artifacts after retention review'],
                    ['label' => 'Old backups', 'count' => $stats['old_backups'], 'recommended_action' => 'Retain only required backup windows and move older archives offline'],
                    ['label' => 'Orphan documents', 'count' => $stats['orphan_documents'], 'recommended_action' => 'Relink or purge document rows with no registry/workflow parent'],
                ],
                'insights' => [
                    'Backup-before-delete should remain enabled for destructive cleanup actions.',
                    'Dry-run cleanup provides the safest preview for operational housekeeping.',
                    $stats['orphan_documents'] > 0 ? 'Document cleanup should be prioritized because orphan rows weaken retrieval integrity.' : 'Current document linking is clean enough for scheduled housekeeping only.'
                ]
            ];

        case 'workflow-logs':
            $queue = tableExists($conn, 'tb_appnstatus') ? $conn->query("SELECT COUNT(*) AS total FROM tb_appnstatus")->fetch_assoc() : ['total' => 0];
            $verified = tableExists($conn, 'tb_appnstatus') ? $conn->query("SELECT COUNT(*) AS total FROM tb_appnstatus WHERE LOWER(COALESCE(verification, '')) = 'verified'")->fetch_assoc() : ['total' => 0];
            $approved = tableExists($conn, 'tb_appnstatus') ? $conn->query("SELECT COUNT(*) AS total FROM tb_appnstatus WHERE LOWER(COALESCE(approval, '')) = 'approved'")->fetch_assoc() : ['total' => 0];
            $queried = tableExists($conn, 'tb_appnstatus') ? $conn->query("SELECT COUNT(*) AS total FROM tb_appnstatus WHERE LOWER(COALESCE(verification, '')) = 'queried' OR LOWER(COALESCE(assessment, '')) = 'queried' OR LOWER(COALESCE(audit, '')) = 'queried'")->fetch_assoc() : ['total' => 0];
            $verificationEscalationDays = getStaffDueVerificationEscalationDays($conn);
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Workflow cases tracked', (int)$queue['total'], 'Application status rows currently monitored'),
                    adminSettingsInsightsMetric('Verified stage completions', (int)$verified['total'], 'Cases moved beyond intake verification'),
                    adminSettingsInsightsMetric('Approved completions', (int)$approved['total'], 'Cases fully approved in the workflow'),
                    adminSettingsInsightsMetric('Queried checkpoints', (int)$queried['total'], 'Cases with review friction that should inform operational coaching'),
                    adminSettingsInsightsMetric('Verification escalation window', $verificationEscalationDays . ' days', 'Submitted applications should start verification inside this governance window')
                ],
                'insights' => [
                    'Workflow report retention should stay long enough to support pension file traceability and audit defense.',
                    'Capturing assignment and comments materially improves accountability analysis in the workflow report stream.',
                    'The submitted-application escalation window should reflect the real administrative service standard, not a convenience threshold.',
                    'Export-enabled workflow reporting supports supervisor review packs without ad-hoc SQL access.'
                ]
            ];

        case 'task-logs':
            $totals = tableExists($conn, 'tb_tasks') ? $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'delegated' THEN 1 ELSE 0 END) AS delegated_total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_total, SUM(CASE WHEN status IN ('declined','rejected') THEN 1 ELSE 0 END) AS rejected_total FROM tb_tasks")->fetch_assoc() : ['total' => 0, 'delegated_total' => 0, 'completed_total' => 0, 'rejected_total' => 0];
            $byRole = [];
            if (tableExists($conn, 'tb_tasks')) {
                $result = $conn->query("SELECT COALESCE(assigned_role, 'Unassigned') AS role_key, COUNT(*) AS total FROM tb_tasks GROUP BY COALESCE(assigned_role, 'Unassigned') ORDER BY total DESC LIMIT 8");
                while ($result && ($row = $result->fetch_assoc())) {
                    $byRole[] = ['label' => getRoleLabel($conn, $row['role_key']), 'count' => (int)$row['total']];
                }
            }
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Tasks tracked', (int)$totals['total'], 'All workflow tasks recorded'),
                    adminSettingsInsightsMetric('Delegated tasks', (int)$totals['delegated_total'], 'Tasks that changed hands and require handoff visibility'),
                    adminSettingsInsightsMetric('Completed tasks', (int)$totals['completed_total'], 'Tasks closed successfully'),
                    adminSettingsInsightsMetric('Declined or rejected', (int)$totals['rejected_total'], 'Signals for governance and routing adjustments')
                ],
                'breakdown' => $byRole,
                'insights' => [
                    'Delegation reason capture should remain mandatory to preserve decision context across handoffs.',
                    'Escalation settings should align with task due periods and response-rate analytics.',
                    'Exportable delegation logs support appraisal, supervision, and workload balancing.'
                ]
            ];

        case 'system-logs':
            ensureSystemLogsTable($conn);
            $totals = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN log_level IN ('error','critical') THEN 1 ELSE 0 END) AS error_total, SUM(CASE WHEN log_level = 'warning' THEN 1 ELSE 0 END) AS warning_total FROM tb_system_logs")->fetch_assoc();
            $categories = [];
            $result = $conn->query("SELECT COALESCE(log_category, 'general') AS log_category, COUNT(*) AS total FROM tb_system_logs GROUP BY COALESCE(log_category, 'general') ORDER BY total DESC LIMIT 8");
            while ($result && ($row = $result->fetch_assoc())) {
                $categories[] = ['label' => ucwords(str_replace('_', ' ', $row['log_category'])), 'count' => (int)$row['total']];
            }
            return [
                'summary' => [
                    adminSettingsInsightsMetric('System log volume', (int)($totals['total'] ?? 0), 'Structured platform events recorded'),
                    adminSettingsInsightsMetric('Errors and critical events', (int)($totals['error_total'] ?? 0), 'Operational failures that need attention'),
                    adminSettingsInsightsMetric('Warnings', (int)($totals['warning_total'] ?? 0), 'Potential issues detected before failure'),
                    adminSettingsInsightsMetric('Categories tracked', count($categories), 'Distinct operational streams represented in the logs')
                ],
                'breakdown' => $categories,
                'insights' => [
                    'System log retention should balance forensic value against disk growth.',
                    'Security, integration, and error capture should remain enabled for incident reconstruction.',
                    'Use the minimum log level carefully; hiding notices can save space but reduce troubleshooting context.'
                ]
            ];

        case 'analysis-reporting':
            if (function_exists('ensureTasksTable')) {
                ensureTasksTable($conn);
            }
            if (function_exists('ensureArrearsAndBudgetTables')) {
                ensureArrearsAndBudgetTables($conn);
            }
            if (function_exists('ensureFeedbackWorkflowTables')) {
                ensureFeedbackWorkflowTables($conn);
            }
            if (function_exists('ensureFileMovementTables')) {
                ensureFileMovementTables($conn);
            }
            if (function_exists('ensureLifeCertificateTables')) {
                ensureLifeCertificateTables($conn);
            }
            if (function_exists('ensurePayrollManagementTables')) {
                ensurePayrollManagementTables($conn);
            }
            if (function_exists('ensureStaffDueSoftDeleteColumns')) {
                ensureStaffDueSoftDeleteColumns($conn);
            }
            $runtime = function_exists('getAnalyticsDigestRuntime')
                ? getAnalyticsDigestRuntime($conn)
                : ['enabled' => false, 'frequency' => 'weekly', 'delivery_time' => '08:00', 'recipient' => null, 'history' => [], 'preview' => ['summary' => []]];
            $workflowTasks = tableExists($conn, 'tb_tasks') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_tasks")->fetch_assoc()['total'] ?? 0) : 0;
            $registryFiles = tableExists($conn, 'tb_fileregistry') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_fileregistry WHERE COALESCE(is_deleted, 0) = 0")->fetch_assoc()['total'] ?? 0) : 0;
            $claimsRows = tableExists($conn, 'tb_arrears_ledger') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_arrears_ledger")->fetch_assoc()['total'] ?? 0) : 0;
            $feedbackRows = tableExists($conn, 'tb_feedback_submissions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_feedback_submissions")->fetch_assoc()['total'] ?? 0) : 0;
            $previewSummary = is_array($runtime['preview']['summary'] ?? null) ? $runtime['preview']['summary'] : [];
            $hasNonZero = false;
            foreach ($previewSummary as $item) {
                $rawValue = $item['value'] ?? null;
                if (is_numeric($rawValue) && (float)$rawValue !== 0.0) {
                    $hasNonZero = true;
                    break;
                }
                if (is_string($rawValue) && trim($rawValue) !== '' && trim($rawValue) !== '0') {
                    $hasNonZero = true;
                    break;
                }
            }
            if (!$hasNonZero && function_exists('buildAnalyticsDigest')) {
                $fallback = buildAnalyticsDigest($conn);
                if (is_array($fallback['summary'] ?? null) && !empty($fallback['summary'])) {
                    $previewSummary = $fallback['summary'];
                }
            }
            return [
                'summary' => [
                    adminSettingsInsightsMetric('Operational datasets connected', 4, 'Registry, workflow, claims and feedback streams contribute to reporting'),
                    adminSettingsInsightsMetric('Workflow analytical base', $workflowTasks, 'Tasks available for workload and performance analysis'),
                    adminSettingsInsightsMetric('Registry analytical base', $registryFiles, 'Registry records available for pension administration trends'),
                    adminSettingsInsightsMetric('Claims and feedback signals', ($claimsRows + $feedbackRows), 'Combined service and operational insight sources'),
                    adminSettingsInsightsMetric('Analytics digest', !empty($runtime['enabled']) ? 'Enabled' : 'Disabled', ucfirst((string)($runtime['frequency'] ?? 'weekly')) . ' delivery at ' . ($runtime['delivery_time'] ?? '08:00')),
                    adminSettingsInsightsMetric('Digest recipient', $runtime['recipient'] ?: 'Not configured', count($runtime['history'] ?? []) . ' recent digest runs recorded')
                ],
                'breakdown' => [
                    ['label' => 'Workflow tasks', 'count' => $workflowTasks],
                    ['label' => 'Registry records', 'count' => $registryFiles],
                    ['label' => 'Claims ledger rows', 'count' => $claimsRows],
                    ['label' => 'Feedback submissions', 'count' => $feedbackRows],
                ],
                'runtime' => $runtime,
                'digest_summary' => $previewSummary,
                'recent_runs' => is_array($runtime['history'] ?? null) ? $runtime['history'] : [],
                'insights' => [
                    'Short refresh intervals improve situational awareness but increase dashboard query load.',
                    'Digest reporting is most useful when paired with export-ready KPI packs for decision makers.',
                    'Predictive and anomaly features should focus on workflow delays, payroll mismatches, and life-certificate risk.'
                ]
            ];
    }

    return [
        'summary' => [],
        'breakdown' => [],
        'insights' => ['No analytics are configured for this section yet.']
    ];
}

function adminSettingsInsightsLargestLabel(array $totals): string {
    $largestLabel = 'managed storage';
    $largestValue = -1;
    foreach ($totals as $label => $value) {
        if ($label === 'combined') {
            continue;
        }
        if ((int)$value > $largestValue) {
            $largestValue = (int)$value;
            $largestLabel = str_replace('_', ' ', $label);
        }
    }
    return ucwords($largestLabel);
}

function ensureRegistryRecycleBinTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_file_registry_recycle_bin (
            recycle_id int(11) NOT NULL AUTO_INCREMENT,
            registry_id int(11) DEFAULT NULL,
            regNo varchar(50) DEFAULT NULL,
            staff_name varchar(160) DEFAULT NULL,
            staff_title varchar(50) DEFAULT NULL,
            delete_request_id int(11) DEFAULT NULL,
            delete_reason text DEFAULT NULL,
            deleted_by varchar(100) DEFAULT NULL,
            deleted_by_name varchar(100) DEFAULT NULL,
            deleted_by_role varchar(50) DEFAULT NULL,
            deleted_at timestamp NOT NULL DEFAULT current_timestamp(),
            record_snapshot longtext NOT NULL,
            restored tinyint(1) NOT NULL DEFAULT 0,
            restored_by varchar(100) DEFAULT NULL,
            restored_by_name varchar(100) DEFAULT NULL,
            restored_by_role varchar(50) DEFAULT NULL,
            restored_at datetime DEFAULT NULL,
            PRIMARY KEY (recycle_id),
            KEY idx_registry_recycle_regno (regNo),
            KEY idx_registry_recycle_restored (restored),
            KEY idx_registry_recycle_deleted_at (deleted_at),
            KEY idx_registry_recycle_request (delete_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function archiveRegistryRecordToRecycleBin(
    mysqli $conn,
    array $registryRecord,
    string $deletedBy,
    string $deletedByName,
    string $deletedByRole,
    string $reason = '',
    ?int $deleteRequestId = null
): array {
    ensureRegistryRecycleBinTable($conn);

    $regNo = trim((string)($registryRecord['regNo'] ?? ''));
    if ($regNo === '') {
        return ['success' => false, 'message' => 'Registry record has no file number for recycle bin archiving.'];
    }

    $staffName = trim(trim((string)($registryRecord['sName'] ?? '')) . ' ' . trim((string)($registryRecord['fName'] ?? '')));
    $staffTitle = trim((string)($registryRecord['title'] ?? ''));
    $snapshot = json_encode($registryRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($snapshot === false || $snapshot === '') {
        return ['success' => false, 'message' => 'Failed to serialize registry snapshot for recycle bin.'];
    }

    $registryId = (int)($registryRecord['id'] ?? 0);
    $requestIdValue = ($deleteRequestId !== null && $deleteRequestId > 0) ? $deleteRequestId : null;

    $stmt = $conn->prepare("
        INSERT INTO tb_file_registry_recycle_bin (
            registry_id, regNo, staff_name, staff_title, delete_request_id, delete_reason,
            deleted_by, deleted_by_name, deleted_by_role, record_snapshot
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return ['success' => false, 'message' => 'Failed to prepare recycle bin insert statement.'];
    }
    $stmt->bind_param(
        'isssisssss',
        $registryId,
        $regNo,
        $staffName,
        $staffTitle,
        $requestIdValue,
        $reason,
        $deletedBy,
        $deletedByName,
        $deletedByRole,
        $snapshot
    );
    $ok = $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();

    if (!$ok) {
        return ['success' => false, 'message' => 'Failed to archive deleted registry record.'];
    }

    return ['success' => true, 'recycle_id' => $newId];
}

function softDeleteRegistryRecord(
    mysqli $conn,
    int $registryId,
    string $deletedBy,
    string $deletedByName,
    string $deletedByRole,
    string $reason = '',
    ?int $deleteRequestId = null
): array {
    ensureFileMovementTables($conn);
    ensureRegistryRecycleBinTable($conn);

    if ($registryId <= 0) {
        return ['success' => false, 'message' => 'Invalid registry record.'];
    }

    $lookupStmt = $conn->prepare("
        SELECT *
        FROM tb_fileregistry
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    if (!$lookupStmt) {
        return ['success' => false, 'message' => 'Failed to prepare registry lookup.'];
    }
    $lookupStmt->bind_param('i', $registryId);
    $lookupStmt->execute();
    $record = $lookupStmt->get_result()->fetch_assoc();
    $lookupStmt->close();

    if (!$record) {
        return ['success' => false, 'message' => 'Registry record not found.'];
    }

    if ((int)($record['is_deleted'] ?? 0) === 1) {
        return ['success' => true, 'already_deleted' => true, 'regNo' => (string)($record['regNo'] ?? '')];
    }

    $archiveResult = archiveRegistryRecordToRecycleBin($conn, $record, $deletedBy, $deletedByName, $deletedByRole, $reason, $deleteRequestId);
    if (empty($archiveResult['success'])) {
        return ['success' => false, 'message' => $archiveResult['message'] ?? 'Failed to archive registry record.'];
    }

    $updateStmt = $conn->prepare("
        UPDATE tb_fileregistry
        SET is_deleted = 1,
            deleted_at = NOW(),
            deleted_by = ?,
            deleted_by_name = ?,
            deleted_by_role = ?,
            delete_reason = ?
        WHERE id = ?
          AND COALESCE(is_deleted, 0) = 0
    ");
    if (!$updateStmt) {
        return ['success' => false, 'message' => 'Failed to prepare registry soft delete update.'];
    }
    $updateStmt->bind_param('ssssi', $deletedBy, $deletedByName, $deletedByRole, $reason, $registryId);
    $updateStmt->execute();
    $affected = (int)$updateStmt->affected_rows;
    $updateStmt->close();

    return [
        'success' => true,
        'deleted' => $affected > 0,
        'recycle_id' => (int)($archiveResult['recycle_id'] ?? 0),
        'regNo' => (string)($record['regNo'] ?? '')
    ];
}

function restoreRegistryRecordFromRecycleBin(
    mysqli $conn,
    int $recycleId,
    string $restoredBy,
    string $restoredByName,
    string $restoredByRole
): array {
    ensureRegistryRecycleBinTable($conn);

    if ($recycleId <= 0) {
        return ['success' => false, 'message' => 'Invalid recycle bin record.'];
    }

    $selectStmt = $conn->prepare("
        SELECT recycle_id, regNo, record_snapshot, restored
        FROM tb_file_registry_recycle_bin
        WHERE recycle_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    if (!$selectStmt) {
        return ['success' => false, 'message' => 'Failed to prepare recycle bin lookup.'];
    }
    $selectStmt->bind_param('i', $recycleId);
    $selectStmt->execute();
    $row = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();

    if (!$row) {
        return ['success' => false, 'message' => 'Recycle bin record not found.'];
    }
    if ((int)($row['restored'] ?? 0) === 1) {
        return ['success' => false, 'message' => 'This recycle bin record has already been restored.'];
    }

    $snapshot = json_decode((string)($row['record_snapshot'] ?? ''), true);
    if (!is_array($snapshot)) {
        return ['success' => false, 'message' => 'Invalid recycle bin snapshot data.'];
    }
    $regNo = trim((string)($snapshot['regNo'] ?? $row['regNo'] ?? ''));
    if ($regNo === '') {
        return ['success' => false, 'message' => 'Snapshot has no file number and cannot be restored.'];
    }

    $existsStmt = $conn->prepare("SELECT id, COALESCE(is_deleted, 0) AS is_deleted FROM tb_fileregistry WHERE regNo = ? LIMIT 1");
    if (!$existsStmt) {
        return ['success' => false, 'message' => 'Failed to validate existing registry record.'];
    }
    $existsStmt->bind_param('s', $regNo);
    $existsStmt->execute();
    $existing = $existsStmt->get_result()->fetch_assoc();
    $existsStmt->close();
    if ($existing && (int)($existing['is_deleted'] ?? 0) !== 1) {
        return ['success' => false, 'message' => 'A registry record with this file number already exists.'];
    }

    if ($existing && (int)($existing['is_deleted'] ?? 0) === 1) {
        $restoreExistingStmt = $conn->prepare("
            UPDATE tb_fileregistry
            SET is_deleted = 0,
                deleted_at = NULL,
                deleted_by = NULL,
                deleted_by_name = NULL,
                deleted_by_role = NULL,
                delete_reason = NULL
            WHERE id = ?
        ");
        if (!$restoreExistingStmt) {
            return ['success' => false, 'message' => 'Failed to restore soft-deleted registry record.'];
        }
        $existingId = (int)$existing['id'];
        $restoreExistingStmt->bind_param('i', $existingId);
        $okExisting = $restoreExistingStmt->execute();
        $restoreExistingStmt->close();
        if (!$okExisting) {
            return ['success' => false, 'message' => 'Failed to restore soft-deleted registry record.'];
        }

        $markStmt = $conn->prepare("
            UPDATE tb_file_registry_recycle_bin
            SET restored = 1,
                restored_by = ?,
                restored_by_name = ?,
                restored_by_role = ?,
                restored_at = NOW()
            WHERE recycle_id = ?
        ");
        if (!$markStmt) {
            return ['success' => false, 'message' => 'Registry restored, but failed to update recycle bin status.'];
        }
        $markStmt->bind_param('sssi', $restoredBy, $restoredByName, $restoredByRole, $recycleId);
        $markStmt->execute();
        $markStmt->close();

        if (function_exists('upsertPensionerUserFromRegistry')) {
            $syncResult = upsertPensionerUserFromRegistry($conn, $regNo, 'Pensioner123', $restoredBy);
            if (empty($syncResult['success'])) {
                return ['success' => false, 'message' => $syncResult['message'] ?? 'Registry restored but pensioner account sync failed.'];
            }
        }

        return ['success' => true, 'regNo' => $regNo];
    }

    static $registryColumns = null;
    if ($registryColumns === null) {
        $registryColumns = [];
        $colRes = $conn->query("SHOW COLUMNS FROM tb_fileregistry");
        while ($colRes && ($col = $colRes->fetch_assoc())) {
            $fieldName = (string)($col['Field'] ?? '');
            if ($fieldName !== '' && strtolower($fieldName) !== 'id') {
                $registryColumns[$fieldName] = true;
            }
        }
    }

    $insertFields = [];
    $insertValues = [];
    foreach ($snapshot as $field => $value) {
        if (!isset($registryColumns[$field])) {
            continue;
        }
        if ($value === null || $value === '') {
            continue;
        }
        $insertFields[] = $field;
        $insertValues[] = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    if (!in_array('regNo', $insertFields, true)) {
        $insertFields[] = 'regNo';
        $insertValues[] = $regNo;
    }

    if (empty($insertFields)) {
        return ['success' => false, 'message' => 'No restorable fields were found in snapshot.'];
    }

    $placeholders = implode(', ', array_fill(0, count($insertFields), '?'));
    $sql = "INSERT INTO tb_fileregistry (" . implode(', ', $insertFields) . ") VALUES ({$placeholders})";
    $insertStmt = $conn->prepare($sql);
    if (!$insertStmt) {
        return ['success' => false, 'message' => 'Failed to prepare registry restore statement.'];
    }
    $types = str_repeat('s', count($insertValues));
    $refs = [];
    $refs[] = &$types;
    foreach ($insertValues as $idx => &$value) {
        $refs[] = &$value;
    }
    call_user_func_array([$insertStmt, 'bind_param'], $refs);
    $ok = $insertStmt->execute();
    $insertStmt->close();
    if (!$ok) {
        return ['success' => false, 'message' => 'Failed to restore registry record from recycle bin.'];
    }

    $markStmt = $conn->prepare("
        UPDATE tb_file_registry_recycle_bin
        SET restored = 1,
            restored_by = ?,
            restored_by_name = ?,
            restored_by_role = ?,
            restored_at = NOW()
        WHERE recycle_id = ?
    ");
    if (!$markStmt) {
        return ['success' => false, 'message' => 'Registry restored, but failed to update recycle bin status.'];
    }
    $markStmt->bind_param('sssi', $restoredBy, $restoredByName, $restoredByRole, $recycleId);
    $markStmt->execute();
    $markStmt->close();

    if (function_exists('upsertPensionerUserFromRegistry')) {
        $syncResult = upsertPensionerUserFromRegistry($conn, $regNo, 'Pensioner123', $restoredBy);
        if (empty($syncResult['success'])) {
            return ['success' => false, 'message' => $syncResult['message'] ?? 'Registry restored but pensioner account sync failed.'];
        }
    }

    return ['success' => true, 'regNo' => $regNo];
}

function clearRegistryRecycleBinItem(
    mysqli $conn,
    int $recycleId
): array {
    ensureRegistryRecycleBinTable($conn);

    if ($recycleId <= 0) {
        return ['success' => false, 'message' => 'Invalid recycle bin record.'];
    }

    $selectStmt = $conn->prepare("
        SELECT recycle_id, registry_id, regNo, staff_name, staff_title, delete_request_id, restored
        FROM tb_file_registry_recycle_bin
        WHERE recycle_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    if (!$selectStmt) {
        return ['success' => false, 'message' => 'Failed to prepare recycle bin lookup.'];
    }
    $selectStmt->bind_param('i', $recycleId);
    $selectStmt->execute();
    $row = $selectStmt->get_result()->fetch_assoc();
    $selectStmt->close();

    if (!$row) {
        return ['success' => false, 'message' => 'Recycle bin record not found.'];
    }

    $registryId = (int)($row['registry_id'] ?? 0);
    $restored = ((int)($row['restored'] ?? 0)) === 1;
    if (!$restored && $registryId > 0) {
        $purgeRegistryStmt = $conn->prepare("
            DELETE FROM tb_fileregistry
            WHERE id = ?
              AND COALESCE(is_deleted, 0) = 1
            LIMIT 1
        ");
        if ($purgeRegistryStmt) {
            $purgeRegistryStmt->bind_param('i', $registryId);
            $purgeRegistryStmt->execute();
            $purgeRegistryStmt->close();
        }
    }

    $deleteStmt = $conn->prepare("
        DELETE FROM tb_file_registry_recycle_bin
        WHERE recycle_id = ?
        LIMIT 1
    ");
    if (!$deleteStmt) {
        return ['success' => false, 'message' => 'Failed to prepare recycle bin delete statement.'];
    }
    $deleteStmt->bind_param('i', $recycleId);
    $ok = $deleteStmt->execute();
    $affected = (int)$deleteStmt->affected_rows;
    $deleteStmt->close();

    if (!$ok || $affected <= 0) {
        return ['success' => false, 'message' => 'Failed to clear recycle bin record.'];
    }

    return [
        'success' => true,
        'recycle_id' => (int)$row['recycle_id'],
        'regNo' => (string)($row['regNo'] ?? ''),
        'staff_name' => (string)($row['staff_name'] ?? ''),
        'staff_title' => (string)($row['staff_title'] ?? ''),
        'delete_request_id' => (int)($row['delete_request_id'] ?? 0),
        'restored' => $restored
    ];
}

function ensureAppnStatusTrackingColumns(mysqli $conn): void {
    static $checked = false;
    if ($checked) {
        return;
    }

    $steps = ['verification', 'writeUp', 'fileCreation', 'entrantAllocation', 'dataCapture', 'assessment', 'audit', 'approval'];
    foreach ($steps as $step) {
        $columns = [
            "{$step}_at" => "DATETIME DEFAULT NULL",
            "{$step}_by" => "VARCHAR(100) DEFAULT NULL",
            "{$step}_comment" => "TEXT DEFAULT NULL"
        ];
        foreach ($columns as $column => $definition) {
            $result = $conn->query("SHOW COLUMNS FROM tb_appnstatus LIKE '{$column}'");
            if ($result && $result->num_rows === 0) {
                $conn->query("ALTER TABLE tb_appnstatus ADD COLUMN {$column} {$definition}");
            }
        }
    }

    $checked = true;
}

function upsertAppnStatus(mysqli $conn, string $regNo): void {
    if ($regNo === '') {
        return;
    }
    $stmt = $conn->prepare("
        INSERT INTO tb_appnstatus (regNo)
        VALUES (?)
        ON DUPLICATE KEY UPDATE regNo = VALUES(regNo)
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("s", $regNo);
    $stmt->execute();
    $stmt->close();
}

function updateAppnStatusStep(mysqli $conn, string $regNo, string $step, string $status, ?string $comment, ?string $userId): void {
    ensureAppnStatusTrackingColumns($conn);
    upsertAppnStatus($conn, $regNo);

    $allowed = ['verification', 'writeUp', 'fileCreation', 'entrantAllocation', 'dataCapture', 'assessment', 'audit', 'approval'];
    if (!in_array($step, $allowed, true)) {
        return;
    }

    $column = $step;
    $columnAt = "{$step}_at";
    $columnBy = "{$step}_by";
    $columnComment = "{$step}_comment";

    $stmt = $conn->prepare("
        UPDATE tb_appnstatus
        SET {$column} = ?,
            {$columnAt} = NOW(),
            {$columnBy} = ?,
            {$columnComment} = ?
        WHERE regNo = ?
    ");
    if (!$stmt) {
        return;
    }
    $commentValue = $comment ?: null;
    $stmt->bind_param("ssss", $status, $userId, $commentValue, $regNo);
    $stmt->execute();
    $stmt->close();
}

function ensureTitlesTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_titles (
            title_id int(11) NOT NULL AUTO_INCREMENT,
            title_name varchar(120) NOT NULL,
            category enum('uniformed','non_uniformed') NOT NULL DEFAULT 'uniformed',
            level enum('junior','senior') NOT NULL DEFAULT 'junior',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (title_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    seedDefaultTitles($conn);
    $created = true;
}

function ensureBanksTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_banks (
            bank_id int(11) NOT NULL AUTO_INCREMENT,
            bank_name varchar(180) NOT NULL,
            short_name varchar(100) DEFAULT NULL,
            bank_code varchar(30) DEFAULT NULL,
            display_order int(11) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (bank_id),
            UNIQUE KEY uq_tb_banks_name (bank_name),
            KEY idx_tb_banks_active_order (is_active, display_order, bank_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    seedDefaultBanks($conn);
    $created = true;
}

function seedDefaultTitles(mysqli $conn): void {
    $result = $conn->query("SELECT COUNT(*) as total FROM tb_titles");
    if (!$result) {
        return;
    }
    $row = $result->fetch_assoc();
    $count = (int)($row['total'] ?? 0);
    if ($count > 0) {
        return;
    }

    $defaults = [
        ['Warder', 'uniformed', 'junior'],
        ['Wardress', 'uniformed', 'junior'],
        ['Lance Corporal', 'uniformed', 'junior'],
        ['Corporal Warder', 'uniformed', 'junior'],
        ['Corporal Wardress', 'uniformed', 'junior'],
        ['Sergeant Warder', 'uniformed', 'junior'],
        ['Sergeant Wardress', 'uniformed', 'junior'],
        ['Chief Warder III', 'uniformed', 'junior'],
        ['Chief Wardress III', 'uniformed', 'junior'],
        ['Chief Warder II', 'uniformed', 'junior'],
        ['Chief Wardress II', 'uniformed', 'junior'],
        ['Chief Warder I', 'uniformed', 'junior'],
        ['Chief Wardress I', 'uniformed', 'junior'],
        ['Cadet Principal Officer', 'uniformed', 'junior'],
        ['Principal Officer II', 'uniformed', 'junior'],
        ['Principal Officer I', 'uniformed', 'junior'],
        ['Cadet Assistant Superintendent of Prisons', 'uniformed', 'senior'],
        ['Assistant Superintendent of Prisons', 'uniformed', 'senior'],
        ['Senior Assistant Superintendent of Prisons', 'uniformed', 'senior'],
        ['Superintendent of Prisons', 'uniformed', 'senior'],
        ['Senior Superintendent of Prisons', 'uniformed', 'senior'],
        ['Assistant Commissioner of Prisons', 'uniformed', 'senior'],
        ['Commissioner of Prisons', 'uniformed', 'senior'],
        ['Senior Commissioner of Prisons', 'uniformed', 'senior'],
        ['Assistant Commissioner General of Prisons', 'uniformed', 'senior'],
        ['Deputy Commissioner General of Prisons', 'uniformed', 'senior'],
        ['Commissioner General of Prisons', 'uniformed', 'senior'],
        ['Office Attendant', 'non_uniformed', 'junior'],
        ['Human Resource Officer', 'non_uniformed', 'junior'],
        ['Senior Human Resource Officer', 'non_uniformed', 'senior'],
        ['Principal Human Resource Officer', 'non_uniformed', 'senior'],
        ['Assistant Commissioner', 'non_uniformed', 'senior'],
        ['Commissioner', 'non_uniformed', 'senior'],
        ['Enrolled Mid Wife', 'non_uniformed', 'junior'],
        ['Medical Officer', 'non_uniformed', 'senior'],
        ['Rehabilitation and Reintegration Officer', 'non_uniformed', 'junior'],
        ['Senior Rehabilitation and Reintegration Officer', 'non_uniformed', 'senior'],
        ['Principal Rehabilitation and Reintegration Officer', 'non_uniformed', 'senior'],
        ['Medical Superintendent', 'non_uniformed', 'senior'],
        ['Nursing Officer', 'non_uniformed', 'junior'],
        ['Senior Nursing Officer', 'non_uniformed', 'senior'],
        ['Instructor I', 'non_uniformed', 'junior'],
        ['Instructor II', 'non_uniformed', 'junior'],
        ['Artisan', 'non_uniformed', 'junior']
    ];

    $stmt = $conn->prepare("
        INSERT INTO tb_titles (title_name, category, level, is_active)
        VALUES (?, ?, ?, 1)
    ");
    if (!$stmt) {
        return;
    }

    foreach ($defaults as $row) {
        $stmt->bind_param("sss", $row[0], $row[1], $row[2]);
        $stmt->execute();
    }
    $stmt->close();
}

function seedDefaultBanks(mysqli $conn): void {
    $defaults = [
        ['Absa Bank Uganda Limited', 'Absa Bank', 'ABSA', 1],
        ['Bank of Africa Uganda Limited', 'Bank of Africa', 'BOA', 2],
        ['Bank of Baroda Uganda Limited', 'Bank of Baroda', 'BOB', 3],
        ['Bank of India (Uganda) Limited', 'Bank of India Uganda', 'BOIUG', 4],
        ['Cairo Bank Uganda Limited', 'Cairo Bank', 'CAIRO', 5],
        ['Centenary Rural Development Bank Limited', 'Centenary Bank', 'CENTENARY', 6],
        ['Citibank Uganda Limited', 'Citibank', 'CITI', 7],
        ['DFCU Bank Limited', 'DFCU Bank', 'DFCU', 8],
        ['Diamond Trust Bank Uganda Limited', 'Diamond Trust Bank', 'DTB', 9],
        ['Ecobank Uganda Limited', 'Ecobank', 'ECOBANK', 10],
        ['Equity Bank Uganda Limited', 'Equity Bank', 'EQUITY', 11],
        ['Exim Bank Uganda Limited', 'Exim Bank', 'EXIM', 12],
        ['Housing Finance Bank Uganda Limited', 'Housing Finance Bank', 'HFB', 13],
        ['I&M Bank (Uganda) Limited', 'I&M Bank Uganda', 'IMBANK', 14],
        ['KCB Bank Uganda Limited', 'KCB Bank', 'KCB', 15],
        ['NCBA Bank Uganda Limited', 'NCBA Bank', 'NCBA', 16],
        ['Stanbic Bank Uganda Limited', 'Stanbic Bank', 'STANBIC', 17],
        ['Standard Chartered Bank Uganda Limited', 'Standard Chartered', 'SCB', 18],
        ['Tropical Bank Limited', 'Tropical Bank', 'TROPICAL', 19],
        ['United Bank for Africa Uganda Limited', 'UBA Uganda', 'UBA', 20],
        ['PostBank Uganda Limited', 'PostBank', 'POSTBANK', 21],
        ['Finance Trust Bank', 'Finance Trust', 'FTB', 22],
        ['Guaranty Trust Bank (GTBank)', 'GTBank', 'GTBANK', 23],
        ['ABC Capital Bank', 'ABC Capital Bank', 'ABC', 24]
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tb_banks (bank_name, short_name, bank_code, display_order, is_active)
        VALUES (?, ?, ?, ?, 1)
    ");
    if (!$stmt) {
        return;
    }

    foreach ($defaults as $row) {
        $stmt->bind_param("sssi", $row[0], $row[1], $row[2], $row[3]);
        $stmt->execute();
    }
    $stmt->close();
}

function getFinancialYearLabelForMonth(int $year, int $month): string {
    if ($month < 1 || $month > 12) {
        $month = (int)date('n');
    }
    $startYear = $month >= 7 ? $year : ($year - 1);
    $endYear = $startYear + 1;
    return "FY {$startYear}/{$endYear}";
}

function getQuarterLabelForMonth(int $month): string {
    if ($month >= 7 && $month <= 9) {
        return 'Q1';
    }
    if ($month >= 10 && $month <= 12) {
        return 'Q2';
    }
    if ($month >= 1 && $month <= 3) {
        return 'Q3';
    }
    return 'Q4';
}

function normalizeRegistryTitle(mysqli $conn, ?string $title): ?string {
    ensureTitlesTable($conn);
    $raw = trim((string)$title);
    if ($raw === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT title_name
        FROM tb_titles
        WHERE is_active = 1
          AND LOWER(title_name) = LOWER(?)
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $raw);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row['title_name'] ?? null;
}

function normalizeBankCatalogName(mysqli $conn, ?string $bankName, bool $activeOnly = false): ?string {
    ensureBanksTable($conn);
    $raw = trim((string)$bankName);
    if ($raw === '') {
        return null;
    }

    $sql = "
        SELECT bank_name
        FROM tb_banks
        WHERE LOWER(bank_name) = LOWER(?)
    ";
    if ($activeOnly) {
        $sql .= " AND is_active = 1 ";
    }
    $sql .= " ORDER BY is_active DESC, display_order ASC, bank_name ASC LIMIT 1 ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("s", $raw);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row['bank_name'] ?? null;
}

function normalizeNationalIdNumber(?string $nin): string {
    return strtoupper(trim((string)$nin));
}

function validateNationalIdNumber(?string $nin, ?string $birthDate = null, ?string $gender = null): array {
    $normalized = normalizeNationalIdNumber($nin);
    if ($normalized === '') {
        return [
            'valid' => true,
            'normalized' => '',
            'message' => null
        ];
    }

    if (!preg_match('/^C[MF][A-Z0-9]{12}$/', $normalized)) {
        return [
            'valid' => false,
            'normalized' => $normalized,
            'message' => 'NIN must start with CM or CF, contain only letters and numbers, and be exactly 14 characters long.'
        ];
    }

    $genderKey = strtolower(trim((string)$gender));
    if ($genderKey !== '') {
        $expectedPrefix = match ($genderKey) {
            'male', 'm' => 'CM',
            'female', 'f' => 'CF',
            default => null
        };
        if ($expectedPrefix !== null && substr($normalized, 0, 2) !== $expectedPrefix) {
            return [
                'valid' => false,
                'normalized' => $normalized,
                'message' => 'NIN prefix must match the selected gender: CM for male and CF for female.'
            ];
        }
    }

    return [
        'valid' => true,
        'normalized' => $normalized,
        'message' => null
    ];
}

function isLifeCertificateExemptRecord(?string $livingStatus, ?string $payType): bool {
    $living = strtolower(trim((string)$livingStatus));
    if ($living === 'deceased' || $living === 'dead' || $living === 'late') {
        return true;
    }

    return normalizeRegistryPayType($payType) === 'One-off Payment';
}

function ensureLifeCertificateTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    ensureFileMovementTables($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_life_certificate_submissions (
            submission_id int(11) NOT NULL AUTO_INCREMENT,
            regNo varchar(50) NOT NULL,
            submission_year int(11) NOT NULL,
            status enum('Submitted') NOT NULL DEFAULT 'Submitted',
            submitted_at datetime NOT NULL DEFAULT current_timestamp(),
            submitted_by varchar(100) DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (submission_id),
            UNIQUE KEY uniq_life_cert_reg_year (regNo, submission_year),
            KEY idx_life_cert_year (submission_year),
            KEY idx_life_cert_regno (regNo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function syncCurrentYearLifeCertificateStatus(mysqli $conn): void {
    ensureLifeCertificateTables($conn);
    $year = (int)date('Y');
    $stmt = $conn->prepare("
        UPDATE tb_fileregistry fr
        LEFT JOIN tb_life_certificate_submissions lcs
          ON lcs.regNo = fr.regNo
         AND lcs.submission_year = ?
        SET fr.lifeCertificate = CASE
            WHEN LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = 'deceased'
              OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                THEN 'Exempt'
            WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
            ELSE 'Not Submitted'
        END
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $stmt->close();
}

function getLifeCertificateSyncDebounceSeconds(mysqli $conn): int {
    $configured = getAppSettingInt($conn, 'life_certificate_sync_debounce_seconds', 300);
    return max(60, min(3600, (int)$configured));
}

function maybeSyncCurrentYearLifeCertificateStatus(mysqli $conn, ?int $minIntervalSeconds = null): array {
    ensureLifeCertificateTables($conn);
    if ($minIntervalSeconds === null || $minIntervalSeconds <= 0) {
        $minIntervalSeconds = getLifeCertificateSyncDebounceSeconds($conn);
    }

    return pgoRunDebouncedMaintenanceTask(
        'life_certificate_status_sync',
        $minIntervalSeconds,
        static function () use ($conn): array {
            syncCurrentYearLifeCertificateStatus($conn);
            return ['year' => (int)date('Y')];
        }
    );
}

function ensurePayrollManagementTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    ensureFileMovementTables($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_payroll_upload_cycles (
            cycle_id int(11) NOT NULL AUTO_INCREMENT,
            payroll_year int(11) NOT NULL,
            payroll_month tinyint(4) NOT NULL,
            financial_year_label varchar(20) NOT NULL,
            quarter_label varchar(6) NOT NULL,
            uploaded_by varchar(100) DEFAULT NULL,
            source_file varchar(255) DEFAULT NULL,
            source_file_original_name varchar(255) DEFAULT NULL,
            source_file_mime varchar(120) DEFAULT NULL,
            payment_register_file varchar(255) DEFAULT NULL,
            payment_register_original_name varchar(255) DEFAULT NULL,
            payment_register_mime varchar(120) DEFAULT NULL,
            is_deleted tinyint(1) NOT NULL DEFAULT 0,
            deleted_by varchar(100) DEFAULT NULL,
            deleted_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (cycle_id),
            KEY idx_payroll_cycle_period (payroll_year, payroll_month),
            KEY idx_payroll_cycle_fy_q (financial_year_label, quarter_label),
            KEY idx_payroll_cycle_active (is_deleted)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_payroll_upload_entries (
            entry_id int(11) NOT NULL AUTO_INCREMENT,
            cycle_id int(11) NOT NULL,
            supplierNo varchar(50) NOT NULL,
            beneficiary_name varchar(150) DEFAULT NULL,
            amount decimal(14,2) NOT NULL DEFAULT 0,
            matched_regNo varchar(50) DEFAULT NULL,
            matched_registry_id int(11) DEFAULT NULL,
            is_matched tinyint(1) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (entry_id),
            KEY idx_payroll_entries_cycle (cycle_id),
            KEY idx_payroll_entries_supplier (supplierNo),
            KEY idx_payroll_entries_match (matched_regNo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_registry_payroll_monthly_status (
            status_id int(11) NOT NULL AUTO_INCREMENT,
            regNo varchar(50) NOT NULL,
            payroll_year int(11) NOT NULL,
            payroll_month tinyint(4) NOT NULL,
            financial_year_label varchar(20) NOT NULL,
            quarter_label varchar(6) NOT NULL,
            payroll_status enum('On Payroll','Not on Payroll') NOT NULL DEFAULT 'Not on Payroll',
            amount decimal(14,2) NOT NULL DEFAULT 0,
            supplierNo varchar(50) DEFAULT NULL,
            cycle_id int(11) DEFAULT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (status_id),
            UNIQUE KEY uniq_registry_payroll_period (regNo, payroll_year, payroll_month),
            KEY idx_registry_payroll_period (payroll_year, payroll_month),
            KEY idx_registry_payroll_fy_q (financial_year_label, quarter_label),
            KEY idx_registry_payroll_status (payroll_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $cycleColumnDefinitions = [
        'source_file_original_name' => "varchar(255) DEFAULT NULL",
        'source_file_mime' => "varchar(120) DEFAULT NULL",
        'payment_register_file' => "varchar(255) DEFAULT NULL",
        'payment_register_original_name' => "varchar(255) DEFAULT NULL",
        'payment_register_mime' => "varchar(120) DEFAULT NULL",
        'is_deleted' => "tinyint(1) NOT NULL DEFAULT 0",
        'deleted_by' => "varchar(100) DEFAULT NULL",
        'deleted_at' => "datetime DEFAULT NULL"
    ];
    foreach ($cycleColumnDefinitions as $column => $definition) {
        $columnResult = $conn->query("SHOW COLUMNS FROM tb_payroll_upload_cycles LIKE '{$column}'");
        if ($columnResult && $columnResult->num_rows === 0) {
            $conn->query("ALTER TABLE tb_payroll_upload_cycles ADD COLUMN {$column} {$definition}");
        }
        if ($columnResult) {
            $columnResult->close();
        }
    }
    $activeIndexResult = $conn->query("SHOW INDEX FROM tb_payroll_upload_cycles WHERE Key_name = 'idx_payroll_cycle_active'");
    if ($activeIndexResult && $activeIndexResult->num_rows === 0) {
        $conn->query("ALTER TABLE tb_payroll_upload_cycles ADD KEY idx_payroll_cycle_active (is_deleted)");
    }
    if ($activeIndexResult) {
        $activeIndexResult->close();
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_payroll_audit_logs (
            audit_id int(11) NOT NULL AUTO_INCREMENT,
            cycle_id int(11) DEFAULT NULL,
            action varchar(64) NOT NULL,
            actor_user_id varchar(100) DEFAULT NULL,
            actor_role varchar(50) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            details text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (audit_id),
            KEY idx_payroll_audit_cycle (cycle_id),
            KEY idx_payroll_audit_actor (actor_user_id),
            KEY idx_payroll_audit_action (action),
            KEY idx_payroll_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function logPayrollAudit(mysqli $conn, array $payload): void {
    ensurePayrollManagementTables($conn);

    $cycleId = isset($payload['cycle_id']) ? (int)$payload['cycle_id'] : null;
    $action = trim((string)($payload['action'] ?? 'unknown_action'));
    if ($action === '') {
        $action = 'unknown_action';
    }
    $actorUserId = trim((string)($payload['actor_user_id'] ?? ($_SESSION['userId'] ?? '')));
    $actorRole = trim((string)($payload['actor_role'] ?? ($_SESSION['userRole'] ?? '')));
    $ipAddress = trim((string)($payload['ip_address'] ?? getClientIP()));

    $detailsValue = $payload['details'] ?? '';
    if (is_array($detailsValue) || is_object($detailsValue)) {
        $detailsValue = formatAuditDetails((array)$detailsValue, $action, 'payroll_cycle', $cycleId !== null ? (string)$cycleId : null);
    } else {
        $detailsValue = trim((string)$detailsValue);
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_payroll_audit_logs
        (cycle_id, action, actor_user_id, actor_role, ip_address, details)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        "isssss",
        $cycleId,
        $action,
        $actorUserId,
        $actorRole,
        $ipAddress,
        $detailsValue
    );
    $stmt->execute();
    $stmt->close();
}

function applyPayrollCycleToRegistry(mysqli $conn, int $cycleId, int $payrollYear, int $payrollMonth): array {
    ensurePayrollManagementTables($conn);

    $fyLabel = getFinancialYearLabelForMonth($payrollYear, $payrollMonth);
    $quarter = getQuarterLabelForMonth($payrollMonth);

    $registryRows = [];
    $supplierMap = [];
    $registryQuery = $conn->query("
        SELECT id, regNo, supplierNo
        FROM tb_fileregistry
        WHERE regNo IS NOT NULL
          AND TRIM(regNo) <> ''
    ");
    if ($registryQuery) {
        while ($row = $registryQuery->fetch_assoc()) {
            $registryRows[] = $row;
            $supplierKey = strtolower(trim((string)($row['supplierNo'] ?? '')));
            if ($supplierKey !== '') {
                if (!isset($supplierMap[$supplierKey])) {
                    $supplierMap[$supplierKey] = [];
                }
                $supplierMap[$supplierKey][] = $row;
            }
        }
    }

    $entryStmt = $conn->prepare("
        SELECT entry_id, supplierNo, beneficiary_name, amount
        FROM tb_payroll_upload_entries
        WHERE cycle_id = ?
    ");
    if (!$entryStmt) {
        return ['matched' => 0, 'unmatched' => 0, 'on_payroll' => 0, 'off_payroll' => 0];
    }
    $entryStmt->bind_param("i", $cycleId);
    $entryStmt->execute();
    $entryResult = $entryStmt->get_result();

    $updateEntryStmt = $conn->prepare("
        UPDATE tb_payroll_upload_entries
        SET matched_regNo = ?, matched_registry_id = ?, is_matched = ?
        WHERE entry_id = ?
    ");
    $matchedByRegNo = [];
    $matchedEntries = 0;
    $unmatchedEntries = 0;

    while ($entry = $entryResult->fetch_assoc()) {
        $entryId = (int)($entry['entry_id'] ?? 0);
        $supplierNo = strtolower(trim((string)($entry['supplierNo'] ?? '')));
        $amount = (float)($entry['amount'] ?? 0);

        $matchedRegNo = null;
        $matchedRegistryId = null;
        $isMatched = 0;

        if ($supplierNo !== '' && isset($supplierMap[$supplierNo]) && !empty($supplierMap[$supplierNo])) {
            $candidate = $supplierMap[$supplierNo][0];
            $matchedRegNo = (string)($candidate['regNo'] ?? '');
            $matchedRegistryId = (int)($candidate['id'] ?? 0);
            if ($matchedRegNo !== '') {
                $isMatched = 1;
                $matchedEntries++;
                if (!isset($matchedByRegNo[$matchedRegNo])) {
                    $matchedByRegNo[$matchedRegNo] = 0.0;
                }
                $matchedByRegNo[$matchedRegNo] += $amount;
            }
        }

        if ($isMatched === 0) {
            $unmatchedEntries++;
        }

        if ($updateEntryStmt) {
            $updateEntryStmt->bind_param(
                "siii",
                $matchedRegNo,
                $matchedRegistryId,
                $isMatched,
                $entryId
            );
            $updateEntryStmt->execute();
        }
    }
    $entryStmt->close();
    if ($updateEntryStmt) {
        $updateEntryStmt->close();
    }

    $isLatestCycle = isLatestActivePayrollCycle($conn, $cycleId);
    if ($isLatestCycle) {
        $conn->query("UPDATE tb_fileregistry SET payrollStatus = 'Not on Payroll'");
        if (!empty($matchedByRegNo)) {
            $regNos = array_keys($matchedByRegNo);
            $in = implode(',', array_fill(0, count($regNos), '?'));
            $types = str_repeat('s', count($regNos));
            $updatePayroll = $conn->prepare("UPDATE tb_fileregistry SET payrollStatus = 'On Payroll' WHERE regNo IN ({$in})");
            if ($updatePayroll) {
                $bind = [$types];
                foreach ($regNos as $key => $value) {
                    $bind[] = &$regNos[$key];
                }
                call_user_func_array([$updatePayroll, 'bind_param'], $bind);
                $updatePayroll->execute();
                $updatePayroll->close();
            }
        }
    }

    $insertMonthly = $conn->prepare("
        INSERT INTO tb_registry_payroll_monthly_status
        (regNo, payroll_year, payroll_month, financial_year_label, quarter_label, payroll_status, amount, supplierNo, cycle_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            payroll_status = VALUES(payroll_status),
            amount = VALUES(amount),
            supplierNo = VALUES(supplierNo),
            cycle_id = VALUES(cycle_id),
            financial_year_label = VALUES(financial_year_label),
            quarter_label = VALUES(quarter_label),
            updated_at = NOW()
    ");

    $onPayrollCount = 0;
    $offPayrollCount = 0;

    if ($insertMonthly) {
        foreach ($registryRows as $row) {
            $regNo = (string)($row['regNo'] ?? '');
            if ($regNo === '') {
                continue;
            }
            $supplierNo = (string)($row['supplierNo'] ?? '');
            $amount = (float)($matchedByRegNo[$regNo] ?? 0);
            $status = $amount > 0 ? 'On Payroll' : 'Not on Payroll';
            if ($status === 'On Payroll') {
                $onPayrollCount++;
            } else {
                $offPayrollCount++;
            }
            $insertMonthly->bind_param(
                "siisssisi",
                $regNo,
                $payrollYear,
                $payrollMonth,
                $fyLabel,
                $quarter,
                $status,
                $amount,
                $supplierNo,
                $cycleId
            );
            $insertMonthly->execute();
        }
        $insertMonthly->close();
    }

    return [
        'matched' => $matchedEntries,
        'unmatched' => $unmatchedEntries,
        'on_payroll' => $onPayrollCount,
        'off_payroll' => $offPayrollCount,
        'financial_year' => $fyLabel,
        'quarter' => $quarter,
        'applied_to_current_status' => $isLatestCycle
    ];
}

function getLatestActivePayrollCycleInfo(mysqli $conn): ?array {
    ensurePayrollManagementTables($conn);

    $row = null;
    $result = $conn->query("
        SELECT cycle_id, payroll_year, payroll_month
        FROM tb_payroll_upload_cycles
        WHERE COALESCE(is_deleted, 0) = 0
        ORDER BY payroll_year DESC, payroll_month DESC, cycle_id DESC
        LIMIT 1
    ");
    if ($result) {
        $row = $result->fetch_assoc() ?: null;
        $result->free();
    }

    if (!$row) {
        return null;
    }

    return [
        'cycle_id' => (int)($row['cycle_id'] ?? 0),
        'payroll_year' => (int)($row['payroll_year'] ?? 0),
        'payroll_month' => (int)($row['payroll_month'] ?? 0)
    ];
}

function isLatestActivePayrollCycle(mysqli $conn, int $cycleId): bool {
    if ($cycleId <= 0) {
        return false;
    }
    $latest = getLatestActivePayrollCycleInfo($conn);
    if ($latest === null) {
        return false;
    }
    return (int)($latest['cycle_id'] ?? 0) === $cycleId;
}

function reconcileLatestActivePayrollCycleToRegistry(mysqli $conn): array {
    ensurePayrollManagementTables($conn);

    $latest = getLatestActivePayrollCycleInfo($conn);
    if ($latest === null) {
        $conn->query("UPDATE tb_fileregistry SET payrollStatus = 'Not on Payroll'");
        return [
            'reconciled_cycles' => 0,
            'last_cycle_id' => null,
            'last_stats' => null,
            'source' => 'none'
        ];
    }

    $cycleId = (int)($latest['cycle_id'] ?? 0);
    $payrollYear = (int)($latest['payroll_year'] ?? 0);
    $payrollMonth = (int)($latest['payroll_month'] ?? 0);
    if ($cycleId <= 0 || $payrollYear <= 0 || $payrollMonth <= 0) {
        $conn->query("UPDATE tb_fileregistry SET payrollStatus = 'Not on Payroll'");
        return [
            'reconciled_cycles' => 0,
            'last_cycle_id' => null,
            'last_stats' => null,
            'source' => 'invalid_latest'
        ];
    }

    $lastStats = applyPayrollCycleToRegistry($conn, $cycleId, $payrollYear, $payrollMonth);
    return [
        'reconciled_cycles' => 1,
        'last_cycle_id' => $cycleId,
        'last_stats' => $lastStats,
        'source' => 'latest_only',
        'last_payroll_year' => $payrollYear,
        'last_payroll_month' => $payrollMonth
    ];
}

/**
 * Rebuild payroll-vs-registry matching from all active uploaded cycles.
 *
 * Why this exists:
 * - Payroll can be uploaded before some registry files are created.
 * - When new registry records are added later, we need to re-run matching so
 *   those files can be picked up by existing payroll cycles.
 * - We apply cycles in chronological order so the latest cycle determines the
 *   current tb_fileregistry.payrollStatus while monthly snapshots are kept for
 *   each period in tb_registry_payroll_monthly_status.
 */
function reconcileAllActivePayrollCyclesToRegistry(mysqli $conn): array {
    ensurePayrollManagementTables($conn);

    $cycles = [];
    $cycleRes = $conn->query("
        SELECT cycle_id, payroll_year, payroll_month
        FROM tb_payroll_upload_cycles
        WHERE COALESCE(is_deleted, 0) = 0
        ORDER BY payroll_year ASC, payroll_month ASC, cycle_id ASC
    ");
    if ($cycleRes) {
        while ($row = $cycleRes->fetch_assoc()) {
            $cycles[] = [
                'cycle_id' => (int)($row['cycle_id'] ?? 0),
                'payroll_year' => (int)($row['payroll_year'] ?? 0),
                'payroll_month' => (int)($row['payroll_month'] ?? 0)
            ];
        }
        $cycleRes->free();
    }

    if (empty($cycles)) {
        $conn->query("UPDATE tb_fileregistry SET payrollStatus = 'Not on Payroll'");
        return [
            'reconciled_cycles' => 0,
            'last_cycle_id' => null,
            'last_stats' => null
        ];
    }

    $lastStats = null;
    $lastCycleId = null;
    foreach ($cycles as $cycle) {
        $cycleId = (int)($cycle['cycle_id'] ?? 0);
        $payrollYear = (int)($cycle['payroll_year'] ?? 0);
        $payrollMonth = (int)($cycle['payroll_month'] ?? 0);
        if ($cycleId <= 0 || $payrollYear <= 0 || $payrollMonth <= 0) {
            continue;
        }
        $lastStats = applyPayrollCycleToRegistry($conn, $cycleId, $payrollYear, $payrollMonth);
        $lastCycleId = $cycleId;
    }

    return [
        'reconciled_cycles' => count($cycles),
        'last_cycle_id' => $lastCycleId,
        'last_stats' => $lastStats
    ];
}

/**
 * Debounced wrapper around payroll reconciliation.
 *
 * This is intentionally lightweight:
 * - Uses a file lock to ensure only one request reconciles at a time.
 * - Uses a small JSON state file to cache last successful run timestamp.
 * - Skips frequent re-runs within the configured interval under heavy traffic.
 */
function getPayrollReconcileDebounceSeconds(mysqli $conn): int {
    $configured = getAppSettingInt($conn, 'payroll_reconcile_debounce_seconds', 60);
    return max(15, min(900, (int)$configured));
}

function maybeReconcileAllActivePayrollCycles(mysqli $conn, ?int $minIntervalSeconds = null): array {
    ensurePayrollManagementTables($conn);

    if ($minIntervalSeconds === null || $minIntervalSeconds <= 0) {
        $minIntervalSeconds = getPayrollReconcileDebounceSeconds($conn);
    } else {
        $minIntervalSeconds = max(15, min(900, (int)$minIntervalSeconds));
    }
    $cacheDir = __DIR__ . '/cache';
    $stateFile = $cacheDir . '/payroll_reconcile_state.json';
    $lockFile = $cacheDir . '/payroll_reconcile.lock';

    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        // Fail open: if cache path is unavailable, run reconciliation immediately.
        $stats = reconcileLatestActivePayrollCycleToRegistry($conn);
        return ['executed' => true, 'reason' => 'cache_unavailable', 'stats' => $stats];
    }

    $handle = @fopen($lockFile, 'c+');
    if ($handle === false) {
        // Fail open: if lock cannot be established, run reconciliation immediately.
        $stats = reconcileLatestActivePayrollCycleToRegistry($conn);
        return ['executed' => true, 'reason' => 'lock_unavailable', 'stats' => $stats];
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['executed' => false, 'reason' => 'busy'];
    }

    $now = time();
    $lastRun = 0;
    $lastCycleId = 0;
    $lastYear = 0;
    $lastMonth = 0;
    $latest = getLatestActivePayrollCycleInfo($conn);
    $latestCycleId = (int)($latest['cycle_id'] ?? 0);
    $latestYear = (int)($latest['payroll_year'] ?? 0);
    $latestMonth = (int)($latest['payroll_month'] ?? 0);

    if (is_file($stateFile)) {
        $raw = @file_get_contents($stateFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $lastRun = (int)($decoded['last_success_ts'] ?? 0);
                $lastCycleId = (int)($decoded['last_cycle_id'] ?? 0);
                $lastYear = (int)($decoded['last_payroll_year'] ?? 0);
                $lastMonth = (int)($decoded['last_payroll_month'] ?? 0);
            }
        }
    }

    $sameLatestCycle = (
        $lastCycleId === $latestCycleId
        && $lastYear === $latestYear
        && $lastMonth === $latestMonth
    );

    if ($sameLatestCycle && $lastRun > 0 && ($now - $lastRun) < $minIntervalSeconds) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        return ['executed' => false, 'reason' => 'debounced', 'last_success_ts' => $lastRun];
    }

    $started = microtime(true);
    try {
        $stats = reconcileLatestActivePayrollCycleToRegistry($conn);
        $durationMs = (int)round((microtime(true) - $started) * 1000);
        $state = [
            'last_success_ts' => $now,
            'duration_ms' => $durationMs,
            'reconciled_cycles' => (int)($stats['reconciled_cycles'] ?? 0),
            'last_cycle_id' => (int)($stats['last_cycle_id'] ?? 0),
            'last_payroll_year' => (int)($stats['last_payroll_year'] ?? 0),
            'last_payroll_month' => (int)($stats['last_payroll_month'] ?? 0)
        ];
        @file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);

        @flock($handle, LOCK_UN);
        fclose($handle);
        return ['executed' => true, 'reason' => 'ok', 'stats' => $stats, 'duration_ms' => $durationMs];
    } catch (Throwable $e) {
        @flock($handle, LOCK_UN);
        fclose($handle);
        throw $e;
    }
}

function ensureArrearsAndBudgetTables(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    ensurePayrollManagementTables($conn);

    ensureArrearsLedgerTableExists($conn);

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_arrears_payments (
            payment_id int(11) NOT NULL AUTO_INCREMENT,
            regNo varchar(50) NOT NULL,
            claim_type varchar(80) NOT NULL,
            amount decimal(14,2) NOT NULL DEFAULT 0,
            applied_amount decimal(14,2) NOT NULL DEFAULT 0,
            unapplied_amount decimal(14,2) NOT NULL DEFAULT 0,
            payment_date date NOT NULL,
            reference_no varchar(120) DEFAULT NULL,
            notes text DEFAULT NULL,
            recorded_by varchar(100) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (payment_id),
            KEY idx_arrears_payment_reg (regNo),
            KEY idx_arrears_payment_type (claim_type),
            KEY idx_arrears_payment_date (payment_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_arrears_payment_allocations (
            allocation_id int(11) NOT NULL AUTO_INCREMENT,
            payment_id int(11) NOT NULL,
            ledger_id int(11) NOT NULL,
            regNo varchar(50) NOT NULL,
            claim_type varchar(80) NOT NULL,
            applied_amount decimal(14,2) NOT NULL DEFAULT 0,
            accrual_financial_year_label varchar(20) DEFAULT NULL,
            payment_financial_year_label varchar(20) DEFAULT NULL,
            requires_accountability tinyint(1) NOT NULL DEFAULT 0,
            accountability_status enum('Not Required','Pending Accountability','Accountability Submitted') NOT NULL DEFAULT 'Not Required',
            accountability_submission_id int(11) DEFAULT NULL,
            accountability_submitted_at datetime DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (allocation_id),
            KEY idx_arr_payment_alloc_payment (payment_id),
            KEY idx_arr_payment_alloc_ledger (ledger_id),
            KEY idx_arr_payment_alloc_reg_type (regNo, claim_type),
            KEY idx_arr_payment_alloc_status (accountability_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_arrears_accountability_submissions (
            submission_id int(11) NOT NULL AUTO_INCREMENT,
            regNo varchar(50) NOT NULL,
            claim_type varchar(80) NOT NULL,
            payment_id int(11) DEFAULT NULL,
            status enum('Submitted') NOT NULL DEFAULT 'Submitted',
            notes text DEFAULT NULL,
            submitted_by varchar(100) DEFAULT NULL,
            submitted_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (submission_id),
            KEY idx_arr_accountability_reg_type (regNo, claim_type),
            KEY idx_arr_accountability_payment (payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_arrears_accountability_files (
            file_id int(11) NOT NULL AUTO_INCREMENT,
            submission_id int(11) NOT NULL,
            file_name varchar(255) NOT NULL,
            file_path varchar(500) NOT NULL,
            mime_type varchar(120) DEFAULT NULL,
            file_size int(11) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (file_id),
            KEY idx_arr_accountability_files_submission (submission_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_suspension_upload_cycles (
            suspension_cycle_id int(11) NOT NULL AUTO_INCREMENT,
            suspension_year int(11) NOT NULL,
            suspension_month tinyint(4) NOT NULL,
            financial_year_label varchar(20) NOT NULL,
            quarter_label varchar(6) NOT NULL,
            reason_label varchar(120) DEFAULT NULL,
            uploaded_by varchar(100) DEFAULT NULL,
            source_file varchar(255) DEFAULT NULL,
            source_file_original_name varchar(255) DEFAULT NULL,
            source_file_mime varchar(120) DEFAULT NULL,
            notes text DEFAULT NULL,
            is_deleted tinyint(1) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (suspension_cycle_id),
            KEY idx_susp_cycle_period (suspension_year, suspension_month),
            KEY idx_susp_cycle_fy_q (financial_year_label, quarter_label)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_suspension_upload_entries (
            entry_id int(11) NOT NULL AUTO_INCREMENT,
            suspension_cycle_id int(11) NOT NULL,
            regNo varchar(50) DEFAULT NULL,
            supplierNo varchar(50) DEFAULT NULL,
            beneficiary_name varchar(150) DEFAULT NULL,
            amount decimal(14,2) NOT NULL DEFAULT 0,
            reason varchar(255) DEFAULT NULL,
            matched_regNo varchar(50) DEFAULT NULL,
            matched_registry_id int(11) DEFAULT NULL,
            is_matched tinyint(1) NOT NULL DEFAULT 0,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (entry_id),
            KEY idx_susp_entries_cycle (suspension_cycle_id),
            KEY idx_susp_entries_supplier (supplierNo),
            KEY idx_susp_entries_match (matched_regNo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $budgetColumnDefinitions = [
        'estimatedPensionArrears' => "decimal(14,2) DEFAULT NULL",
        'estimatedFullPensionArrears' => "decimal(14,2) DEFAULT NULL",
        'estimatedGratuityArrears' => "decimal(14,2) DEFAULT NULL",
        'estimatedUnderpaymentClaims' => "decimal(14,2) DEFAULT NULL",
        'estimatedSuspensionArrears' => "decimal(14,2) DEFAULT NULL",
        'notes' => "text DEFAULT NULL"
    ];
    foreach ($budgetColumnDefinitions as $column => $definition) {
        $columnResult = $conn->query("SHOW COLUMNS FROM tb_budgetforecast LIKE '{$column}'");
        if ($columnResult && $columnResult->num_rows === 0) {
            $conn->query("ALTER TABLE tb_budgetforecast ADD COLUMN {$column} {$definition}");
        }
        if ($columnResult) {
            $columnResult->close();
        }
    }

    $arrearsLedgerColumns = [
        'claim_status' => "varchar(20) NOT NULL DEFAULT 'Incomplete'",
        'accountability_required' => "tinyint(1) NOT NULL DEFAULT 0",
        'accountability_status' => "varchar(40) DEFAULT NULL",
        'source_type' => "varchar(40) NOT NULL DEFAULT 'missed_payment'",
        'reference_cycle_id' => "int(11) NOT NULL DEFAULT 0",
        'balance_amount' => "decimal(14,2) NOT NULL DEFAULT 0",
        'paid_amount' => "decimal(14,2) NOT NULL DEFAULT 0",
        'status' => "enum('Pending','Partially Paid','Paid','Waived') NOT NULL DEFAULT 'Pending'",
        'financial_year_label' => "varchar(20) NOT NULL DEFAULT ''",
        'quarter_label' => "varchar(6) NOT NULL DEFAULT ''"
    ];
    foreach ($arrearsLedgerColumns as $column => $definition) {
        $columnResult = $conn->query("SHOW COLUMNS FROM tb_arrears_ledger LIKE '{$column}'");
        if ($columnResult && $columnResult->num_rows === 0) {
            $conn->query("ALTER TABLE tb_arrears_ledger ADD COLUMN {$column} {$definition}");
        }
        if ($columnResult) {
            $columnResult->close();
        }
    }

    // Ensure source_type defaults to missed_payment going forward.
    $conn->query("ALTER TABLE tb_arrears_ledger MODIFY COLUMN source_type varchar(40) NOT NULL DEFAULT 'missed_payment'");

    // Migrate legacy manual rows to missed_payment (avoid unique key conflicts).
    $conn->query("
        DELETE l
        FROM tb_arrears_ledger l
        INNER JOIN tb_arrears_ledger m
            ON m.regNo = l.regNo
           AND m.claim_type = l.claim_type
           AND m.period_year = l.period_year
           AND m.period_month = l.period_month
           AND m.reference_cycle_id = l.reference_cycle_id
           AND m.source_type = 'missed_payment'
        WHERE LOWER(l.source_type) = 'manual'
    ");
    $conn->query("
        UPDATE tb_arrears_ledger l
        LEFT JOIN tb_arrears_ledger m
            ON m.regNo = l.regNo
           AND m.claim_type = l.claim_type
           AND m.period_year = l.period_year
           AND m.period_month = l.period_month
           AND m.reference_cycle_id = l.reference_cycle_id
           AND m.source_type = 'missed_payment'
        SET l.source_type = 'missed_payment'
        WHERE LOWER(l.source_type) = 'manual'
          AND m.ledger_id IS NULL
    ");

    $arrearsPaymentColumns = [
        'payment_financial_year_label' => "varchar(20) DEFAULT NULL",
        'accountability_required' => "tinyint(1) NOT NULL DEFAULT 0",
        'accountability_status' => "varchar(40) DEFAULT NULL",
        'accountability_submitted_at' => "datetime DEFAULT NULL",
        'latest_submission_id' => "int(11) DEFAULT NULL"
    ];
    foreach ($arrearsPaymentColumns as $column => $definition) {
        $columnResult = $conn->query("SHOW COLUMNS FROM tb_arrears_payments LIKE '{$column}'");
        if ($columnResult && $columnResult->num_rows === 0) {
            $conn->query("ALTER TABLE tb_arrears_payments ADD COLUMN {$column} {$definition}");
        }
        if ($columnResult) {
            $columnResult->close();
        }
    }

    $suspensionCycleColumns = [
        'reason_label' => "varchar(120) DEFAULT NULL"
    ];
    foreach ($suspensionCycleColumns as $column => $definition) {
        $columnResult = $conn->query("SHOW COLUMNS FROM tb_suspension_upload_cycles LIKE '{$column}'");
        if ($columnResult && $columnResult->num_rows === 0) {
            $conn->query("ALTER TABLE tb_suspension_upload_cycles ADD COLUMN {$column} {$definition}");
        }
        if ($columnResult) {
            $columnResult->close();
        }
    }

    $created = true;
}

function normalizeArrearsClaimType(?string $claimType): string {
    $raw = strtolower(trim((string)$claimType));
    if ($raw === '') {
        return 'Pension Arrears';
    }

    $map = [
        'pension' => 'Pension Arrears',
        'pension arrears' => 'Pension Arrears',
        'gratuity' => 'Gratuity Arrears',
        'gratuity arrears' => 'Gratuity Arrears',
        'full pension' => 'Full Pension',
        'full pension arrears' => 'Full Pension Arrears',
        'pension and gratuity arrears' => 'Pension and Gratuity Arrears',
        'underpayment' => 'Underpayment Claim',
        'underpayment claim' => 'Underpayment Claim',
        'suspension' => 'Pension Arrears',
        'suspension arrears' => 'Pension Arrears',
        'delayed payroll arrears' => 'Pension Arrears',
        'delayed first payroll' => 'Pension Arrears'
    ];

    return $map[$raw] ?? ucwords($raw);
}

function normalizeArrearsSourceType(?string $sourceType): string {
    $raw = strtolower(trim((string)$sourceType));
    if ($raw === '') {
        return 'missed_payment';
    }
    $aliases = [
        'missedpayment' => 'missed_payment',
        'missed payment' => 'missed_payment',
        'missed_payment' => 'missed_payment',
        'manualentry' => 'missed_payment',
        'manual entry' => 'missed_payment',
        'manual' => 'missed_payment'
    ];
    if (isset($aliases[$raw])) {
        return $aliases[$raw];
    }
    return $raw;
}

function normalizeClaimVerificationStatus(?string $status): string {
    $raw = strtolower(trim((string)$status));
    if ($raw === '') {
        return 'Incomplete';
    }
    $map = [
        'complete' => 'Complete',
        'completed' => 'Complete',
        'incomplete' => 'Incomplete',
        'invalid' => 'Invalid',
        'valid' => 'Valid'
    ];
    return $map[$raw] ?? 'Incomplete';
}

function computeArrearsStatus(float $expectedAmount, float $paidAmount): array {
    $expected = max(0, round($expectedAmount, 2));
    $paid = max(0, round($paidAmount, 2));
    $balance = round(max($expected - $paid, 0), 2);

    if ($expected <= 0 && $paid <= 0) {
        return ['status' => 'Pending', 'balance' => 0.0];
    }
    if ($balance <= 0.009) {
        return ['status' => 'Paid', 'balance' => 0.0];
    }
    if ($paid > 0) {
        return ['status' => 'Partially Paid', 'balance' => $balance];
    }
    return ['status' => 'Pending', 'balance' => $balance];
}

function getFinancialYearLabelForDate(string $dateText): string {
    $dateText = trim($dateText);
    if ($dateText === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $dateText) ?: DateTime::createFromFormat('!Y-m-d', $dateText);
    if (!$dt) {
        $timestamp = strtotime($dateText);
        if ($timestamp === false) {
            return '';
        }
        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
    return getFinancialYearLabelForMonth((int)$dt->format('Y'), (int)$dt->format('n'));
}

function requiresArrearsAccountabilityForPeriod(int $periodYear, int $periodMonth, string $paymentDate): bool {
    $accrualFy = getFinancialYearLabelForMonth($periodYear, $periodMonth);
    $paymentFy = getFinancialYearLabelForDate($paymentDate);
    return $accrualFy !== '' && $paymentFy !== '' && $accrualFy !== $paymentFy;
}

function recomputeArrearsLedgerAccountabilityStatus(mysqli $conn, int $ledgerId): void {
    if ($ledgerId <= 0) {
        return;
    }

    ensureArrearsAndBudgetTables($conn);

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(applied_amount), 0) AS applied_total,
            SUM(CASE WHEN requires_accountability = 1 THEN 1 ELSE 0 END) AS required_count,
            SUM(CASE WHEN requires_accountability = 1 AND accountability_status = 'Pending Accountability' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN requires_accountability = 1 AND accountability_status = 'Accountability Submitted' THEN 1 ELSE 0 END) AS submitted_count
        FROM tb_arrears_payment_allocations
        WHERE ledger_id = ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("i", $ledgerId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $appliedTotal = round((float)($stats['applied_total'] ?? 0), 2);
    $requiredCount = (int)($stats['required_count'] ?? 0);
    $pendingCount = (int)($stats['pending_count'] ?? 0);
    $submittedCount = (int)($stats['submitted_count'] ?? 0);

    $status = null;
    $required = 0;
    if ($appliedTotal > 0) {
        if ($requiredCount > 0) {
            $required = 1;
            $status = $pendingCount > 0 ? 'Pending Accountability' : ($submittedCount > 0 ? 'Accountability Submitted' : 'Pending Accountability');
        } else {
            $status = 'No Accountability Required';
        }
    }

    $update = $conn->prepare("UPDATE tb_arrears_ledger SET accountability_required = ?, accountability_status = ? WHERE ledger_id = ?");
    if ($update) {
        $update->bind_param("isi", $required, $status, $ledgerId);
        $update->execute();
        $update->close();
    }
}

function recomputeArrearsPaymentAccountabilityStatus(mysqli $conn, int $paymentId): void {
    if ($paymentId <= 0) {
        return;
    }

    ensureArrearsAndBudgetTables($conn);

    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN requires_accountability = 1 THEN 1 ELSE 0 END) AS required_count,
            SUM(CASE WHEN requires_accountability = 1 AND accountability_status = 'Pending Accountability' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN requires_accountability = 1 AND accountability_status = 'Accountability Submitted' THEN 1 ELSE 0 END) AS submitted_count
        FROM tb_arrears_payment_allocations
        WHERE payment_id = ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param("i", $paymentId);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $requiredCount = (int)($stats['required_count'] ?? 0);
    $pendingCount = (int)($stats['pending_count'] ?? 0);
    $submittedCount = (int)($stats['submitted_count'] ?? 0);

    $required = $requiredCount > 0 ? 1 : 0;
    $status = $required ? ($pendingCount > 0 ? 'Pending Accountability' : ($submittedCount > 0 ? 'Accountability Submitted' : 'Pending Accountability')) : 'No Accountability Required';
    $submittedAt = ($required && $pendingCount === 0 && $submittedCount > 0) ? date('Y-m-d H:i:s') : null;

    $update = $conn->prepare("
        UPDATE tb_arrears_payments
        SET accountability_required = ?, accountability_status = ?, accountability_submitted_at = ?
        WHERE payment_id = ?
    ");
    if ($update) {
        $update->bind_param("issi", $required, $status, $submittedAt, $paymentId);
        $update->execute();
        $update->close();
    }
}

function normalizeSuspensionReasonLabel(string $reasonKey, string $fyLabel = ''): string {
    $normalized = strtolower(trim($reasonKey));
    if ($normalized === 'estate_15_years_elapsed') {
        return 'Estate 15 years elapsed';
    }
    if ($normalized === 'deceased_15_years_elapsed') {
        return 'Deceased 15 years elapsed';
    }
    if ($normalized === 'no_accountability') {
        return $fyLabel !== '' ? 'Did not submit accountability for ' . $fyLabel : 'Did not submit accountability';
    }
    return trim($reasonKey);
}

function submitArrearsAccountability(mysqli $conn, array $payload): array {
    ensureArrearsAndBudgetTables($conn);

    $regNo = trim((string)($payload['regNo'] ?? ''));
    $claimType = normalizeArrearsClaimType((string)($payload['claim_type'] ?? 'Pension Arrears'));
    $paymentId = (int)($payload['payment_id'] ?? 0);
    $notes = trim((string)($payload['notes'] ?? ''));
    $submittedBy = trim((string)($payload['submitted_by'] ?? ($_SESSION['userId'] ?? '')));
    $files = $payload['files'] ?? [];
    if ($regNo === '') {
        return ['success' => false, 'message' => 'Beneficiary file number is required.'];
    }
    if (!is_array($files) || empty($files)) {
        return ['success' => false, 'message' => 'At least one accountability form is required.'];
    }

    $paymentIds = [];
    if ($paymentId > 0) {
        $paymentIds[] = $paymentId;
    } else {
        $lookup = $conn->prepare("
            SELECT payment_id
            FROM tb_arrears_payments
            WHERE regNo = ? AND claim_type = ? AND accountability_required = 1 AND accountability_status = 'Pending Accountability'
            ORDER BY payment_date DESC, payment_id DESC
        ");
        if ($lookup) {
            $lookup->bind_param("ss", $regNo, $claimType);
            $lookup->execute();
            $res = $lookup->get_result();
            while ($row = $res->fetch_assoc()) {
                $candidateId = (int)($row['payment_id'] ?? 0);
                if ($candidateId > 0) {
                    $paymentIds[] = $candidateId;
                }
            }
            $lookup->close();
        }
        if (!empty($paymentIds)) {
            $paymentId = (int)$paymentIds[0];
        }
    }
    if (empty($paymentIds) || $paymentId <= 0) {
        return ['success' => false, 'message' => 'No pending accountability record was found for the selected arrears type.'];
    }

    $uploadDir = __DIR__ . '/uploads/accountability_forms';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return ['success' => false, 'message' => 'Unable to create accountability upload directory.'];
    }

    $savedFiles = [];
    $conn->begin_transaction();
    try {
        $submissionStmt = $conn->prepare("
            INSERT INTO tb_arrears_accountability_submissions
            (regNo, claim_type, payment_id, notes, submitted_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$submissionStmt) {
            throw new RuntimeException('Unable to create accountability submission.');
        }
        $submissionStmt->bind_param("ssiss", $regNo, $claimType, $paymentId, $notes, $submittedBy);
        $submissionStmt->execute();
        $submissionId = (int)$submissionStmt->insert_id;
        $submissionStmt->close();

        $paymentIdSql = implode(',', array_map('intval', $paymentIds));

        $fileStmt = $conn->prepare("
            INSERT INTO tb_arrears_accountability_files
            (submission_id, file_name, file_path, mime_type, file_size)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$fileStmt) {
            throw new RuntimeException('Unable to save accountability file records.');
        }

        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $tmpPath = (string)($file['tmp_name'] ?? '');
            $originalName = trim((string)($file['name'] ?? 'accountability_form'));
            $mimeType = trim((string)($file['type'] ?? ''));
            $fileSize = (int)($file['size'] ?? 0);
            $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($errorCode !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
                throw new RuntimeException('One or more accountability files are invalid.');
            }

            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true)) {
                throw new RuntimeException('Accountability files must be PDF, image, or Word documents.');
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $storedName = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            $absolutePath = $uploadDir . '/' . $storedName;
            $relativePath = 'uploads/accountability_forms/' . $storedName;
            if (!move_uploaded_file($tmpPath, $absolutePath)) {
                throw new RuntimeException('Failed to save accountability form.');
            }

            $fileStmt->bind_param("isssi", $submissionId, $originalName, $relativePath, $mimeType, $fileSize);
            $fileStmt->execute();
            $savedFiles[] = $absolutePath;
        }
        $fileStmt->close();

        $allocUpdate = $conn->prepare("
            UPDATE tb_arrears_payment_allocations
            SET accountability_status = 'Accountability Submitted',
                accountability_submission_id = ?,
                accountability_submitted_at = NOW()
            WHERE payment_id IN ({$paymentIdSql}) AND regNo = ? AND claim_type = ? AND requires_accountability = 1
        ");
        if ($allocUpdate) {
            $allocUpdate->bind_param("iss", $submissionId, $regNo, $claimType);
            $allocUpdate->execute();
            $allocUpdate->close();
        }

        if ($paymentIdSql !== '') {
            $paymentUpdate = $conn->prepare("
                UPDATE tb_arrears_payments
                SET latest_submission_id = ?, accountability_submitted_at = NOW()
                WHERE payment_id IN ({$paymentIdSql})
            ");
            if ($paymentUpdate) {
                $paymentUpdate->bind_param("i", $submissionId);
                $paymentUpdate->execute();
                $paymentUpdate->close();
            }
        }

        $ledgerIds = [];
        $ledgerRes = $conn->query("SELECT DISTINCT ledger_id FROM tb_arrears_payment_allocations WHERE payment_id IN ({$paymentIdSql})");
        if ($ledgerRes) {
            while ($ledgerRow = $ledgerRes->fetch_assoc()) {
                $ledgerIds[] = (int)($ledgerRow['ledger_id'] ?? 0);
            }
            $ledgerRes->free();
        }
        foreach ($ledgerIds as $ledgerId) {
            recomputeArrearsLedgerAccountabilityStatus($conn, $ledgerId);
        }
        foreach ($paymentIds as $accountabilityPaymentId) {
            recomputeArrearsPaymentAccountabilityStatus($conn, (int)$accountabilityPaymentId);
        }

        $conn->commit();
        return [
            'success' => true,
            'message' => 'Accountability submitted successfully.',
            'submission_id' => $submissionId,
            'payment_id' => $paymentId,
            'payment_ids' => $paymentIds
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        foreach ($savedFiles as $savedPath) {
            if (is_file($savedPath)) {
                @unlink($savedPath);
            }
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Retirement month pension eligibility rule:
 * - retirement day 1..14: retirement month is payable
 * - retirement day >=15: retirement month is not payable
 */
function isRetirementMonthPayable(?string $retirementDate): bool {
    $retirementDate = trim((string)$retirementDate);
    if ($retirementDate === '') {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $retirementDate);
    if (!$dt) {
        return false;
    }
    return ((int)$dt->format('j')) < 15;
}

/**
 * Estate earning rule for deceased pensioners:
 * - retirement day >= 15: the retirement month counts as the first payable month
 * - retirement day < 15: payment starts from the next month
 *
 * This rule is intentionally separate from `isRetirementMonthPayable()` because
 * the operational arrears logic in the app already relies on the existing helper.
 */
function isEstateRetirementMonthPayable(?string $retirementDate): bool {
    $retirementDate = trim((string)$retirementDate);
    if ($retirementDate === '') {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $retirementDate);
    if (!$dt) {
        return false;
    }
    return ((int)$dt->format('j')) >= 15;
}

function computeEstateExpiryDate(?string $retirementDate): ?string {
    $retirementDate = trim((string)$retirementDate);
    if ($retirementDate === '') {
        return null;
    }

    $retireDate = DateTime::createFromFormat('Y-m-d', $retirementDate);
    if (!$retireDate) {
        return null;
    }

    $firstPayableMonth = (clone $retireDate)->modify(
        isEstateRetirementMonthPayable($retirementDate) ? 'first day of this month' : 'first day of next month'
    )->setTime(0, 0, 0);

    // The expiry date marks the first day of the first month that is no longer payable
    // after 180 qualifying monthly payments (15 years).
    $expiryDate = (clone $firstPayableMonth)->modify('+180 months');
    return $expiryDate->format('Y-m-d');
}

function calculateYearsBetweenDates(?string $startDate, ?string $endDate, int $precision = 1): ?float {
    $startDate = trim((string)$startDate);
    $endDate = trim((string)$endDate);
    if ($startDate === '' || $endDate === '') {
        return null;
    }

    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end || $end < $start) {
        return null;
    }

    $diff = $start->diff($end);
    $years = (float)$diff->y + ((float)$diff->m / 12) + ((float)$diff->d / 365.25);
    return round($years, max(0, $precision));
}

function formatYearsBetweenDatesLabel(?string $startDate, ?string $endDate): string {
    $startDate = trim((string)$startDate);
    $endDate = trim((string)$endDate);
    if ($startDate === '' || $endDate === '') {
        return '';
    }

    $start = DateTime::createFromFormat('Y-m-d', $startDate);
    $end = DateTime::createFromFormat('Y-m-d', $endDate);
    if (!$start || !$end || $end < $start) {
        return '';
    }

    $diff = $start->diff($end);
    $parts = [];
    if ((int)$diff->y > 0) {
        $parts[] = $diff->y . ' yr' . ($diff->y === 1 ? '' : 's');
    }
    if ((int)$diff->m > 0) {
        $parts[] = $diff->m . ' mo' . ($diff->m === 1 ? '' : 's');
    }
    if (empty($parts) && (int)$diff->d >= 0) {
        $parts[] = $diff->d . ' day' . ($diff->d === 1 ? '' : 's');
    }

    return implode(' ', $parts);
}

function evaluatePensionEstateLifecycle(
    ?string $retirementDate,
    ?string $payType,
    ?string $livingStatus,
    ?string $dateOfDeath = null,
    ?string $referenceDate = null
): array {
    $normalizedPayType = normalizeRegistryPayType($payType);
    $normalizedLivingStatus = normalizeRegistryLivingStatus($livingStatus);
    $retirementDate = trim((string)$retirementDate);
    $dateOfDeath = trim((string)$dateOfDeath);
    $expiryDate = computeEstateExpiryDate($retirementDate);
    $referenceDate = trim((string)$referenceDate);
    if ($referenceDate === '') {
        $referenceDate = date('Y-m-d');
    }

    $result = [
        'isApplicable' => false,
        'isExpired' => false,
        'status' => 'Not Applicable',
        'label' => 'Not Applicable',
        'estateExpiryDate' => $expiryDate,
        'deathWithinCap' => null
    ];

    if ($normalizedPayType !== 'Pensioner' || $retirementDate === '') {
        return $result;
    }

    $result['isApplicable'] = true;

    if ($normalizedLivingStatus !== 'Deceased') {
        $result['status'] = 'Pensioner';
        $result['label'] = 'Pensioner';
        return $result;
    }

    $expired = false;
    if ($expiryDate !== null) {
        if ($dateOfDeath !== '' && $dateOfDeath >= $expiryDate) {
            $expired = true;
            $result['deathWithinCap'] = false;
        } elseif ($dateOfDeath !== '') {
            $result['deathWithinCap'] = true;
        }

        if ($referenceDate >= $expiryDate) {
            $expired = true;
        }
    }

    if ($expired) {
        $result['isExpired'] = true;
        $result['status'] = '15 Years Elapsed';
        $result['label'] = '15 Years Elapsed';
        return $result;
    }

    $result['status'] = 'Estate Active';
    $result['label'] = 'Estate Active';
    return $result;
}

function upsertArrearsLedgerEntry(mysqli $conn, array $payload): ?array {
    ensureArrearsAndBudgetTables($conn);

    $regNo = trim((string)($payload['regNo'] ?? ''));
    if ($regNo === '') {
        return null;
    }

    $claimType = normalizeArrearsClaimType((string)($payload['claim_type'] ?? 'Pension Arrears'));
    $periodYear = (int)($payload['period_year'] ?? date('Y'));
    $periodMonth = (int)($payload['period_month'] ?? date('n'));
    if ($periodYear < 1900 || $periodYear > 2200) {
        $periodYear = (int)date('Y');
    }
    if ($periodMonth < 1 || $periodMonth > 12) {
        $periodMonth = (int)date('n');
    }

    $expectedAmount = (float)($payload['expected_amount'] ?? 0);
    $expectedAmount = round(max($expectedAmount, 0), 2);
    $reason = trim((string)($payload['reason'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));
    $recordedBy = trim((string)($payload['recorded_by'] ?? ($_SESSION['userId'] ?? '')));
    $sourceType = normalizeArrearsSourceType((string)($payload['source_type'] ?? 'missed_payment'));
    $claimStatusProvided = array_key_exists('claim_status', $payload) && trim((string)($payload['claim_status'] ?? '')) !== '';
    $claimStatus = $claimStatusProvided
        ? normalizeClaimVerificationStatus((string)($payload['claim_status'] ?? ''))
        : 'Incomplete';
    $referenceCycleId = (int)($payload['reference_cycle_id'] ?? 0);
    $fyLabel = getFinancialYearLabelForMonth($periodYear, $periodMonth);
    $quarterLabel = getQuarterLabelForMonth($periodMonth);

    $insert = $conn->prepare("
        INSERT INTO tb_arrears_ledger
        (
            regNo, claim_type, period_year, period_month, financial_year_label, quarter_label,
            expected_amount, paid_amount, balance_amount, status, claim_status, source_type, reference_cycle_id,
            reason, notes, recorded_by, settled_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 'Pending', ?, ?, ?, ?, ?, ?, NULL)
        ON DUPLICATE KEY UPDATE
            expected_amount = VALUES(expected_amount),
            claim_status = CASE WHEN ? = 1 THEN VALUES(claim_status) ELSE claim_status END,
            reason = CASE WHEN VALUES(reason) = '' THEN reason ELSE VALUES(reason) END,
            notes = CASE WHEN VALUES(notes) = '' THEN notes ELSE VALUES(notes) END,
            updated_at = NOW()
    ");
    if (!$insert) {
        return null;
    }

    $initialBalance = $expectedAmount;
    $claimStatusFlag = $claimStatusProvided ? 1 : 0;
    $insert->bind_param(
        "ssiissddssisssi",
        $regNo,
        $claimType,
        $periodYear,
        $periodMonth,
        $fyLabel,
        $quarterLabel,
        $expectedAmount,
        $initialBalance,
        $claimStatus,
        $sourceType,
        $referenceCycleId,
        $reason,
        $notes,
        $recordedBy,
        $claimStatusFlag
    );
    $insert->execute();
    $insert->close();

    $select = $conn->prepare("
        SELECT ledger_id, expected_amount, paid_amount
        FROM tb_arrears_ledger
        WHERE regNo = ? AND claim_type = ? AND period_year = ? AND period_month = ? AND source_type = ? AND reference_cycle_id = ?
        LIMIT 1
    ");
    if (!$select) {
        return null;
    }
    $select->bind_param("ssiisi", $regNo, $claimType, $periodYear, $periodMonth, $sourceType, $referenceCycleId);
    $select->execute();
    $row = $select->get_result()->fetch_assoc();
    $select->close();
    if (!$row) {
        return null;
    }

    $ledgerId = (int)($row['ledger_id'] ?? 0);
    $statusBundle = computeArrearsStatus((float)($row['expected_amount'] ?? 0), (float)($row['paid_amount'] ?? 0));
    $status = $statusBundle['status'];
    $balance = (float)$statusBundle['balance'];
    $settledAt = ($status === 'Paid') ? date('Y-m-d H:i:s') : null;

    $update = $conn->prepare("
        UPDATE tb_arrears_ledger
        SET status = ?, balance_amount = ?, settled_at = ?
        WHERE ledger_id = ?
    ");
    if ($update) {
        $update->bind_param("sdsi", $status, $balance, $settledAt, $ledgerId);
        $update->execute();
        $update->close();
    }

    return [
        'ledger_id' => $ledgerId,
        'regNo' => $regNo,
        'claim_type' => $claimType,
        'period_year' => $periodYear,
        'period_month' => $periodMonth,
        'expected_amount' => (float)($row['expected_amount'] ?? 0),
        'paid_amount' => (float)($row['paid_amount'] ?? 0),
        'balance_amount' => $balance,
        'status' => $status
    ];
}

function recordArrearsPayment(mysqli $conn, array $payload): array {
    ensureArrearsAndBudgetTables($conn);

    $regNo = trim((string)($payload['regNo'] ?? ''));
    $claimType = normalizeArrearsClaimType((string)($payload['claim_type'] ?? 'Pension Arrears'));
    $amount = round(max((float)($payload['amount'] ?? 0), 0), 2);
    $paymentDate = trim((string)($payload['payment_date'] ?? date('Y-m-d')));
    $referenceNo = trim((string)($payload['reference_no'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));
    $recordedBy = trim((string)($payload['recorded_by'] ?? ($_SESSION['userId'] ?? '')));

    if ($regNo === '' || $amount <= 0) {
        return ['success' => false, 'message' => 'Invalid payment details', 'applied' => 0.0, 'unapplied' => $amount];
    }

    $paymentFy = getFinancialYearLabelForDate($paymentDate);
    $remaining = $amount;
    $applied = 0.0;
    $paymentId = 0;
    $allocations = [];
    $affectedLedgers = [];

    $conn->begin_transaction();
    try {
        $insertPayment = $conn->prepare("
            INSERT INTO tb_arrears_payments
            (regNo, claim_type, amount, applied_amount, unapplied_amount, payment_date, payment_financial_year_label, reference_no, notes, recorded_by, accountability_required, accountability_status)
            VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, ?, 0, 'No Accountability Required')
        ");
        if (!$insertPayment) {
            throw new RuntimeException('Unable to create payment record');
        }
        $insertPayment->bind_param(
            "ssdsssss",
            $regNo,
            $claimType,
            $amount,
            $paymentDate,
            $paymentFy,
            $referenceNo,
            $notes,
            $recordedBy
        );
        $insertPayment->execute();
        $paymentId = (int)$insertPayment->insert_id;
        $insertPayment->close();

        $openStmt = $conn->prepare("
            SELECT ledger_id, period_year, period_month, financial_year_label, expected_amount, paid_amount
            FROM tb_arrears_ledger
            WHERE regNo = ? AND claim_type = ? AND status IN ('Pending','Partially Paid')
            ORDER BY period_year ASC, period_month ASC, ledger_id ASC
        ");
        if ($openStmt) {
            $openStmt->bind_param("ss", $regNo, $claimType);
            $openStmt->execute();
            $result = $openStmt->get_result();
            while ($remaining > 0 && $row = $result->fetch_assoc()) {
                $ledgerId = (int)($row['ledger_id'] ?? 0);
                $expected = (float)($row['expected_amount'] ?? 0);
                $alreadyPaid = (float)($row['paid_amount'] ?? 0);
                $balance = max($expected - $alreadyPaid, 0);
                if ($balance <= 0) {
                    continue;
                }

                $delta = round(min($remaining, $balance), 2);
                if ($delta <= 0) {
                    continue;
                }

                $remaining = round($remaining - $delta, 2);
                $applied = round($applied + $delta, 2);
                $newPaid = round($alreadyPaid + $delta, 2);
                $statusBundle = computeArrearsStatus($expected, $newPaid);
                $newStatus = $statusBundle['status'];
                $newBalance = (float)$statusBundle['balance'];
                $settledAt = ($newStatus === 'Paid') ? date('Y-m-d H:i:s') : null;

                $upd = $conn->prepare("
                    UPDATE tb_arrears_ledger
                    SET paid_amount = ?, balance_amount = ?, status = ?, settled_at = ?
                    WHERE ledger_id = ?
                ");
                if ($upd) {
                    $upd->bind_param("ddssi", $newPaid, $newBalance, $newStatus, $settledAt, $ledgerId);
                    $upd->execute();
                    $upd->close();
                }

                $requiresAccountability = requiresArrearsAccountabilityForPeriod(
                    (int)($row['period_year'] ?? 0),
                    (int)($row['period_month'] ?? 0),
                    $paymentDate
                );

                $allocations[] = [
                    'ledger_id' => $ledgerId,
                    'applied_amount' => $delta,
                    'accrual_fy' => (string)($row['financial_year_label'] ?? getFinancialYearLabelForMonth((int)($row['period_year'] ?? 0), (int)($row['period_month'] ?? 0))),
                    'requires_accountability' => $requiresAccountability ? 1 : 0,
                    'accountability_status' => $requiresAccountability ? 'Pending Accountability' : 'Not Required'
                ];
                $affectedLedgers[$ledgerId] = true;
            }
            $openStmt->close();
        }

        if (!empty($allocations)) {
            $allocStmt = $conn->prepare("
                INSERT INTO tb_arrears_payment_allocations
                (payment_id, ledger_id, regNo, claim_type, applied_amount, accrual_financial_year_label, payment_financial_year_label, requires_accountability, accountability_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$allocStmt) {
                throw new RuntimeException('Unable to create payment allocations');
            }
            foreach ($allocations as $allocation) {
                $ledgerId = (int)$allocation['ledger_id'];
                $appliedAmount = (float)$allocation['applied_amount'];
                $accrualFy = (string)$allocation['accrual_fy'];
                $requiresAccountability = (int)$allocation['requires_accountability'];
                $accountabilityStatus = (string)$allocation['accountability_status'];
                $allocStmt->bind_param(
                    "iissdssis",
                    $paymentId,
                    $ledgerId,
                    $regNo,
                    $claimType,
                    $appliedAmount,
                    $accrualFy,
                    $paymentFy,
                    $requiresAccountability,
                    $accountabilityStatus
                );
                $allocStmt->execute();
            }
            $allocStmt->close();
        }

        $unapplied = round(max($amount - $applied, 0), 2);
        $anyAccountability = false;
        foreach ($allocations as $allocation) {
            if ((int)($allocation['requires_accountability'] ?? 0) === 1) {
                $anyAccountability = true;
                break;
            }
        }
        $paymentAccountabilityStatus = $anyAccountability ? 'Pending Accountability' : 'No Accountability Required';

        $updatePayment = $conn->prepare("
            UPDATE tb_arrears_payments
            SET applied_amount = ?, unapplied_amount = ?, accountability_required = ?, accountability_status = ?
            WHERE payment_id = ?
        ");
        if ($updatePayment) {
            $requiredFlag = $anyAccountability ? 1 : 0;
            $updatePayment->bind_param("ddisi", $applied, $unapplied, $requiredFlag, $paymentAccountabilityStatus, $paymentId);
            $updatePayment->execute();
            $updatePayment->close();
        }

        foreach (array_keys($affectedLedgers) as $ledgerId) {
            recomputeArrearsLedgerAccountabilityStatus($conn, (int)$ledgerId);
        }
        recomputeArrearsPaymentAccountabilityStatus($conn, $paymentId);

        $conn->commit();

        return [
            'success' => true,
            'message' => 'Payment recorded',
            'payment_id' => $paymentId,
            'applied' => $applied,
            'unapplied' => $unapplied,
            'payment_financial_year' => $paymentFy,
            'accountabilityRequired' => $anyAccountability,
            'accountabilityStatus' => $paymentAccountabilityStatus
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage(), 'applied' => 0.0, 'unapplied' => $amount];
    }
}

function reverseArrearsPayment(mysqli $conn, int $paymentId): array {
    ensureArrearsAndBudgetTables($conn);

    if ($paymentId <= 0) {
        return ['success' => false, 'message' => 'Invalid payment record.'];
    }

    $paymentStmt = $conn->prepare("
        SELECT payment_id, regNo, claim_type, latest_submission_id, accountability_status
        FROM tb_arrears_payments
        WHERE payment_id = ?
        LIMIT 1
    ");
    if (!$paymentStmt) {
        return ['success' => false, 'message' => 'Unable to load payment record.'];
    }
    $paymentStmt->bind_param("i", $paymentId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();

    if (!$payment) {
        return ['success' => false, 'message' => 'Payment record not found.'];
    }
    if ((int)($payment['latest_submission_id'] ?? 0) > 0 || trim((string)($payment['accountability_status'] ?? '')) === 'Accountability Submitted') {
        return ['success' => false, 'message' => 'Payments with submitted accountability cannot be edited or deregistered.'];
    }

    $allocations = [];
    $allocStmt = $conn->prepare("
        SELECT allocation_id, ledger_id, applied_amount
        FROM tb_arrears_payment_allocations
        WHERE payment_id = ?
        ORDER BY allocation_id ASC
    ");
    if ($allocStmt) {
        $allocStmt->bind_param("i", $paymentId);
        $allocStmt->execute();
        $res = $allocStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $allocations[] = [
                'allocation_id' => (int)($row['allocation_id'] ?? 0),
                'ledger_id' => (int)($row['ledger_id'] ?? 0),
                'applied_amount' => (float)($row['applied_amount'] ?? 0)
            ];
        }
        $allocStmt->close();
    }

    $conn->begin_transaction();
    try {
        foreach ($allocations as $allocation) {
            $ledgerId = (int)$allocation['ledger_id'];
            $delta = round((float)$allocation['applied_amount'], 2);
            if ($ledgerId <= 0 || $delta <= 0) {
                continue;
            }

            $ledgerStmt = $conn->prepare("
                SELECT expected_amount, paid_amount
                FROM tb_arrears_ledger
                WHERE ledger_id = ?
                LIMIT 1
            ");
            if (!$ledgerStmt) {
                throw new RuntimeException('Unable to load ledger allocation.');
            }
            $ledgerStmt->bind_param("i", $ledgerId);
            $ledgerStmt->execute();
            $ledgerRow = $ledgerStmt->get_result()->fetch_assoc();
            $ledgerStmt->close();
            if (!$ledgerRow) {
                continue;
            }

            $expected = (float)($ledgerRow['expected_amount'] ?? 0);
            $newPaid = round(max((float)($ledgerRow['paid_amount'] ?? 0) - $delta, 0), 2);
            $statusBundle = computeArrearsStatus($expected, $newPaid);
            $newStatus = (string)($statusBundle['status'] ?? 'Pending');
            $newBalance = (float)($statusBundle['balance'] ?? max($expected - $newPaid, 0));
            $settledAt = $newStatus === 'Paid' ? date('Y-m-d H:i:s') : null;

            $updateLedger = $conn->prepare("
                UPDATE tb_arrears_ledger
                SET paid_amount = ?, balance_amount = ?, status = ?, settled_at = ?, updated_at = NOW()
                WHERE ledger_id = ?
            ");
            if (!$updateLedger) {
                throw new RuntimeException('Unable to reverse payment allocation.');
            }
            $updateLedger->bind_param("ddssi", $newPaid, $newBalance, $newStatus, $settledAt, $ledgerId);
            $updateLedger->execute();
            $updateLedger->close();
        }

        $deleteAlloc = $conn->prepare("DELETE FROM tb_arrears_payment_allocations WHERE payment_id = ?");
        if ($deleteAlloc) {
            $deleteAlloc->bind_param("i", $paymentId);
            $deleteAlloc->execute();
            $deleteAlloc->close();
        }

        $deletePayment = $conn->prepare("DELETE FROM tb_arrears_payments WHERE payment_id = ? LIMIT 1");
        if (!$deletePayment) {
            throw new RuntimeException('Unable to remove payment record.');
        }
        $deletePayment->bind_param("i", $paymentId);
        $deletePayment->execute();
        $deletePayment->close();

        foreach ($allocations as $allocation) {
            if ((int)$allocation['ledger_id'] > 0) {
                recomputeArrearsLedgerAccountabilityStatus($conn, (int)$allocation['ledger_id']);
            }
        }

        $conn->commit();
        return ['success' => true, 'message' => 'Payment deregistered successfully.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function updateArrearsPaymentRecord(mysqli $conn, array $payload): array {
    $paymentId = (int)($payload['payment_id'] ?? 0);
    if ($paymentId <= 0) {
        return ['success' => false, 'message' => 'Invalid payment record.'];
    }

    $paymentStmt = $conn->prepare("
        SELECT regNo, claim_type
        FROM tb_arrears_payments
        WHERE payment_id = ?
        LIMIT 1
    ");
    if (!$paymentStmt) {
        return ['success' => false, 'message' => 'Unable to load payment record.'];
    }
    $paymentStmt->bind_param("i", $paymentId);
    $paymentStmt->execute();
    $payment = $paymentStmt->get_result()->fetch_assoc();
    $paymentStmt->close();
    if (!$payment) {
        return ['success' => false, 'message' => 'Payment record not found.'];
    }

    $reverse = reverseArrearsPayment($conn, $paymentId);
    if (empty($reverse['success'])) {
        return $reverse;
    }

    return recordArrearsPayment($conn, [
        'regNo' => trim((string)($payload['regNo'] ?? ($payment['regNo'] ?? ''))),
        'claim_type' => (string)($payload['claim_type'] ?? ($payment['claim_type'] ?? 'Pension Arrears')),
        'amount' => (float)($payload['amount'] ?? 0),
        'payment_date' => trim((string)($payload['payment_date'] ?? date('Y-m-d'))),
        'reference_no' => trim((string)($payload['reference_no'] ?? '')),
        'notes' => trim((string)($payload['notes'] ?? '')),
        'recorded_by' => trim((string)($payload['recorded_by'] ?? ($_SESSION['userId'] ?? '')))
    ]);
}

function autoRebuildDelayedPayrollArrears(mysqli $conn, string $regNo): int {
    ensureArrearsAndBudgetTables($conn);
    $regNo = trim($regNo);
    if ($regNo === '') {
        return 0;
    }

    $rowStmt = $conn->prepare("
        SELECT regNo, retirementDate, reducedPension, fullPension, payType, livingStatus
        FROM tb_fileregistry
        WHERE regNo = ?
        LIMIT 1
    ");
    if (!$rowStmt) {
        return 0;
    }
    $rowStmt->bind_param("s", $regNo);
    $rowStmt->execute();
    $record = $rowStmt->get_result()->fetch_assoc();
    $rowStmt->close();

    if (!$record) {
        return 0;
    }

    $payType = normalizeRegistryPayType((string)($record['payType'] ?? ''));
    if ($payType !== 'Pensioner') {
        $deleteStmt = $conn->prepare("DELETE FROM tb_arrears_ledger WHERE regNo = ? AND source_type = 'auto_delay'");
        if ($deleteStmt) {
            $deleteStmt->bind_param("s", $regNo);
            $deleteStmt->execute();
            $deleteStmt->close();
        }
        return 0;
    }

    $retirementDateRaw = trim((string)($record['retirementDate'] ?? ''));
    if ($retirementDateRaw === '') {
        return 0;
    }

    $firstPayrollStmt = $conn->prepare("
        SELECT payroll_year, payroll_month
        FROM tb_registry_payroll_monthly_status
        WHERE regNo = ? AND payroll_status = 'On Payroll'
        ORDER BY payroll_year ASC, payroll_month ASC
        LIMIT 1
    ");
    if (!$firstPayrollStmt) {
        return 0;
    }
    $firstPayrollStmt->bind_param("s", $regNo);
    $firstPayrollStmt->execute();
    $firstRow = $firstPayrollStmt->get_result()->fetch_assoc();
    $firstPayrollStmt->close();

    $deleteStmt = $conn->prepare("DELETE FROM tb_arrears_ledger WHERE regNo = ? AND source_type = 'auto_delay'");
    if ($deleteStmt) {
        $deleteStmt->bind_param("s", $regNo);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    if (!$firstRow) {
        return 0;
    }

    $expectedAmount = (float)($record['reducedPension'] ?? 0);
    if ($expectedAmount <= 0) {
        $expectedAmount = (float)($record['fullPension'] ?? 0);
    }
    $expectedAmount = round(max($expectedAmount, 0), 2);
    if ($expectedAmount <= 0) {
        return 0;
    }

    $retireDate = DateTime::createFromFormat('Y-m-d', $retirementDateRaw) ?: null;
    if (!$retireDate) {
        return 0;
    }
    $retirementMonthPayable = isRetirementMonthPayable($retirementDateRaw);
    if ($retirementMonthPayable) {
        $cursor = (clone $retireDate)->modify('first day of this month')->setTime(0, 0, 0);
    } else {
        $cursor = (clone $retireDate)->modify('first day of next month')->setTime(0, 0, 0);
    }
    $firstPayrollDate = DateTime::createFromFormat('!Y-n-j', ((int)$firstRow['payroll_year']) . '-' . ((int)$firstRow['payroll_month']) . '-1');
    if (!$firstPayrollDate) {
        return 0;
    }

    $inserted = 0;
    while ($cursor < $firstPayrollDate) {
        $month = (int)$cursor->format('n');
        $year = (int)$cursor->format('Y');
        $saved = upsertArrearsLedgerEntry($conn, [
            'regNo' => $regNo,
            'claim_type' => 'Pension Arrears',
            'period_year' => $year,
            'period_month' => $month,
            'expected_amount' => $expectedAmount,
            'source_type' => 'auto_delay',
            'reference_cycle_id' => 0,
            'reason' => 'Delayed first appearance on payroll',
            'notes' => 'Auto-generated from retirement date to first payroll appearance.'
        ]);
        if ($saved) {
            $inserted++;
        }
        $cursor->modify('+1 month');
    }

    return $inserted;
}

function autoRebuildFullPensionArrears(mysqli $conn, string $regNo): int {
    ensureArrearsAndBudgetTables($conn);
    $regNo = trim($regNo);
    if ($regNo === '') {
        return 0;
    }

    $rowStmt = $conn->prepare("
        SELECT regNo, dateOn15yrs, fullPension, payType, livingStatus
        FROM tb_fileregistry
        WHERE regNo = ?
        LIMIT 1
    ");
    if (!$rowStmt) {
        return 0;
    }
    $rowStmt->bind_param("s", $regNo);
    $rowStmt->execute();
    $record = $rowStmt->get_result()->fetch_assoc();
    $rowStmt->close();

    if (!$record) {
        return 0;
    }

    $payType = normalizeRegistryPayType((string)($record['payType'] ?? ''));
    $livingStatus = strtolower(trim((string)($record['livingStatus'] ?? '')));
    $fullPension = round(max((float)($record['fullPension'] ?? 0), 0), 2);
    $dateOn15 = trim((string)($record['dateOn15yrs'] ?? ''));

    $deleteStmt = $conn->prepare("DELETE FROM tb_arrears_ledger WHERE regNo = ? AND source_type = 'auto_full_pension'");
    if ($deleteStmt) {
        $deleteStmt->bind_param("s", $regNo);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    if ($payType !== 'Pensioner' || $livingStatus !== 'alive' || $fullPension <= 0 || $dateOn15 === '') {
        return 0;
    }

    $eligibleDate = DateTime::createFromFormat('Y-m-d', $dateOn15) ?: null;
    if (!$eligibleDate) {
        return 0;
    }
    $today = new DateTime('today');
    if ($eligibleDate > $today) {
        return 0;
    }

    $eligYear = (int)$eligibleDate->format('Y');
    $eligMonth = (int)$eligibleDate->format('n');

    $statusStmt = $conn->prepare("
        SELECT payroll_year, payroll_month, payroll_status, amount, cycle_id
        FROM tb_registry_payroll_monthly_status
        WHERE regNo = ?
          AND (payroll_year > ? OR (payroll_year = ? AND payroll_month >= ?))
        ORDER BY payroll_year ASC, payroll_month ASC
    ");
    if (!$statusStmt) {
        return 0;
    }
    $statusStmt->bind_param("siii", $regNo, $eligYear, $eligYear, $eligMonth);
    $statusStmt->execute();
    $result = $statusStmt->get_result();

    $inserted = 0;
    while ($row = $result->fetch_assoc()) {
        $year = (int)($row['payroll_year'] ?? 0);
        $month = (int)($row['payroll_month'] ?? 0);
        if ($year <= 0 || $month < 1 || $month > 12) {
            continue;
        }
        $status = strtolower(trim((string)($row['payroll_status'] ?? '')));
        $paidAmount = ($status === 'on payroll') ? (float)($row['amount'] ?? 0) : 0.0;
        $diff = round(max($fullPension - $paidAmount, 0), 2);
        if ($diff <= 0) {
            continue;
        }

        $saved = upsertArrearsLedgerEntry($conn, [
            'regNo' => $regNo,
            'claim_type' => 'Full Pension Arrears',
            'period_year' => $year,
            'period_month' => $month,
            'expected_amount' => $diff,
            'source_type' => 'auto_full_pension',
            'reference_cycle_id' => (int)($row['cycle_id'] ?? 0),
            'reason' => 'Monthly payment below full pension due after 15 years',
            'notes' => 'Auto-generated from payroll monthly status vs full pension.'
        ]);
        if ($saved) {
            $inserted++;
        }
    }
    $statusStmt->close();

    return $inserted;
}

function markRegistryRecordWorkflowAutoArrearsEligible(mysqli $conn, string $regNo, string $source = 'workflow_approval'): bool {
    ensureFileMovementTables($conn);

    $regNo = trim($regNo);
    $source = trim($source);
    if ($regNo === '') {
        return false;
    }
    if ($source === '') {
        $source = 'workflow_approval';
    }

    $stmt = $conn->prepare("
        UPDATE tb_fileregistry
        SET workflow_auto_arrears_enabled = 1,
            workflow_auto_arrears_enabled_at = COALESCE(workflow_auto_arrears_enabled_at, NOW()),
            workflow_auto_arrears_source = ?
        WHERE regNo = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ss", $source, $regNo);
    $ok = $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();
    return $ok && $affected >= 0;
}

function isArrearsAutoReconcileEligible(mysqli $conn, string $regNo): bool {
    $regNo = trim($regNo);
    if ($regNo === '') {
        return false;
    }

    ensureFileMovementTables($conn);

    $stmt = $conn->prepare("
        SELECT 1
        FROM tb_fileregistry
        WHERE regNo = ?
          AND COALESCE(workflow_auto_arrears_enabled, 0) = 1
          AND COALESCE(is_deleted, 0) = 0
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("s", $regNo);
    $stmt->execute();
    $eligible = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();

    return $eligible;
}

function runAutomaticArrearsReconciliation(mysqli $conn, ?string $regNo = null): array {
    ensureArrearsAndBudgetTables($conn);

    $targets = [];
    $trimmedRegNo = trim((string)$regNo);
    if ($trimmedRegNo !== '') {
        if (isArrearsAutoReconcileEligible($conn, $trimmedRegNo)) {
            $targets[] = $trimmedRegNo;
        }
    } else {
        if (tableExists($conn, 'tb_fileregistry')) {
            $res = $conn->query("
                SELECT DISTINCT regNo
                FROM tb_fileregistry
                WHERE regNo IS NOT NULL
                  AND TRIM(regNo) <> ''
                  AND COALESCE(workflow_auto_arrears_enabled, 0) = 1
                  AND COALESCE(is_deleted, 0) = 0
            ");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $value = trim((string)($row['regNo'] ?? ''));
                    if ($value !== '') {
                        $targets[] = $value;
                    }
                }
                $res->free();
            }
        }
    }

    $targets = array_values(array_unique($targets));
    $processed = 0;
    $delayedRows = 0;
    $fullRows = 0;
    foreach ($targets as $itemRegNo) {
        if (!isArrearsAutoReconcileEligible($conn, $itemRegNo)) {
            continue;
        }
        $processed++;
        $delayedRows += autoRebuildDelayedPayrollArrears($conn, $itemRegNo);
        $fullRows += autoRebuildFullPensionArrears($conn, $itemRegNo);
    }

    return [
        'processed_registries' => $processed,
        'delayed_rows' => $delayedRows,
        'full_pension_rows' => $fullRows
    ];
}

function ensureApplicationQueueTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_application_queue (
            queue_id int(11) NOT NULL AUTO_INCREMENT,
            staffdue_id int(11) NOT NULL,
            regNo varchar(50) DEFAULT NULL,
            current_stage varchar(50) NOT NULL DEFAULT 'verified',
            status enum('verified','submitted_to_oc','in_progress','completed','dropped') NOT NULL DEFAULT 'verified',
            verified_by varchar(100) DEFAULT NULL,
            verified_at datetime DEFAULT NULL,
            submitted_by varchar(100) DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (queue_id),
            UNIQUE KEY unique_staffdue (staffdue_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        'staffdue_id' => "INT(11) NOT NULL",
        'regNo' => "VARCHAR(50) DEFAULT NULL",
        'current_stage' => "VARCHAR(50) NOT NULL DEFAULT 'verified'",
        'status' => "ENUM('verified','submitted_to_oc','in_progress','completed','dropped') NOT NULL DEFAULT 'verified'",
        'verified_by' => "VARCHAR(100) DEFAULT NULL",
        'verified_at' => "DATETIME DEFAULT NULL",
        'submitted_by' => "VARCHAR(100) DEFAULT NULL",
        'submitted_at' => "DATETIME DEFAULT NULL",
        'notes' => "TEXT DEFAULT NULL",
        'updated_at' => "TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP() ON UPDATE CURRENT_TIMESTAMP()"
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_application_queue LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_application_queue ADD COLUMN {$column} {$definition}");
        }
    }

    $statusColumn = $conn->query("SHOW COLUMNS FROM tb_application_queue LIKE 'status'");
    if ($statusColumn && $row = $statusColumn->fetch_assoc()) {
        $type = $row['Type'] ?? '';
        if (strpos($type, 'dropped') === false) {
            $conn->query("ALTER TABLE tb_application_queue MODIFY status ENUM('verified','submitted_to_oc','in_progress','completed','dropped') NOT NULL DEFAULT 'verified'");
        }
    }

    $created = true;
}

function ensureArrearsLedgerTableExists(mysqli $conn): bool {
    if (!tableExists($conn, 'tb_arrears_ledger')) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS tb_arrears_ledger (
                ledger_id int(11) NOT NULL AUTO_INCREMENT,
                regNo varchar(50) NOT NULL,
                claim_type varchar(80) NOT NULL,
                period_year int(11) NOT NULL,
                period_month tinyint(4) NOT NULL,
                financial_year_label varchar(20) NOT NULL,
                quarter_label varchar(6) NOT NULL,
                expected_amount decimal(14,2) NOT NULL DEFAULT 0,
                paid_amount decimal(14,2) NOT NULL DEFAULT 0,
                balance_amount decimal(14,2) NOT NULL DEFAULT 0,
                status enum('Pending','Partially Paid','Paid','Waived') NOT NULL DEFAULT 'Pending',
                claim_status varchar(20) NOT NULL DEFAULT 'Incomplete',
                source_type varchar(40) NOT NULL DEFAULT 'missed_payment',
                reference_cycle_id int(11) NOT NULL DEFAULT 0,
                reason varchar(255) DEFAULT NULL,
                notes text DEFAULT NULL,
                recorded_by varchar(100) DEFAULT NULL,
                recorded_at timestamp NOT NULL DEFAULT current_timestamp(),
                updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                settled_at datetime DEFAULT NULL,
                PRIMARY KEY (ledger_id),
                UNIQUE KEY uniq_arrears_period (regNo, claim_type, period_year, period_month, source_type, reference_cycle_id),
                KEY idx_arrears_reg (regNo),
                KEY idx_arrears_type (claim_type),
                KEY idx_arrears_period (period_year, period_month),
                KEY idx_arrears_fy_q (financial_year_label, quarter_label),
                KEY idx_arrears_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    if (!tableExists($conn, 'tb_arrears_ledger')) {
        error_log('ensureArrearsLedgerTableExists: unable to create tb_arrears_ledger. ' . $conn->error);
        // Fallback: attempt a simpler schema if the enum/index create failed.
        $conn->query("
            CREATE TABLE IF NOT EXISTS tb_arrears_ledger (
                ledger_id int(11) NOT NULL AUTO_INCREMENT,
                regNo varchar(50) NOT NULL,
                claim_type varchar(80) NOT NULL,
                period_year int(11) NOT NULL,
                period_month tinyint(4) NOT NULL,
                financial_year_label varchar(20) NOT NULL DEFAULT '',
                quarter_label varchar(6) NOT NULL DEFAULT '',
                expected_amount decimal(14,2) NOT NULL DEFAULT 0,
                paid_amount decimal(14,2) NOT NULL DEFAULT 0,
                balance_amount decimal(14,2) NOT NULL DEFAULT 0,
                status varchar(30) NOT NULL DEFAULT 'Pending',
                claim_status varchar(20) NOT NULL DEFAULT 'Incomplete',
                source_type varchar(40) NOT NULL DEFAULT 'missed_payment',
                reference_cycle_id int(11) NOT NULL DEFAULT 0,
                reason varchar(255) DEFAULT NULL,
                notes text DEFAULT NULL,
                recorded_by varchar(100) DEFAULT NULL,
                recorded_at timestamp NOT NULL DEFAULT current_timestamp(),
                updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                settled_at datetime DEFAULT NULL,
                PRIMARY KEY (ledger_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    return tableExists($conn, 'tb_arrears_ledger');
}

function ensureTasksTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_tasks (
            taskId int(11) NOT NULL AUTO_INCREMENT,
            created_by varchar(100) DEFAULT NULL,
            assigned_to varchar(100) DEFAULT NULL,
            assigned_role varchar(50) DEFAULT NULL,
            task_type varchar(100) DEFAULT NULL,
            task_title varchar(255) DEFAULT NULL,
            task_description text DEFAULT NULL,
            status enum('pending','assigned','in_progress','delegated','completed','declined','cancelled','deferred','returned') NOT NULL DEFAULT 'pending',
            priority enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
            related_staff_id int(11) DEFAULT NULL,
            related_reg_no varchar(50) DEFAULT NULL,
            parent_task_id int(11) DEFAULT NULL,
            due_at datetime DEFAULT NULL,
            assigned_at timestamp NULL DEFAULT NULL,
            declined_reason text DEFAULT NULL,
            metadata text DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            timeStamp timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (taskId)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        'created_by' => "VARCHAR(100) DEFAULT NULL",
        'assigned_to' => "VARCHAR(100) DEFAULT NULL",
        'assigned_role' => "VARCHAR(50) DEFAULT NULL",
        'task_type' => "VARCHAR(100) DEFAULT NULL",
        'task_title' => "VARCHAR(255) DEFAULT NULL",
        'task_description' => "TEXT DEFAULT NULL",
        'status' => "ENUM('pending','assigned','in_progress','delegated','completed','declined','cancelled','deferred','returned') NOT NULL DEFAULT 'pending'",
        'priority' => "ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal'",
        'related_staff_id' => "INT(11) DEFAULT NULL",
        'related_reg_no' => "VARCHAR(50) DEFAULT NULL",
        'parent_task_id' => "INT(11) DEFAULT NULL",
        'due_at' => "DATETIME DEFAULT NULL",
        'assigned_at' => "TIMESTAMP NULL DEFAULT NULL",
        'declined_reason' => "TEXT DEFAULT NULL",
        'metadata' => "TEXT DEFAULT NULL",
        'updated_at' => "DATETIME DEFAULT NULL",
        'completed_at' => "DATETIME DEFAULT NULL"
    ];

    foreach ($columns as $column => $definition) {
        $result = $conn->query("SHOW COLUMNS FROM tb_tasks LIKE '{$column}'");
        if ($result && $result->num_rows === 0) {
            $conn->query("ALTER TABLE tb_tasks ADD COLUMN {$column} {$definition}");
        }
    }

    $parentTaskIndex = $conn->query("SHOW INDEX FROM tb_tasks WHERE Key_name = 'idx_parent_task_id'");
    if ($parentTaskIndex && $parentTaskIndex->num_rows === 0) {
        $conn->query("ALTER TABLE tb_tasks ADD INDEX idx_parent_task_id (parent_task_id)");
    }

    $statusColumn = $conn->query("SHOW COLUMNS FROM tb_tasks LIKE 'status'");
    if ($statusColumn && $row = $statusColumn->fetch_assoc()) {
        $type = $row['Type'] ?? '';
        if (strpos($type, 'delegated') === false || strpos($type, 'deferred') === false || strpos($type, 'returned') === false) {
            $conn->query("
                ALTER TABLE tb_tasks
                MODIFY status ENUM('pending','assigned','in_progress','delegated','completed','declined','cancelled','deferred','returned')
                NOT NULL DEFAULT 'pending'
            ");
        }
    }

    $created = true;
}

function ensureTaskCommentsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_task_comments (
            comment_id int(11) NOT NULL AUTO_INCREMENT,
            task_id int(11) NOT NULL,
            author_id varchar(100) DEFAULT NULL,
            author_name varchar(100) DEFAULT NULL,
            author_role varchar(50) DEFAULT NULL,
            comment text NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (comment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function getTaskDueBusinessDays(mysqli $conn): int {
    $days = getAppSettingInt($conn, 'task_due_business_days', 3);
    if ($days < 1) {
        $days = 1;
    }
    if ($days > 60) {
        $days = 60;
    }
    return $days;
}

function getTaskGraceBusinessDays(mysqli $conn): int {
    $days = getAppSettingInt($conn, 'task_grace_business_days', 0);
    if ($days < 0) {
        $days = 0;
    }
    if ($days > 30) {
        $days = 30;
    }
    return $days;
}

function skipTaskWeekends(mysqli $conn): bool {
    return getAppSettingBool($conn, 'task_skip_weekends', true);
}

function skipTaskUgHolidays(mysqli $conn): bool {
    return getAppSettingBool($conn, 'task_skip_ug_holidays', true);
}

function ensureUgandaPublicHolidaysTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_uganda_public_holidays (
            holiday_date date NOT NULL,
            holiday_name varchar(120) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (holiday_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function seedUgandaPublicHolidaysForYear(mysqli $conn, int $year): void {
    ensureUgandaPublicHolidaysTable($conn);

    static $seededYears = [];
    if (isset($seededYears[$year])) {
        return;
    }

    $holidays = [
        sprintf('%04d-01-01', $year) => "New Year's Day",
        sprintf('%04d-01-26', $year) => 'NRM Liberation Day',
        sprintf('%04d-03-08', $year) => "International Women's Day",
        sprintf('%04d-05-01', $year) => 'Labour Day',
        sprintf('%04d-06-03', $year) => 'Uganda Martyrs Day',
        sprintf('%04d-06-09', $year) => 'National Heroes Day',
        sprintf('%04d-10-09', $year) => 'Independence Day',
        sprintf('%04d-12-25', $year) => 'Christmas Day',
        sprintf('%04d-12-26', $year) => 'Boxing Day'
    ];

    $easterTimestamp = @easter_date($year);
    if ($easterTimestamp !== false) {
        $holidays[date('Y-m-d', strtotime('-2 day', $easterTimestamp))] = 'Good Friday';
        $holidays[date('Y-m-d', strtotime('+1 day', $easterTimestamp))] = 'Easter Monday';
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_uganda_public_holidays (holiday_date, holiday_name, is_active)
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name)
    ");
    if ($stmt) {
        foreach ($holidays as $holidayDate => $holidayName) {
            $stmt->bind_param("ss", $holidayDate, $holidayName);
            $stmt->execute();
        }
        $stmt->close();
    }

    $seededYears[$year] = true;
}

function getUgandaHolidaySet(mysqli $conn, int $startYear, int $endYear): array {
    ensureUgandaPublicHolidaysTable($conn);

    if ($startYear > $endYear) {
        [$startYear, $endYear] = [$endYear, $startYear];
    }

    for ($year = $startYear; $year <= $endYear; $year++) {
        seedUgandaPublicHolidaysForYear($conn, $year);
    }

    static $cache = [];
    $cacheKey = "{$startYear}-{$endYear}";
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $fromDate = sprintf('%04d-01-01', $startYear);
    $toDate = sprintf('%04d-12-31', $endYear);

    $stmt = $conn->prepare("
        SELECT holiday_date
        FROM tb_uganda_public_holidays
        WHERE is_active = 1
          AND holiday_date BETWEEN ? AND ?
    ");
    $set = [];
    if ($stmt) {
        $stmt->bind_param("ss", $fromDate, $toDate);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $dateValue = (string)($row['holiday_date'] ?? '');
            if ($dateValue !== '') {
                $set[$dateValue] = true;
            }
        }
        $stmt->close();
    }

    $cache[$cacheKey] = $set;
    return $set;
}

function isTaskBusinessDay(mysqli $conn, DateTimeImmutable $date): bool {
    if (skipTaskWeekends($conn)) {
        $dayOfWeek = (int)$date->format('N'); // 1 = Monday, 7 = Sunday
        if ($dayOfWeek >= 6) {
            return false;
        }
    }

    if (skipTaskUgHolidays($conn)) {
        $year = (int)$date->format('Y');
        $holidaySet = getUgandaHolidaySet($conn, $year - 1, $year + 1);
        if (isset($holidaySet[$date->format('Y-m-d')])) {
            return false;
        }
    }

    return true;
}

function addTaskBusinessDays(mysqli $conn, string $baseDateTime, int $days): string {
    try {
        $cursor = new DateTimeImmutable($baseDateTime ?: 'now');
    } catch (Throwable $e) {
        $cursor = new DateTimeImmutable('now');
    }

    if ($days <= 0) {
        return $cursor->format('Y-m-d H:i:s');
    }

    $added = 0;
    while ($added < $days) {
        $cursor = $cursor->modify('+1 day');
        if (isTaskBusinessDay($conn, $cursor)) {
            $added++;
        }
    }

    return $cursor->format('Y-m-d H:i:s');
}

function calculateTaskDueDateTime(mysqli $conn, ?string $baseDateTime = null, ?int $overrideDays = null): string {
    $days = $overrideDays ?? getTaskDueBusinessDays($conn);
    return addTaskBusinessDays($conn, $baseDateTime ?: date('Y-m-d H:i:s'), $days);
}

function calculateTaskEffectiveDeadline(mysqli $conn, ?string $dueAt): ?string {
    if ($dueAt === null || trim($dueAt) === '') {
        return null;
    }
    $graceDays = getTaskGraceBusinessDays($conn);
    if ($graceDays <= 0) {
        return $dueAt;
    }
    return addTaskBusinessDays($conn, $dueAt, $graceDays);
}

function isTaskOverdueByPolicy(mysqli $conn, ?string $dueAt): bool {
    $deadline = calculateTaskEffectiveDeadline($conn, $dueAt);
    if ($deadline === null) {
        return false;
    }
    $deadlineTs = strtotime($deadline);
    if ($deadlineTs === false) {
        return false;
    }
    return $deadlineTs < time();
}

function taskAlertsEnabled(mysqli $conn): bool {
    return getAppSettingBool($conn, 'task_alerts_enabled', true);
}

function getTaskAlertDueSoonHours(mysqli $conn): int {
    $hours = getAppSettingInt($conn, 'task_alert_due_soon_hours', 24);
    if ($hours < 1) {
        $hours = 1;
    }
    if ($hours > 168) {
        $hours = 168;
    }
    return $hours;
}

function getTaskAlertStalledHours(mysqli $conn): int {
    $hours = getAppSettingInt($conn, 'task_alert_stalled_hours', 72);
    if ($hours < 6) {
        $hours = 6;
    }
    if ($hours > 720) {
        $hours = 720;
    }
    return $hours;
}

function getTaskAlertEscalationHours(mysqli $conn): int {
    $hours = getAppSettingInt($conn, 'task_alert_escalation_hours', 24);
    if ($hours < 1) {
        $hours = 1;
    }
    if ($hours > 720) {
        $hours = 720;
    }
    return $hours;
}

function ensureTaskAlertsTable(mysqli $conn): void {
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_task_alerts (
            alert_id int(11) NOT NULL AUTO_INCREMENT,
            task_id int(11) NOT NULL,
            alert_type enum('due_soon','overdue','stalled') NOT NULL,
            severity enum('info','warning','critical') NOT NULL DEFAULT 'warning',
            alert_status enum('open','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'open',
            assigned_to varchar(100) DEFAULT NULL,
            assigned_role varchar(50) DEFAULT NULL,
            related_reg_no varchar(50) DEFAULT NULL,
            due_at datetime DEFAULT NULL,
            triggered_at datetime NOT NULL DEFAULT current_timestamp(),
            acknowledged_at datetime DEFAULT NULL,
            acknowledged_by varchar(100) DEFAULT NULL,
            resolved_at datetime DEFAULT NULL,
            resolved_by varchar(100) DEFAULT NULL,
            last_evaluated_at datetime NOT NULL DEFAULT current_timestamp(),
            metadata text DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (alert_id),
            UNIQUE KEY uniq_task_alert_type (task_id, alert_type),
            KEY idx_alert_status (alert_status),
            KEY idx_alert_assignee_status (assigned_to, alert_status),
            KEY idx_alert_role_status (assigned_role, alert_status),
            KEY idx_alert_triggered (triggered_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $created = true;
}

function determineTaskAlertCandidate(mysqli $conn, array $taskRow): ?array {
    $status = strtolower(trim((string)($taskRow['status'] ?? '')));
    if (!in_array($status, ['pending', 'assigned', 'in_progress', 'deferred', 'returned'], true)) {
        return null;
    }

    $now = time();
    $dueAt = trim((string)($taskRow['due_at'] ?? ''));
    if ($dueAt !== '') {
        $effectiveDeadline = calculateTaskEffectiveDeadline($conn, $dueAt);
        $deadlineTs = $effectiveDeadline ? strtotime($effectiveDeadline) : false;
        if ($deadlineTs !== false) {
            if ($deadlineTs < $now) {
                $elapsed = $now - $deadlineTs;
                $criticalAfter = getTaskAlertEscalationHours($conn) * 3600;
                return [
                    'type' => 'overdue',
                    'severity' => ($elapsed >= $criticalAfter) ? 'critical' : 'warning',
                    'due_at' => $dueAt,
                    'metadata' => [
                        'seconds_overdue' => $elapsed,
                        'effective_deadline' => $effectiveDeadline
                    ]
                ];
            }

            $dueSoonWindow = getTaskAlertDueSoonHours($conn) * 3600;
            $remaining = $deadlineTs - $now;
            if ($remaining <= $dueSoonWindow) {
                return [
                    'type' => 'due_soon',
                    'severity' => ($remaining <= 2 * 3600) ? 'warning' : 'info',
                    'due_at' => $dueAt,
                    'metadata' => [
                        'seconds_remaining' => $remaining,
                        'effective_deadline' => $effectiveDeadline
                    ]
                ];
            }
        }
    }

    $stalledWindow = getTaskAlertStalledHours($conn) * 3600;
    $referenceTimeRaw = trim((string)($taskRow['updated_at'] ?? ''));
    if ($referenceTimeRaw === '') {
        $referenceTimeRaw = trim((string)($taskRow['timeStamp'] ?? ''));
    }
    $referenceTs = $referenceTimeRaw !== '' ? strtotime($referenceTimeRaw) : false;
    if ($referenceTs !== false) {
        $idleSeconds = $now - $referenceTs;
        if ($idleSeconds >= $stalledWindow) {
            return [
                'type' => 'stalled',
                'severity' => ($idleSeconds >= ($stalledWindow * 2)) ? 'critical' : 'warning',
                'due_at' => $dueAt !== '' ? $dueAt : null,
                'metadata' => [
                    'seconds_idle' => $idleSeconds,
                    'reference_time' => $referenceTimeRaw
                ]
            ];
        }
    }

    return null;
}

function syncTaskAlerts(mysqli $conn): array {
    ensureTasksTable($conn);
    ensureTaskAlertsTable($conn);

    $summary = [
        'evaluated_tasks' => 0,
        'opened' => 0,
        'updated' => 0,
        'resolved' => 0,
        'reopened' => 0,
        'disabled' => false
    ];

    if (!taskAlertsEnabled($conn)) {
        $resolveStmt = $conn->prepare("
            UPDATE tb_task_alerts
            SET alert_status = 'resolved',
                resolved_at = NOW(),
                resolved_by = 'system',
                last_evaluated_at = NOW()
            WHERE alert_status IN ('open', 'acknowledged')
        ");
        if ($resolveStmt) {
            $resolveStmt->execute();
            $summary['resolved'] = $resolveStmt->affected_rows;
            $resolveStmt->close();
        }
        $summary['disabled'] = true;
        return $summary;
    }

    $openTaskStatuses = ['pending', 'assigned', 'in_progress', 'deferred', 'returned'];
    $taskStatusList = "'" . implode("','", $openTaskStatuses) . "'";

    // Alerts tied to closed tasks are resolved automatically.
    $resolveClosedSql = "
        UPDATE tb_task_alerts a
        LEFT JOIN tb_tasks t
               ON t.taskId = a.task_id
              AND t.status IN ($taskStatusList)
        SET a.alert_status = 'resolved',
            a.resolved_at = NOW(),
            a.resolved_by = 'system',
            a.last_evaluated_at = NOW()
        WHERE t.taskId IS NULL
          AND a.alert_status IN ('open', 'acknowledged')
    ";
    if ($conn->query($resolveClosedSql) === true) {
        $summary['resolved'] += (int)$conn->affected_rows;
    }

    $tasksResult = $conn->query("
        SELECT taskId, status, assigned_to, assigned_role, related_reg_no, due_at, updated_at, timeStamp
        FROM tb_tasks
        WHERE status IN ($taskStatusList)
    ");

    $tasks = [];
    if ($tasksResult) {
        while ($row = $tasksResult->fetch_assoc()) {
            $taskId = (int)($row['taskId'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            $tasks[$taskId] = $row;
        }
    }

    if (empty($tasks)) {
        return $summary;
    }

    $summary['evaluated_tasks'] = count($tasks);
    $taskIds = array_keys($tasks);
    $idList = implode(',', array_map('intval', $taskIds));

    $existingMap = [];
    $existingResult = $conn->query("
        SELECT alert_id, task_id, alert_type, alert_status
        FROM tb_task_alerts
        WHERE task_id IN ($idList)
    ");
    if ($existingResult) {
        while ($row = $existingResult->fetch_assoc()) {
            $taskId = (int)($row['task_id'] ?? 0);
            $alertType = (string)($row['alert_type'] ?? '');
            if ($taskId <= 0 || $alertType === '') {
                continue;
            }
            if (!isset($existingMap[$taskId])) {
                $existingMap[$taskId] = [];
            }
            $existingMap[$taskId][$alertType] = $row;
        }
    }

    $insertStmt = $conn->prepare("
        INSERT INTO tb_task_alerts (
            task_id, alert_type, severity, alert_status, assigned_to, assigned_role, related_reg_no, due_at, metadata, triggered_at, last_evaluated_at
        ) VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $updateStmt = $conn->prepare("
        UPDATE tb_task_alerts
        SET severity = ?,
            assigned_to = ?,
            assigned_role = ?,
            related_reg_no = ?,
            due_at = ?,
            metadata = ?,
            last_evaluated_at = NOW()
        WHERE alert_id = ?
    ");

    $reopenStmt = $conn->prepare("
        UPDATE tb_task_alerts
        SET alert_status = 'open',
            severity = ?,
            assigned_to = ?,
            assigned_role = ?,
            related_reg_no = ?,
            due_at = ?,
            metadata = ?,
            triggered_at = NOW(),
            acknowledged_at = NULL,
            acknowledged_by = NULL,
            resolved_at = NULL,
            resolved_by = NULL,
            last_evaluated_at = NOW()
        WHERE alert_id = ?
    ");

    $resolveStmt = $conn->prepare("
        UPDATE tb_task_alerts
        SET alert_status = 'resolved',
            resolved_at = NOW(),
            resolved_by = 'system',
            last_evaluated_at = NOW()
        WHERE alert_id = ?
          AND alert_status IN ('open', 'acknowledged')
    ");

    foreach ($tasks as $taskId => $taskRow) {
        $candidate = determineTaskAlertCandidate($conn, $taskRow);
        $requiredType = $candidate['type'] ?? null;
        $severity = $candidate['severity'] ?? 'warning';
        $metadataJson = !empty($candidate['metadata'])
            ? json_encode($candidate['metadata'], JSON_UNESCAPED_SLASHES)
            : null;
        $assignedTo = trim((string)($taskRow['assigned_to'] ?? ''));
        if ($assignedTo === '') {
            $assignedTo = null;
        }
        $assignedRole = trim((string)($taskRow['assigned_role'] ?? ''));
        if ($assignedRole === '') {
            $assignedRole = null;
        }
        $relatedRegNo = trim((string)($taskRow['related_reg_no'] ?? ''));
        if ($relatedRegNo === '') {
            $relatedRegNo = null;
        }
        $dueAtValue = trim((string)($candidate['due_at'] ?? ($taskRow['due_at'] ?? '')));
        if ($dueAtValue === '') {
            $dueAtValue = null;
        }

        $taskAlerts = $existingMap[$taskId] ?? [];
        if ($requiredType === null) {
            foreach ($taskAlerts as $existingAlert) {
                $alertId = (int)($existingAlert['alert_id'] ?? 0);
                if ($alertId <= 0 || !$resolveStmt) {
                    continue;
                }
                $resolveStmt->bind_param("i", $alertId);
                $resolveStmt->execute();
                $summary['resolved'] += max(0, (int)$resolveStmt->affected_rows);
            }
            continue;
        }

        foreach ($taskAlerts as $alertType => $existingAlert) {
            if ($alertType === $requiredType) {
                continue;
            }
            $alertId = (int)($existingAlert['alert_id'] ?? 0);
            if ($alertId <= 0 || !$resolveStmt) {
                continue;
            }
            $resolveStmt->bind_param("i", $alertId);
            $resolveStmt->execute();
            $summary['resolved'] += max(0, (int)$resolveStmt->affected_rows);
        }

        if (isset($taskAlerts[$requiredType])) {
            $existingAlert = $taskAlerts[$requiredType];
            $alertId = (int)($existingAlert['alert_id'] ?? 0);
            $alertStatus = strtolower(trim((string)($existingAlert['alert_status'] ?? '')));
            if ($alertId > 0) {
                if (in_array($alertStatus, ['resolved', 'dismissed'], true) && $reopenStmt) {
                    $reopenStmt->bind_param(
                        "ssssssi",
                        $severity,
                        $assignedTo,
                        $assignedRole,
                        $relatedRegNo,
                        $dueAtValue,
                        $metadataJson,
                        $alertId
                    );
                    $reopenStmt->execute();
                    $summary['reopened'] += max(0, (int)$reopenStmt->affected_rows);
                } elseif ($updateStmt) {
                    $updateStmt->bind_param(
                        "ssssssi",
                        $severity,
                        $assignedTo,
                        $assignedRole,
                        $relatedRegNo,
                        $dueAtValue,
                        $metadataJson,
                        $alertId
                    );
                    $updateStmt->execute();
                    $summary['updated'] += max(0, (int)$updateStmt->affected_rows);
                }
            }
        } elseif ($insertStmt) {
            $insertStmt->bind_param(
                "isssssss",
                $taskId,
                $requiredType,
                $severity,
                $assignedTo,
                $assignedRole,
                $relatedRegNo,
                $dueAtValue,
                $metadataJson
            );
            $insertStmt->execute();
            if ($insertStmt->affected_rows > 0) {
                $summary['opened'] += (int)$insertStmt->affected_rows;

                if (getAppSettingBool($conn, 'notify_task_alerts_enabled', true)) {
                    $subject = sprintf('Task Alert: %s', strtoupper(str_replace('_', ' ', $requiredType)));
                    $message = sprintf(
                        "Task #%d requires attention (%s).",
                        $taskId,
                        strtoupper(str_replace('_', ' ', $requiredType))
                    );
                    if ($assignedTo !== null) {
                        queueNotification($conn, 'push', $assignedTo, $subject, $message, [
                            'category' => 'task_alert',
                            'task_id' => $taskId,
                            'alert_type' => $requiredType,
                            'severity' => $severity
                        ]);
                    }

                    if ($severity === 'critical') {
                        $adminEmail = trim((string)getAppSetting($conn, 'security_alert_email'));
                        if ($adminEmail !== '') {
                            queueNotification($conn, 'email', $adminEmail, $subject, $message, [
                                'category' => 'task_alert',
                                'task_id' => $taskId,
                                'alert_type' => $requiredType,
                                'severity' => $severity
                            ]);
                        }
                    }
                }
            }
        }
    }

    if ($insertStmt) {
        $insertStmt->close();
    }
    if ($updateStmt) {
        $updateStmt->close();
    }
    if ($reopenStmt) {
        $reopenStmt->close();
    }
    if ($resolveStmt) {
        $resolveStmt->close();
    }

    return $summary;
}

function getTaskAlertSyncDebounceSeconds(mysqli $conn): int {
    $configured = getAppSettingInt($conn, 'task_alert_sync_debounce_seconds', 90);
    return max(30, min(1800, (int)$configured));
}

function maybeSyncTaskAlerts(mysqli $conn, ?int $minIntervalSeconds = null): array {
    ensureTasksTable($conn);
    ensureTaskAlertsTable($conn);
    if ($minIntervalSeconds === null || $minIntervalSeconds <= 0) {
        $minIntervalSeconds = getTaskAlertSyncDebounceSeconds($conn);
    }

    return pgoRunDebouncedMaintenanceTask(
        'task_alert_sync',
        $minIntervalSeconds,
        static function () use ($conn): array {
            return syncTaskAlerts($conn);
        }
    );
}

function normalizeRegistryLivingStatus(?string $status): string {
    $raw = strtolower(trim((string)$status));
    if ($raw === 'deceased' || $raw === 'dead' || $raw === 'late') {
        return 'Deceased';
    }
    return 'Alive';
}

function deriveLivingStatusFromRetirementType(?string $retirementType, ?string $fallbackStatus = 'Alive'): string
{
    $normalizedType = normalizeBenefitsRetirementTypeKey($retirementType);
    if ($normalizedType === 'death') {
        return 'Deceased';
    }

    $fallback = trim((string)$fallbackStatus);
    if ($fallback !== '') {
        return normalizeRegistryLivingStatus($fallback);
    }

    return 'Alive';
}

function deriveStaffLivingStatus(array $staffRow): string {
    if (isset($staffRow['livingStatus'])) {
        return normalizeRegistryLivingStatus((string)$staffRow['livingStatus']);
    }

    return deriveLivingStatusFromRetirementType((string)($staffRow['retirementType'] ?? ''), 'Alive');
}

function normalizeRegistryPayType(?string $payType): string {
    $raw = strtolower(trim((string)$payType));
    if ($raw === '') {
        return 'Pensioner';
    }

    // One-off aliases should be boxed together only.
    $normalized = preg_replace('/[^a-z0-9]/', '', $raw);
    $oneOffAliases = [
        'oneoffpayment',
        'oneoff',
        'oneoffpayout',
        'oneoffpay',
        'gratuityonly'
    ];
    if (in_array($normalized, $oneOffAliases, true)) {
        return 'One-off Payment';
    }

    return 'Pensioner';
}

function deriveRegistryPayTypeFromProfile(?string $retirementType, ?string $enlistmentDate, ?string $retirementDate, ?string $fallbackPayType = null): string
{
    $normalizedType = normalizeBenefitsRetirementTypeKey($retirementType);
    $rawFallback = trim((string)$fallbackPayType);
    $fallback = $rawFallback === '' ? '' : normalizeRegistryPayType($rawFallback);

    if ($normalizedType === '') {
        return $fallback;
    }

    switch ($normalizedType) {
        case 'mandatory':
        case 'voluntary':
        case 'oldAge':
        case 'abolition':
            return 'Pensioner';

        case 'marriage':
        case 'contract':
        case 'tx':
            return 'One-off Payment';

        default:
            break;
    }

    $months = calculateBenefitsLengthOfServiceMonths($enlistmentDate, $retirementDate);
    if ($months === null) {
        return $fallback;
    }

    $qualifiesForLongService = $months >= 120;

    switch ($normalizedType) {
        case 'early':
        case 'aor':
        case 'cbe':
        case 'ube':
        case 'public':
        case 'death':
        case 'medical':
            return $qualifiesForLongService ? 'Pensioner' : 'One-off Payment';

        default:
            return $fallback;
    }
}

function getRegistryBoxAllocationClass(?string $livingStatus, ?string $payType): string
{
    if (normalizeRegistryLivingStatus($livingStatus) === 'Deceased') {
        return 'Death';
    }

    return normalizeRegistryPayType($payType) === 'One-off Payment'
        ? 'One-off Payment'
        : 'Pensioner';
}

function getRegistryBoxAllocationPriority(?string $classification): int
{
    return match (trim((string)$classification)) {
        'Death' => 1,
        'Pensioner' => 2,
        'One-off Payment' => 3,
        default => 99,
    };
}

function getRegistryBoxNumberOptions(mysqli $conn): array
{
    static $memoryCache = null;
    if (is_array($memoryCache)) {
        return $memoryCache;
    }

    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/registry_box_number_options.json';
    if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 30) {
        $raw = @file_get_contents($cacheFile);
        if ($raw !== false) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $memoryCache = array_values(array_filter(array_map('strval', $decoded), static function ($value) {
                    return trim((string)$value) !== '';
                }));
                if (!empty($memoryCache)) {
                    return $memoryCache;
                }
            }
        }
    }

    $result = $conn->query("
        SELECT box_number
        FROM (
            SELECT DISTINCT
                TRIM(boxNo) AS box_number,
                CASE WHEN TRIM(boxNo) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END AS sort_group,
                CASE WHEN TRIM(boxNo) REGEXP '^[0-9]+$' THEN CAST(TRIM(boxNo) AS UNSIGNED) ELSE NULL END AS sort_numeric
            FROM tb_fileregistry
            WHERE COALESCE(is_deleted, 0) = 0
              AND boxNo IS NOT NULL
              AND TRIM(boxNo) <> ''
        ) box_options
        ORDER BY
            sort_group ASC,
            sort_numeric ASC,
            box_number ASC
    ");

    if (!$result) {
        return [];
    }

    $options = [];
    while ($row = $result->fetch_assoc()) {
        $value = trim((string)($row['box_number'] ?? ''));
        if ($value !== '') {
            $options[$value] = $value;
        }
    }
    $result->close();

    $memoryCache = array_values($options);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    if (is_dir($cacheDir)) {
        @file_put_contents($cacheFile, json_encode($memoryCache, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $memoryCache;
}

function clearRegistryBoxNumberOptionsCache(): void
{
    $cacheFile = __DIR__ . '/cache/registry_box_number_options.json';
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
}

function getRegistryBoxAllocationStats(mysqli $conn): array
{
    $result = $conn->query("
        SELECT
            CAST(boxNo AS UNSIGNED) AS box_num,
            livingStatus,
            payType
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
          AND boxNo IS NOT NULL
          AND TRIM(boxNo) <> ''
          AND boxNo REGEXP '^[0-9]+$'
        ORDER BY CAST(boxNo AS UNSIGNED) ASC
    ");

    $boxStats = [];
    $maxBox = 0;
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $boxNum = (int)($row['box_num'] ?? 0);
            if ($boxNum <= 0) {
                continue;
            }

            $classification = getRegistryBoxAllocationClass(
                (string)($row['livingStatus'] ?? ''),
                $row['payType'] ?? null
            );

            if (!isset($boxStats[$boxNum])) {
                $boxStats[$boxNum] = [
                    'death' => 0,
                    'pensioner' => 0,
                    'oneoff' => 0,
                    'total' => 0,
                ];
            }

            $classKey = match ($classification) {
                'Death' => 'death',
                'One-off Payment' => 'oneoff',
                default => 'pensioner',
            };

            $boxStats[$boxNum][$classKey]++;
            $boxStats[$boxNum]['total']++;
            if ($boxNum > $maxBox) {
                $maxBox = $boxNum;
            }
        }
        $result->close();
    }

    return [
        'boxStats' => $boxStats,
        'maxBox' => $maxBox,
    ];
}

function computeDateOn15Years(?string $retirementDate): ?string {
    $retirementDate = trim((string)$retirementDate);
    if ($retirementDate === '') {
        return null;
    }

    $timestamp = strtotime($retirementDate);
    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d', strtotime('+15 years', $timestamp));
}

function normalizeBenefitsRetirementTypeKey(?string $retirementType): string
{
    $value = strtolower(trim((string)$retirementType));
    if ($value === '') {
        return '';
    }

    $normalized = preg_replace('/[^a-z0-9]+/', '', $value);
    $definitions = getBenefitsRetirementTypeDefinitions();

    foreach ($definitions as $key => $definition) {
        if ($normalized === preg_replace('/[^a-z0-9]+/', '', $key)) {
            return $key;
        }
        foreach (($definition['aliases'] ?? []) as $alias) {
            $aliasToken = preg_replace('/[^a-z0-9]+/', '', strtolower(trim((string)$alias)));
            if ($aliasToken !== '' && $normalized === $aliasToken) {
                return $key;
            }
        }
    }

    return $normalized;
}

function getBenefitsRetirementTypeDefinitions(): array
{
    static $definitions = null;
    if ($definitions !== null) {
        return $definitions;
    }

    $definitions = [
        'mandatory' => [
            'label' => 'Mandatory Retirement',
            'aliases' => ['mandatory', 'mandatory retirement'],
        ],
        'early' => [
            'label' => 'Early Retirement',
            'aliases' => ['early', 'early retirement'],
        ],
        'death' => [
            'label' => 'Death',
            'aliases' => ['death', 'retirement by death'],
        ],
        'aor' => [
            'label' => 'Discharge (A.O.R)',
            'aliases' => ['aor', 'at own request', 'discharge aor', 'discharge (a.o.r)', 'attainment of required age', 'age of retirement'],
        ],
        'medical' => [
            'label' => 'Discharge (Medical)',
            'aliases' => ['medical', 'medical grounds', 'medical retirement', 'discharge medical', 'discharge (medical)'],
        ],
        'marriage' => [
            'label' => 'Discharge (Marriage)',
            'aliases' => ['marriage', 'marriage grounds', 'discharge marriage', 'discharge (marriage)'],
        ],
        'cbe' => [
            'label' => 'Discharge (C.B.E)',
            'aliases' => ['cbe', 'discharge cbe', 'discharge (c.b.e)', 'compulsory board exit', 'discharge'],
        ],
        'ube' => [
            'label' => 'Discharge (U.B.E)',
            'aliases' => ['ube', 'discharge ube', 'discharge (u.b.e)'],
        ],
        'public' => [
            'label' => 'Discharge (Public Interest)',
            'aliases' => ['public', 'public interest', 'discharge public interest', 'discharge (public interest)'],
        ],
        'contract' => [
            'label' => 'End of Contract',
            'aliases' => ['contract', 'contract expired', 'contract expiry', 'contract end', 'end of contract'],
        ],
        'tx' => [
            'label' => 'Discharge (T.X)',
            'aliases' => ['tx', 't.x', 'time expired', 'discharge tx', 'discharge (t.x)'],
        ],
        'voluntary' => [
            'label' => 'Voluntary',
            'aliases' => ['voluntary', 'voluntary retirement'],
        ],
        'oldAge' => [
            'label' => 'Old Age',
            'aliases' => ['oldage', 'old age'],
        ],
        'abolition' => [
            'label' => 'Abolition of Office',
            'aliases' => ['abolition', 'abolition of office'],
        ],
    ];

    return $definitions;
}

function getBenefitsRetirementTypeLabel(?string $retirementType): string
{
    $normalized = normalizeBenefitsRetirementTypeKey($retirementType);
    if ($normalized === '') {
        return '';
    }

    $definitions = getBenefitsRetirementTypeDefinitions();
    if (isset($definitions[$normalized]['label'])) {
        return (string)$definitions[$normalized]['label'];
    }

    return trim((string)$retirementType);
}

function getBenefitsRetirementTypeAliasesForFilter(?string $retirementType): array
{
    $normalized = normalizeBenefitsRetirementTypeKey($retirementType);
    if ($normalized === '') {
        return [];
    }

    $definitions = getBenefitsRetirementTypeDefinitions();
    $definition = $definitions[$normalized] ?? null;
    if ($definition === null) {
        return [strtolower(trim((string)$retirementType))];
    }

    $values = [$normalized, (string)($definition['label'] ?? '')];
    foreach (($definition['aliases'] ?? []) as $alias) {
        $values[] = (string)$alias;
    }

    $aliases = [];
    foreach ($values as $value) {
        $trimmed = strtolower(trim((string)$value));
        if ($trimmed !== '') {
            $aliases[$trimmed] = true;
        }
    }

    return array_keys($aliases);
}

function getBenefitsRetirementTypeSqlLiteralList(mysqli $conn, ?string $retirementType): string
{
    $aliases = getBenefitsRetirementTypeAliasesForFilter($retirementType);
    if (empty($aliases)) {
        return "''";
    }

    $quoted = [];
    foreach ($aliases as $alias) {
        $quoted[] = "'" . $conn->real_escape_string((string)$alias) . "'";
    }

    return implode(', ', $quoted);
}

function buildBenefitsRetirementTypeMatchSql(mysqli $conn, string $expression, ?string $retirementType): string
{
    $sqlList = getBenefitsRetirementTypeSqlLiteralList($conn, $retirementType);
    return "LOWER(TRIM(COALESCE({$expression}, ''))) IN ({$sqlList})";
}

function getBenefitsRetirementTypeSelectOptions(): array
{
    $options = [];
    foreach (getBenefitsRetirementTypeDefinitions() as $key => $definition) {
        $options[$key] = (string)($definition['label'] ?? $key);
    }
    return $options;
}

function isBenefitsRetirementTypeSupported(?string $retirementType): bool
{
    $normalized = normalizeBenefitsRetirementTypeKey($retirementType);
    if ($normalized === '') {
        return false;
    }
    $definitions = getBenefitsRetirementTypeDefinitions();
    return array_key_exists($normalized, $definitions);
}

function calculateServicePeriodMonthsAndDays(?string $enlistmentDate, ?string $retirementDate): ?array
{
    $enlistmentDate = trim((string)$enlistmentDate);
    $retirementDate = trim((string)$retirementDate);
    if ($enlistmentDate === '' || $retirementDate === '') {
        return null;
    }

    try {
        $start = new DateTimeImmutable($enlistmentDate);
        $end = new DateTimeImmutable($retirementDate);
    } catch (Throwable $e) {
        return null;
    }

    if ($end <= $start) {
        return null;
    }

    $yearDiff = (int)$end->format('Y') - (int)$start->format('Y');
    $monthDiff = (int)$end->format('n') - (int)$start->format('n');
    $dayDiff = (int)$end->format('j') - (int)$start->format('j');

    if ($dayDiff < 0) {
        $prevMonth = $end->modify('first day of this month')->modify('-1 day');
        $dayDiff += (int)$prevMonth->format('t');
        $monthDiff -= 1;
    }

    if ($monthDiff < 0) {
        $monthDiff += 12;
        $yearDiff -= 1;
    }

    $months = max(0, ($yearDiff * 12) + $monthDiff);
    $days = max(0, $dayDiff);
    $roundedMonths = $days >= 15 ? $months + 1 : $months;

    return [
        'months' => $months,
        'days' => $days,
        'rounded_months' => $roundedMonths
    ];
}

function calculateBenefitsLengthOfServiceMonths(?string $enlistmentDate, ?string $retirementDate): ?int
{
    $period = calculateServicePeriodMonthsAndDays($enlistmentDate, $retirementDate);
    if ($period === null) {
        return null;
    }
    return (int)($period['rounded_months'] ?? 0);
}

function calculateAgeAtRetirementDate(?string $birthDate, ?string $retirementDate): ?int
{
    $birthDate = trim((string)$birthDate);
    $retirementDate = trim((string)$retirementDate);
    if ($birthDate === '' || $retirementDate === '') {
        return null;
    }

    try {
        $dob = new DateTimeImmutable($birthDate);
        $retire = new DateTimeImmutable($retirementDate);
    } catch (Throwable $e) {
        return null;
    }

    if ($retire < $dob) {
        return null;
    }

    $age = (int)$retire->format('Y') - (int)$dob->format('Y');
    $dobMonthDay = $dob->format('md');
    $retireMonthDay = $retire->format('md');
    if ($retireMonthDay < $dobMonthDay) {
        $age--;
    }

    return max(0, $age);
}

function formatRetirementPolicyServiceLabel(?int $months): string
{
    if ($months === null) {
        return 'service duration unavailable';
    }

    $safeMonths = max(0, (int)$months);
    $years = intdiv($safeMonths, 12);
    $remainingMonths = $safeMonths % 12;

    return sprintf(
        '%d year%s, %d month%s',
        $years,
        $years === 1 ? '' : 's',
        $remainingMonths,
        $remainingMonths === 1 ? '' : 's'
    );
}

function validateRetirementPolicyProfile(?string $retirementType, ?string $birthDate, ?string $enlistmentDate, ?string $retirementDate): array
{
    $normalizedType = normalizeBenefitsRetirementTypeKey($retirementType);
    $label = getBenefitsRetirementTypeLabel($normalizedType);
    $birthDate = trim((string)$birthDate);
    $enlistmentDate = trim((string)$enlistmentDate);
    $retirementDate = trim((string)$retirementDate);
    $errors = [];
    $warnings = [];
    $mandatoryRetirementRuleLabel = 'age 60 at retirement, or age 55 when the retirement year is 2000 or earlier';

    $parseDate = static function (?string $value): ?DateTimeImmutable {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
    };

    $birth = $parseDate($birthDate);
    $enlistment = $parseDate($enlistmentDate);
    $retirement = $parseDate($retirementDate);

    if ($birthDate !== '' && $birth === null) {
        $errors[] = 'Date of birth is invalid.';
    }
    if ($enlistmentDate !== '' && $enlistment === null) {
        $errors[] = 'Date of enlistment is invalid.';
    }
    if ($retirementDate !== '' && $retirement === null) {
        $errors[] = 'Date of retirement is invalid.';
    }

    if ($birth && $enlistment && $enlistment <= $birth) {
        $errors[] = 'Date of enlistment must be later than date of birth.';
    }
    if ($birth && $retirement && $retirement <= $birth) {
        $errors[] = 'Retirement date must be later than date of birth.';
    }
    if ($enlistment && $retirement && $retirement <= $enlistment) {
        $errors[] = 'Retirement date must be later than the enlistment date.';
    }

    $serviceMonths = null;
    if ($enlistment && $retirement && $retirement > $enlistment) {
        $serviceMonths = calculateBenefitsLengthOfServiceMonths($enlistmentDate, $retirementDate);
    }
    $ageAtRetirement = null;
    if ($birth && $retirement && $retirement > $birth) {
        $ageAtRetirement = calculateAgeAtRetirementDate($birthDate, $retirementDate);
    }
    $qualifiesMandatoryRetirementAge = static function (?int $ageAtRetirement, ?DateTimeImmutable $retirement): bool {
        if ($ageAtRetirement === 60) {
            return true;
        }
        if ($ageAtRetirement === 55 && $retirement instanceof DateTimeImmutable) {
            return (int)$retirement->format('Y') <= 2000;
        }
        return false;
    };

    if ($normalizedType !== '') {
        switch ($normalizedType) {
            case 'mandatory':
                if ($birthDate === '') {
                    $errors[] = "{$label} requires date of birth so the system can confirm {$mandatoryRetirementRuleLabel}.";
                } elseif (!$qualifiesMandatoryRetirementAge($ageAtRetirement, $retirement)) {
                    $ageText = $ageAtRetirement === null ? 'an invalid age profile' : "age {$ageAtRetirement}";
                    $retirementYearText = $retirement instanceof DateTimeImmutable
                        ? ' in ' . $retirement->format('Y')
                        : ' with no valid retirement year';
                    $errors[] = "{$label} requires {$mandatoryRetirementRuleLabel}; this profile evaluates to {$ageText}{$retirementYearText}.";
                }
                break;

            case 'oldAge':
                if ($birthDate === '') {
                    $errors[] = "{$label} requires date of birth so the system can confirm retirement at age 60 or above.";
                } elseif ($ageAtRetirement === null || $ageAtRetirement < 60) {
                    $ageText = $ageAtRetirement === null ? 'an invalid age profile' : "age {$ageAtRetirement}";
                    $errors[] = "{$label} requires age 60 or above at retirement; this profile evaluates to {$ageText}.";
                }
                break;

            case 'marriage':
                if ($birthDate === '') {
                    $errors[] = "{$label} requires date of birth so the system can confirm the below-45 age policy.";
                } elseif ($ageAtRetirement === null || $ageAtRetirement >= 45) {
                    $ageText = $ageAtRetirement === null ? 'an invalid age profile' : "age {$ageAtRetirement}";
                    $errors[] = "{$label} should only be captured below age 45; this profile evaluates to {$ageText}.";
                }
                if ($serviceMonths !== null && $serviceMonths >= 240) {
                    $warnings[] = "{$label} is unusual at " . formatRetirementPolicyServiceLabel($serviceMonths) . ' of service. Reconfirm the retirement authority.';
                }
                break;

            case 'early':
            case 'aor':
                if ($enlistmentDate === '' || $retirementDate === '') {
                    $errors[] = "{$label} requires enlistment and retirement dates so the qualifying service can be confirmed.";
                    break;
                }

                if ($serviceMonths === null) {
                    $errors[] = 'Retirement date must be later than the enlistment date.';
                    break;
                }

                if ($serviceMonths >= 240) {
                    break;
                }

                if ($birthDate === '') {
                    $errors[] = "{$label} requires either 20 years of service, or at least 10 years of service with age 45 years or above. Provide date of birth to validate the age-based route.";
                    break;
                }

                $qualifiesByAgeRoute = $ageAtRetirement !== null && $ageAtRetirement >= 45 && $serviceMonths >= 120;
                if (!$qualifiesByAgeRoute) {
                    $ageText = $ageAtRetirement === null ? 'age unavailable' : "age {$ageAtRetirement}";
                    $serviceText = formatRetirementPolicyServiceLabel($serviceMonths);
                    $errors[] = "{$label} requires either 20 years of service, or at least 10 years of service with age 45 years or above at retirement. This profile evaluates to {$serviceText} and {$ageText}.";
                }
                break;

            default:
                break;
        }
    }

    $uniqueErrors = array_values(array_unique(array_filter($errors)));
    $uniqueWarnings = array_values(array_unique(array_filter($warnings)));
    $primaryMessage = $uniqueErrors[0] ?? ($uniqueWarnings[0] ?? '');

    return [
        'valid' => count($uniqueErrors) === 0,
        'errors' => $uniqueErrors,
        'warnings' => $uniqueWarnings,
        'primaryMessage' => $primaryMessage,
        'retirementTypeKey' => $normalizedType,
        'retirementTypeLabel' => $label,
        'ageAtRetirement' => $ageAtRetirement,
        'lengthOfServiceMonths' => $serviceMonths,
    ];
}

function calculateBenefitSnapshotFromInputs(?string $retirementType, ?string $enlistmentDate, ?string $retirementDate, $monthlySalary, ?string $birthDate = null, bool $forceApprovedSnapshot = false): array
{
    $salary = is_numeric($monthlySalary) ? (float)$monthlySalary : (float)preg_replace('/[^0-9.\\-]/', '', (string)$monthlySalary);
    $salary = round(max($salary, 0), 2);

    $months = calculateBenefitsLengthOfServiceMonths($enlistmentDate, $retirementDate);
    $annual = $salary > 0 ? round($salary * 12, 2) : null;

    $result = [
        'lengthOfService' => $months,
        'annualSalary' => $annual,
        'reducedPension' => null,
        'fullPension' => null,
        'gratuity' => null,
        'retirementTypeKey' => normalizeBenefitsRetirementTypeKey($retirementType),
        'retirementTypeLabel' => getBenefitsRetirementTypeLabel($retirementType),
    ];

    if ($months === null || $annual === null || $annual <= 0) {
        return $result;
    }

    $cappedMonths = min($months, 900);
    $baseAmount = (($cappedMonths * $annual) / 500);
    $mandatoryGratuity = $baseAmount * (1 / 3) * 15;
    $monthlyPensionFormula = (($baseAmount * (2 / 3)) / 12);
    $fullPensionFormula = $baseAmount / 12;
    $shortServiceGratuity = (($cappedMonths * $annual) * 10) / 500;
    $marriageGratuity = (($cappedMonths * $annual) * 5) / 500;
    $contractGratuity = 0.25 * $annual * 2;
    $abolitionGratuity = $baseAmount * 0.25 * (1 / 3) * 15;
    $abolitionReducedPension = ($baseAmount * 0.25 * (2 / 3)) / 12;
    $abolitionFullPension = ($baseAmount * 0.25) / 12;

    $normalizedType = normalizeBenefitsRetirementTypeKey($retirementType);
    $qualifiesForLongService = $cappedMonths >= 120;

    $result['gratuity'] = 0.0;
    $result['reducedPension'] = 0.0;
    $result['fullPension'] = 0.0;

    $assignMandatoryBenefits = static function () use (&$result, $mandatoryGratuity, $monthlyPensionFormula, $fullPensionFormula): void {
        $result['gratuity'] = round($mandatoryGratuity, 2);
        $result['reducedPension'] = round($monthlyPensionFormula, 2);
        $result['fullPension'] = round($fullPensionFormula, 2);
    };

    switch ($normalizedType) {
        case 'mandatory':
        case 'voluntary':
        case 'oldAge':
            $assignMandatoryBenefits();
            break;
        case 'early':
        case 'aor':
            if ($qualifiesForLongService) {
                $assignMandatoryBenefits();
            }
            break;
        case 'death':
        case 'medical':
            $result['gratuity'] = round(max(3 * $annual, $mandatoryGratuity), 2);
            if ($qualifiesForLongService) {
                $result['reducedPension'] = round($monthlyPensionFormula, 2);
                $result['fullPension'] = round($fullPensionFormula, 2);
            }
            break;
        case 'marriage':
            $result['gratuity'] = round($marriageGratuity, 2);
            break;
        case 'cbe':
        case 'ube':
        case 'public':
            if ($qualifiesForLongService) {
                $assignMandatoryBenefits();
            } else {
                $result['gratuity'] = round($shortServiceGratuity, 2);
            }
            break;
        case 'contract':
        case 'tx':
            $result['gratuity'] = round($contractGratuity, 2);
            break;
        case 'abolition':
            $result['gratuity'] = round($abolitionGratuity, 2);
            $result['reducedPension'] = round($abolitionReducedPension, 2);
            $result['fullPension'] = round($abolitionFullPension, 2);
            break;
        default:
            if ($forceApprovedSnapshot) {
                if ($qualifiesForLongService) {
                    $assignMandatoryBenefits();
                } else {
                    $result['gratuity'] = round($shortServiceGratuity, 2);
                }
            }
            break;
    }

    return $result;
}

function ensureFileRegistryPerformanceIndexes(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $indexes = [
        'idx_fileregistry_recent' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_recent (timeStamp, id)",
        'idx_fileregistry_availability_recent' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_availability_recent (availability_status, timeStamp, id)",
        'idx_fileregistry_name_sort' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_name_sort (sName, fName, id)",
        'idx_fileregistry_regno_active' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_regno_active (regNo, is_deleted)",
        'idx_fileregistry_box_active' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_box_active (is_deleted, boxNo)",
        'idx_fileregistry_active_recent' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_active_recent (is_deleted, timeStamp, id)",
        'idx_fileregistry_active_name' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_active_name (is_deleted, sName, fName, id)",
        'idx_fileregistry_active_payroll' => "ALTER TABLE tb_fileregistry ADD INDEX idx_fileregistry_active_payroll (is_deleted, payrollStatus, id)"
    ];

    foreach ($indexes as $indexName => $sql) {
        $result = $conn->query("SHOW INDEX FROM tb_fileregistry WHERE Key_name = '{$indexName}'");
        if ($result && $result->num_rows === 0) {
            $conn->query($sql);
        }
    }

    $ensured = true;
}

function ensureStaffDuePerformanceIndexes(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $indexes = [
        'idx_staffdue_regno' => "ALTER TABLE tb_staffdue ADD INDEX idx_staffdue_regno (regNo)",
        'idx_staffdue_workflow_status' => "ALTER TABLE tb_staffdue ADD INDEX idx_staffdue_workflow_status (is_deleted, submissionStatus, appnStatus, submission_at)",
        'idx_staffdue_retirement_type' => "ALTER TABLE tb_staffdue ADD INDEX idx_staffdue_retirement_type (retirementType)",
        'idx_staffdue_active_submission_order' => "ALTER TABLE tb_staffdue ADD INDEX idx_staffdue_active_submission_order (is_deleted, submission_at, timeStamp, id)",
        'idx_staffdue_active_retirement' => "ALTER TABLE tb_staffdue ADD INDEX idx_staffdue_active_retirement (is_deleted, retirementType, id)",
        'idx_staffdue_active_appn' => "ALTER TABLE tb_staffdue ADD INDEX idx_staffdue_active_appn (is_deleted, appnStatus, id)",
        'idx_application_queue_staff_status' => "ALTER TABLE tb_application_queue ADD INDEX idx_application_queue_staff_status (staffdue_id, status)"
    ];

    foreach ($indexes as $indexName => $sql) {
        $tableName = (strpos($indexName, 'application_queue') !== false) ? 'tb_application_queue' : 'tb_staffdue';
        $result = $conn->query("SHOW INDEX FROM {$tableName} WHERE Key_name = '{$indexName}'");
        if ($result && $result->num_rows === 0) {
            $conn->query($sql);
        }
    }

    $ensured = true;
}

function ensureTaskPerformanceIndexes(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $indexes = [
        'idx_tasks_related_staff_status_type' => "ALTER TABLE tb_tasks ADD INDEX idx_tasks_related_staff_status_type (related_staff_id, status, task_type)",
        'idx_tasks_assignee_status' => "ALTER TABLE tb_tasks ADD INDEX idx_tasks_assignee_status (assigned_to, status)",
        'idx_tasks_role_status' => "ALTER TABLE tb_tasks ADD INDEX idx_tasks_role_status (assigned_role, status)",
        'idx_tasks_status_completed' => "ALTER TABLE tb_tasks ADD INDEX idx_tasks_status_completed (status, completed_at)",
        'idx_tasks_status_due_updated' => "ALTER TABLE tb_tasks ADD INDEX idx_tasks_status_due_updated (status, due_at, updated_at)"
    ];

    foreach ($indexes as $indexName => $sql) {
        $result = $conn->query("SHOW INDEX FROM tb_tasks WHERE Key_name = '{$indexName}'");
        if ($result && $result->num_rows === 0) {
            $conn->query($sql);
        }
    }

    $ensured = true;
}

function allocateRegistryBoxNumber(mysqli $conn, string $livingStatus, ?string $payType = null): string {
    $targetClassification = getRegistryBoxAllocationClass($livingStatus, $payType);
    $targetKey = match ($targetClassification) {
        'Death' => 'death',
        'One-off Payment' => 'oneoff',
        default => 'pensioner',
    };

    $stats = getRegistryBoxAllocationStats($conn);
    $boxStats = $stats['boxStats'] ?? [];
    $maxBox = (int)($stats['maxBox'] ?? 0);

    if ($maxBox === 0) {
        return '1';
    }

    for ($box = 1; $box <= $maxBox; $box++) {
        if (!isset($boxStats[$box])) {
            continue;
        }

        $currentStats = $boxStats[$box];
        if (($currentStats['total'] ?? 0) >= 70) {
            continue;
        }

        if (($currentStats[$targetKey] ?? 0) === ($currentStats['total'] ?? 0)) {
            return (string)$box;
        }
    }

    for ($box = 1; $box <= $maxBox; $box++) {
        if (!isset($boxStats[$box])) {
            return (string)$box;
        }
    }

    return (string)($maxBox + 1);
}

function rebalanceRegistryBoxNumbers(mysqli $conn): array {
    $rows = [];
    $result = $conn->query("
        SELECT id, livingStatus, payType, boxNo, timeStamp
        FROM tb_fileregistry
        WHERE COALESCE(is_deleted, 0) = 0
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();
    }

    if (empty($rows)) {
        return ['updated' => 0, 'boxes' => 0];
    }

    usort($rows, static function (array $left, array $right): int {
        $leftPriority = getRegistryBoxAllocationPriority(
            getRegistryBoxAllocationClass(
                (string)($left['livingStatus'] ?? ''),
                $left['payType'] ?? null
            )
        );
        $rightPriority = getRegistryBoxAllocationPriority(
            getRegistryBoxAllocationClass(
                (string)($right['livingStatus'] ?? ''),
                $right['payType'] ?? null
            )
        );

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        $leftBox = preg_match('/^\d+$/', trim((string)($left['boxNo'] ?? '')))
            ? (int)trim((string)$left['boxNo'])
            : PHP_INT_MAX;
        $rightBox = preg_match('/^\d+$/', trim((string)($right['boxNo'] ?? '')))
            ? (int)trim((string)$right['boxNo'])
            : PHP_INT_MAX;
        if ($leftBox !== $rightBox) {
            return $leftBox <=> $rightBox;
        }

        $leftTime = strtotime((string)($left['timeStamp'] ?? '')) ?: PHP_INT_MAX;
        $rightTime = strtotime((string)($right['timeStamp'] ?? '')) ?: PHP_INT_MAX;
        if ($leftTime !== $rightTime) {
            return $leftTime <=> $rightTime;
        }

        return ((int)($left['id'] ?? 0)) <=> ((int)($right['id'] ?? 0));
    });

    $conn->begin_transaction();
    try {
        $clearStmt = $conn->prepare("UPDATE tb_fileregistry SET boxNo = NULL WHERE COALESCE(is_deleted, 0) = 0");
        if (!$clearStmt) {
            throw new RuntimeException('Failed to prepare registry box reset.');
        }
        if (!$clearStmt->execute()) {
            $error = $clearStmt->error;
            $clearStmt->close();
            throw new RuntimeException($error ?: 'Failed to reset existing box numbers.');
        }
        $clearStmt->close();

        $updateStmt = $conn->prepare("UPDATE tb_fileregistry SET boxNo = ? WHERE id = ?");
        if (!$updateStmt) {
            throw new RuntimeException('Failed to prepare registry box update.');
        }

        $updated = 0;
        $usedBoxes = [];
        foreach ($rows as $row) {
            $boxNo = allocateRegistryBoxNumber(
                $conn,
                (string)($row['livingStatus'] ?? 'Alive'),
                $row['payType'] ?? null
            );
            $recordId = (int)($row['id'] ?? 0);
            $updateStmt->bind_param('si', $boxNo, $recordId);
            if (!$updateStmt->execute()) {
                $error = $updateStmt->error;
                $updateStmt->close();
                throw new RuntimeException($error ?: 'Failed to assign balanced box number.');
            }
            $updated++;
            $usedBoxes[$boxNo] = true;
        }

        $updateStmt->close();
        $conn->commit();

        return ['updated' => $updated, 'boxes' => count($usedBoxes)];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function createWorkflowTask(mysqli $conn, array $data): ?int {
    ensureTasksTable($conn);

    $createdBy = $data['created_by'] ?? null;
    $assignedTo = $data['assigned_to'] ?? null;
    $assignedRole = $data['assigned_role'] ?? null;
    $taskType = $data['task_type'] ?? null;
    $taskTitle = $data['task_title'] ?? null;
    $taskDescription = $data['task_description'] ?? null;
    $status = $data['status'] ?? 'pending';
    $priority = strtolower((string)($data['priority'] ?? 'normal'));
    $allowedPriority = ['low', 'normal', 'high', 'urgent'];
    if (!in_array($priority, $allowedPriority, true)) {
        $priority = 'normal';
    }
    $relatedStaffId = $data['related_staff_id'] ?? null;
    $relatedRegNo = $data['related_reg_no'] ?? null;
    $parentTaskId = isset($data['parent_task_id']) ? (int)$data['parent_task_id'] : null;
    $dueAt = $data['due_at'] ?? null;
    if ($dueAt === null || trim((string)$dueAt) === '') {
        // Workflow due dates follow configurable business-day rules.
        $dueAt = calculateTaskDueDateTime($conn);
    }
    $metadata = $data['metadata'] ?? null;

    if (is_array($metadata)) {
        $metadata = json_encode($metadata, JSON_UNESCAPED_SLASHES);
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_tasks (
            created_by, assigned_to, assigned_role, task_type, task_title, task_description,
            status, priority, related_staff_id, related_reg_no, parent_task_id, due_at, metadata, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        "ssssssssisiss",
        $createdBy,
        $assignedTo,
        $assignedRole,
        $taskType,
        $taskTitle,
        $taskDescription,
        $status,
        $priority,
        $relatedStaffId,
        $relatedRegNo,
        $parentTaskId,
        $dueAt,
        $metadata
    );

    $ok = $stmt->execute();
    $taskId = $ok ? (int)$stmt->insert_id : null;
    $stmt->close();

    if ($taskId && function_exists('recordWorkflowLog')) {
        recordWorkflowLog($conn, [
            'task_id' => $taskId,
            'staffdue_id' => $relatedStaffId,
            'regNo' => $relatedRegNo,
            'action' => 'task_created',
            'from_status' => null,
            'to_status' => $status,
            'actor_id' => $createdBy,
            'actor_name' => $data['created_by_name'] ?? ($_SESSION['userName'] ?? 'System'),
            'actor_role' => $data['created_by_role'] ?? ($_SESSION['userRole'] ?? 'system'),
            'metadata' => [
                'task_type' => $taskType,
                'assigned_to' => $assignedTo,
                'assigned_role' => $assignedRole,
                'priority' => $priority
            ]
        ]);
    }
    return $taskId;
}

function buildImportReviewExportPayload(string $filePrefix, array $rows, array $preferredColumns = []): ?array {
    if (empty($rows)) {
        return null;
    }

    $columns = [];
    foreach ($preferredColumns as $column) {
        $label = trim((string)$column);
        if ($label !== '' && !in_array($label, $columns, true)) {
            $columns[] = $label;
        }
    }

    if (empty($columns)) {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                $label = trim((string)$key);
                if ($label !== '' && !in_array($label, $columns, true)) {
                    $columns[] = $label;
                }
            }
        }
    }

    if (empty($columns)) {
        return null;
    }

    $safePrefix = preg_replace('/[^a-zA-Z0-9._-]+/', '_', trim($filePrefix));
    $safePrefix = trim((string)$safePrefix, '._-');
    if ($safePrefix === '') {
        $safePrefix = 'import_review';
    }

    $stream = fopen('php://temp', 'w+');
    if ($stream === false) {
        return null;
    }

    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, $columns);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = [];
        foreach ($columns as $column) {
            $value = $row[$column] ?? '';
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $line[] = (string)$value;
        }
        fputcsv($stream, $line);
    }

    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    if ($csv === false || $csv === '') {
        return null;
    }

    return [
        'file_name' => $safePrefix . '_review_' . date('Ymd_His') . '.csv',
        'mime' => 'text/csv; charset=utf-8',
        'row_count' => count($rows),
        'columns' => $columns,
        'content_base64' => base64_encode($csv)
    ];
}

function buildImportReviewRowFromSource(array $headers, array $sourceRow, array $meta = []): array {
    $mapped = [];
    $labelCounts = [];

    foreach (array_values($headers) as $index => $header) {
        $label = trim((string)$header);
        if ($label === '') {
            $label = 'Column ' . ($index + 1);
        }
        if (isset($labelCounts[$label])) {
            $labelCounts[$label]++;
            $label .= ' (' . $labelCounts[$label] . ')';
        } else {
            $labelCounts[$label] = 1;
        }
        $mapped[$label] = trim((string)($sourceRow[$index] ?? ''));
    }

    $reviewFields = $meta['Review Fields'] ?? [];
    if (is_array($reviewFields)) {
        $reviewFields = implode(', ', array_values(array_filter(array_map(static fn($value) => trim((string)$value), $reviewFields), static fn($value) => $value !== '')));
    }

    $reviewRow = [
        'Source Row' => (string)($meta['Source Row'] ?? ''),
        'Review Status' => trim((string)($meta['Review Status'] ?? 'Needs Review')),
        'Review Reason' => trim((string)($meta['Review Reason'] ?? 'Check this row before re-importing.')),
        'Review Fields' => trim((string)$reviewFields),
        'Matched Key' => trim((string)($meta['Matched Key'] ?? ''))
    ];

    foreach ($mapped as $label => $value) {
        $reviewRow[$label] = $value;
    }

    return $reviewRow;
}

?>
