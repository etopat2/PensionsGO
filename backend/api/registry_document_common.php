<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

function registryDocumentRequireEditAccess(mysqli $conn): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
        throw new RuntimeException('Authentication required');
    }

    if (!currentUserHasPermission($conn, 'registry.edit')) {
        throw new RuntimeException('Access denied');
    }

    ensureStaffDocumentsTable($conn);
    ensureStaffDueExtendedColumns($conn);
    if (function_exists('maybeApplyDocumentRetentionRules')) {
        maybeApplyDocumentRetentionRules($conn);
    } else {
        applyDocumentRetentionRules($conn);
    }

    $docSettings = getDocumentStorageSettings($conn);
    if (empty($docSettings['enabled'])) {
        throw new RuntimeException('Document storage is disabled by settings.');
    }

    return [
        'user_id' => (string)($_SESSION['userId'] ?? ''),
        'user_name' => (string)($_SESSION['userName'] ?? ''),
        'user_role' => normalizeRoleKey((string)($_SESSION['userRole'] ?? '')),
        'doc_settings' => $docSettings
    ];
}

function registryDocumentResolveRegistryTarget(mysqli $conn, int $registryId = 0, string $regNo = ''): ?array
{
    $regNo = trim($regNo);
    if ($registryId <= 0 && $regNo === '') {
        return null;
    }

    if ($registryId > 0) {
        $stmt = $conn->prepare("
            SELECT fr.id, fr.regNo, sd.id AS staffdue_id
            FROM tb_fileregistry fr
            LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
            WHERE fr.id = ?
              AND COALESCE(fr.is_deleted, 0) = 0
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $registryId);
    } else {
        $stmt = $conn->prepare("
            SELECT fr.id, fr.regNo, sd.id AS staffdue_id
            FROM tb_fileregistry fr
            LEFT JOIN tb_staffdue sd ON sd.regNo = fr.regNo
            WHERE fr.regNo = ?
              AND COALESCE(fr.is_deleted, 0) = 0
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $regNo);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ?: null;
}

function registryDocumentLoadById(mysqli $conn, int $documentId): ?array
{
    if ($documentId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, file_size, mime_type, uploaded_at, file_hash
        FROM tb_staff_documents
        WHERE document_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ?: null;
}

function registryDocumentFetchSaved(mysqli $conn, int $documentId): ?array
{
    $stmt = $conn->prepare("
        SELECT document_id, staffdue_id, regNo, doc_type, file_name, file_path, uploaded_at
        FROM tb_staff_documents
        WHERE document_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $documentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row ?: null;
}

function registryDocumentLabel(string $value, string $fallback = 'Document'): string
{
    $value = trim($value);
    if ($value === '') {
        return $fallback;
    }
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function registryDocumentSafeFilename(string $label): string
{
    $label = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $label);
    $label = preg_replace('/\s+/', ' ', (string)$label);
    $label = trim((string)$label, " .-_\t\n\r\0\x0B");
    return $label !== '' ? $label : 'Document';
}

function registryDocumentBuildPath(array $registry, string $docType, string $extension, array $docSettings): array
{
    $regNo = registryDocumentLabel((string)($registry['regNo'] ?? ''), 'Registry');
    $staffdueId = (int)($registry['staffdue_id'] ?? 0);
    $folderLabel = $regNo !== '' ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $regNo) : 'staff_' . $staffdueId;
    $targetDir = __DIR__ . '/../uploads/documents/' . $folderLabel;
    ensureUploadDirectoryGuard($targetDir);

    $timestampLabel = (new DateTimeImmutable('now'))->format('Ymd_His');
    $docTypeLabel = registryDocumentLabel($docType, 'Document');
    $namingScheme = strtolower(trim((string)($docSettings['naming_scheme'] ?? 'regno_doc_type_timestamp')));

    switch ($namingScheme) {
        case 'regno_timestamp':
            $displayStem = $regNo . ' - ' . $timestampLabel;
            break;
        case 'doc_type_timestamp':
            $displayStem = $docTypeLabel . ' - ' . $timestampLabel;
            break;
        case 'regno_doc_type_timestamp':
        default:
            $displayStem = $regNo . ' - ' . $docTypeLabel . ' - ' . $timestampLabel;
            break;
    }

    $safeStem = registryDocumentSafeFilename($displayStem);
    $finalName = $safeStem . '.' . $extension;
    $counter = 1;
    while (is_file($targetDir . '/' . $finalName)) {
        $finalName = $safeStem . ' (' . $counter . ').' . $extension;
        $counter++;
    }

    return [
        'directory' => $targetDir,
        'absolute_path' => $targetDir . '/' . $finalName,
        'relative_path' => 'uploads/documents/' . $folderLabel . '/' . $finalName,
        'file_name' => $finalName
    ];
}

function registryDocumentValidateUpload(mysqli $conn, array $file, array $docSettings, string $contextLabel = 'Registry document'): array
{
    $allowedExt = $docSettings['allowed_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];
    $upload = assertUploadedFileIsSafe($conn, $file, $allowedExt, ['image/', 'application/pdf', 'application/msword', 'application/vnd.'], $contextLabel);

    if (!empty($docSettings['max_size_mb'])) {
        $maxDocBytes = max(1, (int)$docSettings['max_size_mb']) * 1024 * 1024;
        if (isset($file['size']) && (int)$file['size'] > $maxDocBytes) {
            throw new RuntimeException('File size exceeds document storage limit.');
        }
    }

    $scanResult = runVirusScanOnFile($conn, (string)$upload['tmp_name'], [
        'storage_context' => 'registry_document',
        'file_name' => (string)$upload['original_name'],
        'file_path' => null,
        'mime_type' => (string)$upload['mime_type'],
        'scanned_by' => $_SESSION['userId'] ?? null,
        'scanned_by_name' => $_SESSION['userName'] ?? null,
        'scanned_by_role' => $_SESSION['userRole'] ?? null
    ]);
    if (($scanResult['enabled'] ?? true) && in_array(($scanResult['status'] ?? ''), ['infected', 'suspicious', 'error'], true)) {
        $reason = trim((string)($scanResult['findings'] ?? 'Document upload failed the configured virus scan.'));
        throw new RuntimeException($reason !== '' ? $reason : 'Document upload failed the configured virus scan.');
    }

    return [
        'original_name' => (string)$upload['original_name'],
        'extension' => (string)$upload['extension'],
        'tmp_name' => (string)$upload['tmp_name'],
        'mime_type' => (string)$upload['mime_type'],
        'file_size' => (int)$upload['file_size'],
        'file_hash' => (string)$upload['file_hash']
    ];
}

function registryDocumentEnsureNoDuplicate(mysqli $conn, string $fileHash, array $registry, int $ignoreDocumentId = 0): void
{
    $fileHash = trim($fileHash);
    if ($fileHash === '') {
        return;
    }

    $staffdueId = (int)($registry['staffdue_id'] ?? 0);
    $regNo = trim((string)($registry['regNo'] ?? ''));

    $sql = "
        SELECT document_id
        FROM tb_staff_documents
        WHERE file_hash = ?
          AND (staffdue_id = ? OR regNo = ?)
    ";
    if ($ignoreDocumentId > 0) {
        $sql .= " AND document_id <> ?";
    }
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return;
    }

    if ($ignoreDocumentId > 0) {
        $stmt->bind_param('sisi', $fileHash, $staffdueId, $regNo, $ignoreDocumentId);
    } else {
        $stmt->bind_param('sis', $fileHash, $staffdueId, $regNo);
    }
    $stmt->execute();
    $duplicate = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicate) {
        throw new RuntimeException('Duplicate document detected for this pension file.');
    }
}

function registryDocumentRefreshStaffDocumentFlag(mysqli $conn, int $staffdueId): void
{
    if ($staffdueId <= 0) {
        return;
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM tb_staff_documents WHERE staffdue_id = ?");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $staffdueId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['total' => 0];
    $stmt->close();

    $hasDocuments = (int)($row['total'] ?? 0) > 0 ? 1 : 0;
    $update = $conn->prepare("UPDATE tb_staffdue SET documents_uploaded = ? WHERE id = ?");
    if (!$update) {
        return;
    }
    $update->bind_param('ii', $hasDocuments, $staffdueId);
    $update->execute();
    $update->close();
}
