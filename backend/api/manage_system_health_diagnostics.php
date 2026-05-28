<?php
header('Content-Type: application/json; charset=UTF-8');
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';
require_once __DIR__ . '/../system_health_tools.php';

function findSystemHealthAlertByKey(array $snapshot, string $alertKey): ?array
{
    foreach (($snapshot['alerts'] ?? []) as $alert) {
        if ((string)($alert['key'] ?? '') === $alertKey) {
            return $alert;
        }
    }

    return null;
}

function buildSystemHealthActor(): array
{
    return [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'Administrator',
        'actor_role' => $_SESSION['userRole'] ?? 'admin',
    ];
}

try {
    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request method'
        ]);
        exit;
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $action = strtolower(trim((string)($payload['action'] ?? '')));
    $alertKey = trim((string)($payload['alert_key'] ?? ''));
    $actor = buildSystemHealthActor();
    $snapshotBefore = getSystemHealthSnapshot($conn);
    $targetAlert = $alertKey !== '' ? findSystemHealthAlertByKey($snapshotBefore, $alertKey) : null;
    $message = '';
    $result = [];

    switch ($action) {
        case 'process_notification_queue':
            $queueResult = processNotificationQueue($conn, [
                'force' => true,
                'reason' => 'system_health_diagnostics',
                'actor_id' => $actor['actor_id'],
                'actor_name' => $actor['actor_name'],
                'actor_role' => $actor['actor_role'],
            ]);
            if (!($queueResult['success'] ?? false)) {
                throw new RuntimeException((string)($queueResult['message'] ?? 'Unable to process notification queue.'));
            }

            $result = $queueResult;
            $message = (string)($queueResult['message'] ?? 'Notification queue processed.');

            $failedAfterRun = (int)($queueResult['failed'] ?? 0);
            $notificationAlert = findSystemHealthAlertByKey($snapshotBefore, 'notification-email-delivery');
            if ($failedAfterRun === 0 && !empty($notificationAlert['log_ids'])) {
                resolveSystemHealthLogIds(
                    $conn,
                    (array)$notificationAlert['log_ids'],
                    $actor,
                    'resolved',
                    'Notification queue processed successfully from System Health Diagnostics.'
                );
                $message = 'Notification queue processed successfully and the current notification incident was marked resolved.';
            }

            logAuditEvent($conn, [
                'actor_id' => $actor['actor_id'],
                'actor_name' => $actor['actor_name'],
                'actor_role' => $actor['actor_role'],
                'action' => 'system_health_queue_processed',
                'entity_type' => 'system_health',
                'entity_id' => 'notification-email-delivery',
                'details' => [
                    'message' => $message,
                    'processed' => (int)($queueResult['processed'] ?? 0),
                    'sent' => (int)($queueResult['sent'] ?? 0),
                    'failed' => (int)($queueResult['failed'] ?? 0),
                    'skipped' => (int)($queueResult['skipped'] ?? 0),
                ],
            ]);
            break;

        case 'clear_failed_notification_queue':
            requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before clearing failed notification rows from diagnostics.');
            if (function_exists('ensureNotificationQueueTable')) {
                ensureNotificationQueueTable($conn);
            }

            $countResult = $conn->query("
                SELECT COUNT(*) AS total
                FROM tb_notification_queue
                WHERE channel = 'email'
                  AND status = 'failed'
            ");
            $affected = (int)(($countResult && ($row = $countResult->fetch_assoc())) ? ($row['total'] ?? 0) : 0);
            if ($countResult instanceof mysqli_result) {
                $countResult->close();
            }

            if ($affected > 0) {
                $conn->query("
                    DELETE FROM tb_notification_queue
                    WHERE channel = 'email'
                      AND status = 'failed'
                ");
            }

            $notificationAlert = $targetAlert ?: findSystemHealthAlertByKey($snapshotBefore, 'notification-email-delivery');
            $resolvedLogs = 0;
            if (!empty($notificationAlert['log_ids'])) {
                $resolvedLogs = resolveSystemHealthLogIds(
                    $conn,
                    (array)$notificationAlert['log_ids'],
                    $actor,
                    'resolved',
                    'Failed notification rows were cleared from System Health Diagnostics.'
                );
            }

            $result = [
                'affected' => $affected,
                'resolved_logs' => $resolvedLogs,
            ];
            $message = $affected > 0
                ? "Cleared {$affected} failed email notification row(s)." . ($resolvedLogs > 0 ? " Also resolved {$resolvedLogs} related warning/error log(s)." : '')
                : 'There were no failed email notification rows to clear.';

            logAuditEvent($conn, [
                'actor_id' => $actor['actor_id'],
                'actor_name' => $actor['actor_name'],
                'actor_role' => $actor['actor_role'],
                'action' => 'system_health_failed_notification_queue_cleared',
                'entity_type' => 'system_health',
                'entity_id' => 'notification-email-delivery',
                'details' => [
                    'affected_records' => $affected,
                    'resolved_logs' => $resolvedLogs,
                    'message' => $message,
                ],
            ]);

            if (function_exists('recordSystemLog')) {
                recordSystemLog($conn, [
                    'log_level' => 'info',
                    'log_category' => 'system_health',
                    'event_code' => 'diagnostic_failed_notifications_cleared',
                    'message' => 'Failed email notifications were cleared from System Health Diagnostics.',
                    'context' => [
                        'affected_records' => $affected,
                        'resolved_logs' => $resolvedLogs,
                    ],
                    'actor_id' => $actor['actor_id'],
                    'actor_name' => $actor['actor_name'],
                    'actor_role' => $actor['actor_role'],
                ]);
            }
            break;

        case 'resolve_alert':
            requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before resolving diagnostics incidents.');
            if ($alertKey === '') {
                throw new RuntimeException('A diagnostic alert key is required.');
            }

            if (!$targetAlert) {
                $message = 'The selected diagnostic alert is already clear.';
                break;
            }

            $logIds = array_values(array_filter(array_map('intval', (array)($targetAlert['log_ids'] ?? []))));
            if (empty($logIds)) {
                throw new RuntimeException('This alert clears automatically after the underlying condition is corrected.');
            }

            $resolved = resolveSystemHealthLogIds(
                $conn,
                $logIds,
                $actor,
                'resolved',
                'Incident resolved from System Health Diagnostics.'
            );
            $result = ['resolved_logs' => $resolved];
            $message = $resolved > 0
                ? "Marked {$resolved} diagnostic event(s) as resolved."
                : 'No unresolved diagnostic events matched this alert.';

            logAuditEvent($conn, [
                'actor_id' => $actor['actor_id'],
                'actor_name' => $actor['actor_name'],
                'actor_role' => $actor['actor_role'],
                'action' => 'system_health_alert_resolved',
                'entity_type' => 'system_health',
                'entity_id' => $alertKey,
                'details' => [
                    'resolved_logs' => $resolved,
                    'alert_title' => $targetAlert['title'] ?? 'Diagnostic incident',
                    'subsystem' => $targetAlert['subsystem'] ?? '',
                ],
            ]);

            if (function_exists('recordSystemLog')) {
                recordSystemLog($conn, [
                    'log_level' => 'info',
                    'log_category' => 'system_health',
                    'event_code' => 'diagnostic_incident_resolved',
                    'message' => 'A diagnostics incident was marked resolved from the admin console.',
                    'context' => [
                        'alert_key' => $alertKey,
                        'resolved_logs' => $resolved,
                        'title' => $targetAlert['title'] ?? '',
                        'subsystem' => $targetAlert['subsystem'] ?? '',
                    ],
                    'actor_id' => $actor['actor_id'],
                    'actor_name' => $actor['actor_name'],
                    'actor_role' => $actor['actor_role'],
                ]);
            }
            break;

        default:
            throw new RuntimeException('Unsupported diagnostics action.');
    }

    $snapshotAfter = getSystemHealthSnapshot($conn);

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => $message !== '' ? $message : 'Diagnostics action completed.',
        'action' => $action,
        'result' => $result,
        'snapshot' => $snapshotAfter,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $statusCode = stripos($message, 'admin access required') !== false ? 403 : (stripos($message, 're-enter your admin password') !== false ? 428 : 500);
    http_response_code($statusCode);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $message !== '' ? $message : 'Unable to run diagnostics action.',
        'requiresReauth' => $statusCode === 428,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
}
?>
