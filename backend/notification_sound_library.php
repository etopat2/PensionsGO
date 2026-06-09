<?php

function notificationSoundProjectRoot(): string
{
    return dirname(__DIR__);
}

function notificationBuiltInSoundDefinitions(): array
{
    return [
        [
            'path' => 'audio/notification.mp3',
            'name' => 'Classic Alert (MP3)',
            'is_builtin' => true
        ],
        [
            'path' => 'audio/notification.wav',
            'name' => 'Classic Alert (WAV)',
            'is_builtin' => true
        ]
    ];
}

function notificationAllowedSoundExtensions(): array
{
    return ['mp3', 'wav', 'ogg', 'm4a'];
}

function notificationAllowedSoundMimeTypes(): array
{
    return [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/vnd.wave',
        'audio/ogg',
        'application/ogg',
        'audio/mp4',
        'audio/x-m4a',
        'audio/aac'
    ];
}

function notificationCustomSoundWebDirectory(): string
{
    return 'audio/custom-notifications';
}

function notificationCustomSoundStorageDirectory(): string
{
    return notificationSoundProjectRoot()
        . DIRECTORY_SEPARATOR . 'frontend'
        . DIRECTORY_SEPARATOR . 'audio'
        . DIRECTORY_SEPARATOR . 'custom-notifications';
}

function notificationNormalizeSoundPath(?string $path): string
{
    $normalized = trim((string)$path);
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/[?#].*$/', '', $normalized);
    $normalized = str_replace('\\', '/', $normalized);
    $normalized = preg_replace('#/+#', '/', $normalized);
    $normalized = preg_replace('#^\./+#', '', $normalized);
    $normalized = ltrim($normalized, '/');

    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return '';
    }

    if (stripos($normalized, 'frontend/') === 0) {
        $normalized = substr($normalized, strlen('frontend/'));
    }

    if (stripos($normalized, 'audio/') !== 0) {
        return '';
    }

    $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
    if (!in_array($extension, notificationAllowedSoundExtensions(), true)) {
        return '';
    }

    return $normalized;
}

function notificationSoundAbsolutePath(string $relativePath): string
{
    return notificationSoundProjectRoot()
        . DIRECTORY_SEPARATOR . 'frontend'
        . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function notificationGuessMimeTypeFromExtension(string $extension): string
{
    return match (strtolower($extension)) {
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4',
        default => 'audio/mpeg',
    };
}

function notificationBuildSoundRecord(string $path, string $name, bool $isBuiltin): ?array
{
    $normalizedPath = notificationNormalizeSoundPath($path);
    if ($normalizedPath === '') {
        return null;
    }

    $absolutePath = notificationSoundAbsolutePath($normalizedPath);
    if (!is_file($absolutePath)) {
        return null;
    }

    $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
    $sizeBytes = @filesize($absolutePath);
    $lastModified = @filemtime($absolutePath);

    return [
        'id' => sha1($normalizedPath),
        'name' => trim($name) !== '' ? trim($name) : basename($absolutePath),
        'path' => $normalizedPath,
        'url' => $normalizedPath,
        'file_name' => basename($absolutePath),
        'extension' => $extension,
        'mime_type' => notificationGuessMimeTypeFromExtension($extension),
        'size_bytes' => is_numeric($sizeBytes) ? (int)$sizeBytes : 0,
        'last_modified' => is_numeric($lastModified) ? date('Y-m-d H:i:s', (int)$lastModified) : '',
        'is_builtin' => $isBuiltin,
        'can_delete' => !$isBuiltin
    ];
}

function notificationEnsureCustomSoundDirectory(): string
{
    $directory = notificationCustomSoundStorageDirectory();
    if (function_exists('ensureUploadDirectoryGuard')) {
        ensureUploadDirectoryGuard($directory);
    } elseif (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    return $directory;
}

function notificationGetSoundLibrary(): array
{
    $library = [];
    $seenPaths = [];

    foreach (notificationBuiltInSoundDefinitions() as $definition) {
        $record = notificationBuildSoundRecord(
            (string)($definition['path'] ?? ''),
            (string)($definition['name'] ?? ''),
            true
        );
        if (!$record) {
            continue;
        }
        $library[] = $record;
        $seenPaths[$record['path']] = true;
    }

    $customDirectory = notificationCustomSoundStorageDirectory();
    if (is_dir($customDirectory)) {
        $files = scandir($customDirectory);
        if (is_array($files)) {
            natcasesort($files);
            foreach ($files as $fileName) {
                if ($fileName === '.' || $fileName === '..') {
                    continue;
                }

                $absolutePath = $customDirectory . DIRECTORY_SEPARATOR . $fileName;
                if (!is_file($absolutePath)) {
                    continue;
                }

                $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
                if (!in_array($extension, notificationAllowedSoundExtensions(), true)) {
                    continue;
                }

                $webPath = notificationCustomSoundWebDirectory() . '/' . $fileName;
                if (isset($seenPaths[$webPath])) {
                    continue;
                }

                $displayName = ucwords(str_replace(['-', '_'], ' ', pathinfo($fileName, PATHINFO_FILENAME)));
                $record = notificationBuildSoundRecord($webPath, $displayName, false);
                if (!$record) {
                    continue;
                }

                $library[] = $record;
                $seenPaths[$record['path']] = true;
            }
        }
    }

    return array_values($library);
}

function notificationFindSoundByPath(?string $path, ?array $library = null): ?array
{
    $normalizedPath = notificationNormalizeSoundPath($path);
    if ($normalizedPath === '') {
        return null;
    }

    $library = is_array($library) ? $library : notificationGetSoundLibrary();
    foreach ($library as $sound) {
        if (($sound['path'] ?? '') === $normalizedPath) {
            return $sound;
        }
    }

    return null;
}

function notificationResolveSelectedSoundPath(?string $selectedPath, ?array $library = null): string
{
    $library = is_array($library) ? $library : notificationGetSoundLibrary();
    $selected = notificationFindSoundByPath($selectedPath, $library);
    if ($selected) {
        return (string)$selected['path'];
    }

    $default = notificationFindSoundByPath('audio/notification.mp3', $library);
    if ($default) {
        return (string)$default['path'];
    }

    return (string)($library[0]['path'] ?? 'audio/notification.mp3');
}

function notificationGenerateUploadFilename(string $originalName): string
{
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, notificationAllowedSoundExtensions(), true)) {
        $extension = 'mp3';
    }

    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $baseName), '-'));
    if ($slug === '') {
        $slug = 'notification-sound';
    }

    return $slug . '-' . gmdate('YmdHis') . '-' . substr(sha1($originalName . microtime(true)), 0, 10) . '.' . $extension;
}

function notificationDeleteCustomSound(string $path): bool
{
    $sound = notificationFindSoundByPath($path);
    if (!$sound || !empty($sound['is_builtin'])) {
        return false;
    }

    $normalizedPath = notificationNormalizeSoundPath((string)$sound['path']);
    if (stripos($normalizedPath, notificationCustomSoundWebDirectory() . '/') !== 0) {
        return false;
    }

    $absolutePath = notificationSoundAbsolutePath($normalizedPath);
    if (!is_file($absolutePath)) {
        return false;
    }

    return unlink($absolutePath);
}
