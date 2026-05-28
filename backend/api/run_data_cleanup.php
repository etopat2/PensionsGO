<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';

try {
    $actor = requireAdminDataManagementAccess($conn);
    requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before running data cleanup.');
    ensureDataManagementInfrastructure($conn);

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid cleanup request.');
    }

    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $dryRun = array_key_exists('dry_run', $payload)
        ? filter_var($payload['dry_run'], FILTER_VALIDATE_BOOLEAN)
        : getAppSettingBool($conn, 'storage_cleanup_dry_run_default', true);
    $backupBeforeDelete = getAppSettingBool($conn, 'storage_cleanup_backup_before_delete', true);
    $backupCheckDays = 7;
    if ($backupBeforeDelete && !$dryRun && tableExists($conn, 'tb_backup_logs')) {
        $backupCheckStmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM tb_backup_logs
            WHERE status = 'success'
              AND backup_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        if ($backupCheckStmt) {
            $backupCheckStmt->bind_param("i", $backupCheckDays);
            $backupCheckStmt->execute();
            $backupRow = $backupCheckStmt->get_result()->fetch_assoc() ?: [];
            $backupCheckStmt->close();
            if ((int)($backupRow['total'] ?? 0) === 0) {
                throw new RuntimeException('Backup required before cleanup. Create a recent backup, then retry.');
            }
        }
    }
    $affected = 0;
    $message = 'No action executed.';

    switch ($action) {
        case 'purge_inactive_sessions':
            $sessionDays = max(1, getAppSettingInt($conn, 'storage_cleanup_sessions_days', 30));
            $query = "SELECT COUNT(*) AS total FROM tb_user_sessions WHERE is_active = 0 OR last_activity < DATE_SUB(NOW(), INTERVAL {$sessionDays} DAY)";
            $count = (int)(($conn->query($query)->fetch_assoc()['total'] ?? 0));
            $affected = $count;
            if (!$dryRun) {
                $conn->query("DELETE FROM tb_user_sessions WHERE is_active = 0 OR last_activity < DATE_SUB(NOW(), INTERVAL {$sessionDays} DAY)");
            }
            $message = $dryRun ? "Dry run found {$affected} inactive sessions." : "Removed {$affected} inactive sessions.";
            break;

        case 'purge_notification_queue':
            $notificationDays = max(1, getAppSettingInt($conn, 'storage_cleanup_notification_days', 30));
            $query = "SELECT COUNT(*) AS total FROM tb_notification_queue WHERE status IN ('sent','failed') AND created_at < DATE_SUB(NOW(), INTERVAL {$notificationDays} DAY)";
            $count = (int)(($conn->query($query)->fetch_assoc()['total'] ?? 0));
            $affected = $count;
            if (!$dryRun) {
                $conn->query("DELETE FROM tb_notification_queue WHERE status IN ('sent','failed') AND created_at < DATE_SUB(NOW(), INTERVAL {$notificationDays} DAY)");
            }
            $message = $dryRun ? "Dry run found {$affected} notification queue records." : "Removed {$affected} notification queue records.";
            break;

        case 'purge_import_history':
            $importDays = max(1, getAppSettingInt($conn, 'storage_cleanup_imports_days', 90));
            $query = "SELECT COUNT(*) AS total FROM tb_data_import_runs WHERE completed_at < DATE_SUB(NOW(), INTERVAL {$importDays} DAY)";
            $count = (int)(($conn->query($query)->fetch_assoc()['total'] ?? 0));
            $affected = $count;
            if (!$dryRun) {
                $conn->query("DELETE FROM tb_data_import_runs WHERE completed_at < DATE_SUB(NOW(), INTERVAL {$importDays} DAY)");
            }
            $message = $dryRun ? "Dry run found {$affected} import history records." : "Removed {$affected} import history records.";
            break;

        case 'purge_export_history':
            $exportDays = max(1, getAppSettingInt($conn, 'storage_cleanup_exports_days', 90));
            $result = $conn->query("SELECT file_path FROM tb_data_export_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$exportDays} DAY)");
            $paths = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $paths[] = $row['file_path'] ?? null;
            }
            if ($result) {
                $result->close();
            }
            $affected = count($paths);
            if (!$dryRun) {
                foreach ($paths as $path) {
                    dmDeleteFileIfExists($path);
                }
                $conn->query("DELETE FROM tb_data_export_runs WHERE created_at < DATE_SUB(NOW(), INTERVAL {$exportDays} DAY)");
            }
            $message = $dryRun ? "Dry run found {$affected} export history records." : "Removed {$affected} export history records.";
            break;

        case 'purge_backup_history':
            $backupDays = max(1, getAppSettingInt($conn, 'storage_cleanup_backups_days', 180));
            $result = $conn->query("SELECT file_path FROM tb_backup_logs WHERE backup_time < DATE_SUB(NOW(), INTERVAL {$backupDays} DAY)");
            $paths = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $paths[] = $row['file_path'] ?? null;
            }
            if ($result) {
                $result->close();
            }
            $affected = count($paths);
            if (!$dryRun) {
                foreach ($paths as $path) {
                    dmDeleteFileIfExists($path);
                }
                $conn->query("DELETE FROM tb_backup_logs WHERE backup_time < DATE_SUB(NOW(), INTERVAL {$backupDays} DAY)");
            }
            $message = $dryRun ? "Dry run found {$affected} backup history records." : "Removed {$affected} backup history records.";
            break;

        case 'purge_orphan_documents':
            ensureStaffDocumentsTable($conn);
            $orphanDays = max(1, getAppSettingInt($conn, 'storage_cleanup_orphan_documents_days', 30));
            $result = $conn->query("
                SELECT d.document_id, d.file_path
                FROM tb_staff_documents d
                LEFT JOIN tb_fileregistry r ON r.regNo = d.regNo
                LEFT JOIN tb_staffdue s ON s.id = d.staffdue_id
                WHERE r.id IS NULL
                  AND s.id IS NULL
                  AND d.uploaded_at < DATE_SUB(NOW(), INTERVAL {$orphanDays} DAY)
            ");
            $rows = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $rows[] = $row;
            }
            if ($result) {
                $result->close();
            }
            $affected = count($rows);
            if (!$dryRun && $affected > 0) {
                foreach ($rows as $row) {
                    dmDeleteFileIfExists($row['file_path'] ?? null);
                    $deleteStmt = $conn->prepare("DELETE FROM tb_staff_documents WHERE document_id = ? LIMIT 1");
                    if ($deleteStmt) {
                        $docId = (int)($row['document_id'] ?? 0);
                        $deleteStmt->bind_param("i", $docId);
                        $deleteStmt->execute();
                        $deleteStmt->close();
                    }
                }
            }
            $message = $dryRun ? "Dry run found {$affected} orphan documents." : "Removed {$affected} orphan documents.";
            break;

        default:
            throw new RuntimeException('Unknown cleanup action.');
    }

    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => $dryRun ? 'data_cleanup_preview' : 'data_cleanup_run',
        'entity_type' => 'data_cleanup',
        'entity_id' => $action,
        'details' => [
            'action' => $action,
            'affected_records' => $affected,
            'dry_run' => $dryRun
        ]
    ]);

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'notice',
            'log_category' => 'storage_cleanup',
            'event_code' => $dryRun ? 'cleanup_preview' : 'cleanup_run',
            'message' => $message,
            'context' => [
                'action' => $action,
                'affected_records' => $affected,
                'dry_run' => $dryRun
            ],
            'actor_id' => $actor['user_id'],
            'actor_name' => $actor['user_name'],
            'actor_role' => $actor['user_role']
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'affected_records' => $affected
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
