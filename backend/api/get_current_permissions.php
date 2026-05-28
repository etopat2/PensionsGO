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

$userId = (string)($_SESSION['userId'] ?? '');
$userRole = strtolower((string)($_SESSION['userRole'] ?? ''));

$keysParam = $_GET['keys'] ?? '';
$keys = [];
if (is_string($keysParam) && trim($keysParam) !== '') {
    $keys = array_values(array_filter(array_map(static function ($item) {
        return trim((string)$item);
    }, explode(',', $keysParam))));
}

$permissions = getEffectivePermissionsForUser($conn, $userId, $userRole, $keys);
$catalog = getPermissionCatalog();
$catalogSubset = [];
foreach ($permissions as $key => $allowed) {
    if (!isset($catalog[$key])) {
        continue;
    }
    $catalogSubset[$key] = [
        'label' => $catalog[$key]['label'] ?? $key,
        'description' => $catalog[$key]['description'] ?? '',
        'default_roles' => $catalog[$key]['default_roles'] ?? []
    ];
}

echo json_encode([
    'success' => true,
    'userId' => $userId,
    'userRole' => $userRole,
    'permissions' => $permissions,
    'catalog' => $catalogSubset
]);

$conn->close();
?>
