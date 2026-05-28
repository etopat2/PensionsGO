<?php
/**
 * get_session_settings.php - Returns user's session settings
 * SIMPLIFIED VERSION for immediate use
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';

$timeoutMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'session_timeout_minutes') : null;
$graceMinutesRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'grace_period_minutes') : null;
$idleWarningRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'session_idle_warning_minutes') : null;
$maxConcurrentRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'max_concurrent_sessions') : null;
$allowMultipleRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'allow_multiple_devices') : null;
$autoLogoutRaw = function_exists('getAppSetting') ? getAppSetting($conn, 'auto_logout_on_conflict') : null;

$timeoutMinutes = is_numeric($timeoutMinutesRaw) ? (int)$timeoutMinutesRaw : 30;
$graceMinutes = is_numeric($graceMinutesRaw) ? (int)$graceMinutesRaw : 5;
$idleWarningMinutes = is_numeric($idleWarningRaw) ? (int)$idleWarningRaw : 5;
$maxConcurrent = is_numeric($maxConcurrentRaw) ? (int)$maxConcurrentRaw : 1;

$allowMultipleFlag = filter_var($allowMultipleRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$allowMultiple = ($allowMultipleFlag === null) ? ($allowMultipleRaw === '1') : (bool)$allowMultipleFlag;
$autoLogoutFlag = filter_var($autoLogoutRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
$autoLogout = ($autoLogoutFlag === null) ? ($autoLogoutRaw === '1') : (bool)$autoLogoutFlag;

// Simple response - return defaults if table doesn't exist yet
$defaultSettings = [
    'max_concurrent_sessions' => max(1, $maxConcurrent),
    'session_timeout' => max(300, $timeoutMinutes * 60),
    'allow_multiple_devices' => $allowMultiple,
    'auto_logout_on_conflict' => $autoLogout,
    'inactivity_warning_minutes' => max(1, $idleWarningMinutes),
    'grace_period_minutes' => max(1, $graceMinutes)
];

echo json_encode([
    'success' => true,
    'settings' => $defaultSettings
]);
?>
