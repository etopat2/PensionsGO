<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';

function dmSqlLiteral(mysqli $conn, $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_numeric($value) && !preg_match('/^0\d+$/', (string)$value)) {
        return (string)$value;
    }
    return "'" . $conn->real_escape_string((string)$value) . "'";
}

function dmGenerateDatabaseSqlDump(mysqli $conn): string
{
    $databaseRow = $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc();
    $databaseName = (string)($databaseRow['db_name'] ?? 'pension_db');
    $tables = [];
    $tableResult = $conn->query('SHOW TABLES');
    while ($tableResult && ($row = $tableResult->fetch_array(MYSQLI_NUM))) {
        $tables[] = (string)$row[0];
    }
    if ($tableResult) {
        $tableResult->close();
    }

    $sql = [];
    $sql[] = '-- UPS PensionsGo full system backup';
    $sql[] = '-- Generated at ' . date('Y-m-d H:i:s');
    $sql[] = '-- Database: ' . $databaseName;
    $sql[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $sql[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
    $sql[] = 'START TRANSACTION;';

    foreach ($tables as $table) {
        $createResult = $conn->query("SHOW CREATE TABLE `{$table}`");
        $createRow = $createResult ? $createResult->fetch_assoc() : null;
        if ($createResult) {
            $createResult->close();
        }
        if (!$createRow || empty($createRow['Create Table'])) {
            continue;
        }

        $sql[] = '';
        $sql[] = '--';
        $sql[] = '-- Structure for table `' . $table . '`';
        $sql[] = '--';
        $sql[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
        $sql[] = $createRow['Create Table'] . ';';

        $rowResult = $conn->query("SELECT * FROM `{$table}`");
        if (!$rowResult || $rowResult->num_rows === 0) {
            if ($rowResult) {
                $rowResult->close();
            }
            continue;
        }

        $fields = [];
        foreach ($rowResult->fetch_fields() as $field) {
            $fields[] = '`' . $field->name . '`';
        }

        $insertChunks = [];
        $chunkBytes = 0;
        $maxInsertChunkBytes = 128 * 1024;
        while ($row = $rowResult->fetch_assoc()) {
            $values = [];
            foreach ($row as $value) {
                $values[] = dmSqlLiteral($conn, $value);
            }
            $tuple = '(' . implode(', ', $values) . ')';
            $tupleBytes = strlen($tuple) + 2;
            if (!empty($insertChunks) && ($chunkBytes + $tupleBytes) > $maxInsertChunkBytes) {
                $sql[] = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES ' . implode(",\n", $insertChunks) . ';';
                $insertChunks = [];
                $chunkBytes = 0;
            }
            $insertChunks[] = $tuple;
            $chunkBytes += $tupleBytes;
        }
        if (!empty($insertChunks)) {
            $sql[] = 'INSERT INTO `' . $table . '` (' . implode(', ', $fields) . ') VALUES ' . implode(",\n", $insertChunks) . ';';
        }
        $rowResult->close();
    }

    $sql[] = 'COMMIT;';
    $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';

    return implode("\n", $sql) . "\n";
}

try {
    $actor = requireAdminDataManagementAccess($conn);
    requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before creating a system backup.');
    ensureDataManagementInfrastructure($conn);

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid request payload.');
    }

    $label = trim((string)($payload['backup_label'] ?? ''));
    $scope = strtolower(trim((string)($payload['backup_scope'] ?? 'full_system')));
    $includeUploads = !empty($payload['include_uploads']);
    if (!in_array($scope, ['full_system', 'database_only', 'uploads_only'], true)) {
        $scope = 'full_system';
    }
    if ($scope === 'database_only') {
        $includeUploads = false;
    }
    if ($scope === 'uploads_only') {
        $includeUploads = true;
    }

    $timestamp = date('Ymd_His');
    $backupDir = getBackupStoragePath();
    $fileName = 'ups_pensionsgo_backup_' . $timestamp . '.zip';
    $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileName;

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required for backup creation.');
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create backup archive.');
    }

    $metadata = [
        'app' => 'UPS PensionsGo',
        'generated_at' => date('c'),
        'generated_by' => $actor['user_name'],
        'generated_role' => $actor['user_role'],
        'backup_scope' => $scope,
        'include_uploads' => $includeUploads,
        'database' => $conn->query('SELECT DATABASE() AS db_name')->fetch_assoc()['db_name'] ?? ''
    ];

    if ($scope !== 'uploads_only') {
        $zip->addFromString('database.sql', dmGenerateDatabaseSqlDump($conn));
    }

    if ($includeUploads) {
        dmRecursiveAddToZip($zip, __DIR__ . '/../uploads', 'uploads');
    }

    $zip->addFromString('metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $size = is_file($filePath) ? (int)filesize($filePath) : 0;
    $checksum = is_file($filePath) ? hash_file('sha256', $filePath) : null;

    recordBackupLog($conn, [
        'backup_label' => $label !== '' ? $label : 'System Backup ' . $timestamp,
        'backup_type' => 'manual',
        'backup_scope' => $scope,
        'file_name' => $fileName,
        'file_path' => $filePath,
        'file_size_bytes' => $size,
        'checksum_sha256' => $checksum,
        'include_uploads' => $includeUploads,
        'status' => 'success',
        'notes' => 'Manual backup generated from Admin Console.',
        'created_by' => $actor['user_id'],
        'created_by_name' => $actor['user_name'],
        'created_by_role' => $actor['user_role']
    ]);

    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => 'system_backup_created',
        'entity_type' => 'data_backup',
        'entity_id' => $fileName,
        'details' => [
            'backup_scope' => $scope,
            'include_uploads' => $includeUploads,
            'file_size_bytes' => $size
        ]
    ]);

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'notice',
            'log_category' => 'backup',
            'event_code' => 'backup_created',
            'message' => 'System backup created successfully.',
            'context' => [
                'backup_scope' => $scope,
                'include_uploads' => $includeUploads,
                'file_name' => $fileName,
                'file_size_bytes' => $size
            ],
            'actor_id' => $actor['user_id'],
            'actor_name' => $actor['user_name'],
            'actor_role' => $actor['user_role']
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Backup created successfully.',
        'backup' => [
            'file_name' => $fileName,
            'file_size_bytes' => $size,
            'download_url' => '../backend/api/download_data_artifact.php?type=backup&file=' . rawurlencode($fileName)
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($actor) && function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'error',
            'log_category' => 'backup',
            'event_code' => 'backup_creation_failed',
            'message' => 'System backup creation failed.',
            'context' => [
                'error' => $e->getMessage()
            ],
            'actor_id' => $actor['user_id'] ?? null,
            'actor_name' => $actor['user_name'] ?? null,
            'actor_role' => $actor['user_role'] ?? null
        ]);
    }
    if (isset($actor)) {
        recordBackupLog($conn, [
            'backup_label' => trim((string)($payload['backup_label'] ?? '')) ?: 'Failed backup',
            'backup_type' => 'manual',
            'backup_scope' => $scope ?? 'full_system',
            'file_name' => $fileName ?? null,
            'file_path' => $filePath ?? null,
            'include_uploads' => !empty($includeUploads),
            'status' => 'failed',
            'notes' => $e->getMessage(),
            'created_by' => $actor['user_id'] ?? null,
            'created_by_name' => $actor['user_name'] ?? null,
            'created_by_role' => $actor['user_role'] ?? null
        ]);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
