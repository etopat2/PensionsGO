<?php
/**
 * 
 * login.php
 * 
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers for CORS and JSON response
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight OPTIONS request
// Start output buffering
ob_start();
$responseSent = false;

$sendJsonResponse = static function (array $payload, int $statusCode = 200) use (&$responseSent): void {
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $responseSent = true;
    exit;
};

register_shutdown_function(static function () use (&$responseSent, $sendJsonResponse): void {
    if ($responseSent) {
        return;
    }

    $lastError = error_get_last();
    if (!$lastError) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array((int)$lastError['type'], $fatalTypes, true)) {
        return;
    }

    error_log('LOGIN FATAL: ' . ($lastError['message'] ?? 'Unknown fatal error'));
    $sendJsonResponse([
        'success' => false,
        'message' => 'Login failed due to a server error.',
        'errorCode' => 500
    ], 500);
});

try {
    // 
    // Load config
    // 
    require_once __DIR__ . '/../config.php';
    applyApiCorsPolicy($conn, ['POST', 'OPTIONS']);

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    // Check if database connection is available
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection not available');
    }
    
    // Check if SessionManager is available
    if (!isset($sessionManager) || !($sessionManager instanceof SessionManager)) {
        throw new Exception('SessionManager not available');
    }

    if (function_exists('ensureUserPasswordUpdatedAtColumn')) {
        ensureUserPasswordUpdatedAtColumn($conn);
    }
    if (function_exists('ensureUserActiveColumn')) {
        ensureUserActiveColumn($conn);
    }

    // 
    // Validate request
    // 
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. Only POST is allowed.', 405);
    }

    // Get and validate input
    $identifierRaw = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (empty($identifierRaw) || empty($password)) {
        throw new Exception('Missing credentials. Please provide both email/phone and password.', 400);
    }

    $requestIp = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '');
    $ipAttemptLimit = max(10, (int)(function_exists('getAppSettingInt') ? getAppSettingInt($conn, 'login_ip_attempt_limit', 20) : 20));
    $ipLockoutMinutes = max(5, (int)(function_exists('getAppSettingInt') ? getAppSettingInt($conn, 'login_ip_lockout_minutes', 15) : 15));
    if ($requestIp !== '') {
        $ipStmt = $conn->prepare("
            SELECT COUNT(*) AS fail_count
            FROM tb_user_logs
            WHERE activity_type = 'login_failed'
              AND ip_address = ?
              AND created_at >= (NOW() - INTERVAL ? MINUTE)
        ");
        if ($ipStmt) {
            $ipStmt->bind_param('si', $requestIp, $ipLockoutMinutes);
            $ipStmt->execute();
            $ipRow = $ipStmt->get_result()->fetch_assoc();
            $ipStmt->close();
            if ((int)($ipRow['fail_count'] ?? 0) >= $ipAttemptLimit) {
                throw new Exception('Too many failed attempts from this network. Please wait before trying again.', 429);
            }
        }
    }

    // 
    // Identify login method (email or phone)
    // 
    $isEmail = filter_var($identifierRaw, FILTER_VALIDATE_EMAIL);
    $normalizedPhone = $isEmail ? null : normalizePhoneNumber($identifierRaw);
    $isPhone = (!$isEmail && $normalizedPhone !== null);

    if (!$isEmail && !$isPhone) {
        throw new Exception('Enter a valid email or phone number (e.g., +256700123456, 0770123456, 0312123456, 0800123456).', 400);
    }

    // 
    // Fetch user from database
    // 
    $user = null;
    if ($isEmail) {
        $stmt = $conn->prepare("SELECT userId, userName, userRole, userPassword, userPhoto, phoneNo, userEmail, password_updated_at, timeStamp, is_active FROM tb_users WHERE userEmail = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Database query preparation failed: ' . $conn->error);
        }
        $stmt->bind_param('s', $identifierRaw);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $user = $result->fetch_assoc();
        }
        $stmt->close();
    } else {
        $phoneCandidates = buildPhoneLookupCandidates($identifierRaw);
        $stmt = $conn->prepare("SELECT userId, userName, userRole, userPassword, userPhoto, phoneNo, userEmail, password_updated_at, timeStamp, is_active FROM tb_users WHERE phoneNo = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Database query preparation failed: ' . $conn->error);
        }

        foreach ($phoneCandidates as $candidate) {
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $user = $result->fetch_assoc();
                break;
            }
        }
        $stmt->close();
    }

    if (!$user) {
        if (function_exists('logUserActivity')) {
            logUserActivity($conn, [
                'user_id' => 'unknown',
                'user_name' => 'Unknown User',
                'user_role' => 'guest',
                'activity_type' => 'login_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => session_id(),
                'details' => "Failed login attempt for unknown account: {$identifierRaw}"
            ]);
        }
        throw new Exception('Invalid credentials. User not found.', 401);
    }

    if ((int)($user['is_active'] ?? 1) !== 1) {
        if (function_exists('logUserActivity')) {
            logUserActivity($conn, [
                'user_id' => $user['userId'],
                'user_name' => $user['userName'],
                'user_role' => $user['userRole'],
                'activity_type' => 'login_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => session_id(),
                'details' => 'Login blocked: Account is deactivated'
            ]);
        }
        throw new Exception('This account has been deactivated. Please contact the system administrator.', 403);
    }

    // Verify password
    if (!password_verify($password, $user['userPassword'])) {
        // Log failed attempt
        if (function_exists('logUserActivity')) {
            logUserActivity($conn, [
                'user_id' => 'unknown',
                'user_name' => 'Unknown User',
                'user_role' => 'guest',
                'activity_type' => 'login_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => session_id(),
                'details' => "Failed login attempt for: {$identifierRaw}"
            ]);
        }
        throw new Exception('Invalid credentials. Please check your email/phone and password.', 401);
    }

    // 
    // Pensioner portal access control
    // 
    $pensionerLoginEnabled = function_exists('getAppSettingBool')
        ? getAppSettingBool($conn, 'pensioner_login_enabled', true)
        : true;

    if (($user['userRole'] ?? '') === 'pensioner' && !$pensionerLoginEnabled) {
        if (function_exists('logUserActivity')) {
            logUserActivity($conn, [
                'user_id' => $user['userId'],
                'user_name' => $user['userName'],
                'user_role' => $user['userRole'],
                'activity_type' => 'login_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => session_id(),
                'details' => 'Login blocked: Pensioner login is currently disabled'
            ]);
        }

        throw new Exception('Pensioner login is currently disabled. Please contact the pensions office for assistance.', 403);
    }

    $staffLoginEnabled = function_exists('getAppSettingBool')
        ? getAppSettingBool($conn, 'staff_login_enabled', true)
        : true;
    $loginRoleRaw = function_exists('normalizeRoleKey')
        ? normalizeRoleKey((string)($user['userRole'] ?? ''))
        : strtolower(trim((string)($user['userRole'] ?? '')));
    $loginRoleForStaffGate = function_exists('resolveRoleAccessKey')
        ? resolveRoleAccessKey($conn, $loginRoleRaw)
        : $loginRoleRaw;
    $isStaffAccount = $loginRoleRaw !== 'pensioner' && $loginRoleRaw !== 'super_admin' && $loginRoleForStaffGate !== 'admin';

    if ($isStaffAccount && !$staffLoginEnabled) {
        if (function_exists('logUserActivity')) {
            logUserActivity($conn, [
                'user_id' => $user['userId'],
                'user_name' => $user['userName'],
                'user_role' => $user['userRole'],
                'activity_type' => 'login_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => session_id(),
                'details' => 'Login blocked: Staff login is currently disabled'
            ]);
        }

        throw new Exception('Staff account login is currently disabled. Please contact the system administrator.', 403);
    }

    // 
    // Password expiry enforcement
    // 
    $expiryRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'password_expiry_days') : null;
    $expiryDays = is_numeric($expiryRaw) ? (int)$expiryRaw : 0;
    if ($expiryDays > 0) {
        $updatedAt = $user['password_updated_at'] ?? $user['timeStamp'] ?? null;
        if (!empty($updatedAt)) {
            $expiryCutoff = strtotime("-{$expiryDays} days");
            $updatedAtTs = strtotime($updatedAt);
            if ($updatedAtTs && $updatedAtTs < $expiryCutoff) {
                if (function_exists('logUserActivity')) {
                    logUserActivity($conn, [
                        'user_id' => $user['userId'],
                        'user_name' => $user['userName'],
                        'user_role' => $user['userRole'],
                        'activity_type' => 'login_failed',
                        'ip_address' => getClientIP(),
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                        'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                        'location' => getLocationFromIP(getClientIP()),
                        'session_id' => session_id(),
                        'details' => 'Password expired'
                    ]);
                }
                throw new Exception('Password expired. Please contact your administrator to reset it.', 403);
            }
        }
    }

    // 
    // Maintenance mode enforcement
    // 
    $maintenanceRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'maintenance_mode') : null;
    $maintenanceFlag = filter_var($maintenanceRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $maintenanceEnabled = ($maintenanceFlag === null) ? ($maintenanceRaw === '1') : (bool)$maintenanceFlag;

    $loginRoleEffective = function_exists('resolveRoleAccessKey')
        ? resolveRoleAccessKey($conn, (string)($user['userRole'] ?? ''))
        : (string)($user['userRole'] ?? '');
    $loginHasAdminAccess = function_exists('roleHasAdminAccess')
        ? roleHasAdminAccess($conn, (string)($user['userRole'] ?? ''))
        : in_array($loginRoleEffective, ['admin', 'super_admin'], true);
    if ($maintenanceEnabled && !$loginHasAdminAccess) {
        if (function_exists('logUserActivity')) {
            logUserActivity($conn, [
                'user_id' => $user['userId'],
                'user_name' => $user['userName'],
                'user_role' => $user['userRole'],
                'activity_type' => 'login_failed',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => session_id(),
                'details' => 'Login blocked: Maintenance mode is enabled'
            ]);
        }
        throw new Exception('The system is currently under maintenance. Please try again later.', 403);
    }

    // 
    // Login attempt throttling
    // 
    $attemptLimitRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'login_attempt_limit') : null;
    $lockoutMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'lockout_minutes') : null;
    $attemptLimit = is_numeric($attemptLimitRaw) ? (int)$attemptLimitRaw : 5;
    $lockoutMinutes = is_numeric($lockoutMinutesRaw) ? (int)$lockoutMinutesRaw : 15;

    if ($attemptLimit > 0 && $lockoutMinutes > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS fail_count
            FROM tb_user_logs
            WHERE user_id = ?
              AND activity_type = 'login_failed'
              AND created_at >= (NOW() - INTERVAL ? MINUTE)
        ");
        if ($stmt) {
            $stmt->bind_param('si', $user['userId'], $lockoutMinutes);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $failCount = (int)($row['fail_count'] ?? 0);
            if ($failCount >= $attemptLimit) {
                throw new Exception('Too many failed attempts. Please wait before trying again.', 429);
            }
        }
    }

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = getClientIP();

    // 
    // App-level session defaults
    // 
    $timeoutMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'session_timeout_minutes') : null;
    $graceMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'grace_period_minutes') : null;
    $allowMultipleRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'allow_multiple_devices') : null;

    $timeoutMinutes = is_numeric($timeoutMinutesRaw) ? (int)$timeoutMinutesRaw : 30;
    $graceMinutes = is_numeric($graceMinutesRaw) ? (int)$graceMinutesRaw : 5;
    $allowMultipleFlag = filter_var($allowMultipleRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $allowMultipleDevices = ($allowMultipleFlag === null) ? ($allowMultipleRaw === '1') : (bool)$allowMultipleFlag;

    if ($timeoutMinutes <= 0) {
        $timeoutMinutes = 30;
    }
    if ($graceMinutes < 0) {
        $graceMinutes = 5;
    }

    // 
    // Check for existing active sessions using the stable device token hash.
    // Same-device re-logins should not be treated as another device conflict.
    // 
    $deviceId = resolveClientDeviceIdentifierHash();

    $checkStmt = $conn->prepare("
        SELECT
            COUNT(*) AS active_count,
            SUM(CASE WHEN device_id = ? THEN 1 ELSE 0 END) AS same_device_count
        FROM tb_user_sessions 
        WHERE user_id = ? AND is_active = 1
    ");
    if (!$checkStmt) {
        throw new Exception('Failed to check existing sessions: ' . $conn->error);
    }
    
    $checkStmt->bind_param('ss', $deviceId, $user['userId']);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();
    
    $activeCount = (int)($checkResult['active_count'] ?? 0);
    $sameDeviceCount = (int)($checkResult['same_device_count'] ?? 0);
    $otherActiveCount = max(0, $activeCount - $sameDeviceCount);
    $hasExistingSession = $otherActiveCount > 0;
    
    if ($hasExistingSession && function_exists('logUserActivity')) {
        logUserActivity($conn, [
            'user_id' => $user['userId'],
            'user_name' => $user['userName'],
            'user_role' => $user['userRole'],
            'activity_type' => 'device_conflict_detected',
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'device_type' => detectDeviceType($userAgent),
            'location' => getLocationFromIP($ip),
            'session_id' => session_id(),
            'details' => 'Login attempted while another session is active'
        ]);
    }

    // 
    // Enforce max concurrent sessions & auto-logout behavior
    // 
    $maxConcurrentRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'max_concurrent_sessions') : null;
    $autoLogoutRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'auto_logout_on_conflict') : null;
    $maxConcurrent = is_numeric($maxConcurrentRaw) ? (int)$maxConcurrentRaw : 1;
    if ($maxConcurrent <= 0) {
        $maxConcurrent = 1;
    }
    $autoLogoutFlag = filter_var($autoLogoutRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $autoLogoutOnConflict = ($autoLogoutFlag === null) ? ($autoLogoutRaw === '1') : (bool)$autoLogoutFlag;

    if ($allowMultipleDevices && $otherActiveCount >= $maxConcurrent) {
        if ($autoLogoutOnConflict) {
            $terminateCount = ($otherActiveCount - $maxConcurrent) + 1;
            if ($terminateCount > 0 && method_exists($sessionManager, 'terminateOldestSessions')) {
                $sessionManager->terminateOldestSessions($user['userId'], $terminateCount, 'auto_logout');
            }
        } else {
            throw new Exception('Maximum active sessions reached. Please log out from another device.', 409);
        }
    }

    // 
    // Create new session via SessionManager
    // 
    $sessionInfo = $sessionManager->initializeSession(
        $user['userId'],
        $user['userName'],
        $user['userRole'],
        $deviceId,
        'web',
        [
            'allow_multiple_devices' => $allowMultipleDevices,
            'timeout' => $timeoutMinutes * 60,
            'grace_period' => $graceMinutes * 60
        ]
    );

      $effectiveRole = function_exists('resolveRoleAccessKey')
          ? resolveRoleAccessKey($conn, (string)($user['userRole'] ?? ''))
          : (string)($user['userRole'] ?? '');

    // Regenerate PHP session ID before storing auth data so shared hosts persist
    // the final session record that the browser receives.
    session_regenerate_id(true);

      // 
      // Store session data
      // 
      $_SESSION['userId']        = $user['userId'];
      $_SESSION['userName']      = $user['userName'];
      $_SESSION['userRole']      = $user['userRole'];
      $_SESSION['userRoleEffective'] = $effectiveRole;
    $_SESSION['userPhoto']     = $user['userPhoto'] ?? null;
    $_SESSION['phoneNo']       = $user['phoneNo'] ?? null;
    $_SESSION['userEmail']     = $user['userEmail'] ?? null;
    $_SESSION['session_id']    = $sessionInfo['session_id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['device_id']     = $deviceId;
    setSignedSessionCookies((string)$sessionInfo['session_id'], (string)$user['userId']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // 
    // Log successful login
    // 
    if (function_exists('logUserActivity')) {
        logUserActivity($conn, [
            'user_id' => $user['userId'],
            'user_name' => $user['userName'],
            'user_role' => $user['userRole'],
            'activity_type' => 'login',
            'ip_address' => getClientIP(),
            'user_agent' => $userAgent,
            'device_type' => detectDeviceType($userAgent),
            'location' => getLocationFromIP($ip),
            'session_id' => $sessionInfo['session_id'],
            'details' => "Successful login via " . ($isEmail ? 'email' : 'phone')
        ]);
    }

        // 
        // Prepare success response
        // 
      $response = [
          'success'              => true,
          'message'              => 'Login successful',
          'userId'               => $user['userId'],
          'userName'             => $user['userName'],
          'userRole'             => $user['userRole'],
          'userRoleEffective'    => $effectiveRole,
          'userPhoto'            => $user['userPhoto'] ?? 'images/default-user.png',
          'phoneNo'              => $user['phoneNo'] ?? '',
          'userEmail'            => $user['userEmail'] ?? '',
        'sessionTimeout'       => $sessionInfo['timeout'] ?? 1800,
        'gracePeriod'          => $sessionInfo['grace_period'] ?? 300,
        'allowMultipleDevices' => $sessionInfo['allow_multiple_devices'] ?? $allowMultipleDevices,
        'hasExistingSession'   => $hasExistingSession,
        'sessionPersisted'     => true,
        'sessionId'            => $sessionInfo['session_id'],
        'sessionLoginTime'     => $sessionInfo['login_time'] ?? date('Y-m-d H:i:s'),
        'appTimezone'          => function_exists('getConfiguredAppTimezone') ? getConfiguredAppTimezone() : date_default_timezone_get()
    ];

    // Clear output buffer and send response
    $sendJsonResponse($response, 200);

} catch (Throwable $e) {
    // Handle errors
    error_log("LOGIN ERROR: " . $e->getMessage());

    $statusCode = $e->getCode() ?: 500;
    if ($statusCode < 400 || $statusCode > 599) {
        $statusCode = 500;
    }

    $sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'errorCode' => $statusCode
    ], $statusCode);
} finally {
    // Avoid emitting buffered warnings/notices after JSON has been sent.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Close database connection if it exists
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

exit;
?>

