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
define('PTS_DB_CREATE', true);
require $temp . '/app/bootstrap.php';
install_schema();

$fail = static function (string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
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
    $check(str_contains($blob, 'Sergei Kolesnikov'), 'Author metadata missing: ' . $format);
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
rrmdir($temp);
echo "PhotoCMS smoke tests passed\n";
