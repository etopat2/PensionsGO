<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit;
}

$currentUserId = (string)($_SESSION['userId'] ?? '');
$currentRole = getSessionEffectiveRoleKey($conn);
if (!sessionRoleIn($conn, ['admin'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

ensureUserPermissionsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload'
    ]);
    exit;
}

$targetUserId = trim((string)($payload['user_id'] ?? ''));
if ($targetUserId === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Target user is required'
    ]);
    exit;
}

$targetStmt = $conn->prepare("
    SELECT userId, userRole, userName
    FROM tb_users
    WHERE userId = ?
    LIMIT 1
");
if (!$targetStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to validate target user'
    ]);
    exit;
}
$targetStmt->bind_param("s", $targetUserId);
$targetStmt->execute();
$targetResult = $targetStmt->get_result();
$targetUser = $targetResult ? $targetResult->fetch_assoc() : null;
$targetStmt->close();

if (!$targetUser) {
    echo json_encode([
        'success' => false,
        'message' => 'Target user not found'
    ]);
    exit;
}

$permissionsPayload = $payload['permissions'] ?? null;
if (!is_array($permissionsPayload)) {
    echo json_encode([
        'success' => false,
        'message' => 'Permissions payload must be an object'
    ]);
    exit;
}

$catalog = getPermissionCatalog();
$allowedModes = ['default', 'allow', 'deny'];
$updatedCount = 0;
$changeNotes = [];

$conn->begin_transaction();
try {
    foreach ($permissionsPayload as $permissionKey => $modeData) {
        if (!isset($catalog[$permissionKey])) {
            continue;
        }

        $mode = 'default';
        $notes = '';
        if (is_array($modeData)) {
            $mode = strtolower(trim((string)($modeData['mode'] ?? 'default')));
            $notes = trim((string)($modeData['notes'] ?? ''));
        } else {
            $mode = strtolower(trim((string)$modeData));
        }

        if (!in_array($mode, $allowedModes, true)) {
            throw new RuntimeException("Invalid mode for {$permissionKey}");
        }

        $isAllowed = null;
        if ($mode === 'allow') {
            $isAllowed = true;
        } elseif ($mode === 'deny') {
            $isAllowed = false;
        }

        $ok = setUserPermissionOverride($conn, $targetUserId, $permissionKey, $isAllowed, $currentUserId, $notes);
        if (!$ok) {
            throw new RuntimeException("Failed to update {$permissionKey}");
        }
        $updatedCount++;

        $permLabel = (string)($catalog[$permissionKey]['label'] ?? $permissionKey);
        $modeLabel = $mode === 'allow' ? 'Allow' : ($mode === 'deny' ? 'Deny' : 'Default');
        $noteSuffix = $notes !== '' ? " (note: {$notes})" : '';
        $changeNotes[] = "Set {$permLabel} to {$modeLabel}{$noteSuffix}";
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $currentUserId,
        'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
        'actor_role' => $currentRole,
        'action' => 'user_permissions_updated',
        'entity_type' => 'tb_users',
        'entity_id' => $targetUserId,
        'details' => [
            'target_user' => (string)($targetUser['userName'] ?? ''),
            'updated_permissions' => $updatedCount,
            'changes' => $changeNotes
        ]
    ]);
}

$targetRole = strtolower((string)($targetUser['userRole'] ?? 'user'));
$effective = getEffectivePermissionsForUser($conn, $targetUserId, $targetRole);
$overrides = getUserPermissionOverrides($conn, $targetUserId);

echo json_encode([
    'success' => true,
    'message' => 'User permission overrides saved successfully.',
    'user_id' => $targetUserId,
    'effective_permissions' => $effective,
    'overrides' => $overrides
]);

$conn->close();
?>
