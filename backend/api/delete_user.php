<?php
/**
 * 
 * Delete User Api
 * 
 * Deletes user record and associated profile image
 * from ../uploads/profiles/ directory.
 * 
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$userId = $data['userId'] ?? '';

if (empty($userId)) {
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

try {
    // Fetch user photo path before deletion
    $stmt = $conn->prepare("SELECT userName, userRole, userPhoto FROM tb_users WHERE userId = ?");
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    if ($userId === (string)($_SESSION['userId'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'You cannot delete your own account']);
        exit;
    }

    $targetRole = normalizeRoleKey((string)($row['userRole'] ?? ''));
    if ($targetRole === 'super_admin') {
        echo json_encode(['success' => false, 'message' => 'The super administrator account cannot be deleted from user management']);
        exit;
    }
    if ($targetRole === 'admin' && !canCurrentSessionManageAdminAccounts($conn)) {
        echo json_encode(['success' => false, 'message' => 'Only the super administrator can delete administrator accounts']);
        exit;
    }

    // Delete associated image file
    if (!empty($row['userPhoto']) && $row['userPhoto'] !== '../uploads/profiles/default-user.png') {
        $photoPath = __DIR__ . '/../' . str_replace('../', '', $row['userPhoto']);
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
    }

    // Delete user record
    $stmt = $conn->prepare("DELETE FROM tb_users WHERE userId = ?");
    $stmt->bind_param("s", $userId);
    $stmt->execute();

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $_SESSION['userId'] ?? 'system',
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? 'system',
            'action' => 'user_deleted',
            'entity_type' => 'user',
            'entity_id' => $userId,
                'details' => [
                'target_user' => $row['userName'] ?? null,
                'target_role' => $row['userRole'] ?? null,
                'user_photo' => $row['userPhoto'] ?? null
            ]
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'User and associated image deleted successfully']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

if (isset($stmt)) $stmt->close();
$conn->close();
?>

