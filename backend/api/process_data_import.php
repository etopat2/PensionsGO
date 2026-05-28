<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/import_common.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit;
}

try {
    if (!isset($_FILES['import_file']) || (int)($_FILES['import_file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Select an import file to continue.']);
        exit;
    }

    $datasetKey = strtolower(trim((string)($_POST['dataset'] ?? '')));
    $mode = strtolower(trim((string)($_POST['mode'] ?? 'dry_run')));
    if (!in_array($mode, ['dry_run', 'import'], true)) {
        $mode = 'dry_run';
    }
    if ($mode === 'import') {
        requireRecentAdminSensitiveVerification($conn, 'Re-enter your admin password before applying an import.');
    }

    $upload = $_FILES['import_file'];
    enforceUploadedFileSizeLimit($conn, $upload, 'Import file');
    $originalName = (string)($upload['name'] ?? 'import_file');
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
        enforceZipArchiveSafety($conn, $tmpName, 'Import workbook');
    }

    $result = processDataImport(
        $conn,
        $datasetKey,
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
            'log_category' => 'data_import',
            'event_code' => $mode === 'dry_run' ? 'import_preview_completed' : 'import_completed',
            'message' => $mode === 'dry_run' ? 'Data import dry run completed.' : 'Data import completed.',
            'context' => [
                'dataset' => $datasetKey,
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
                ? 'Dry run completed. Review the report before applying the import.'
                : 'Import completed. Review the report for inserted, merged, and flagged rows.')
    ]);
} catch (Throwable $e) {
    error_log('process_data_import error: ' . $e->getMessage());
    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'error',
            'log_category' => 'data_import',
            'event_code' => 'import_failed',
            'message' => 'Import processing failed.',
            'context' => [
                'error' => $e->getMessage(),
                'dataset' => $datasetKey ?? null,
                'mode' => $mode ?? null
            ]
        ]);
    }
    echo json_encode(['success' => false, 'message' => 'Import processing failed.']);
}

$conn->close();
