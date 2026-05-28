<?php
ob_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/data_management_common.php';

try {
    $type = strtolower(trim((string)($_GET['type'] ?? '')));
    $file = basename((string)($_GET['file'] ?? ''));
    $downloadRequested = in_array(strtolower(trim((string)($_GET['download'] ?? ''))), ['1', 'true', 'yes'], true);
    if ($file === '') {
        throw new RuntimeException('File is required.');
    }

    if ($type === 'backup') {
        requireAdminDataManagementAccess($conn);
        $path = getBackupStoragePath() . DIRECTORY_SEPARATOR . $file;
    } elseif ($type === 'export') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['userId'], $_SESSION['userRole'])) {
            throw new RuntimeException('Authentication required');
        }
        ensureDataManagementInfrastructure($conn);
        $sessionUserId = (string)($_SESSION['userId'] ?? '');
        $canAccessExport = sessionCanAccessDataManagement($conn);
        $ownerId = '';
        $ownerStmt = $conn->prepare('SELECT created_by FROM tb_data_export_runs WHERE file_name = ? ORDER BY export_id DESC LIMIT 1');
        if ($ownerStmt) {
            $ownerStmt->bind_param('s', $file);
            $ownerStmt->execute();
            $ownerStmt->bind_result($ownerId);
            $ownerStmt->fetch();
            $ownerStmt->close();
        }
        if (!$canAccessExport && $sessionUserId !== '' && $ownerId !== '' && hash_equals($ownerId, $sessionUserId)) {
            $canAccessExport = true;
        }
        if (!$canAccessExport) {
            throw new RuntimeException('Access denied');
        }
        $path = getDataExportStoragePath() . DIRECTORY_SEPARATOR . $file;
    } else {
        throw new RuntimeException('Invalid artifact type.');
    }

    if (!is_file($path)) {
        throw new RuntimeException('Requested file was not found.');
    }

    $mime = 'application/octet-stream';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'zip') {
        $mime = 'application/zip';
    } elseif ($ext === 'csv') {
        $mime = 'text/csv';
    } elseif ($ext === 'json') {
        $mime = 'application/json';
    } elseif ($ext === 'pdf') {
        $mime = 'application/pdf';
    } elseif ($ext === 'xlsx') {
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    $disposition = ($type === 'export' && $ext === 'pdf' && !$downloadRequested) ? 'inline' : 'attachment';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . $disposition . '; filename="' . basename($path) . '"');
    readfile($path);
    exit;
} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
