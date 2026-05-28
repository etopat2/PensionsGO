<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';
require_once __DIR__ . '/data_export_runtime.php';

try {
    $actor = requireAdminDataManagementAccess($conn);
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid export request.');
    }

    $datasetKey = strtolower(trim((string)($payload['dataset_key'] ?? '')));
    $format = strtolower(trim((string)($payload['format'] ?? 'xlsx')));
    $result = dmExecuteConfiguredDataExport(
        $conn,
        $actor,
        $datasetKey,
        $format,
        $payload,
        'Export generated from Admin Console.'
    );

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'info',
            'log_category' => 'data_export',
            'event_code' => 'export_generated',
            'message' => 'Administrative data export generated.',
            'context' => [
                'dataset' => $datasetKey,
                'format' => $format,
                'file_name' => $result['file_name'] ?? null
            ],
            'actor_id' => $actor['user_id'],
            'actor_name' => $actor['user_name'],
            'actor_role' => $actor['user_role']
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Export generated successfully.',
        'export' => $result
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($actor) && function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'error',
            'log_category' => 'data_export',
            'event_code' => 'export_failed',
            'message' => 'Administrative data export failed.',
            'context' => [
                'error' => $e->getMessage(),
                'dataset' => $datasetKey ?? null,
                'format' => $format ?? null
            ],
            'actor_id' => $actor['user_id'] ?? null,
            'actor_name' => $actor['user_name'] ?? null,
            'actor_role' => $actor['user_role'] ?? null
        ]);
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
