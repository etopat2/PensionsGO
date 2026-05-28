<?php
/**
 * 
 * verify_admin.php
 * Purpose: Verify if current user has admin privileges
 * 
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !isset($_SESSION['userRole'])) {
    echo json_encode([
        'success' => false,
        'is_admin' => false,
        'message' => 'Not logged in'
    ]);
    exit;
}

$effectiveRole = getSessionEffectiveRoleKey($conn);
$rawRole = normalizeRoleKey((string)($_SESSION['userRole'] ?? ''));
$isAdmin = sessionRoleIn($conn, ['admin']);

echo json_encode([
    'success' => true,
    'is_admin' => $isAdmin,
    'is_super_admin' => $rawRole === 'super_admin',
    'user_id' => $_SESSION['userId'],
    'user_role' => $_SESSION['userRole'],
    'user_role_effective' => $effectiveRole,
    'admin_reauth_verified' => $isAdmin ? hasRecentAdminSensitiveVerification($conn) : false,
    'admin_reauth_verified_at' => (int)($_SESSION['admin_reauth_verified_at'] ?? 0),
    'admin_reauth_window_seconds' => $isAdmin ? getAdminReauthWindowSeconds($conn) : 0
]);
?>

