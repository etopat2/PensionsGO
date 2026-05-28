<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (
    !sessionRoleIn($conn, ['admin', 'oc_pen', 'dep_oc', 'deputy_oc', 'deputy_oc_pen', 'deputy_oc_pension', 'data_entry'])
    || !currentUserHasPermission($conn, 'registry.bulk_upload')
) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

try {
    if (!isset($_FILES['import_file']) || (int)($_FILES['import_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Select an import file to continue.']);
        exit;
    }

    $mode = strtolower(trim((string)($_POST['mode'] ?? 'dry_run')));
    if (!in_array($mode, ['dry_run', 'import'], true)) {
        $mode = 'dry_run';
    }

    $upload = $_FILES['import_file'];
    enforceUploadedFileSizeLimit($conn, $upload, 'Registry import file');
    $originalName = (string)($upload['name'] ?? 'registry_import');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx', 'xlxl'], true)) {
        echo json_encode(['success' => false, 'message' => 'Only .csv and .xlsx files are supported for import.']);
        exit;
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        echo json_encode(['success' => false, 'message' => 'Unable to access the uploaded file.']);
        exit;
    }
    if (in_array($extension, ['xlsx', 'xlxl'], true)) {
        enforceZipArchiveSafety($conn, $tmpName, 'Registry import workbook');
    }

    $result = processDataImport(
        $conn,
        'file_registry',
        $tmpName,
        $originalName,
        $extension,
        $mode,
        (string)($_SESSION['userId'] ?? ''),
        (string)($_SESSION['userName'] ?? ''),
        (string)($_SESSION['userRole'] ?? '')
    );

    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => $mode === 'dry_run' ? 'info' : 'notice',
            'log_category' => 'registry_import',
            'event_code' => $mode === 'dry_run' ? 'registry_import_preview_completed' : 'registry_import_completed',
            'message' => $mode === 'dry_run' ? 'Registry import dry check completed.' : 'Registry import completed.',
            'context' => [
                'mode' => $mode,
                'summary' => $result['summary'] ?? []
            ]
        ]);
    }

    echo json_encode([
        'success' => true,
        'status' => $result['status'],
        'summary' => $result['summary'],
        'report' => $result['report'],
        'review_export' => $result['review_export'] ?? null,
        'message' => $result['message']
            ?? ($mode === 'dry_run'
                ? 'Dry check completed. Review the report before applying the upload.'
                : 'Import completed. Review the report for inserted, merged, and flagged rows.')
    ]);
} catch (Throwable $e) {
    error_log('process_registry_file_import error: ' . $e->getMessage());
    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'error',
            'log_category' => 'registry_import',
            'event_code' => 'registry_import_failed',
            'message' => 'Registry import processing failed.',
            'context' => [
                'error' => $e->getMessage(),
                'mode' => $mode ?? null
            ]
        ]);
    }
    echo json_encode(['success' => false, 'message' => 'Registry import processing failed.']);
}

$conn->close();
