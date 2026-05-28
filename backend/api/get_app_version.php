<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (is_file(__DIR__ . '/../config.local.php')) {
    require_once __DIR__ . '/../config.local.php';
}
require_once __DIR__ . '/../versioning.php';

$versionInfo = pensionAppGetVersionInfo();
header('ETag: "' . md5($versionInfo['cache_version']) . '"');
header('X-App-Version: ' . $versionInfo['version']);
header('X-App-Build: ' . $versionInfo['build']);

echo json_encode([
    'success' => true,
    'version' => $versionInfo,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
