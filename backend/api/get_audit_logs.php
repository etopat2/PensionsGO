<?php
/**
 * 
 * get_audit_logs.php
 * Purpose: Fetch audit logs for admin console
 * Access: Admin only
 * 
 */

header('Content-Type: application/json');
ob_start();

try {
    require_once __DIR__ . '/../config.php';

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

    if (function_exists('ensureAuditLogsTable')) {
        ensureAuditLogsTable($conn);
    }
    if (function_exists('ensurePayrollManagementTables')) {
        ensurePayrollManagementTables($conn);
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $action = isset($_GET['action']) ? trim($_GET['action']) : '';
    $actorRole = isset($_GET['actor_role']) ? trim($_GET['actor_role']) : '';
    $actor = isset($_GET['actor']) ? trim($_GET['actor']) : '';
    $dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

    $baseQuery = "
        SELECT
            CAST(a.audit_id AS SIGNED) AS audit_id,
            CONVERT(COALESCE(a.actor_id, 'system') USING utf8mb4) COLLATE utf8mb4_general_ci AS actor_id,
            CONVERT(COALESCE(a.actor_name, 'System') USING utf8mb4) COLLATE utf8mb4_general_ci AS actor_name,
            CONVERT(COALESCE(a.actor_role, 'system') USING utf8mb4) COLLATE utf8mb4_general_ci AS actor_role,
            CONVERT(COALESCE(a.action, 'audit_event') USING utf8mb4) COLLATE utf8mb4_general_ci AS action,
            CONVERT(COALESCE(a.entity_type, 'system') USING utf8mb4) COLLATE utf8mb4_general_ci AS entity_type,
            CONVERT(COALESCE(a.entity_id, '') USING utf8mb4) COLLATE utf8mb4_general_ci AS entity_id,
            CONVERT(COALESCE(a.details, '') USING utf8mb4) COLLATE utf8mb4_general_ci AS details,
            a.created_at AS created_at
        FROM tb_audit_logs a

        UNION ALL

        SELECT
            CAST(1000000000 + p.audit_id AS SIGNED) AS audit_id,
            CONVERT(COALESCE(p.actor_user_id, 'system') USING utf8mb4) COLLATE utf8mb4_general_ci AS actor_id,
            CONVERT(COALESCE(u.userName, 'System') USING utf8mb4) COLLATE utf8mb4_general_ci AS actor_name,
            CONVERT(COALESCE(NULLIF(p.actor_role, ''), 'system') USING utf8mb4) COLLATE utf8mb4_general_ci AS actor_role,
            CONVERT(COALESCE(NULLIF(p.action, ''), 'payroll_event') USING utf8mb4) COLLATE utf8mb4_general_ci AS action,
            CONVERT('payroll_cycle' USING utf8mb4) COLLATE utf8mb4_general_ci AS entity_type,
            CONVERT(COALESCE(CAST(p.cycle_id AS CHAR), '') USING utf8mb4) COLLATE utf8mb4_general_ci AS entity_id,
            CONVERT(COALESCE(p.details, '') USING utf8mb4) COLLATE utf8mb4_general_ci AS details,
            p.created_at AS created_at
        FROM tb_payroll_audit_logs p
        LEFT JOIN tb_users u ON u.userId = p.actor_user_id
    ";

    $query = "SELECT audit_id, actor_id, actor_name, actor_role, action, entity_type, entity_id, details, created_at FROM ({$baseQuery}) logs WHERE 1=1";

    $params = [];
    $types = '';

    if ($action !== '') {
        $query .= " AND logs.action = ?";
        $params[] = $action;
        $types .= 's';
    }

    if ($actorRole !== '') {
        $query .= " AND logs.actor_role = ?";
        $params[] = $actorRole;
        $types .= 's';
    }

    if ($actor !== '') {
        $query .= " AND (logs.actor_name LIKE ? OR logs.actor_id LIKE ?)";
        $actorLike = '%' . $actor . '%';
        $params[] = $actorLike;
        $params[] = $actorLike;
        $types .= 'ss';
    }

    if ($dateFrom !== '') {
        $query .= " AND DATE(logs.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }

    if ($dateTo !== '') {
        $query .= " AND DATE(logs.created_at) <= ?";
        $params[] = $dateTo;
        $types .= 's';
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

    $query .= " ORDER BY logs.created_at DESC LIMIT ? OFFSET ?";
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
    $logs = [];

    while ($row = $result->fetch_assoc()) {
        $logs[] = [
            'audit_id' => (int)$row['audit_id'],
            'actor_id' => $row['actor_id'],
            'actor_name' => $row['actor_name'],
            'actor_role' => $row['actor_role'],
            'action' => $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'],
            'details' => $row['details'],
            'created_at' => $row['created_at'],
            'created_date' => date('M j, Y g:i A', strtotime($row['created_at']))
        ];
    }

    $stmt->close();

    ob_clean();
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total_records' => $totalRecords,
            'total_pages' => (int)ceil($totalRecords / $limit)
        ],
        'filters' => [
            'action' => $action,
            'actor_role' => $actorRole,
            'actor' => $actor,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]
    ]);
} catch (Throwable $e) {
    ob_clean();
    error_log("Error fetching audit logs: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching audit logs: ' . $e->getMessage()
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

