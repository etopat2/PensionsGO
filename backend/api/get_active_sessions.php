<?php
/**
 * get_active_sessions.php
 * Purpose: Return active session counts from tb_user_sessions
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Administrator access is required.'
        ]);
        exit;
    }

    $activeSessions = 0;
    $activeUsers = 0;
    $sessions = [];

    $result = $conn->query("
        SELECT COUNT(*) as active_sessions,
               COUNT(DISTINCT user_id) as active_users
        FROM tb_user_sessions
        WHERE is_active = 1
    ");
    if (!$result) {
        throw new RuntimeException($conn->error);
    }

    $row = $result->fetch_assoc() ?: [];
    $activeSessions = (int)($row['active_sessions'] ?? 0);
    $activeUsers = (int)($row['active_users'] ?? 0);

    $listResult = $conn->query("
        SELECT
            s.id,
            s.session_id,
            s.user_id,
            s.device_id,
            s.session_type,
            s.login_time,
            s.last_activity,
            s.grace_period_until,
            s.ip_address,
            s.user_agent,
            geo.location_label,
            geo.city AS location_city,
            geo.region AS location_region,
            geo.country AS location_country,
            u.userName,
            u.userEmail,
            u.phoneNo,
            u.userRole,
            TIMESTAMPDIFF(SECOND, s.login_time, NOW()) AS session_age_seconds,
            TIMESTAMPDIFF(SECOND, s.last_activity, NOW()) AS idle_seconds
        FROM tb_user_sessions s
        LEFT JOIN tb_users u ON u.userId = s.user_id
        LEFT JOIN tb_ip_geolocation geo ON geo.ip_address COLLATE utf8mb4_unicode_ci = s.ip_address COLLATE utf8mb4_unicode_ci
        WHERE s.is_active = 1
        ORDER BY s.last_activity DESC, s.login_time DESC
        LIMIT 500
    ");

    if (!$listResult) {
        throw new RuntimeException($conn->error);
    }

    $currentSessionId = (string)($_SESSION['session_id'] ?? session_id());
    while ($session = $listResult->fetch_assoc()) {
        $userAgent = (string)($session['user_agent'] ?? '');
        $ipAddress = (string)($session['ip_address'] ?? '');
        $deviceType = function_exists('detectDeviceType') ? detectDeviceType($userAgent) : 'Unknown';
        $locationLabel = trim((string)($session['location_label'] ?? ''));
        if ($locationLabel === '' && $ipAddress !== '' && function_exists('getLocationFromIP')) {
            $locationLabel = getLocationFromIP($ipAddress);
        }

        $sessions[] = [
            'id' => (int)($session['id'] ?? 0),
            'session_id' => (string)($session['session_id'] ?? ''),
            'session_token_tail' => substr((string)($session['session_id'] ?? ''), -8),
            'user_id' => (string)($session['user_id'] ?? ''),
            'user_name' => (string)($session['userName'] ?? 'Unknown User'),
            'user_email' => (string)($session['userEmail'] ?? ''),
            'phone_no' => (string)($session['phoneNo'] ?? ''),
            'user_role' => (string)($session['userRole'] ?? 'user'),
            'session_type' => (string)($session['session_type'] ?? 'web'),
            'device_id_tail' => substr((string)($session['device_id'] ?? ''), -10),
            'device_type' => $deviceType,
            'ip_address' => $ipAddress,
            'physical_location' => $locationLabel !== '' ? $locationLabel : 'Unknown Location',
            'location_city' => (string)($session['location_city'] ?? ''),
            'location_region' => (string)($session['location_region'] ?? ''),
            'location_country' => (string)($session['location_country'] ?? ''),
            'user_agent' => $userAgent,
            'login_time' => (string)($session['login_time'] ?? ''),
            'last_activity' => (string)($session['last_activity'] ?? ''),
            'grace_period_until' => (string)($session['grace_period_until'] ?? ''),
            'session_age_seconds' => max(0, (int)($session['session_age_seconds'] ?? 0)),
            'idle_seconds' => max(0, (int)($session['idle_seconds'] ?? 0)),
            'is_current_session' => hash_equals($currentSessionId, (string)($session['session_id'] ?? ''))
        ];
    }

    echo json_encode([
        'success' => true,
        'active_sessions' => $activeSessions,
        'active_users' => $activeUsers,
        'generated_at' => date('Y-m-d H:i:s'),
        'sessions' => $sessions
    ]);
} catch (Throwable $e) {
    error_log("get_active_sessions error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load active sessions'
    ]);
}

if (isset($conn)) {
    $conn->close();
}

?>
