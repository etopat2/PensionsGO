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

$role = $_SESSION['userRole'] ?? '';
$allowedRoles = ['super_admin', 'admin', 'clerk', 'data_entry'];
if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$queueId = isset($payload['queue_id']) ? (int)$payload['queue_id'] : 0;
$action = isset($payload['action']) ? trim($payload['action']) : '';
$priority = strtolower(trim($payload['priority'] ?? 'normal'));
if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
    $priority = 'normal';
}

if ($queueId <= 0 || $action === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

ensureApplicationQueueTable($conn);
ensureTasksTable($conn);
ensureStaffDueWorkflowColumns($conn);

$ownerStmt = $conn->prepare("
    SELECT queue_id
    FROM tb_application_queue
    WHERE queue_id = ?
      AND (verified_by = ? OR submitted_by = ?)
    LIMIT 1
");
if (!$ownerStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to validate queue ownership']);
    exit;
}
$ownerStmt->bind_param("iss", $queueId, $_SESSION['userId'], $_SESSION['userId']);
$ownerStmt->execute();
$ownerResult = $ownerStmt->get_result();
$ownedQueue = $ownerResult ? $ownerResult->fetch_assoc() : null;
$ownerStmt->close();

if (!$ownedQueue) {
    echo json_encode(['success' => false, 'message' => 'Access denied for this queue item']);
    exit;
}

if ($action === 'drop') {
    $staffId = null;
    $regNo = '';
    $staffLookup = $conn->prepare("
        SELECT staffdue_id, regNo
        FROM tb_application_queue
        WHERE queue_id = ?
        LIMIT 1
    ");
    if ($staffLookup) {
        $staffLookup->bind_param("i", $queueId);
        $staffLookup->execute();
        $staffRow = $staffLookup->get_result()->fetch_assoc();
        $staffLookup->close();
        if ($staffRow) {
            $staffId = (int)($staffRow['staffdue_id'] ?? 0);
            $regNo = (string)($staffRow['regNo'] ?? '');
        }
    }

    $stmt = $conn->prepare("
        UPDATE tb_application_queue
        SET status = 'dropped',
            current_stage = 'dropped',
            notes = 'Dropped from queue'
        WHERE queue_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param("i", $queueId);
        $stmt->execute();
        $stmt->close();
    }

    if ($staffId > 0) {
        $staffStmt = $conn->prepare("
            UPDATE tb_staffdue
            SET appnStatus = 'pending',
                submissionStatus = 'submitted',
                submission_at = COALESCE(submission_at, NOW()),
                submission_by = COALESCE(submission_by, ?),
                appn_status_reason = 'Removed from verified queue',
                appn_status_at = NOW(),
                appn_status_by = ?
            WHERE id = ?
        ");
        if ($staffStmt) {
            $staffStmt->bind_param("ssi", $_SESSION['userId'], $_SESSION['userId'], $staffId);
            $staffStmt->execute();
            $staffStmt->close();
        }

        if (function_exists('recordWorkflowLog')) {
            recordWorkflowLog($conn, [
                'task_id' => null,
                'staffdue_id' => $staffId,
                'regNo' => $regNo,
                'action' => 'verification_queue_dropped',
                'from_status' => 'verified',
                'to_status' => 'submitted',
                'actor_id' => $_SESSION['userId'] ?? '',
                'actor_name' => $_SESSION['userName'] ?? 'System User',
                'actor_role' => $_SESSION['userRole'] ?? '',
                'note' => 'Removed from verified queue'
            ]);
        }

        $cancelStmt = $conn->prepare("
            UPDATE tb_tasks
            SET status = 'cancelled',
                declined_reason = COALESCE(declined_reason, 'Dropped from verified queue'),
                updated_at = NOW()
            WHERE status IN ('pending','assigned','in_progress','deferred','returned')
              AND (related_staff_id = ? OR (related_reg_no = ? AND related_reg_no <> ''))
        ");
        if ($cancelStmt) {
            $cancelStmt->bind_param("is", $staffId, $regNo);
            $cancelStmt->execute();
            $cancelStmt->close();
        }
    }

    echo json_encode(['success' => true, 'message' => 'Queue item dropped.']);
    $conn->close();
    exit;
}

if ($action === 'submit_to_oc') {
    $stmt = $conn->prepare("
        UPDATE tb_application_queue
        SET status = 'submitted_to_oc',
            current_stage = 'authorize_writeup',
            submitted_by = ?,
            submitted_at = NOW()
        WHERE queue_id = ? AND status = 'verified'
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to update queue']);
        exit;
    }

    $stmt->bind_param("si", $_SESSION['userId'], $queueId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    if ($updated) {
        $meta = [];
        $staffId = null;
        $regNo = null;

        $processReason = 'In process - submitted to OC/Pen for write-up authorization';
        $staffProcessStmt = $conn->prepare("
            UPDATE tb_staffdue
            SET appnStatus = 'in_process',
                appn_status_at = NOW(),
                appn_status_by = ?,
                appn_status_reason = ?
            WHERE id = (
                SELECT staffdue_id
                FROM tb_application_queue
                WHERE queue_id = ?
                LIMIT 1
            )
        ");
        if ($staffProcessStmt) {
            $staffProcessStmt->bind_param("ssi", $_SESSION['userId'], $processReason, $queueId);
            $staffProcessStmt->execute();
            $staffProcessStmt->close();
        }

        $staffStmt = $conn->prepare("
            SELECT staffdue_id, regNo
            FROM tb_application_queue
            WHERE queue_id = ?
            LIMIT 1
        ");
        if ($staffStmt) {
            $staffStmt->bind_param("i", $queueId);
            $staffStmt->execute();
            $staffResult = $staffStmt->get_result();
            if ($row = $staffResult->fetch_assoc()) {
                $staffId = (int)$row['staffdue_id'];
                $regNo = $row['regNo'];
            }
            $staffStmt->close();
        }

        $meta = [
            'queue_id' => $queueId,
            'stage' => 'authorize_writeup'
        ];

        createWorkflowTask($conn, [
            'created_by' => $_SESSION['userId'],
            'assigned_role' => 'oc_pen',
            'task_type' => 'authorize_writeup',
            'task_title' => 'Authorize Write-up',
            'task_description' => 'Authorize write-up for verified application.',
            'status' => 'pending',
            'priority' => $priority,
            'related_staff_id' => $staffId,
            'related_reg_no' => $regNo,
            'metadata' => $meta
        ]);

        if (function_exists('recordWorkflowLog')) {
            recordWorkflowLog($conn, [
                'task_id' => null,
                'staffdue_id' => $staffId,
                'regNo' => $regNo,
                'action' => 'verification_submitted_to_oc',
                'from_status' => 'verified',
                'to_status' => 'in_process',
                'actor_id' => $_SESSION['userId'] ?? '',
                'actor_name' => $_SESSION['userName'] ?? 'System User',
                'actor_role' => $_SESSION['userRole'] ?? '',
                'metadata' => $meta
            ]);
        }
    }

    echo json_encode([
        'success' => $updated,
        'message' => $updated ? 'Submitted to OC/Pen.' : 'Queue item not updated.'
    ]);
    $conn->close();
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unsupported action']);
$conn->close();
?>
