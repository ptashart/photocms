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

$check(str_contains(photo_metadata_xmp(photo_metadata_values()), '<xmpRights:WebStatement>https://www.ptash.art/license</xmpRights:WebStatement>'), 'License WebStatement missing');
$textLicense = array_merge(photo_metadata_values(), ['license' => 'Все права защищены']);
$textLicenseXmp = photo_metadata_xmp($textLicense);
$check(str_contains($textLicenseXmp, 'xmpRights:UsageTerms') && !str_contains($textLicenseXmp, 'xmpRights:WebStatement'), 'Plain-text license became a WebStatement URL');
$check(photo_license_url('Все права защищены') === '' && photo_license_url('https://example.test/license') === 'https://example.test/license', 'License URL validation failed');

$createImage = static function (int $width, int $height, string $slug) use ($temp): array {
    db_run('INSERT INTO images (slug, ext, orig_name, w, h, alt, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $slug, 'jpg', $slug . '.jpg', $width, $height, $slug, time(),
    ]);
    $id = (int)db()->lastInsertId();
    $dir = $temp . '/storage/originals';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $im = imagecreatetruecolor($width, $height);
    if (!imagejpeg($im, $dir . '/' . $id . '.jpg')) {
        throw new RuntimeException('Test image creation failed');
    }
    imagedestroy($im);
    return image_get($id);
};
$createPage = static function (string $slug, string $title, ?int $parentId = null, array $fields = []): int {
    $data = array_merge([
        'type' => 'page',
        'title' => $title,
        'slug' => $slug,
        'parent_id' => $parentId,
        'is_home' => 0,
        'is_published' => 1,
        'canonical' => '',
        'schema_type' => '',
        'created_at' => time(),
        'updated_at' => time(),
    ], $fields);
    db_run('INSERT INTO pages (type, title, slug, parent_id, is_home, is_published, canonical, schema_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values($data));
    return (int)db()->lastInsertId();
};

set_setting('base_url', 'https://example.test');
set_setting('site_name', 'Фотограф');
set_setting('business_name', 'Фото Организация');
$small = $createImage(200, 160, 'small');
$logo = $createImage(180, 120, 'logo');
$favicon = $createImage(160, 160, 'favicon');
$check(image_widths($small) === [200], 'Small image width descriptor is incorrect');
$check(image_width_allowed($small, 200) && !image_width_allowed($small, 201), 'Small original width is not routed safely');
$check(image_make_variant($small, 200, 'jpg'), 'Small original variant was not generated');
$generatedSize = getimagesize(image_variant_path($small, 200, 'jpg'));
$check($generatedSize !== false && $generatedSize[0] === 200 && $generatedSize[1] === 160, 'Generated small image dimensions changed');
$check(image_existing_variant($small, 'webp', 440) === image_variant_path($small, 200, 'jpg'), 'Small original variant is not available as fallback');
$smallOg = og_image_meta($small, 'Маленькое фото');
$check($smallOg['w'] === 200 && $smallOg['h'] === 160, 'Open Graph reports fictitious small image dimensions');
$check(str_ends_with((string)parse_url($smallOg['url'], PHP_URL_PATH), '/media/' . $small['id'] . '/200/small.jpg'), 'Open Graph small image URL is incorrect');

putenv('IMG_SKIP_UPLOAD_VARIANTS');
set_setting('avif', '0');
foreach ([[200, 160], [600, 400]] as [$uploadWidth, $uploadHeight]) {
    $uploadSource = $temp . '/storage/upload-' . $uploadWidth . '.jpg';
    $uploadResource = imagecreatetruecolor($uploadWidth, $uploadHeight);
    imagejpeg($uploadResource, $uploadSource);
    imagedestroy($uploadResource);
    $uploaded = image_add_from_file($uploadSource, 'upload-' . $uploadWidth . '.jpg');
    $check((int)$uploaded['w'] === $uploadWidth && (int)$uploaded['h'] === $uploadHeight, 'Upload lifecycle failed: ' . $uploadWidth);
    $uploadSize = getimagesize(image_variant_path($uploaded, 1240, 'jpg'));
    $check($uploadSize !== false && $uploadSize[0] === $uploadWidth && $uploadSize[1] === $uploadHeight, 'Upload preview dimensions failed: ' . $uploadWidth);
}

$siteCss = (string)file_get_contents(ROOT . '/assets/css/site.css');
$check(str_contains($siteCss, '--muted:rgba(37,37,37,.67)'), 'Muted text contrast token regressed');
$check(setting('gallery_columns_mobile') === '1', 'Mobile gallery default is too dense');
fx_stamp_text('V&M WEDDING');
$stampOnce = masonry_html([$small, $logo], 2, 1, 'Тест', false, true);
$check(substr_count($stampOnce, 'class="ph-date"') === 1, 'Custom stamp is repeated despite first-frame mode');
set_setting('fx_stamp_once', '0');
$stampEvery = masonry_html([$small, $logo], 2, 1, 'Тест', false, true);
$check(substr_count($stampEvery, 'class="ph-date"') === 2, 'Custom stamp repeat option was not preserved');
fx_stamp_text('');

set_setting('default_og_image_id', (string)$small['id']);
set_setting('logo_image_id', (string)$logo['id']);
set_setting('favicon_image_id', (string)$favicon['id']);
$logoSchema = jsonld_logo();
$check(($logoSchema['width'] ?? 0) === 180 && ($logoSchema['height'] ?? 0) === 120, 'Logo dimensions are incorrect');
$check(str_contains((string)$logoSchema['url'], '/media/' . $logo['id'] . '/'), 'Configured logo is ignored');
$wideLogo = $createImage(320, 40, 'wide-logo');
set_setting('logo_image_id', (string)$wideLogo['id']);
$logoSchema = jsonld_logo();
$check(str_contains((string)$logoSchema['url'], '/media/' . $favicon['id'] . '/'), 'Undersized logo did not fall back to favicon');
set_setting('logo_image_id', (string)$logo['id']);

$homeId = $createPage('home', 'Главная', null, ['is_home' => 1]);
$rootId = $createPage('works', 'Работы');
$parentId = $createPage('weddings', 'Свадьбы', $rootId);
$childId = $createPage('wedding-story', 'Свадебная история', $parentId, ['canonical' => '/wedding-story/']);
$crumbs = page_breadcrumbs(page_get($childId));
$check(array_column($crumbs, 'url') === ['/', '/works', '/weddings', '/wedding-story'], 'Nested breadcrumbs are incomplete');
$childMeta = page_meta(page_get($childId));
$check($childMeta['canonical'] === 'https://example.test/wedding-story', 'Self-canonical retains a redirecting slash');
$check(canonical_url('https://external.test/article/', '/wedding-story') === 'https://external.test/article/', 'External canonical was changed');
db_run('UPDATE pages SET parent_id = ? WHERE id = ?', [$childId, $parentId]);
$check(array_column(page_breadcrumbs(page_get($childId)), 'url') === ['/', '/weddings', '/wedding-story'], 'Breadcrumb cycle was not stopped');
db_run('UPDATE pages SET parent_id = ?, is_published = 0 WHERE id = ?', [$rootId, $parentId]);
$check(array_column(page_breadcrumbs(page_get($childId)), 'url') === ['/', '/wedding-story'], 'Unpublished breadcrumb ancestor is exposed');
db_run('UPDATE pages SET is_published = 1 WHERE id = ?', [$parentId]);

$albumId = $createPage('small-album', 'Альбом', null, ['type' => 'album']);
db_run('INSERT INTO gallery_items (owner_type, owner_id, image_id, position) VALUES (?, ?, ?, ?)', ['page', $albumId, $small['id'], 0]);
$albumMeta = page_meta(page_get($albumId));
$albumImage = $albumMeta['jsonld'][1]['image'][0] ?? [];
$check(($albumImage['width'] ?? 0) === 200 && ($albumImage['height'] ?? 0) === 160, 'ImageObject dimensions are fictitious');
$check(str_contains((string)($albumImage['contentUrl'] ?? ''), '/200/small.jpg'), 'ImageObject URL uses an upscale step');

set_setting('opening_hours', 'Пн-Пт 10:00-19:00, Сб 11:00-16:00');
set_setting('social_yandex_maps', 'https://yandex.ru/maps/org/test');
set_setting('social_whatsapp', 'https://wa.me/79000000000');
set_setting('person_name', '');
$homeMeta = page_meta(page_get($homeId));
$business = $homeMeta['jsonld'][1];
$check($business['@type'] === 'Organization' && !isset($business['address']), 'LocalBusiness is emitted without an address');
$check(!isset($business['openingHoursSpecification']), 'Opening hours are attached to a non-local organization');
set_setting('city', 'Москва');
set_setting('person_name', 'Иван Петров');
set_setting('person_job', 'Фотограф');
$business = page_meta(page_get($homeId))['jsonld'][1];
$check($business['@type'] === 'LocalBusiness' && ($business['address']['addressLocality'] ?? '') === 'Москва', 'Local business address was lost');
$hours = $business['openingHoursSpecification'] ?? [];
$check(count($hours) === 2 && count($hours[0]['dayOfWeek']) === 5, 'OpeningHoursSpecification days are incomplete');
$check($hours[0]['dayOfWeek'][0] === 'https://schema.org/Monday' && $hours[0]['dayOfWeek'][4] === 'https://schema.org/Friday', 'Weekday range is incorrect');
$check($hours[0]['opens'] === '10:00' && $hours[0]['closes'] === '19:00' && $hours[1]['opens'] === '11:00', 'Opening hours times are incorrect');
$check(isset($business['openingHours']) && opening_hours_specification_schema('По договорённости') === [], 'Opening hours fallback changed');
$check(($business['founder']['@id'] ?? '') === 'https://example.test/#person' && !isset($business['founder']['sameAs']), 'Organization links leaked into the person entity');

$profileId = $createPage('profile', 'Обо мне', null, ['schema_type' => 'ProfilePage']);
$profile = page_meta(page_get($profileId))['jsonld'][1];
$check(($profile['mainEntity']['@type'] ?? '') === 'Person' && ($profile['mainEntity']['name'] ?? '') === 'Иван Петров', 'ProfilePage person is missing');
set_setting('person_name', '');
$profile = page_meta(page_get($profileId))['jsonld'][1];
$check(($profile['mainEntity']['@type'] ?? '') === 'Organization' && ($profile['mainEntity']['name'] ?? '') === 'Фото Организация', 'ProfilePage organization fallback is missing');

$now = time();
db_run('INSERT INTO posts (title, slug, section, body, excerpt, is_published, canonical, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
    'Заметка', 'test-post', 'blog', '', 'Краткое описание', 1, '/blog/test-post/', $now, $now, $now,
]);
$post = post_get((int)db()->lastInsertId());
$postMeta = post_meta($post);
$check($postMeta['canonical'] === 'https://example.test/blog/test-post', 'Post self-canonical retains a redirecting slash');
$article = $postMeta['jsonld'][2];
$check(($article['author']['@type'] ?? '') === 'Organization' && ($article['author']['name'] ?? '') === 'Фото Организация', 'Site name is misclassified as a person');
set_setting('person_name', 'Иван Петров');
$article = post_meta($post)['jsonld'][2];
$check(($article['author']['@type'] ?? '') === 'Person' && ($article['author']['@id'] ?? '') === 'https://example.test/#person', 'Named post author is incorrect');

$videoPageId = $createPage('video-page', 'Видео');
db_run('INSERT INTO videos (file, title, description, poster_image_id, duration, created_at) VALUES (?, ?, ?, ?, ?, ?)', [
    'test-video.mp4', 'Тестовое видео', 'Описание', null, 5, '2026-11-23T12:00:00+03:00',
]);
$videoId = (int)db()->lastInsertId();
db_run('INSERT INTO sections (owner_type, page_id, type, position, data) VALUES (?, ?, ?, ?, ?)', [
    'page', $videoPageId, 'video', 0, json_encode(['video_id' => $videoId]),
]);
$videoPage = page_get($videoPageId);
$videoMeta = ['title' => 'Видео', 'og_image' => ['url' => 'https://example.test/generic-og.jpg']];
$check(jsonld_section_blocks($videoPage, $videoMeta) === [], 'VideoObject uses a generic OG image instead of a video poster');
db_run('UPDATE videos SET poster_image_id = ? WHERE id = ?', [$small['id'], $videoId]);
$videoBlocks = jsonld_section_blocks($videoPage, $videoMeta);
$check(count($videoBlocks) === 1 && ($videoBlocks[0]['duration'] ?? '') === 'PT5S', 'VideoObject with poster is missing');
$check(str_contains((string)($videoBlocks[0]['thumbnailUrl'] ?? ''), '/200/small.jpg'), 'VideoObject thumbnail is incorrect');

set_setting('robots_extra', 'Disallow: /private/');
$robots = robots_txt();
$check(substr_count($robots, 'Disallow: /private/') === 2, 'Shared robots rule is not applied to Google and Yandex');
set_setting('robots_extra', "User-agent: Googlebot\nDisallow: /google-private/");
$robots = robots_txt();
$check(substr_count($robots, 'User-agent: Googlebot') === 1 && substr_count($robots, 'Disallow: /google-private/') === 1, 'Explicit robots group was duplicated');
$check(strpos($robots, 'User-agent: Googlebot') > strpos($robots, 'User-agent: Yandex'), 'Explicit robots group changed position');
$check(substr_count($robots, 'Sitemap: https://example.test/sitemap.xml') === 1, 'Sitemap directive is missing');

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
$check(server_cms_release_version('v1.9.116') === '1.9.116', 'CMS release tag parsing failed');
$check(server_cms_release_version('1.9.116') === '1.9.116', 'Numeric CMS release rejected');
$check(server_cms_release_version('1.9.116.1') === '', 'Nonstandard CMS release accepted');
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
