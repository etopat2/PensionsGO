<?php
/**
 * 
 * verify_admin_password.php
 * Purpose: Verify admin password for re-authentication
 * Access: Admin only (requires active session)
 * 
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode([
        'success' => false,
        'valid' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

// Get the posted data
$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode([
        'success' => false,
        'valid' => false,
        'message' => 'Password is required'
    ]);
    exit;
}

try {
    // Get current user's password hash from database
    $stmt = $conn->prepare("
        SELECT userPassword 
        FROM tb_users 
        WHERE userId = ?
    ");
    $stmt->bind_param("s", $_SESSION['userId']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($user = $result->fetch_assoc()) {
        $isValid = password_verify($password, $user['userPassword']);
        
        if ($isValid) {
            // Update session activity timestamp
            $_SESSION['last_activity'] = time();
            markAdminSensitiveActionVerified($conn, 'admin_password_reauth');
            
            // Log the successful re-authentication
            error_log("Admin re-authentication successful for user: " . $_SESSION['userId']);
            
            echo json_encode([
                'success' => true,
                'valid' => true,
                'message' => 'Password verified successfully',
                'verified_at' => (int)($_SESSION['admin_reauth_verified_at'] ?? time()),
                'reauth_window_seconds' => getAdminReauthWindowSeconds($conn)
            ]);
        } else {
            // Log failed attempt
            error_log("Admin re-authentication failed for user: " . $_SESSION['userId']);
            
            echo json_encode([
                'success' => true,
                'valid' => false,
                'message' => 'Invalid password'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'valid' => false,
            'message' => 'User not found'
        ]);
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Admin password verification error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'valid' => false,
        'message' => 'Error verifying password: ' . $e->getMessage()
    ]);
}

$conn->close();
?>

