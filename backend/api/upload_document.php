<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../runtime_admin_tools.php';

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
applyDocumentRetentionRules($conn);

$docSettings = getDocumentStorageSettings($conn);
if (empty($docSettings['enabled'])) {
    echo json_encode(['success' => false, 'message' => 'Document storage is disabled by settings.']);
    exit;
}

$staffId = isset($_POST['staffdue_id']) ? (int)$_POST['staffdue_id'] : 0;
$docType = trim($_POST['doc_type'] ?? '');
$normalizedDocType = normalizeStandardDocumentType($docType);

if ($staffId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid document request']);
    exit;
}

if (!empty($docSettings['classification_required']) && $docType === '') {
    echo json_encode(['success' => false, 'message' => 'Select a document type before upload.']);
    exit;
}
if ($docType !== '' && $normalizedDocType === null) {
    echo json_encode(['success' => false, 'message' => 'Select a valid document type from the approved list.']);
    exit;
}
if ($normalizedDocType !== null) {
    $docType = $normalizedDocType;
} elseif ($docType === '') {
    $docType = 'Other';
}

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload failed']);
    exit;
}

$file = $_FILES['document'];
enforceUploadedFileSizeLimit($conn, $file, 'Registry document');
$allowedExt = $docSettings['allowed_types'] ?? ['pdf', 'jpg', 'jpeg', 'png'];
$originalName = $file['name'] ?? 'document';
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, $allowedExt, true)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported file type']);
    exit;
}
if (!empty($docSettings['max_size_mb'])) {
    $maxDocBytes = max(1, (int)$docSettings['max_size_mb']) * 1024 * 1024;
    if (isset($file['size']) && (int)$file['size'] > $maxDocBytes) {
        echo json_encode(['success' => false, 'message' => 'File size exceeds document storage limit.']);
        exit;
    }
}

$regNo = null;
$staffExists = false;
$regStmt = $conn->prepare("SELECT regNo FROM tb_staffdue WHERE id = ? LIMIT 1");
if ($regStmt) {
    $regStmt->bind_param("i", $staffId);
    $regStmt->execute();
    $row = $regStmt->get_result()->fetch_assoc();
    $regNo = $row['regNo'] ?? null;
    $staffExists = !empty($row);
    $regStmt->close();
}
if (!empty($docSettings['link_registry_required'])) {
    if (!$staffExists) {
        echo json_encode(['success' => false, 'message' => 'Document uploads must be linked to a workflow or registry record.']);
        exit;
    }
}

$safeFolder = $regNo ? preg_replace('/[^a-zA-Z0-9_-]/', '_', $regNo) : 'staff_' . $staffId;
$targetDir = __DIR__ . '/../uploads/documents/' . $safeFolder;
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

$buildDocumentLabel = static function (?string $value, string $fallback = 'Document') : string {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value) !== '' ? trim((string)$value) : $fallback;
};

$buildSafeFilename = static function (string $label) : string {
    $label = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $label);
    $label = preg_replace('/\s+/', ' ', (string)$label);
    $label = trim((string)$label, " .-_\t\n\r\0\x0B");
    return $label !== '' ? $label : 'Document';
};

$namingScheme = strtolower(trim((string)($docSettings['naming_scheme'] ?? 'regno_doc_type_timestamp')));
$timestampLabel = (new DateTimeImmutable('now'))->format('Ymd_His');
$regLabel = $buildDocumentLabel($regNo, 'Staff ' . $staffId);
$docTypeLabel = $buildDocumentLabel($docType, 'Document');

switch ($namingScheme) {
    case 'regno_timestamp':
        $displayStem = $regLabel . ' - ' . $timestampLabel;
        break;
    case 'doc_type_timestamp':
        $displayStem = $docTypeLabel . ' - ' . $timestampLabel;
        break;
    case 'regno_doc_type_timestamp':
    default:
        $displayStem = $regLabel . ' - ' . $docTypeLabel . ' - ' . $timestampLabel;
        break;
}

$safeStem = $buildSafeFilename($displayStem);
$finalName = $safeStem . '.' . $ext;
$counter = 1;
while (is_file($targetDir . '/' . $finalName)) {
    $finalName = $safeStem . ' (' . $counter . ').' . $ext;
    $counter++;
}
$targetPath = $targetDir . '/' . $finalName;

$scanResult = runVirusScanOnFile($conn, $file['tmp_name'], [
    'storage_context' => 'registry_document',
    'file_name' => $originalName,
    'file_path' => null,
    'mime_type' => $file['type'] ?? null,
    'scanned_by' => $_SESSION['userId'] ?? null,
    'scanned_by_name' => $_SESSION['userName'] ?? null,
    'scanned_by_role' => $_SESSION['userRole'] ?? null
]);
if (($scanResult['enabled'] ?? true) && in_array(($scanResult['status'] ?? ''), ['infected', 'suspicious', 'error'], true)) {
    $reason = trim((string)($scanResult['findings'] ?? 'Document upload failed the configured virus scan.'));
    echo json_encode(['success' => false, 'message' => $reason !== '' ? $reason : 'Document upload failed the configured virus scan.']);
    exit;
}

$fileHash = hash_file('sha256', $file['tmp_name']);
if (!empty($docSettings['dedupe_enabled']) && !empty($fileHash)) {
    $dupStmt = $conn->prepare("
        SELECT document_id
        FROM tb_staff_documents
        WHERE file_hash = ?
          AND (staffdue_id = ? OR regNo = ?)
        LIMIT 1
    ");
    if ($dupStmt) {
        $dupStmt->bind_param("sis", $fileHash, $staffId, $regNo);
        $dupStmt->execute();
        $dupRow = $dupStmt->get_result()->fetch_assoc();
        $dupStmt->close();
        if ($dupRow) {
            echo json_encode(['success' => false, 'message' => 'Duplicate document detected for this file.']);
            exit;
        }
    }
}

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    echo json_encode(['success' => false, 'message' => 'Unable to save file']);
    exit;
}

$filePath = 'uploads/documents/' . $safeFolder . '/' . $finalName;
$mimeType = $file['type'] ?? null;
$fileSize = isset($file['size']) ? (int)$file['size'] : null;

$stmt = $conn->prepare("
    INSERT INTO tb_staff_documents
    (staffdue_id, regNo, doc_type, file_name, file_path, file_size, mime_type, uploaded_by, file_hash)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Failed to save document']);
    exit;
}

$uploadedBy = $_SESSION['userId'] ?? null;
$stmt->bind_param("issssisss", $staffId, $regNo, $docType, $finalName, $filePath, $fileSize, $mimeType, $uploadedBy, $fileHash);
$stmt->execute();
$documentId = $stmt->insert_id;
$stmt->close();

$conn->query("UPDATE tb_staffdue SET documents_uploaded = 1 WHERE id = " . (int)$staffId);

echo json_encode([
    'success' => true,
    'message' => 'Document uploaded',
    'document' => [
        'document_id' => $documentId,
        'doc_type' => $docType,
        'file_name' => $finalName,
        'file_path' => $filePath
    ]
]);

$conn->close();
