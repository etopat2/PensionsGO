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

ensureStaffDocumentsTable($conn);
if (function_exists('maybeApplyDocumentRetentionRules')) {
    maybeApplyDocumentRetentionRules($conn);
} else {
    applyDocumentRetentionRules($conn);
}

$docSettings = getDocumentStorageSettings($conn);
if (empty($docSettings['enabled'])) {
    echo json_encode(['success' => true, 'documents' => [], 'message' => 'Document storage is disabled.']);
    exit;
}

$staffId = isset($_GET['staffId']) ? (int)$_GET['staffId'] : 0;
$regNo = trim($_GET['regNo'] ?? '');

if ($staffId <= 0 && $regNo === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if ($staffId > 0) {
    $stmt = $conn->prepare("
        SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, uploaded_at
        FROM tb_staff_documents
        WHERE staffdue_id = ?
        ORDER BY uploaded_at DESC
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to load documents']);
        exit;
    }
    $stmt->bind_param("i", $staffId);
} else {
    $stmt = $conn->prepare("
        SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, uploaded_at
        FROM tb_staff_documents
        WHERE regNo = ?
        ORDER BY uploaded_at DESC
    ");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Failed to load documents']);
        exit;
    }
    $stmt->bind_param("s", $regNo);
}

$stmt->execute();
$result = $stmt->get_result();
$documents = [];
while ($row = $result->fetch_assoc()) {
    $documents[] = $row;
}
$stmt->close();

echo json_encode(['success' => true, 'documents' => $documents]);
$conn->close();
