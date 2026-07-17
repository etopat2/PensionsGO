<?php
/**
 * SessionManager.php - Complete Fixed Version
 * 
 * FIXED: All bind_param() parameter mismatches
 */

declare(strict_types=1);

class SessionManager
{
    private static ?SessionManager $instance = null;
    private mysqli $conn;

    private int $defaultTimeout = 1800; // 30 min
    private int $defaultGrace   = 300;  // 5 min

    private function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        $this->ensureTablesExist();
    }

    public static function getInstance(mysqli $conn): SessionManager
    {
        if (!self::$instance) {
            self::$instance = new SessionManager($conn);
        } else {
            self::$instance->conn = $conn;
        }
        return self::$instance;
    }

    /**
     * Ensure required tables exist
     */
    private function ensureTablesExist(): void
    {
        // Check if sessions table exists
        $result = $this->conn->query("SHOW TABLES LIKE 'tb_user_sessions'");
        if ($result && $result->num_rows === 0) {
            // Create table
            $this->conn->query("
                CREATE TABLE IF NOT EXISTS `tb_user_sessions` (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `session_id` varchar(128) NOT NULL,
                    `user_id` varchar(100) NOT NULL,
                    `device_id` varchar(64) NOT NULL,
                    `session_type` enum('web','mobile','api') DEFAULT 'web',
                    `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
                    `last_activity` timestamp NOT NULL DEFAULT current_timestamp(),
                    `grace_period_until` timestamp NULL DEFAULT NULL,
                    `is_active` tinyint(1) DEFAULT 1,
                    `termination_reason` varchar(50) DEFAULT NULL,
                    `user_agent` text DEFAULT NULL,
                    `ip_address` varchar(45) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_session_id` (`session_id`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
            ");
        }
        if ($result) {
            $result->free();
        }

        $this->ensureSessionTableSchema();
    }

    private function sessionColumnExists(string $column): bool
    {
        $stmt = $this->conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_user_sessions'
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $column);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    private function sessionKeyExists(string $keyName): bool
    {
        $stmt = $this->conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tb_user_sessions'
              AND INDEX_NAME = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $keyName);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    private function applySessionSchemaStatement(string $sql): void
    {
        if (!$this->conn->query($sql)) {
            error_log('Session schema repair skipped: ' . $this->conn->error . ' SQL: ' . $sql);
        }
    }

    private function ensureSessionTableSchema(): void
    {
        $columns = [
            'id' => "`id` int(11) NOT NULL AUTO_INCREMENT",
            'session_id' => "`session_id` varchar(128) NOT NULL",
            'user_id' => "`user_id` varchar(100) NOT NULL",
            'device_id' => "`device_id` varchar(64) NOT NULL",
            'session_type' => "`session_type` enum('web','mobile','api') DEFAULT 'web'",
            'login_time' => "`login_time` timestamp NOT NULL DEFAULT current_timestamp()",
            'last_activity' => "`last_activity` timestamp NOT NULL DEFAULT current_timestamp()",
            'grace_period_until' => "`grace_period_until` timestamp NULL DEFAULT NULL",
            'is_active' => "`is_active` tinyint(1) DEFAULT 1",
            'termination_reason' => "`termination_reason` varchar(50) DEFAULT NULL",
            'user_agent' => "`user_agent` text DEFAULT NULL",
            'ip_address' => "`ip_address` varchar(45) DEFAULT NULL"
        ];

        foreach ($columns as $column => $definition) {
            if (!$this->sessionColumnExists($column)) {
                $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` ADD COLUMN {$definition}");
            }
        }

        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `session_id` varchar(128) NOT NULL");
        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `user_id` varchar(100) NOT NULL");
        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `device_id` varchar(64) NOT NULL");
        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `session_type` enum('web','mobile','api') DEFAULT 'web'");
        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `last_activity` timestamp NOT NULL DEFAULT current_timestamp()");
        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `is_active` tinyint(1) DEFAULT 1");

        if (!$this->sessionKeyExists('PRIMARY')) {
            $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` ADD PRIMARY KEY (`id`)");
        }
        $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` MODIFY `id` int(11) NOT NULL AUTO_INCREMENT");
        if (!$this->sessionKeyExists('unique_session_id')) {
            $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` ADD UNIQUE KEY `unique_session_id` (`session_id`)");
        }
        if (!$this->sessionKeyExists('idx_user_active')) {
            $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` ADD KEY `idx_user_active` (`user_id`, `is_active`)");
        }
        if (!$this->sessionKeyExists('idx_session_device')) {
            $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` ADD KEY `idx_session_device` (`session_id`, `device_id`)");
        }
        if (!$this->sessionKeyExists('idx_last_activity')) {
            $this->applySessionSchemaStatement("ALTER TABLE `tb_user_sessions` ADD KEY `idx_last_activity` (`last_activity`)");
        }
    }

    /**
     * Initialize a new session (SIMPLIFIED WORKING VERSION)
     */
    public function initializeSession(
        string $userId,
        string $userName,
        string $userRole,
        string $deviceId,
        string $platform = 'web',
        array $options = []
    ): array
    {
        // Generate session ID
        $sessionId = bin2hex(random_bytes(32));
        
        // Get user agent and IP
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ipAddress = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        
        $allowMultipleDevices = (bool)($options['allow_multiple_devices'] ?? false);
        $timeout = (int)($options['timeout'] ?? $this->defaultTimeout);
        $gracePeriod = (int)($options['grace_period'] ?? $this->defaultGrace);
        
        if ($timeout <= 0) {
            $timeout = $this->defaultTimeout;
        }
        
        if ($gracePeriod < 0) {
            $gracePeriod = $this->defaultGrace;
        }
        
        $nowString = $this->currentAppDateTimeString();
        $graceUntil = $this->currentAppDateTimeString(time() + $gracePeriod);
        
        // Terminate existing sessions if multiple devices not allowed
        if (!$allowMultipleDevices) {
            $this->terminateUserSessions($userId, 'device_conflict');
        }
        
        // Insert new session - SIMPLE VERSION
        $stmt = $this->conn->prepare("
            INSERT INTO tb_user_sessions
            (session_id, user_id, device_id, user_agent, ip_address, session_type, login_time, last_activity, grace_period_until, is_active, termination_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL)
        ");
        
        if (!$stmt) {
            error_log("SessionManager prepare failed: " . $this->conn->error);
            throw new RuntimeException("Prepare failed: " . $this->conn->error);
        }
        
        $sessionType = in_array($platform, ['web', 'mobile', 'api'], true) ? $platform : 'web';
        
        $stmt->bind_param(
            "sssssssss",
            $sessionId,
            $userId,
            $deviceId,
            $userAgent,
            $ipAddress,
            $sessionType,
            $nowString,
            $nowString,
            $graceUntil
        );
        
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            error_log("SessionManager execute failed: " . $error);
            throw new RuntimeException("Execute failed: " . $error);
        }
        
        $stmt->close();

        if (!$this->confirmSessionStored($sessionId, $userId, $deviceId)) {
            error_log("SessionManager insert verification failed for session {$sessionId} and user {$userId}");
            throw new RuntimeException("Session was not persisted after login.");
        }
        
        // Log session start - USE SIMPLIFIED LOGGING
        $this->logSessionStartSimple($userId, $userName, $userRole, $sessionId);
        
        return [
            'session_id' => $sessionId,
            'timeout' => $timeout,
            'grace_period' => $gracePeriod,
            'allow_multiple_devices' => $allowMultipleDevices,
            'login_time' => $nowString
        ];
    }

    private function currentAppDateTimeString(?int $timestamp = null): string
    {
        $timezoneName = function_exists('getConfiguredAppTimezone') ? getConfiguredAppTimezone() : date_default_timezone_get();
        try {
            $timezone = new DateTimeZone($timezoneName ?: 'Africa/Kampala');
        } catch (Throwable $error) {
            $timezone = new DateTimeZone('Africa/Kampala');
        }

        $dateTime = $timestamp === null
            ? new DateTimeImmutable('now', $timezone)
            : (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        return $dateTime->format('Y-m-d H:i:s');
    }

    public function confirmSessionStored(string $sessionId, string $userId, ?string $deviceId = null): bool
    {
        $hasDeviceId = $deviceId !== null && $deviceId !== '';
        $sql = "
            SELECT session_id
            FROM tb_user_sessions
            WHERE session_id = ?
              AND user_id = ?
              AND is_active = 1
        ";
        if ($hasDeviceId) {
            $sql .= " AND device_id = ?";
        }
        $sql .= " LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("SessionManager verification prepare failed: " . $this->conn->error);
            return false;
        }

        if ($hasDeviceId) {
            $stmt->bind_param('sss', $sessionId, $userId, $deviceId);
        } else {
            $stmt->bind_param('ss', $sessionId, $userId);
        }
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    }

    /**
     * Simplified log session start
     */
    private function logSessionStartSimple(string $userId, string $userName, string $userRole, string $sessionId): void
    {
        if (function_exists('getAppSetting')) {
            $enabledRaw = getAppSetting($this->conn, 'enable_activity_logs');
            $enabledFlag = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $enabled = ($enabledFlag === null) ? ($enabledRaw === '1') : (bool)$enabledFlag;
            if (!$enabled) {
                return;
            }
        }

        // Use direct query to avoid bind_param reference issues in some PHP builds
        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = $this->conn->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? '');
        $deviceType = $this->detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? '');
        $location = $this->getLocationFromIP($ip);

        $sql = "INSERT INTO tb_user_logs 
                (user_id, user_name, user_role, activity_type, ip_address, 
                 user_agent, device_type, location, session_id, details)
                VALUES (
                    '" . $this->conn->real_escape_string($userId) . "',
                    '" . $this->conn->real_escape_string($userName) . "',
                    '" . $this->conn->real_escape_string($userRole) . "',
                    'session_started',
                    '" . $this->conn->real_escape_string($ip) . "',
                    '" . $userAgent . "',
                    '" . $this->conn->real_escape_string($deviceType) . "',
                    '" . $this->conn->real_escape_string($location) . "',
                    '" . $this->conn->real_escape_string($sessionId) . "',
                    'Session Started'
                )";

        $this->conn->query($sql);
    }

    /**
     * Alternative: Direct query without bind_param
     */
    private function logSessionStartDirect(string $userId, string $userName, string $userRole, string $sessionId): void
    {
        if (function_exists('getAppSetting')) {
            $enabledRaw = getAppSetting($this->conn, 'enable_activity_logs');
            $enabledFlag = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $enabled = ($enabledFlag === null) ? ($enabledRaw === '1') : (bool)$enabledFlag;
            if (!$enabled) {
                return;
            }
        }

        $ip = function_exists('getClientIP') ? getClientIP() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $userAgent = $this->conn->real_escape_string($_SERVER['HTTP_USER_AGENT'] ?? '');
        $deviceType = $this->detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? '');
        $location = $this->getLocationFromIP($ip);
        
        // Use direct query to avoid bind_param issues
        $sql = "INSERT INTO tb_user_logs 
                (user_id, user_name, user_role, activity_type, ip_address, 
                 user_agent, device_type, location, session_id, details)
                VALUES (
                    '" . $this->conn->real_escape_string($userId) . "',
                    '" . $this->conn->real_escape_string($userName) . "',
                    '" . $this->conn->real_escape_string($userRole) . "',
                    'session_started',
                    '" . $this->conn->real_escape_string($ip) . "',
                    '" . $userAgent . "',
                    '" . $this->conn->real_escape_string($deviceType) . "',
                    '" . $this->conn->real_escape_string($location) . "',
                    '" . $this->conn->real_escape_string($sessionId) . "',
                    'Session Started'
                )";
        
        $this->conn->query($sql);
    }

    /**
     * Validate session
     */
    public function validateSession(string $sessionId, string $userId): array
    {
        $stmt = $this->conn->prepare("
            SELECT session_id, user_id, last_activity, is_active, termination_reason
            FROM tb_user_sessions
            WHERE session_id = ? AND user_id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }

        $stmt->bind_param('ss', $sessionId, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return [
                'active'       => false,
                'expired'      => true,
                'in_grace'     => false,
                'seconds_left' => 0,
                'reason'       => 'not_found',
                'message'      => 'Session not found. Please login again.'
            ];
        }

        if ((int)$row['is_active'] !== 1) {
            $mapped = $this->mapTerminationReason($row['termination_reason'] ?? null);
            return [
                'active'       => false,
                'expired'      => $mapped['expired'],
                'in_grace'     => false,
                'seconds_left' => 0,
                'reason'       => $mapped['reason'],
                'message'      => $mapped['message']
            ];
        }

        $lastActivity = $this->parseAppTimestamp((string)$row['last_activity']);
        $now = time();
        $timeoutSeconds = $this->getConfiguredTimeoutSeconds();
        $expired = ($now - $lastActivity) > $timeoutSeconds;

        if ($expired && (int)$row['is_active'] === 1) {
            $updateStmt = $this->conn->prepare("
                UPDATE tb_user_sessions
                SET is_active = 0,
                    termination_reason = 'timeout'
                WHERE session_id = ? AND user_id = ? AND is_active = 1
            ");
            if ($updateStmt) {
                $updateStmt->bind_param('ss', $sessionId, $userId);
                $updateStmt->execute();
                $updateStmt->close();
            }

            if (function_exists('logSessionEnd')) {
                logSessionEnd($this->conn, $userId, $sessionId, 'session_expiry', 'Session expired due to inactivity');
            } elseif (function_exists('logUserActivity')) {
                logUserActivity($this->conn, [
                    'user_id' => $userId,
                    'user_name' => $_SESSION['userName'] ?? 'Unknown User',
                    'user_role' => $_SESSION['userRole'] ?? 'guest',
                    'activity_type' => 'session_expiry',
                    'ip_address' => getClientIP(),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'location' => getLocationFromIP(getClientIP()),
                    'session_id' => $sessionId,
                    'details' => 'Session expired due to inactivity'
                ]);
            }
        }

        return [
            'active'          => !$expired,
            'expired'         => $expired,
            'in_grace'        => false,
            'last_activity'   => $lastActivity,
            'seconds_left'    => max(0, ($lastActivity + $timeoutSeconds) - $now),
            'reason'          => $expired ? 'timeout' : null,
            'message'         => $expired ? 'Session expired due to inactivity.' : null
        ];
    }

    private function getConfiguredTimeoutSeconds(): int
    {
        $timeoutMinutesRaw = function_exists('getAppSetting') ? getAppSetting($this->conn, 'session_timeout_minutes') : null;
        $timeoutSeconds = is_numeric($timeoutMinutesRaw) ? (int)$timeoutMinutesRaw * 60 : $this->defaultTimeout;
        return $timeoutSeconds > 0 ? $timeoutSeconds : $this->defaultTimeout;
    }

    private function parseAppTimestamp(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $timezoneName = function_exists('getConfiguredAppTimezone') ? getConfiguredAppTimezone() : date_default_timezone_get();
        try {
            $timezone = new DateTimeZone($timezoneName ?: 'Africa/Kampala');
        } catch (Throwable $error) {
            $timezone = new DateTimeZone('Africa/Kampala');
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
        if ($parsed instanceof DateTimeImmutable) {
            return $parsed->getTimestamp();
        }

        $fallback = strtotime($value);
        return $fallback === false ? 0 : $fallback;
    }

    /**
     * Normalize termination reason for consistent frontend messaging
     */
    private function mapTerminationReason(?string $reason): array
    {
        $reason = strtolower(trim((string)$reason));

        $deviceConflictReasons = [
            'device_conflict',
            'user_confirmed_device_conflict',
            'device_conflict_detected'
        ];

        if (in_array($reason, $deviceConflictReasons, true)) {
            return [
                'reason' => 'device_conflict',
                'message' => 'Your account was logged in from another device. For security, this session has been terminated.',
                'expired' => false
            ];
        }

        $timeoutReasons = ['timeout', 'session_expiry', 'auto_cleanup', 'expired'];
        if (in_array($reason, $timeoutReasons, true)) {
            return [
                'reason' => 'timeout',
                'message' => 'Session expired due to inactivity.',
                'expired' => true
            ];
        }

        $logoutReasons = ['user_initiated', 'logout', 'user_logout'];
        if (in_array($reason, $logoutReasons, true)) {
            return [
                'reason' => 'logged_out',
                'message' => 'You have been logged out.',
                'expired' => false
            ];
        }

        if ($reason === '') {
            return [
                'reason' => 'terminated',
                'message' => 'Session ended. Please login again.',
                'expired' => false
            ];
        }

        return [
            'reason' => $reason,
            'message' => 'Session ended. Please login again.',
            'expired' => false
        ];
    }

    /**
     * Touch session - update last activity
     */
    public function touchSession(string $sessionId, ?string $deviceId = null): bool
    {
        $hasDeviceId = $deviceId !== null && $deviceId !== '';

        if ($hasDeviceId) {
            $stmt = $this->conn->prepare("
                UPDATE tb_user_sessions
                SET last_activity = ?
                WHERE session_id = ? AND is_active = 1 AND device_id = ?
            ");
        } else {
            $stmt = $this->conn->prepare("
                UPDATE tb_user_sessions
                SET last_activity = ?
                WHERE session_id = ? AND is_active = 1
            ");
        }

        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }

        $nowString = $this->currentAppDateTimeString();
        if ($hasDeviceId) {
            $stmt->bind_param("sss", $nowString, $sessionId, $deviceId);
        } else {
            $stmt->bind_param("ss", $nowString, $sessionId);
        }
        $stmt->execute();
        $updated = $stmt->affected_rows > 0;
        $stmt->close();

        if ($updated) {
            return true;
        }

        if ($hasDeviceId) {
            $matchStmt = $this->conn->prepare("
                SELECT 1
                FROM tb_user_sessions
                WHERE session_id = ? AND is_active = 1 AND device_id = ?
                LIMIT 1
            ");
        } else {
            $matchStmt = $this->conn->prepare("
                SELECT 1
                FROM tb_user_sessions
                WHERE session_id = ? AND is_active = 1
                LIMIT 1
            ");
        }

        if (!$matchStmt) {
            throw new RuntimeException($this->conn->error);
        }

        if ($hasDeviceId) {
            $matchStmt->bind_param("ss", $sessionId, $deviceId);
        } else {
            $matchStmt->bind_param("s", $sessionId);
        }
        $matchStmt->execute();
        $matched = (bool)$matchStmt->get_result()->fetch_row();
        $matchStmt->close();

        return $matched;
    }

    /**
     * Terminate a session
     */
    public function terminateSession(string $sessionId, string $userId, string $reason = 'user_initiated'): void
    {
        $stmt = $this->conn->prepare("
            UPDATE tb_user_sessions 
            SET is_active = 0, 
                termination_reason = ?
            WHERE session_id = ? AND user_id = ? AND is_active = 1
        ");
        
        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }
        
        $stmt->bind_param("sss", $reason, $sessionId, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Terminate all sessions for a user
     */
    private function terminateUserSessions(string $userId, string $reason): void
    {
        $stmt = $this->conn->prepare("
            UPDATE tb_user_sessions 
            SET is_active = 0, 
                termination_reason = ?
            WHERE user_id = ? AND is_active = 1
        ");
        
        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }
        
        $stmt->bind_param("ss", $reason, $userId);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        if ($affectedRows > 0 && function_exists('logUserActivity')) {
            $userInfo = $this->getUserInfoForLog($userId);
            logUserActivity($this->conn, [
                'user_id' => $userId,
                'user_name' => $userInfo['user_name'],
                'user_role' => $userInfo['user_role'],
                'activity_type' => 'multiple_sessions_terminated',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => $_SESSION['session_id'] ?? session_id(),
                'details' => "Terminated {$affectedRows} active session(s). Reason: {$reason}"
            ]);
        }
    }

    /**
     * Terminate the oldest active sessions for a user to make room.
     */
    public function terminateOldestSessions(string $userId, int $count, string $reason = 'auto_logout'): int
    {
        if ($count <= 0) {
            return 0;
        }

        $stmt = $this->conn->prepare("
            SELECT session_id
            FROM tb_user_sessions
            WHERE user_id = ? AND is_active = 1
            ORDER BY last_activity ASC
            LIMIT ?
        ");
        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }
        $stmt->bind_param("si", $userId, $count);
        $stmt->execute();
        $result = $stmt->get_result();
        $sessionIds = [];
        while ($row = $result->fetch_assoc()) {
            $sessionIds[] = $row['session_id'];
        }
        $stmt->close();

        if (empty($sessionIds)) {
            return 0;
        }

        $updateStmt = $this->conn->prepare("
            UPDATE tb_user_sessions
            SET is_active = 0,
                termination_reason = ?
            WHERE session_id = ? AND user_id = ? AND is_active = 1
        ");
        if (!$updateStmt) {
            throw new RuntimeException($this->conn->error);
        }

        $terminated = 0;
        foreach ($sessionIds as $sessionId) {
            $updateStmt->bind_param("sss", $reason, $sessionId, $userId);
            $updateStmt->execute();
            if ($updateStmt->affected_rows > 0) {
                $terminated++;
            }
        }
        $updateStmt->close();

        if ($terminated > 0 && function_exists('logUserActivity')) {
            $userInfo = $this->getUserInfoForLog($userId);
            logUserActivity($this->conn, [
                'user_id' => $userId,
                'user_name' => $userInfo['user_name'],
                'user_role' => $userInfo['user_role'],
                'activity_type' => 'auto_logout',
                'ip_address' => getClientIP(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                'device_type' => detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? ''),
                'location' => getLocationFromIP(getClientIP()),
                'session_id' => $_SESSION['session_id'] ?? session_id(),
                'details' => "Auto-logged out {$terminated} session(s) to enforce max concurrent sessions."
            ]);
        }

        return $terminated;
    }

    /**
     * Terminate all other sessions except current one
     */
    public function terminateAllOtherSessions(string $userId, string $currentSessionId, string $reason): int
    {
        $stmt = $this->conn->prepare("
            UPDATE tb_user_sessions 
            SET is_active = 0, 
                termination_reason = ?
            WHERE user_id = ? 
              AND session_id != ? 
              AND is_active = 1
        ");
        
        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }
        
        $stmt->bind_param("sss", $reason, $userId, $currentSessionId);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        return $affectedRows;
    }

    /**
     * Helper: Detect device type
     */
    private function detectDeviceType(string $userAgent): string
    {
        $ua = strtolower($userAgent);
        
        if (strpos($ua, 'mobile') !== false) return 'Mobile';
        if (strpos($ua, 'android') !== false) return 'Android';
        if (strpos($ua, 'iphone') !== false) return 'iPhone';
        if (strpos($ua, 'ipad') !== false) return 'iPad';
        if (strpos($ua, 'windows') !== false) return 'Windows PC';
        if (strpos($ua, 'macintosh') !== false) return 'Mac';
        if (strpos($ua, 'linux') !== false) return 'Linux';
        
        return 'Unknown';
    }

    /**
     * Helper: Get user info for logging
     */
    private function getUserInfoForLog(string $userId): array
    {
        if (isset($_SESSION['userId']) && $_SESSION['userId'] === $userId) {
            return [
                'user_name' => $_SESSION['userName'] ?? 'Unknown User',
                'user_role' => $_SESSION['userRole'] ?? 'guest'
            ];
        }

        $stmt = $this->conn->prepare("
            SELECT userName, userRole
            FROM tb_users
            WHERE userId = ?
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $stmt->close();
                return [
                    'user_name' => $row['userName'] ?? 'Unknown User',
                    'user_role' => $row['userRole'] ?? 'guest'
                ];
            }
            $stmt->close();
        }

        return [
            'user_name' => 'Unknown User',
            'user_role' => 'guest'
        ];
    }

    /**
     * Helper: Get location from IP
     */
    private function getLocationFromIP(string $ip): string
    {
        if (function_exists('getLocationFromIP')) {
            return getLocationFromIP($ip);
        }

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return 'Local Development Environment';
        }

        return 'Unknown Location';
    }

    /**
     * Cleanup expired sessions
     */
    public function cleanupExpiredSessions(): int
    {
        $timeoutMinutesRaw = function_exists('getAppSetting') ? getAppSetting($this->conn, 'session_timeout_minutes') : null;
        $graceMinutesRaw = function_exists('getAppSetting') ? getAppSetting($this->conn, 'grace_period_minutes') : null;

        $timeoutSeconds = is_numeric($timeoutMinutesRaw) ? (int)$timeoutMinutesRaw * 60 : $this->defaultTimeout;
        $graceSeconds = is_numeric($graceMinutesRaw) ? (int)$graceMinutesRaw * 60 : $this->defaultGrace;
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = $this->defaultTimeout;
        }
        if ($graceSeconds < 0) {
            $graceSeconds = 0;
        }

        $maxAgeSeconds = $timeoutSeconds + $graceSeconds;
        $stmt = $this->conn->prepare("
            UPDATE tb_user_sessions 
            SET is_active = 0, 
                termination_reason = 'auto_cleanup'
            WHERE is_active = 1 
              AND TIMESTAMPDIFF(SECOND, last_activity, NOW()) > ?
        ");
        
        if (!$stmt) {
            throw new RuntimeException($this->conn->error);
        }
        $stmt->bind_param("i", $maxAgeSeconds);
        $stmt->execute();
        $affectedRows = $stmt->affected_rows;
        $stmt->close();
        
        return $affectedRows;
    }

    public function cleanupExpiredSessionsThrottled(int $intervalSeconds = 60): int
    {
        $safeInterval = max(15, min(3600, $intervalSeconds));
        $markerPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pensionsgo_session_cleanup_at.txt';
        $now = time();

        $lastRun = 0;
        $rawLastRun = @file_get_contents($markerPath);
        if ($rawLastRun !== false && is_numeric(trim($rawLastRun))) {
            $lastRun = (int)trim($rawLastRun);
        }

        if ($lastRun > 0 && ($now - $lastRun) < $safeInterval) {
            return 0;
        }

        $lockPath = $markerPath . '.lock';
        $lockHandle = @fopen($lockPath, 'c');
        if (!$lockHandle) {
            return $this->cleanupExpiredSessions();
        }

        try {
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return 0;
            }

            $rawLastRun = @file_get_contents($markerPath);
            if ($rawLastRun !== false && is_numeric(trim($rawLastRun))) {
                $lastRun = (int)trim($rawLastRun);
            }

            if ($lastRun > 0 && ($now - $lastRun) < $safeInterval) {
                flock($lockHandle, LOCK_UN);
                return 0;
            }

            @file_put_contents($markerPath, (string)$now, LOCK_EX);
            $affectedRows = $this->cleanupExpiredSessions();
            flock($lockHandle, LOCK_UN);
            return $affectedRows;
        } finally {
            fclose($lockHandle);
        }
    }
}

