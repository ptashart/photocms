<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
/** Фронт-контроллер: все адреса, которым не соответствует файл на диске, попадают сюда.
    @author Sergei Kolesnikov
    @copyright 2025–2026 Sergei Kolesnikov. Использование и распространение — на условиях NOTICE.md. */
require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    redirect('/install.php');
}
migrate();

/* Склейка зеркал: когда в настройках задан канонический адрес сайта (base_url), запросы
   на другие хосты (www/алиасы/IP) и на http при https-каноне уходят 301 на canonical —
   без этого каждое зеркало отдавало 200 со «своим» canonical и плодило дубли в индексе.
   Важно: если SSL терминируется на прокси (nginx/Cloudflare), добавьте его IP
   в 'trusted_proxies' в config.php, иначе is_https() не увидит https и будет петля. */
$base = trim(setting('base_url'));
if ($base !== '' && preg_match('~^https?://~i', $base)) {
    $bHost = strtolower((string)parse_url($base, PHP_URL_HOST));
    $bScheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
    $rHost = strtolower((string)preg_replace('/[^a-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $rHost = (string)preg_replace('/:\d+$/', '', $rHost); // порт в сравнении не участвует
    if ($bHost !== '' && (strcasecmp($bHost, $rHost) !== 0 || ($bScheme === 'https' && !is_https()))) {
        redirect(rtrim($base, '/') . (string)($_SERVER['REQUEST_URI'] ?? '/'), 301);
    }
}

/* «//» в начале адреса: parse_url() трактует такой REQUEST_URI как scheme-relative
   (первый сегмент уходит в «host», path = null), и request_path() ниже вернул бы '/' —
   любой адрес вида «//blog» показывал главную с 200 OK: неограниченный источник дублей
   главной. 301 на нормализованный адрес (SEO-аудит 1.9.17, находка 1). */
$rawUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
if (str_starts_with($rawUri, '//')) {
    $qpos = strpos($rawUri, '?');
    $dec = rawurldecode($qpos === false ? $rawUri : substr($rawUri, 0, $qpos));
    $enc = implode('/', array_map('rawurlencode', explode('/', trim($dec, '/\\'))));
    redirect('/' . $enc . ($qpos === false ? '' : substr($rawUri, $qpos)), 301);
}

/* Рекламные метки (utm_*, fbclid, gclid и т.п.): адрес с ними — тот же документ, что без них,
   но для поисковика это отдельный URL-дубль (SEO-аудит 1.9.26). 301 на адрес без меток;
   функциональные параметры (?page=2 у блога и т.п.) не трогаем. С 1.9.40 фильтруем СЫРУЮ
   строку запроса посегментно: parse_str переписывал имена с точками/пробелами («a.b» → «a_b»)
   и перекодировал значения, а «ref» (легитимная пометка источников) резался заодно с метками
   (аудит 1.9.40, п.19). */
if ($qs0 = (string)($_SERVER['QUERY_STRING'] ?? '')) {
    $kept = [];
    $removed = false;
    foreach (explode('&', $qs0) as $seg) {
        $eq = strpos($seg, '=');
        $name = strtolower(rawurldecode($eq === false ? $seg : substr($seg, 0, $eq)));
        if (str_starts_with($name, 'utm_')
            || in_array($name, ['fbclid', 'gclid', 'yclid', 'mc_cid', 'mc_eid', 'igshid'], true)) {
            $removed = true;
            continue;
        }
        $kept[] = $seg;
    }
    if ($removed) {
        $rawPath = (string)parse_url($rawUri, PHP_URL_PATH);
        redirect(($rawPath === '' ? '/' : $rawPath) . ($kept !== [] ? '?' . implode('&', $kept) : ''), 301);
    }
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
        if (!in_array($name, $allowedQuery, true)) {
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
    /* $path уже декодирован (%5C → «\», %2F → «/»). Раньше он подставлялся в Location как есть, и запрос вида
       «/%5Cevil.example/» давал редирект на чужой домен. Теперь: без ведущих «/» и «\», заново закодирован по сегментам. */
    $clean = rtrim(ltrim($path, '/\\'), '/');
    $enc = implode('/', array_map('rawurlencode', explode('/', $clean)));
    redirect('/' . $enc . ($qs !== '' ? '?' . $qs : ''), 301);
}

/** Адрес не найден — serve_not_found() в app/render.php (там же render_404). */

/* Служебные файлы отдаём с HTTP-кэшем и ETag: боты дёргают их часто,
   а меняются они редко — нечего генерировать XML на каждый запрос. */
switch ($path) {
    case '/sitemap.xml':
        $xml = xml_cache_fetch('sitemap.xml', 'sitemap_xml'); // файловый кэш: не собираем XML на каждый хит ботов
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
    case '/llms.txt':
        $txt = xml_cache_fetch('llms.txt', 'llms_txt'); // тот же файловый кэш и ключ свежести, что у sitemap
        header('Content-Type: text/markdown; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', xml_content_lastmod()) . ' GMT');
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
                $favOk = false; // негативный кэш: недавно не получилось — не дёргаем GD на каждый запрос иконки
            } else {
                /* самый частозапрашиваемый URL сайта: вечный flock здесь выкашивает пул
                   PHP-воркеров при зависшем слоте — ждём недолго, как в img.php; слот занят —
                   204, браузер запросит иконку позже (аудит 1.9.12, находка 3) */
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
                    /* папка варианта появляется только после успешного декода — при битом
                       оригинале её нет, и touch молча не срабатывал; плюс провал идёт в общий
                       fail-троттлинг, которого тут раньше не было (аудит 1.9.12, находка 1) */
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

/* Файловый кэш готового HTML для анонимов (1.9.48, app/pagecache.php). Проверка стоит
   ПОСЛЕ всех 301-нормализаций выше (зеркала, «//», utm-метки, трейлинг-слэш): кэшировать
   можно только финальный адрес, иначе ответ-редирект «приклеился» бы к нему. is_admin()
   здесь сознательно не вызываем: владельца с кукой ptsid отсеивает pagecache_requestable(),
   а сам is_admin() при наличии куки поднимал бы сессию на каждый хит. */
$pcacheFiles = pagecache_requestable() ? pagecache_files($path) : null;
$pcacheHit = pagecache_lookup($path, $pcacheFiles);

// Заметки: /{раздел} — список, /{раздел}/{slug} — заметка (разделы настраиваются в админке)
/* при хите кэша роутинг и рендер пропускаем целиком: $html уже готов, а заголовки/304
   ниже работают с ним одинаково независимо от источника (кэш или рендер) */
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
    /* Nonce и список внешних источников вычисляются на каждый ответ; $html остаётся исходным
       для стабильного ETag и pagecache. Блокирующий CSP действует и на формах, и на видео. */
    $responseHtml = public_csp_send($html);
    /* Кэш-валидация по RFC 7232: при наличии If-None-Match оценивается ТОЛЬКО ETag,
       а If-Modified-Since применяется, лишь когда If-None-Match отсутствует. До 1.9.35
       IMS проверялся первым: клиент со старой датой получал 304, даже когда ETag уже
       изменился (ревизия 1.9.34, п.1). В Last-Modified подмешан site_rev — отметка
       site_touch(): правки настроек и меню меняют HTML всех страниц, но не трогают
       updated_at ни одной, и боты держали старый снимок (там же).
       Last-Modified/If-Modified-Since: Яндекс активнее Google использует дату изменения
       при расписании обхода; ETag/304 при этом остаётся. */
    /* При хите кэша content_lastmod() пуст (рендера не было) — берём сохранённый в .meta
       при записи lastmod; заодно это гарантирует, что hit и miss отдают одинаковые заголовки */
    $lastmod = $pcacheHit !== null && $pcacheHit['lastmod'] > 0
        ? $pcacheHit['lastmod']
        : content_lastmod((int)setting('site_rev'));
    $etag = '"' . md5($html) . '"'; // md5 от готового $html: дёшево и одинаково для кэша и рендера
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
/* Промах кэша — сохраняем ответ для следующих анонимных хитов. После вычисления
   ETag/Last-Modified: они уходят в .meta рядом с html. При хите ($pcacheHit !== null)
   не пишем ничего: ответ из кэша не должен трогать ни диск, ни сессию, ни базу на запись. */
if ($pcacheHit === null) {
    pagecache_store($path, $html, $etag ?? '', $lastmod ?? 0, $pcacheFiles);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit; // HEAD: заголовки уже выставлены, тело не отдаём
}
echo $responseHtml;
