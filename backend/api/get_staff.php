<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// Restrict pensioner access
if (!isset($_SESSION['userRole']) || $_SESSION['userRole'] === 'pensioner') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

if (function_exists('ensureStaffDueBaseColumns')) {
    ensureStaffDueBaseColumns($conn);
}

$stmt = $conn->prepare("SELECT * FROM tb_staffdue WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare query']);
    $conn->close();
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
    exit;
}

$staff = $result->fetch_assoc();
$staff['computerNo'] = (string)($staff['computerNo'] ?? ($staff['supplierNo'] ?? ''));
$staffId = (int)($staff['id'] ?? 0);
$appnNormalized = strtolower(trim((string)($staff['appnStatus'] ?? '')));
if ($appnNormalized === 'querried') {
    $appnNormalized = 'queried';
}
$workflowState = '';

if (in_array($appnNormalized, ['completed', 'in_process'], true)) {
    $workflowState = $appnNormalized;
} else {
    ensureApplicationQueueTable($conn);
    ensureTasksTable($conn);

    $queueStmt = $conn->prepare("SELECT status FROM tb_application_queue WHERE staffdue_id = ? LIMIT 1");
    if ($queueStmt) {
        $queueStmt->bind_param('i', $staffId);
        $queueStmt->execute();
        $queueRow = $queueStmt->get_result()->fetch_assoc();
        $queueStmt->close();
        $queueStatus = strtolower(trim((string)($queueRow['status'] ?? '')));
        if ($queueStatus === 'completed') {
            $workflowState = 'completed';
        } elseif (in_array($queueStatus, ['submitted_to_oc', 'in_progress'], true)) {
            $workflowState = 'in_process';
        }
    }

    if ($workflowState === '') {
        $completedStmt = $conn->prepare("
            SELECT 1
            FROM tb_tasks
            WHERE related_staff_id = ?
              AND task_type = 'approval'
              AND status = 'completed'
            LIMIT 1
        ");
        if ($completedStmt) {
            $completedStmt->bind_param('i', $staffId);
            $completedStmt->execute();
            $completedRow = $completedStmt->get_result()->fetch_assoc();
            $completedStmt->close();
            if ($completedRow) {
                $workflowState = 'completed';
            }
        }

        if ($workflowState === '') {
            $openStmt = $conn->prepare("
                SELECT 1
                FROM tb_tasks
                WHERE related_staff_id = ?
                  AND status IN ('pending','assigned','in_progress','deferred','returned')
                LIMIT 1
            ");
            if ($openStmt) {
                $openStmt->bind_param('i', $staffId);
                $openStmt->execute();
                $openRow = $openStmt->get_result()->fetch_assoc();
                $openStmt->close();
                if ($openRow) {
                    $workflowState = 'in_process';
                }
            }
        }
    }
}

$submissionState = strtolower(trim((string)($staff['submissionStatus'] ?? '')));
$effectiveStatus = 'pending';
if ($workflowState === 'completed') {
    $effectiveStatus = 'completed';
} elseif ($workflowState === 'in_process') {
    $effectiveStatus = 'in_process';
} elseif (in_array($appnNormalized, ['verified', 'queried', 'rejected'], true)) {
    $effectiveStatus = $appnNormalized;
} elseif ($submissionState === 'submitted') {
    $effectiveStatus = 'submitted';
}

$staff['appnStatusEffective'] = $effectiveStatus;
$staff['workflowActionState'] = $workflowState;

echo json_encode(['success' => true, 'record' => $staff]);
$stmt->close();
$conn->close();
?>
