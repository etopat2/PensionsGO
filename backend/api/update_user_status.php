<?php
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
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

if (function_exists('ensureUserActiveColumn')) {
    ensureUserActiveColumn($conn);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$userId = trim((string)($payload['userId'] ?? ''));
if (!array_key_exists('is_active', $payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID and active status are required']);
    exit;
}

$isActiveRaw = $payload['is_active'];
$isActive = filter_var($isActiveRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($userId === '' || $isActive === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID and active status are required']);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT userId, userName, userRole, is_active FROM tb_users WHERE userId = ? LIMIT 1");
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    if ($userId === (string)($_SESSION['userId'] ?? '') && !$isActive) {
        echo json_encode(['success' => false, 'message' => 'You cannot deactivate your own account']);
        exit;
    }

    $targetRole = normalizeRoleKey((string)($target['userRole'] ?? ''));
    if (isPrivilegedAdminAccountRole($targetRole) && !canCurrentSessionManageAdminAccounts($conn)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only the super administrator can activate or deactivate administrator accounts']);
        exit;
    }

    $newValue = $isActive ? 1 : 0;
    $oldValue = (int)($target['is_active'] ?? 1);

    $update = $conn->prepare("UPDATE tb_users SET is_active = ? WHERE userId = ?");
    $update->bind_param('is', $newValue, $userId);
    $success = $update->execute();
    $update->close();

    if (!$success) {
        throw new RuntimeException('Unable to update account status');
    }

    if (!$isActive) {
        $sessionStmt = $conn->prepare("
            UPDATE tb_user_sessions
            SET is_active = 0, termination_reason = 'account_deactivated'
            WHERE user_id = ? AND is_active = 1
        ");
        if ($sessionStmt) {
            $sessionStmt->bind_param('s', $userId);
            $sessionStmt->execute();
            $sessionStmt->close();
        }
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $_SESSION['userId'] ?? 'system',
            'actor_name' => $_SESSION['userName'] ?? 'System',
            'actor_role' => $_SESSION['userRole'] ?? 'system',
            'action' => $isActive ? 'user_activated' : 'user_deactivated',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'details' => [
                'target_user' => $target['userName'] ?? null,
                'target_role' => $target['userRole'] ?? null,
                'from_status' => $oldValue === 1 ? 'active' : 'inactive',
                'to_status' => $newValue === 1 ? 'active' : 'inactive'
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => $isActive ? 'User account activated successfully' : 'User account deactivated successfully',
        'user' => [
            'userId' => $userId,
            'is_active' => $newValue === 1
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Unable to update user status']);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>
