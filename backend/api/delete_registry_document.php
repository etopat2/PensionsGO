<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/registry_document_common.php';

try {
    registryDocumentRequireEditAccess($conn);

    $payload = json_decode(file_get_contents('php://input'), true);
    $documentId = isset($payload['document_id']) ? (int)$payload['document_id'] : 0;
    if ($documentId <= 0) {
        throw new RuntimeException('Invalid document request.');
    }

    $document = registryDocumentLoadById($conn, $documentId);
    if (!$document) {
        throw new RuntimeException('Document not found.');
    }

    $resolvedRegNo = trim((string)($document['regNo'] ?? ''));
    if ($resolvedRegNo === '' && (int)($document['staffdue_id'] ?? 0) > 0) {
        $staffStmt = $conn->prepare("SELECT regNo FROM tb_staffdue WHERE id = ? LIMIT 1");
        if ($staffStmt) {
            $staffId = (int)($document['staffdue_id'] ?? 0);
            $staffStmt->bind_param('i', $staffId);
            $staffStmt->execute();
            $staffRow = $staffStmt->get_result()->fetch_assoc() ?: [];
            $staffStmt->close();
            $resolvedRegNo = trim((string)($staffRow['regNo'] ?? ''));
        }
    }

    $registry = registryDocumentResolveRegistryTarget($conn, 0, $resolvedRegNo);
    if (!$registry && $resolvedRegNo !== '') {
        throw new RuntimeException('The linked registry record could not be validated.');
    }

    $stmt = $conn->prepare("DELETE FROM tb_staff_documents WHERE document_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Failed to delete the document record.');
    }
    $stmt->bind_param('i', $documentId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Failed to delete the document record.');
    }
    $stmt->close();

    $relativePath = trim((string)($document['file_path'] ?? ''));
    if ($relativePath !== '') {
        $absolutePath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/\\'));
        $allowedRoot = realpath(__DIR__ . '/../uploads/documents');
        if ($absolutePath !== false && $allowedRoot !== false && strpos($absolutePath, $allowedRoot) === 0 && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    registryDocumentRefreshStaffDocumentFlag($conn, (int)($document['staffdue_id'] ?? 0));

    echo json_encode([
        'success' => true,
        'message' => 'Document deleted successfully.'
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'Unable to delete registry document.'
    ]);
}

$conn->close();
