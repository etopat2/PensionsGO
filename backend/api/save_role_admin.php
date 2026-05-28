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

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload'
    ]);
    exit;
}

$action = strtolower(trim((string)($payload['action'] ?? 'update')));
$roleKey = strtolower(trim((string)($payload['role_key'] ?? '')));
$roleLabel = trim((string)($payload['role_label'] ?? ''));
$roleDescription = trim((string)($payload['role_description'] ?? ''));
$cloneFromRole = strtolower(trim((string)($payload['clone_from_role'] ?? '')));
$isActive = array_key_exists('is_active', $payload) ? ((bool)$payload['is_active']) : true;

if (!in_array($action, ['create', 'update', 'delete'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Unsupported action.'
    ]);
    exit;
}

if ($roleKey === '' || !preg_match('/^[a-z][a-z0-9_]{1,49}$/', $roleKey)) {
    echo json_encode([
        'success' => false,
        'message' => 'Role key must start with a letter and contain only lowercase letters, numbers, and underscores.'
    ]);
    exit;
}

if (in_array($action, ['create', 'update'], true) && $roleLabel === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Role UI label is required.'
    ]);
    exit;
}

ensureRoleGovernanceTables($conn);

$resolvedCloneRole = '';
if ($cloneFromRole !== '') {
    if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $cloneFromRole)) {
        echo json_encode([
            'success' => false,
            'message' => 'Clone source role key is invalid.'
        ]);
        exit;
    }
    if ($cloneFromRole === $roleKey) {
        echo json_encode([
            'success' => false,
            'message' => 'A role cannot clone privileges from itself.'
        ]);
        exit;
    }
    $cloneStmt = $conn->prepare("SELECT role_key FROM tb_roles WHERE role_key = ? LIMIT 1");
    if (!$cloneStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to validate clone source role.'
        ]);
        exit;
    }
    $cloneStmt->bind_param("s", $cloneFromRole);
    $cloneStmt->execute();
    $cloneSource = $cloneStmt->get_result()->fetch_assoc();
    $cloneStmt->close();
    if (!$cloneSource) {
        echo json_encode([
            'success' => false,
            'message' => 'Clone source role was not found.'
        ]);
        exit;
    }
    $resolvedCloneRole = $cloneFromRole;
}

$existingStmt = $conn->prepare("
    SELECT role_key, role_label, is_system, is_active
    FROM tb_roles
    WHERE role_key = ?
    LIMIT 1
");
if (!$existingStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to validate role.'
    ]);
    exit;
}
$existingStmt->bind_param("s", $roleKey);
$existingStmt->execute();
$existing = $existingStmt->get_result()->fetch_assoc();
$existingStmt->close();

$applyRoleClone = static function (
    mysqli $conn,
    string $targetRoleKey,
    string $sourceRoleKey,
    string $actorUserId
): array {
    $cloneFromRole = strtolower(trim($sourceRoleKey));
    $targetRoleKey = strtolower(trim($targetRoleKey));
    if ($cloneFromRole === '' || $targetRoleKey === '') {
        return ['ok' => true];
    }
    if (!preg_match('/^[a-z][a-z0-9_]{1,49}$/', $cloneFromRole)) {
        return ['ok' => false, 'message' => 'Clone source role key is invalid.'];
    }
    if ($cloneFromRole === $targetRoleKey) {
        return ['ok' => false, 'message' => 'A role cannot clone privileges from itself.'];
    }

    $cloneStmt = $conn->prepare("
        SELECT role_key
        FROM tb_roles
        WHERE role_key = ?
        LIMIT 1
    ");
    if (!$cloneStmt) {
        return ['ok' => false, 'message' => 'Unable to validate clone source role.'];
    }
    $cloneStmt->bind_param("s", $cloneFromRole);
    $cloneStmt->execute();
    $cloneSourceRole = $cloneStmt->get_result()->fetch_assoc();
    $cloneStmt->close();

    if (!$cloneSourceRole) {
        return ['ok' => false, 'message' => 'Clone source role was not found.'];
    }

    $permissionCatalog = getPermissionCatalog();
    $sourceOverrides = getRolePermissionOverrides($conn, $cloneFromRole);
    $cloneNotes = 'Cloned from role "' . $cloneFromRole . '"';

    foreach ($permissionCatalog as $permissionKey => $meta) {
        if (isset($sourceOverrides[$permissionKey])) {
            $sourceEffective = ((bool)($sourceOverrides[$permissionKey]['is_allowed'] ?? false));
        } else {
            $sourceEffective = roleHasDefaultPermission($conn, $cloneFromRole, $permissionKey);
        }

        $targetDefault = in_array($targetRoleKey, (array)($meta['default_roles'] ?? []), true);
        if ($sourceEffective === $targetDefault) {
            setRolePermissionOverride(
                $conn,
                $targetRoleKey,
                $permissionKey,
                null,
                $actorUserId,
                $cloneNotes
            );
            continue;
        }

        $ok = setRolePermissionOverride(
            $conn,
            $targetRoleKey,
            $permissionKey,
            $sourceEffective,
            $actorUserId,
            $cloneNotes
        );
        if (!$ok) {
            return ['ok' => false, 'message' => 'Failed while cloning role privileges.'];
        }
    }

    return ['ok' => true];
};

if ($action === 'delete') {
    if (!$existing) {
        echo json_encode([
            'success' => false,
            'message' => 'Role not found.'
        ]);
        exit;
    }

    $isSystemRole = ((int)($existing['is_system'] ?? 0)) === 1;
    if ($isSystemRole) {
        echo json_encode([
            'success' => false,
            'message' => 'System roles cannot be deleted.'
        ]);
        exit;
    }

    $usageStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM tb_users
        WHERE LOWER(TRIM(COALESCE(userRole, ''))) = ?
    ");
    if (!$usageStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to validate role usage.'
        ]);
        exit;
    }
    $usageStmt->bind_param("s", $roleKey);
    $usageStmt->execute();
    $usageRow = $usageStmt->get_result()->fetch_assoc();
    $usageStmt->close();
    $usageCount = (int)($usageRow['total'] ?? 0);
    if ($usageCount > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot delete role while assigned to users.',
            'assigned_users' => $usageCount
        ]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $deletePermStmt = $conn->prepare("
            DELETE FROM tb_role_permissions
            WHERE role_key = ?
        ");
        if (!$deletePermStmt) {
            throw new RuntimeException('Unable to delete role permission overrides.');
        }
        $deletePermStmt->bind_param("s", $roleKey);
        $deletePermStmt->execute();
        $deletePermStmt->close();

        $deleteRoleStmt = $conn->prepare("
            DELETE FROM tb_roles
            WHERE role_key = ?
            LIMIT 1
        ");
        if (!$deleteRoleStmt) {
            throw new RuntimeException('Unable to delete role.');
        }
        $deleteRoleStmt->bind_param("s", $roleKey);
        $deleteRoleStmt->execute();
        $affected = (int)$deleteRoleStmt->affected_rows;
        $deleteRoleStmt->close();

        if ($affected < 1) {
            throw new RuntimeException('Role not found.');
        }

        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $ex->getMessage() !== '' ? $ex->getMessage() : 'Failed to delete role.'
        ]);
        exit;
    }

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $currentUserId,
            'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
            'actor_role' => $currentRole,
            'action' => 'role_deleted',
            'entity_type' => 'tb_roles',
            'entity_id' => $roleKey,
            'details' => [
                'role_label' => (string)($existing['role_label'] ?? '')
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Role deleted successfully.',
        'deleted_role_key' => $roleKey
    ]);
    $conn->close();
    exit;
}

if ($action === 'create') {
    if ($existing) {
        echo json_encode([
            'success' => false,
            'message' => 'Role key already exists. Use update instead.'
        ]);
        exit;
    }

    $isSystem = 0;
    $isActiveInt = $isActive ? 1 : 0;
    $insertStmt = $conn->prepare("
        INSERT INTO tb_roles (role_key, role_label, role_description, clone_from_role, is_active, is_system)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    if (!$insertStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to create role.'
        ]);
        exit;
    }
    $cloneParam = $resolvedCloneRole !== '' ? $resolvedCloneRole : null;
    $insertStmt->bind_param("ssssii", $roleKey, $roleLabel, $roleDescription, $cloneParam, $isActiveInt, $isSystem);
    $ok = $insertStmt->execute();
    $insertStmt->close();
    if (!$ok) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to create role.'
        ]);
        exit;
    }

    if ($resolvedCloneRole !== '') {
        $cloneResult = $applyRoleClone($conn, $roleKey, $resolvedCloneRole, $currentUserId);
        if (!$cloneResult['ok']) {
            echo json_encode([
                'success' => false,
                'message' => (string)($cloneResult['message'] ?? 'Failed to clone role privileges.')
            ]);
            exit;
        }
    }

    if (function_exists('logAuditEvent')) {
        $auditDetails = [
            'role_label' => $roleLabel,
            'is_active' => $isActive
        ];
        if ($resolvedCloneRole !== '') {
            $auditDetails['clone_from_role'] = $resolvedCloneRole;
        }

        logAuditEvent($conn, [
            'actor_id' => $currentUserId,
            'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
            'actor_role' => $currentRole,
            'action' => 'role_created',
            'entity_type' => 'tb_roles',
            'entity_id' => $roleKey,
            'details' => $auditDetails
        ]);
    }
} else {
    if (!$existing) {
        echo json_encode([
            'success' => false,
            'message' => 'Role not found.'
        ]);
        exit;
    }

    $isSystemRole = ((int)($existing['is_system'] ?? 0)) === 1;
    if ($isSystemRole) {
        $isActive = true;
    }

    $isActiveInt = $isActive ? 1 : 0;
    $updateStmt = $conn->prepare("
        UPDATE tb_roles
        SET role_label = ?, role_description = ?, clone_from_role = ?, is_active = ?, updated_at = NOW()
        WHERE role_key = ?
        LIMIT 1
    ");
    if (!$updateStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Unable to update role.'
        ]);
        exit;
    }
    $cloneParam = $resolvedCloneRole !== '' ? $resolvedCloneRole : null;
    $updateStmt->bind_param("sssis", $roleLabel, $roleDescription, $cloneParam, $isActiveInt, $roleKey);
    $ok = $updateStmt->execute();
    $updateStmt->close();
    if (!$ok) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update role.'
        ]);
        exit;
    }

    if ($resolvedCloneRole !== '') {
        $cloneResult = $applyRoleClone($conn, $roleKey, $resolvedCloneRole, $currentUserId);
        if (!$cloneResult['ok']) {
            echo json_encode([
                'success' => false,
                'message' => (string)($cloneResult['message'] ?? 'Failed to clone role privileges.')
            ]);
            exit;
        }
    }

    if (function_exists('logAuditEvent')) {
        $details = [
            'role_label' => $roleLabel,
            'is_active' => $isActive
        ];
        if ($resolvedCloneRole !== '') {
            $details['clone_from_role'] = $resolvedCloneRole;
        }

        logAuditEvent($conn, [
            'actor_id' => $currentUserId,
            'actor_name' => (string)($_SESSION['userName'] ?? 'Administrator'),
            'actor_role' => $currentRole,
            'action' => 'role_updated',
            'entity_type' => 'tb_roles',
            'entity_id' => $roleKey,
            'details' => $details
        ]);
    }
}

$roleStmt = $conn->prepare("
    SELECT role_key, role_label, role_description, clone_from_role, is_active, is_system, created_at, updated_at
    FROM tb_roles
    WHERE role_key = ?
    LIMIT 1
");
if (!$roleStmt) {
    echo json_encode([
        'success' => true,
        'message' => 'Role saved successfully.',
        'role_key' => $roleKey
    ]);
    exit;
}
$roleStmt->bind_param("s", $roleKey);
$roleStmt->execute();
$row = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

echo json_encode([
    'success' => true,
    'message' => 'Role saved successfully.',
    'role' => $row ? [
        'role_key' => strtolower((string)($row['role_key'] ?? $roleKey)),
        'role_label' => (string)($row['role_label'] ?? $roleLabel),
        'role_description' => (string)($row['role_description'] ?? ''),
        'clone_from_role' => (string)($row['clone_from_role'] ?? ''),
        'is_active' => ((int)($row['is_active'] ?? 0)) === 1,
        'is_system' => ((int)($row['is_system'] ?? 0)) === 1,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? '')
    ] : null
]);

$conn->close();
?>
