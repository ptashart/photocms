<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);




define('ROOT', dirname(__DIR__));

























































































































































































































define('APP_VERSION', '1.9.114');



if (extension_loaded('mbstring')) {
    mb_internal_encoding('UTF-8');
}

require_once __DIR__ . '/helpers.php';

app_register_error_handlers();


security_headers();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/image_metadata.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/sanitize.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/pagecache.php';
require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/blog.php';
require_once __DIR__ . '/render.php';



$tz = (string)cfg('timezone', 'Europe/Moscow');
if (!@date_default_timezone_set($tz)) {
    app_log_error('bootstrap: неизвестная timezone «' . $tz . '» в config.php — используется UTC');
    date_default_timezone_set('UTC');
}

if (cfg('debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
