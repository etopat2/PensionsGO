<?php
require_once __DIR__ . '/config.php';

function ensureMessageStorageSnapshotsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_message_storage_snapshots (
        snapshot_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        snapshot_date DATE NOT NULL,
        snapshot_type ENUM('auto','manual') NOT NULL DEFAULT 'auto',
        status ENUM('created','failed') NOT NULL DEFAULT 'created',
        file_name VARCHAR(255) DEFAULT NULL,
        file_path VARCHAR(500) DEFAULT NULL,
        file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        message_count INT NOT NULL DEFAULT 0,
        attachment_count INT NOT NULL DEFAULT 0,
        total_storage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        notes TEXT DEFAULT NULL,
        created_by VARCHAR(50) DEFAULT NULL,
        created_by_name VARCHAR(150) DEFAULT NULL,
        created_by_role VARCHAR(80) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_message_snapshot_date (snapshot_date),
        INDEX idx_message_snapshot_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getMessageBackupStoragePath(): string {
    $dir = getBackupStoragePath() . DIRECTORY_SEPARATOR . 'messages';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function buildMessageStorageSnapshotPayload(mysqli $conn): array {
    $payload = [
        'generated_at' => date('c'),
        'messages' => [],
        'attachments' => [],
        'summary' => [
            'message_count' => 0,
            'attachment_count' => 0,
            'message_bytes' => 0,
            'attachment_bytes' => 0
        ]
    ];

    if (tableExists($conn, 'tb_messages')) {
        $result = $conn->query("SELECT message_id, sender_id, subject, message_type, is_urgent, created_at, is_deleted_by_sender, deleted_by_sender_at, message_text FROM tb_messages ORDER BY message_id DESC LIMIT 5000");
        while ($result && ($row = $result->fetch_assoc())) {
            $row['message_text'] = decodeMessageText($row['message_text'] ?? '');
            $payload['summary']['message_bytes'] += strlen((string)$row['message_text']);
            $payload['messages'][] = $row;
        }
        if ($result) {
            $result->close();
        }
        $payload['summary']['message_count'] = count($payload['messages']);
    }

    if (tableExists($conn, 'tb_message_attachments')) {
        $result = $conn->query("SELECT attachment_id, message_id, file_name, file_path, file_size, mime_type, uploaded_at FROM tb_message_attachments ORDER BY attachment_id DESC LIMIT 5000");
        while ($result && ($row = $result->fetch_assoc())) {
            $payload['summary']['attachment_bytes'] += (int)($row['file_size'] ?? 0);
            $payload['attachments'][] = $row;
        }
        if ($result) {
            $result->close();
        }
        $payload['summary']['attachment_count'] = count($payload['attachments']);
    }

    return $payload;
}

function createMessageStorageSnapshot(mysqli $conn, array $meta = []): array {
    ensureMessageStorageSnapshotsTable($conn);
    $snapshotPayload = buildMessageStorageSnapshotPayload($conn);
    $timestamp = date('Ymd_His');
    $fileName = 'message_storage_snapshot_' . $timestamp . '.json';
    $filePath = getMessageBackupStoragePath() . DIRECTORY_SEPARATOR . $fileName;
    $json = json_encode([
        'app' => 'UPS PensionsGo',
        'meta' => $meta,
        'snapshot' => $snapshotPayload
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Unable to encode message storage snapshot.');
    }
    if (file_put_contents($filePath, $json) === false) {
        throw new RuntimeException('Unable to write message storage snapshot file.');
    }

    $messageCount = (int)($snapshotPayload['summary']['message_count'] ?? 0);
    $attachmentCount = (int)($snapshotPayload['summary']['attachment_count'] ?? 0);
    $totalStorage = (int)($snapshotPayload['summary']['message_bytes'] ?? 0) + (int)($snapshotPayload['summary']['attachment_bytes'] ?? 0);
    $size = (int)(filesize($filePath) ?: 0);

    $stmt = $conn->prepare("INSERT INTO tb_message_storage_snapshots (
        snapshot_date, snapshot_type, status, file_name, file_path, file_size_bytes,
        message_count, attachment_count, total_storage_bytes, notes,
        created_by, created_by_name, created_by_role
    ) VALUES (CURDATE(), ?, 'created', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Unable to record message storage snapshot.');
    }
    $snapshotType = ($meta['snapshot_type'] ?? 'auto') === 'manual' ? 'manual' : 'auto';
    $notes = $meta['notes'] ?? 'Message storage snapshot created.';
    $createdBy = $meta['created_by'] ?? ($_SESSION['userId'] ?? null);
    $createdByName = $meta['created_by_name'] ?? ($_SESSION['userName'] ?? null);
    $createdByRole = $meta['created_by_role'] ?? ($_SESSION['userRole'] ?? null);
    $stmt->bind_param('sssiiisssss', $snapshotType, $fileName, $filePath, $size, $messageCount, $attachmentCount, $totalStorage, $notes, $createdBy, $createdByName, $createdByRole);
    $stmt->execute();
    $snapshotId = (int)$stmt->insert_id;
    $stmt->close();

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'info',
            'log_category' => 'message_snapshot',
            'event_code' => 'message_snapshot_created',
            'message' => 'Message storage snapshot created.',
            'context' => [
                'snapshot_id' => $snapshotId,
                'snapshot_type' => $snapshotType,
                'message_count' => $messageCount,
                'attachment_count' => $attachmentCount,
                'file_name' => $fileName
            ],
            'actor_id' => $createdBy,
            'actor_name' => $createdByName,
            'actor_role' => $createdByRole
        ]);
    }

    return [
        'snapshot_id' => $snapshotId,
        'file_name' => $fileName,
        'file_path' => $filePath,
        'file_size_bytes' => $size,
        'message_count' => $messageCount,
        'attachment_count' => $attachmentCount,
        'total_storage_bytes' => $totalStorage,
        'snapshot_type' => $snapshotType,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

function maybeCreateMessageStorageSnapshot(mysqli $conn, array $meta = []): ?array {
    if (!getAppSettingBool($conn, 'message_backup_enabled', false)) {
        return null;
    }
    ensureMessageStorageSnapshotsTable($conn);
    $existingStmt = $conn->prepare("SELECT snapshot_id FROM tb_message_storage_snapshots WHERE snapshot_date = CURDATE() AND snapshot_type = 'auto' LIMIT 1");
    if ($existingStmt) {
        $existingStmt->execute();
        $exists = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();
        if ($exists) {
            return null;
        }
    }
    $meta['snapshot_type'] = 'auto';
    $meta['notes'] = $meta['notes'] ?? 'Automatic daily message snapshot.';
    return createMessageStorageSnapshot($conn, $meta);
}

function getMessageStorageRuntimeSummary(mysqli $conn): array {
    ensureMessageStorageSnapshotsTable($conn);
    $summary = [
        'message_count' => 0,
        'attachment_count' => 0,
        'soft_deleted_sender' => 0,
        'soft_deleted_recipient' => 0,
        'snapshot_count' => 0,
        'last_snapshot_at' => null,
        'snapshots' => []
    ];
    if (tableExists($conn, 'tb_messages')) {
        $row = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN is_deleted_by_sender = 1 THEN 1 ELSE 0 END) AS deleted_sender_total FROM tb_messages")->fetch_assoc();
        $summary['message_count'] = (int)($row['total'] ?? 0);
        $summary['soft_deleted_sender'] = (int)($row['deleted_sender_total'] ?? 0);
    }
    if (tableExists($conn, 'tb_message_recipients')) {
        $row = $conn->query("SELECT SUM(CASE WHEN is_deleted = 1 THEN 1 ELSE 0 END) AS deleted_recipient_total FROM tb_message_recipients")->fetch_assoc();
        $summary['soft_deleted_recipient'] = (int)($row['deleted_recipient_total'] ?? 0);
    }
    if (tableExists($conn, 'tb_message_attachments')) {
        $row = $conn->query("SELECT COUNT(*) AS total FROM tb_message_attachments")->fetch_assoc();
        $summary['attachment_count'] = (int)($row['total'] ?? 0);
    }
    $row = $conn->query("SELECT COUNT(*) AS total, MAX(created_at) AS last_snapshot_at FROM tb_message_storage_snapshots WHERE status = 'created'")->fetch_assoc();
    $summary['snapshot_count'] = (int)($row['total'] ?? 0);
    $summary['last_snapshot_at'] = $row['last_snapshot_at'] ?? null;
    $result = $conn->query("SELECT snapshot_id, snapshot_type, file_name, file_size_bytes, message_count, attachment_count, total_storage_bytes, created_at FROM tb_message_storage_snapshots ORDER BY created_at DESC LIMIT 8");
    while ($result && ($row = $result->fetch_assoc())) {
        $summary['snapshots'][] = $row;
    }
    if ($result) {
        $result->close();
    }
    return $summary;
}

function ensureFileScanLogsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_file_scan_logs (
        scan_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        storage_context VARCHAR(80) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) DEFAULT NULL,
        file_hash VARCHAR(64) DEFAULT NULL,
        mime_type VARCHAR(120) DEFAULT NULL,
        scan_engine VARCHAR(80) NOT NULL DEFAULT 'heuristic',
        scan_status ENUM('clean','infected','suspicious','error','skipped') NOT NULL DEFAULT 'clean',
        findings TEXT DEFAULT NULL,
        scanned_by VARCHAR(50) DEFAULT NULL,
        scanned_by_name VARCHAR(150) DEFAULT NULL,
        scanned_by_role VARCHAR(80) DEFAULT NULL,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_file_scan_context (storage_context, scanned_at),
        INDEX idx_file_scan_status (scan_status, scanned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function detectAvailableVirusScanner(): array {
    static $scanner = null;
    if ($scanner !== null) {
        return $scanner;
    }

    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $lookupPrefix = $isWindows ? 'where ' : 'command -v ';
    $lookupStderr = $isWindows ? ' 2>NUL' : ' 2>/dev/null';
    $databaseArg = '';
    if (defined('CLAMAV_DATABASE_PATH')) {
        $databasePath = trim((string)CLAMAV_DATABASE_PATH);
        if ($databasePath !== '') {
            $databaseArg = ' --database="' . str_replace('"', '\"', $databasePath) . '"';
        }
    }

    $configuredCandidates = [];
    if (trim((string)CLAMAV_CLAMDSCAN_PATH) !== '') {
        $configuredCandidates[] = [
            'engine' => 'clamdscan',
            'path' => trim((string)CLAMAV_CLAMDSCAN_PATH),
            'command' => '"' . trim((string)CLAMAV_CLAMDSCAN_PATH) . '"' . $databaseArg . ' --no-summary --stdout '
        ];
    }
    if (trim((string)CLAMAV_CLAMSCAN_PATH) !== '') {
        $configuredCandidates[] = [
            'engine' => 'clamscan',
            'path' => trim((string)CLAMAV_CLAMSCAN_PATH),
            'command' => '"' . trim((string)CLAMAV_CLAMSCAN_PATH) . '"' . $databaseArg . ' --no-summary --stdout '
        ];
    }

    $pathCandidates = [
        ['engine' => 'clamdscan', 'binary' => 'clamdscan', 'command' => 'clamdscan' . $databaseArg . ' --no-summary --stdout '],
        ['engine' => 'clamscan', 'binary' => 'clamscan', 'command' => 'clamscan' . $databaseArg . ' --no-summary --stdout ']
    ];

    if (CLAMAV_PREFERRED_ENGINE === 'clamscan') {
        usort($pathCandidates, fn($a, $b) => $a['engine'] === 'clamscan' ? -1 : 1);
    } elseif (CLAMAV_PREFERRED_ENGINE === 'clamdscan') {
        usort($pathCandidates, fn($a, $b) => $a['engine'] === 'clamdscan' ? -1 : 1);
    }

    foreach ($configuredCandidates as $candidate) {
        $path = $candidate['path'];
        if ($path !== '' && file_exists($path)) {
            $scanner = ['engine' => $candidate['engine'], 'command' => $candidate['command'], 'available' => true];
            return $scanner;
        }
    }

    $candidates = array_map(function ($candidate) use ($lookupPrefix, $lookupStderr) {
        $candidate['lookup'] = $lookupPrefix . $candidate['binary'] . $lookupStderr;
        return $candidate;
    }, $pathCandidates);

    foreach ($candidates as $candidate) {
        $output = @shell_exec($candidate['lookup']);
        if (is_string($output) && trim($output) !== '') {
            $scanner = ['engine' => $candidate['engine'], 'command' => $candidate['command'], 'available' => true];
            return $scanner;
        }
    }
    $scanner = ['engine' => 'heuristic', 'command' => null, 'available' => false];
    return $scanner;
}

function heuristicVirusScan(string $filePath, string $fileName = ''): array {
    $findings = [];
    $ext = strtolower(pathinfo($fileName ?: $filePath, PATHINFO_EXTENSION));
    $dangerousExt = ['exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'ps1', 'vbs', 'js', 'jse', 'msi', 'php', 'phtml', 'phar', 'sh'];
    if (in_array($ext, $dangerousExt, true)) {
        $findings[] = 'Executable or script extension detected.';
    }
    $sample = @file_get_contents($filePath, false, null, 0, 8192);
    if (is_string($sample) && $sample !== '') {
        if (strncmp($sample, 'MZ', 2) === 0 && !in_array($ext, ['exe', 'dll'], true)) {
            $findings[] = 'Windows executable header detected in a non-executable file.';
        }
        foreach (['<script', 'powershell', 'WScript.Shell', 'cmd.exe', 'shell_exec', 'base64_decode('] as $indicator) {
            if (stripos($sample, $indicator) !== false) {
                $findings[] = 'Suspicious code pattern detected: ' . $indicator;
            }
        }
    }
    return [
        'engine' => 'heuristic',
        'status' => empty($findings) ? 'clean' : 'suspicious',
        'findings' => implode(' ', $findings)
    ];
}

function runVirusScanOnFile(mysqli $conn, string $filePath, array $context = [], string $settingKey = 'attachment_scan_enabled'): array {
    ensureFileScanLogsTable($conn);
    if (!getAppSettingBool($conn, $settingKey, false)) {
        return ['enabled' => false, 'engine' => 'disabled', 'status' => 'skipped', 'findings' => 'Virus scan is disabled.'];
    }

    $scanner = detectAvailableVirusScanner();
    $fileName = (string)($context['file_name'] ?? basename($filePath));
    $mimeType = (string)($context['mime_type'] ?? (@mime_content_type($filePath) ?: 'application/octet-stream'));
    $fileHash = @hash_file('sha256', $filePath) ?: null;
    $status = 'clean';
    $findings = '';
    $engine = $scanner['engine'];

    if (!empty($scanner['available']) && !empty($scanner['command'])) {
        $output = [];
        $exitCode = 0;
        @exec($scanner['command'] . escapeshellarg($filePath) . ' 2>&1', $output, $exitCode);
        $scanOutput = trim(implode("\n", $output));
        $findings = $scanOutput;
        if ($exitCode === 1) {
            $status = 'infected';
        } elseif ($exitCode !== 0) {
            $heuristic = heuristicVirusScan($filePath, $fileName);
            $status = $heuristic['status'];
            $findings = trim(($scanOutput ? $scanOutput . ' ' : '') . $heuristic['findings']);
            $engine .= '+heuristic';
        }
    } else {
        $heuristic = heuristicVirusScan($filePath, $fileName);
        $status = $heuristic['status'];
        $findings = $heuristic['findings'];
        $engine = $heuristic['engine'];
    }

    $stmt = $conn->prepare("INSERT INTO tb_file_scan_logs (
        storage_context, file_name, file_path, file_hash, mime_type,
        scan_engine, scan_status, findings, scanned_by, scanned_by_name, scanned_by_role
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $storageContext = (string)($context['storage_context'] ?? 'attachment');
        $relativePath = (string)($context['file_path'] ?? $filePath);
        $scannedBy = $context['scanned_by'] ?? ($_SESSION['userId'] ?? null);
        $scannedByName = $context['scanned_by_name'] ?? ($_SESSION['userName'] ?? null);
        $scannedByRole = $context['scanned_by_role'] ?? ($_SESSION['userRole'] ?? null);
        $stmt->bind_param('sssssssssss', $storageContext, $fileName, $relativePath, $fileHash, $mimeType, $engine, $status, $findings, $scannedBy, $scannedByName, $scannedByRole);
        $stmt->execute();
        $stmt->close();
    }

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => $status === 'clean' ? 'info' : ($status === 'infected' ? 'error' : 'warning'),
            'log_category' => 'security_scan',
            'event_code' => 'file_scan_' . $status,
            'message' => 'File security scan completed for uploaded content.',
            'context' => [
                'storage_context' => $context['storage_context'] ?? 'attachment',
                'file_name' => $fileName,
                'scan_engine' => $engine,
                'scan_status' => $status,
                'findings' => $findings
            ]
        ]);
    }

    return [
        'enabled' => true,
        'engine' => $engine,
        'status' => $status,
        'findings' => $findings,
        'file_hash' => $fileHash,
        'mime_type' => $mimeType
    ];
}

function getAttachmentScanRuntime(mysqli $conn): array {
    ensureFileScanLogsTable($conn);
    $scanner = detectAvailableVirusScanner();
    $summary = [
        'engine' => $scanner['engine'],
        'native_available' => !empty($scanner['available']),
        'total_scans' => 0,
        'infected_count' => 0,
        'suspicious_count' => 0,
        'last_scan_at' => null,
        'recent' => []
    ];
    $row = $conn->query("SELECT COUNT(*) AS total, SUM(CASE WHEN scan_status = 'infected' THEN 1 ELSE 0 END) AS infected_total, SUM(CASE WHEN scan_status = 'suspicious' THEN 1 ELSE 0 END) AS suspicious_total, MAX(scanned_at) AS last_scan_at FROM tb_file_scan_logs")->fetch_assoc();
    $summary['total_scans'] = (int)($row['total'] ?? 0);
    $summary['infected_count'] = (int)($row['infected_total'] ?? 0);
    $summary['suspicious_count'] = (int)($row['suspicious_total'] ?? 0);
    $summary['last_scan_at'] = $row['last_scan_at'] ?? null;
    $result = $conn->query("SELECT storage_context, file_name, scan_engine, scan_status, findings, scanned_at FROM tb_file_scan_logs ORDER BY scanned_at DESC LIMIT 8");
    while ($result && ($row = $result->fetch_assoc())) {
        $summary['recent'][] = $row;
    }
    if ($result) {
        $result->close();
    }
    return $summary;
}

function ensureNotificationDigestRunsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_notification_digest_runs (
        digest_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        digest_date DATE NOT NULL,
        run_type ENUM('scheduled','manual','preview') NOT NULL DEFAULT 'scheduled',
        recipient VARCHAR(255) DEFAULT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        summary_json LONGTEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by VARCHAR(50) DEFAULT NULL,
        created_by_name VARCHAR(150) DEFAULT NULL,
        created_by_role VARCHAR(80) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notification_digest_date (digest_date),
        INDEX idx_notification_digest_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @ $conn->query("ALTER TABLE tb_notification_digest_runs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'queued'");
}

function resolveNotificationDigestRecipient(mysqli $conn): ?string {
    $recipient = trim(getAppSettingString($conn, 'notify_test_recipient', ''));
    if ($recipient !== '') {
        return $recipient;
    }
    $recipient = trim(getAppSettingString($conn, 'security_alert_email', ''));
    if ($recipient !== '') {
        return $recipient;
    }
    $recipient = trim(getAppSettingString($conn, 'support_email', ''));
    if ($recipient !== '') {
        return $recipient;
    }
    if (tableExists($conn, 'tb_users')) {
        $result = $conn->query("
            SELECT userEmail
            FROM tb_users
            WHERE LOWER(TRIM(COALESCE(userRole, ''))) = 'admin'
              AND userEmail IS NOT NULL
              AND TRIM(userEmail) <> ''
            ORDER BY userId ASC
            LIMIT 1
        ");
        if ($result && ($row = $result->fetch_assoc())) {
            $candidate = trim((string)($row['userEmail'] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }
    return null;
}

function buildAdminDailyDigest(mysqli $conn): array {
    $summary = [
        'active_users' => tableExists($conn, 'tb_user_sessions') ? (int)($conn->query("SELECT COUNT(DISTINCT user_id) AS total FROM tb_user_sessions WHERE is_active = 1")->fetch_assoc()['total'] ?? 0) : 0,
        'queued_notifications' => tableExists($conn, 'tb_notification_queue') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_notification_queue WHERE status = 'queued'")->fetch_assoc()['total'] ?? 0) : 0,
        'workflow_tasks_open' => tableExists($conn, 'tb_tasks') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_tasks WHERE status IN ('pending','delegated','in_progress')")->fetch_assoc()['total'] ?? 0) : 0,
        'workflow_tasks_overdue' => tableExists($conn, 'tb_tasks') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_tasks WHERE status IN ('pending','delegated','in_progress') AND due_at IS NOT NULL AND due_at < NOW()")->fetch_assoc()['total'] ?? 0) : 0,
        'feedback_open' => tableExists($conn, 'tb_feedback_submissions') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_feedback_submissions WHERE LOWER(TRIM(COALESCE(status, ''))) IN ('new','reviewed')")->fetch_assoc()['total'] ?? 0) : 0,
        'claims_open' => tableExists($conn, 'tb_arrears_ledger') ? (int)($conn->query("SELECT COUNT(*) AS total FROM tb_arrears_ledger WHERE LOWER(REPLACE(TRIM(COALESCE(status, '')), ' ', '_')) IN ('pending','partially_paid')")->fetch_assoc()['total'] ?? 0) : 0
    ];
    $subject = 'UPS PensionsGo Daily Digest - ' . date('d M Y');
    $lines = [
        'Daily operational digest generated on ' . date('d M Y H:i'),
        '',
        'Active users: ' . number_format($summary['active_users']),
        'Queued notifications: ' . number_format($summary['queued_notifications']),
        'Open workflow tasks: ' . number_format($summary['workflow_tasks_open']),
        'Overdue workflow tasks: ' . number_format($summary['workflow_tasks_overdue']),
        'Open feedback items: ' . number_format($summary['feedback_open']),
        'Open claims items: ' . number_format($summary['claims_open']),
        '',
        'Review the Admin Dashboard for detailed analysis and follow-up actions.'
    ];
    return [
        'generated_at' => date('c'),
        'subject' => $subject,
        'message' => implode("\n", $lines),
        'summary' => $summary
    ];
}

function queueAdminDailyDigest(mysqli $conn, array $options = []): array {
    ensureNotificationDigestRunsTable($conn);
    $digest = buildAdminDailyDigest($conn);
    $recipient = $options['recipient'] ?? resolveNotificationDigestRecipient($conn);
    if (!$recipient) {
        throw new RuntimeException('No digest recipient is configured.');
    }
    $runType = $options['run_type'] ?? 'manual';
    $status = $runType === 'preview' ? 'previewed' : 'queued';
    $stmt = $conn->prepare("INSERT INTO tb_notification_digest_runs (
        digest_date, run_type, recipient, subject, status, summary_json, notes, created_by, created_by_name, created_by_role
    ) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new RuntimeException('Unable to record digest run.');
    }
    $summaryJson = json_encode($digest['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $notes = $options['notes'] ?? ($runType === 'preview' ? 'Digest preview generated from Notification Settings.' : 'Digest queued from Notification Settings.');
    $createdBy = $options['created_by'] ?? ($_SESSION['userId'] ?? null);
    $createdByName = $options['created_by_name'] ?? ($_SESSION['userName'] ?? null);
    $createdByRole = $options['created_by_role'] ?? ($_SESSION['userRole'] ?? null);
    $stmt->bind_param('sssssssss', $runType, $recipient, $digest['subject'], $status, $summaryJson, $notes, $createdBy, $createdByName, $createdByRole);
    $stmt->execute();
    $digestId = (int)$stmt->insert_id;
    $stmt->close();

    if ($runType !== 'preview') {
        $htmlBody = '<p>Daily operational digest generated on ' . htmlspecialchars(date('d M Y H:i'), ENT_QUOTES, 'UTF-8') . '.</p><ul>'
            . '<li><strong>Active users:</strong> ' . number_format((int)$digest['summary']['active_users']) . '</li>'
            . '<li><strong>Queued notifications:</strong> ' . number_format((int)$digest['summary']['queued_notifications']) . '</li>'
            . '<li><strong>Open workflow tasks:</strong> ' . number_format((int)$digest['summary']['workflow_tasks_open']) . '</li>'
            . '<li><strong>Overdue workflow tasks:</strong> ' . number_format((int)$digest['summary']['workflow_tasks_overdue']) . '</li>'
            . '<li><strong>Open feedback items:</strong> ' . number_format((int)$digest['summary']['feedback_open']) . '</li>'
            . '<li><strong>Open claims items:</strong> ' . number_format((int)$digest['summary']['claims_open']) . '</li>'
            . '</ul><p>Review the Admin Dashboard for detailed analysis and follow-up actions.</p>';
        $queued = queueNotification($conn, 'email', $recipient, $digest['subject'], $digest['message'], [
            'source' => 'daily_digest',
            'digest_id' => $digestId,
            'summary' => $digest['summary'],
            'html_body' => $htmlBody
        ]);
        if (!$queued) {
            $status = 'failed';
            $failStmt = $conn->prepare("UPDATE tb_notification_digest_runs SET status = 'failed', notes = CONCAT(COALESCE(notes, ''), ' Queue insertion failed.') WHERE digest_id = ? LIMIT 1");
            if ($failStmt) {
                $failStmt->bind_param('i', $digestId);
                $failStmt->execute();
                $failStmt->close();
            }
        }
    }
    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'info',
            'log_category' => 'notification_digest',
            'event_code' => 'digest_' . $status,
            'message' => 'Notification digest ' . ($runType === 'preview' ? 'previewed' : 'queued') . '.',
            'context' => [
                'digest_id' => $digestId,
                'recipient' => $recipient,
                'summary' => $digest['summary']
            ],
            'actor_id' => $createdBy,
            'actor_name' => $createdByName,
            'actor_role' => $createdByRole
        ]);
    }
    return [
        'digest_id' => $digestId,
        'recipient' => $recipient,
        'subject' => $digest['subject'],
        'message' => $digest['message'],
        'summary' => $digest['summary'],
        'status' => $status,
        'run_type' => $runType
    ];
}

function maybeQueueDailyAdminDigest(mysqli $conn): ?array {
    if (!getAppSettingBool($conn, 'notify_admin_digest_enabled', false)) {
        return null;
    }
    if (!getAppSettingBool($conn, 'notify_email_enabled', true)) {
        return null;
    }
    ensureNotificationDigestRunsTable($conn);
    $digestTime = trim(getAppSettingString($conn, 'notify_digest_time', '07:30')) ?: '07:30';
    $current = date('H:i');
    if ($current < $digestTime) {
        return null;
    }
    $stmt = $conn->prepare("SELECT digest_id FROM tb_notification_digest_runs WHERE digest_date = CURDATE() AND run_type = 'scheduled' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($exists) {
            return null;
        }
    }
    try {
        return queueAdminDailyDigest($conn, [
            'run_type' => 'scheduled',
            'notes' => 'Scheduled daily digest queued automatically.'
        ]);
    } catch (Throwable $e) {
        if (function_exists('recordSystemLog')) {
            recordSystemLog($conn, [
                'log_level' => 'warning',
                'log_category' => 'notification_digest',
                'event_code' => 'digest_schedule_skipped',
                'message' => 'Scheduled daily digest could not be queued.',
                'context' => ['error' => $e->getMessage()]
            ]);
        }
        return null;
    }
}

function getNotificationDigestRuntime(mysqli $conn): array {
    ensureNotificationDigestRunsTable($conn);
    $summary = [
        'recipient' => resolveNotificationDigestRecipient($conn),
        'enabled' => getAppSettingBool($conn, 'notify_admin_digest_enabled', false),
        'delivery_time' => getAppSettingString($conn, 'notify_digest_time', '07:30'),
        'history' => [],
        'preview' => buildAdminDailyDigest($conn)
    ];
    $result = $conn->query("SELECT digest_id, digest_date, run_type, recipient, subject, status, notes, created_at FROM tb_notification_digest_runs ORDER BY created_at DESC LIMIT 8");
    while ($result && ($row = $result->fetch_assoc())) {
        $summary['history'][] = $row;
    }
    if ($result) {
        $result->close();
    }
    return $summary;
}

function ensureAnalyticsDigestRunsTable(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS tb_analytics_digest_runs (
        digest_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        digest_date DATE NOT NULL,
        run_type ENUM('scheduled','manual','preview') NOT NULL DEFAULT 'scheduled',
        digest_frequency VARCHAR(20) NOT NULL DEFAULT 'weekly',
        recipient VARCHAR(255) DEFAULT NULL,
        subject VARCHAR(255) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'queued',
        summary_json LONGTEXT DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_by VARCHAR(50) DEFAULT NULL,
        created_by_name VARCHAR(150) DEFAULT NULL,
        created_by_role VARCHAR(80) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_analytics_digest_date (digest_date),
        INDEX idx_analytics_digest_created (created_at),
        INDEX idx_analytics_digest_frequency (digest_frequency, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @ $conn->query("ALTER TABLE tb_analytics_digest_runs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'queued'");
}

function resolveAnalyticsDigestRecipient(mysqli $conn): ?string {
    $recipient = trim(getAppSettingString($conn, 'analytics_digest_recipient', ''));
    if ($recipient !== '') {
        return $recipient;
    }
    return resolveNotificationDigestRecipient($conn);
}

function getAnalyticsDigestFrequency(mysqli $conn): string {
    $frequency = strtolower(trim(getAppSettingString($conn, 'analytics_digest_frequency', 'weekly')));
    return in_array($frequency, ['daily', 'weekly', 'monthly'], true) ? $frequency : 'weekly';
}

function getAnalyticsDigestDeliveryTime(mysqli $conn): string {
    $time = trim(getAppSettingString($conn, 'analytics_digest_time', ''));
    if ($time === '') {
        $time = trim(getAppSettingString($conn, 'notify_digest_time', '08:00'));
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        $time = '08:00';
    }
    return $time;
}

function buildAnalyticsDigest(mysqli $conn): array {
    if (function_exists('ensureStaffDueWorkflowColumns')) {
        ensureStaffDueWorkflowColumns($conn);
    }
    if (function_exists('ensureStaffDueSoftDeleteColumns')) {
        ensureStaffDueSoftDeleteColumns($conn);
    }
    if (function_exists('ensureTasksTable')) {
        ensureTasksTable($conn);
    }
    if (function_exists('ensureArrearsAndBudgetTables')) {
        ensureArrearsAndBudgetTables($conn);
    }
    if (function_exists('ensureFeedbackWorkflowTables')) {
        ensureFeedbackWorkflowTables($conn);
    }
    if (function_exists('ensureLifeCertificateTables')) {
        ensureLifeCertificateTables($conn);
    }
    if (function_exists('ensurePayrollManagementTables')) {
        ensurePayrollManagementTables($conn);
    }
    if (function_exists('ensureFileMovementTables')) {
        ensureFileMovementTables($conn);
    }

    $columnCache = [];
    $hasColumn = function (string $table, string $column) use ($conn, &$columnCache): bool {
        $key = strtolower($table) . '.' . strtolower($column);
        if (array_key_exists($key, $columnCache)) {
            return $columnCache[$key];
        }
        if (!tableExists($conn, $table)) {
            $columnCache[$key] = false;
            return false;
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            $columnCache[$key] = false;
            return false;
        }
        $escapedColumn = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$escapedColumn}'");
        $exists = $result && $result->num_rows > 0;
        if ($result instanceof mysqli_result) {
            $result->close();
        }
        $columnCache[$key] = $exists;
        return $exists;
    };

    $currentYear = (int)date('Y');
    $registrySoftDeleteClause = $hasColumn('tb_fileregistry', 'is_deleted') ? 'COALESCE(is_deleted, 0) = 0' : '';
    $staffSoftDeleteClause = $hasColumn('tb_staffdue', 'is_deleted') ? 'COALESCE(is_deleted, 0) = 0' : '';

    $registrySummary = ['total' => 0, 'alive' => 0, 'deceased' => 0];
    if (tableExists($conn, 'tb_fileregistry')) {
        $registryWhere = $registrySoftDeleteClause !== '' ? "WHERE {$registrySoftDeleteClause}" : '';
        $result = $conn->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(livingStatus, ''))) = 'alive' THEN 1 ELSE 0 END) AS alive_total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(livingStatus, ''))) = 'deceased' THEN 1 ELSE 0 END) AS deceased_total
            FROM tb_fileregistry
            {$registryWhere}
        ");
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $registrySummary = [
            'total' => (int)($row['total'] ?? 0),
            'alive' => (int)($row['alive_total'] ?? 0),
            'deceased' => (int)($row['deceased_total'] ?? 0),
        ];
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }

    $staffDueSummary = ['total' => 0, 'submitted' => 0];
    if (tableExists($conn, 'tb_staffdue')) {
        $staffWhere = $staffSoftDeleteClause !== '' ? "WHERE {$staffSoftDeleteClause}" : '';
        $result = $conn->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN LOWER(TRIM(COALESCE(submissionStatus, ''))) = 'submitted' THEN 1 ELSE 0 END) AS submitted_total
            FROM tb_staffdue
            {$staffWhere}
        ");
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $staffDueSummary = [
            'total' => (int)($row['total'] ?? 0),
            'submitted' => (int)($row['submitted_total'] ?? 0),
        ];
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }

    $workflowSummary = ['open' => 0, 'overdue' => 0, 'completed_week' => 0];
    if (tableExists($conn, 'tb_tasks')) {
        $taskStatusExpr = "LOWER(REPLACE(TRIM(COALESCE(status, '')), ' ', '_'))";
        $result = $conn->query("
            SELECT
                SUM(CASE WHEN {$taskStatusExpr} IN ('pending','assigned','in_progress','delegated','deferred','returned') THEN 1 ELSE 0 END) AS open_total,
                SUM(CASE WHEN {$taskStatusExpr} IN ('pending','assigned','in_progress','delegated','deferred','returned') AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) AS overdue_total,
                SUM(CASE WHEN {$taskStatusExpr} = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS completed_last_week
            FROM tb_tasks
        ");
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $workflowSummary = [
            'open' => (int)($row['open_total'] ?? 0),
            'overdue' => (int)($row['overdue_total'] ?? 0),
            'completed_week' => (int)($row['completed_last_week'] ?? 0),
        ];
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }

    $claimsSummary = ['open' => 0, 'balance' => 0.0, 'pending_accountability' => 0];
    if (tableExists($conn, 'tb_arrears_ledger')) {
        $result = $conn->query("
            SELECT
                SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(status, '')), ' ', '_')) IN ('pending','partially_paid') THEN 1 ELSE 0 END) AS open_total,
                COALESCE(SUM(balance_amount), 0) AS balance_total,
                SUM(CASE WHEN COALESCE(accountability_status, '') = 'Pending Accountability' THEN 1 ELSE 0 END) AS accountability_total
            FROM tb_arrears_ledger
        ");
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $claimsSummary = [
            'open' => (int)($row['open_total'] ?? 0),
            'balance' => (float)($row['balance_total'] ?? 0),
            'pending_accountability' => (int)($row['accountability_total'] ?? 0),
        ];
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }

    $feedbackSummary = ['open' => 0, 'critical' => 0];
    if (tableExists($conn, 'tb_feedback_submissions')) {
        $result = $conn->query("
            SELECT
                SUM(CASE WHEN status IN ('new','reviewed') THEN 1 ELSE 0 END) AS open_total,
                SUM(CASE WHEN priority = 'critical' AND status <> 'closed' THEN 1 ELSE 0 END) AS critical_total
            FROM tb_feedback_submissions
        ");
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $feedbackSummary = [
            'open' => (int)($row['open_total'] ?? 0),
            'critical' => (int)($row['critical_total'] ?? 0),
        ];
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }

    $movementSummary = ['open' => 0, 'overdue' => 0];
    if (tableExists($conn, 'tb_file_movements')) {
        $movementSql = "
            SELECT
                SUM(CASE WHEN m.returned_at IS NULL THEN 1 ELSE 0 END) AS open_total,
                SUM(CASE WHEN m.returned_at IS NULL AND m.expected_return_at IS NOT NULL AND m.expected_return_at < NOW() THEN 1 ELSE 0 END) AS overdue_total
            FROM tb_file_movements m
        ";
        if (tableExists($conn, 'tb_fileregistry')) {
            $movementSql .= " LEFT JOIN tb_fileregistry fr ON fr.regNo = m.regNo";
            if ($registrySoftDeleteClause !== '') {
                $movementSql .= " WHERE " . str_replace('is_deleted', 'fr.is_deleted', $registrySoftDeleteClause);
            }
        }
        $result = $conn->query($movementSql);
        $row = $result ? ($result->fetch_assoc() ?: []) : [];
        $movementSummary = [
            'open' => (int)($row['open_total'] ?? 0),
            'overdue' => (int)($row['overdue_total'] ?? 0),
        ];
        if ($result instanceof mysqli_result) {
            $result->close();
        }
    }

    $lifeCertificateSummary = ['submitted' => 0, 'pending' => 0, 'exempt' => 0];
    if (tableExists($conn, 'tb_fileregistry')) {
        $lifeCertStatusExpr = "
            CASE
                WHEN LOWER(TRIM(COALESCE(fr.livingStatus, ''))) = 'deceased'
                  OR LOWER(REPLACE(REPLACE(REPLACE(COALESCE(fr.payType, ''), '-', ''), ' ', ''), '_', '')) IN ('oneoffpayment','oneoff','oneoffpayout','oneoffpay','gratuityonly')
                    THEN 'Exempt'
                WHEN lcs.submission_id IS NOT NULL THEN 'Submitted'
                ELSE 'Not Submitted'
            END
        ";
        $lifeWhere = [
            "fr.regNo IS NOT NULL",
            "TRIM(fr.regNo) <> ''"
        ];
        if ($registrySoftDeleteClause !== '') {
            $lifeWhere[] = "COALESCE(fr.is_deleted, 0) = 0";
        }
        $stmt = $conn->prepare("
            SELECT
                SUM(CASE WHEN {$lifeCertStatusExpr} = 'Submitted' THEN 1 ELSE 0 END) AS submitted_total,
                SUM(CASE WHEN {$lifeCertStatusExpr} = 'Not Submitted' THEN 1 ELSE 0 END) AS pending_total,
                SUM(CASE WHEN {$lifeCertStatusExpr} = 'Exempt' THEN 1 ELSE 0 END) AS exempt_total
            FROM tb_fileregistry fr
            LEFT JOIN tb_life_certificate_submissions lcs
              ON lcs.regNo = fr.regNo
             AND lcs.submission_year = ?
            WHERE " . implode(' AND ', $lifeWhere) . "
        ");
        if ($stmt) {
            $stmt->bind_param('i', $currentYear);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $lifeCertificateSummary = [
                'submitted' => (int)($row['submitted_total'] ?? 0),
                'pending' => (int)($row['pending_total'] ?? 0),
                'exempt' => (int)($row['exempt_total'] ?? 0),
            ];
        }
    }

    $latestPayrollLabel = 'Latest cycle unavailable';
    $payrollSummary = ['on' => 0, 'off' => 0];
    if (tableExists($conn, 'tb_payroll_upload_cycles') && tableExists($conn, 'tb_registry_payroll_monthly_status')) {
        $cycleResult = $conn->query("
            SELECT cycle_id, payroll_year, payroll_month
            FROM tb_payroll_upload_cycles
            WHERE COALESCE(is_deleted, 0) = 0
            ORDER BY payroll_year DESC, payroll_month DESC, cycle_id DESC
            LIMIT 1
        ");
        $cycle = $cycleResult ? ($cycleResult->fetch_assoc() ?: null) : null;
        if ($cycleResult instanceof mysqli_result) {
            $cycleResult->close();
        }
        if ($cycle) {
            $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $payrollYear = (int)($cycle['payroll_year'] ?? 0);
            $payrollMonth = (int)($cycle['payroll_month'] ?? 0);
            if ($payrollYear > 0 && $payrollMonth >= 1 && $payrollMonth <= 12) {
                $latestPayrollLabel = $monthNames[$payrollMonth - 1] . '/' . $payrollYear;
                $stmt = $conn->prepare("
                    SELECT
                        SUM(CASE WHEN pms.payroll_status = 'On Payroll' THEN 1 ELSE 0 END) AS on_total,
                        SUM(CASE WHEN pms.payroll_status = 'Not on Payroll' THEN 1 ELSE 0 END) AS off_total
                    FROM tb_registry_payroll_monthly_status pms
                    INNER JOIN tb_fileregistry fr ON fr.regNo = pms.regNo
                    WHERE pms.payroll_year = ?
                      AND pms.payroll_month = ?
                      " . ($hasColumn('tb_fileregistry', 'payType') ? "AND LOWER(TRIM(COALESCE(fr.payType, ''))) = 'pensioner'" : "") . "
                      " . ($registrySoftDeleteClause !== '' ? "AND COALESCE(fr.is_deleted, 0) = 0" : "") . "
                ");
                if ($stmt) {
                    $stmt->bind_param('ii', $payrollYear, $payrollMonth);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc() ?: [];
                    $stmt->close();
                    $payrollSummary = [
                        'on' => (int)($row['on_total'] ?? 0),
                        'off' => (int)($row['off_total'] ?? 0),
                    ];
                }
            }
        }
    }

    $summary = [
        ['label' => 'Registry portfolio', 'value' => $registrySummary['total'], 'helper' => $registrySummary['alive'] . ' alive | ' . $registrySummary['deceased'] . ' deceased'],
        ['label' => 'Staff due submitted', 'value' => $staffDueSummary['submitted'], 'helper' => max(0, $staffDueSummary['total'] - $staffDueSummary['submitted']) . ' still pending submission'],
        ['label' => 'Open workflow tasks', 'value' => $workflowSummary['open'], 'helper' => $workflowSummary['overdue'] . ' overdue | ' . $workflowSummary['completed_week'] . ' completed in 7 days'],
        ['label' => 'Claims outstanding balance', 'value' => round($claimsSummary['balance'], 2), 'helper' => $claimsSummary['open'] . ' open claims | ' . $claimsSummary['pending_accountability'] . ' pending accountability', 'format' => 'currency'],
        ['label' => 'Open feedback items', 'value' => $feedbackSummary['open'], 'helper' => $feedbackSummary['critical'] . ' critical-priority items still open'],
        ['label' => 'Life certificates pending', 'value' => $lifeCertificateSummary['pending'], 'helper' => $lifeCertificateSummary['submitted'] . ' submitted | ' . $lifeCertificateSummary['exempt'] . ' exempt'],
        ['label' => 'Files out of registry', 'value' => $movementSummary['open'], 'helper' => $movementSummary['overdue'] . ' overdue returns'],
        ['label' => 'Off payroll pensioners', 'value' => $payrollSummary['off'], 'helper' => $latestPayrollLabel . ' latest payroll cut']
    ];

    $lines = [
        'Analytics digest generated on ' . date('d M Y H:i'),
        '',
        'Registry portfolio: ' . number_format($registrySummary['total']) . ' files (' . number_format($registrySummary['alive']) . ' alive, ' . number_format($registrySummary['deceased']) . ' deceased)',
        'Staff due submitted: ' . number_format($staffDueSummary['submitted']) . ' of ' . number_format($staffDueSummary['total']),
        'Open workflow tasks: ' . number_format($workflowSummary['open']) . ' (' . number_format($workflowSummary['overdue']) . ' overdue)',
        'Claims outstanding balance: UGX ' . number_format($claimsSummary['balance'], 2),
        'Open feedback items: ' . number_format($feedbackSummary['open']) . ' (' . number_format($feedbackSummary['critical']) . ' critical)',
        'Pending life certificates: ' . number_format($lifeCertificateSummary['pending']),
        'Open file movements: ' . number_format($movementSummary['open']) . ' (' . number_format($movementSummary['overdue']) . ' overdue returns)',
        'Latest payroll (' . $latestPayrollLabel . '): ' . number_format($payrollSummary['off']) . ' pensioners remain off payroll',
        '',
        'Use the Dashboard and Admin Console reporting sections for drill-down analysis and export-ready evidence packs.'
    ];

    return [
        'generated_at' => date('c'),
        'subject' => 'UPS PensionsGo Analytics Digest - ' . date('d M Y'),
        'message' => implode("\n", $lines),
        'summary' => $summary,
        'context' => [
            'frequency' => getAnalyticsDigestFrequency($conn),
            'latest_payroll_label' => $latestPayrollLabel
        ]
    ];
}

function markAnalyticsDigestRunStatus(mysqli $conn, array $meta, string $status, ?string $error = null): void {
    $digestId = (int)($meta['digest_id'] ?? 0);
    if ($digestId <= 0 || !tableExists($conn, 'tb_analytics_digest_runs')) {
        return;
    }
    $status = in_array($status, ['queued', 'previewed', 'sent', 'failed'], true) ? $status : 'queued';
    $notes = null;
    if ($error !== null && $error !== '') {
        $notes = 'Delivery worker update: ' . $error;
    }

    $stmt = $conn->prepare("
        UPDATE tb_analytics_digest_runs
        SET status = ?, notes = CASE WHEN ? IS NULL OR ? = '' THEN notes ELSE ? END
        WHERE digest_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('ssssi', $status, $notes, $notes, $notes, $digestId);
        $stmt->execute();
        $stmt->close();
    }
}

function queueAnalyticsDigest(mysqli $conn, array $options = []): array {
    ensureAnalyticsDigestRunsTable($conn);
    $digest = buildAnalyticsDigest($conn);
    $recipient = $options['recipient'] ?? resolveAnalyticsDigestRecipient($conn);
    if (!$recipient) {
        throw new RuntimeException('No analytics digest recipient is configured.');
    }

    $runType = $options['run_type'] ?? 'manual';
    $frequency = $options['frequency'] ?? getAnalyticsDigestFrequency($conn);
    $status = $runType === 'preview' ? 'previewed' : 'queued';
    $summaryJson = json_encode($digest['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $notes = $options['notes'] ?? ($runType === 'preview'
        ? 'Analytics digest preview generated from Analysis & Reporting.'
        : 'Analytics digest queued from Analysis & Reporting.');
    $createdBy = $options['created_by'] ?? ($_SESSION['userId'] ?? null);
    $createdByName = $options['created_by_name'] ?? ($_SESSION['userName'] ?? null);
    $createdByRole = $options['created_by_role'] ?? ($_SESSION['userRole'] ?? null);

    $stmt = $conn->prepare("
        INSERT INTO tb_analytics_digest_runs (
            digest_date, run_type, digest_frequency, recipient, subject, status,
            summary_json, notes, created_by, created_by_name, created_by_role
        ) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to record analytics digest run.');
    }
    $stmt->bind_param('ssssssssss', $runType, $frequency, $recipient, $digest['subject'], $status, $summaryJson, $notes, $createdBy, $createdByName, $createdByRole);
    $stmt->execute();
    $digestId = (int)$stmt->insert_id;
    $stmt->close();

    if ($runType !== 'preview') {
        $htmlItems = array_map(
            static fn(array $item): string => '<li><strong>' . htmlspecialchars((string)($item['label'] ?? 'Metric'), ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars(((isset($item['format']) && $item['format'] === 'currency')
                    ? ('UGX ' . number_format((float)($item['value'] ?? 0), 2))
                    : (string)($item['value'] ?? 0)), ENT_QUOTES, 'UTF-8')
                . (!empty($item['helper']) ? ' <span style="color:#6b7280;">(' . htmlspecialchars((string)$item['helper'], ENT_QUOTES, 'UTF-8') . ')</span>' : '')
                . '</li>',
            $digest['summary']
        );
        $htmlBody = '<p>Analytics digest generated on ' . htmlspecialchars(date('d M Y H:i'), ENT_QUOTES, 'UTF-8') . '.</p><ul>'
            . implode('', $htmlItems)
            . '</ul><p>Use the Dashboard and Admin Console reporting sections for drill-down analysis and export-ready evidence packs.</p>';
        $queued = queueNotification($conn, 'email', $recipient, $digest['subject'], $digest['message'], [
            'source' => 'analytics_digest',
            'digest_id' => $digestId,
            'summary' => $digest['summary'],
            'html_body' => $htmlBody
        ]);
        if (!$queued) {
            $status = 'failed';
            $failStmt = $conn->prepare("UPDATE tb_analytics_digest_runs SET status = 'failed', notes = CONCAT(COALESCE(notes, ''), ' Queue insertion failed.') WHERE digest_id = ? LIMIT 1");
            if ($failStmt) {
                $failStmt->bind_param('i', $digestId);
                $failStmt->execute();
                $failStmt->close();
            }
        }
    }

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'info',
            'log_category' => 'analytics_digest',
            'event_code' => 'analytics_digest_' . $status,
            'message' => 'Analytics digest ' . ($runType === 'preview' ? 'previewed' : 'queued') . '.',
            'context' => [
                'digest_id' => $digestId,
                'recipient' => $recipient,
                'frequency' => $frequency,
                'summary' => $digest['summary']
            ],
            'actor_id' => $createdBy,
            'actor_name' => $createdByName,
            'actor_role' => $createdByRole
        ]);
    }

    return [
        'digest_id' => $digestId,
        'recipient' => $recipient,
        'subject' => $digest['subject'],
        'message' => $digest['message'],
        'summary' => $digest['summary'],
        'status' => $status,
        'run_type' => $runType,
        'frequency' => $frequency,
        'generated_at' => $digest['generated_at'] ?? date('c')
    ];
}

function maybeQueueAnalyticsDigest(mysqli $conn): ?array {
    if (!getAppSettingBool($conn, 'analytics_auto_digest_enabled', false)) {
        return null;
    }
    if (!getAppSettingBool($conn, 'notify_email_enabled', true)) {
        return null;
    }

    ensureAnalyticsDigestRunsTable($conn);
    $frequency = getAnalyticsDigestFrequency($conn);
    $deliveryTime = getAnalyticsDigestDeliveryTime($conn);
    if (date('H:i') < $deliveryTime) {
        return null;
    }

    $shouldQueue = false;
    $stmt = null;
    if ($frequency === 'daily') {
        $stmt = $conn->prepare("SELECT digest_id FROM tb_analytics_digest_runs WHERE digest_date = CURDATE() AND run_type = 'scheduled' LIMIT 1");
        $shouldQueue = true;
    } elseif ($frequency === 'weekly') {
        if ((int)date('N') !== 1) {
            return null;
        }
        $stmt = $conn->prepare("SELECT digest_id FROM tb_analytics_digest_runs WHERE YEARWEEK(digest_date, 1) = YEARWEEK(CURDATE(), 1) AND run_type = 'scheduled' LIMIT 1");
        $shouldQueue = true;
    } elseif ($frequency === 'monthly') {
        if ((int)date('j') !== 1) {
            return null;
        }
        $stmt = $conn->prepare("SELECT digest_id FROM tb_analytics_digest_runs WHERE YEAR(digest_date) = YEAR(CURDATE()) AND MONTH(digest_date) = MONTH(CURDATE()) AND run_type = 'scheduled' LIMIT 1");
        $shouldQueue = true;
    }
    if (!$shouldQueue || !$stmt) {
        return null;
    }

    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($exists) {
        return null;
    }

    try {
        return queueAnalyticsDigest($conn, [
            'run_type' => 'scheduled',
            'frequency' => $frequency,
            'notes' => 'Scheduled analytics digest queued automatically.'
        ]);
    } catch (Throwable $e) {
        if (function_exists('recordSystemLog')) {
            recordSystemLog($conn, [
                'log_level' => 'warning',
                'log_category' => 'analytics_digest',
                'event_code' => 'analytics_digest_schedule_skipped',
                'message' => 'Scheduled analytics digest could not be queued.',
                'context' => ['error' => $e->getMessage(), 'frequency' => $frequency]
            ]);
        }
        return null;
    }
}

function getAnalyticsDigestRuntime(mysqli $conn): array {
    ensureAnalyticsDigestRunsTable($conn);
    $summary = [
        'recipient' => resolveAnalyticsDigestRecipient($conn),
        'enabled' => getAppSettingBool($conn, 'analytics_auto_digest_enabled', false),
        'frequency' => getAnalyticsDigestFrequency($conn),
        'delivery_time' => getAnalyticsDigestDeliveryTime($conn),
        'history' => [],
        'preview' => buildAnalyticsDigest($conn)
    ];
    $result = $conn->query("
        SELECT digest_id, digest_date, run_type, digest_frequency, recipient, subject, status, notes, created_at
        FROM tb_analytics_digest_runs
        ORDER BY created_at DESC
        LIMIT 8
    ");
    while ($result && ($row = $result->fetch_assoc())) {
        $summary['history'][] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    return $summary;
}
