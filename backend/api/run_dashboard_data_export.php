<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
ob_start();

$dashboardExportResponseSent = false;
register_shutdown_function(static function () use (&$dashboardExportResponseSent): void {
    if ($dashboardExportResponseSent) {
        return;
    }

    $fatal = error_get_last();
    if (!$fatal || !in_array((int)($fatal['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => false,
        'message' => 'The export failed while generating the file. Try reducing the selected fields or export a narrower filtered view.'
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
});

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';
require_once __DIR__ . '/data_export_runtime.php';

function requireDashboardDataManagementExportAccess(mysqli $conn, string $datasetKey): array
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

    if (!$allowed) {
        throw new RuntimeException('Access denied');
    }

    return [
        'user_id' => (string)($actor['user_id'] ?? ''),
        'user_name' => (string)($actor['user_name'] ?? 'System User'),
        'user_role' => $role
    ];
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid export request.');
    }

    $datasetKey = strtolower(trim((string)($payload['dataset_key'] ?? '')));
    $format = strtolower(trim((string)($payload['format'] ?? 'xlsx')));
    if (!in_array($datasetKey, ['registry_recycle_bin', 'file_registry', 'staff_due', 'file_movements', 'claims_ledger', 'tasks', 'feedback_submissions'], true)) {
        throw new RuntimeException('Unsupported dashboard dataset export.');
    }

    $actor = requireDashboardDataManagementExportAccess($conn, $datasetKey);
    $result = dmExecuteConfiguredDataExport(
        $conn,
        $actor,
        $datasetKey,
        $format,
        $payload,
        'Export generated from Dashboard Data Management.'
    );

    if (ob_get_length()) {
        ob_clean();
    }
    $dashboardExportResponseSent = true;
    echo json_encode([
        'success' => true,
        'message' => 'Export generated successfully.',
        'export' => $result
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $status = stripos($message, 'access denied') !== false ? 403 : (stripos($message, 'authentication required') !== false ? 401 : 500);
    http_response_code($status);
    if (ob_get_length()) {
        ob_clean();
    }
    $dashboardExportResponseSent = true;
    echo json_encode([
        'success' => false,
        'message' => $message
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
