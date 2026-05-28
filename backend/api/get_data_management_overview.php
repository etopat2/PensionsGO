<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';

try {
    requireAdminDataManagementAccess($conn);
    ensureDataManagementInfrastructure($conn);

    $backupPath = getBackupStoragePath();
    $exportPath = getDataExportStoragePath();
    $overview = [
        'backup_runs' => dmListBackupRuns($conn, 15),
        'export_runs' => dmListExportRuns($conn, 15),
        'cleanup_stats' => dmGetCleanupStats($conn),
        'paths' => [
            'backup_path' => dmRelativePath($backupPath),
            'export_path' => dmRelativePath($exportPath)
        ],
        'settings' => [
            'backup_retention_days' => getAppSettingInt($conn, 'backup_retention_days', 90),
            'export_retention_days' => getAppSettingInt($conn, 'export_retention_days', 90),
            'backup_include_uploads_default' => getAppSettingBool($conn, 'backup_include_uploads_default', true)
        ],
        'export_datasets' => array_values(dmGetExportDatasetConfigs($conn))
    ];

    echo json_encode(['success' => true, 'overview' => $overview], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
