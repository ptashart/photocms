<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function component_normalize_version(string $version): string
{
    return trim((string)preg_replace('/[^0-9A-Za-z.]+/', '.', $version), '.');
}

function component_php_latest(array $releases, string $branch): ?string
{
    $versions = [];
    foreach ($releases as $version => $release) {
        if (is_array($release) && str_starts_with((string)$version, $branch . '.')) {
            $versions[] = (string)$version;
        }
    }
    usort($versions, static fn(string $a, string $b): int => version_compare(component_normalize_version($a), component_normalize_version($b)));
    return $versions === [] ? null : end($versions);
}

function component_imagick_library_version(string $version): string
{
    return preg_match('/ImageMagick\s+(\d+\.\d+\.\d+(?:-\d+)?)/', $version, $matches) === 1 ? $matches[1] : $version;
}

function component_sqlite_latest(string $html): ?string
{
    return preg_match('/PRODUCT,(\d+\.\d+\.\d+),/', $html, $matches) === 1 ? $matches[1] : null;
}

function component_kernel_latest(string $text): ?string
{
    return preg_match('/The latest stable version of the Linux kernel is:\s+(\d+\.\d+(?:\.\d+)?)/i', $text, $m) ? $m[1] : null;
}

function component_status(?string $current, ?string $latest): array
{
    if ($current === null || $current === '') {
        return ['state' => 'missing', 'label' => 'Не установлено'];
    }
    if ($latest === null || $latest === '') {
        return ['state' => 'unknown', 'label' => 'Нет данных о релизах'];
    }
    if (version_compare(component_normalize_version($current), component_normalize_version($latest), '<')) {
        return ['state' => 'update', 'label' => 'Доступно обновление'];
    }
    return ['state' => 'current', 'label' => 'Актуальная версия'];
}

function component_current_versions(string $appVersion): array
{
    $imagickVersion = extension_loaded('imagick') ? (string)phpversion('imagick') : null;
    $libraryVersion = null;
    if (extension_loaded('imagick')) {
        $libraryVersion = component_imagick_library_version((string)(Imagick::getVersion()['versionString'] ?? ''));
    }
    return [
        'application' => [
            'name' => 'PhotoCMS', 'current' => $appVersion,
            'source' => 'https://github.com/ptashart/photocms/releases', 'note' => 'Версия кода сайта, развёрнутого на сервере.',
        ],
        'php' => [
            'name' => 'PHP', 'current' => PHP_VERSION,
            'source' => 'https://www.php.net/releases/', 'note' => 'Проверяется последняя версия ветки ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.',
        ],
        'imagick' => [
            'name' => 'imagick', 'current' => $imagickVersion,
            'source' => 'https://pecl.php.net/package/imagick', 'note' => 'PHP-расширение для обработки фотографий.',
        ],
        'imagemagick' => [
            'name' => 'ImageMagick', 'current' => $libraryVersion,
            'source' => 'https://github.com/ImageMagick/ImageMagick/releases', 'note' => 'Библиотека обработки изображений и HEIC.',
        ],
        'sqlite' => [
            'name' => 'SQLite', 'current' => (string)db_val('SELECT sqlite_version()'),
            'source' => 'https://www.sqlite.org/download.html', 'note' => 'Версия библиотеки, с которой работает PHP.',
        ],
        'kernel' => [
            'name' => 'Ядро Linux', 'current' => PHP_OS_FAMILY === 'Linux' ? php_uname('r') : null,
            'source' => 'https://www.kernel.org/', 'note' => 'Обновления ОС устанавливает хостер или системный менеджер обновлений.',
        ],
    ];
}

function component_read_cache(): array
{
    $path = ROOT . '/storage/cache/component-versions.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) && isset($data['checked_at'], $data['latest']) && is_array($data['latest']) ? $data : [];
}

function component_cache_is_fresh(array $cache): bool
{
    if ($cache === []) {
        return false;
    }
    $ttl = empty($cache['errors']) ? 21600 : 900;
    return time() - (int)$cache['checked_at'] < $ttl;
}

function component_http_gets(array $urls): array
{
    if (!function_exists('curl_multi_init')) {
        return array_fill_keys(array_keys($urls), ['status' => 0, 'error' => 'cURL недоступен', 'body' => '']);
    }
    $multi = curl_multi_init();
    $handles = [];
    $bodies = [];
    foreach ($urls as $key => $url) {
        $bodies[$key] = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'PhotoCMS-Component-Check/' . APP_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, text/html;q=0.8'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bodies, $key): int {
                if (strlen($bodies[$key]) + strlen($chunk) > 1048576) {
                    return 0;
                }
                $bodies[$key] .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$key] = $handle;
    }
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running && $status === CURLM_OK) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running && $status === CURLM_OK);
    $results = [];
    foreach ($handles as $key => $handle) {
        $results[$key] = [
            'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'error' => curl_error($handle),
            'body' => $bodies[$key],
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $results;
}

function component_fetch_latest(): array
{
    $responses = component_http_gets([
        'php' => 'https://www.php.net/releases/index.php?json=1&max=100&version=' . PHP_MAJOR_VERSION,
        'imagick' => 'https://pecl.php.net/rest/r/imagick/latest.txt',
        'imagemagick' => 'https://api.github.com/repos/ImageMagick/ImageMagick/releases/latest',
        'sqlite' => 'https://www.sqlite.org/download.html',
        'kernel' => 'https://www.kernel.org/finger_banner',
    ]);
    $latest = array_fill_keys(array_keys($responses), null);
    $errors = [];
    if ($responses['php']['status'] === 200) {
        $releases = json_decode($responses['php']['body'], true);
        if (is_array($releases)) {
            $latest['php'] = component_php_latest($releases, PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
        }
    }
    if ($responses['imagick']['status'] === 200) {
        $version = trim($responses['imagick']['body']);
        if (preg_match('/^\d+\.\d+\.\d+(?:[A-Za-z0-9.-]*)?$/', $version) === 1) {
            $latest['imagick'] = $version;
        }
    }
    if ($responses['imagemagick']['status'] === 200) {
        $release = json_decode($responses['imagemagick']['body'], true);
        $tag = is_array($release) ? (string)($release['tag_name'] ?? '') : '';
        if (preg_match('/^\d+\.\d+\.\d+-\d+$/', $tag) === 1) {
            $latest['imagemagick'] = $tag;
        }
    }
    if ($responses['sqlite']['status'] === 200) {
        $latest['sqlite'] = component_sqlite_latest($responses['sqlite']['body']);
    }
    if ($responses['kernel']['status'] === 200) {
        $latest['kernel'] = component_kernel_latest($responses['kernel']['body']);
    }
    foreach ($responses as $key => $response) {
        if ($latest[$key] === null) {
            $errors[$key] = $response['status'] !== 200 ? 'HTTP ' . $response['status'] . ($response['error'] !== '' ? ': ' . $response['error'] : '') : 'Не удалось разобрать ответ официального источника.';
        }
    }
    return ['latest' => $latest, 'errors' => $errors];
}

function component_assemble(array $current, array $cache): array
{
    $components = [];
    foreach ($current as $key => $item) {
        $latest = $cache['latest'][$key] ?? null;
        if ($key === 'application') {
            $app = server_cms_update();
            $latest = $app['latest'] !== '' ? $app['latest'] : null;
        }
        $item['latest'] = $latest;
        $item['status'] = component_status($item['current'], $latest);
        $components[$key] = $item;
    }
    return [
        'components' => $components,
        'checked_at' => (int)($cache['checked_at'] ?? 0),
        'errors' => $cache['errors'] ?? [],
    ];
}

function component_versions(string $appVersion, bool $refresh = false): array
{
    $current = component_current_versions($appVersion);
    $cache = component_read_cache();
    if (!$refresh && component_cache_is_fresh($cache)) {
        return component_assemble($current, $cache);
    }
    $directory = ROOT . '/storage/cache';
    if (!is_dir($directory)) {
        mkdir($directory, 0750, true);
    }
    $lock = fopen($directory . '/component-versions.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return component_assemble($current, $cache);
    }
    try {
        $cache = component_read_cache();
        if ($refresh || !component_cache_is_fresh($cache)) {
            $result = component_fetch_latest();
            $cache = ['checked_at' => time()] + $result;
            $json = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = $directory . '/component-versions-' . bin2hex(random_bytes(6)) . '.tmp';
            if (file_put_contents($temporary, $json, LOCK_EX) !== false) {
                chmod($temporary, 0640);
                rename($temporary, $directory . '/component-versions.json');
            } else {
                @unlink($temporary);
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return component_assemble($current, $cache);
}
