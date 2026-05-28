<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/registry_document_common.php';

try {
    $context = registryDocumentRequireEditAccess($conn);
    $docSettings = $context['doc_settings'];

    $registryId = (int)($_POST['registry_id'] ?? 0);
    $regNo = trim((string)($_POST['regNo'] ?? ''));
    $docType = trim((string)($_POST['doc_type'] ?? ''));
    $normalizedDocType = normalizeStandardDocumentType($docType);

    if (!empty($docSettings['classification_required']) && $docType === '') {
        throw new RuntimeException('Select a document type before upload.');
    }
    if ($docType !== '' && $normalizedDocType === null) {
        throw new RuntimeException('Select a valid document type from the approved list.');
    }
    if ($normalizedDocType !== null) {
        $docType = $normalizedDocType;
    } elseif ($docType === '') {
        $docType = 'Other';
    }
    if (!isset($_FILES['document'])) {
        throw new RuntimeException('Choose a file to upload.');
    }

    $registry = registryDocumentResolveRegistryTarget($conn, $registryId, $regNo);
    if (!$registry) {
        throw new RuntimeException('Registry record not found.');
    }

    $upload = registryDocumentValidateUpload($conn, $_FILES['document'], $docSettings, 'Registry document');
    if (!empty($docSettings['dedupe_enabled'])) {
        registryDocumentEnsureNoDuplicate($conn, (string)$upload['file_hash'], $registry);
    }

    $storage = registryDocumentBuildPath($registry, $docType, (string)$upload['extension'], $docSettings);
    if (!move_uploaded_file((string)$upload['tmp_name'], $storage['absolute_path'])) {
        throw new RuntimeException('Unable to save the uploaded file.');
    }

    $stmt = $conn->prepare("
        INSERT INTO tb_staff_documents
        (staffdue_id, regNo, doc_type, file_name, file_path, file_size, mime_type, uploaded_by, file_hash)
        VALUES (NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        @unlink($storage['absolute_path']);
        throw new RuntimeException('Failed to save document metadata.');
    }

    $staffdueId = (int)($registry['staffdue_id'] ?? 0);
    $registryRegNo = trim((string)($registry['regNo'] ?? ''));
    $uploadedBy = $context['user_id'] !== '' ? $context['user_id'] : null;
    $fileSize = (int)($upload['file_size'] ?? 0);
    $mimeType = trim((string)($upload['mime_type'] ?? ''));
    $fileHash = trim((string)($upload['file_hash'] ?? ''));

    $stmt->bind_param(
        'issssisss',
        $staffdueId,
        $registryRegNo,
        $docType,
        $storage['file_name'],
        $storage['relative_path'],
        $fileSize,
        $mimeType,
        $uploadedBy,
        $fileHash
    );
    if (!$stmt->execute()) {
        $stmt->close();
        @unlink($storage['absolute_path']);
        throw new RuntimeException('Failed to save document metadata.');
    }
    $documentId = (int)$stmt->insert_id;
    $stmt->close();

    registryDocumentRefreshStaffDocumentFlag($conn, (int)($registry['staffdue_id'] ?? 0));
    $saved = registryDocumentFetchSaved($conn, $documentId);

    echo json_encode([
        'success' => true,
        'message' => 'Document uploaded successfully.',
        'document' => $saved
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'Unable to upload registry document.'
    ]);
}

$conn->close();
