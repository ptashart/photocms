<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
/**
 * Импорт сайта с конструктора wfolio по сохранённым HTML-страницам.
 *
 *   php tools/import_wfolio.php /путь/к/папке_с_html [--dry-run] [--force] [--skip-images]
 *
 * Что делает:
 *  - читает страницы (главная, разделы, альбомы), их title/description, тексты, кнопки, карточки;
 *  - скачивает самые крупные версии всех фотографий и кладёт на ВАШ сервер (storage/originals),
 *    дальше сайт отдаёт их только со своего домена, в нужных размерах и в WebP;
 *  - строит страницы, меню, родителей альбомов, ссылки на соцсети, favicon и картинку для соцсетей.
 * Сначала установите сайт через install.php. Запуск повторно пропускает уже существующие страницы (без --force).
 */
if (PHP_SAPI !== 'cli') {
    exit('Запускайте из командной строки: php tools/import_wfolio.php <папка с html>');
}
require dirname(__DIR__) . '/app/bootstrap.php';

@ini_set('memory_limit', '1024M');
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

$opts = ['dry-run' => false, 'force' => false, 'skip-images' => false];
$paths = [];
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--') === 0) {
        $k = substr($a, 2);
        if (!array_key_exists($k, $opts)) {
            fwrite(STDERR, "Неизвестный параметр: $a\n");
            exit(1);
        }
        $opts[$k] = true;
    } else {
        $paths[] = $a;
    }
}
if (!$paths) {
    fwrite(STDERR, "Использование: php tools/import_wfolio.php <папка или файлы .html> [--dry-run] [--force] [--skip-images]\n");
    exit(1);
}
if (!$opts['dry-run'] && !is_installed()) {
    fwrite(STDERR, "Сначала установите сайт через install.php в браузере.\n");
    exit(1);
}
$dry = $opts['dry-run'];

function out(string $s): void
{
    echo $s . "\n";
}

function has_class(string $c): string
{
    return 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
}

function inner_html(DOMNode $n): string
{
    $h = '';
    foreach ($n->childNodes as $c) {
        $h .= $n->ownerDocument->saveHTML($c);
    }
    return $h;
}

function norm_url(string $u): string
{
    $u = trim($u);
    return strpos($u, '//') === 0 ? 'https:' . $u : $u;
}

function http_get(string $url, int $tries = 3): ?string
{
    for ($i = 0; $i < $tries; $i++) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 90,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (site-migration)',
                CURLOPT_MAXREDIRS => 5,
            ]);
            /* только http/https (в том числе при редиректах): без этого curl согласится и на file://, ftp:// и т. п. */
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
                curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            }
            curl_setopt($ch, CURLOPT_MAXFILESIZE, 200 * 1048576);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch); // curl_close() объявлен deprecated в PHP 8.5; с 8.0 дескриптор — объект и освобождается сам (аудит 1.9.40, п.19)
            if ($body !== false && $code === 200 && $body !== '') {
                return (string)$body;
            }
        } else {
            /* fallback без curl: только http(s) — иначе file_get_contents согласился бы и на
               file:// (чтение локальных файлов через «адрес» из источника) (аудит 1.9.48, п.15) */
            if (!preg_match('~^https?://~i', $url)) {
                return null;
            }
            $ctx = stream_context_create(['http' => ['timeout' => 90, 'header' => "User-Agent: Mozilla/5.0 (site-migration)\r\n"]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false && $body !== '') {
                return $body;
            }
        }
        sleep(1);
    }
    return null;
}

$imgCache = [];
$imgFail = [];
$imgCount = 0;

/* slug заглушки из чужого path может быть невалидным для CMS (верхний регистр, кириллица,
   точки): такая страница создалась бы недоступной по адресу (аудит 1.9.48, п.15) —
   нормализуем, а при коллизии добавляем короткий суффикс */
function imp_slug(string $raw, string $fallback): string
{
    $s = valid_slug($raw) ? $raw : slugify($raw);
    if ($s === '' || !valid_slug($s)) {
        $s = $fallback;
    }
    if (page_by_slug($s)) {
        $s = $s . '-' . substr(sha1($raw), 0, 6);
    }
    return $s;
}

function imp_image(string $url, string $alt, string $name = ''): ?int
{
    global $opts, $imgCache, $imgFail, $imgCount;
    if ($opts['skip-images'] || $opts['dry-run'] || $url === '') {
        return null;
    }
    $url = norm_url($url);
    if (array_key_exists($url, $imgCache)) {
        return $imgCache[$url];
    }
    $body = http_get($url);
    if ($body === null) {
        $imgFail[] = $url . ' — не скачалось';
        echo 'x';
        return $imgCache[$url] = null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($tmp, $body);
    $id = null;
    try {
        $base = $name !== '' ? $name : ($alt !== '' ? $alt : 'photo');
        $img = image_add_from_file($tmp, $base . '.jpg', $alt);
        $id = (int)$img['id'];
        $imgCount++;
        echo '.';
    } catch (Throwable $e) {
        $imgFail[] = $url . ' — ' . $e->getMessage();
        echo 'x';
    }
    @unlink($tmp);
    return $imgCache[$url] = $id;
}

/** Из data-gallery-versions берём версию с наибольшим разрешением. */
function best_version(DOMElement $a): ?string
{
    $v = json_decode($a->getAttribute('data-gallery-versions'), true);
    if (!is_array($v) || !$v) {
        return null;
    }
    usort($v, static fn($x, $y) => ((int)($y['w'] ?? 0) * (int)($y['h'] ?? 0)) <=> ((int)($x['w'] ?? 0) * (int)($x['h'] ?? 0)));
    return isset($v[0]['src']) ? norm_url((string)$v[0]['src']) : null;
}

function pieces_of(DOMXPath $xp, DOMNode $root): array
{
    $list = [];
    foreach ($xp->query('.//div[' . has_class('piece') . ']', $root) as $piece) {
        $a = $xp->query('.//a[@data-gallery-versions]', $piece)->item(0);
        if (!$a) {
            continue;
        }
        $src = best_version($a);
        if (!$src) {
            continue;
        }
        $img = $xp->query('.//img', $piece)->item(0);
        $alt = $img ? trim($img->getAttribute('alt')) : '';
        if (strpos($alt, ' | ') !== false) {
            $alt = ''; // конструктор подставляет в alt заголовок страницы целиком — это не описание фото
        }
        $list[] = ['src' => $src, 'alt' => $alt];
    }
    return $list;
}

function icon_from_class(string $cls): string
{
    foreach (['phone' => 'phone', 'telegram' => 'telegram', 'whatsapp' => 'whatsapp', 'envelope' => 'mail', 'instagram' => 'instagram', 'youtube' => 'youtube'] as $k => $v) {
        if (strpos($cls, $k) !== false) {
            return $v;
        }
    }
    return '';
}

function path_of(string $href, string $host): ?string
{
    $u = parse_url(norm_url($href));
    if ($u === false) {
        return null;
    }
    if (isset($u['scheme']) && !in_array(strtolower((string)$u['scheme']), ['http', 'https'], true)) {
        return null;
    }
    if (isset($u['host']) && strtolower(preg_replace('/^www\./', '', $u['host'])) !== $host) {
        return null;
    }
    return '/' . trim((string)($u['path'] ?? ''), '/');
}

function parse_page(string $file): ?array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . (string)file_get_contents($file));
    libxml_clear_errors();
    $xp = new DOMXPath($dom);

    $meta = static function (string $q) use ($xp): string {
        $n = $xp->query($q)->item(0);
        return $n ? trim($n->getAttribute('content')) : '';
    };
    $canon = '';
    $cn = $xp->query('//link[@rel="canonical"]')->item(0);
    if ($cn) {
        $canon = trim($cn->getAttribute('href'));
    }
    if ($canon === '') {
        $canon = $meta('//meta[@property="og:url"]');
    }
    if ($canon === '') {
        return null;
    }
    $u = parse_url($canon);
    $host = strtolower(preg_replace('/^www\./', '', (string)($u['host'] ?? '')));
    $path = '/' . trim((string)($u['path'] ?? ''), '/');
    $body = $xp->query('//body')->item(0);
    $bodyClass = $body ? $body->getAttribute('class') : '';
    $titleNode = $dom->getElementsByTagName('title')->item(0);

    $pg = [
        'file' => basename($file),
        'host' => $host,
        'path' => $path,
        'slug' => trim($path, '/'),
        'is_home' => $path === '/',
        'type' => strpos($bodyClass, 'uc-page-type-album') !== false ? 'album' : 'page',
        'title_tag' => $titleNode ? trim($titleNode->textContent) : '',
        'description' => $meta('//meta[@name="description"]'),
        'og_image' => $meta('//meta[@property="og:image"]'),
        'yandex' => $meta('//meta[@name="yandex-verification"]'),
        'google' => $meta('//meta[@name="google-site-verification"]'),
        'sections' => [],
        'photos' => [],
        'unsupported' => [],
        'menu' => [],
        'socials' => [],
        'footer_text' => '',
        'favicon' => '',
    ];

    // иконки
    foreach ($xp->query('//link[contains(@rel,"apple-touch-icon")]') as $l) {
        $pg['favicon'] = norm_url($l->getAttribute('href'));
    }
    if ($pg['favicon'] === '') {
        foreach ($xp->query('//link[@rel="icon"][@sizes]') as $l) {
            $pg['favicon'] = norm_url($l->getAttribute('href'));
        }
    }

    // разделы
    foreach ($xp->query('//section[' . has_class('section-container') . ']') as $s) {
        $cls = $s->getAttribute('class');
        if (strpos($cls, 'text-section') !== false) {
            $pg['sections'][] = ['type' => 'text', 'html' => inner_html($s)];
        } elseif (strpos($cls, 'picture-set-section') !== false) {
            $cols = 3;
            if (preg_match('/(\d)\s*$/', $s->getAttribute('data-columns'), $m)) {
                $cols = max(1, min(6, (int)$m[1]));
            }
            $pg['sections'][] = ['type' => 'gallery', 'photos' => pieces_of($xp, $s), 'columns' => $cols];
        } elseif (strpos($cls, 'action-section') !== false) {
            $btns = [];
            foreach ($xp->query('.//a[' . has_class('button') . ']', $s) as $a) {
                $ic = $xp->query('.//i', $a)->item(0);
                $btns[] = [
                    'label' => trim($a->textContent),
                    'url' => normalize_url(trim($a->getAttribute('href'))), // javascript:/data: из чужой вёрстки отбрасываются
                    'icon' => $ic ? icon_from_class($ic->getAttribute('class')) : '',
                    'newtab' => $a->getAttribute('target') === '_blank',
                ];
            }
            $pg['sections'][] = [
                'type' => 'buttons', 'items' => $btns,
                'style' => strpos($cls, '-solid') !== false ? 'solid' : 'outline',
                'align' => strpos($cls, '-left') !== false ? 'left' : 'center',
            ];
        } elseif (strpos($cls, 'inline-listing-section') !== false) {
            $items = [];
            $lst = $xp->query('.//div[' . has_class('listing') . ']', $s)->item(0);
            $format = $lst ? $lst->getAttribute('data-format') : 'portrait';
            foreach ($xp->query('.//div[' . has_class('listing-item') . ']', $s) as $it) {
                $a = $xp->query('.//a[' . has_class('listing-link') . ']', $it)->item(0);
                if (!$a) {
                    continue;
                }
                $img = $xp->query('.//img', $it)->item(0);
                $ttl = $xp->query('.//div[' . has_class('listing-title') . ']', $it)->item(0);
                $items[] = [
                    'path' => path_of($a->getAttribute('href'), $host),
                    'title' => $ttl ? trim($ttl->textContent) : '',
                    'img' => $img ? norm_url($img->getAttribute('data-src')) : '',
                    'alt' => $img ? trim($img->getAttribute('alt')) : '',
                ];
            }
            $pg['sections'][] = ['type' => 'listing', 'items' => $items, 'format' => in_array($format, ['portrait', 'landscape', 'square'], true) ? $format : 'original'];
        } else {
            $pg['unsupported'][] = trim(preg_replace('/\s+/', ' ', $cls));
        }
    }

    // альбом
    if ($pg['type'] === 'album') {
        $g = $xp->query('//div[' . has_class('album-grid') . ']')->item(0);
        if ($g) {
            $pg['photos'] = pieces_of($xp, $g);
        }
    }

    // меню (берём с любой страницы, где оно есть)
    $menu = $xp->query('//ul[' . has_class('menu-list') . ']')->item(0);
    if ($menu) {
        foreach ($xp->query('./li', $menu) as $li) {
            $link = $xp->query('./a | ./span', $li)->item(0);
            if (!$link) {
                continue;
            }
            $node = ['title' => trim($link->textContent), 'href' => $link->nodeName === 'a' ? $link->getAttribute('href') : null, 'children' => []];
            foreach ($xp->query('.//ul[' . has_class('submenu-list') . ']/li/a', $li) as $ca) {
                $node['children'][] = ['title' => trim($ca->textContent), 'href' => $ca->getAttribute('href')];
            }
            $pg['menu'][] = $node;
        }
    }

    // подвал
    foreach ($xp->query('//ul[' . has_class('social-links') . ']//a') as $a) {
        $pg['socials'][] = trim($a->getAttribute('href'));
    }
    $fc = $xp->query('//footer//div[' . has_class('footer-content') . ']')->item(0);
    if ($fc) {
        $pg['footer_text'] = trim(preg_replace('/\s+/u', ' ', $fc->textContent));
    }
    return $pg;
}

function rewrite_internal_links(string $html, string $host): string
{
    return (string)preg_replace_callback(
        '~href="https?://(?:www\.)?' . preg_quote($host, '~') . '(/[^"]*)?"~i',
        static fn($m) => 'href="' . (($m[1] ?? '') !== '' ? $m[1] : '/') . '"',
        $html
    );
}

/* ---------- 1. читаем файлы ---------- */
$files = [];
foreach ($paths as $p) {
    if (is_dir($p)) {
        foreach (array_merge(glob(rtrim($p, '/') . '/*.html') ?: [], glob(rtrim($p, '/') . '/*.htm') ?: []) as $f) {
            $files[] = $f;
        }
    } elseif (is_file($p)) {
        $files[] = $p;
    }
}
sort($files);
if (!$files) {
    fwrite(STDERR, "HTML-файлы не найдены.\n");
    exit(1);
}

$parsed = [];
foreach ($files as $f) {
    $pg = parse_page($f);
    if (!$pg) {
        out('! Пропущен (нет canonical/og:url): ' . basename($f));
        continue;
    }
    $parsed[$pg['path']] = $pg;
    out(sprintf('Прочитано: %-40s → %s (%s), разделов: %d, фото в альбоме: %d', mb_substr($pg['file'], 0, 38), $pg['path'], $pg['type'], count($pg['sections']), count($pg['photos'])));
    foreach ($pg['unsupported'] as $cls) {
        out('  ! неподдерживаемый раздел пропущен: ' . $cls);
    }
}
if (!isset($parsed['/'])) {
    out('! Главная страница не найдена среди файлов — импортирую остальное, главную настройте вручную.');
}
$host = '';
foreach ($parsed as $pg) {
    $host = $pg['host'];
    break;
}

// меню, соцсети, подвал — с первой страницы, где они есть
$menuSrc = [];
$socials = [];
$footerText = '';
$favicon = '';
$ogDefault = '';
$yandex = '';
$google = '';
foreach ($parsed as $pg) {
    $menuSrc = $menuSrc ?: $pg['menu'];
    $socials = $socials ?: $pg['socials'];
    $footerText = $footerText !== '' ? $footerText : $pg['footer_text'];
    $favicon = $favicon !== '' ? $favicon : $pg['favicon'];
    $yandex = $yandex !== '' ? $yandex : $pg['yandex'];
    $google = $google !== '' ? $google : $pg['google'];
}
if (isset($parsed['/'])) {
    $ogDefault = $parsed['/']['og_image'];
}

// заголовки страниц (для меню и списков)
$menuTitles = [];
foreach ($menuSrc as $n) {
    $p = $n['href'] ? path_of($n['href'], $host) : null;
    if ($p !== null) {
        $menuTitles[$p] = $n['title'];
    }
    foreach ($n['children'] as $c) {
        $cp = path_of($c['href'], $host);
        if ($cp !== null) {
            $menuTitles[$cp] = $c['title'];
        }
    }
}
$listTitles = [];
foreach ($parsed as $pg) {
    foreach ($pg['sections'] as $s) {
        if ($s['type'] === 'listing') {
            foreach ($s['items'] as $it) {
                if ($it['path'] !== null && $it['title'] !== '') {
                    $listTitles[$it['path']] = $it['title'];
                }
            }
        }
    }
}
function derive_title(array $pg, array $menuTitles, array $listTitles): string
{
    if ($pg['is_home']) {
        return 'Главная';
    }
    if (isset($menuTitles[$pg['path']])) {
        return $menuTitles[$pg['path']];
    }
    if (isset($listTitles[$pg['path']])) {
        return $listTitles[$pg['path']];
    }
    $left = explode(' | ', $pg['title_tag'])[0];
    $pos = mb_strpos($left, '. ');
    return $pos !== false ? mb_substr($left, 0, $pos) : $left;
}

if ($dry) {
    out("\n--- Пробный прогон (ничего не записано) ---");
    foreach ($parsed as $pg) {
        $n = 0;
        foreach ($pg['sections'] as $s) {
            $n += count($s['photos'] ?? []);
        }
        out(sprintf('%-28s «%s»  фото: %d', $pg['path'], derive_title($pg, $menuTitles, $listTitles), $n + count($pg['photos'])));
    }
    out('Пункты меню: ' . count($menuSrc) . ', соцсети: ' . count($socials) . ', favicon: ' . ($favicon !== '' ? 'есть' : 'нет'));
    exit(0);
}

/* ---------- 2. создаём страницы ---------- */
out("\nСоздаю страницы и скачиваю фотографии (точка = фото)…");
$pos = (int)db_val('SELECT COALESCE(MAX(position), 0) FROM pages');
$idBySlug = [];
$pendingListings = [];
$created = [];
$skipped = [];

$order = array_keys($parsed);
usort($order, static fn($a, $b) => ($a === '/' ? 0 : 1) <=> ($b === '/' ? 0 : 1));

foreach ($order as $path) {
    $pg = $parsed[$path];
    /* адрес из чужой вёрстки приводим к правилам этой CMS: вложенные пути, заглавные буквы и служебные слова
       дали бы недоступную или конфликтующую страницу */
    if (!$pg['is_home'] && (strpos($pg['slug'], '/') !== false || !valid_slug($pg['slug']))) {
        $skipped[] = $pg['path'] . ' (адрес недопустим для этой CMS: вложенный путь, заглавные буквы или служебное слово)';
        continue;
    }
    $title = derive_title($pg, $menuTitles, $listTitles);
    $t = now();

    if ($pg['is_home']) {
        $home = home_page();
        if (!$home) {
            $hid = db_insert("INSERT INTO pages (type, title, slug, is_home, is_published, position, created_at, updated_at) VALUES ('page','Главная','home',1,1,0,?,?)", [$t, $t]);
            $home = page_get($hid);
        }
        $pid = (int)$home['id'];
        $existingContent = (int)db_val("SELECT COUNT(*) FROM sections WHERE owner_type = 'page' AND page_id = ?", [$pid]);
        $isDefault = $existingContent === 1 && strpos((string)db_val("SELECT data FROM sections WHERE owner_type = 'page' AND page_id = ?", [$pid]), 'стартовая страница') !== false;
        if ($existingContent > 0 && !$isDefault && !$opts['force']) {
            $skipped[] = '/ (главная уже наполнена; --force чтобы перезаписать)';
            $idBySlug[''] = $pid;
            continue;
        }
    } else {
        $ex = page_by_slug($pg['slug']);
        if ($ex) {
            if (!$opts['force']) {
                $skipped[] = $pg['path'] . ' (уже существует; --force чтобы перезаписать)';
                $idBySlug[$pg['slug']] = (int)$ex['id'];
                continue;
            }
            $pid = (int)$ex['id'];
        } else {
            $pid = db_insert(
                'INSERT INTO pages (type, title, slug, is_published, position, show_title, created_at, updated_at) VALUES (?,?,?,1,?,?,?,?)',
                [$pg['type'], $title, $pg['slug'], ++$pos, $pg['type'] === 'album' ? 1 : 0, $t, $t]
            );
        }
    }

    // чистим прежнее содержимое (для повторного запуска с --force)
    foreach (db_all("SELECT id FROM sections WHERE owner_type = 'page' AND page_id = ?", [$pid]) as $s) {
        db_run("DELETE FROM gallery_items WHERE owner_type = 'section' AND owner_id = ?", [$s['id']]);
    }
    db_run("DELETE FROM sections WHERE owner_type = 'page' AND page_id = ?", [$pid]);
    db_run("DELETE FROM gallery_items WHERE owner_type = 'page' AND owner_id = ?", [$pid]);

    db_run(
        'UPDATE pages SET type = ?, title = ?, seo_title = ?, seo_description = ?, updated_at = ? WHERE id = ?', /* is_published не трогаем: --force не должен публиковать черновики */
        [$pg['type'], $title, $pg['title_tag'], $pg['description'], $t, $pid]
    );
    $idBySlug[$pg['slug']] = $pid;
    out("\n• " . $pg['path'] . ' — «' . $title . '»');

    $sp = 0;
    foreach ($pg['sections'] as $s) {
        $sp++;
        if ($s['type'] === 'text') {
            $d = section_defaults('text');
            $d['html'] = sanitize_html(rewrite_internal_links($s['html'], $host));
            db_insert('INSERT INTO sections (page_id, type, position, data) VALUES (?,?,?,?)', [$pid, 'text', $sp, json_encode($d, JSON_UNESCAPED_UNICODE)]);
        } elseif ($s['type'] === 'gallery') {
            $d = section_defaults('gallery');
            $d['columns'] = $s['columns'];
            $sid = db_insert('INSERT INTO sections (page_id, type, position, data) VALUES (?,?,?,?)', [$pid, 'gallery', $sp, json_encode($d, JSON_UNESCAPED_UNICODE)]);
            $ids = [];
            foreach ($s['photos'] as $k => $ph) {
                $id = imp_image($ph['src'], $ph['alt'], ($pg['is_home'] ? 'home' : $pg['slug']) . '-' . ($k + 1));
                if ($id) {
                    $ids[] = $id;
                }
            }
            gallery_replace('section', $sid, $ids);
        } elseif ($s['type'] === 'buttons') {
            $d = section_defaults('buttons');
            $d['items'] = array_values(array_filter($s['items'], static fn($b) => $b['label'] !== ''));
            $d['style'] = $s['style'];
            $d['align'] = $s['align'];
            db_insert('INSERT INTO sections (page_id, type, position, data) VALUES (?,?,?,?)', [$pid, 'buttons', $sp, json_encode($d, JSON_UNESCAPED_UNICODE)]);
            foreach ($d['items'] as $b) {
                if (strpos($b['url'], 'tel:') === 0 && trim(setting('phone')) === '') {
                    set_setting('phone', substr($b['url'], 4));
                }
            }
        } elseif ($s['type'] === 'listing') {
            $d = section_defaults('listing');
            $d['format'] = $s['format'];
            $sid = db_insert('INSERT INTO sections (page_id, type, position, data) VALUES (?,?,?,?)', [$pid, 'listing', $sp, json_encode($d, JSON_UNESCAPED_UNICODE)]);
            $pendingListings[] = ['section' => $sid, 'page' => $pid, 'items' => $s['items'], 'data' => $d];
        }
    }
    if ($pg['type'] === 'album') {
        $ids = [];
        foreach ($pg['photos'] as $k => $ph) {
            $id = imp_image($ph['src'], $ph['alt'], $pg['slug'] . '-' . ($k + 1));
            if ($id) {
                $ids[] = $id;
            }
        }
        gallery_replace('page', $pid, $ids);
        out(' фото: ' . count($ids) . ' из ' . count($pg['photos']));
    }
    $created[] = $pg['path'];
}

/* ---------- 3. карточки разделов, заглушки для страниц, которых не было среди файлов ---------- */
$stubs = [];
foreach ($pendingListings as $pl) {
    $ids = [];
    foreach ($pl['items'] as $i => $it) {
        if ($it['path'] === null || $it['path'] === '/') {
            continue;
        }
        $slug = trim($it['path'], '/');
        if (!isset($idBySlug[$slug])) {
            $ex = page_by_slug($slug);
            if ($ex) {
                $idBySlug[$slug] = (int)$ex['id'];
            } else {
                $t = now();
                $title = $it['title'] !== '' ? $it['title'] : $slug;
                $stubSlug = imp_slug($slug, 'album-' . ($pos + 1));
                $ex = page_by_slug($stubSlug);
                if ($ex) {
                    $sid = (int)$ex['id'];
                } else {
                    $sid = db_insert(
                        "INSERT INTO pages (type, title, slug, is_published, position, show_title, created_at, updated_at) VALUES ('album',?,?,0,?,1,?,?)",
                        [$title, $stubSlug, ++$pos, $t, $t]
                    );
                    $cover = imp_image($it['img'], '', $slug . '-cover');
                    if ($cover) {
                        db_run('UPDATE pages SET cover_image_id = ? WHERE id = ?', [$cover, $sid]);
                        gallery_replace('page', $sid, [$cover]);
                    }
                    $stubs[] = '/' . $slug . ' — альбом-черновик (есть только обложка)';
                }
                $idBySlug[$slug] = $sid;
            }
        }
        $cid = $idBySlug[$slug];
        $ids[] = $cid;
        db_run('UPDATE pages SET parent_id = ?, position = ? WHERE id = ? AND is_home = 0', [$pl['page'], $i + 1, $cid]);
    }
    $d = $pl['data'];
    $d['page_ids'] = $ids;
    db_run('UPDATE sections SET data = ? WHERE id = ?', [json_encode($d, JSON_UNESCAPED_UNICODE), $pl['section']]);
}

/* ---------- 4. меню ---------- */
function menu_target(string $href, string $host, array &$idBySlug, array &$stubs, string $title): array
{
    $p = path_of($href, $host);
    if ($p === null) {
        return [null, $href];
    }
    $slug = trim($p, '/');
    if ($slug === '') {
        $h = home_page();
        return [$h ? (int)$h['id'] : null, ''];
    }
    if (!isset($idBySlug[$slug])) {
        $ex = page_by_slug($slug);
        if ($ex) {
            $idBySlug[$slug] = (int)$ex['id'];
        } else {
            $stubSlug = imp_slug($slug, 'page-' . (count($stubs) + 1));
            $ex = page_by_slug($stubSlug);
            if ($ex) {
                $idBySlug[$slug] = (int)$ex['id'];
            } else {
                $t = now();
                $idBySlug[$slug] = db_insert(
                    "INSERT INTO pages (type, title, slug, is_published, position, created_at, updated_at) VALUES ('page',?,?,0,?,?,?)",
                    [$title, $stubSlug, 900 + count($stubs), $t, $t]
                );
                $stubs[] = '/' . $slug . ' — страница-черновик «' . $title . '» (наполните и опубликуйте)';
            }
        }
    }
    return [$idBySlug[$slug], ''];
}

if ($menuSrc) {
    db_tx(function () use ($menuSrc, $host, &$idBySlug, &$stubs): void {
        db_run('DELETE FROM menu_items');
        $mpos = 0;
        foreach ($menuSrc as $n) {
            $mpos += 10;
            [$pageId, $url] = $n['href'] !== null ? menu_target($n['href'], $host, $idBySlug, $stubs, $n['title']) : [null, ''];
            $parentId = db_insert('INSERT INTO menu_items (title, page_id, url, position) VALUES (?,?,?,?)', [$n['title'], $pageId, $url, $mpos]);
            $cpos = 0;
            foreach ($n['children'] as $c) {
                $cpos += 10;
                [$cp, $cu] = menu_target($c['href'], $host, $idBySlug, $stubs, $c['title']);
                db_insert('INSERT INTO menu_items (parent_id, title, page_id, url, position) VALUES (?,?,?,?,?)', [$parentId, $c['title'], $cp, $cu, $cpos]);
            }
        }
    });
    site_touch();
    out("\nМеню перенесено: " . count($menuSrc) . ' пунктов верхнего уровня.');
}

/* ---------- 5. настройки сайта ---------- */
$map = ['vk.' => 'social_vk', 'vkontakte' => 'social_vk', 't.me' => 'social_telegram', 'telegram' => 'social_telegram', 'instagram' => 'social_instagram', 'wa.me' => 'social_whatsapp', 'whatsapp' => 'social_whatsapp', 'youtube' => 'social_youtube'];
foreach ($socials as $url) {
    foreach ($map as $needle => $key) {
        if (stripos($url, $needle) !== false) {
            if (trim(setting($key)) === '') {
                set_setting($key, $url);
            }
            break;
        }
    }
}
if ($footerText !== '' && trim(setting('footer_text')) === '') {
    set_setting('footer_text', $footerText);
}
if ($yandex !== '' && trim(setting('verify_yandex')) === '') {
    set_setting('verify_yandex', $yandex);
}
if ($google !== '' && trim(setting('verify_google')) === '') {
    set_setting('verify_google', $google);
}
if ($favicon !== '' && setting('favicon_image_id') === '') {
    $fid = imp_image($favicon, '', 'favicon');
    if ($fid) {
        set_setting('favicon_image_id', (string)$fid);
    }
}
if ($ogDefault !== '' && setting('default_og_image_id') === '') {
    $oid = imp_image($ogDefault, '', 'og-image');
    if ($oid) {
        set_setting('default_og_image_id', (string)$oid);
    }
}

/* ---------- итог ---------- */
out("\n==================== ГОТОВО ====================");
out('Страниц создано/обновлено: ' . count($created) . ', фото скачано на ваш сервер: ' . $imgCount);
foreach ($skipped as $s) {
    out('Пропущено: ' . $s);
}
if ($stubs) {
    out("\nСозданы черновики (в меню не видны, пока не опубликуете):");
    foreach ($stubs as $s) {
        out('  ' . $s);
    }
}
if ($imgFail) {
    out("\nНе удалось загрузить:");
    foreach ($imgFail as $f) {
        out('  ' . $f);
    }
}
out("\nДальше:");
out(' 1. Админка → Настройки → «Основное»: укажите адрес сайта https://' . ($host ?: 'ваш-домен') . ' и проверьте остальное.');
out(' 2. Админка → Медиа → «Без alt»: проставьте описания фотографий.');
out(' 3. Админка → Страницы: проверьте страницы и опубликуйте черновики.');
