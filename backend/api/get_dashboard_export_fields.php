<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';

function requireDashboardExportFieldAccess(mysqli $conn, string $datasetKey): void
{
    $actor = requireDataManagementAccess($conn);
    $role = normalizeRoleKey((string)($actor['user_role'] ?? ''));

    $allowed = false;
    switch ($datasetKey) {
        case 'registry_recycle_bin':
            $allowed = currentUserHasPermission($conn, 'registry.delete_queue.process');
            break;
        case 'claims_ledger':
            $allowed = currentUserHasPermission($conn, 'claims.arrears.view');
            break;
        case 'feedback_submissions':
            $allowed = currentUserHasPermission($conn, 'feedback.view') && getAppSettingBool($conn, 'feedback_allow_export', true);
            break;
        case 'file_registry':
        case 'staff_due':
        case 'file_movements':
            $allowed = true;
            break;
        case 'tasks':
            $allowed = getAppSettingBool($conn, 'workflow_logs_export_enabled', true);
            break;
    }

    if (!$allowed || $role === '') {
        throw new RuntimeException('Access denied');
    }
}

try {
    $datasetKey = strtolower(trim((string)($_GET['dataset_key'] ?? '')));
    if (!in_array($datasetKey, ['registry_recycle_bin', 'file_registry', 'staff_due', 'file_movements', 'claims_ledger', 'tasks', 'feedback_submissions'], true)) {
        throw new RuntimeException('Unsupported dashboard dataset export.');
    }

    requireDashboardExportFieldAccess($conn, $datasetKey);
    $metadata = dmGetDashboardExportMetadata($conn, $datasetKey);

    echo json_encode([
        'success' => true,
        'metadata' => $metadata
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $status = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
