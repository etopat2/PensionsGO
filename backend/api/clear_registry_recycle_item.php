<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/registry_recycle_common.php';

try {
    $actor = registryRecycleActorContext($conn, false);
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request payload');
    }

    $recycleId = (int)($payload['recycle_id'] ?? 0);
    if ($recycleId <= 0) {
        throw new RuntimeException('Invalid recycle bin record');
    }

    $conn->begin_transaction();
    $result = clearRegistryRecycleBinItem($conn, $recycleId);
    if (empty($result['success'])) {
        throw new RuntimeException($result['message'] ?? 'Failed to clear recycle bin record');
    }

    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => 'registry_recycle_bin_cleared',
        'entity_type' => 'tb_file_registry_recycle_bin',
        'entity_id' => (string)$recycleId,
        'details' => [
            'regNo' => $result['regNo'] ?? '',
            'staff_name' => $result['staff_name'] ?? '',
            'restored' => !empty($result['restored'])
        ]
    ]);

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Recycle bin record cleared permanently.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli && $conn->errno === 0) {
        // no-op guard
    }
    if (isset($conn) && $conn instanceof mysqli) {
        @$conn->rollback();
    }
    http_response_code(str_contains(strtolower($error->getMessage()), 'access denied') ? 403 : 500);
    echo json_encode([
        'success' => false,
        'message' => $error->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
