<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);



require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    redirect('/install.php');
}
migrate();






$base = trim(setting('base_url'));
if ($base !== '' && preg_match('~^https?://~i', $base)) {
    $bHost = strtolower((string)parse_url($base, PHP_URL_HOST));
    $bScheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
    $rHost = strtolower((string)preg_replace('/[^a-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $rHost = (string)preg_replace('/:\d+$/', '', $rHost);
    if ($bHost !== '' && (strcasecmp($bHost, $rHost) !== 0 || ($bScheme === 'https' && !is_https()))) {
        redirect(rtrim($base, '/') . (string)($_SERVER['REQUEST_URI'] ?? '/'), 301);
    }
}





$rawUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
if (str_starts_with($rawUri, '//')) {
    $qpos = strpos($rawUri, '?');
    $dec = rawurldecode($qpos === false ? $rawUri : substr($rawUri, 0, $qpos));
    $enc = implode('/', array_map('rawurlencode', explode('/', trim($dec, '/\\'))));
    redirect('/' . $enc . ($qpos === false ? '' : substr($rawUri, $qpos)), 301);
}







$path = request_path();
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
if ($qs !== '') {
    $allowedQuery = ['page', 'sent', 'err'];
    $kept = [];
    $removed = false;
    foreach (explode('&', $qs) as $seg) {
        $eq = strpos($seg, '=');
        $name = strtolower(rawurldecode($eq === false ? $seg : substr($seg, 0, $eq)));
        if (!in_array($name, $allowedQuery, true) && !is_tracking_param($name)) {
            $removed = true;
            continue;
        }
        $kept[] = $seg;
    }
    if ($removed) {
        redirect($path . ($kept ? '?' . implode('&', $kept) : ''), 301);
    }
}

if ($path === '/index.php') {
    redirect('/', 301);
}
if ($path !== '/' && substr($path, -1) === '/') {


    $clean = rtrim(ltrim($path, '/\\'), '/');
    $enc = implode('/', array_map('rawurlencode', explode('/', $clean)));
    redirect('/' . $enc . ($qs !== '' ? '?' . $qs : ''), 301);
}





switch ($path) {
    case '/sitemap.xml':
        $xml = xml_cache_fetch('sitemap.xml', 'sitemap_xml');
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', xml_content_lastmod()) . ' GMT');
        $etag = '"' . md5($xml) . '"';
        header('ETag: ' . $etag);
        if (etag_matches($etag)) {
            http_response_code(304);
            exit;
        }
        echo $xml;
        exit;
    case '/robots.txt':
        $txt = robots_txt();
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        $etag = '"' . md5($txt) . '"';
        header('ETag: ' . $etag);
        if (etag_matches($etag)) {
            http_response_code(304);
            exit;
        }
        echo $txt;
        exit;
    case '/feed.xml':
        $xml = xml_cache_fetch('feed.xml', 'rss_xml');
        header('Content-Type: application/rss+xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', xml_content_lastmod()) . ' GMT');
        $etag = '"' . md5($xml) . '"';
        header('ETag: ' . $etag);
        if (etag_matches($etag)) {
            http_response_code(304);
            exit;
        }
        echo $xml;
        exit;
    case '/favicon.ico':
        $fav = (int)setting('favicon_image_id') ? image_get((int)setting('favicon_image_id')) : null;
        $favOk = false;
        if ($fav) {
            $favPath = image_variant_path($fav, 48, 'png');
            $failMark = $favPath . '.fail';
            if (is_file($favPath)) {
                $favOk = true;
            } elseif (is_file($failMark) && time() - (int)@filemtime($failMark) < 3600) {
                $favOk = false;
            } else {



                if (!img_throttle_allow(client_ip())) {
                    http_response_code(404);
                    exit;
                }
                $lock = is_file($favPath) ? null : image_lock_wait((int)$fav['id'], 2);
                if ($lock === false || $lock === null) {
                    http_response_code(503);
                    header('Retry-After: 2');
                    exit;
                }
                if (!img_throttle_allow(client_ip())) {
                    image_unlock($lock);
                    http_response_code(404);
                    exit;
                }
                try {
                    $favOk = image_make_variant($fav, 48, 'png');
                } finally {
                    image_unlock($lock);
                }
                if (!$favOk) {



                    $failDir = dirname($failMark);
                    if (!is_dir($failDir)) {
                        @mkdir($failDir, 0755, true);
                    }
                    @touch($failMark);
                    if (!img_throttle_hit(client_ip())) {
                        http_response_code(503);
                        header('Retry-After: 60');
                        exit;
                    }
                }
            }
        }
        if ($fav && $favOk) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=86400');
            readfile(image_variant_path($fav, 48, 'png'));
        } else {
            http_response_code(204);
        }
        exit;
}






$pcacheFiles = pagecache_requestable() ? pagecache_files($path) : null;
$pcacheHit = pagecache_lookup($path, $pcacheFiles);




$html = $pcacheHit !== null ? $pcacheHit['html'] : null;
$seg = explode('/', trim($path, '/'));
$sec = $html === null && $seg[0] !== '' ? section_get($seg[0]) : null;
if ($sec !== null && count($seg) === 1) {
    $html = render_blog_index($sec);
} elseif ($sec !== null && count($seg) === 2 && preg_match('~^[a-z0-9][a-z0-9_\-]{0,99}$~', $seg[1])) {
    $post = post_by_slug($seg[1]);
    if (!$post || (string)($post['section'] ?? 'blog') !== $sec['slug'] || (!(int)$post['is_published'] && !is_admin())) {
        serve_not_found($path);
    }
    $html = render_blog_post($post);
}

if ($html === null) {
    if ($path === '/') {
        $page = home_page();
    } else {
        $slug = substr($path, 1);
        $page = strpos($slug, '/') === false ? page_by_slug($slug) : null;
        if ($page && (int)$page['is_home']) {
            redirect('/', 301);
        }
    }

    if (!$page || (!(int)$page['is_published'] && !is_admin())) {
        serve_not_found($path);
    }

    $html = render_page($page);
}

$responseHtml = $html;
if (!is_admin()) {


    $responseHtml = public_csp_send($html);










    $lastmod = $pcacheHit !== null && $pcacheHit['lastmod'] > 0
        ? $pcacheHit['lastmod']
        : content_lastmod((int)setting('site_rev'));
    $etag = '"' . md5($html) . '"';
    if ($lastmod > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastmod) . ' GMT');
    }
    header('ETag: ' . $etag);
    header('Cache-Control: no-cache');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
        if (etag_matches($etag)) {
            http_response_code(304);
            exit;
        }
    } elseif ($lastmod > 0) {
        $ims = strtotime((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
        if ($ims !== false && $ims >= $lastmod) {
            http_response_code(304);
            exit;
        }
    }
} else {
    header('Cache-Control: no-store');
}
header('Content-Type: text/html; charset=utf-8');



if ($pcacheHit === null) {
    pagecache_store($path, $html, $etag ?? '', $lastmod ?? 0, $pcacheFiles);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}
echo $responseHtml;
