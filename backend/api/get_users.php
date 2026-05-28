<?php
// 
// get_users.php
// Purpose: Fetch all registered users from the database with session validation
// Author: Patrick
// 
// Include config and start session
require_once __DIR__ . '/../config.php';

// Ensure user is logged in before fetching users
if (!isset($_SESSION['userId'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['success' => false, 'message' => 'Session expired or unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    $excludePensioner = isset($_GET['exclude_pensioner'])
        ? filter_var($_GET['exclude_pensioner'], FILTER_VALIDATE_BOOLEAN)
        : false;
    $accountType = strtolower(trim((string)($_GET['account_type'] ?? '')));

    $filters = [];
    if ($excludePensioner) {
        $filters[] = "LOWER(TRIM(COALESCE(userRole, ''))) <> 'pensioner'";
    }
    if ($accountType === 'staff') {
        $filters[] = "LOWER(TRIM(COALESCE(userRole, ''))) <> 'pensioner'";
    } elseif ($accountType === 'pensioner') {
        $filters[] = "LOWER(TRIM(COALESCE(userRole, ''))) = 'pensioner'";
    }
    $whereClause = !empty($filters) ? ('WHERE ' . implode(' AND ', $filters)) : '';

    // Prepare and execute the SQL statement
    $stmt = $conn->prepare("
        SELECT 
            userId, 
            userTitle, 
            userName, 
            userEmail, 
            phoneNo,
            userRole, 
            userPhoto 
        FROM tb_users 
        {$whereClause}
        ORDER BY userName
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $rawRole = (string)($row['userRole'] ?? '');
        $roleKey = resolveRoleKeyFromInput($conn, $rawRole, false);
        if ($roleKey === '') {
            $roleKey = 'user';
        }
        $users[] = [
            'userId'      => $row['userId'],
            'userTitle'   => $row['userTitle'],
            'userName'    => $row['userName'],
            'userEmail'   => $row['userEmail'],
            'phoneNo'     => $row['phoneNo'] ?? '',
            'userRole'    => $roleKey,
            'roleLabel'   => getRoleLabel($conn, $roleKey),
            'userPhoto'   => $row['userPhoto'] ?: 'images/default-user.png'
        ];
    }

    $roles = [];
    $roleResult = $conn->query("
        SELECT role_key, role_label, role_description, is_active, is_system
        FROM tb_roles
        WHERE is_active = 1
        ORDER BY is_system DESC, role_label ASC, role_key ASC
    ");
    if ($roleResult) {
        while ($roleRow = $roleResult->fetch_assoc()) {
            $key = strtolower((string)($roleRow['role_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $roles[] = [
                'role_key' => $key,
                'role_label' => (string)($roleRow['role_label'] ?? getRoleLabel($conn, $key)),
                'role_description' => (string)($roleRow['role_description'] ?? ''),
                'is_active' => ((int)($roleRow['is_active'] ?? 0)) === 1,
                'is_system' => ((int)($roleRow['is_system'] ?? 0)) === 1
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'users' => $users,
        'role_labels' => getRoleLabelMap($conn, false),
        'roles' => $roles
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching users: ' . $e->getMessage()
    ]);
}

// Cleanup
if (isset($stmt)) $stmt->close();
$conn->close();
?>

