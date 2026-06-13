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

$currentRole = getSessionEffectiveRoleKey($conn);
if (!sessionRoleIn($conn, ['admin'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Admin access required'
    ]);
    exit;
}

ensureUserPermissionsTable($conn);

$users = [];
$usersStmt = $conn->prepare("
    SELECT userId, userName, userEmail, userRole
    FROM tb_users
    WHERE LOWER(COALESCE(userRole, '')) <> 'pensioner'
    ORDER BY userName ASC
");
if (!$usersStmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load users list'
    ]);
    exit;
}
$usersStmt->execute();
$usersResult = $usersStmt->get_result();
while ($row = $usersResult->fetch_assoc()) {
    $users[] = [
        'userId' => (string)($row['userId'] ?? ''),
        'userName' => (string)($row['userName'] ?? ''),
        'userEmail' => (string)($row['userEmail'] ?? ''),
        'userRole' => strtolower((string)($row['userRole'] ?? 'user'))
    ];
}
$usersStmt->close();

$selectedUserId = trim((string)($_GET['user_id'] ?? ''));
if ($selectedUserId === '' && !empty($users)) {
    $selectedUserId = (string)$users[0]['userId'];
}

$selectedUser = null;
foreach ($users as $candidate) {
    if ((string)$candidate['userId'] === $selectedUserId) {
        $selectedUser = $candidate;
        break;
    }
}

if (!$selectedUser && !empty($users)) {
    $selectedUser = $users[0];
    $selectedUserId = (string)$selectedUser['userId'];
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
if ($selectedUser) {
    $selectedRole = (string)($selectedUser['userRole'] ?? 'user');
    $overrides = getUserPermissionOverrides($conn, $selectedUserId);
    foreach ($catalog as $key => $meta) {
        $override = $overrides[$key] ?? null;
        $mode = 'default';
        if (is_array($override)) {
            $mode = !empty($override['is_allowed']) ? 'allow' : 'deny';
        }
        $defaultAllowed = roleHasDefaultPermission($conn, $selectedRole, $key);
        $effectiveAllowed = getEffectiveUserPermission($conn, $selectedUserId, $selectedRole, $key);

        $permissions[] = [
            'key' => $key,
            'label' => (string)($meta['label'] ?? $key),
            'description' => (string)($meta['description'] ?? ''),
            'mode' => $mode,
            'default_allowed' => $defaultAllowed,
            'effective_allowed' => $effectiveAllowed,
            'notes' => (string)($override['notes'] ?? ''),
            'updated_at' => (string)($override['updated_at'] ?? '')
        ];
    }
}

echo json_encode([
    'success' => true,
    'role_labels' => getRoleLabelMap($conn, false),
    'catalog' => $catalogList,
    'users' => $users,
    'selected_user_id' => $selectedUserId,
    'selected_user' => $selectedUser,
    'permissions' => $permissions
]);

$conn->close();
?>
