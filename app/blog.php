<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

/* ============================================================
 * Заметки: короткие тексты по адресам /{раздел}/{slug}.
 * Разделов может быть несколько (Блог, Новости, Статьи…) — настройка
 * в админке, «Разделы». С 1.9.54 текст заметки собирается из секций
 * (блоков), как у страниц: таблица sections, owner_type='post'.
 * ============================================================ */

/* Константы (POSTS_PER_PAGE, RSS_ITEMS, POST_TS_SQL) — в app/constants.php. */

/* ---------- разделы ---------- */

/** Список разделов заметок: [['slug'=>…, 'title'=>…, 'description'=>…], …]. 'blog' всегда есть и идёт первым. */
function post_sections(): array
{
    /* вызывается десятки раз за запрос (reserved_slugs, section_get, меню…) — декодируем JSON один раз для каждого значения настройки */
    static $memo = [];
    $rawJson = (string)setting('post_sections');
    if (isset($memo[$rawJson])) {
        return $memo[$rawJson];
    }
    $out = [];
    $raw = json_decode($rawJson, true);
    if (is_array($raw)) {
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $slug = (string)($s['slug'] ?? '');
            /* модификатор D обязателен: без него «slug\n» проходил проверку ($ допускает \n
               перед концом строки), как это уже ловили в valid_slug() (ревизия 1.9.34) */
            if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,49}$/D', $slug)) {
                continue;
            }
            $out[$slug] = [
                'slug' => $slug,
                'title' => trim((string)($s['title'] ?? '')),
                'description' => trim((string)($s['description'] ?? '')),
            ];
        }
    }
    $blog = $out['blog'] ?? ['slug' => 'blog', 'title' => '', 'description' => ''];
    unset($out['blog']);
    return $memo[$rawJson] = array_values(['blog' => $blog] + $out);
}

function section_get(string $slug): ?array
{
    foreach (post_sections() as $s) {
        if ($s['slug'] === $slug) {
            return $s;
        }
    }
    return null;
}

/* Основной раздел «Блог» берёт название и описание только из настроек (правятся в Разделы → Блог) —
   так в двух местах не может оказаться два разных названия. */
function section_title(array $s): string
{
    if ($s['slug'] === 'blog') {
        return blog_title();
    }
    return $s['title'] !== '' ? $s['title'] : $s['slug'];
}

function section_description(array $s): string
{
    if ($s['slug'] === 'blog') {
        return trim(setting('blog_description'));
    }
    return $s['description'];
}

function section_base(array $s): string
{
    return '/' . $s['slug'];
}

/** Раздел, к которому относится заметка. */
function post_section(array $p): array
{
    return section_get((string)($p['section'] ?? 'blog')) ?? section_get('blog');
}

function post_url(array $p): string
{
    return section_base(post_section($p)) . '/' . $p['slug'];
}

function post_get(int $id): ?array
{
    return $id > 0 ? db_one('SELECT * FROM posts WHERE id = ?', [$id]) : null;
}

function post_by_slug(string $slug): ?array
{
    return db_one('SELECT * FROM posts WHERE slug = ?', [$slug]);
}

/** Дата публикации заметки (published_at, а для старых записей — created_at). */
function post_pub_ts(array $p): int
{
    return (int)($p['published_at'] ?? 0) > 0 ? (int)$p['published_at'] : (int)$p['created_at'];
}

/** Одна страница списка заметок раздела: читаем из базы только нужные записи, а не весь блог. */
function posts_page(string $section, int $limit, int $offset): array
{
    return db_all(
        'SELECT * FROM posts WHERE is_published = 1 AND section = ? ORDER BY ' . POST_TS_SQL . ' DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
        [$section]
    );
}

function posts_count(string $section): int
{
    return (int)db_val('SELECT COUNT(*) FROM posts WHERE is_published = 1 AND section = ?', [$section]);
}

/** Русская дата вида «14 марта 2026». */
function ru_date(int $ts): string
{
    $m = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Дата ISO для микроразметки. */
function iso_date(int $ts): string
{
    return date('c', $ts);
}

/* ---------- вики-разметка (история: до 1.9.53 тело хранилось как wiki-текст;
   функции ниже нужны цепочке миграций db.php — v11 конвертирует wiki → HTML) ---------- */

/** Находит заметку по slug или по точному названию (без учёта регистра). Кэшируется на запрос. */
function post_find(string $target): ?array
{
    static $bySlug = null, $byTitle = null;
    if ($bySlug === null) {
        $bySlug = [];
        $byTitle = [];
        /* тела заметок для поиска цели ссылки не нужны (раньше SELECT * тянул все тексты целиком) */
        foreach (db_all('SELECT id, slug, title, section, is_published FROM posts') as $p) {
            $bySlug[(string)$p['slug']] = $p;
            $key = mb_strtolower(trim((string)$p['title']));
            if (!isset($byTitle[$key])) {
                $byTitle[$key] = $p;
            }
        }
    }
    $t = trim($target);
    return $bySlug[$t] ?? $byTitle[mb_strtolower($t)] ?? null;
}

/**
 * Строка inline-разметки → HTML. Входной текст ещё НЕ экранирован.
 * Готовые куски HTML (код, ссылки) прячутся в плейсхолдеры и подставляются в конце, поэтому жирный и курсив
 * больше не «цепляются» за звёздочки внутри адресов и кода и не ломают атрибуты href.
 */
function wiki_inline(string $text, bool $admin): string
{
    $out = e(str_replace("\0", '', $text));
    $stash = [];
    $keep = static function (string $html) use (&$stash): string {
        $stash[] = $html;
        return "\0" . (count($stash) - 1) . "\0";
    };

    // `код`
    $out = (string)preg_replace_callback('~`([^`\n]+)`~u', static function ($m) use ($keep): string {
        return $keep('<code>' . $m[1] . '</code>');
    }, $out);

    // [текст](https://…) — внешняя ссылка. Обрабатывается ДО вики-ссылок, чтобы [[…]] внутри подписи не давали вложенных <a>
    $out = (string)preg_replace_callback('~\[([^\[\]]+)\]\(([^)\s]+)\)~u', static function ($m) use ($keep): string {
        $url = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
        if (strpos($url, "\0") !== false) {
            return $m[0]; // в адрес попал плейсхолдер (код внутри адреса) — оставляем как обычный текст
        }
        /* «/» разрешён, но НЕ «//» и «/\» — это protocol-relative URL на чужой домен */
        if (!preg_match('~^(https?:|mailto:|tel:|/(?![/\\\\])|#)~i', $url)) {
            return $m[1];
        }
        $ext = preg_match('~^https?://~i', $url) ? ' target="_blank" rel="noopener"' : '';
        return $keep('<a href="' . e($url) . '"' . $ext . '>') . $m[1] . $keep('</a>');
    }, $out);

    // [[slug]] / [[Название]] / [[slug|свой текст]]
    $out = (string)preg_replace_callback(
        '~\[\[([^\[\]|]+)(?:\|([^\[\]]+))?\]\]~u',
        static function ($m) use ($admin, $keep): string {
            $target = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            $label = trim(html_entity_decode($m[2] ?? '', ENT_QUOTES, 'UTF-8'));
            $p = post_find($target);
            if ($p && ((int)$p['is_published'] || $admin)) {
                $text = $label !== '' ? $label : (string)$p['title'];
                $draft = (int)$p['is_published'] ? '' : ' (черновик)';
                return $keep('<a class="wiki-link" href="' . e(post_url($p)) . '">' . e($text . $draft) . '</a>');
            }
            $text = $label !== '' ? $label : $target;
            if ($admin) {
                return $keep('<a class="wiki-new" href="' . admin_base() . '?p=post_new&amp;title=' . rawurlencode($target)
                    . '" title="Такой заметки нет — нажмите, чтобы создать">' . e($text) . '</a>');
            }
            return $keep('<span class="wiki-new" title="Заметка ещё не написана">' . e($text) . '</span>');
        },
        $out
    );

    // **жирный**, *курсив* — теперь только по тексту, а не по готовым тегам
    $out = (string)preg_replace('~\*\*([^*\n]+)\*\*~u', '<strong>$1</strong>', $out);
    $out = (string)preg_replace('~(?<![\w*])\*([^*\n]+)\*(?![\w*])~u', '<em>$1</em>', $out);

    // подставляем спрятанные куски (вложенность — максимум 3 уровня)
    for ($i = 0; $i < 3 && strpos($out, "\0") !== false; $i++) {
        $out = (string)preg_replace_callback('~\x00(\d+)\x00~', static fn($m) => $stash[(int)$m[1]] ?? '', $out);
    }
    return str_replace("\0", '', $out);
}

/** Убирает вики-разметку, оставляя чистый текст (для анонсов, содержания, подсчёта слов). */
function wiki_strip(string $t): string
{
    $t = (string)preg_replace('~\[\[([^\[\]|]+)\|([^\[\]]+)\]\]~u', '$2', $t);
    $t = (string)preg_replace('~\[\[([^\[\]]+)\]\]~u', '$1', $t);
    $t = (string)preg_replace('~\[([^\[\]]+)\]\([^)\s]+\)~u', '$1', $t);
    $t = (string)preg_replace('~(\*\*|`)~u', '', $t);
    return (string)preg_replace('~(?<![\w*])\*([^*]+)\*(?![\w*])~u', '$1', $t);
}

/** Блок «Содержание» для длинных заметок: ссылки-якоря на заголовки. */
function toc_html(array $heads): string
{
    $h = '<nav class="toc" aria-label="Содержание"><div class="toc-title">Содержание</div><ul>';
    foreach ($heads as [$lvl, $id, $text]) {
        $h .= '<li class="l' . (int)$lvl . '"><a href="#' . e($id) . '">' . e($text) . '</a></li>';
    }
    return $h . "</ul></nav>\n";
}

/** Сколько слов в заметке. С 1.9.54 текст живёт в текстовых секциях — считаем их. */
function post_words(array $p): int
{
    return (int)preg_match_all('~[\p{L}\p{N}]+~u', html_to_plain(post_text_html($p)));
}

function post_reading_minutes(array $p): int
{
    return max(1, (int)ceil(post_words($p) / 180));
}

/** Весь текст заметки → HTML (абзацы, заголовки ##/###, списки "- " и "1. ", цитаты "> ", --- , фото {photo:ID}, галереи {gallery:ID,ID}).
 *  $toc = true — для заметок с тремя и более заголовками сверху добавляется «Содержание», у заголовков появляются якоря.
 *  С 1.9.54 заметка собирается из секций, как страница; эта функция остаётся для миграций
 *  (db.php, SCHEMA_V 11/12) — $expandMedia=false оставляет шорткоды {photo}/{gallery}
 *  текстом-токеном в <p>, чтобы миграция v12 могла вырезать их в отдельные секции. */
function blog_render_body(string $body, string $postTitle = '', bool $toc = false, bool $expandMedia = true): string
{
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
    if ($body === '') {
        return '';
    }
    $admin = is_admin();
    $html = '';
    $buf = [];
    $heads = [];
    $usedIds = [];
    $firstMedia = true;
    $flush = static function () use (&$html, &$buf, $admin): void {
        if ($buf) {
            $html .= '<p>' . implode("<br>\n", array_map(static fn($l) => wiki_inline($l, $admin), $buf)) . "</p>\n";
            $buf = [];
        }
    };
    $lines = explode("\n", $body);
    $n = count($lines);
    $i = 0;
    while ($i < $n) {
        $t = trim($lines[$i]);
        if ($t === '') {
            $flush();
            $i++;
            continue;
        }
        if (preg_match('~^\{(photo|gallery)\s*:\s*([^}]*)\}$~u', $t, $m)) {
            if (!$expandMedia) { // миграция в HTML: шорткод остаётся текстом-токеном в абзаце
                $buf[] = $t;
                $i++;
                continue;
            }
            $flush();
            $parts = explode('|', (string)$m[2]);
            $idsRaw = trim((string)array_shift($parts));
            $opts = block_opts_parse($parts);
            if (empty($opts['eager'])) {
                /* первый медиаблок заметки — вероятный LCP: без lazy (дальше по тексту — обычные lazy) */
                $opts['eager'] = $firstMedia ? '1' : null;
                $firstMedia = false;
            }
            /* {photo:12, 13} — это опечатка (по одному фото в блоке), берём первый id, а не склеенный «1213» */
            $html .= $m[1] === 'photo'
                ? post_photo_block(preg_match('~\d+~', $idsRaw, $pm) ? (int)$pm[0] : 0, $postTitle, $opts)
                : post_gallery_block($idsRaw, $postTitle, $opts);
            $i++;
            continue;
        }
        if (preg_match('~^(#{1,3})\s+(.+)$~u', $t, $m)) {
            $flush();
            $lvl = strlen($m[1]) >= 3 ? 3 : 2; // # и ## → h2, ### → h3: H1 на странице один — заголовок заметки
            $plain = trim((string)preg_replace('~\s+~u', ' ', wiki_strip($m[2])));
            $hid = slugify($plain, 50);
            if ($hid === '') {
                $hid = 'razdel';
            }
            $base = $hid;
            for ($k = 2; isset($usedIds[$hid]); $k++) {
                $hid = $base . '-' . $k;
            }
            $usedIds[$hid] = true;
            $heads[] = [$lvl, $hid, $plain];
            $html .= '<h' . $lvl . ' id="' . e($hid) . '">' . wiki_inline($m[2], $admin) . '</h' . $lvl . ">\n";
            $i++;
            continue;
        }
        if ($t === '---' || $t === '***') {
            $flush();
            $html .= "<hr>\n";
            $i++;
            continue;
        }
        if (str_starts_with($t, '- ')) {
            $flush();
            $html .= "<ul>\n";
            while ($i < $n && str_starts_with(trim($lines[$i]), '- ')) {
                $html .= '<li>' . wiki_inline(mb_substr(trim($lines[$i]), 2), $admin) . "</li>\n";
                $i++;
            }
            $html .= "</ul>\n";
            continue;
        }
        if (preg_match('~^\d{1,3}[.)]\s+~u', $t)) {
            $flush();
            $html .= "<ol>\n";
            while ($i < $n && preg_match('~^\d{1,3}[.)]\s+(.*)$~u', trim($lines[$i]), $om)) {
                $html .= '<li>' . wiki_inline($om[1], $admin) . "</li>\n";
                $i++;
            }
            $html .= "</ol>\n";
            continue;
        }
        if (str_starts_with($t, '> ')) {
            $flush();
            $q = [];
            while ($i < $n && str_starts_with(trim($lines[$i]), '> ')) {
                $q[] = mb_substr(trim($lines[$i]), 2);
                $i++;
            }
            $html .= '<blockquote>' . implode("<br>\n", array_map(static fn($l) => wiki_inline($l, $admin), $q)) . "</blockquote>\n";
            continue;
        }
        $buf[] = $t;
        $i++;
    }
    $flush();
    if ($toc && count($heads) >= 3) {
        $html = toc_html($heads) . $html;
    }
    return $html;
}

/* ---------- HTML-тело заметки (с 1.9.53 тело хранится как HTML из визуального редактора) ---------- */

/** HTML → чистый текст (для анонсов, подсчёта слов, description). */
function html_to_plain(string $html): string
{
    $t = (string)preg_replace('~<(script|style)[^>]*>.*?</\1>~si', ' ', $html);
    $t = strip_tags($t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_replace('~\s+~u', ' ', $t));
}

/**
 * Подготовка HTML-тела заметки при сохранении: заголовкам h2/h3 без id проставляются
 * якоря (slug из текста, уникальные в пределах заметки) — якоря нужны «Содержанию» и прямым ссылкам.
 * Санитизация — до этой функции (sanitize_html), здесь только якоря.
 * $used — уже занятые якоря: с 1.9.54 текст заметки разбит на секции, и уникальность
 * держится в пределах ВСЕЙ заметки — вызывающий передаёт общий массив от секции к секции.
 */
function blog_html_prepare(string $html, array &$used = []): string
{
    /* id уже бывших в этом куске (могли остаться от прошлых правок) — тоже считаем занятыми */
    preg_match_all('~<h[23]\b[^>]*\sid="([^"]+)"~i', $html, $m);
    foreach ($m[1] as $id) {
        $used[$id] = true;
    }
    return (string)preg_replace_callback(
        '~<h([23])((?:(?!\sid=)[^>])*)>(.*?)</h\1>~si',
        static function (array $m) use (&$used): string {
            $plain = html_to_plain($m[3]);
            $hid = slugify($plain, 50);
            if ($hid === '') {
                $hid = 'razdel';
            }
            $base = $hid;
            for ($k = 2; isset($used[$hid]); $k++) {
                $hid = $base . '-' . $k;
            }
            $used[$hid] = true;
            return '<h' . $m[1] . rtrim((string)$m[2]) . ' id="' . $hid . '">' . $m[3] . '</h' . $m[1] . '>';
        },
        $html
    );
}

/* ---------- заметка как стопка секций (с 1.9.54 текст живёт в sections, owner_type='post') ---------- */

/** Весь текст заметки одним HTML (текстовые секции по порядку): для анонсов, подсчёта слов,
    индекса ссылок между заметками и meta description. Медиа-секции текста не дают. */
function post_text_html(array $p): string
{
    $out = '';
    foreach (post_content_sections((int)$p['id']) as $s) {
        if ($s['type'] === 'text') {
            $out .= (string)$s['data']['html'] . "\n";
        }
    }
    return $out;
}

/** Заголовки h2/h3 с якорями из всех текстовых секций заметки — для блока «Содержание»
    (якоря расставляет blog_html_prepare при сохранении / миграции). */
function post_content_heads(array $sections): array
{
    $heads = [];
    foreach ($sections as $s) {
        if ($s['type'] !== 'text') {
            continue;
        }
        preg_match_all('~<h([23])\b[^>]*\sid="([^"]+)"[^>]*>(.*?)</h\1>~si', (string)$s['data']['html'], $m, PREG_SET_ORDER);
        foreach ($m as $h) {
            $plain = html_to_plain((string)$h[3]);
            if ($plain !== '') {
                $heads[] = [(int)$h[1], (string)$h[2], $plain];
            }
        }
    }
    return $heads;
}

/** Контекст «как страница» для шаблонов секций заметки: они ждут $page (title — для alt
    по умолчанию, page_url() — для формы связи). id обнуляем: «Список страниц» в режиме
    «вложенные» иначе нашёл бы детей страницы со случайно совпавшим id. slug собираем как
    «раздел/адрес», чтобы page_url() отдал настоящий адрес заметки. */
function post_page_context(array $post): array
{
    $ctx = $post;
    $ctx['id'] = 0;
    $ctx['is_home'] = 0;
    $ctx['slug'] = trim(section_base(post_section($post)), '/') . '/' . $post['slug'];
    return $ctx;
}

/** Полный HTML заметки из её секций — для RSS (content:encoded). На сайте секции выводит
    templates/blog_post.php (там же «Содержание» и eager-загрузка первого медиа). */
function post_render_html(array $post): string
{
    $ctx = post_page_context($post);
    $html = '';
    foreach (post_content_sections((int)$post['id']) as $s) {
        if (in_array($s['type'], ['contact', 'html'], true)) {
            continue;
        }
        $html .= render_section($s, $ctx);
    }
    return $html;
}

/** Разбор HTML-тела заметки на секции (миграция 1.9.54, db.php SCHEMA_V 12): абзацы-токены
    {photo:…}/{gallery:…} → настоящие секции «Фото»/«Галерея» (опции токена переносятся),
    остальной HTML между ними — секции «Текст». Токены, не стоящие отдельным абзацем,
    остаются обычным текстом — как они и показывались посетителям в 1.9.53. */
function post_body_to_sections(string $html): array
{
    $out = [];
    $buf = [];
    $flush = static function () use (&$out, &$buf): void {
        /* пустые обрезки (между токенами часто остаются одни переводы строк) секциями не делаем;
           при этом <hr> и подобный «немой» HTML сохраняем — он виден посетителю */
        $h = trim(implode('', $buf));
        if ($h !== '') {
            $out[] = ['type' => 'text', 'html' => $h];
        }
        $buf = [];
    };
    $parts = preg_split('~(<p>\s*\{(?:photo|gallery)\s*:[^}]*\}\s*(?:<br\s*/?>)?\s*</p>)~u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $part) {
        $t = trim($part);
        if (preg_match('~^<p>\s*\{(photo|gallery)\s*:\s*([^}]*)\}\s*(?:<br\s*/?>)?\s*</p>$~u', $t, $m)) {
            $flush();
            $tokens = explode('|', (string)$m[2]);
            $idsRaw = trim((string)array_shift($tokens));
            $opts = block_opts_parse($tokens);
            if ($m[1] === 'photo') {
                /* {photo:12, 13} — опечатка: берём первый id, как делал рендер 1.9.53 */
                $out[] = ['type' => 'photo', 'image_id' => preg_match('~\d+~', $idsRaw, $pm) ? (int)$pm[0] : 0, 'opts' => $opts];
            } else {
                $out[] = ['type' => 'gallery', 'ids' => array_values(array_filter(array_map('intval', preg_split('~[^\d]+~', $idsRaw)))), 'opts' => $opts];
            }
        } else {
            $buf[] = $part;
        }
    }
    $flush();
    return $out;
}

/** Анонс: ручной excerpt или первый абзац текста. С 1.9.54 текст — в текстовых секциях. */
function post_excerpt(array $p, int $max = 180): string
{
    $ex = trim((string)$p['excerpt']);
    if ($ex !== '') {
        return $ex;
    }
    /* из анонса убираем заголовки — они о структуре, а не о содержании */
    $text = html_to_plain((string)preg_replace('~<h[1-4]\b[^>]*>.*?</h[1-4]>~si', ' ', post_text_html($p)));
    if ($text === '') { // заметка только из заголовков — берём хотя бы их
        $text = html_to_plain(post_text_html($p));
    }
    return str_limit($text, $max);
}

/* ---------- фото и галереи в тексте ---------- */

/** Разбор опций после «|»: {photo:12|small|static}, {gallery:1,2,3|cols=3|lb},
    {photo:12|link=https://…|newtab} — своя ссылка по клику вместо лайтбокса (1.9.52). */
function block_opts_parse(array $tokens): array
{
    $o = ['size' => 'wide', 'lb' => null, 'cols' => 0, 'link' => null];
    foreach ($tokens as $raw) {
        $raw = trim($raw);
        if ($raw === '') {
            continue;
        }
        /* link= разбираем до strtolower: адреса регистрозависимы */
        if (stripos($raw, 'link=') === 0) {
            $url = normalize_url(substr($raw, 5));
            $o['link'] = $url !== '' ? ['url' => $url, 'new_tab' => false] : null;
            continue;
        }
        $t = strtolower($raw);
        if (array_key_exists($t, photo_size_presets())) {
            $o['size'] = $t;
        } elseif ($t === 'lb') {
            $o['lb'] = true;
        } elseif ($t === 'static' || $t === 'nolb') {
            $o['lb'] = false;
        } elseif ($t === 'newtab' && $o['link'] !== null) {
            $o['link']['new_tab'] = true;
        } elseif (preg_match('~^cols=(\d)$~', $t, $m)) {
            $o['cols'] = max(1, min(6, (int)$m[1]));
        }
    }
    return $o;
}

/** Одно фото из медиатеки: {photo:ID} на отдельной строке, опции через «|». */
function post_photo_block(int $id, string $postTitle, array $opts = []): string
{
    $img = $id > 0 ? image_get($id) : null;
    if (!$img) {
        return is_admin() ? '<p><span class="wiki-new">Фото №' . $id . ' не найдено в медиатеке — выберите заново</span></p>' : '';
    }
    $alt = trim((string)$img['alt']) !== '' ? (string)$img['alt'] : $postTitle;
    $link = isset($opts['link']) && is_array($opts['link']) ? $opts['link'] : null;
    return single_photo_html($img, (string)($opts['size'] ?? 'wide'), $opts['lb'] ?? null, $alt, 700, '', !empty($opts['eager']), false, $link);
}

/** Галерея из медиатеки: {gallery:ID,ID,…} на отдельной строке, опции через «|». */
function post_gallery_block(string $idsRaw, string $postTitle, array $opts = []): string
{
    $ids = array_values(array_filter(array_map('intval', preg_split('~[^\d]+~', $idsRaw))));
    images_prime($ids); // все фото галереи — одним запросом, дальше работает кэш image_get()
    $items = [];
    foreach ($ids as $id) {
        $img = $id > 0 ? image_get($id) : null;
        if ($img) {
            $items[] = $img;
        }
    }
    if (!$items) {
        return is_admin() ? '<p><span class="wiki-new">Галерея пуста или фото не найдены в медиатеке</span></p>' : '';
    }
    $n = count($items);
    $cols = (int)($opts['cols'] ?? 0);
    if ($cols < 1) {
        $cols = $n >= 5 ? 3 : ($n === 4 ? 2 : max(1, min(3, $n))); // 2→2, 3→3, 4→2, 5 и больше→3
    }
    $cols = min($cols, 6);
    /* на телефоне — мобильная настройка колонок, как у страничных галерей (ревизия 1.9.35, п.5);
       sizes на телефоне считаем по ней, а не по десктопному $cols */
    $colsM = max(1, min(3, (int)setting('gallery_columns_mobile'), $cols));
    $sizes = '(max-width: 700px) ' . (int)round(100 / $colsM) . 'vw, ' . (int)round(700 / $cols) . 'px';
    $lb = array_key_exists('lb', $opts) && $opts['lb'] !== null ? (bool)$opts['lb'] : setting('lightbox') === '1';
    $html = '<div class="pg grid-gallery js-gallery" style="--cols:' . $cols . ';--cols-m:' . $colsM . '">';
    /* eager только по просьбе вызывающего (первый медиаблок заметки — вероятный LCP): раньше
       первый ряд КАЖДОЙ галереи грузился без lazy, и длинная заметка с несколькими галереями
       тянула лишний трафик с самого открытия (аудит 1.9.48, п.11) */
    $eagerRow = !empty($opts['eager']);
    foreach ($items as $i => $img) {
        $html .= photo_html($img, $i, $sizes, $lb, $postTitle, $eagerRow && $i < $cols); // без суффикса «— фото N» (1.9.28)
    }
    return $html . "</div>\n";
}

/** Первое фото из секций заметки (для Open Graph, если своя картинка не задана):
    первая секция «Фото» с выбранным кадром, иначе первый кадр первой непустой «Галереи». */
function post_first_image(array $p): ?array
{
    $ids = posts_first_image_ids([(int)$p['id']]);
    $iid = (int)($ids[(int)$p['id']] ?? 0);
    return $iid > 0 ? image_get($iid) : null;
}

/**
 * Первые фото сразу для списка заметок: [post_id => image_id]. Два пакетных запроса
 * (секции + их галереи) вместо перебора по одной заметке (списки блога и RSS).
 */
function posts_first_image_ids(array $postIds): array
{
    $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds))));
    if (!$postIds) {
        return [];
    }
    $out = [];
    $galSecs = []; // post_id => [section_id, …] в порядке следования
    foreach (db_all_in("SELECT id, page_id, type, data FROM sections WHERE owner_type = 'post' AND page_id IN ({IN}) AND type IN ('photo','gallery') ORDER BY page_id, position, id", $postIds) as $s) {
        $pid = (int)$s['page_id'];
        if (isset($out[$pid])) {
            continue;
        }
        if ($s['type'] === 'photo') {
            $d = json_decode((string)$s['data'], true);
            $iid = (int)($d['image_id'] ?? 0);
            /* фото-секция без выбранного кадра на сайте не выводит ничего — пропускаем,
               первым медиа может оказаться следующая галерея */
            if ($iid > 0) {
                $out[$pid] = $iid;
            }
            continue;
        }
        $galSecs[$pid][] = (int)$s['id'];
    }
    if ($galSecs) {
        $allSids = [];
        foreach ($galSecs as $sids) {
            foreach ($sids as $sid) {
                $allSids[] = $sid;
            }
        }
        $imgBySec = [];
        foreach (db_all_in("SELECT owner_id, image_id FROM gallery_items WHERE owner_type = 'section' AND owner_id IN ({IN}) ORDER BY owner_id, position, id", $allSids) as $gi) {
            $sid = (int)$gi['owner_id'];
            if (!isset($imgBySec[$sid])) {
                $imgBySec[$sid] = (int)$gi['image_id'];
            }
        }
        foreach ($galSecs as $pid => $sids) {
            if (isset($out[$pid])) {
                continue;
            }
            foreach ($sids as $sid) {
                if (isset($imgBySec[$sid])) {
                    $out[$pid] = $imgBySec[$sid];
                    break;
                }
            }
        }
    }
    return $out;
}

/** Перестраивает индекс исходящих ссылок заметки (таблица post_links). Вызывать при каждом изменении текста.
    С 1.9.53 тело — HTML: индексируются внутренние ссылки <a href> на заметки блога (/раздел/slug).
    Цель по-прежнему хранится как slug — post_find() разрешает её тем же правилом. */
function post_links_reindex(int $id, string $body): void
{
    db_run('DELETE FROM post_links WHERE from_id = ?', [$id]);
    $seen = [];
    foreach (post_body_links($body) as $l) {
        $t = trim($l['target']);
        $lc = mb_strtolower($t);
        if ($t === '' || isset($seen[$lc])) {
            continue;
        }
        $seen[$lc] = true;
        db_run('INSERT INTO post_links (from_id, target, target_lc) VALUES (?,?,?)', [$id, $t, $lc]);
    }
}

/** Ссылки на заметки блога внутри HTML-тела: [['target' => slug, 'label' => ''], …].
    Ловим только адреса вида /раздел/slug, где «раздел» — существующий раздел заметок. */
function post_body_links(string $html): array
{
    if (!preg_match_all('~<a\b[^>]*\bhref="([^"]+)"~i', $html, $m)) {
        return [];
    }
    static $bases = null;
    if ($bases === null) {
        $bases = [];
        foreach (post_sections() as $s) {
            $bases[] = section_base($s);
        }
    }
    $out = [];
    foreach ($m[1] as $href) {
        $href = html_entity_decode((string)$href, ENT_QUOTES, 'UTF-8');
        $path = (string)parse_url($href, PHP_URL_PATH);
        foreach ($bases as $b) {
            if (preg_match('~^' . preg_quote($b, '~') . '/([a-z0-9][a-z0-9_\-]*)/?$~', $path, $mm)) {
                $out[] = ['target' => $mm[1], 'label' => ''];
                break;
            }
        }
    }
    return $out;
}

/**
 * Заметки, которые ссылаются на эту (ссылкой на её адрес; до 1.9.53 — вики-ссылкой [[...]]).
 * Раньше при каждом просмотре заметки регулярками разбирались тексты ВСЕХ заметок; теперь кандидаты берутся из индекса,
 * а итоговое совпадение по-прежнему проверяет post_find() — правила «slug, затем название» не изменились.
 */
function post_backlinks(array $post): array
{
    $id = (int)$post['id'];
    $cand = db_all(
        'SELECT from_id, target FROM post_links WHERE from_id <> ? AND target_lc IN (?, ?)',
        [$id, mb_strtolower((string)$post['slug']), mb_strtolower(trim((string)$post['title']))]
    );
    $ids = [];
    foreach ($cand as $c) {
        $r = post_find((string)$c['target']);
        if ($r && (int)$r['id'] === $id) {
            $ids[(int)$c['from_id']] = true;
        }
    }
    if (!$ids) {
        return [];
    }
    $ids = array_keys($ids);
    $in = implode(',', array_fill(0, count($ids), '?'));
    return db_all("SELECT * FROM posts WHERE is_published = 1 AND id IN ($in) ORDER BY id", $ids);
}

/**
 * Числа обратных ссылок сразу для списка заметок (админка «Блог», ревизия 1.9.34, п.8):
 * раньше count(post_backlinks($p)) на КАЖДУЮ строку списка делал запрос кандидатов +
 * post_find() на кандидата. Здесь — один запрос по post_links и разрешение целей через
 * статические карты post_find() (правила те же: сначала slug, затем точное название).
 * Как и post_backlinks(), считаем только ссылки из ОПУБЛИКОВАННЫХ заметок и не из самой заметки.
 *
 * @param int[] $ids id заметок списка
 * @return array<int,int> id заметки → число ссылающихся
 */
function post_backlink_counts(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $out = array_fill_keys($ids, 0);
    if (!$ids) {
        return $out;
    }
    $idSet = array_fill_keys($ids, true);
    $published = [];
    foreach (db_all('SELECT id, is_published FROM posts') as $r) {
        if ((int)$r['is_published'] === 1) {
            $published[(int)$r['id']] = true;
        }
    }
    /* разрешение цели — ровно как post_find(): точный slug, затем название без учёта регистра */
    $resolve = static function (string $target) {
        $t = trim($target);
        $r = post_find($t);
        return $r ? (int)$r['id'] : null;
    };
    foreach (db_all('SELECT DISTINCT from_id, target FROM post_links') as $row) {
        $from = (int)$row['from_id'];
        if (!isset($published[$from])) {
            continue; // ссылки из черновиков post_backlinks() не считает
        }
        $to = $resolve((string)$row['target']);
        if ($to !== null && $to !== $from && isset($idSet[$to])) {
            $out[$to]++;
        }
    }
    return $out;
}

/** «Ещё по теме»: свежие опубликованные заметки того же раздела, кроме текущей — внутренняя перелинковка. */
function post_related(array $post, int $limit = 3): array
{
    return db_all(
        'SELECT id, slug, title, section, published_at, created_at, updated_at FROM posts
         WHERE is_published = 1 AND section = ? AND id <> ? ORDER BY ' . POST_TS_SQL . ' DESC, id DESC LIMIT ' . max(1, $limit),
        [(string)($post['section'] ?? 'blog'), (int)$post['id']]
    );
}

/** Соседние опубликованные заметки того же раздела: [старше, новее] (null, если нет). */
function post_neighbors(array $post): array
{
    $sec = (string)($post['section'] ?? 'blog');
    $c = post_pub_ts($post);
    $id = (int)$post['id'];
    $ts = POST_TS_SQL;
    /* CAST(? AS INTEGER) — страховка: выражение CASE…END не имеет аффинности типа, и сравнение
       со строковым параметром в SQLite было бы всегда истинным/ложным (аудит 1.9.15, баг постнавигации).
       После типизированного биндинга в db_run() int и так ушёл бы как INTEGER, но полагаться на это
       в SQL с выражениями без аффинности нельзя. */
    /* Узкий SELECT (аудит 1.9.40, п.16): SELECT * тянул body обеих заметок — килобайты текста
       ради ссылки и заголовка. Шаблону (blog_post.php) нужны только url и title. */
    $older = db_one(
        "SELECT id, slug, title, section, published_at, created_at FROM posts WHERE is_published = 1 AND section = ? AND ($ts < CAST(? AS INTEGER) OR ($ts = CAST(? AS INTEGER) AND id < ?))
         ORDER BY $ts DESC, id DESC LIMIT 1",
        [$sec, $c, $c, $id]
    );
    $newer = db_one(
        "SELECT id, slug, title, section, published_at, created_at FROM posts WHERE is_published = 1 AND section = ? AND ($ts > CAST(? AS INTEGER) OR ($ts = CAST(? AS INTEGER) AND id > ?))
         ORDER BY $ts ASC, id ASC LIMIT 1",
        [$sec, $c, $c, $id]
    );
    return [$older, $newer];
}

/** Картинка-превью заметки для списка: своя для соцсетей, иначе первое фото из текста. */
function post_thumb(array $p): ?array
{
    $img = !empty($p['og_image_id']) ? image_get((int)$p['og_image_id']) : null;
    return $img ?: post_first_image($p);
}

/* ---------- SEO ---------- */

function post_seo_title(array $p): string
{
    $t = trim((string)$p['seo_title']);
    if ($t !== '') {
        return $t;
    }
    return str_replace(['%title%', '%site%'], [(string)$p['title'], setting('site_name')], setting('title_template'));
}

function post_seo_description(array $p): string
{
    $d = trim((string)$p['seo_description']);
    if ($d !== '') {
        return $d;
    }
    $ex = post_excerpt($p, 155); // как и у страниц: под сниппет ~150–160 знаков
    if ($ex !== '') {
        return $ex;
    }
    /* последний fallback — название сайта: пустого description быть не должно */
    $d = trim(setting('default_description'));
    return $d !== '' ? $d : trim(setting('site_name'));
}

function post_meta(array $p): array
{
    /* canonical — как у страниц (page_meta, 1.9.55): пусто/protocol-relative → свой адрес,
       путь «/…» → абсолютный, полный URL своего хоста — как есть */
    $canonical = trim((string)($p['canonical'] ?? ''));
    if ($canonical === '' || str_starts_with($canonical, '//')) {
        $canonical = abs_url(post_url($p));
    } elseif ($canonical[0] === '/') {
        $canonical = abs_url($canonical);
    }
    $og = null;
    $img = !empty($p['og_image_id']) ? image_get((int)$p['og_image_id']) : null;
    if (!$img) {
        $img = post_first_image($p);
    }
    if (!$img && setting('default_og_image_id') !== '') {
        $img = image_get((int)setting('default_og_image_id'));
    }
    if ($img) {
        /* с 1.9.67 — общая с страницами логика og_image_meta(): кадр 1200×630 вокруг фокусной
           точки (блог раньше оставался на старой ветке — вертикальные обложки заметок
           уезжали в центр-срез мессенджеров); фолбэк — вариант с честными размерами */
        $og = og_image_meta($img, (string)$p['title']);
    }
    $meta = [
        'title' => post_seo_title($p),
        'description' => post_seo_description($p),
        'canonical' => $canonical,
        'noindex' => !empty($p['noindex']) || !(int)$p['is_published'],
        'og_type' => 'article',
        'og_image' => $og,
        'article' => ['published' => iso_date(post_pub_ts($p)), 'modified' => iso_date((int)$p['updated_at'])],
    ];

    $base = base_url();
    $sec = post_section($p);
    $secBase = section_base($sec);
    $author = trim(setting('person_name')) !== '' ? trim(setting('person_name')) : setting('site_name');
    $type = trim((string)($p['schema_type'] ?? ''));
    if (!in_array($type, ['BlogPosting', 'Article', 'NewsArticle'], true)) {
        $type = 'BlogPosting';
    }
    /* publisher с logo — Google давно рекомендует его для Article-разметки */
    $publisher = ['@type' => 'Organization', 'name' => trim(setting('business_name')) !== '' ? trim(setting('business_name')) : setting('site_name')];
    $logo = jsonld_logo();
    if ($logo) {
        $publisher['logo'] = $logo;
    }
    $article = [
        '@context' => 'https://schema.org',
        '@type' => $type,
        '@id' => explode('#', $canonical, 2)[0] . '#post',
        'mainEntityOfPage' => $canonical,
        'headline' => (string)$p['title'],
        'datePublished' => iso_date(post_pub_ts($p)),
        'dateModified' => iso_date((int)$p['updated_at']),
        'inLanguage' => setting('lang'),
        'author' => ['@type' => 'Person', 'name' => $author, 'url' => $base . '/'],
        'publisher' => $publisher,
        'articleSection' => section_title($sec),
        'wordCount' => post_words($p),
        'isPartOf' => ['@id' => $base . $secBase . '#blog'],
    ];
    if ($meta['description'] !== '') {
        $article['description'] = $meta['description'];
    }
    if ($og) {
        $article['image'] = $og['url'];
    }
    $crumbs = [
        ['name' => 'Главная', 'url' => '/'],
        ['name' => section_title($sec), 'url' => $secBase],
        ['name' => (string)$p['title'], 'url' => post_url($p)],
    ];
    /* 1.9.32: заметка ссылается isPartOf на #blog (а тот — на #website), но обе сущности
       выводились только на странице раздела — в ответе заметки ссылки графа были висячими.
       Добавляем короткие блоки; полный Blog с blogPost остаётся на странице раздела,
       JSON-LD склеивает сущности по @id, поэтому дублирование @id безопасно. */
    $blogRef = [
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        '@id' => $base . $secBase . '#blog',
        'url' => $base . $secBase,
        'name' => section_title($sec),
        'isPartOf' => ['@id' => $base . '/#website'],
    ];
    $meta['jsonld'] = [
        jsonld_website_short(),
        $blogRef,
        $article,
        jsonld_breadcrumbs($crumbs),
    ];
    $meta['jsonld'] = array_merge($meta['jsonld'], jsonld_section_blocks($p, $meta, post_content_sections((int)$p['id'])));
    $extra = jsonld_extra_blocks((string)($p['jsonld_extra'] ?? ''));
    if ($extra) {
        $meta['jsonld'] = array_merge($meta['jsonld'], $extra);
    }
    return $meta;
}

function blog_title(): string
{
    $t = trim(setting('blog_title'));
    return $t !== '' ? $t : 'Блог';
}

/* og:image раздела: глобальный дефолт (через og_image_for с пустой страницей),
   иначе обложка/первое фото свежей заметки — чтобы репост /blog не уходил без картинки.
   Оба пути — через og_image_meta(): кадр 1200×630 вокруг фокуса, иначе честные размеры (1.9.67). */
function blog_index_og_image(string $title, array $posts): ?array
{
    $og = og_image_for(['og_image_id' => null, 'cover_image_id' => null, 'id' => 0, 'title' => $title]);
    if ($og || !$posts) {
        return $og;
    }
    $img = post_thumb($posts[0]);
    if (!$img) {
        return null;
    }
    return og_image_meta($img, $title);
}

function blog_index_meta(array $sec, int $pg = 1, int $pages = 1, array $posts = []): array
{
    $secBase = section_base($sec);
    $title = section_title($sec);
    $canonical = abs_url($secBase) . ($pg > 1 ? '?page=' . $pg : '');
    $desc = section_description($sec);
    if ($desc === '') {
        $desc = trim(setting('default_description'));
    }
    if ($desc === '') {
        $desc = trim(setting('site_name')); // как у страниц/заметок: пустого description быть не должно
    }
    /* у страниц пагинации раньше был одинаковый description — добавляем номер, как уже делаем в title */
    if ($pg > 1) {
        $desc = $desc !== '' ? $desc . ' — страница ' . $pg : 'Страница ' . $pg;
    }
    $meta = [
        'title' => str_replace(['%title%', '%site%'], [$title . ($pg > 1 ? ' — страница ' . $pg : ''), setting('site_name')], setting('title_template')),
        'description' => $desc,
        'canonical' => $canonical,
        'noindex' => false,
        /* Страницы пагинации — тонкий контент (тот же список + номер): закрываем от индексации,
           но оставляем follow, чтобы робот доходил до заметок с глубоких страниц. Canonical при
           этом остаётся на саму страницу: при noindex оба варианта допустимы, self-canonical честнее. */
        'robots' => $pg > 1 ? 'noindex,follow' : 'index,follow,max-image-preview:large,max-snippet:-1',
        'og_type' => 'website',
        'og_image' => blog_index_og_image($title, $posts),
    ];
    $last = (int)db_val('SELECT COALESCE(MAX(updated_at), 0) FROM posts WHERE is_published = 1 AND section = ?', [$sec['slug']]);
    content_lastmod($last); // список меняется при правке любой заметки раздела — для Last-Modified
    $blog = [
        '@context' => 'https://schema.org',
        '@type' => 'Blog',
        /* @id без ?page=N: страницы пагинации описывают ОДНУ сущность «блог», а не разные */
        '@id' => abs_url($secBase) . '#blog',
        'url' => $canonical,
        'name' => $title,
        'inLanguage' => setting('lang'),
        'isPartOf' => ['@id' => base_url() . '/#website'],
    ];
    if ($desc !== '') {
        $blog['description'] = $desc;
    }
    if ($last > 0) {
        $blog['dateModified'] = iso_date($last);
    }
    if ($posts) {
        $blog['blogPost'] = array_map(static fn($p) => [
            '@type' => 'BlogPosting',
            'headline' => (string)$p['title'],
            'url' => abs_url(post_url($p)),
            'datePublished' => iso_date(post_pub_ts($p)),
        ], $posts);
    }
    $crumbs = [
        ['name' => 'Главная', 'url' => '/'],
        ['name' => $title, 'url' => $secBase],
    ];
    $meta['jsonld'] = [
        jsonld_website_short(), // 1.9.32: isPartOf #website блока Blog ссылался на сущность, которой не было в этом ответе
        $blog,
        jsonld_breadcrumbs($crumbs),
    ];
    return $meta;
}

/* ---------- рендер ---------- */

function render_blog_index(array $sec): string
{
    $total = posts_count($sec['slug']);
    $pages = max(1, (int)ceil($total / POSTS_PER_PAGE));
    $pgRaw = $_GET['page'] ?? 1;
    $pgNum = is_scalar($pgRaw) ? (int)$pgRaw : 1;
    /* ?page=1 — дубль первой страницы: canonical бы и разрулил, но 301 чище (и Яндекс любит явные редиректы) */
    if (isset($_GET['page']) && $pgNum === 1) {
        redirect(section_base($sec), 301);
    }
    /* Раньше ?page=999 молча отдавал контент последней страницы с 200 OK —
       бесконечное число URL-дублей. Несуществующий номер страницы — честный 404, даже в пустом разделе. */
    if ($pgNum < 1 || $pgNum > $pages) {
        serve_not_found(request_path());
    }
    $pg = max(1, min($pages, $pgNum));
    $posts = posts_page($sec['slug'], POSTS_PER_PAGE, ($pg - 1) * POSTS_PER_PAGE); // из базы — только нужная страница
    /* секции всех заметок страницы — одним запросом: анонс и «время чтения» в шаблоне
       берутся из текстовых секций (1.9.54) */
    sections_prime('post', array_map(static fn($p) => (int)$p['id'], $posts));
    /* обложки всех заметок страницы — одним запросом (в шаблоне post_thumb() уже бьёт в кэш image_get) */
    $thumbIds = [];
    foreach ($posts as $p) {
        if ((int)($p['og_image_id'] ?? 0) > 0) {
            $thumbIds[(int)$p['og_image_id']] = true;
        }
    }
    foreach (posts_first_image_ids(array_map(static fn($p) => (int)$p['id'], $posts)) as $iid) {
        $thumbIds[$iid] = true;
    }
    images_prime(array_keys($thumbIds));
    $page = ['id' => 0, 'type' => 'blog', 'title' => section_title($sec), 'slug' => $sec['slug'], 'is_home' => 0, 'is_published' => 1,
        'edit_url' => admin_base() . '?p=posts'];
    ob_start();
    include ROOT . '/templates/blog_index.php';
    $content = (string)ob_get_clean();
    return render_layout(blog_index_meta($sec, $pg, $pages, $posts), $content, $page);
}

function render_blog_post(array $post): string
{
    $lastmod = max((int)$post['updated_at'], (int)db_val('SELECT COALESCE(MAX(updated_at), 0) FROM posts WHERE is_published = 1'));
    content_lastmod($lastmod); // в шаблоне есть заголовки и даты других заметок
    $page = $post + ['type' => 'post', 'is_home' => 0, 'edit_url' => admin_base() . '?p=post_edit&id=' . (int)$post['id']];
    ob_start();
    include ROOT . '/templates/blog_post.php';
    $content = (string)ob_get_clean();
    return render_layout(post_meta($post), $content, $page);
}

/* ---------- RSS ---------- */

function rss_xml(): string
{
    /* RSS — публичный продукт: даже если ленту запросил залогиненный автор (или xml взяли из кэша,
       собранного под ним), в неё не должны попасть админ-подсказки wiki-разметки и ссылки «править» */
    public_view(true);
    try {
        $base = base_url();
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:media="http://search.yahoo.com/mrss/">' . "\n<channel>\n";
        /* лента объединяет заметки всех разделов, поэтому канал называем сайтом, а не одним разделом «Блог» */
        $x .= '<title>' . xml_e(setting('site_name')) . "</title>\n";
        $x .= '<link>' . xml_e($base . '/') . "</link>\n";
        $x .= '<atom:link href="' . xml_e($base . '/feed.xml') . '" rel="self" type="application/rss+xml"/>' . "\n";
        $d = trim(setting('default_description'));
        $x .= '<description>' . xml_e($d !== '' ? $d : setting('site_name')) . "</description>\n";
        $x .= '<language>' . xml_e(setting('lang')) . "</language>\n";
        /* noindex-заметки автор явно закрыл от индексации — в публичной ленте им тоже не место */
        $rows = db_all('SELECT * FROM posts WHERE is_published = 1 AND noindex = 0 ORDER BY ' . POST_TS_SQL . ' DESC, id DESC LIMIT ' . RSS_ITEMS);
        if ($rows) {
            $x .= '<lastBuildDate>' . date('r', (int)max(array_map(static fn($r) => (int)$r['updated_at'], $rows))) . "</lastBuildDate>\n";
        }
        /* обложки всех заметок ленты — одним пакетным запросом вместо запроса на каждую;
           секции — тоже пакетно (анонс и полный текст собираются из секций, 1.9.54) */
        sections_prime('post', array_map(static fn($r) => (int)$r['id'], $rows));
        $ids = [];
        foreach ($rows as $p) {
            if ((int)($p['og_image_id'] ?? 0) > 0) {
                $ids[(int)$p['og_image_id']] = true;
            }
        }
        foreach (posts_first_image_ids(array_map(static fn($r) => (int)$r['id'], $rows)) as $iid) {
            $ids[$iid] = true;
        }
        images_prime(array_keys($ids));
        foreach ($rows as $p) {
            $x .= "<item>\n";
            $x .= '<title>' . xml_e((string)$p['title']) . "</title>\n";
            $x .= '<link>' . xml_e($base . post_url($p)) . "</link>\n";
            $x .= '<guid isPermaLink="true">' . xml_e($base . post_url($p)) . "</guid>\n";
            $x .= '<pubDate>' . date('r', post_pub_ts($p)) . "</pubDate>\n";
            $x .= '<category>' . xml_e(section_title(post_section($p))) . "</category>\n";
            $ex = post_excerpt($p, 300);
            if ($ex !== '') {
                $x .= '<description>' . xml_e($ex) . "</description>\n";
            }
            /* Полный текст — для RSS-читалок и Яндекс.Дзена; относительные адреса фото делаем
               абсолютными, иначе в агрегаторах картинки не откроются. С 1.9.35 абсолютными
               становятся и srcset (фото в тексте раньше оставались относительными именно там —
               читалки показывали их битыми; ревизия 1.9.34, п.11). xml_clean убирает байты,
               недопустимые в XML 1.0, — один такой символ в тексте делал невалидным весь feed.
               С 1.9.54 текст рендерится из секций заметки (post_render_html). */
            $bodyHtml = post_render_html($p);
            if ($bodyHtml !== '') {
                $bodyHtml = (string)preg_replace('~\b(src|href)="/(?!/)~', '$1="' . $base . '/', $bodyHtml);
                $bodyHtml = (string)preg_replace_callback(
                    '~\bsrcset="([^"]*)"~',
                    static function (array $m) use ($base): string {
                        $parts = [];
                        foreach (explode(',', $m[1]) as $one) {
                            $one = trim($one); // элемент — «URL дескриптор», дескриптор (640w) не трогаем
                            if ($one !== '' && $one[0] === '/' && ($one[1] ?? '') !== '/') {
                                $one = $base . $one;
                            }
                            $parts[] = $one;
                        }
                        return 'srcset="' . implode(', ', $parts) . '"';
                    },
                    $bodyHtml
                );
                $x .= '<content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', xml_clean($bodyHtml)) . "]]></content:encoded>\n";
            }
            /* обложка заметки — превью в читалках (og-image, иначе первое фото из текста) */
            $thumb = post_thumb($p);
            if ($thumb) {
                $x .= '<media:content url="' . xml_e(abs_url(image_url($thumb, (int)image_pick_width(image_widths($thumb), 1240), 'jpg'))) . '" medium="image"/>' . "\n";
            }
            $x .= "</item>\n";
        }
        return $x . "</channel>\n</rss>\n";
    } finally {
        public_view(false);
    }
}
