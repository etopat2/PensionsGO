<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/registry_recycle_common.php';

try {
    $actor = registryRecycleActorContext($conn, false);
    $filters = registryRecycleNormalizeFilters($_GET);
    $summary = registryRecycleFetchSummary($conn, $filters);
    $rows = registryRecycleFetchRows($conn, $filters, $filters['limit'], $filters['offset']);
    $totalPages = max(1, (int)ceil(($summary['total'] ?: 0) / max(1, $filters['limit'])));

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'items' => $rows,
        'page' => $filters['page'],
        'limit' => $filters['limit'],
        'total_pages' => $totalPages,
        'role_options' => registryRecycleFetchRoleOptions($conn),
        'permissions' => [
            'can_restore' => currentUserHasPermission($conn, 'registry.delete_queue.process'),
            'can_clear' => currentUserHasPermission($conn, 'registry.delete_queue.process'),
            'can_purge' => roleHasAdminAccess($conn, (string)($actor['user_role'] ?? '')),
            'can_export' => currentUserHasPermission($conn, 'registry.delete_queue.process')
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(str_contains(strtolower($error->getMessage()), 'access denied') ? 403 : 500);
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
