<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);




const SESSION_LIFETIME = 604800;
const SESSION_IDLE = 43200;
const LOGIN_MAX_PER_IP = 8;
const LOGIN_MAX_PER_LOGIN = 30;
const LOGIN_WINDOW = 900;
const PWCHANGE_MAX_ATTEMPTS = 5;






const UPLOAD_MAX_BYTES = 104857600;
const IMG_MAX_PIXELS_CAP = 90000000;
const IMG_LOCK_SLOTS = 4;
const IMG_LOCK_WAIT = 8;
const IMG_GEN_MAX_PER_IP = 300;
const IMG_GEN_COLD_MAX_PER_IP = 1200;
const IMG_FAIL_MAX_PER_IP = 12;
const IMG_QUALITY_MIN = 40;
const IMG_QUALITY_MAX = 95;


const POSTS_PER_PAGE = 24;
const RSS_ITEMS = 20;


const ERROR_LOG_MAX = 1048576;


const POST_TS_SQL = 'CASE WHEN published_at > 0 THEN published_at ELSE created_at END';


const RESERVED_SLUGS = ['admin', 'media', 'assets', 'app', 'storage', 'templates', 'tools', 'sitemap.xml', 'robots.txt',
    'favicon.ico', 'index', 'index.php', 'img.php', 'install', 'install.php', 'config', 'config.php',
    'blog', 'feed', 'feed.xml', 'import'];
