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

$currentRole = getSessionEffectiveRoleKey($conn);
if (!sessionRoleIn($conn, ['admin'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

ensureRoleGovernanceTables($conn);

$roles = [];
$stmt = $conn->prepare("
    SELECT role_key, role_label, role_description, clone_from_role, is_active, is_system, created_at, updated_at
    FROM tb_roles
    ORDER BY is_system DESC, role_label ASC, role_key ASC
");
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load roles list'
    ]);
    exit;
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $roleKey = strtolower((string)($row['role_key'] ?? ''));
    if ($roleKey === '') {
        continue;
    }
    $roles[] = [
        'role_key' => $roleKey,
        'role_label' => (string)($row['role_label'] ?? getRoleLabel($conn, $roleKey)),
        'role_description' => (string)($row['role_description'] ?? ''),
        'clone_from_role' => (string)($row['clone_from_role'] ?? ''),
        'is_active' => ((int)($row['is_active'] ?? 0)) === 1,
        'is_system' => ((int)($row['is_system'] ?? 0)) === 1,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? '')
    ];
}
$stmt->close();

$selectedRoleKey = strtolower(trim((string)($_GET['role_key'] ?? '')));
if ($selectedRoleKey === '' && !empty($roles)) {
    $selectedRoleKey = (string)$roles[0]['role_key'];
}

$selectedRole = null;
foreach ($roles as $role) {
    if ((string)$role['role_key'] === $selectedRoleKey) {
        $selectedRole = $role;
        break;
    }
}
if (!$selectedRole && !empty($roles)) {
    $selectedRole = $roles[0];
    $selectedRoleKey = (string)$selectedRole['role_key'];
}

$catalog = getPermissionCatalog();
$catalogList = [];
foreach ($catalog as $key => $meta) {
    $catalogList[] = [
        'key' => $key,
        'label' => (string)($meta['label'] ?? $key),
        'description' => (string)($meta['description'] ?? ''),
        'default_roles' => array_values($meta['default_roles'] ?? [])
    ];
}

$permissions = [];
if ($selectedRole) {
    $overrides = getRolePermissionOverrides($conn, $selectedRoleKey);
    $effectiveRoleKey = getEffectiveRoleKey($conn, $selectedRoleKey);
    foreach ($catalog as $key => $meta) {
        $override = $overrides[$key] ?? null;
        $mode = 'default';
        if (is_array($override)) {
            $mode = !empty($override['is_allowed']) ? 'allow' : 'deny';
        }
        $defaultAllowed = in_array($effectiveRoleKey, $meta['default_roles'] ?? [], true);
        $effectiveAllowed = ($mode === 'allow')
            ? true
            : (($mode === 'deny') ? false : $defaultAllowed);

        $permissions[] = [
            'key' => $key,
            'label' => (string)($meta['label'] ?? $key),
            'description' => (string)($meta['description'] ?? ''),
            'mode' => $mode,
            'default_allowed' => $defaultAllowed,
            'effective_allowed' => $effectiveAllowed,
            'notes' => (string)($override['notes'] ?? ''),
            'updated_by' => (string)($override['updated_by'] ?? ''),
            'updated_at' => (string)($override['updated_at'] ?? '')
        ];
    }
}

echo json_encode([
    'success' => true,
    'role_labels' => getRoleLabelMap($conn, false),
    'roles' => $roles,
    'selected_role_key' => $selectedRoleKey,
    'selected_role' => $selectedRole,
    'catalog' => $catalogList,
    'permissions' => $permissions
]);

$conn->close();
?>
