<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/registry_document_common.php';

try {
    $context = registryDocumentRequireEditAccess($conn);
    $docSettings = $context['doc_settings'];

    $documentId = (int)($_POST['document_id'] ?? 0);
    if ($documentId <= 0) {
        throw new RuntimeException('Invalid document request.');
    }

    $existing = registryDocumentLoadById($conn, $documentId);
    if (!$existing) {
        throw new RuntimeException('Document not found.');
    }

    $registryId = (int)($_POST['registry_id'] ?? 0);
    $requestedRegNo = trim((string)($_POST['regNo'] ?? ''));
    $resolvedRegNo = trim((string)($existing['regNo'] ?? ''));
    if ($resolvedRegNo === '' && (int)($existing['staffdue_id'] ?? 0) > 0) {
        $staffStmt = $conn->prepare("SELECT regNo FROM tb_staffdue WHERE id = ? LIMIT 1");
        if ($staffStmt) {
            $staffId = (int)($existing['staffdue_id'] ?? 0);
            $staffStmt->bind_param('i', $staffId);
            $staffStmt->execute();
            $staffRow = $staffStmt->get_result()->fetch_assoc() ?: [];
            $staffStmt->close();
            $resolvedRegNo = trim((string)($staffRow['regNo'] ?? ''));
        }
    }

    $registry = registryDocumentResolveRegistryTarget($conn, $registryId, $requestedRegNo !== '' ? $requestedRegNo : $resolvedRegNo);
    if (!$registry) {
        throw new RuntimeException('The linked registry record could not be validated.');
    }

    $docType = trim((string)($_POST['doc_type'] ?? ($existing['doc_type'] ?? '')));
    $normalizedDocType = normalizeStandardDocumentType($docType);
    if (!empty($docSettings['classification_required']) && $docType === '') {
        throw new RuntimeException('Document type is required.');
    }
    if ($docType !== '' && $normalizedDocType === null) {
        throw new RuntimeException('Select a valid document type from the approved list.');
    }
    if ($normalizedDocType !== null) {
        $docType = $normalizedDocType;
    } elseif ($docType === '') {
        $docType = trim((string)($existing['doc_type'] ?? 'Document'));
    }

    $hasReplacementFile = isset($_FILES['document']) && (int)($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    if (!$hasReplacementFile && $docType === trim((string)($existing['doc_type'] ?? ''))) {
        throw new RuntimeException('No document changes were supplied.');
    }

    $newStorage = null;
    $upload = null;
    if ($hasReplacementFile) {
        $upload = registryDocumentValidateUpload($conn, $_FILES['document'], $docSettings, 'Registry document');
        if (!empty($docSettings['dedupe_enabled'])) {
            registryDocumentEnsureNoDuplicate($conn, (string)$upload['file_hash'], $registry, $documentId);
        }
        $newStorage = registryDocumentBuildPath($registry, $docType, (string)$upload['extension'], $docSettings);
        if (!move_uploaded_file((string)$upload['tmp_name'], $newStorage['absolute_path'])) {
            throw new RuntimeException('Unable to save the replacement file.');
        }
    }

    $fields = ['doc_type = ?'];
    $types = 's';
    $params = [$docType];

    if ($hasReplacementFile && $newStorage && $upload) {
        $fields[] = 'file_name = ?';
        $fields[] = 'file_path = ?';
        $fields[] = 'file_size = ?';
        $fields[] = 'mime_type = ?';
        $fields[] = 'file_hash = ?';
        $fields[] = 'uploaded_by = ?';
        $fields[] = 'uploaded_at = NOW()';

        $types .= 'ssisss';
        $params[] = $newStorage['file_name'];
        $params[] = $newStorage['relative_path'];
        $params[] = (int)($upload['file_size'] ?? 0);
        $params[] = trim((string)($upload['mime_type'] ?? ''));
        $params[] = trim((string)($upload['file_hash'] ?? ''));
        $params[] = $context['user_id'] !== '' ? $context['user_id'] : null;
    }

    $types .= 'i';
    $params[] = $documentId;

    $sql = "UPDATE tb_staff_documents SET " . implode(', ', $fields) . " WHERE document_id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        if ($newStorage) {
            @unlink($newStorage['absolute_path']);
        }
        throw new RuntimeException('Failed to update document metadata.');
    }

    $bindValues = [];
    foreach ($params as $index => $value) {
        $bindValues[$index] = $value;
    }
    $bindArgs = [$types];
    foreach ($bindValues as $index => &$value) {
        $bindArgs[] = &$value;
    }
    unset($value);
    call_user_func_array([$stmt, 'bind_param'], $bindArgs);

    if (!$stmt->execute()) {
        $stmt->close();
        if ($newStorage) {
            @unlink($newStorage['absolute_path']);
        }
        throw new RuntimeException('Failed to update the document.');
    }
    $stmt->close();

    if ($hasReplacementFile) {
        $oldRelativePath = trim((string)($existing['file_path'] ?? ''));
        if ($oldRelativePath !== '') {
            $oldAbsolutePath = realpath(__DIR__ . '/../' . ltrim($oldRelativePath, '/\\'));
            $allowedRoot = realpath(__DIR__ . '/../uploads/documents');
            if ($oldAbsolutePath !== false && $allowedRoot !== false && strpos($oldAbsolutePath, $allowedRoot) === 0 && is_file($oldAbsolutePath)) {
                @unlink($oldAbsolutePath);
            }
        }
    }

    $saved = registryDocumentFetchSaved($conn, $documentId);
    echo json_encode([
        'success' => true,
        'message' => $hasReplacementFile
            ? 'Document details updated and file replaced successfully.'
            : 'Document details updated successfully.',
        'document' => $saved
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'Unable to update registry document.'
    ]);
}

$conn->close();
