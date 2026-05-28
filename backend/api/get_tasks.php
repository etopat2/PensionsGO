<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

ensureTasksTable($conn);
ensureTaskCommentsTable($conn);

$userId = $_SESSION['userId'];
$userRole = $_SESSION['userRole'] ?? '';
$normalizedUserRole = normalizeWorkflowRoleKey($userRole);
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$includeDelegated = isset($_GET['include_delegated']) && $_GET['include_delegated'] === '1';

$query = "
    SELECT 
        t.taskId,
        t.created_by,
        creator.userName AS created_by_name,
        t.assigned_to,
        assignee.userName AS assigned_to_name,
        t.assigned_role,
        t.task_type,
        t.task_title,
        t.task_description,
        t.status,
        t.priority,
        t.related_staff_id,
        t.related_reg_no,
        t.due_at,
        t.declined_reason,
        t.metadata,
        t.updated_at,
        t.completed_at,
        t.timeStamp,
        TRIM(CONCAT(COALESCE(sd.sName, ''), ' ', COALESCE(sd.fName, ''))) AS applicant_name,
        sd.prisonUnit AS applicant_station,
        sd.title AS applicant_title,
        (SELECT COUNT(*) FROM tb_task_comments c WHERE c.task_id = t.taskId) as comment_count
    FROM tb_tasks t
    LEFT JOIN tb_users creator ON creator.userId = t.created_by
    LEFT JOIN tb_users assignee ON assignee.userId = t.assigned_to
    LEFT JOIN tb_staffdue sd
        ON (t.related_staff_id IS NOT NULL AND sd.id = t.related_staff_id)
        OR (t.related_staff_id IS NULL AND t.related_reg_no IS NOT NULL AND sd.regNo = t.related_reg_no)
    WHERE 1=1
";
$params = [];
$types = "";

$isAdmin = $normalizedUserRole === 'admin';
$inboxRoles = getWorkflowRoleKeysForInbox($userRole);
$rolePlaceholders = implode(',', array_fill(0, count($inboxRoles), '?'));

if (!$isAdmin) {
    if (empty($inboxRoles)) {
        $query .= " AND t.assigned_to = ?";
        $params[] = $userId;
        $types .= "s";
    } else {
        if ($includeDelegated) {
            $query .= " AND (
                t.assigned_to = ?
                OR (t.assigned_to IS NULL AND t.assigned_role IN ($rolePlaceholders))
                OR (t.created_by = ? AND (t.assigned_to IS NULL OR t.assigned_to <> ?))
            )";
            $params[] = $userId;
            foreach ($inboxRoles as $roleKey) {
                $params[] = $roleKey;
            }
            $params[] = $userId;
            $params[] = $userId;
            $types .= "s" . str_repeat("s", count($inboxRoles)) . "ss";
        } else {
            $query .= " AND (
                t.assigned_to = ?
                OR (t.assigned_to IS NULL AND t.assigned_role IN ($rolePlaceholders))
            )";
            $params[] = $userId;
            foreach ($inboxRoles as $roleKey) {
                $params[] = $roleKey;
            }
            $types .= "s" . str_repeat("s", count($inboxRoles));
        }
    }
}

if ($status !== '') {
    $query .= " AND t.status = ?";
    $params[] = $status;
    $types .= "s";
}

$query .= " ORDER BY t.timeStamp DESC";

$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    exit;
}

if ($types !== '' && !empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$tasks = [];

while ($row = $result->fetch_assoc()) {
    $isOverdue = false;
    // Overdue is policy-driven (business-day aware) and only applies
    // to non-terminal task states.
    if (!empty($row['due_at']) && !in_array($row['status'], ['completed', 'declined', 'cancelled'], true)) {
        $isOverdue = isTaskOverdueByPolicy($conn, (string)$row['due_at']);
    }

    // API payload ships both workflow fields and applicant context so
    // task pages can render cards/details without extra joins per row.
    $tasks[] = [
        'taskId' => (int)$row['taskId'],
        'created_by' => $row['created_by'],
        'created_by_name' => $row['created_by_name'] ?? null,
        'assigned_to' => $row['assigned_to'],
        'assigned_to_name' => $row['assigned_to_name'] ?? null,
        'assigned_role' => $row['assigned_role'],
        'task_type' => $row['task_type'],
        'task_title' => $row['task_title'],
        'task_description' => $row['task_description'],
        'status' => $row['status'],
        'priority' => $row['priority'],
        'related_staff_id' => $row['related_staff_id'],
        'related_reg_no' => $row['related_reg_no'],
        'due_at' => $row['due_at'],
        'declined_reason' => $row['declined_reason'],
        'metadata' => $row['metadata'],
        'updated_at' => $row['updated_at'],
        'completed_at' => $row['completed_at'],
        'created_at' => $row['timeStamp'],
        'applicant_name' => trim((string)($row['applicant_name'] ?? '')),
        'applicant_station' => $row['applicant_station'] ?? null,
        'applicant_title' => $row['applicant_title'] ?? null,
        'comment_count' => (int)$row['comment_count'],
        'is_overdue' => $isOverdue
    ];
}

$stmt->close();

echo json_encode(['success' => true, 'tasks' => $tasks]);
$conn->close();
?>
