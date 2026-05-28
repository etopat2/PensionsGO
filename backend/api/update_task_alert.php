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

ensureTaskAlertsTable($conn);

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$alertId = (int)($payload['alert_id'] ?? 0);
$action = strtolower(trim((string)($payload['action'] ?? '')));
if ($alertId <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$validActions = ['acknowledge', 'dismiss', 'resolve', 'reopen'];
if (!in_array($action, $validActions, true)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported action']);
    exit;
}

$userId = (string)($_SESSION['userId'] ?? '');
$userRole = (string)($_SESSION['userRole'] ?? '');
$normalizedRole = normalizeWorkflowRoleKey($userRole);
$isPrivileged = in_array($normalizedRole, ['admin', 'oc_pen'], true);

$stmt = $conn->prepare("
    SELECT alert_id, task_id, assigned_to, assigned_role, alert_status
    FROM tb_task_alerts
    WHERE alert_id = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to load alert record']);
    exit;
}
$stmt->bind_param("i", $alertId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Alert not found']);
    exit;
}

$assignedTo = (string)($row['assigned_to'] ?? '');
$assignedRole = (string)($row['assigned_role'] ?? '');
$isAssignee = ($assignedTo !== '' && $assignedTo === $userId)
    || ($assignedTo === '' && $assignedRole !== '' && rolesAreWorkflowEquivalent($assignedRole, $userRole));

if (!$isPrivileged && !$isAssignee) {
    echo json_encode(['success' => false, 'message' => 'You are not allowed to update this alert.']);
    exit;
}

if ($action === 'reopen' && !$isPrivileged) {
    echo json_encode(['success' => false, 'message' => 'Only administrators can reopen alerts.']);
    exit;
}

$statusBefore = strtolower(trim((string)($row['alert_status'] ?? '')));
$updateSql = '';
$types = '';
$params = [];

switch ($action) {
    case 'acknowledge':
        $updateSql = "
            UPDATE tb_task_alerts
            SET alert_status = 'acknowledged',
                acknowledged_at = NOW(),
                acknowledged_by = ?,
                last_evaluated_at = NOW()
            WHERE alert_id = ?
              AND alert_status IN ('open', 'acknowledged')
        ";
        $types = 'si';
        $params = [$userId, $alertId];
        break;

    case 'dismiss':
        $updateSql = "
            UPDATE tb_task_alerts
            SET alert_status = 'dismissed',
                resolved_at = NOW(),
                resolved_by = ?,
                last_evaluated_at = NOW()
            WHERE alert_id = ?
              AND alert_status IN ('open', 'acknowledged', 'dismissed')
        ";
        $types = 'si';
        $params = [$userId, $alertId];
        break;

    case 'resolve':
        $updateSql = "
            UPDATE tb_task_alerts
            SET alert_status = 'resolved',
                resolved_at = NOW(),
                resolved_by = ?,
                last_evaluated_at = NOW()
            WHERE alert_id = ?
              AND alert_status IN ('open', 'acknowledged', 'resolved')
        ";
        $types = 'si';
        $params = [$userId, $alertId];
        break;

    case 'reopen':
        $updateSql = "
            UPDATE tb_task_alerts
            SET alert_status = 'open',
                acknowledged_at = NULL,
                acknowledged_by = NULL,
                resolved_at = NULL,
                resolved_by = NULL,
                triggered_at = NOW(),
                last_evaluated_at = NOW()
            WHERE alert_id = ?
        ";
        $types = 'i';
        $params = [$alertId];
        break;
}

$updateStmt = $conn->prepare($updateSql);
if (!$updateStmt) {
    echo json_encode(['success' => false, 'message' => 'Unable to update alert state.']);
    exit;
}
$updateStmt->bind_param($types, ...$params);
$updateStmt->execute();
$affected = (int)$updateStmt->affected_rows;
$updateStmt->close();

if ($affected <= 0) {
    echo json_encode(['success' => true, 'message' => 'No changes were required.']);
    exit;
}

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $userId,
        'actor_name' => $_SESSION['userName'] ?? 'System User',
        'actor_role' => $userRole,
        'action' => 'task_alert_' . $action,
        'entity_type' => 'task_alert',
        'entity_id' => (string)$alertId,
        'details' => [
            'task_id' => (int)($row['task_id'] ?? 0),
            'status_before' => $statusBefore
        ]
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Alert updated successfully.'
]);

$conn->close();
?>
