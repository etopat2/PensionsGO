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

ensureRoleGovernanceTables($conn);

$activeOnlyParam = strtolower(trim((string)($_GET['active_only'] ?? '1')));
$includeInactive = in_array($activeOnlyParam, ['0', 'false', 'no'], true);
$activeOnly = !$includeInactive;

$includePensionerParam = strtolower(trim((string)($_GET['include_pensioner'] ?? '1')));
$includePensioner = !in_array($includePensionerParam, ['0', 'false', 'no'], true);

$sql = "
    SELECT role_key, role_label, role_description, clone_from_role, is_active, is_system, created_at, updated_at
    FROM tb_roles
";
$conditions = [];
if ($activeOnly) {
    $conditions[] = "is_active = 1";
}
if (!$includePensioner) {
    $conditions[] = "LOWER(role_key) <> 'pensioner'";
}
if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}
$sql .= " ORDER BY is_system DESC, role_label ASC, role_key ASC";

$result = $conn->query($sql);
if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load roles'
    ]);
    exit;
}

$roles = [];
while ($row = $result->fetch_assoc()) {
    $roleKey = strtolower((string)($row['role_key'] ?? ''));
    if ($roleKey === '') {
        continue;
    }
    $roleLabel = trim((string)($row['role_label'] ?? ''));
    if ($roleLabel === '') {
        $roleLabel = getRoleLabel($conn, $roleKey);
    }
    $roles[] = [
        'role_key' => $roleKey,
        'role_label' => $roleLabel,
        'role_description' => (string)($row['role_description'] ?? ''),
        'clone_from_role' => (string)($row['clone_from_role'] ?? ''),
        'is_active' => ((int)($row['is_active'] ?? 0)) === 1,
        'is_system' => ((int)($row['is_system'] ?? 0)) === 1,
        'created_at' => (string)($row['created_at'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? '')
    ];
}

echo json_encode([
    'success' => true,
    'role_labels' => getRoleLabelMap($conn, false),
    'roles' => $roles
]);

$conn->close();
?>
