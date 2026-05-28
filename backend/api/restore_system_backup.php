<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';

function dmExecuteSqlScript(mysqli $conn, string $sql): void
{
    foreach (dmSplitSqlStatements($sql) as $statement) {
        dmExecuteSqlStatement($conn, $statement);
    }
}

function dmSplitSqlStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($blockComment) {
            if ($char === '*' && $next === '/') {
                $blockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $next === '-' && ($i === 0 || $sql[$i - 1] === "\n" || $sql[$i - 1] === "\r")) {
                $lineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $lineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble && !$inBacktick) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inSingle = !$inSingle;
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '"' && !$inSingle && !$inBacktick) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) {
                $inDouble = !$inDouble;
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
            $buffer .= $char;
            continue;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function dmExecuteSqlStatement(mysqli $conn, string $statement): void
{
    $statement = trim($statement);
    if ($statement === '') {
        return;
    }

    $maxStatementBytes = 256 * 1024;
    if (preg_match('/^INSERT\\s+INTO\\s.+?\\sVALUES\\s/is', $statement) && strlen($statement) > $maxStatementBytes) {
        foreach (dmChunkInsertStatement($statement, $maxStatementBytes) as $chunk) {
            if (!$conn->query($chunk)) {
                throw new RuntimeException($conn->error ?: 'Restore insert execution failed.');
            }
        }
        return;
    }

    if (!$conn->query($statement)) {
        throw new RuntimeException($conn->error ?: 'Restore statement execution failed.');
    }
}

function dmChunkInsertStatement(string $statement, int $maxBytes = 262144): array
{
    if (!preg_match('/^(INSERT\\s+INTO\\s.+?\\sVALUES\\s)(.+)$/is', $statement, $matches)) {
        return [$statement];
    }

    $prefix = $matches[1];
    $valuesSql = rtrim(trim($matches[2]), ';');
    $tuples = [];
    $buffer = '';
    $length = strlen($valuesSql);
    $depth = 0;
    $inSingle = false;
    $inDouble = false;
    $escaped = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $valuesSql[$i];

        if ($inSingle || $inDouble) {
            $buffer .= $char;
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === "'" && $inSingle) {
                $inSingle = false;
            } elseif ($char === '"' && $inDouble) {
                $inDouble = false;
            }
            continue;
        }

        if ($char === "'") {
            $inSingle = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '"') {
            $inDouble = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            $buffer .= $char;
            continue;
        }
        if ($char === ')') {
            $depth = max(0, $depth - 1);
            $buffer .= $char;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $tuple = trim($buffer);
            if ($tuple !== '') {
                $tuples[] = $tuple;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tuple = trim($buffer);
    if ($tuple !== '') {
        $tuples[] = $tuple;
    }

    if (count($tuples) <= 1) {
        return [$prefix . $valuesSql . ';'];
    }

    $chunks = [];
    $current = [];
    foreach ($tuples as $tupleItem) {
        $candidate = $prefix . implode(",\n", array_merge($current, [$tupleItem])) . ';';
        if (!empty($current) && strlen($candidate) > $maxBytes) {
            $chunks[] = $prefix . implode(",\n", $current) . ';';
            $current = [$tupleItem];
            continue;
        }
        $current[] = $tupleItem;
    }

    if (!empty($current)) {
        $chunks[] = $prefix . implode(",\n", $current) . ';';
    }

    return $chunks;
}

try {
    $actor = requireAdminDataManagementAccess($conn);
    requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before restoring a backup.');
    ensureDataManagementInfrastructure($conn);

    $backupPath = '';
    $uploadedTemp = '';
    if (!empty($_POST['backup_file_name'])) {
        $backupPath = getBackupStoragePath() . DIRECTORY_SEPARATOR . basename((string)$_POST['backup_file_name']);
    } elseif (!empty($_FILES['restore_file']['tmp_name'])) {
        enforceUploadedFileSizeLimit($conn, $_FILES['restore_file'], 'Backup archive');
        $uploadedName = (string)($_FILES['restore_file']['name'] ?? 'backup.zip');
        if (strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION)) !== 'zip') {
            throw new RuntimeException('Backup restore only accepts .zip archives.');
        }
        $uploadedTemp = (string)$_FILES['restore_file']['tmp_name'];
        $backupPath = $uploadedTemp;
    } else {
        throw new RuntimeException('Select an existing backup or upload a backup archive to restore.');
    }

    if (!is_file($backupPath)) {
        throw new RuntimeException('Selected backup file was not found.');
    }

    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is required for backup restore.');
    }

    enforceZipArchiveSafety($conn, $backupPath, 'Backup archive');

    $zip = new ZipArchive();
    if ($zip->open($backupPath) !== true) {
        throw new RuntimeException('Unable to open backup archive.');
    }

    $dbSql = $zip->getFromName('database.sql');
    $metadataJson = $zip->getFromName('metadata.json');
    $restoreFiles = !empty($_POST['restore_files']);

    if ($dbSql === false && !$restoreFiles) {
        $zip->close();
        throw new RuntimeException('The backup archive does not contain a restorable database payload.');
    }

    if ($dbSql !== false) {
        dmExecuteSqlScript($conn, (string)$dbSql);
    }

    if ($restoreFiles) {
        $uploadsRoot = realpath(__DIR__ . '/../uploads');
        if ($uploadsRoot === false) {
            $uploadsRoot = __DIR__ . '/../uploads';
            @mkdir($uploadsRoot, 0775, true);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string)$zip->getNameIndex($i);
            if (!str_starts_with($entryName, 'uploads/')) {
                continue;
            }
            $relative = ltrim(substr($entryName, strlen('uploads/')), '/');
            if ($relative === '') {
                continue;
            }
            $targetPath = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (str_ends_with($entryName, '/')) {
                @mkdir($targetPath, 0775, true);
                continue;
            }
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }
            $contents = $zip->getFromIndex($i);
            if ($contents !== false) {
                file_put_contents($targetPath, $contents);
            }
        }
    }
    $zip->close();

    $metadata = [];
    if ($metadataJson !== false) {
        $decoded = json_decode((string)$metadataJson, true);
        if (is_array($decoded)) {
            $metadata = $decoded;
        }
    }

    recordBackupLog($conn, [
        'backup_label' => 'Restore from backup',
        'backup_type' => 'restore_point',
        'backup_scope' => 'full_system',
        'file_name' => basename($backupPath),
        'file_path' => $backupPath,
        'include_uploads' => !empty($restoreFiles),
        'status' => 'restored',
        'notes' => 'Backup restored through Admin Console.',
        'created_by' => $actor['user_id'],
        'created_by_name' => $actor['user_name'],
        'created_by_role' => $actor['user_role'],
        'restored_at' => date('Y-m-d H:i:s'),
        'restored_by' => $actor['user_id']
    ]);

    logAuditEvent($conn, [
        'actor_id' => $actor['user_id'],
        'actor_name' => $actor['user_name'],
        'actor_role' => $actor['user_role'],
        'action' => 'system_backup_restored',
        'entity_type' => 'data_backup',
        'entity_id' => basename($backupPath),
        'details' => [
            'restore_files' => (bool)$restoreFiles,
            'metadata' => $metadata
        ]
    ]);

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'warning',
            'log_category' => 'backup_restore',
            'event_code' => 'backup_restored',
            'message' => 'Backup restored through the Admin Console.',
            'context' => [
                'backup_file' => basename($backupPath),
                'restore_files' => (bool)$restoreFiles
            ],
            'actor_id' => $actor['user_id'],
            'actor_name' => $actor['user_name'],
            'actor_role' => $actor['user_role']
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Backup restored successfully.',
        'metadata' => $metadata
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($actor) && function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'error',
            'log_category' => 'backup_restore',
            'event_code' => 'backup_restore_failed',
            'message' => 'Backup restore failed.',
            'context' => [
                'error' => $e->getMessage()
            ],
            'actor_id' => $actor['user_id'] ?? null,
            'actor_name' => $actor['user_name'] ?? null,
            'actor_role' => $actor['user_role'] ?? null
        ]);
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
