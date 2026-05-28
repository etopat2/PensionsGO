<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$role = strtolower((string)($_SESSION['userRole'] ?? ''));
if (!currentUserHasPermission($conn, 'registry.delete_queue.process')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureRegistryDeleteQueueTable')) {
    ensureRegistryDeleteQueueTable($conn);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$requestId = (int)($payload['request_id'] ?? 0);
$action = strtolower(trim((string)($payload['action'] ?? '')));
$note = trim((string)($payload['note'] ?? ''));

if ($requestId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
    exit;
}
if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}
if ($action === 'reject' && $note === '') {
    echo json_encode(['success' => false, 'message' => 'Rejection note is required']);
    exit;
}

$processorId = (string)($_SESSION['userId'] ?? '');
$processorName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown');
$processorRole = $role;

$conn->begin_transaction();

try {
    $selectStmt = $conn->prepare("
        SELECT request_id, registry_id, regNo, status, reason
        FROM tb_file_registry_delete_requests
        WHERE request_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    if (!$selectStmt) {
        throw new Exception('Failed to prepare request query');
    }
    $selectStmt->bind_param("i", $requestId);
    $selectStmt->execute();
    $result = $selectStmt->get_result();
    $request = $result ? $result->fetch_assoc() : null;
    $selectStmt->close();

    if (!$request) {
        throw new Exception('Delete request not found');
    }

    if (($request['status'] ?? '') !== 'pending') {
        throw new Exception('This delete request has already been processed');
    }

    $registryId = (int)($request['registry_id'] ?? 0);
    $regNo = (string)($request['regNo'] ?? '');
    $updateStatus = $action === 'approve' ? 'approved' : 'rejected';
    $message = $action === 'approve' ? 'Delete request approved and record removed' : 'Delete request rejected';

    if ($action === 'approve') {
        $recordStmt = $conn->prepare("
            SELECT *
            FROM tb_fileregistry
            WHERE id = ? AND COALESCE(is_deleted, 0) = 0
            LIMIT 1
            FOR UPDATE
        ");
        if ($recordStmt) {
            $recordStmt->bind_param("i", $registryId);
            $recordStmt->execute();
            $record = $recordStmt->get_result()->fetch_assoc();
            $recordStmt->close();
        } else {
            $record = null;
        }

        $deleteResult = function_exists('softDeleteRegistryRecord')
            ? softDeleteRegistryRecord(
                $conn,
                $registryId,
                $processorId,
                $processorName,
                $processorRole,
                (string)($request['reason'] ?? ''),
                $requestId
            )
            : ['success' => false, 'message' => 'Registry soft delete helper is unavailable.'];
        if (empty($deleteResult['success'])) {
            throw new Exception($deleteResult['message'] ?? 'Failed to delete registry record.');
        }
        $deletedRows = !empty($deleteResult['deleted']) || !empty($deleteResult['already_deleted']) ? 1 : 0;
        if ($deletedRows === 0) {
            $message = 'Delete request approved. Registry record was already removed';
        } elseif (function_exists('deletePensionerUsersByRegistryRegNo')) {
            $cascadeResult = deletePensionerUsersByRegistryRegNo(
                $conn,
                $regNo,
                $processorId,
                $processorName,
                $processorRole
            );
            if (empty($cascadeResult['success'])) {
                throw new Exception($cascadeResult['message'] ?? 'Failed to delete linked pensioner account');
            }
        }
    }

    $updateStmt = $conn->prepare("
        UPDATE tb_file_registry_delete_requests
        SET status = ?,
            processed_by = ?,
            processed_by_name = ?,
            processed_by_role = ?,
            processed_note = ?,
            processed_at = NOW()
        WHERE request_id = ?
    ");
    if (!$updateStmt) {
        throw new Exception('Failed to prepare status update query');
    }
    $updateStmt->bind_param(
        "sssssi",
        $updateStatus,
        $processorId,
        $processorName,
        $processorRole,
        $note,
        $requestId
    );
    if (!$updateStmt->execute()) {
        $error = $updateStmt->error;
        $updateStmt->close();
        throw new Exception($error ?: 'Failed to update request status');
    }
    $updateStmt->close();

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => $processorId,
            'actor_name' => $processorName,
            'actor_role' => $processorRole,
            'action' => $action === 'approve' ? 'registry_delete_approved' : 'registry_delete_rejected',
            'entity_type' => 'tb_fileregistry',
            'entity_id' => (string)$registryId,
            'details' => [
                'request_id' => $requestId,
                'regNo' => $regNo,
                'reason' => (string)($request['reason'] ?? ''),
                'note' => $note
            ]
        ]);
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

$conn->close();
?>
