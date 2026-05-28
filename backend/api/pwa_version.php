<?php
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
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

echo "self.PWA_APP_VERSION = '" . addslashes($versionInfo['version']) . "';";
echo "self.PWA_BUILD_ID = '" . addslashes($versionInfo['build']) . "';";
echo "self.PWA_RELEASE_CHANNEL = '" . addslashes($versionInfo['channel']) . "';";
echo "self.PWA_SCHEMA_VERSION = '" . addslashes($versionInfo['schema_version']) . "';";
echo "self.PWA_BUILD_VERSION = '" . addslashes($versionInfo['build_fingerprint']) . "';";
echo "self.PWA_CACHE_VERSION = '" . addslashes($versionInfo['cache_version']) . "';";
