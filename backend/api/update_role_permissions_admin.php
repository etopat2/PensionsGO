<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit;
}

$currentUserId = (string)($_SESSION['userId'] ?? '');
$currentRole = getSessionEffectiveRoleKey($conn);
if ($currentRole !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

ensureRoleGovernanceTables($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload'
    ]);
    exit;
}

$targetRoleKey = strtolower(trim((string)($payload['role_key'] ?? '')));
if ($targetRoleKey === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Role key is required'
    ]);
    exit;
}

$roleStmt = $conn->prepare("
    SELECT role_key, role_label
    FROM tb_roles
    WHERE role_key = ?
    LIMIT 1
");
if (!$roleStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to validate role'
    ]);
    exit;
}
$roleStmt->bind_param("s", $targetRoleKey);
$roleStmt->execute();
$role = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();
if (!$role) {
    echo json_encode([
        'success' => false,
        'message' => 'Target role not found'
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

        $ok = setRolePermissionOverride($conn, $targetRoleKey, $permissionKey, $isAllowed, $currentUserId, $notes);
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
        'action' => 'role_permissions_updated',
        'entity_type' => 'tb_roles',
        'entity_id' => $targetRoleKey,
        'details' => [
            'role_label' => (string)($role['role_label'] ?? $targetRoleKey),
            'updated_permissions' => $updatedCount,
            'changes' => $changeNotes
        ]
    ]);
}

$roleOverrides = getRolePermissionOverrides($conn, $targetRoleKey);
$effectivePermissions = [];
foreach ($catalog as $permissionKey => $meta) {
    $override = $roleOverrides[$permissionKey] ?? null;
    if (is_array($override)) {
        $effectivePermissions[$permissionKey] = !empty($override['is_allowed']);
        continue;
    }
    $effectivePermissions[$permissionKey] = in_array($targetRoleKey, $meta['default_roles'] ?? [], true);
}

echo json_encode([
    'success' => true,
    'message' => 'Role permission overrides saved successfully.',
    'role_key' => $targetRoleKey,
    'role_label' => (string)($role['role_label'] ?? $targetRoleKey),
    'overrides' => $roleOverrides,
    'effective_permissions' => $effectivePermissions
]);

$conn->close();
?>
