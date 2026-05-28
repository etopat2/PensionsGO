<?php
declare(strict_types=1);

if (!array_key_exists('__pensionAppVersionManifestCache', $GLOBALS)) {
    $GLOBALS['__pensionAppVersionManifestCache'] = null;
}

function pensionAppVersionRoot(): string
{
    return dirname(__DIR__);
}

function pensionAppVersionManifestPath(): string
{
    return pensionAppVersionRoot() . DIRECTORY_SEPARATOR . 'app_version.json';
}

function pensionAppDefaultVersionManifest(): array
{
    return [
        'name' => 'UPS PensionsGo',
        'version' => '1.0.0-dev',
        'display_version' => '1.0.0-dev',
        'channel' => 'dev',
        'build' => gmdate('Ymd') . '.1',
        'release_date' => gmdate('Y-m-d'),
        'schema_version' => '5.2.1',
    ];
}

function pensionAppSanitizeVersionPart(?string $value, string $fallback = ''): string
{
    $sanitized = preg_replace('/[^0-9A-Za-z._-]/', '', trim((string)$value));
    return $sanitized !== '' ? $sanitized : $fallback;
}

function pensionAppSanitizeVersionText(?string $value, string $fallback = '', int $maxLength = 160): string
{
    $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string)$value));
    if ($sanitized === null) {
        $sanitized = '';
    }
    if ($sanitized !== '' && strlen($sanitized) > $maxLength) {
        $sanitized = substr($sanitized, 0, $maxLength);
    }
    return $sanitized !== '' ? $sanitized : $fallback;
}

function pensionAppSanitizeVersionManifest(array $input, ?array $base = null): array
{
    $defaults = pensionAppDefaultVersionManifest();
    $seed = is_array($base) ? array_merge($defaults, $base) : $defaults;
    $meta = array_merge($seed, $input);

    $meta['name'] = pensionAppSanitizeVersionText((string)($meta['name'] ?? ''), $seed['name'], 160);
    $meta['version'] = pensionAppSanitizeVersionPart((string)($meta['version'] ?? ''), $seed['version']);
    $meta['channel'] = pensionAppSanitizeVersionPart((string)($meta['channel'] ?? ''), $seed['channel']);
    $meta['build'] = pensionAppSanitizeVersionPart((string)($meta['build'] ?? ''), $seed['build']);
    $meta['release_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($meta['release_date'] ?? ''))
        ? (string)$meta['release_date']
        : $seed['release_date'];
    $meta['schema_version'] = pensionAppSanitizeVersionPart((string)($meta['schema_version'] ?? ''), $seed['schema_version']);

    $displayVersion = pensionAppSanitizeVersionText((string)($meta['display_version'] ?? ''), '', 80);
    $meta['display_version'] = $displayVersion !== '' ? $displayVersion : $meta['version'];

    return [
        'name' => $meta['name'],
        'version' => $meta['version'],
        'display_version' => $meta['display_version'],
        'channel' => $meta['channel'],
        'build' => $meta['build'],
        'release_date' => $meta['release_date'],
        'schema_version' => $meta['schema_version'],
    ];
}

function pensionAppResetVersionManifestCache(): void
{
    $GLOBALS['__pensionAppVersionManifestCache'] = null;
}

function pensionAppLoadVersionManifest(): array
{
    $cached = $GLOBALS['__pensionAppVersionManifestCache'] ?? null;
    if (is_array($cached)) {
        return $cached;
    }

    $manifestPath = pensionAppVersionManifestPath();
    $default = pensionAppDefaultVersionManifest();

    $loaded = [];
    if (is_file($manifestPath)) {
        $raw = file_get_contents($manifestPath);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $loaded = $decoded;
        }
    }

    $appVersionOverride = null;
    if (defined('PENSIONAPP_APP_VERSION')) {
        $appVersionOverride = (string)PENSIONAPP_APP_VERSION;
    } else {
        $envVersion = getenv('PENSIONAPP_APP_VERSION');
        if ($envVersion !== false && $envVersion !== '') {
            $appVersionOverride = (string)$envVersion;
        }
    }

    $meta = array_merge($default, $loaded);
    if ($appVersionOverride !== null && trim($appVersionOverride) !== '') {
        $meta['version'] = $appVersionOverride;
    }

    $meta = pensionAppSanitizeVersionManifest($meta, $default);

    $GLOBALS['__pensionAppVersionManifestCache'] = $meta;
    return $meta;
}

function pensionAppSaveVersionManifest(array $input): array
{
    $manifestPath = pensionAppVersionManifestPath();
    $existing = pensionAppLoadVersionManifest();
    $sanitized = pensionAppSanitizeVersionManifest($input, $existing);

    $directory = dirname($manifestPath);
    if (!is_dir($directory)) {
        throw new RuntimeException('Version manifest directory is unavailable.');
    }
    if (!is_file($manifestPath) && !is_writable($directory)) {
        throw new RuntimeException('Version manifest directory is not writable.');
    }
    if (is_file($manifestPath) && !is_writable($manifestPath)) {
        throw new RuntimeException('Version manifest file is not writable.');
    }

    $encoded = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode the version manifest.');
    }

    $written = file_put_contents($manifestPath, $encoded . PHP_EOL, LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Unable to write the version manifest.');
    }

    clearstatcache(true, $manifestPath);
    pensionAppResetVersionManifestCache();

    return pensionAppLoadVersionManifest();
}

function pensionAppComputeBuildFingerprint(?string $frontendRoot = null): string
{
    static $cache = [];

    $frontendPath = $frontendRoot ?: pensionAppVersionRoot() . DIRECTORY_SEPARATOR . 'frontend';
    $backendPath = pensionAppVersionRoot() . DIRECTORY_SEPARATOR . 'backend';
    $frontendResolved = realpath($frontendPath);
    $backendResolved = realpath($backendPath);
    $cacheKey = implode('|', [
        $frontendResolved ?: $frontendPath,
        $backendResolved ?: $backendPath,
        pensionAppVersionManifestPath(),
    ]);

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $allowedExtensions = [
        'html',
        'css',
        'js',
        'json',
        'webmanifest',
        'svg',
        'png',
        'ico',
        'jpg',
        'jpeg',
        'gif',
        'webp',
        'mp3',
        'wav',
        'php',
    ];

    $latestModified = 0;

    $scanRoots = array_filter([
        $frontendResolved,
        $backendResolved,
    ]);
    $ignoredDirectories = ['uploads', 'logs', 'cache', 'tmp', 'temp', '.git', 'node_modules'];
    $ignoredFiles = ['config.local.php', 'config.local.example.php', 'desktop.ini'];

    foreach ($scanRoots as $rootPath) {
        if (!is_dir($rootPath)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $current) use ($ignoredDirectories, $ignoredFiles): bool {
                    $name = strtolower($current->getFilename());
                    if ($current->isDir()) {
                        return !in_array($name, $ignoredDirectories, true);
                    }
                    return !in_array($name, $ignoredFiles, true);
                }
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $allowedExtensions, true)) {
                continue;
            }

            $mtime = $file->getMTime();
            if ($mtime > $latestModified) {
                $latestModified = $mtime;
            }
        }
    }

    $manifestPath = pensionAppVersionManifestPath();
    if (is_file($manifestPath)) {
        $mtime = (int)filemtime($manifestPath);
        if ($mtime > $latestModified) {
            $latestModified = $mtime;
        }
    }

    if (!$latestModified) {
        $latestModified = time();
    }

    $cache[$cacheKey] = gmdate('YmdHis', $latestModified);
    return $cache[$cacheKey];
}

function pensionAppGetVersionInfo(): array
{
    $meta = pensionAppLoadVersionManifest();
    $fingerprint = pensionAppComputeBuildFingerprint();
    $cacheVersion = pensionAppSanitizeVersionPart(
        sprintf('%s-%s-%s', $meta['version'], $meta['build'], $fingerprint),
        $meta['version']
    );

    return [
        'name' => $meta['name'],
        'version' => $meta['version'],
        'channel' => $meta['channel'],
        'build' => $meta['build'],
        'release_date' => $meta['release_date'],
        'schema_version' => $meta['schema_version'],
        'display_version' => $meta['display_version'],
        'build_fingerprint' => $fingerprint,
        'cache_version' => $cacheVersion,
    ];
}
