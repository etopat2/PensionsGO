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
if (!roleHasAdminAccess($conn, $role) && !(function_exists('isOcPenEquivalentRole') && isOcPenEquivalentRole($role))) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

if (function_exists('ensureRegistryDeleteQueueTable')) {
    ensureRegistryDeleteQueueTable($conn);
}
if (function_exists('ensureRegistryRecycleBinTable')) {
    ensureRegistryRecycleBinTable($conn);
}

$includeHistoryParam = strtolower(trim((string)($_GET['include_history'] ?? '0')));
$includeHistory = in_array($includeHistoryParam, ['1', 'true', 'yes', 'on'], true);

$pendingSql = "
    SELECT
        r.request_id,
        r.registry_id,
        r.regNo,
        COALESCE(
            NULLIF(TRIM(r.staff_name), ''),
            NULLIF(TRIM(CONCAT(fr.sName, ' ', fr.fName)), ''),
            NULLIF(TRIM(CONCAT(sd.sName, ' ', sd.fName)), ''),
            NULLIF(TRIM(rb.staff_name), ''),
            ''
        ) AS staff_name,
        COALESCE(NULLIF(TRIM(r.staff_title), ''), NULLIF(TRIM(fr.title), ''), NULLIF(TRIM(rb.staff_title), ''), '') AS staff_title,
        r.requested_by,
        r.requested_by_name,
        r.requested_by_role,
        r.reason,
        r.status,
        r.processed_by,
        r.processed_by_name,
        r.processed_by_role,
        r.processed_note,
        r.created_at,
        r.processed_at
    FROM tb_file_registry_delete_requests r
    LEFT JOIN tb_file_registry_recycle_bin rb ON rb.delete_request_id = r.request_id
    LEFT JOIN tb_fileregistry fr ON fr.id = r.registry_id
    LEFT JOIN tb_staffdue sd ON sd.regNo = r.regNo
    WHERE r.status = 'pending'
    ORDER BY
        r.created_at DESC
    LIMIT 250
";

$result = $conn->query($pendingSql);
if (!$result) {
    echo json_encode(['success' => false, 'message' => 'Failed to load delete requests']);
    exit;
}

$pendingRequests = [];
while ($row = $result->fetch_assoc()) {
    $pendingRequests[] = [
        'request_id' => (int)$row['request_id'],
        'registry_id' => (int)$row['registry_id'],
        'regNo' => $row['regNo'],
        'staff_name' => $row['staff_name'] ?? '',
        'staff_title' => $row['staff_title'] ?? '',
        'requested_by' => $row['requested_by'],
        'requested_by_name' => $row['requested_by_name'],
        'requested_by_role' => $row['requested_by_role'],
        'reason' => $row['reason'],
        'status' => $row['status'],
        'processed_by' => $row['processed_by'],
        'processed_by_name' => $row['processed_by_name'],
        'processed_by_role' => $row['processed_by_role'],
        'processed_note' => $row['processed_note'],
        'created_at' => $row['created_at'],
        'processed_at' => $row['processed_at'],
        'source' => 'request_queue'
    ];
}

$historyRequests = [];
$recycleBin = [];

if ($includeHistory) {
    $recycleSql = "
        SELECT
            recycle_id,
            registry_id,
            regNo,
            staff_name,
            staff_title,
            delete_request_id,
            delete_reason,
            deleted_by,
            deleted_by_name,
            deleted_by_role,
            deleted_at,
            restored,
            restored_by,
            restored_by_name,
            restored_by_role,
            restored_at
        FROM tb_file_registry_recycle_bin
        ORDER BY deleted_at DESC, recycle_id DESC
        LIMIT 250
    ";
    $recycleResult = $conn->query($recycleSql);
    if ($recycleResult) {
        while ($row = $recycleResult->fetch_assoc()) {
            $recycleBin[] = [
                'recycle_id' => (int)$row['recycle_id'],
                'registry_id' => (int)($row['registry_id'] ?? 0),
                'regNo' => $row['regNo'],
                'staff_name' => $row['staff_name'] ?? '',
                'staff_title' => $row['staff_title'] ?? '',
                'delete_request_id' => (int)($row['delete_request_id'] ?? 0),
                'delete_reason' => $row['delete_reason'],
                'deleted_by' => $row['deleted_by'],
                'deleted_by_name' => $row['deleted_by_name'],
                'deleted_by_role' => $row['deleted_by_role'],
                'deleted_at' => $row['deleted_at'],
                'restored' => ((int)($row['restored'] ?? 0)) === 1,
                'restored_by' => $row['restored_by'],
                'restored_by_name' => $row['restored_by_name'],
                'restored_by_role' => $row['restored_by_role'],
                'restored_at' => $row['restored_at']
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'requests' => $pendingRequests,
    'history_requests' => $historyRequests,
    'recycle_bin' => $recycleBin
]);
$conn->close();
?>
