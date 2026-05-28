<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../versioning.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required',
        ]);
        exit;
    }

    $manifestPath = pensionAppVersionManifestPath();
    $manifestExists = is_file($manifestPath);

    echo json_encode([
        'success' => true,
        'manifest' => pensionAppLoadVersionManifest(),
        'version' => pensionAppGetVersionInfo(),
        'meta' => [
            'manifest_file' => basename($manifestPath),
            'manifest_exists' => $manifestExists,
            'manifest_writable' => $manifestExists ? is_writable($manifestPath) : is_writable(dirname($manifestPath)),
            'manifest_updated_at' => $manifestExists ? date('c', (int)filemtime($manifestPath)) : null,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('get_version_manifest error: ' . $error->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load version manifest',
    ]);
}
