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
if (!currentUserHasPermission($conn, 'registry.delete_request')) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureFileMovementTables')) {
    ensureFileMovementTables($conn);
}
if (function_exists('ensureRegistryDeleteQueueTable')) {
    ensureRegistryDeleteQueueTable($conn);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

$registryId = (int)($payload['registry_id'] ?? 0);
$reason = trim((string)($payload['reason'] ?? ''));

if ($registryId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid registry record']);
    exit;
}
if ($reason === '') {
    echo json_encode(['success' => false, 'message' => 'Delete reason is required']);
    exit;
}
if (strlen($reason) > 1000) {
    $reason = substr($reason, 0, 1000);
}

$recordStmt = $conn->prepare("
    SELECT *
    FROM tb_fileregistry
    WHERE id = ? AND COALESCE(is_deleted, 0) = 0
    LIMIT 1
");
if (!$recordStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare lookup query']);
    exit;
}
$recordStmt->bind_param("i", $registryId);
$recordStmt->execute();
$recordResult = $recordStmt->get_result();
$record = $recordResult ? $recordResult->fetch_assoc() : null;
$recordStmt->close();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Registry record not found']);
    exit;
}

$pendingStmt = $conn->prepare("
    SELECT request_id
    FROM tb_file_registry_delete_requests
    WHERE registry_id = ? AND status = 'pending'
    LIMIT 1
");
if (!$pendingStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare pending query']);
    exit;
}
$pendingStmt->bind_param("i", $registryId);
$pendingStmt->execute();
$pendingRes = $pendingStmt->get_result();
$pendingRow = $pendingRes ? $pendingRes->fetch_assoc() : null;
$pendingStmt->close();

if ($pendingRow) {
    echo json_encode(['success' => false, 'message' => 'A pending delete request already exists for this file']);
    exit;
}

$requestedBy = (string)($_SESSION['userId'] ?? '');
$requestedByName = (string)($_SESSION['userName'] ?? $_SESSION['name'] ?? 'Unknown');
$requestedByRole = $role;
$regNo = (string)($record['regNo'] ?? '');
$staffName = trim(trim((string)($record['sName'] ?? '')) . ' ' . trim((string)($record['fName'] ?? '')));
$staffTitle = trim((string)($record['title'] ?? ''));
$canDeleteImmediately = ($role === 'admin') || (function_exists('isOcPenEquivalentRole') && isOcPenEquivalentRole($role));

if ($canDeleteImmediately) {
    $conn->begin_transaction();
    try {
        $pendingUpdateStmt = $conn->prepare("
            UPDATE tb_file_registry_delete_requests
            SET status = 'approved',
                processed_by = ?,
                processed_by_name = ?,
                processed_by_role = ?,
                processed_note = ?,
                processed_at = NOW()
            WHERE registry_id = ? AND status = 'pending'
        ");
        if ($pendingUpdateStmt) {
            $directNote = 'Direct deletion by privileged user. ' . $reason;
            $pendingUpdateStmt->bind_param(
                "ssssi",
                $requestedBy,
                $requestedByName,
                $requestedByRole,
                $directNote,
                $registryId
            );
            $pendingUpdateStmt->execute();
            $pendingUpdateStmt->close();
        }

        $deleteResult = function_exists('softDeleteRegistryRecord')
            ? softDeleteRegistryRecord($conn, $registryId, $requestedBy, $requestedByName, $requestedByRole, $reason, null)
            : ['success' => false, 'message' => 'Registry soft delete helper is unavailable.'];
        if (empty($deleteResult['success'])) {
            throw new RuntimeException($deleteResult['message'] ?? 'Failed to delete registry record.');
        }
        $deletedRows = !empty($deleteResult['deleted']) || !empty($deleteResult['already_deleted']) ? 1 : 0;

        if ($deletedRows > 0 && function_exists('deletePensionerUsersByRegistryRegNo')) {
            $cascadeResult = deletePensionerUsersByRegistryRegNo(
                $conn,
                $regNo,
                $requestedBy,
                $requestedByName,
                $requestedByRole
            );
            if (empty($cascadeResult['success'])) {
                throw new RuntimeException($cascadeResult['message'] ?? 'Failed to delete linked pensioner account');
            }
        }

        if (function_exists('logAuditEvent')) {
            logAuditEvent($conn, [
                'actor_id' => $requestedBy,
                'actor_name' => $requestedByName,
                'actor_role' => $requestedByRole,
                'action' => 'registry_deleted',
                'entity_type' => 'tb_fileregistry',
                'entity_id' => (string)$registryId,
                'details' => [
                    'regNo' => $regNo,
                    'reason' => $reason,
                    'mode' => 'direct_privileged_delete'
                ]
            ]);
        }

        $conn->commit();
        echo json_encode([
            'success' => true,
            'direct_delete' => true,
            'message' => $deletedRows > 0 ? 'Registry record deleted successfully' : 'Registry record was already removed'
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}

$insertStmt = $conn->prepare("
    INSERT INTO tb_file_registry_delete_requests (
        registry_id, regNo, staff_name, staff_title, requested_by, requested_by_name, requested_by_role, reason, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");

if (!$insertStmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to prepare insert query']);
    exit;
}

$insertStmt->bind_param(
    "isssssss",
    $registryId,
    $regNo,
    $staffName,
    $staffTitle,
    $requestedBy,
    $requestedByName,
    $requestedByRole,
    $reason
);

if (!$insertStmt->execute()) {
    $error = $insertStmt->error;
    $insertStmt->close();
    echo json_encode(['success' => false, 'message' => $error ?: 'Failed to queue delete request']);
    exit;
}
$insertStmt->close();

if (function_exists('logAuditEvent')) {
    logAuditEvent($conn, [
        'actor_id' => $requestedBy,
        'actor_name' => $requestedByName,
        'actor_role' => $requestedByRole,
        'action' => 'registry_delete_requested',
        'entity_type' => 'tb_fileregistry',
        'entity_id' => (string)$registryId,
        'details' => [
            'regNo' => $regNo,
            'reason' => $reason
        ]
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Delete request queued for approval'
]);
$conn->close();
?>
