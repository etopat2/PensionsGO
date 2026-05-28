<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/registry_recycle_common.php';

try {
    $actor = registryRecycleActorContext($conn, true);
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request payload');
    }

    $dryRun = !empty($payload['dry_run']);
    $filters = registryRecycleNormalizeFilters($payload, true);
    $summary = registryRecycleFetchSummary($conn, $filters, true);
    $sampleRows = registryRecycleFetchRows($conn, $filters, 5, 0);

    if ($dryRun) {
        echo json_encode([
            'success' => true,
            'message' => $summary['total'] > 0
                ? 'Purge preview generated successfully.'
                : 'No recycle bin records match the current purge rules.',
            'preview' => [
                'summary' => $summary,
                'sample' => $sampleRows,
                'older_than_days' => $filters['older_than_days'],
                'state' => $filters['state'],
                'actor_role' => $filters['actor_role']
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->close();
        }
        exit;
    }

    if ($summary['total'] <= 0) {
        throw new RuntimeException('No recycle bin records match the current purge rules.');
    }

    $values = [];
    $types = '';
    $where = registryRecycleBuildWhere($filters, $values, $types, true);
    $selectSql = "SELECT recycle_id FROM tb_file_registry_recycle_bin WHERE {$where}";
    $selectStmt = $conn->prepare($selectSql);
    if (!$selectStmt) {
        throw new RuntimeException('Failed to prepare recycle bin purge query');
    }
    registryRecycleBindParams($selectStmt, $types, $values);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    $purgedCount = 0;
    while ($result && ($row = $result->fetch_assoc())) {
        $clearResult = clearRegistryRecycleBinItem($conn, (int)($row['recycle_id'] ?? 0));
        if (empty($clearResult['success'])) {
            $selectStmt->close();
            throw new RuntimeException($clearResult['message'] ?? 'Failed to purge recycle bin records');
        }
        $purgedCount++;
    }
    $selectStmt->close();

    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => 'registry_recycle_bin_purged',
        'entity_type' => 'tb_file_registry_recycle_bin',
        'entity_id' => 'recycle_bin',
        'details' => [
            'purged_count' => $purgedCount,
            'older_than_days' => $filters['older_than_days'],
            'state' => $filters['state'],
            'actor_role_filter' => $filters['actor_role']
        ]
    ]);

    echo json_encode([
        'success' => true,
        'message' => sprintf('Purged %d recycle bin record%s.', $purgedCount, $purgedCount === 1 ? '' : 's'),
        'purged_count' => $purgedCount
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
