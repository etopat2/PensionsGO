<?php
/**
 * 
 * get_notification_queue.php
 * Purpose: Fetch notification queue entries for admin console
 * Access: Admin only
 * 
 */

header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../runtime_admin_tools.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required'
        ]);
        exit;
    }

    if (function_exists('ensureNotificationQueueTable')) {
        ensureNotificationQueueTable($conn);
    }
    if (function_exists('maybeQueueDailyAdminDigest')) {
        maybeQueueDailyAdminDigest($conn);
    }
    if (function_exists('maybeQueueAnalyticsDigest')) {
        maybeQueueAnalyticsDigest($conn);
    }
    if (function_exists('maybeProcessNotificationQueue')) {
        maybeProcessNotificationQueue($conn, ['reason' => 'admin_queue_view']);
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $channel = isset($_GET['channel']) ? trim($_GET['channel']) : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $query = "
        SELECT
            notification_id,
            channel,
            recipient,
            subject,
            message,
            status,
            meta,
            attempts,
            last_attempted_at,
            sent_at,
            failed_at,
            last_error,
            created_at
        FROM tb_notification_queue
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    if ($status !== '') {
        $query .= " AND status = ?";
        $params[] = $status;
        $types .= 's';
    }

    if ($channel !== '') {
        $query .= " AND channel = ?";
        $params[] = $channel;
        $types .= 's';
    }

    if ($search !== '') {
        $query .= " AND (recipient LIKE ? OR subject LIKE ? OR message LIKE ?)";
        $searchLike = '%' . $search . '%';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'sss';
    }

    $countQuery = "SELECT COUNT(*) as total FROM ($query) as filtered";
    if (!empty($params)) {
        $countStmt = $conn->prepare($countQuery);
        if ($countStmt === false) {
            throw new Exception('Failed to prepare count query: ' . $conn->error);
        }
        bindParams($countStmt, $types, $params);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRecords = (int)($countResult->fetch_assoc()['total'] ?? 0);
        $countStmt->close();
    } else {
        $countResult = $conn->query($countQuery);
        if ($countResult === false) {
            throw new Exception('Failed to execute count query: ' . $conn->error);
        }
        $totalRecords = (int)($countResult->fetch_assoc()['total'] ?? 0);
    }

    $query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception('Failed to prepare query: ' . $conn->error);
    }

    if (!empty($params)) {
        bindParams($stmt, $types, $params);
    }

    if (!$stmt->execute()) {
        throw new Exception('Failed to execute query: ' . $stmt->error);
    }

    $result = $stmt->get_result();
    $queue = [];

    while ($row = $result->fetch_assoc()) {
        $metaLabel = '';
        if (!empty($row['meta'])) {
            $metaPayload = json_decode($row['meta'], true);
            if (is_array($metaPayload)) {
                if (!empty($metaPayload['source'])) {
                    $metaLabel = $metaPayload['source'];
                } elseif (!empty($metaPayload['event'])) {
                    $metaLabel = $metaPayload['event'];
                } elseif (!empty($metaPayload['category'])) {
                    $metaLabel = $metaPayload['category'];
                }
            }
        }

        $queue[] = [
            'notification_id' => (int)$row['notification_id'],
            'channel' => $row['channel'],
            'recipient' => $row['recipient'],
            'subject' => $row['subject'],
            'message' => $row['message'],
            'status' => $row['status'],
            'meta' => $row['meta'],
            'meta_label' => $metaLabel,
            'attempts' => (int)($row['attempts'] ?? 0),
            'last_attempted_at' => $row['last_attempted_at'],
            'sent_at' => $row['sent_at'],
            'failed_at' => $row['failed_at'],
            'last_error' => $row['last_error'],
            'created_at' => $row['created_at'],
            'created_date' => date('M j, Y g:i A', strtotime($row['created_at']))
        ];
    }

    $stmt->close();

    $summary = [
        'total' => 0,
        'queued' => 0,
        'sent' => 0,
        'failed' => 0
    ];
    $summaryResult = $conn->query("
        SELECT status, COUNT(*) as count
        FROM tb_notification_queue
        GROUP BY status
    ");
    if ($summaryResult) {
        while ($row = $summaryResult->fetch_assoc()) {
            $statusKey = $row['status'] ?? '';
            if ($statusKey !== '' && array_key_exists($statusKey, $summary)) {
                $summary[$statusKey] = (int)$row['count'];
            }
        }
        $summary['total'] = array_sum($summary);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'queue' => $queue,
        'summary' => $summary,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => (int)ceil($totalRecords / $limit)
        ],
        'filters' => [
            'status' => $status,
            'channel' => $channel,
            'search' => $search
        ]
    ]);
} catch (Throwable $e) {
    ob_clean();
    error_log("Error fetching notification queue: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching notification queue: ' . $e->getMessage()
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    ob_end_flush();
}

function bindParams(mysqli_stmt $stmt, string $types, array $params): void {
    if (empty($params)) {
        return;
    }

    $bind = [];
    $bind[] = $types;

    foreach ($params as $key => $value) {
        if (isset($types[$key]) && $types[$key] === 'i') {
            $params[$key] = (int)$value;
        }
        $bind[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}
?>

