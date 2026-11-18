<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function section_types(): array
{
    return [
        'text' => 'Текст',
        'photo' => 'Фото',
        'gallery' => 'Галерея',
        'buttons' => 'Кнопки',
        'listing' => 'Список страниц (карточки)',
        'html' => 'HTML-код',
        'contact' => 'Форма связи',
        'faq' => 'Вопросы и ответы',
        'pricing' => 'Пакеты и цены',
        'video' => 'Видео',
    ];
}

/** Типы секций, доступные в заметках блога (1.9.54): всё, кроме «Вопросы и ответы» —
    FAQ-разметка остаётся особенностью страниц. «Пакеты и цены» (1.9.63) тоже только
    страницам: предложения OfferCatalog в заметке блога смысла не имеют. */
function post_section_types(): array
{
    $t = section_types();
    unset($t['faq'], $t['pricing']);
    return $t;
}

function section_defaults(string $type): array
{
    switch ($type) {
        case 'text':
            return ['html' => '', 'width' => 'narrow', 'align' => 'left', 'space' => 'md'];
        case 'gallery':
            /* autoplay* — автопрокрутка ленты (1.9.45): работает только при layout=strip;
               fx/speed/height — полноэкранное слайдшоу (1.9.46): только при layout=slides */
            return ['layout' => 'masonry', 'columns' => 0, 'lightbox' => '', 'autoplay' => '0', 'autoplay_dir' => 'fwd', 'autoplay_speed' => '5', 'fx' => 'fade', 'speed' => '5', 'height' => 'full', 'space' => 'md'];
        case 'photo':
            return ['image_id' => 0, 'size' => 'wide', 'lightbox' => '', 'caption' => '', 'link' => '', 'link_new_tab' => false, 'space' => 'md'];
        case 'buttons':
            return ['items' => [], 'style' => 'outline', 'align' => 'center', 'space' => 'md'];
        case 'listing':
            return ['mode' => 'pages', 'parent_id' => 0, 'page_ids' => [], 'format' => 'portrait', 'columns' => 3, 'kicker' => '', 'heading' => '', 'space' => 'md'];
        case 'html':
            return ['code' => '', 'space' => 'md'];
        case 'contact':
            return ['kicker' => '', 'heading' => '', 'intro' => '', 'button' => '', 'space' => 'md'];
        case 'faq':
            return ['kicker' => '', 'heading' => '', 'items' => [], 'space' => 'md'];
        case 'pricing':
            /* Пакеты и цены (1.9.63): items — [{name, desc, price_from, price_to, duration, url}];
               duration (1.9.76) — длительность пакета «8 часов» рядом с ценой;
               цены попадают в OfferCatalog микроразметки (см. jsonld_pricing_offers в seo.php) */
            return ['kicker' => '', 'heading' => '', 'currency' => 'RUB', 'items' => [], 'space' => 'md'];
        case 'video':
            return ['video_id' => 0, 'space' => 'md'];
    }
    return ['space' => 'md'];
}

function page_url(array $p): string
{
    return !empty($p['is_home']) ? '/' : '/' . $p['slug'];
}

function page_get(int $id): ?array
{
    return $id > 0 ? db_one('SELECT * FROM pages WHERE id = ?', [$id]) : null;
}

function page_by_slug(string $slug): ?array
{
    return db_one('SELECT * FROM pages WHERE slug = ?', [$slug]);
}

function home_page(): ?array
{
    return db_one('SELECT * FROM pages WHERE is_home = 1 LIMIT 1');
}

/** Кэш секций на время запроса (1.9.26): на обычной странице page_sections() дёргается из
    SEO (первый текст для description), потом из render_page() и шаблона — раньше каждый вызов
    повторял SELECT и json_decode всех секций. Ключ — «владелец:id» ('page:5', 'post:12'):
    с 1.9.54 секции бывают не только у страниц, но и у заметок блога. */
function &page_sections_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Сброс кэша секций: вызывается при любой записи в sections (сохранение страницы/заметки,
    добавление/удаление/перестановка секций, обнуление ссылки на удалённое фото).
    null — сбросить всё. */
function page_sections_cache_flush(?int $pageId = null, string $ownerType = 'page'): void
{
    $cache = &page_sections_cache();
    if ($pageId === null) {
        $cache = [];
    } else {
        unset($cache[$ownerType . ':' . $pageId]);
    }
}

/** Секции владельца в порядке вывода: 'page' — страница сайта, 'post' — заметка блога (1.9.54).
    Колонка в таблице по-прежнему называется page_id — теперь это id владельца. */
function owner_sections(string $ownerType, int $ownerId): array
{
    $cache = &page_sections_cache();
    $key = $ownerType . ':' . $ownerId;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $out = [];
    foreach (db_all('SELECT * FROM sections WHERE owner_type = ? AND page_id = ? ORDER BY position, id', [$ownerType, $ownerId]) as $s) {
        $d = json_decode((string)$s['data'], true);
        $s['data'] = array_merge(section_defaults((string)$s['type']), is_array($d) ? $d : []);
        $out[] = $s;
    }
    return $cache[$key] = $out;
}

function page_sections(int $pageId): array
{
    return owner_sections('page', $pageId);
}

/** Секции заметки блога (1.9.54: заметка собирается из блоков, как страница). */
function post_content_sections(int $postId): array
{
    return owner_sections('post', $postId);
}

/** Пакетное наполнение кэша секций (списки заметок: анонс и «время чтения» у каждой) —
    один запрос на весь список вместо запроса на заметку. */
function sections_prime(string $ownerType, array $ownerIds): void
{
    /* owner_type — не пользовательский ввод, а наша константа; страховка на будущее переиспользование */
    if (!in_array($ownerType, ['page', 'post'], true)) {
        throw new InvalidArgumentException('sections_prime: недопустимый owner_type');
    }
    $cache = &page_sections_cache();
    $ids = [];
    foreach ($ownerIds as $id) {
        $id = (int)$id;
        if ($id > 0 && !array_key_exists($ownerType . ':' . $id, $cache)) {
            $ids[$id] = true;
        }
    }
    if (!$ids) {
        return;
    }
    $ids = array_keys($ids);
    foreach ($ids as $id) {
        $cache[$ownerType . ':' . $id] = []; // у владельца может не быть секций — кэшируем и пустоту
    }
    foreach (db_all_in("SELECT * FROM sections WHERE owner_type = '" . $ownerType . "' AND page_id IN ({IN}) ORDER BY page_id, position, id", $ids) as $s) {
        $d = json_decode((string)$s['data'], true);
        $s['data'] = array_merge(section_defaults((string)$s['type']), is_array($d) ? $d : []);
        $cache[$ownerType . ':' . (int)$s['page_id']][] = $s;
    }
}

function &gallery_cache(): array
{
    /* в пределах одного запроса галерея страницы дёргается дважды (рендер секции и обложка) —
       повторный JOIN по тем же данным не нужен */
    static $cache = [];
    return $cache;
}

function gallery_cache_flush(): void
{
    $cache = &gallery_cache();
    $cache = [];
}

function gallery_images(string $ownerType, int $ownerId): array
{
    $cache = &gallery_cache();
    $key = $ownerType . ':' . $ownerId;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = db_all(
            'SELECT gi.id AS item_id, i.* FROM gallery_items gi
             JOIN images i ON i.id = gi.image_id
             WHERE gi.owner_type = ? AND gi.owner_id = ?
             ORDER BY gi.position, gi.id',
            [$ownerType, $ownerId]
        );
    }
    return $cache[$key];
}

function gallery_replace(string $ownerType, int $ownerId, array $imageIds): void
{
    $want = [];
    foreach ($imageIds as $iid) {
        $iid = (int)$iid;
        /* Дубликаты отбрасываем сразу (аудит 1.9.26): раньше array_unique стоял только
           в проверке существования, и POST вида «12,12,15» вставлял фото 12 в галерею дважды. */
        if ($iid > 0 && !in_array($iid, $want, true)) {
            $want[] = $iid;
        }
    }
    /* Одна транзакция: раньше сбой посреди цикла оставлял галерею пустой или обрезанной (DELETE уже выполнен). */
    db_tx(function () use ($ownerType, $ownerId, $want): void {
        db_run('DELETE FROM gallery_items WHERE owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]);
        if (!$want) {
            return;
        }
        /* существование фото проверяем одним запросом (раньше — отдельный SELECT на каждое фото) */
        $exist = [];
        foreach (db_all_in('SELECT id FROM images WHERE id IN ({IN})', array_values(array_unique($want))) as $r) {
            $exist[(int)$r['id']] = true;
        }
        $st = db()->prepare('INSERT INTO gallery_items (owner_type, owner_id, image_id, position) VALUES (?,?,?,?)');
        $pos = 0;
        foreach ($want as $iid) {
            if (isset($exist[$iid])) {
                $st->execute([$ownerType, $ownerId, $iid, $pos++]);
            }
        }
    });
    gallery_cache_flush();
}

/** Обложка страницы: выбранная вручную, иначе первое фото. */
function page_cover(array $p): ?array
{
    $covers = pages_covers([$p]);
    return $covers[(int)$p['id']] ?? null;
}

/** Обложки списка: загружаются только выбранные и первые кадры, а не все фото альбомов. */
function pages_covers(array $pages): array
{
    $result = [];
    foreach ($pages as $p) {
        $result[(int)$p['id']] = null;
    }
    if (!$pages) {
        return $result;
    }
    $images = [];
    $coverIds = array_values(array_unique(array_filter(array_map(static fn($p): int => (int)($p['cover_image_id'] ?? 0), $pages))));
    if ($coverIds) {
        foreach (db_all_in('SELECT * FROM images WHERE id IN ({IN})', $coverIds) as $img) {
            $images[(int)$img['id']] = $img;
        }
    }
    $rest = [];
    foreach ($pages as $p) {
        $pid = (int)$p['id'];
        $cid = (int)($p['cover_image_id'] ?? 0);
        if ($cid > 0 && isset($images[$cid])) {
            $result[$pid] = $images[$cid];
        } else {
            $rest[] = $pid;
        }
    }
    if (!$rest) {
        return $result;
    }
    $links = db_all_in("SELECT gi.owner_id, gi.image_id FROM gallery_items gi
        WHERE gi.owner_type = 'page' AND gi.owner_id IN ({IN})
          AND gi.id = (SELECT gi2.id FROM gallery_items gi2
              WHERE gi2.owner_type = 'page' AND gi2.owner_id = gi.owner_id
              ORDER BY gi2.position, gi2.id LIMIT 1)", $rest);
    $need = array_values(array_unique(array_map(static fn($r): int => (int)$r['image_id'], $links)));
    if ($need) {
        foreach (db_all_in('SELECT * FROM images WHERE id IN ({IN})', $need) as $img) {
            $images[(int)$img['id']] = $img;
        }
    }
    $left = array_fill_keys($rest, true);
    foreach ($links as $link) {
        $pid = (int)$link['owner_id'];
        $iid = (int)$link['image_id'];
        if (isset($left[$pid], $images[$iid])) {
            $result[$pid] = $images[$iid];
            unset($left[$pid]);
        }
    }
    $rest = array_keys($left);
    if (!$rest) {
        return $result;
    }
    $links = db_all_in("SELECT s.page_id, gi.image_id FROM sections s
        JOIN gallery_items gi ON gi.owner_type = 'section' AND gi.owner_id = s.id
        WHERE s.owner_type = 'page' AND s.page_id IN ({IN}) AND s.type = 'gallery'
          AND gi.id = (SELECT gi2.id FROM gallery_items gi2
              WHERE gi2.owner_type = 'section' AND gi2.owner_id = s.id
              ORDER BY gi2.position, gi2.id LIMIT 1)
        ORDER BY s.position, s.id", $rest);
    $need = array_values(array_unique(array_diff(array_map(static fn($r): int => (int)$r['image_id'], $links), array_keys($images))));
    if ($need) {
        foreach (db_all_in('SELECT * FROM images WHERE id IN ({IN})', $need) as $img) {
            $images[(int)$img['id']] = $img;
        }
    }
    foreach ($links as $link) {
        $pid = (int)$link['page_id'];
        $iid = (int)$link['image_id'];
        if (isset($left[$pid], $images[$iid])) {
            $result[$pid] = $images[$iid];
            unset($left[$pid]);
        }
    }
    return $result;
}

/** Счётчики фото в галереях страниц одним пакетным запросом: [page_id => кол-во]. Для бейджа «N фото» на карточках. */
function pages_photo_counts(array $pageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds))));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (db_all_in('SELECT owner_id, COUNT(*) AS c FROM gallery_items WHERE owner_type = \'page\' AND owner_id IN ({IN}) GROUP BY owner_id', $ids) as $r) {
        $out[(int)$r['owner_id']] = (int)$r['c'];
    }
    return $out;
}

function child_pages(int $parentId, bool $onlyPublished = true): array
{
    $sql = 'SELECT * FROM pages WHERE parent_id = ?' . ($onlyPublished ? ' AND is_published = 1' : '') . ' ORDER BY position, id';
    return db_all($sql, [$parentId]);
}

/** Альбомы с количеством фото — для фильтра медиатеки и окна выбора фото (1.9.56).
    Один проход GROUP BY, без запроса на альбом. */
function albums_with_counts(): array
{
    return db_all("SELECT p.id, p.title, COUNT(gi.id) AS photos FROM pages p
        LEFT JOIN gallery_items gi ON gi.owner_type = 'page' AND gi.owner_id = p.id
        WHERE p.type = 'album' GROUP BY p.id ORDER BY p.title COLLATE NOCASE, p.id");
}

function listing_pages(array $data, array $currentPage): array
{
    if (($data['mode'] ?? 'pages') === 'children') {
        $pid = (int)($data['parent_id'] ?? 0);
        return child_pages($pid > 0 ? $pid : (int)$currentPage['id'], true);
    }
    /* array_unique: вручную сформированный/повреждённый POST мог внести одну страницу
       в список несколько раз — она и выводилась бы несколько раз (аудит 1.9.26) */
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['page_ids'] ?? [])))));
    if (!$ids) {
        return [];
    }
    $by = [];
    foreach (db_all_in('SELECT * FROM pages WHERE id IN ({IN}) AND is_published = 1', $ids) as $r) {
        $by[(int)$r['id']] = $r;
    }
    $out = array_values($by);
    /* Порядок — живой, по списку «Страницы» (position): подсказка в редакторе обещает именно
       его, а раньше карточки шли в порядке, застывшем в page_ids на момент сохранения секции —
       после перестановки страниц стрелками секцию приходилось пересохранять (1.9.52) */
    usort($out, static fn($a, $b) => ((int)$a['position'] <=> (int)$b['position']) ?: ((int)$a['id'] <=> (int)$b['id']));
    return $out;
}

/** Меню: два уровня. Скрытые/черновые страницы и пустые рубрики не показываются. */
function menu_tree(string $currentPath): array
{
    $items = db_all(
        'SELECT m.*, p.slug AS p_slug, p.is_home AS p_home, p.is_published AS p_pub
         FROM menu_items m LEFT JOIN pages p ON p.id = m.page_id
         ORDER BY m.position, m.id'
    );
    $nodes = [];
    foreach ($items as $it) {
        $href = null;
        $hidden = false;
        if (!empty($it['page_id'])) {
            if (!(int)$it['p_pub']) {
                $hidden = true;
            } else {
                $href = (int)$it['p_home'] ? '/' : '/' . $it['p_slug'];
            }
        } elseif (trim((string)$it['url']) !== '') {
            /* javascript:/data: в адресе пункта (старые данные, правка БД) превращается в «нет ссылки» */
            $href = normalize_url((string)$it['url']);
            if ($href === '') {
                $href = null;
            }
        }
        $nodes[(int)$it['id']] = [
            'id' => (int)$it['id'],
            'parent' => (int)($it['parent_id'] ?? 0),
            'title' => (string)$it['title'],
            'href' => $href,
            'new_tab' => (bool)$it['new_tab'],
            'hidden' => $hidden,
            'exact' => $href !== null && $href === $currentPath,
            /* раздел подсвечивается и на вложенных адресах: пункт «/blog» активен на /blog/любая-заметка */
            'active' => $href !== null && ($href === $currentPath
                || ($href[0] === '/' && $href !== '/' && str_starts_with($currentPath, $href . '/'))),
            'children' => [],
        ];
    }
    $byParent = [];
    foreach ($nodes as $n) {
        if (!$n['hidden']) {
            $byParent[$n['parent']][] = $n;
        }
    }
    $result = [];
    foreach ($byParent[0] ?? [] as $n) {
        $n['children'] = $byParent[$n['id']] ?? [];
        if ($n['href'] === null && !$n['children']) {
            continue;
        }
        $open = $n['active'];
        foreach ($n['children'] as $c) {
            if ($c['active']) {
                $open = true;
            }
        }
        $n['open'] = $open;
        $result[] = $n;
    }
    return $result;
}

/* reserved_slugs() переехала в helpers.php (1.9.35): там она доступна и раннему роутингу,
   и получила мемоизацию по JSON разделов (ревизия 1.9.34, п.22). */

/* ---------- редиректы ---------- */

/** Путь для таблицы редиректов: декодированный, ведущий «/», без хвостового «/» (кроме корня). */
function redirect_norm_path(string $p): string
{
    /* админ может вставить адрес как из браузера (кириллица в %-кодах), а request_path() уже декодирован —
       без rawurldecode такой редирект никогда бы не сработал */
    $p = '/' . ltrim(trim(rawurldecode($p)), '/');
    return $p === '/' ? '/' : rtrim($p, '/');
}

/**
 * Добавляет/обновляет редирект. Возвращает текст ошибки или null при успехе.
 * Отклоняет самоссылки и петли (A→B, B→A), не принимает «//host» и «/\host»,
 * а уже существующие цепочки «X → from» сразу перенаправляет на новый адрес.
 */
function redirect_add(string $from, string $to, int $code = 301): ?string
{
    $from = redirect_norm_path($from);
    $to = trim($to);
    if ($from === '/') {
        return 'С главной страницы редирект настроить нельзя.';
    }
    if ($to === '' || !preg_match('~^(https?://|/(?![/\\\\]))~i', $to)) {
        return 'Новый адрес: путь вида /new-page или полный адрес https://…';
    }
    if ($to[0] === '/') {
        $to = redirect_norm_path($to);
    }
    if ($from === $to) {
        return 'Старый и новый адрес совпадают.';
    }
    $cur = $to;
    $seen = [];
    for ($i = 0; $i < 200; $i++) {
        if ($cur === $from || isset($seen[$cur])) {
            return 'Такой редирект создал бы петлю: цепочка редиректов приводит обратно на старый адрес.';
        }
        $seen[$cur] = true;
        $next = db_val('SELECT to_url FROM redirects WHERE from_path = ?', [$cur]);
        if ($next === null) {
            break;
        }
        $cur = (string)$next;
    }
    if ($i >= 200) {
        return 'Такой редирект создал бы петлю: цепочка редиректов приводит обратно на старый адрес.';
    }
    db_tx(function () use ($from, $to, $code): void {
        db_run('UPDATE redirects SET to_url = ? WHERE to_url = ?', [$to, $from]);
        db_run('INSERT OR REPLACE INTO redirects (from_path, to_url, code) VALUES (?,?,?)', [$from, $to, $code === 302 ? 302 : 301]);
    });
    return null;
}

/** Убирает редирект С этого адреса: адрес снова занят живой страницей, редирект с него только мешал бы. */
function redirect_clear_from(string $path): void
{
    db_run('DELETE FROM redirects WHERE from_path = ?', [redirect_norm_path($path)]);
}

/** Открывается ли по этому адресу страница, раздел или заметка (тогда редирект с него не сработает). */
function path_has_content(string $path): bool
{
    $p = trim(rawurldecode($path), '/');
    if ($p === '') {
        return true; // главная
    }
    $seg = explode('/', $p);
    if (count($seg) > 2) {
        return false;
    }
    $sec = section_get($seg[0]);
    if ($sec !== null) {
        if (count($seg) === 1) {
            return true;
        }
        $post = post_by_slug($seg[1]);
        return $post !== null && $post['section'] === $sec['slug'];
    }
    return count($seg) === 1 && page_by_slug($seg[0]) !== null;
}

/** Является ли $maybeAncestor предком страницы $pageId (по цепочке parent_id): так не создаём циклы A→B→C→A. */
function page_is_ancestor(int $maybeAncestor, int $pageId): bool
{
    $cur = $pageId;
    for ($i = 0; $i < 50; $i++) {
        $parent = (int)db_val('SELECT parent_id FROM pages WHERE id = ?', [$cur]);
        if ($parent <= 0) {
            return false;
        }
        if ($parent === $maybeAncestor) {
            return true;
        }
        $cur = $parent;
    }
    return true; // подозрительно глубокая цепочка — считаем циклом
}

function valid_slug(string $s): bool
{
    /* модификатор D: без него «$» допускает перевод строки в конце — слаг «page\n» проходил
       проверку и попадал в базу (ревизия 1.9.34, п.21) */
    return (bool)preg_match('/^[a-z0-9][a-z0-9_\-]{0,99}$/D', $s) && !in_array($s, reserved_slugs(), true);
}

/* ---------- видеотека (1.9.48) ---------- */

function video_get(int $id): ?array
{
    return $id > 0 ? db_one('SELECT * FROM videos WHERE id = ?', [$id]) : null;
}

/** Публичный адрес файла ролика: media/video/<id>-<hex>.mp4 (Apache отдаёт как статику). */
function video_url(array $v): string
{
    return '/media/video/' . basename((string)$v['file']);
}

/** Длительность в ISO 8601 («PT1H2M3S») для разметки VideoObject; пусто, если не задана. */
function video_duration_iso($seconds): string
{
    $s = max(0, (int)$seconds);
    if ($s <= 0) {
        return '';
    }
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $s %= 60;
    return 'PT' . ($h > 0 ? $h . 'H' : '') . ($m > 0 ? $m . 'M' : '') . ($s > 0 || ($h === 0 && $m === 0) ? $s . 'S' : '');
}

function unique_slug(string $base, ?int $exceptId = null): string
{
    $base = slugify($base, 90);
    if ($base === '') {
        $base = 'page';
    }
    $slug = $base;
    $i = 2;
    $reserved = reserved_slugs(); // список строится заново при каждом вызове — выносим из цикла
    while (in_array($slug, $reserved, true)
        || db_val('SELECT id FROM pages WHERE slug = ? AND id <> ?', [$slug, (int)$exceptId])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}
