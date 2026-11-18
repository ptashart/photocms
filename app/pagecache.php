<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

/* Файловый кэш готового HTML для анонимных посетителей (1.9.48).
   До него каждый хит рендерил страницу целиком (15–30 запросов к базе), хотя для
   анонима без параметров ответ почти всегда тот же. Кэшируем только GET/HEAD без
   query string и без куки сессии, и только ответы 200; 404, редиректы, страницы
   с ?page= и служебные файлы (sitemap.xml и т.п.) идут мимо.
   Хранилище: storage/cache/pages/<rev>-<sha1>.html и рядом <имя>.meta (JSON с etag
   и lastmod). rev = setting('site_rev'): site_touch() бампает её при правках настроек
   и меню — и весь кэш устаревает разом, самим ключом, без отдельной очистки. */

/**
 * Можно ли этот запрос обслужить из кэша / положить в кэш.
 * Куку проверяем напрямую, а не через is_admin(): is_admin() при наличии куки поднимает
 * сессию (session_boot), а нам на каждом хите нужен дешёвый отсев без старта сессии —
 * анониму сессия не нужна вовсе. Имя куки 'ptsid' задано в session_boot() (helpers.php).
 */
function pagecache_requestable(): bool
{
    $m = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m !== 'GET' && $m !== 'HEAD') {
        return false;
    }
    /* любой query string — мимо кэша: ?page=2 у блога — другой документ, а рекламные
       метки к этому моменту уже срезаны 301 в index.php */
    if ((string)($_SERVER['QUERY_STRING'] ?? '') !== '') {
        return false;
    }
    return empty($_COOKIE['ptsid']);
}

/**
 * Подпись версии контента. Основа — xml_fresh_key() (pages/posts: COUNT + MAX(updated_at),
 * images: COUNT + MAX(id), базовые настройки, эффективный хост, APP_VERSION).
 * Плюс своя структурная подпись: порядок страниц (page_move() меняет position без
 * updated_at и без site_touch) и фокусные точки фото (admin_image_focus() пишет
 * images.focus_x/focus_y таким же молчаливым UPDATE) — без них после таких правок
 * кэш отдавал бы старый порядок списков и старое кадрирование обложек.
 */
function pagecache_fresh_key(): string
{
    $sig = db_one(
        'SELECT (SELECT COALESCE(SUM(position * (id + 1)), 0) FROM pages) AS pp,'
        . " (SELECT COALESCE(SUM(CAST(ROUND(focus_x * 997) AS INTEGER) + CAST(ROUND(focus_y * 991) AS INTEGER)), 0) FROM images) AS fi"
    );
    return xml_fresh_key() . "\n" . pagecache_rev() . '|' . (string)($sig['pp'] ?? '') . '|' . (string)($sig['fi'] ?? '');
}

/** Префикс ревизии из site_rev (только цифры — он часть имени файла). Пусто → '0'. */
function pagecache_rev(): string
{
    $rev = (string)preg_replace('~[^0-9]~', '', setting('site_rev'));
    return $rev !== '' ? $rev : '0';
}

/** Пути к паре файлов кэша страницы: [<rev>-<sha1>.html, <rev>-<sha1>.meta]. */
function pagecache_files(string $path): array
{
    $base = ROOT . '/storage/cache/pages/' . pagecache_rev() . '-' . sha1($path . "\n" . pagecache_fresh_key());
    return [$base . '.html', $base . '.meta'];
}

/**
 * Снимок страницы из кэша: ['html' => …, 'etag' => …, 'lastmod' => …] или null на промахе
 * (тогда index.php рендерит как обычно). Чтение гонко-устойчиво: GC другого процесса мог
 * удалить файл между is_file() и чтением — молча считаем промахом, а не падаем фаталом.
 */
function pagecache_lookup(string $path): ?array
{
    if (!pagecache_requestable()) {
        return null;
    }
    [$file, $metaFile] = pagecache_files($path);
    if (!is_file($file)) {
        return null;
    }
    /* Токен контактной формы принимается 8 суток (contact_token_ok), а файл кэша при живом
       rev мог бы жить неограниченно: pagecache_gc() вызывается только при записи, полная
       выметка — с вероятностью 1/50. На редко обновляемом сайте форма через 8 суток начала
       бы отклонять всех посетителей без самолечения. Поэтому HTML старше 6 суток считаем
       промахом: страница перерендерится со свежим токеном (2 суток запаса). */
    $mt = @filemtime($file);
    if ($mt === false || $mt < time() - 6 * 86400) {
        return null;
    }
    $html = @file_get_contents($file);
    if ($html === false) {
        return null;
    }
    /* .meta мог не дописаться или потеряться — страница при этом валидна (ключ гарантирует),
       поэтому битая/отсутствующая мета — не промах, а просто пустые etag/lastmod */
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

/**
 * Сохранить готовый ответ в кэш. Вызывается из index.php после вычисления ETag/Last-Modified —
 * они уходят в .meta, чтобы при хите отдавать те же заголовки без рендера.
 * Кэш — ускорение, а не обязанность: любая неудача записи молча пропускается,
 * страница уже готова и уйдёт клиенту как обычно.
 */
function pagecache_store(string $path, string $html, string $etag, int $lastmod, ?array $files = null): void
{
    /* страховка: кэшируем только успешный ответ на кэшируемый запрос (404 уходит раньше
       через serve_not_found() и сюда не доходит, но проверка дешёвая) */
    if (http_response_code() !== 200 || !pagecache_requestable()) {
        return;
    }
    [$file, $metaFile] = $files ?? pagecache_files($path);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    /* атомарная запись temp-файл + rename, как у xml_cache_fetch() и .htaccess:
       читатель другого процесса не должен подхватить файл наполовину; flock на запись
       не нужен — rename сам по себе атомарен */
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

/**
 * Уборка storage/cache/pages. Вызывается при ЗАПИСИ (а не при чтении — анонимный хит
 * не должен платить за скан каталога). Файлы с чужим rev-префиксом мертвы навсегда
 * (site_touch() сменил rev), их немного — удаляем сразу. Плюс с вероятностью ~1/50
 * выметаем всё старше 7 суток: сироты .meta без .html, недописанные .tmp-* и страницы,
 * к которым давно никто не обращался.
 */
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
