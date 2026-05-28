<?php

if (!function_exists('parseMemoryLimitToBytes')) {
    function parseMemoryLimitToBytes($rawLimit): int
    {
        $value = trim((string)$rawLimit);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        switch ($unit) {
            case 'g':
                return (int)($number * 1024 * 1024 * 1024);
            case 'm':
                return (int)($number * 1024 * 1024);
            case 'k':
                return (int)($number * 1024);
            default:
                return (int)$number;
        }
    }
}

function ensureSystemLogResolutionsTable(mysqli $conn): void
{
    static $created = false;
    if ($created) {
        return;
    }

    $conn->query("
        CREATE TABLE IF NOT EXISTS tb_system_log_resolutions (
            resolution_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            log_id BIGINT UNSIGNED NOT NULL,
            resolution_status ENUM('acknowledged','resolved','dismissed') NOT NULL DEFAULT 'resolved',
            resolution_note TEXT DEFAULT NULL,
            resolved_by_id VARCHAR(50) DEFAULT NULL,
            resolved_by_name VARCHAR(150) DEFAULT NULL,
            resolved_by_role VARCHAR(80) DEFAULT NULL,
            resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_system_log_resolution (log_id),
            INDEX idx_system_log_resolution_status (resolution_status, resolved_at),
            INDEX idx_system_log_resolution_actor (resolved_by_id, resolved_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $created = true;
}

function systemHealthSeverityWeight(string $severity): int
{
    $normalized = strtolower(trim($severity));
    switch ($normalized) {
        case 'error':
            return 300;
        case 'warning':
            return 200;
        case 'healthy':
            return 100;
        default:
            return 0;
    }
}

function systemHealthLevelWeight(string $level): int
{
    $normalized = strtolower(trim($level));
    switch ($normalized) {
        case 'critical':
            return 400;
        case 'error':
            return 300;
        case 'warning':
            return 200;
        case 'notice':
            return 100;
        default:
            return 0;
    }
}

function systemHealthLevelToSeverity(string $level): string
{
    return systemHealthLevelWeight($level) >= 300 ? 'error' : 'warning';
}

function systemHealthQuoteLevels(array $levels): string
{
    $safe = [];
    foreach ($levels as $level) {
        $normalized = strtolower(trim((string)$level));
        if (in_array($normalized, ['warning', 'error', 'critical'], true)) {
            $safe[] = "'" . $normalized . "'";
        }
    }

    return !empty($safe) ? implode(', ', array_unique($safe)) : "'warning', 'error', 'critical'";
}

function countSystemHealthUnresolvedLogs(mysqli $conn, int $hours = 1, array $levels = ['warning', 'error', 'critical']): int
{
    ensureSystemLogsTable($conn);
    ensureSystemLogResolutionsTable($conn);

    if (!tableExists($conn, 'tb_system_logs')) {
        return 0;
    }

    $hours = max(1, min(168, $hours));
    $levelList = systemHealthQuoteLevels($levels);
    $sql = "
        SELECT COUNT(*) AS total
        FROM tb_system_logs l
        LEFT JOIN tb_system_log_resolutions r
            ON r.log_id = l.log_id
           AND r.resolution_status IN ('acknowledged', 'resolved', 'dismissed')
        WHERE l.log_level IN ({$levelList})
          AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
          AND r.log_id IS NULL
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    $result->close();
    return (int)($row['total'] ?? 0);
}

function countSystemHealthRecentResolutions(mysqli $conn, int $hours = 24): int
{
    ensureSystemLogResolutionsTable($conn);

    $hours = max(1, min(168, $hours));
    $result = $conn->query("
        SELECT COUNT(*) AS total
        FROM tb_system_log_resolutions
        WHERE resolution_status IN ('acknowledged', 'resolved', 'dismissed')
          AND resolved_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
    ");
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    $result->close();
    return (int)($row['total'] ?? 0);
}

function fetchSystemHealthUnresolvedLogs(mysqli $conn, int $hours = 72, int $limit = 120): array
{
    ensureSystemLogsTable($conn);
    ensureSystemLogResolutionsTable($conn);

    if (!tableExists($conn, 'tb_system_logs')) {
        return [];
    }

    $hours = max(1, min(168, $hours));
    $limit = max(1, min(400, $limit));
    $sql = "
        SELECT
            l.log_id,
            l.log_level,
            l.log_category,
            l.event_code,
            l.message,
            l.context_json,
            l.actor_id,
            l.actor_name,
            l.actor_role,
            l.created_at
        FROM tb_system_logs l
        LEFT JOIN tb_system_log_resolutions r
            ON r.log_id = l.log_id
           AND r.resolution_status IN ('acknowledged', 'resolved', 'dismissed')
        WHERE l.log_level IN ('warning', 'error', 'critical')
          AND l.created_at >= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
          AND r.log_id IS NULL
        ORDER BY l.created_at DESC, l.log_id DESC
        LIMIT {$limit}
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $context = [];
        if (!empty($row['context_json'])) {
            $decoded = json_decode((string)$row['context_json'], true);
            if (is_array($decoded)) {
                $context = $decoded;
            }
        }
        $row['context'] = $context;
        unset($row['context_json']);
        $logs[] = $row;
    }
    $result->close();

    return $logs;
}

function systemHealthFormatDate(?string $value): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return 'Not available';
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('M j, Y g:i A', $ts);
}

function systemHealthShortText(string $value, int $limit = 180): string
{
    $clean = trim(preg_replace('/\s+/', ' ', $value));
    if ($clean === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($clean) <= $limit) {
            return $clean;
        }
        return rtrim(mb_substr($clean, 0, max(1, $limit - 3))) . '...';
    }

    if (strlen($clean) <= $limit) {
        return $clean;
    }

    return rtrim(substr($clean, 0, max(1, $limit - 3))) . '...';
}

function systemHealthFormatLabel(string $value): string
{
    $normalized = trim((string)$value);
    if ($normalized === '') {
        return 'General';
    }

    return ucwords(str_replace(['_', '-'], ' ', $normalized));
}

function systemHealthBuildMessageList(array $values, int $limit = 4): array
{
    $messages = [];
    $seen = [];

    foreach ($values as $value) {
        $text = systemHealthShortText((string)$value, 220);
        if ($text === '') {
            continue;
        }
        $key = strtolower($text);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $messages[] = $text;
        if (count($messages) >= $limit) {
            break;
        }
    }

    return $messages;
}

function systemHealthExtractContextMessage(array $context): string
{
    $priorityKeys = ['error', 'last_error', 'message', 'reason', 'note'];
    foreach ($priorityKeys as $key) {
        $value = $context[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    foreach ($context as $value) {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return '';
}

function systemHealthBuildLogGroupKey(array $log): string
{
    $category = strtolower(trim((string)($log['log_category'] ?? 'general'))) ?: 'general';
    $eventCode = strtolower(trim((string)($log['event_code'] ?? '')));
    if ($category === 'notification_queue') {
        return 'notification-email-delivery';
    }

    if ($eventCode !== '') {
        return 'log:' . $category . ':' . $eventCode;
    }

    $message = strtolower(systemHealthShortText((string)($log['message'] ?? ''), 90));
    return 'log:' . $category . ':' . substr(sha1($message), 0, 12);
}

function systemHealthSortAlerts(array &$alerts): void
{
    usort($alerts, static function (array $left, array $right): int {
        $severityCompare = systemHealthSeverityWeight((string)($right['severity'] ?? ''))
            <=> systemHealthSeverityWeight((string)($left['severity'] ?? ''));
        if ($severityCompare !== 0) {
            return $severityCompare;
        }

        $occurrenceCompare = (int)($right['occurrences'] ?? 0) <=> (int)($left['occurrences'] ?? 0);
        if ($occurrenceCompare !== 0) {
            return $occurrenceCompare;
        }

        return strcmp((string)($right['last_seen_at_raw'] ?? ''), (string)($left['last_seen_at_raw'] ?? ''));
    });
}

function getSystemHealthNotificationQueueStats(mysqli $conn): array
{
    if (function_exists('ensureNotificationQueueTable')) {
        ensureNotificationQueueTable($conn);
    }

    $stats = [
        'failed_email_total' => 0,
        'queued_email_total' => 0,
        'queued_total' => 0,
        'last_failed_at' => null,
        'latest_failed_subject' => '',
        'latest_failed_recipient' => '',
        'latest_error' => '',
        'transport' => strtoupper(MAIL_TRANSPORT),
        'smtp_host' => MAIL_TRANSPORT === 'smtp' ? MAIL_SMTP_HOST : '',
        'smtp_port' => MAIL_TRANSPORT === 'smtp' ? (string)MAIL_SMTP_PORT : '',
        'encryption' => MAIL_TRANSPORT === 'smtp' ? strtoupper(MAIL_SMTP_ENCRYPTION ?: 'none') : 'NOT APPLICABLE',
        'from_address' => MAIL_FROM_ADDRESS,
    ];

    if (!tableExists($conn, 'tb_notification_queue')) {
        return $stats;
    }

    $summaryResult = $conn->query("
        SELECT channel, status, COUNT(*) AS total
        FROM tb_notification_queue
        GROUP BY channel, status
    ");
    if ($summaryResult) {
        while ($row = $summaryResult->fetch_assoc()) {
            $channel = strtolower((string)($row['channel'] ?? ''));
            $status = strtolower((string)($row['status'] ?? ''));
            $total = (int)($row['total'] ?? 0);
            if ($status === 'queued') {
                $stats['queued_total'] += $total;
            }
            if ($channel === 'email' && $status === 'failed') {
                $stats['failed_email_total'] = $total;
            }
            if ($channel === 'email' && $status === 'queued') {
                $stats['queued_email_total'] = $total;
            }
        }
        $summaryResult->close();
    }

    $failureResult = $conn->query("
        SELECT recipient, subject, last_error, failed_at, last_attempted_at, created_at
        FROM tb_notification_queue
        WHERE channel = 'email'
          AND status = 'failed'
        ORDER BY COALESCE(failed_at, last_attempted_at, created_at) DESC
        LIMIT 1
    ");
    if ($failureResult && ($failureRow = $failureResult->fetch_assoc())) {
        $stats['latest_failed_recipient'] = trim((string)($failureRow['recipient'] ?? ''));
        $stats['latest_failed_subject'] = trim((string)($failureRow['subject'] ?? ''));
        $stats['latest_error'] = trim((string)($failureRow['last_error'] ?? ''));
        $stats['last_failed_at'] = (string)($failureRow['failed_at'] ?? ($failureRow['last_attempted_at'] ?? ($failureRow['created_at'] ?? '')));
    }
    if ($failureResult instanceof mysqli_result) {
        $failureResult->close();
    }

    return $stats;
}

function buildSystemHealthRuntimeAlert(
    string $key,
    string $severity,
    string $subsystem,
    string $title,
    string $summary,
    string $cause,
    array $steps,
    array $metrics,
    array $messages = []
): array {
    return [
        'key' => $key,
        'severity' => $severity,
        'subsystem' => $subsystem,
        'title' => $title,
        'summary' => $summary,
        'cause' => $cause,
        'recommended_fix' => $steps[0] ?? 'Investigate the subsystem and return the metric to a safe range.',
        'recommended_steps' => array_values($steps),
        'alert_messages' => systemHealthBuildMessageList($messages),
        'metrics' => $metrics,
        'actions' => [],
        'occurrences' => 1,
        'last_seen_at' => 'Current snapshot',
        'last_seen_at_raw' => date('Y-m-d H:i:s'),
        'log_ids' => [],
        'clears_automatically' => true,
    ];
}

function buildSystemHealthNotificationAlert(array $logs, array $queueStats): ?array
{
    $notificationLogs = [];
    foreach ($logs as $log) {
        if (strtolower((string)($log['log_category'] ?? '')) === 'notification_queue') {
            $notificationLogs[] = $log;
        }
    }

    $failedEmailTotal = (int)($queueStats['failed_email_total'] ?? 0);
    if ($failedEmailTotal <= 0 && empty($notificationLogs)) {
        return null;
    }

    $latestLog = $notificationLogs[0] ?? null;
    $latestLogContext = is_array($latestLog['context'] ?? null) ? $latestLog['context'] : [];
    $latestError = trim((string)($queueStats['latest_error'] ?? ''));
    $lastSeenRaw = (string)($queueStats['last_failed_at'] ?? ($latestLog['created_at'] ?? date('Y-m-d H:i:s')));

    $severity = 'warning';
    foreach ($notificationLogs as $log) {
        if (systemHealthLevelWeight((string)($log['log_level'] ?? 'warning')) >= 300) {
            $severity = 'error';
            break;
        }
    }

    $summary = $failedEmailTotal > 0
        ? number_format($failedEmailTotal) . ' failed email notification(s) need attention.'
        : 'Notification queue warnings were recorded and should be reviewed.';

    $cause = $latestError !== ''
        ? $latestError
        : systemHealthExtractContextMessage($latestLogContext);

    if ($cause === '') {
        if (strtoupper((string)($queueStats['transport'] ?? '')) === 'SMTP') {
            $smtpHost = trim((string)($queueStats['smtp_host'] ?? ''));
            $smtpPort = trim((string)($queueStats['smtp_port'] ?? ''));
            $target = $smtpHost !== '' ? $smtpHost . ($smtpPort !== '' ? ':' . $smtpPort : '') : 'the configured SMTP server';
            $cause = 'Outbound email is failing through ' . $target . '.';
        } else {
            $cause = 'Outbound email delivery is failing and the notification queue cannot clear itself.';
        }
    }

    $steps = [];
    $transport = strtoupper((string)($queueStats['transport'] ?? ''));
    $smtpHost = strtolower(trim((string)($queueStats['smtp_host'] ?? '')));
    $smtpPort = trim((string)($queueStats['smtp_port'] ?? ''));
    $fromAddress = trim((string)($queueStats['from_address'] ?? ''));
    $isLocalProfile = $transport === 'SMTP' && in_array($smtpHost, ['127.0.0.1', 'localhost'], true);

    if ($isLocalProfile) {
        $steps[] = 'Start the local SMTP listener on ' . ($smtpHost !== '' ? $smtpHost : 'localhost') . ($smtpPort !== '' ? ':' . $smtpPort : '') . ' or replace the development mail profile with a real SMTP service.';
    } else {
        $steps[] = 'Verify that the configured mail transport is reachable and accepting outbound connections from the app server.';
    }
    $steps[] = $fromAddress !== ''
        ? 'Confirm that the sender address ' . $fromAddress . ' is valid for the active mail transport and accepted by the SMTP server.'
        : 'Confirm that a valid sender address is configured for notification delivery.';
    $steps[] = 'After fixing transport, run the notification queue again and clear stale failed email rows that no longer need to be retried.';

    $messages = [
        $latestError,
        $queueStats['latest_failed_subject'] !== '' ? 'Latest subject: ' . $queueStats['latest_failed_subject'] : '',
        $queueStats['latest_failed_recipient'] !== '' ? 'Latest recipient: ' . $queueStats['latest_failed_recipient'] : '',
        $latestLog ? (string)($latestLog['message'] ?? '') : '',
    ];

    $actions = [
        [
            'action' => 'process_notification_queue',
            'label' => 'Process Queue Now',
            'variant' => 'secondary',
            'requires_reauth' => false,
            'action_label' => 'process the notification queue',
            'confirm_message' => '',
        ],
    ];

    if ($failedEmailTotal > 0) {
        $actions[] = [
            'action' => 'clear_failed_notification_queue',
            'label' => 'Clear Failed Emails',
            'variant' => 'danger',
            'requires_reauth' => true,
            'action_label' => 'clear failed notification emails',
            'confirm_message' => 'Clear the failed email notification rows tied to this incident?',
        ];
    }

    $logIds = array_values(array_map(static fn(array $log): int => (int)($log['log_id'] ?? 0), $notificationLogs));
    $logIds = array_values(array_filter($logIds, static fn(int $id): bool => $id > 0));
    if (!empty($logIds)) {
        $actions[] = [
            'action' => 'resolve_alert',
            'label' => 'Mark Incident Resolved',
            'variant' => 'secondary',
            'requires_reauth' => true,
            'action_label' => 'mark the notification incident resolved',
            'confirm_message' => 'Mark the currently listed notification warning/error events as resolved after you have handled them?',
        ];
    }

    return [
        'key' => 'notification-email-delivery',
        'severity' => $severity,
        'subsystem' => 'Notifications / Email',
        'title' => 'Email transport failure',
        'summary' => $summary,
        'cause' => $cause,
        'recommended_fix' => $steps[0] ?? 'Repair the outbound email transport, then rerun the queue.',
        'recommended_steps' => $steps,
        'alert_messages' => systemHealthBuildMessageList($messages),
        'metrics' => [
            ['label' => 'Failed Emails', 'value' => number_format($failedEmailTotal)],
            ['label' => 'Queued Emails', 'value' => number_format((int)($queueStats['queued_email_total'] ?? 0))],
            ['label' => 'Last Failure', 'value' => systemHealthFormatDate($lastSeenRaw)],
            ['label' => 'Transport', 'value' => $transport !== '' ? $transport : 'Unknown'],
        ],
        'actions' => $actions,
        'occurrences' => max($failedEmailTotal, count($notificationLogs)),
        'last_seen_at' => systemHealthFormatDate($lastSeenRaw),
        'last_seen_at_raw' => $lastSeenRaw,
        'log_ids' => $logIds,
        'clears_automatically' => false,
    ];
}

function systemHealthDescribeGenericCategory(string $category, string $eventCode, string $message): array
{
    $map = [
        'backup' => ['subsystem' => 'Backups', 'title' => 'Backup operation issue'],
        'backup_restore' => ['subsystem' => 'Backup Restore', 'title' => 'Backup restore issue'],
        'data_import' => ['subsystem' => 'Data Import', 'title' => 'Data import issue'],
        'registry_import' => ['subsystem' => 'Registry Import', 'title' => 'Registry import issue'],
        'staff_due_import' => ['subsystem' => 'Staff Due Import', 'title' => 'Staff due import issue'],
        'feedback_notification' => ['subsystem' => 'Feedback Notifications', 'title' => 'Feedback notification issue'],
        'notification_digest' => ['subsystem' => 'Notification Digest', 'title' => 'Notification digest issue'],
        'analytics_digest' => ['subsystem' => 'Analytics Digest', 'title' => 'Analytics digest issue'],
        'data_export' => ['subsystem' => 'Data Export', 'title' => 'Data export issue'],
        'storage_cleanup' => ['subsystem' => 'Storage Cleanup', 'title' => 'Data cleanup issue'],
        'settings' => ['subsystem' => 'Settings', 'title' => 'Settings warning'],
    ];

    if (isset($map[$category])) {
        return $map[$category];
    }

    if (strpos($eventCode, 'failed') !== false || stripos($message, 'failed') !== false || stripos($message, 'unable') !== false) {
        return [
            'subsystem' => systemHealthFormatLabel($category),
            'title' => systemHealthFormatLabel($category) . ' failure',
        ];
    }

    return [
        'subsystem' => systemHealthFormatLabel($category),
        'title' => systemHealthFormatLabel($category) . ' alert',
    ];
}

function systemHealthBuildGenericFixSteps(string $category): array
{
    switch ($category) {
        case 'backup':
        case 'backup_restore':
            return [
                'Check backup destination access, disk space, and file permissions before retrying the operation.',
                'Review the latest backup or restore event details to identify the exact file, database, or permission error.',
                'After the underlying issue is fixed, rerun the affected backup or restore workflow and then mark the incident resolved.',
            ];
        case 'data_import':
        case 'registry_import':
        case 'staff_due_import':
            return [
                'Inspect the latest import error details and validate the source file structure before rerunning the import.',
                'Use the preview or dry-run mode first so the import can be validated without changing production data.',
                'After a clean run, mark the historical incident resolved so system health reflects the fix.',
            ];
        case 'feedback_notification':
        case 'notification_digest':
        case 'analytics_digest':
            return [
                'Verify that the outbound notification channel and recipient configuration are still valid.',
                'Review the latest event payload to identify whether delivery, scheduling, or queue processing failed.',
                'After a successful retry, mark the current incident resolved to clear the alert from health diagnostics.',
            ];
        case 'data_export':
            return [
                'Confirm the export destination, generated file path, and write permissions on the application server.',
                'Retry the export after correcting any file-system or data-preparation problem.',
                'Resolve the alert after confirming a fresh export completes successfully.',
            ];
        case 'storage_cleanup':
            return [
                'Review the cleanup preview and retention settings before applying another cleanup run.',
                'Confirm that the target directories are writable and that no required files are still in active use.',
                'After the cleanup runs successfully, mark the incident resolved so it no longer contributes to warning pressure.',
            ];
        default:
            return [
                'Review the latest system log entry and its context payload to isolate the failing workflow.',
                'Correct the underlying subsystem issue and rerun the affected process if applicable.',
                'Mark the incident resolved after confirming the warning or error is no longer recurring.',
            ];
    }
}

function buildSystemHealthGenericAlerts(array $logs): array
{
    $grouped = [];
    foreach ($logs as $log) {
        $key = systemHealthBuildLogGroupKey($log);
        if ($key === 'notification-email-delivery') {
            continue;
        }
        if (!isset($grouped[$key])) {
            $grouped[$key] = [];
        }
        $grouped[$key][] = $log;
    }

    $alerts = [];
    foreach ($grouped as $key => $groupLogs) {
        if (empty($groupLogs)) {
            continue;
        }

        $latest = $groupLogs[0];
        $category = strtolower(trim((string)($latest['log_category'] ?? 'general'))) ?: 'general';
        $eventCode = strtolower(trim((string)($latest['event_code'] ?? '')));
        $descriptor = systemHealthDescribeGenericCategory($category, $eventCode, (string)($latest['message'] ?? ''));
        $steps = systemHealthBuildGenericFixSteps($category);

        $maxSeverity = 'warning';
        foreach ($groupLogs as $log) {
            if (systemHealthLevelWeight((string)($log['log_level'] ?? 'warning')) >= 300) {
                $maxSeverity = 'error';
                break;
            }
        }

        $messages = [];
        foreach ($groupLogs as $log) {
            $contextMessage = systemHealthExtractContextMessage(is_array($log['context'] ?? null) ? $log['context'] : []);
            if ($contextMessage !== '') {
                $messages[] = $contextMessage;
            }
            $messages[] = (string)($log['message'] ?? '');
        }

        $logIds = array_values(array_map(static fn(array $log): int => (int)($log['log_id'] ?? 0), $groupLogs));
        $logIds = array_values(array_filter($logIds, static fn(int $id): bool => $id > 0));
        $lastSeenRaw = (string)($latest['created_at'] ?? date('Y-m-d H:i:s'));

        $actions = [];
        if (!empty($logIds)) {
            $actions[] = [
                'action' => 'resolve_alert',
                'label' => 'Mark Incident Resolved',
                'variant' => 'secondary',
                'requires_reauth' => true,
                'action_label' => 'mark the incident resolved',
                'confirm_message' => 'Mark the currently listed warning/error events as resolved after you have handled them?',
            ];
        }

        $alerts[] = [
            'key' => $key,
            'severity' => $maxSeverity,
            'subsystem' => $descriptor['subsystem'],
            'title' => $descriptor['title'],
            'summary' => number_format(count($groupLogs)) . ' unresolved event(s) were recorded for this subsystem.',
            'cause' => systemHealthExtractContextMessage(is_array($latest['context'] ?? null) ? $latest['context'] : []) ?: systemHealthShortText((string)($latest['message'] ?? ''), 220),
            'recommended_fix' => $steps[0] ?? 'Inspect the subsystem and resolve the underlying issue.',
            'recommended_steps' => $steps,
            'alert_messages' => systemHealthBuildMessageList($messages),
            'metrics' => [
                ['label' => 'Occurrences', 'value' => number_format(count($groupLogs))],
                ['label' => 'Latest Event', 'value' => systemHealthFormatDate($lastSeenRaw)],
                ['label' => 'Event Code', 'value' => $eventCode !== '' ? systemHealthFormatLabel($eventCode) : 'Not supplied'],
            ],
            'actions' => $actions,
            'occurrences' => count($groupLogs),
            'last_seen_at' => systemHealthFormatDate($lastSeenRaw),
            'last_seen_at_raw' => $lastSeenRaw,
            'log_ids' => $logIds,
            'clears_automatically' => false,
        ];
    }

    return $alerts;
}

function resolveSystemHealthLogIds(mysqli $conn, array $logIds, array $actor = [], string $status = 'resolved', string $note = ''): int
{
    ensureSystemLogResolutionsTable($conn);

    $status = strtolower(trim($status));
    if (!in_array($status, ['acknowledged', 'resolved', 'dismissed'], true)) {
        $status = 'resolved';
    }

    $uniqueLogIds = array_values(array_unique(array_filter(array_map('intval', $logIds), static fn(int $id): bool => $id > 0)));
    if (empty($uniqueLogIds)) {
        return 0;
    }

    $resolvedById = (string)($actor['actor_id'] ?? ($_SESSION['userId'] ?? 'system'));
    $resolvedByName = (string)($actor['actor_name'] ?? ($_SESSION['userName'] ?? 'System'));
    $resolvedByRole = (string)($actor['actor_role'] ?? ($_SESSION['userRole'] ?? 'system'));
    $note = trim($note);

    $stmt = $conn->prepare("
        INSERT INTO tb_system_log_resolutions (
            log_id,
            resolution_status,
            resolution_note,
            resolved_by_id,
            resolved_by_name,
            resolved_by_role,
            resolved_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            resolution_status = VALUES(resolution_status),
            resolution_note = VALUES(resolution_note),
            resolved_by_id = VALUES(resolved_by_id),
            resolved_by_name = VALUES(resolved_by_name),
            resolved_by_role = VALUES(resolved_by_role),
            resolved_at = NOW()
    ");
    if (!$stmt) {
        return 0;
    }

    $resolved = 0;
    foreach ($uniqueLogIds as $logId) {
        $stmt->bind_param('isssss', $logId, $status, $note, $resolvedById, $resolvedByName, $resolvedByRole);
        if ($stmt->execute()) {
            $resolved++;
        }
    }
    $stmt->close();

    return $resolved;
}

function getSystemHealthSnapshot(mysqli $conn): array
{
    ensureSystemLogsTable($conn);
    ensureSystemLogResolutionsTable($conn);

    $dbConnected = $conn->ping();
    $memoryUsageBytes = memory_get_usage(true);
    $memoryPeakBytes = memory_get_peak_usage(true);
    $memoryLimitBytes = parseMemoryLimitToBytes(ini_get('memory_limit'));
    $memoryUsagePercent = $memoryLimitBytes > 0
        ? (float)round(($memoryUsageBytes / $memoryLimitBytes) * 100, 1)
        : 0.0;

    $diskTotalBytes = @disk_total_space(__DIR__);
    $diskFreeBytes = @disk_free_space(__DIR__);
    $diskUsedPercent = ($diskTotalBytes && $diskTotalBytes > 0)
        ? (float)round((($diskTotalBytes - $diskFreeBytes) / $diskTotalBytes) * 100, 1)
        : 0.0;

    $warningCount = countSystemHealthUnresolvedLogs($conn, 1, ['warning', 'error', 'critical']);
    $unresolvedSignals24h = countSystemHealthUnresolvedLogs($conn, 24, ['warning', 'error', 'critical']);
    $resolvedSignals24h = countSystemHealthRecentResolutions($conn, 24);
    $logs = fetchSystemHealthUnresolvedLogs($conn, 72, 120);
    $queueStats = getSystemHealthNotificationQueueStats($conn);

    $alerts = [];

    if (!$dbConnected) {
        $alerts[] = buildSystemHealthRuntimeAlert(
            'runtime-database-connectivity',
            'error',
            'Database',
            'Database connectivity failure',
            'The application cannot confirm a healthy connection to the database.',
            'MySQL did not respond successfully to the application health check.',
            [
                'Verify that the database service is running and reachable from the application server.',
                'Review database credentials, host configuration, and network access rules.',
                'Refresh diagnostics after connectivity is restored; this alert clears automatically.',
            ],
            [
                ['label' => 'Connection', 'value' => 'Failed'],
                ['label' => 'Detected At', 'value' => systemHealthFormatDate(date('Y-m-d H:i:s'))],
            ],
            ['Database ping failed.']
        );
    }

    if ($memoryUsagePercent >= 75) {
        $alerts[] = buildSystemHealthRuntimeAlert(
            'runtime-memory-pressure',
            $memoryUsagePercent >= 90 ? 'error' : 'warning',
            'Memory',
            $memoryUsagePercent >= 90 ? 'Critical memory pressure' : 'Elevated memory pressure',
            'PHP memory usage is above the configured safety threshold.',
            'Current memory consumption has crossed the operational threshold for this instance.',
            [
                'Review recent imports, exports, and background jobs that may be holding excessive memory.',
                'Raise the PHP memory limit only if sustained usage is expected and the host has spare capacity.',
                'This alert clears automatically after memory returns to a safe range.',
            ],
            [
                ['label' => 'Usage', 'value' => round($memoryUsageBytes / 1048576, 1) . ' MB'],
                ['label' => 'Limit', 'value' => $memoryLimitBytes > 0 ? round($memoryLimitBytes / 1048576, 1) . ' MB' : 'Unlimited'],
                ['label' => 'Utilization', 'value' => number_format($memoryUsagePercent, 1) . '%'],
            ],
            ['Memory utilization is ' . number_format($memoryUsagePercent, 1) . '%.']
        );
    }

    if ($diskUsedPercent >= 85) {
        $alerts[] = buildSystemHealthRuntimeAlert(
            'runtime-disk-pressure',
            $diskUsedPercent >= 95 ? 'error' : 'warning',
            'Storage',
            $diskUsedPercent >= 95 ? 'Critical disk pressure' : 'Elevated disk pressure',
            'Available disk capacity is below the recommended safety margin.',
            'The application host is approaching storage exhaustion, which can interrupt uploads, backups, and exports.',
            [
                'Archive or remove stale exports, backups, and other large artifacts from the host.',
                'Review retention settings for cleanup and export-heavy workflows.',
                'This alert clears automatically after free disk space returns to a safe level.',
            ],
            [
                ['label' => 'Disk Used', 'value' => number_format($diskUsedPercent, 1) . '%'],
                ['label' => 'Disk Free', 'value' => $diskFreeBytes > 0 ? round($diskFreeBytes / 1073741824, 1) . ' GB' : 'Unknown'],
                ['label' => 'Disk Total', 'value' => $diskTotalBytes > 0 ? round($diskTotalBytes / 1073741824, 1) . ' GB' : 'Unknown'],
            ],
            ['Disk utilization is ' . number_format($diskUsedPercent, 1) . '%.']
        );
    }

    $notificationAlert = buildSystemHealthNotificationAlert($logs, $queueStats);
    if ($notificationAlert) {
        $alerts[] = $notificationAlert;
    }

    foreach (buildSystemHealthGenericAlerts($logs) as $alert) {
        $alerts[] = $alert;
    }

    systemHealthSortAlerts($alerts);
    $primaryAlert = $alerts[0] ?? null;

    $healthStatus = 'healthy';
    if ($primaryAlert) {
        $healthStatus = (string)($primaryAlert['severity'] ?? 'warning');
    }

    $healthMessage = 'All systems operational.';
    if ($primaryAlert) {
        $suffix = count($alerts) > 1 ? ' Review diagnostics for additional affected subsystems.' : '';
        $healthMessage = rtrim((string)($primaryAlert['title'] ?? 'System warning')) . ' detected.' . $suffix;
    }

    $subsystems = [];
    foreach ($alerts as $alert) {
        $subsystem = trim((string)($alert['subsystem'] ?? ''));
        if ($subsystem !== '') {
            $subsystems[$subsystem] = true;
        }
    }

    $summaryCards = [
        [
            'label' => 'Open Incidents',
            'value' => number_format(count($alerts)),
            'detail' => count($alerts) > 0 ? 'Subsystem alerts still need attention.' : 'No active incidents.',
        ],
        [
            'label' => 'Impacted Subsystems',
            'value' => number_format(count($subsystems)),
            'detail' => !empty($subsystems) ? implode(', ', array_slice(array_keys($subsystems), 0, 3)) : 'All monitored subsystems are clear.',
        ],
        [
            'label' => 'Unresolved Signals (24h)',
            'value' => number_format($unresolvedSignals24h),
            'detail' => 'Warning and error events that still count against health.',
        ],
        [
            'label' => 'Resolved Incidents (24h)',
            'value' => number_format($resolvedSignals24h),
            'detail' => 'Diagnostics incidents cleared from the new health console.',
        ],
    ];

    if ((int)($queueStats['failed_email_total'] ?? 0) > 0) {
        $summaryCards[] = [
            'label' => 'Failed Emails',
            'value' => number_format((int)$queueStats['failed_email_total']),
            'detail' => 'Notification emails currently parked in a failed state.',
        ];
    }

    return [
        'status' => $healthStatus,
        'message' => $healthMessage,
        'detail' => $primaryAlert['summary'] ?? 'Platform diagnostics are within the expected range.',
        'primary_alert_key' => $primaryAlert['key'] ?? null,
        'primary_subsystem' => $primaryAlert['subsystem'] ?? null,
        'alerts' => $alerts,
        'diagnostic_summary' => $summaryCards,
        'diagnostics' => [
            'database_connected' => (bool)$dbConnected,
            'warning_count_1h' => (int)$warningCount,
            'memory_usage_mb' => round($memoryUsageBytes / 1048576, 1),
            'memory_peak_mb' => round($memoryPeakBytes / 1048576, 1),
            'memory_limit_mb' => $memoryLimitBytes > 0 ? round($memoryLimitBytes / 1048576, 1) : null,
            'memory_usage_percent' => (float)$memoryUsagePercent,
            'disk_total_gb' => $diskTotalBytes > 0 ? round($diskTotalBytes / 1073741824, 1) : null,
            'disk_free_gb' => $diskFreeBytes > 0 ? round($diskFreeBytes / 1073741824, 1) : null,
            'disk_used_percent' => (float)$diskUsedPercent,
            'generated_at' => date('Y-m-d H:i:s'),
            'open_alert_count' => count($alerts),
            'impacted_subsystems' => count($subsystems),
            'unresolved_signals_24h' => $unresolvedSignals24h,
            'resolved_signals_24h' => $resolvedSignals24h,
            'failed_email_notifications' => (int)($queueStats['failed_email_total'] ?? 0),
            'queued_email_notifications' => (int)($queueStats['queued_email_total'] ?? 0),
        ],
    ];
}
