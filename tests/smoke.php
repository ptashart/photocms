<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

$source = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/photocms-test-' . bin2hex(random_bytes(5));
$copy = static function (string $dir) use (&$copy, $source, $temp): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.git') {
            continue;
        }
        $src = $dir . '/' . $name;
        $dst = $temp . '/' . substr($src, strlen($source) + 1);
        if (is_dir($src)) {
            if (!is_dir($dst)) {
                mkdir($dst, 0700, true);
            }
            $copy($src);
        } else {
            $parent = dirname($dst);
            if (!is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
            copy($src, $dst);
        }
    }
};
$copy($source);
file_put_contents($temp . '/config.php', '<?php return ' . var_export([
    'db' => $temp . '/storage/test.sqlite',
    'trusted_proxies' => ['127.0.0.1', '10.0.0.1'],
    'cloudflare_proxies' => ['10.0.0.1'],
], true) . ';');
define('PTS_DB_CREATE', true);
require $temp . '/app/bootstrap.php';
$failure = null;
try {
install_schema();

$fail = static function (string $message): void {
    throw new RuntimeException($message);
};
$check = static function (bool $condition, string $message) use ($fail): void {
    if (!$condition) {
        $fail($message);
    }
};

$check(SCHEMA_V === 20, 'Unexpected schema version');
$check(db_column_exists('contact_messages', 'source_json'), 'Missing source_json');
$check(safe_color('#abc') === '#abc' && safe_color('#abcdef12') === '#abcdef12', 'Valid colors rejected');
$check(safe_color('#abcde') === '#cccccc' && safe_color('#abcdef1') === '#cccccc', 'Invalid colors accepted');
$check(ip_in_cidr('173.245.48.10', '173.245.48.0/20'), 'IPv4 CIDR failed');
$check(!ip_in_cidr('173.245.64.1', '173.245.48.0/20'), 'IPv4 CIDR boundary failed');
$check(ip_in_cidr('2400:cb00::1', '2400:cb00::/32'), 'IPv6 CIDR failed');
$check(is_tracking_param('utm_source') && is_tracking_param('yclid') && is_tracking_param('from'), 'Tracking parameter missing');

$sourceJson = contact_attribution(json_encode(['params' => ['yclid' => 'abc123', 'unsafe' => 'x'], 'landing' => '/?yclid=abc123']));
$sourceData = json_decode($sourceJson, true);
$check(($sourceData['params']['yclid'] ?? '') === 'abc123' && !isset($sourceData['params']['unsafe']), 'Attribution validation failed');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
try {
    foreach (['script' => 'src', 'link' => 'href', 'iframe' => 'src', 'video' => 'src', 'audio' => 'src', 'source' => 'src', 'form' => 'action'] as $tag => $attr) {
        foreach (['""', "''", ''] as $value) {
            $html = '<' . $tag . ' ' . $attr . '=' . $value . '>';
            $check(public_csp_origins($html, $tag, $attr) === [], 'Empty CSP attribute failed: ' . $tag . '/' . $attr . '=' . $value);
        }
    }
    $check(public_csp_origins('<script src="https://cdn.example/app.js"></script>', 'script', 'src') === ['https://cdn.example'], 'CSP origin regression');
} finally {
    restore_error_handler();
}
$check(contact_origin_matches('https://www.example.ru/path?q=1', 'https://example.ru'), 'Compatible contact origin rejected');
$check(contact_origin_matches('https://example.ru:443', 'https://example.ru'), 'Default HTTPS port mismatch');
$check(!contact_origin_matches('http://example.ru', 'https://example.ru'), 'Cross-scheme contact origin accepted');
$check(!contact_origin_matches('https://example.ru:444', 'https://example.ru'), 'Cross-port contact origin accepted');
$check(!contact_origin_matches('https://user@example.ru', 'https://example.ru'), 'Origin userinfo accepted');

$serverBefore = $_SERVER;
try {
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
    $check(client_ip() === '203.0.113.9', 'Generic proxy accepted CF header');
    $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
    $check(client_ip() === '198.51.100.7', 'Configured Cloudflare proxy rejected');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
    $check(client_ip() === '192.0.2.1', 'Untrusted proxy headers accepted');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST'] = 'example.test';
    $_SERVER['SERVER_PORT'] = 80;
    $_SERVER['HTTPS'] = 'off';
    $_GET = [];
    $_COOKIE = [];
    $httpCacheKey = pagecache_fresh_key();
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = 443;
    $check($httpCacheKey !== pagecache_fresh_key(), 'Cache key ignores scheme');
    $_GET = ['page' => '2'];
    $check(!pagecache_requestable('/blog'), 'Out-of-range blog page can be cached');
    $check(!pagecache_requestable('/'), 'Pagination is cached on a non-blog route');
    $_GET = ['page' => '1'];
    $check(!pagecache_requestable('/blog'), 'Canonical first blog page can be cached');
} finally {
    $_SERVER = $serverBefore;
    $_GET = [];
    $_COOKIE = [];
}

set_setting('photo_author', 'Sergei Kolesnikov');
set_setting('photo_copyright', '© Sergei Kolesnikov');
set_setting('photo_credit', 'ptash.art');
set_setting('photo_license', 'https://www.ptash.art/license');
$schema = image_metadata_schema();
$check(($schema['creator']['name'] ?? '') === 'Sergei Kolesnikov', 'Image creator schema missing');

$image = imagecreatetruecolor(16, 12);
imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 180));
$formats = ['jpg', 'png'];
if (function_exists('imagewebp')) {
    $formats[] = 'webp';
}
if (function_exists('imageavif')) {
    $formats[] = 'avif';
}
foreach ($formats as $format) {
    $path = $temp . '/storage/test.' . $format;
    $saved = match ($format) {
        'jpg' => imagejpeg($image, $path),
        'png' => imagepng($image, $path),
        'webp' => imagewebp($image, $path),
        'avif' => imageavif($image, $path),
    };
    $check((bool)$saved, 'Image creation failed: ' . $format);
    $check(image_metadata_embed($path, $format), 'Metadata write failed: ' . $format);
    $blob = file_get_contents($path);
    if ($format !== 'avif') {
        $check(str_contains($blob, 'Sergei Kolesnikov'), 'Author metadata missing: ' . $format);
    }
    $check(filesize($path) > 20, 'Image became empty: ' . $format);
    if ($format !== 'avif') {
        $check(getimagesize($path) !== false, 'Image invalid after metadata: ' . $format);
    }
}
imagedestroy($image);

$snapshot = $temp . '/storage/snapshot.sqlite';
$check(db_snapshot_to($snapshot), 'Database snapshot failed');
$copyDb = new PDO('sqlite:' . $snapshot);
$check($copyDb->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Snapshot integrity failed');
require ROOT . '/admin/server.php';
$check(server_bytes('256M') === 268435456, 'Server byte conversion failed');
$check(server_bytes('2G') === 2147483648, 'Server GiB conversion failed');
$check(server_format_bytes(268435456) === '256 МБ', 'Server byte formatting failed');
$state = server_collect();
$check(is_array($state['warnings']) && is_array($state['load']), 'Server state collection failed');
$check(component_normalize_version('7.1.2-36') === '7.1.2.36', 'Component version normalization failed');
$check(component_status('8.5.12', '8.5.13')['state'] === 'update', 'Component update not detected');
$check(component_status('8.5.13', '8.5.13')['state'] === 'current', 'Current component misdetected');
$check(component_status('8.5.13', null)['state'] === 'unknown', 'Unknown release state missing');
$check(component_imagick_library_version('ImageMagick 7.1.2-36 Q16-HDRI') === '7.1.2-36', 'ImageMagick version parsing failed');
$check(component_sqlite_latest('PRODUCT,3.54.1,') === '3.54.1', 'SQLite release parsing failed');
$check(component_kernel_latest('The latest stable version of the Linux kernel is: 7.3.5') === '7.3.5', 'Kernel release parsing failed');
$check(server_cms_release_version('v1.9.114') === '1.9.114', 'CMS release tag parsing failed');
$check(server_cms_release_version('1.9.114') === '1.9.114', 'Numeric CMS release rejected');
$check(server_cms_release_version('1.9.114.1') === '', 'Nonstandard CMS release accepted');
$check(server_cms_release_version('javascript:alert(1)') === '', 'Unsafe CMS release accepted');
} catch (Throwable $e) {
    $failure = $e;
} finally {
    rrmdir($temp);
}
if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
echo "PhotoCMS smoke tests passed\n";
