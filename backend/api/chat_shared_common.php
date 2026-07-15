<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

function chatSharedJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chatSharedClean(?string $value, int $max = 255): string
{
    $value = trim((string)$value);
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function chatSharedCleanMessage(?string $value, int $max = 2000): string
{
    $value = trim((string)$value);
    $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
    $value = preg_replace("/[ \t]+/", ' ', $value) ?? '';
    $value = preg_replace("/\n{4,}/", "\n\n\n", $value) ?? '';
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function chatSharedJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function chatSharedIdentifier(string $value): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', $value) ?? '';
}

function chatSharedAddColumnIfMissing(mysqli $conn, string $tableName, string $columnName, string $definition): void
{
    $tableName = chatSharedIdentifier($tableName);
    $columnName = chatSharedIdentifier($columnName);
    if ($tableName === '' || $columnName === '') {
        return;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $tableName, $columnName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)($row['total'] ?? 0) > 0) {
            return;
        }
    } else {
        $result = $conn->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
        if ($result && $result->num_rows > 0) {
            return;
        }
    }

    $conn->query("ALTER TABLE `{$tableName}` ADD COLUMN {$definition}");
}

function chatSharedAddIndexIfMissing(mysqli $conn, string $tableName, string $indexName, string $columns): void
{
    $tableName = chatSharedIdentifier($tableName);
    $indexName = chatSharedIdentifier($indexName);
    if ($tableName === '' || $indexName === '') {
        return;
    }

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $tableName, $indexName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int)($row['total'] ?? 0) === 0) {
        $conn->query("ALTER TABLE `{$tableName}` ADD INDEX `{$indexName}` ({$columns})");
    }
}

function chatSharedAttachmentIsPreviewable(string $mime, string $fileName): bool
{
    $mime = strtolower(trim($mime));
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/')) {
        return true;
    }
    if (in_array($mime, ['application/pdf', 'text/plain', 'text/csv'], true)) {
        return true;
    }
    return in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv', 'docx', 'mp3', 'wav', 'ogg', 'webm', 'm4a'], true);
}

function chatSharedPlaybackMime(string $mime, string $fileName): string
{
    $mime = strtolower(trim($mime));
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($ext === 'docx' && ($mime === '' || $mime === 'application/octet-stream' || $mime === 'application/zip')) {
        return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    if ($ext === 'doc' && ($mime === '' || $mime === 'application/octet-stream')) {
        return 'application/msword';
    }
    if ($ext === 'webm' && ($mime === '' || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/octet-stream')) {
        return 'audio/webm';
    }
    if ($ext === 'ogg' && ($mime === '' || $mime === 'application/octet-stream')) {
        return 'audio/ogg';
    }
    if ($ext === 'mp3' && ($mime === '' || $mime === 'application/octet-stream')) {
        return 'audio/mpeg';
    }
    if ($ext === 'm4a' && ($mime === '' || $mime === 'application/octet-stream' || $mime === 'audio/x-m4a' || $mime === 'video/mp4')) {
        return 'audio/mp4';
    }
    if ($ext === 'mp4' && ($mime === '' || $mime === 'application/octet-stream')) {
        return 'audio/mp4';
    }
    if ($ext === 'wav' && ($mime === '' || $mime === 'application/octet-stream')) {
        return 'audio/wav';
    }
    return $mime ?: 'application/octet-stream';
}

function chatSharedClientNonce(?string $clientNonce): string
{
    $clientNonce = trim((string)$clientNonce);
    return preg_match('/^[a-zA-Z0-9._:-]{1,80}$/', $clientNonce) ? $clientNonce : '';
}

function chatSharedStoreUpload(mysqli $conn, array $file, array $options = []): array
{
    $storageDir = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($options['storage_dir'] ?? 'chat'));
    $prefix = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($options['prefix'] ?? 'upload'));
    $label = (string)($options['label'] ?? 'Chat upload');
    $allowedExtensions = array_values(array_filter(array_map('strtolower', (array)($options['allowed_extensions'] ?? []))));
    $allowedMimes = array_values(array_filter(array_map('strtolower', (array)($options['allowed_mimes'] ?? []))));

    $maxBytes = (int)($options['max_bytes'] ?? 0);
    $size = (int)($file['size'] ?? 0);
    if ($maxBytes > 0 && ($size <= 0 || $size > $maxBytes)) {
        throw new RuntimeException((string)($options['max_bytes_message'] ?? $label . ' is larger than the configured limit.'));
    }

    $uploadDir = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $chatDir = $uploadDir . DIRECTORY_SEPARATOR . $storageDir;
    ensureUploadDirectoryGuard($chatDir);

    $validated = assertUploadedFileIsSafe($conn, $file, $allowedExtensions, $allowedMimes, $label);
    if (isset($options['content_validator']) && is_callable($options['content_validator'])) {
        $options['content_validator']($validated);
    }

    $scanSettingKey = (string)($options['scan_setting_key'] ?? 'attachment_scan_enabled');
    $scanResult = runVirusScanOnFile($conn, (string)$validated['tmp_name'], [
        'storage_context' => (string)($options['storage_context'] ?? $storageDir),
        'file_name' => (string)$validated['original_name'],
        'file_path' => null,
        'mime_type' => (string)$validated['mime_type'],
        'scanned_by' => $options['scanned_by'] ?? ($_SESSION['userId'] ?? null),
        'scanned_by_name' => $options['scanned_by_name'] ?? ($_SESSION['userName'] ?? null),
        'scanned_by_role' => $options['scanned_by_role'] ?? ($_SESSION['userRole'] ?? null)
    ], $scanSettingKey);
    if (($scanResult['enabled'] ?? true) && in_array(($scanResult['status'] ?? ''), ['infected', 'suspicious', 'error'], true)) {
        $reason = trim((string)($scanResult['findings'] ?? 'Attachment failed the configured virus scan.'));
        throw new RuntimeException($reason !== '' ? $reason : 'Attachment failed the configured virus scan.');
    }

    $safe = sanitizeUploadedFileName((string)$validated['original_name'], 'upload.' . (string)$validated['extension']);
    $stored = $prefix . '_' . time() . '_' . bin2hex(random_bytes(8)) . '_' . $safe;
    $target = $chatDir . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file((string)$validated['tmp_name'], $target)) {
        throw new RuntimeException('Unable to store uploaded file.');
    }

    return [
        'file_name' => (string)$validated['original_name'],
        'file_path' => 'uploads/' . $storageDir . '/' . $stored,
        'absolute_path' => $target,
        'file_size' => filesize($target) ?: (int)$validated['file_size'],
        'mime_type' => (string)$validated['mime_type'],
        'extension' => (string)$validated['extension']
    ];
}
