<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
















function pagecache_requestable(?string $path = null): bool
{
    $m = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m !== 'GET' && $m !== 'HEAD') {
        return false;
    }
    if (!empty($_COOKIE['ptsid'])) {
        return false;
    }
    foreach ($_GET as $key => $value) {
        if (is_tracking_param((string)$key)) {
            continue;
        }
        if ($key === 'page' && is_string($value) && ctype_digit($value) && (int)$value > 0) {
            continue;
        }
        return false;
    }
    if (isset($_GET['page'])) {
        $segments = explode('/', trim($path ?? request_path(), '/'));
        if (count($segments) !== 1 || $segments[0] === '' || section_get($segments[0]) === null) {
            return false;
        }
        $page = (int)$_GET['page'];
        if ($page === 1) {
            return false;
        }
        static $pageCounts = [];
        if (!array_key_exists($segments[0], $pageCounts)) {
            $pageCounts[$segments[0]] = posts_count($segments[0]);
        }
        $lastPage = max(1, (int)ceil($pageCounts[$segments[0]] / POSTS_PER_PAGE));
        if ($page > $lastPage) {
            return false;
        }
    }
    return true;
}

function pagecache_rev(): string
{
    $rev = (string)preg_replace('~[^0-9]~', '', setting('site_rev'));
    return $rev !== '' ? $rev : '0';
}


function pagecache_fresh_key(): string
{
    return pagecache_rev() . '|' . request_host() . '|' . (is_https() ? 'https' : 'http') . '|' . APP_VERSION;
}


function pagecache_files(string $path): array
{
    $base = ROOT . '/storage/cache/pages/' . pagecache_rev() . '-' . sha1($path . '|page=' . (int)($_GET['page'] ?? 0) . "\n" . pagecache_fresh_key());
    return [$base . '.html', $base . '.meta'];
}






function pagecache_lookup(string $path, ?array $files = null): ?array
{
    if (!pagecache_requestable()) {
        return null;
    }
    [$file, $metaFile] = $files ?? pagecache_files($path);
    if (!is_file($file)) {
        return null;
    }





    $mt = @filemtime($file);
    if ($mt === false || $mt < time() - 6 * 86400) {
        return null;
    }
    $html = @file_get_contents($file);
    if ($html === false) {
        return null;
    }


    $meta = [];
    $raw = is_file($metaFile) ? @file_get_contents($metaFile) : false;
    if ($raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $meta = $decoded;
        }
    }
    return [
        'html' => $html,
        'etag' => (string)($meta['etag'] ?? ''),
        'lastmod' => (int)($meta['lastmod'] ?? 0),
    ];
}







function pagecache_store(string $path, string $html, string $etag, int $lastmod, ?array $files = null): void
{


    if (http_response_code() !== 200 || !pagecache_requestable()) {
        return;
    }
    [$file, $metaFile] = $files ?? pagecache_files($path);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }



    $tmp = $file . '.tmp-' . getmypid();
    if (@file_put_contents($tmp, $html, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return;
    }
    $tmpM = $metaFile . '.tmp-' . getmypid();
    if (@file_put_contents($tmpM, json_db(['etag' => $etag, 'lastmod' => $lastmod]), LOCK_EX) === false || !@rename($tmpM, $metaFile)) {
        @unlink($tmpM);
    }
    pagecache_gc($dir);
}








function pagecache_gc(string $dir): void
{
    $prefix = pagecache_rev() . '-';
    foreach (glob($dir . '/*.html') ?: [] as $f) {
        if (!str_starts_with(basename($f), $prefix)) {
            @unlink($f);
            @unlink(substr($f, 0, -5) . '.meta');
        }
    }
    if (mt_rand(1, 50) === 1) {
        $old = time() - 7 * 86400;
        foreach (glob($dir . '/*') ?: [] as $f) {
            $mt = @filemtime($f);
            if ($mt !== false && $mt < $old) {
                @unlink($f);
            }
        }
    }
}
