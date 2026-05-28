<?php
require_once __DIR__ . '/../config.php';

function registryRecycleActorContext(mysqli $conn, bool $adminOnly = false): array
{
    $actor = requireDataManagementAccess($conn);

    $role = strtolower((string)($actor['user_role'] ?? ''));
    $userId = (string)($actor['user_id'] ?? '');
    $userName = (string)($actor['user_name'] ?? 'Unknown');

    if ($adminOnly) {
        if ($role !== 'admin') {
            throw new RuntimeException('Access denied');
        }
    } elseif (!currentUserHasPermission($conn, 'registry.delete_queue.process')) {
        throw new RuntimeException('Access denied');
    }

    ensureRegistryRecycleBinTable($conn);

    return [
        'user_id' => $userId,
        'user_name' => $userName,
        'user_role' => $role
    ];
}

function registryRecycleNormalizeFilters(array $input, bool $forPurge = false): array
{
    $state = strtolower(trim((string)($input['state'] ?? 'all')));
    if (!in_array($state, ['all', 'deleted', 'restored'], true)) {
        $state = $forPurge ? 'restored' : 'all';
    }

    $limit = (int)($input['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }
    $limit = max(1, min($limit, 200));

    $page = (int)($input['page'] ?? 1);
    if ($page <= 0) {
        $page = 1;
    }

    $olderThanDays = (int)($input['older_than_days'] ?? 90);
    if ($olderThanDays <= 0) {
        $olderThanDays = 90;
    }
    $olderThanDays = min($olderThanDays, 3650);

    $actorRole = strtolower(trim((string)($input['actor_role'] ?? '')));
    $search = trim((string)($input['search'] ?? ''));
    $dateFrom = trim((string)($input['date_from'] ?? ''));
    $dateTo = trim((string)($input['date_to'] ?? ''));

    if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $dateFrom = '';
    }
    if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $dateTo = '';
    }

    return [
        'search' => $search,
        'state' => $state,
        'actor_role' => $actorRole,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page' => $page,
        'limit' => $limit,
        'offset' => ($page - 1) * $limit,
        'older_than_days' => $olderThanDays
    ];
}

function registryRecycleBindParams(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types === '' || empty($values)) {
        return;
    }

    $refs = [];
    $refs[] = &$types;
    foreach ($values as $index => $value) {
        $refs[] = &$values[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function registryRecycleBuildWhere(array $filters, array &$values, string &$types, bool $requirePurgeWindow = false): string
{
    $clauses = ['1=1'];

    if (!empty($filters['search'])) {
        $clauses[] = "(regNo LIKE ? OR staff_name LIKE ? OR staff_title LIKE ? OR delete_reason LIKE ? OR deleted_by_name LIKE ? OR deleted_by_role LIKE ? OR restored_by_name LIKE ?)";
        $phrase = '%' . $filters['search'] . '%';
        for ($i = 0; $i < 7; $i += 1) {
            $values[] = $phrase;
            $types .= 's';
        }
    }

    if (($filters['state'] ?? 'all') === 'deleted') {
        $clauses[] = 'restored = 0';
    } elseif (($filters['state'] ?? 'all') === 'restored') {
        $clauses[] = 'restored = 1';
    }

    if (!empty($filters['actor_role'])) {
        $clauses[] = 'LOWER(COALESCE(deleted_by_role, \'\')) = ?';
        $values[] = strtolower((string)$filters['actor_role']);
        $types .= 's';
    }

    if (!empty($filters['date_from'])) {
        $clauses[] = 'DATE(deleted_at) >= ?';
        $values[] = (string)$filters['date_from'];
        $types .= 's';
    }

    if (!empty($filters['date_to'])) {
        $clauses[] = 'DATE(deleted_at) <= ?';
        $values[] = (string)$filters['date_to'];
        $types .= 's';
    }

    if ($requirePurgeWindow) {
        $clauses[] = 'deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $values[] = (int)($filters['older_than_days'] ?? 90);
        $types .= 'i';
    }

    return implode(' AND ', $clauses);
}

function registryRecycleFetchSummary(mysqli $conn, array $filters, bool $requirePurgeWindow = false): array
{
    $values = [];
    $types = '';
    $where = registryRecycleBuildWhere($filters, $values, $types, $requirePurgeWindow);

    $sql = "
        SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN restored = 0 THEN 1 ELSE 0 END) AS deleted_rows,
            SUM(CASE WHEN restored = 1 THEN 1 ELSE 0 END) AS restored_rows,
            SUM(CASE WHEN delete_request_id IS NULL OR delete_request_id = 0 THEN 1 ELSE 0 END) AS direct_rows,
            SUM(CASE WHEN delete_request_id IS NOT NULL AND delete_request_id > 0 THEN 1 ELSE 0 END) AS queued_rows
        FROM tb_file_registry_recycle_bin
        WHERE {$where}
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare recycle bin summary query');
    }
    registryRecycleBindParams($stmt, $types, $values);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return [
        'total' => (int)($summary['total_rows'] ?? 0),
        'deleted' => (int)($summary['deleted_rows'] ?? 0),
        'restored' => (int)($summary['restored_rows'] ?? 0),
        'direct' => (int)($summary['direct_rows'] ?? 0),
        'queued' => (int)($summary['queued_rows'] ?? 0)
    ];
}

function registryRecycleFetchRows(mysqli $conn, array $filters, int $limit, int $offset): array
{
    $values = [];
    $types = '';
    $where = registryRecycleBuildWhere($filters, $values, $types);

    $sql = "
        SELECT
            recycle_id,
            registry_id,
            regNo,
            staff_name,
            staff_title,
            delete_request_id,
            delete_reason,
            deleted_by_name,
            deleted_by_role,
            deleted_at,
            restored,
            restored_by_name,
            restored_by_role,
            restored_at
        FROM tb_file_registry_recycle_bin
        WHERE {$where}
        ORDER BY deleted_at DESC, recycle_id DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare recycle bin query');
    }

    $values[] = $limit;
    $types .= 'i';
    $values[] = $offset;
    $types .= 'i';

    registryRecycleBindParams($stmt, $types, $values);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'recycle_id' => (int)($row['recycle_id'] ?? 0),
            'registry_id' => (int)($row['registry_id'] ?? 0),
            'regNo' => (string)($row['regNo'] ?? ''),
            'staff_name' => (string)($row['staff_name'] ?? ''),
            'staff_title' => (string)($row['staff_title'] ?? ''),
            'delete_request_id' => (int)($row['delete_request_id'] ?? 0),
            'delete_mode' => ((int)($row['delete_request_id'] ?? 0) > 0) ? 'Queued Request' : 'Direct Delete',
            'delete_reason' => (string)($row['delete_reason'] ?? ''),
            'deleted_by_name' => (string)($row['deleted_by_name'] ?? ''),
            'deleted_by_role' => (string)($row['deleted_by_role'] ?? ''),
            'deleted_at' => (string)($row['deleted_at'] ?? ''),
            'restored' => ((int)($row['restored'] ?? 0)) === 1,
            'restored_by_name' => (string)($row['restored_by_name'] ?? ''),
            'restored_by_role' => (string)($row['restored_by_role'] ?? ''),
            'restored_at' => (string)($row['restored_at'] ?? '')
        ];
    }
    $stmt->close();

    return $rows;
}

function registryRecycleFetchAllRows(mysqli $conn, array $filters): array
{
    $summary = registryRecycleFetchSummary($conn, $filters);
    $limit = max(1, min($summary['total'] ?: 1, 5000));
    return registryRecycleFetchRows($conn, $filters, $limit, 0);
}

function registryRecycleFetchRoleOptions(mysqli $conn): array
{
    $options = [];
    $sql = "
        SELECT LOWER(TRIM(deleted_by_role)) AS role_key
        FROM tb_file_registry_recycle_bin
        WHERE deleted_by_role IS NOT NULL AND TRIM(deleted_by_role) <> ''
        GROUP BY LOWER(TRIM(deleted_by_role))
        ORDER BY role_key ASC
    ";
    $result = $conn->query($sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roleKey = trim((string)($row['role_key'] ?? ''));
            if ($roleKey === '') {
                continue;
            }
            $options[] = [
                'value' => $roleKey,
                'label' => getRoleLabel($conn, $roleKey) ?: ucwords(str_replace('_', ' ', $roleKey))
            ];
        }
    }
    return $options;
}
