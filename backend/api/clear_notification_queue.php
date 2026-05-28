<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';

try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        throw new RuntimeException('Admin access required');
    }

    requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before clearing notification queue records.');
    ensureNotificationQueueTable($conn);

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $scope = strtolower(trim((string)($payload['scope'] ?? 'filtered')));
    if (!in_array($scope, ['filtered', 'all'], true)) {
        $scope = 'filtered';
    }

    $status = strtolower(trim((string)($payload['status'] ?? '')));
    $channel = strtolower(trim((string)($payload['channel'] ?? '')));
    $search = trim((string)($payload['search'] ?? ''));

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($scope !== 'all') {
        if ($status !== '' && in_array($status, ['queued', 'sent', 'failed'], true)) {
            $where[] = 'status = ?';
            $types .= 's';
            $params[] = $status;
        }
        if ($channel !== '' && in_array($channel, ['email', 'sms', 'push'], true)) {
            $where[] = 'channel = ?';
            $types .= 's';
            $params[] = $channel;
        }
        if ($search !== '') {
            $where[] = '(recipient LIKE ? OR subject LIKE ? OR message LIKE ?)';
            $types .= 'sss';
            $searchLike = '%' . $search . '%';
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
        }
    }

    $countSql = 'SELECT COUNT(*) AS total FROM tb_notification_queue WHERE ' . implode(' AND ', $where);
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new RuntimeException('Unable to prepare notification queue count.');
    }
    if ($types !== '') {
        bindDynamicParams($countStmt, $types, $params);
    }
    $countStmt->execute();
    $affected = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $countStmt->close();

    if ($affected > 0) {
        $deleteSql = 'DELETE FROM tb_notification_queue WHERE ' . implode(' AND ', $where);
        $deleteStmt = $conn->prepare($deleteSql);
        if (!$deleteStmt) {
            throw new RuntimeException('Unable to prepare notification queue cleanup.');
        }
        if ($types !== '') {
            bindDynamicParams($deleteStmt, $types, $params);
        }
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    logAuditEvent($conn, [
        'actor_id' => $_SESSION['userId'] ?? 'system',
        'actor_name' => $_SESSION['userName'] ?? 'Administrator',
        'actor_role' => $_SESSION['userRole'] ?? 'admin',
        'action' => 'notification_queue_cleared',
        'entity_type' => 'notification_queue',
        'entity_id' => $scope,
        'details' => [
            'scope' => $scope,
            'status' => $status,
            'channel' => $channel,
            'search' => $search,
            'affected_records' => $affected
        ]
    ]);

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'warning',
            'log_category' => 'notification_queue',
            'event_code' => 'notification_queue_cleared',
            'message' => 'Notification queue records were cleared from the admin console.',
            'context' => [
                'scope' => $scope,
                'status' => $status,
                'channel' => $channel,
                'search' => $search,
                'affected_records' => $affected
            ],
            'actor_id' => $_SESSION['userId'] ?? 'system',
            'actor_name' => $_SESSION['userName'] ?? 'Administrator',
            'actor_role' => $_SESSION['userRole'] ?? 'admin'
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => $affected > 0
            ? "Cleared {$affected} notification queue record(s)."
            : 'No notification queue records matched the selected scope.',
        'affected' => $affected
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $statusCode = stripos($message, 'admin access required') !== false ? 403 : (stripos($message, 're-enter your admin password') !== false ? 428 : 500);
    http_response_code($statusCode);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'requiresReauth' => $statusCode === 428
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
