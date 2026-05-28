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

if (!currentUserHasPermission($conn, 'staff_due.bulk_upload')) {
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
    enforceUploadedFileSizeLimit($conn, $upload, 'Staff due import file');
    $originalName = (string)($upload['name'] ?? 'staff_due_import');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'xlsx'], true)) {
        echo json_encode(['success' => false, 'message' => 'Only .csv and .xlsx files are supported for import.']);
        exit;
    }

    $tmpName = (string)($upload['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        echo json_encode(['success' => false, 'message' => 'Unable to access the uploaded file.']);
        exit;
    }
    if ($extension === 'xlsx') {
        enforceZipArchiveSafety($conn, $tmpName, 'Staff due import workbook');
    }

    $result = processDataImport(
        $conn,
        'staff_due',
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
            'log_category' => 'staff_due_import',
            'event_code' => $mode === 'dry_run' ? 'staff_due_import_preview_completed' : 'staff_due_import_completed',
            'message' => $mode === 'dry_run' ? 'Staff due import dry check completed.' : 'Staff due import completed.',
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
    error_log('process_staff_due_import error: ' . $e->getMessage());
    if (function_exists('recordSystemLog')) {
        recordSystemLog($conn, [
            'log_level' => 'error',
            'log_category' => 'staff_due_import',
            'event_code' => 'staff_due_import_failed',
            'message' => 'Staff due import processing failed.',
            'context' => [
                'error' => $e->getMessage(),
                'mode' => $mode ?? null
            ]
        ]);
    }
    echo json_encode(['success' => false, 'message' => 'Staff due import processing failed.']);
}

$conn->close();
