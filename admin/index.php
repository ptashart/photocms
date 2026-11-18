<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib.php';
require __DIR__ . '/pages.php';
require __DIR__ . '/media.php';
require __DIR__ . '/videos.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/blog.php';
require __DIR__ . '/misc.php';

if (!is_installed()) {
    redirect('/install.php');
}
migrate();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');


$previewRequest = in_array($_GET['p'] ?? '', ['page_preview', 'post_preview'], true);
if (!$previewRequest) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . admin_csp_nonce() . "'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}
session_boot();

$p = $_GET['p'] ?? 'dashboard';
$p = is_string($p) ? $p : 'dashboard';

if ($p === 'login') {
    admin_login();
    exit;
}



if ($p === 'ping') {
    $ok = admin_session_ok(false, false);
    http_response_code($ok ? 200 : 401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok]);
    exit;
}

if (!admin_session_ok()) {

    $back = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        $ab = admin_base();
        if (strncmp($uri, $ab, strlen($ab)) === 0 && strncmp($uri, $ab . '/', strlen($ab) + 1) !== 0) {
            $back = $uri;
        }
    }
    redirect(admin_base() . '?p=login' . ($back !== '' ? '&return=' . rawurlencode($back) : ''));
}
if ($p === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        session_clear();
    }
    redirect(admin_base() . '?p=login');
}

switch ($p) {
    case 'dashboard': admin_dashboard(); break;
    case 'pages': admin_pages(); break;
    case 'page_new': admin_page_new(); break;
    case 'page_edit': admin_page_edit(); break;
    case 'page_preview': admin_page_preview(); break;
    case 'posts': admin_posts(); break;
    case 'post_new': admin_post_new(); break;
    case 'post_edit': admin_post_edit(); break;
    case 'post_preview': admin_post_preview(); break;
    case 'sections': admin_sections(); break;
    case 'section_edit': admin_section_edit(); break;
    case 'media': admin_media(); break;
    case 'videos': admin_videos(); break;
    case 'messages': admin_messages(); break;
    case 'upload': admin_upload(); break;
    case 'media_json': admin_media_json(); break;
    case 'image_alt': admin_image_alt(); break;
    case 'image_focus': admin_image_focus(); break;
    case 'image_delete': admin_image_delete(); break;
    case 'warm_thumbs': admin_thumbs_warm(); break;
    case 'menu': admin_menu(); break;
    case 'settings': admin_settings(); break;
    case 'redirects': admin_redirects(); break;
    case 'account': admin_account(); break;
    default:
        http_response_code(404);
        admin_header('Не найдено');
        echo '<p>Такого раздела нет.</p>';
        admin_footer();
}
