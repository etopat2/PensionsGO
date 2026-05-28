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
$allowedRoles = ['admin', 'clerk', 'data_entry'];
if (!in_array($role, $allowedRoles, true)) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

ensureStaffDocumentsTable($conn);
ensureStaffDueExtendedColumns($conn);

$payload = json_decode(file_get_contents('php://input'), true);
$documentId = isset($payload['document_id']) ? (int)$payload['document_id'] : 0;

if ($documentId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document id']);
    exit;
}

$stmt = $conn->prepare("
    SELECT document_id, staffdue_id, file_path
    FROM tb_staff_documents
    WHERE document_id = ?
    LIMIT 1
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to load document']);
    exit;
}
$stmt->bind_param("i", $documentId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    echo json_encode(['success' => false, 'message' => 'Document not found']);
    exit;
}

$filePath = $doc['file_path'] ?? '';
if ($filePath !== '') {
    $fullPath = __DIR__ . '/../' . $filePath;
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }
}

$delStmt = $conn->prepare("DELETE FROM tb_staff_documents WHERE document_id = ?");
if ($delStmt) {
    $delStmt->bind_param("i", $documentId);
    $delStmt->execute();
    $delStmt->close();
}

$staffId = (int)($doc['staffdue_id'] ?? 0);
if ($staffId > 0) {
    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM tb_staff_documents WHERE staffdue_id = ?");
    if ($countStmt) {
        $countStmt->bind_param("i", $staffId);
        $countStmt->execute();
        $countRow = $countStmt->get_result()->fetch_assoc();
        $countStmt->close();
        $total = (int)($countRow['total'] ?? 0);
        if ($total === 0) {
            $conn->query("UPDATE tb_staffdue SET documents_uploaded = 0 WHERE id = " . $staffId);
        }
    }
}

echo json_encode(['success' => true, 'message' => 'Document deleted']);
$conn->close();
