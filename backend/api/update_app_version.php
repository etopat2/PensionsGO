<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ob_start();

try {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../versioning.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['userId']) || !sessionRoleIn($conn, ['admin'])) {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Admin access required',
        ]);
        exit;
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $currentManifest = pensionAppLoadVersionManifest();
    $payload = [
        'name' => $currentManifest['name'] ?? 'UPS PensionsGo',
        'version' => trim((string)($input['version'] ?? $currentManifest['version'] ?? '')),
        'display_version' => trim((string)($input['display_version'] ?? $currentManifest['display_version'] ?? '')),
        'channel' => trim((string)($input['channel'] ?? $currentManifest['channel'] ?? '')),
        'build' => trim((string)($input['build'] ?? $currentManifest['build'] ?? '')),
        'release_date' => trim((string)($input['release_date'] ?? $currentManifest['release_date'] ?? '')),
        'schema_version' => trim((string)($input['schema_version'] ?? $currentManifest['schema_version'] ?? '')),
    ];

    if ($payload['version'] === '') {
        http_response_code(422);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Internal version is required',
        ]);
        exit;
    }

    if ($payload['display_version'] === '') {
        $payload['display_version'] = $payload['version'];
    }

    if ($payload['release_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload['release_date'])) {
        http_response_code(422);
        ob_clean();
        echo json_encode([
            'success' => false,
            'message' => 'Release date must use YYYY-MM-DD format',
        ]);
        exit;
    }

    $savedManifest = pensionAppSaveVersionManifest($payload);
    $versionInfo = pensionAppGetVersionInfo();
    $manifestPath = pensionAppVersionManifestPath();

    if (function_exists('logAuditEvent')) {
        logAuditEvent($conn, [
            'actor_id' => (string)($_SESSION['userId'] ?? 'system'),
            'actor_name' => (string)($_SESSION['userName'] ?? $_SESSION['userId'] ?? 'System'),
            'actor_role' => (string)($_SESSION['userRole'] ?? 'admin'),
            'action' => 'update_app_version',
            'entity_type' => 'app_version',
            'entity_id' => basename($manifestPath),
            'details' => [
                'from' => [
                    'version' => $currentManifest['version'] ?? '',
                    'display_version' => $currentManifest['display_version'] ?? '',
                    'channel' => $currentManifest['channel'] ?? '',
                    'build' => $currentManifest['build'] ?? '',
                    'release_date' => $currentManifest['release_date'] ?? '',
                    'schema_version' => $currentManifest['schema_version'] ?? '',
                ],
                'to' => [
                    'version' => $savedManifest['version'] ?? '',
                    'display_version' => $savedManifest['display_version'] ?? '',
                    'channel' => $savedManifest['channel'] ?? '',
                    'build' => $savedManifest['build'] ?? '',
                    'release_date' => $savedManifest['release_date'] ?? '',
                    'schema_version' => $savedManifest['schema_version'] ?? '',
                ],
            ],
        ]);
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Application version updated successfully.',
        'manifest' => $savedManifest,
        'version' => $versionInfo,
        'meta' => [
            'manifest_file' => basename($manifestPath),
            'manifest_exists' => is_file($manifestPath),
            'manifest_writable' => is_writable($manifestPath),
            'manifest_updated_at' => is_file($manifestPath) ? date('c', (int)filemtime($manifestPath)) : null,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    http_response_code(500);
    error_log('update_app_version error: ' . $error->getMessage());
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Unable to update application version',
    ]);
}
