<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Please log in.'
    ]);
    exit;
}

ensureRoleGovernanceTables($conn);

try {
    $roleLabels = getRoleLabelMap($conn, false);

    $roleCounts = [];
    $countResult = $conn->query("
        SELECT
            COALESCE(userRole, '') AS role_key,
            COUNT(*) AS total_count
        FROM tb_users
        GROUP BY COALESCE(userRole, '')
    ");
    if ($countResult) {
        while ($row = $countResult->fetch_assoc()) {
            $key = resolveRoleKeyFromInput($conn, (string)($row['role_key'] ?? ''), false);
            if ($key === '') {
                $key = 'user';
            }
            $roleCounts[$key] = (int)($roleCounts[$key] ?? 0) + (int)($row['total_count'] ?? 0);
        }
    }

    $users = [];
    $seen = [];

    $roleResult = $conn->query("
        SELECT role_key, role_label, is_active, is_system
        FROM tb_roles
        ORDER BY is_system DESC, role_label ASC, role_key ASC
    ");
    if ($roleResult) {
        while ($role = $roleResult->fetch_assoc()) {
            $roleKey = strtolower(trim((string)($role['role_key'] ?? '')));
            if ($roleKey === '') {
                continue;
            }

            $users[] = [
                'role' => $roleKey,
                'role_label' => trim((string)($role['role_label'] ?? getRoleLabel($conn, $roleKey))),
                'count' => (int)($roleCounts[$roleKey] ?? 0),
                'is_active' => ((int)($role['is_active'] ?? 0)) === 1,
                'is_system' => ((int)($role['is_system'] ?? 0)) === 1
            ];
            $seen[$roleKey] = true;
        }
    }

    // Include legacy/unknown roles still present in tb_users but not in tb_roles.
    $unknownRoles = [];
    foreach ($roleCounts as $roleKey => $count) {
        if (isset($seen[$roleKey])) {
            continue;
        }
        $unknownRoles[] = [
            'role' => $roleKey,
            'role_label' => (string)($roleLabels[$roleKey] ?? ucwords(str_replace('_', ' ', $roleKey))),
            'count' => (int)$count,
            'is_active' => true,
            'is_system' => false
        ];
    }

    if (!empty($unknownRoles)) {
        usort($unknownRoles, static function (array $a, array $b): int {
            $countCmp = (int)($b['count'] ?? 0) <=> (int)($a['count'] ?? 0);
            if ($countCmp !== 0) {
                return $countCmp;
            }
            return strcasecmp((string)($a['role_label'] ?? ''), (string)($b['role_label'] ?? ''));
        });
        $users = array_merge($users, $unknownRoles);
    }

    $totalUsers = array_sum(array_map(static function (array $entry): int {
        return (int)($entry['count'] ?? 0);
    }, $users));

    echo json_encode([
        'success' => true,
        'users' => $users,
        'total_users' => $totalUsers,
        'role_labels' => $roleLabels,
        'query_info' => [
            'users_found' => count($users),
            'roles_found' => array_values(array_map(static function (array $entry): string {
                return (string)($entry['role'] ?? '');
            }, $users))
        ]
    ]);
} catch (Throwable $e) {
    error_log('Error in get_users_summary.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load users summary.'
    ]);
}

$conn->close();
?>
