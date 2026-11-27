<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

if (!defined('ROOT')) {
    http_response_code(404);
    exit;
}

function page_move(int $id, int $dir): void
{
    $p = page_get($id);
    if (!$p) {
        return;
    }
    $sibs = $p['parent_id'] === null
        ? db_all('SELECT id FROM pages WHERE parent_id IS NULL ORDER BY position, id')
        : db_all('SELECT id FROM pages WHERE parent_id = ? ORDER BY position, id', [$p['parent_id']]);
    $ids = array_map('intval', array_column($sibs, 'id'));
    $i = array_search($id, $ids, true);
    $j = $i + $dir;
    if ($i === false || $j < 0 || $j >= count($ids)) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    db_tx(static function () use ($ids): void {
        foreach ($ids as $pos => $pid) {
            db_run('UPDATE pages SET position = ? WHERE id = ?', [$pos + 1, $pid]);
        }



        site_touch();
    });
}

function page_delete(int $id): void
{
    $p = page_get($id);
    if (!$p || (int)$p['is_home']) {
        return;
    }
    db_tx(static function () use ($id, $p): void {
        foreach (db_all("SELECT id FROM sections WHERE owner_type = 'page' AND page_id = ?", [$id]) as $s) {
            db_run("DELETE FROM gallery_items WHERE owner_type = 'section' AND owner_id = ?", [$s['id']]);
        }
        db_run("DELETE FROM sections WHERE owner_type = 'page' AND page_id = ?", [$id]);
        db_run("DELETE FROM gallery_items WHERE owner_type = 'page' AND owner_id = ?", [$id]);
        db_run('UPDATE menu_items SET parent_id = NULL WHERE parent_id IN (SELECT id FROM menu_items WHERE page_id = ?)', [$id]);
        db_run('DELETE FROM menu_items WHERE page_id = ?', [$id]);
        db_run('UPDATE pages SET parent_id = NULL WHERE parent_id = ?', [$id]);
        db_run('DELETE FROM redirects WHERE from_path = ? OR to_url = ?', ['/' . $p['slug'], '/' . $p['slug']]);
        db_run('DELETE FROM pages WHERE id = ?', [$id]);
    });
    site_touch();
    page_sections_cache_flush($id);
}

function admin_pages(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $aRaw = $_POST['action'] ?? '';
        $a = is_string($aRaw) ? $aRaw : '';
        $id = (int)($_POST['id'] ?? 0);
        if ($a === 'move') {
            page_move($id, ($_POST['dir'] ?? '') === 'up' ? -1 : 1);
        } elseif ($a === 'delete') {
            page_delete($id);
            flash('Страница удалена. Фотографии остались в медиатеке.');
        }
        redirect(a_url('pages'));
    }

    $all = db_all(
        "SELECT p.*, (SELECT COUNT(*) FROM gallery_items g WHERE g.owner_type = 'page' AND g.owner_id = p.id) AS n_photos
         FROM pages p ORDER BY position, id"
    );

    $extra = [];
    foreach (db_all("SELECT page_id, COUNT(*) c FROM sections WHERE owner_type = 'page' GROUP BY page_id") as $r) {
        $extra[(int)$r['page_id']]['sections'] = (int)$r['c'];
    }
    foreach (db_all('SELECT page_id, COUNT(*) c FROM menu_items GROUP BY page_id') as $r) {
        $extra[(int)$r['page_id']]['menu'] = (int)$r['c'];
    }
    $ids = [];
    foreach ($all as $p) {
        $ids[(int)$p['id']] = true;
    }
    $by = [];
    foreach ($all as $p) {
        $par = (!empty($p['parent_id']) && isset($ids[(int)$p['parent_id']])) ? (int)$p['parent_id'] : 0;
        $by[$par][] = $p;
    }

    admin_header('Страницы', 'pages');
    ?>
<div class="bar"><h1>Страницы</h1><span class="grow"></span>
  <a class="btn primary" href="<?= e(a_url('page_new', ['type' => 'album'])) ?>">+ Альбом (галерея)</a>
  <a class="btn" href="<?= e(a_url('page_new', ['type' => 'page'])) ?>">+ Страница</a></div>
<p class="muted">Альбом — страница с фотографиями (например, одна свадьба). Обычная страница собирается из разделов: текст, галерея, кнопки, карточки.</p>
<p class="filterline"><input type="search" data-filter="#pagesTbl tbody tr" placeholder="Быстрый фильтр по названию или адресу…"></p>
<table class="tbl" id="pagesTbl">
<thead><tr><th>Название</th><th>Тип</th><th>Адрес</th><th>SEO</th><th>Статус</th><th></th></tr></thead>
<tbody>
    <?php pages_rows($by, 0, 0, $extra); ?>
</tbody></table>
    <?php
    admin_footer();
}

function pages_rows(array $by, int $parent, int $depth, array $extra = []): void
{


    if ($depth > 32) {
        app_log_error('pages_rows: подозрение на цикл parent_id (глубина > 32), ветка обрезана');
        return;
    }
    foreach ($by[$parent] ?? [] as $p) {
        $url = page_url($p);
        $seoOk = trim((string)$p['seo_description']) !== '' || trim((string)$p['seo_title']) !== '';

        $pid = (int)$p['id'];
        $consequences = [];
        if ((int)$p['n_photos'] > 0) {
            $consequences[] = (int)$p['n_photos'] . ' фото в галерее (файлы останутся в медиатеке)';
        }
        if (!empty($extra[$pid]['sections'])) {
            $consequences[] = 'разделов: ' . $extra[$pid]['sections'];
        }
        $nChildren = count($by[$pid] ?? []);
        if ($nChildren) {
            $consequences[] = 'дочерних страниц: ' . $nChildren . ' (станут верхнеуровневыми)';
        }
        if (!empty($extra[$pid]['menu'])) {
            $consequences[] = 'пунктов меню: ' . $extra[$pid]['menu'] . ' (исчезнут из меню)';
        }
        $confirm = 'Удалить страницу «' . $p['title'] . '»? Это нельзя отменить.'
            . ($consequences ? "\n\nВместе с ней: " . implode(', ', $consequences) . '.'
                . "\nВнешние ссылки на неё дадут 404 — после удаления добавьте редирект в разделе «Редиректы»." : '');
        ?>
<tr>
  <td style="padding-left:<?= 10 + $depth * 22 ?>px"><?= $depth ? '└ ' : '' ?><a href="<?= e(a_url('page_edit', ['id' => $p['id']])) ?>"><strong><?= e($p['title']) ?></strong></a>
    <?php if ($p['type'] === 'album'): ?><span class="muted"> · <?= (int)$p['n_photos'] ?> фото</span><?php endif; ?></td>
  <td><?= $p['type'] === 'album' ? 'Альбом' : ((int)$p['is_home'] ? 'Главная' : 'Страница') ?></td>
  <td><a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a></td>
  <td><?= $seoOk ? '<span class="tag ok">заполнено</span>' : '<span class="tag warn">нет описания</span>' ?><?= (int)$p['noindex'] ? ' <span class="tag">noindex</span>' : '' ?><?= (int)($p['is_service'] ?? 0) ? ' <span class="tag ok">услуга' . e(trim((string)($p['service_price'] ?? '')) !== '' ? ' · от ' . number_format((float)$p['service_price'], 0, ',', ' ') . ' ' . (string)$p['service_currency'] : '') . '</span>' : '' ?></td>
  <td><?= (int)$p['is_published'] ? '<span class="tag ok">опубликована</span>' : '<span class="tag warn">черновик</span>' ?></td>
  <td class="acts">
    <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <button class="ico" name="dir" value="up" title="Выше">↑</button><button class="ico" name="dir" value="down" title="Ниже">↓</button></form>
    <a class="btn sm" href="<?= e(a_url('page_edit', ['id' => $p['id']])) ?>">Править</a>
    <?php if (!(int)$p['is_home']): ?>
    <form method="post" class="inline" data-confirm="<?= e($confirm) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $pid ?>"><button class="btn sm danger">Удалить</button></form>
    <?php endif; ?>
  </td>
</tr>
        <?php
        pages_rows($by, $pid, $depth + 1, $extra);
    }
}

function admin_page_new(): void
{
    $type = ($_GET['type'] ?? $_POST['type'] ?? 'page') === 'album' ? 'album' : 'page';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $title = post_str('title', 200);
        if ($title === '') {
            flash('Введите название.', 'err');
            redirect(a_url('page_new', ['type' => $type]));
        }
        $slugIn = slug_normalize(post_str('slug'));
        $slugOk = $slugIn !== '' && valid_slug($slugIn) && !db_val('SELECT id FROM pages WHERE slug = ?', [$slugIn]);
        $slug = $slugOk ? $slugIn : unique_slug($title);
        if ($slugIn !== '' && !$slugOk) {

            flash('Адрес «' . $slugIn . '» недопустим или уже занят — придумали другой: «' . $slug . '».', 'err');
        }
        $parent = (int)($_POST['parent_id'] ?? 0);
        $pp = $parent > 0 ? page_get($parent) : null;
        $parent = ($pp && $pp['type'] === 'page') ? $parent : null;
        $pos = (int)db_val('SELECT COALESCE(MAX(position), 0) + 1 FROM pages') ;
        $t = now();

        $showTitle = $type === 'album' ? 1 : 0;
        try {
            $id = db_insert(
                'INSERT INTO pages (type, title, slug, parent_id, is_published, position, show_title, created_at, updated_at) VALUES (?,?,?,?,0,?,?,?,?)',
                [$type, $title, $slug, $parent, $pos, $showTitle, $t, $t]
            );
        } catch (PDOException $e) {

            if (stripos($e->getMessage(), 'unique') === false) {
                throw $e;
            }
            $slug = unique_slug($title);
            $id = db_insert(
                'INSERT INTO pages (type, title, slug, parent_id, is_published, position, show_title, created_at, updated_at) VALUES (?,?,?,?,0,?,?,?,?)',
                [$type, $title, $slug, $parent, $pos, $showTitle, $t, $t]
            );
        }
        redirect_clear_from('/' . $slug);
        flash('Создано как черновик. Добавьте содержимое и отметьте «Опубликована».');
        redirect(a_url('page_edit', ['id' => $id]));
    }
    admin_header($type === 'album' ? 'Новый альбом' : 'Новая страница', 'pages');
    ?>
<h1><?= $type === 'album' ? 'Новый альбом' : 'Новая страница' ?></h1>
<form method="post" class="card narrow">
  <?= csrf_field() ?><input type="hidden" name="type" value="<?= e($type) ?>">
  <?= f_input('title', 'Название', '', ['maxlength' => 150]) ?>
  <?= f_input('slug', 'Адрес (латиницей)', '', ['help' => 'Оставьте пустым — создастся из названия. Будет доступно как /адрес']) ?>
  <?= f_select('parent_id', 'Раздел (родитель)', [0 => '— нет —'] + page_options(null, true), (int)($_GET['parent'] ?? 0), ['help' => 'Альбомы в разделе показываются друг другу как «Другие работы».']) ?>
  <button class="btn primary">Создать</button>
</form>
    <?php
    admin_footer();
}



function section_collect(string $type, array $f): array
{
    $sp = in_array($f['space'] ?? '', ['none', 'sm', 'md', 'lg'], true) ? $f['space'] : 'md';
    $d = section_defaults($type);
    $d['space'] = $sp;
    switch ($type) {
        case 'text':
            $d['html'] = sanitize_html((string)($f['html'] ?? ''));
            $d['width'] = in_array($f['width'] ?? '', ['narrow', 'medium', 'wide'], true) ? $f['width'] : 'narrow';
            $d['align'] = ($f['align'] ?? '') === 'center' ? 'center' : 'left';
            break;
        case 'gallery':
            $d['layout'] = in_array($f['layout'] ?? '', ['masonry', 'grid', 'lineup', 'strip', 'slides', 'contactsheet'], true) ? $f['layout'] : 'masonry';
            $d['columns'] = max(0, min(6, (int)($f['columns'] ?? 0)));
            $d['lightbox'] = in_array($f['lightbox'] ?? '', ['', '1', '0'], true) ? (string)($f['lightbox'] ?? '') : '';


            $d['autoplay'] = ($f['autoplay'] ?? '') === '1' ? '1' : '0';
            $d['autoplay_dir'] = ($f['autoplay_dir'] ?? '') === 'back' ? 'back' : 'fwd';
            $d['autoplay_speed'] = in_array((string)($f['autoplay_speed'] ?? ''), ['3', '4', '5', '6', '8', '10', '15'], true) ? (string)$f['autoplay_speed'] : '5';

            $d['fx'] = in_array($f['fx'] ?? '', ['fade', 'cut', 'slide'], true) ? $f['fx'] : 'fade';
            $d['speed'] = in_array((string)($f['speed'] ?? ''), ['3', '4', '5', '6', '8', '10', '15'], true) ? (string)$f['speed'] : '5';
            $d['height'] = in_array($f['height'] ?? '', ['full', 'tall', 'half'], true) ? $f['height'] : 'full';
            break;
        case 'photo':
            $d['image_id'] = (int)($f['image_id'] ?? 0);
            $d['size'] = array_key_exists((string)($f['size'] ?? ''), photo_size_presets()) ? (string)$f['size'] : 'wide';
            $d['lightbox'] = in_array($f['lightbox'] ?? '', ['', '1', '0'], true) ? (string)($f['lightbox'] ?? '') : '';
            $d['caption'] = clip((string)($f['caption'] ?? ''), 300);

            $d['link'] = clip(normalize_url((string)($f['link'] ?? '')), 500);
            $d['link_new_tab'] = !empty($f['link_new_tab']);
            break;
        case 'buttons':
            $items = [];
            foreach ((array)($f['btn'] ?? []) as $b) {
                if (!is_array($b) || trim((string)($b['label'] ?? '')) === '') {
                    continue;
                }
                $icon = (string)($b['icon'] ?? '');
                $items[] = [
                    'label' => clip((string)$b['label'], 80),

                    'url' => clip(normalize_url((string)($b['url'] ?? '')), 500),
                    'icon' => array_key_exists($icon, button_icons()) ? $icon : '',
                    'newtab' => !empty($b['newtab']),
                ];
            }
            $d['items'] = $items;
            $d['style'] = ($f['style'] ?? '') === 'solid' ? 'solid' : 'outline';
            $d['align'] = ($f['align'] ?? '') === 'left' ? 'left' : 'center';
            break;
        case 'listing':
            $d['mode'] = ($f['mode'] ?? '') === 'children' ? 'children' : 'pages';
            $d['parent_id'] = (int)($f['parent_id'] ?? 0);
            $d['page_ids'] = array_values(array_unique(array_filter(array_map('intval', (array)($f['page_ids'] ?? [])))));
            $d['format'] = in_array($f['format'] ?? '', ['portrait', 'landscape', 'square', 'original'], true) ? $f['format'] : 'portrait';
            $d['columns'] = max(1, min(5, (int)($f['columns'] ?? 3)));
            $d['kicker'] = clip((string)($f['kicker'] ?? ''), 80);
            $d['heading'] = clip((string)($f['heading'] ?? ''), 150);
            break;
        case 'html':
            $d['code'] = (string)($f['code'] ?? '');
            break;
        case 'contact':
            $d['kicker'] = clip((string)($f['kicker'] ?? ''), 80);
            $d['heading'] = clip((string)($f['heading'] ?? ''), 150);
            $d['intro'] = clip((string)($f['intro'] ?? ''), 300);
            $d['button'] = clip((string)($f['button'] ?? ''), 40);
            break;
        case 'faq':
            $d['kicker'] = clip((string)($f['kicker'] ?? ''), 80);
            $d['heading'] = clip((string)($f['heading'] ?? ''), 150);
            $items = [];
            foreach ((array)($f['qa'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $q = clip((string)($it['q'] ?? ''), 300);
                $a = clip((string)($it['a'] ?? ''), 2000);
                if ($q === '' && $a === '') {
                    continue;
                }
                $items[] = ['q' => $q, 'a' => $a];
                if (count($items) >= 50) {
                    break;
                }
            }
            $d['items'] = $items;
            $d['schema'] = !empty($f['schema']) ? '1' : '0';
            break;
        case 'video':

            $vid = (int)($f['video_id'] ?? 0);
            $d['video_id'] = $vid > 0 && video_get($vid) ? $vid : 0;
            break;
        case 'pricing':
            $d['kicker'] = clip((string)($f['kicker'] ?? ''), 80);
            $d['heading'] = clip((string)($f['heading'] ?? ''), 150);
            $cur = (string)($f['currency'] ?? 'RUB');
            $d['currency'] = array_key_exists($cur, service_currencies()) ? $cur : 'RUB';
            $items = [];
            foreach ((array)($f['price'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $name = clip((string)($it['name'] ?? ''), 120);
                $desc = clip((string)($it['desc'] ?? ''), 600);


                $from = clip((string)preg_replace('~\D~', '', (string)($it['price_from'] ?? '')), 9);
                $to = clip((string)preg_replace('~\D~', '', (string)($it['price_to'] ?? '')), 9);


                $dur = clip((string)($it['duration'] ?? ''), 60);
                $url = clip(normalize_url((string)($it['url'] ?? '')), 500);

                $iid = (int)($it['image_id'] ?? 0);
                $iid = $iid > 0 && image_get($iid) ? $iid : 0;
                if ($name === '' && $desc === '' && $from === '' && $iid === 0) {
                    continue;
                }
                if ($to !== '' && $from !== '' && (int)$to < (int)$from) {
                    $to = $from;
                }
                $items[] = ['name' => $name, 'desc' => $desc, 'price_from' => $from, 'price_to' => $to, 'duration' => $dur, 'url' => $url, 'image_id' => $iid];
                if (count($items) >= 12) {
                    break;
                }
            }
            $d['items'] = $items;
            break;
    }
    return $d;
}

function page_save(array $page): bool
{
    $id = (int)$page['id'];
    $isHome = (int)$page['is_home'] === 1;
    $isAlbum = $page['type'] === 'album';



    if (!isset($_POST['form_end'])) {
        flash('Форма пришла не полностью: хостинг обрезал её по лимиту max_input_vars. '
            . 'Ничего не сохранено — увеличьте max_input_vars в настройках PHP (например, до 5000) и повторите.', 'err');
        return false;
    }







    $formUpd = (int)($_POST['form_updated_at'] ?? 0);
    if ($formUpd > 0 && (int)$page['updated_at'] !== $formUpd) {
        throw new RuntimeException('page_edit_conflict');
    }

    $title = post_str('title', 200);
    if ($title === '') {
        $title = (string)$page['title'];
        flash('Название не может быть пустым — оставлено прежнее.', 'err');
    }

    $slug = (string)$page['slug'];
    if (!$isHome) {
        $in = slug_normalize(post_str('slug'));
        if ($in === '') {
            $in = unique_slug($title, $id);
        }
        if ($in !== $slug) {
            if (!valid_slug($in)) {
                flash('Адрес «' . $in . '» недопустим (это служебное слово: админка, блог, медиа и т. п.). Оставлен прежний.', 'err');
            } elseif (db_val('SELECT id FROM pages WHERE slug = ? AND id <> ?', [$in, $id])) {
                flash('Адрес «' . $in . '» уже занят другой страницей. Оставлен прежний.', 'err');
            } else {
                $slug = $in;
            }
        }
    }

    $parent = (int)($_POST['parent_id'] ?? 0);
    $pp = $parent > 0 ? page_get($parent) : null;


    if ($isHome || !$pp || $pp['type'] !== 'page' || $parent === $id || page_is_ancestor($id, $parent)) {
        $parent = null;
    }
    $pub = $isHome ? 1 : (empty($_POST['is_published']) ? 0 : 1);

    $canonical = post_str('canonical', 500);
    $rejected = 0;





    if ($canonical !== '') {
        $canonErr = '';
        if (preg_match('~^https?://~i', $canonical)) {
            $ownHost = parse_url(base_url(), PHP_URL_HOST);
            $canonHost = parse_url($canonical, PHP_URL_HOST);
            if (!$canonHost) {
                $canonErr = 'Canonical должен содержать корректный домен этого сайта. Поле очищено.';
            } elseif ($ownHost && strcasecmp((string)$ownHost, (string)$canonHost) !== 0) {
                $canonErr = 'Canonical ведёт на другой домен (' . $canonHost . '), а не на этот сайт (' . $ownHost . '). Поле очищено.';
            }
        } elseif (!preg_match('~^/(?![/\\\\])~', $canonical)) {
            $canonErr = 'Canonical должен быть путём вида /page или полным адресом этого сайта (https://…). Поле очищено.';
        }
        if ($canonErr !== '') {
            $_SESSION['old']['canonical'] = $canonical;
            $canonical = '';
            $rejected++;
            flash($canonErr, 'err');
        }
    }


    $schemaType = post_str('schema_type');
    if (!array_key_exists($schemaType, page_schema_types())) {
        $schemaType = '';
    }


    $jsonldExtra = post_str('jsonld_extra');
    if ($jsonldExtra !== '' && !jsonld_extra_blocks($jsonldExtra)) {
        $_SESSION['old']['jsonld_extra'] = $jsonldExtra;
        $jsonldExtra = (string)($page['jsonld_extra'] ?? '');
        $rejected++;
        flash('Свой JSON-LD не сохранён: это не JSON-объект. Проверьте запятые, кавычки и скобки.', 'err');
    }


    $isService = !$isHome && !empty($_POST['is_service']) ? 1 : 0;
    $serviceName = post_str('service_name', 200);
    $servicePrice = preg_replace('~\D~', '', post_str('service_price'));
    $serviceCurrency = post_str('service_currency');
    if (!array_key_exists($serviceCurrency, service_currencies())) {
        $serviceCurrency = 'RUB';
    }
    $serviceDesc = post_str('service_desc', 300);
    $serviceArea = post_str('service_area', 200);


    $albumLayout = post_str('album_layout');
    if (!in_array($albumLayout, ['', 'masonry', 'grid', 'lineup', 'contactsheet', 'hung'], true)) {
        $albumLayout = '';
    }

    $saveSql = 'UPDATE pages SET title=?, slug=?, parent_id=?, is_published=?, h1=?, show_title=?, short_text=?, cover_image_id=?,
         body_html=?, album_columns=?, album_layout=?, badge=?, stamp_text=?, shoot_note=?, album_related=?, seo_title=?, seo_description=?, og_image_id=?, noindex=?, canonical=?, schema_type=?, jsonld_extra=?,
         is_service=?, service_name=?, service_price=?, service_currency=?, service_desc=?, service_area=?, updated_at=? WHERE id=?';
    $saveParams = [
        $title, $slug, $parent, $pub,
        post_str('h1', 200),
        empty($_POST['show_title']) ? 0 : 1,
        post_str('short_text', 500),
        ((int)($_POST['cover_image_id'] ?? 0)) ?: null,
        $isAlbum ? sanitize_html(post_str('body_html')) : (string)$page['body_html'],
        max(0, min(6, (int)($_POST['album_columns'] ?? 0))),
        $albumLayout,
        post_str('badge', 60),
        post_str('stamp_text', 40),
        post_str('shoot_note', 80),
        empty($_POST['album_related']) ? 0 : 1,
        post_str('seo_title', 200),
        post_str('seo_description', 400),
        ((int)($_POST['og_image_id'] ?? 0)) ?: null,
        empty($_POST['noindex']) ? 0 : 1,
        $canonical,
        $schemaType,
        $jsonldExtra,
        $isService,
        $serviceName,
        $servicePrice,
        $serviceCurrency,
        $serviceDesc,
        $serviceArea,
        max(now(), (int)$page['updated_at'] + 1),
        $id,
    ];
    if ($formUpd > 0) {
        $saveSql .= ' AND updated_at=?';
        $saveParams[] = $formUpd;
    }
    try {
        $st = db_run($saveSql, $saveParams);
    } catch (PDOException $e) {



        if ($slug !== (string)$page['slug'] && stripos($e->getMessage(), 'unique') !== false) {
            flash('Адрес «' . $in . '» только что заняла другая страница. Оставлен прежний.', 'err');
            $slug = (string)$page['slug'];
            $saveParams[1] = $slug;
            $st = db_run($saveSql, $saveParams);
        } else {
            throw $e;
        }
    }
    if ($formUpd > 0 && $st->rowCount() === 0) {
        throw new RuntimeException('page_edit_conflict');
    }

    if ($slug !== $page['slug'] && !$isHome) {

        redirect_clear_from('/' . $slug);
        if ((int)$page['is_published']) {
            $rerr = redirect_add('/' . $page['slug'], '/' . $slug);
            if ($rerr === null) {
                flash('Адрес изменён. Со старого адреса /' . $page['slug'] . ' настроен редирект 301.');
            } else {
                flash('Адрес изменён, но редирект со старого адреса не создан: ' . $rerr, 'err');
            }
        } else {
            flash('Адрес изменён. Страница ещё не опубликована, поэтому редирект не нужен.');
        }
    }


    foreach (post_arr('sec') as $sid => $f) {
        $s = db_one("SELECT * FROM sections WHERE id = ? AND owner_type = 'page' AND page_id = ?", [(int)$sid, $id]);
        if ($s && is_array($f)) {
            db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db(section_collect((string)$s['type'], $f)), $s['id']]);
        }
    }
    page_sections_cache_flush($id);


    $allowed = $isAlbum ? ['page'] : [];
    foreach (db_all("SELECT id FROM sections WHERE owner_type = 'page' AND page_id = ? AND type = ?", [$id, 'gallery']) as $s) {
        $allowed[] = 's' . $s['id'];
    }


    $galPost = post_arr('gal');
    $galJsonRaw = $_POST['gal_json'] ?? [];
    $galJson = is_array($galJsonRaw) ? $galJsonRaw : [];
    foreach (post_arr('gal_keys') as $key) {
        $key = is_scalar($key) ? (string)$key : '';
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        if (isset($galJson[$key]) && is_string($galJson[$key])) {
            $ids = json_decode($galJson[$key], true);
            $ids = is_array($ids) ? $ids : [];
        } else {
            $ids = is_array($galPost[$key] ?? null) ? $galPost[$key] : [];
        }
        if ($key === 'page') {
            gallery_replace('page', $id, $ids);
        } else {
            gallery_replace('section', (int)substr($key, 1), $ids);
        }
    }


    $act = post_str('action') ?: 'save';
    if (strpos($act, ':') !== false) {
        [$verb, $arg] = explode(':', $act, 2);
        if ($verb === 'add_section' && isset(section_types()[$arg])) {
            $pos = (int)db_val("SELECT COALESCE(MAX(position), 0) + 1 FROM sections WHERE owner_type = 'page' AND page_id = ?", [$id]);
            $new = db_insert("INSERT INTO sections (owner_type, page_id, type, position, data) VALUES ('page',?,?,?,?)", [$id, $arg, $pos, json_db(section_defaults($arg))]);
            $_SESSION['scroll_to'] = 's' . $new;
        } elseif ($verb === 'del_section') {
            $sid = (int)$arg;
            if (db_val("SELECT id FROM sections WHERE id = ? AND owner_type = 'page' AND page_id = ?", [$sid, $id])) {
                db_run("DELETE FROM gallery_items WHERE owner_type = 'section' AND owner_id = ?", [$sid]);
                db_run('DELETE FROM sections WHERE id = ?', [$sid]);
            }
        } elseif ($verb === 'up_section' || $verb === 'down_section') {
            $ids = array_map('intval', array_column(db_all("SELECT id FROM sections WHERE owner_type = 'page' AND page_id = ? ORDER BY position, id", [$id]), 'id'));
            $i = array_search((int)$arg, $ids, true);
            $j = $i === false ? false : $i + ($verb === 'up_section' ? -1 : 1);
            if ($i !== false && $j >= 0 && $j < count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
                foreach ($ids as $pos => $sid) {
                    db_run('UPDATE sections SET position = ? WHERE id = ?', [$pos + 1, $sid]);
                }
                $_SESSION['scroll_to'] = 's' . (int)$arg;
            }
        }
        page_sections_cache_flush($id);
    }
    flash($rejected ? 'Сохранено, кроме отклонённых полей — исправьте их (значения возвращены в поля) и сохраните ещё раз.' : 'Сохранено.', $rejected ? 'err' : 'ok');
    return true;
}

function admin_page_edit(): void
{
    $page = page_get((int)($_GET['id'] ?? 0));
    if (!$page) {
        http_response_code(404);
        admin_header('Не найдено', 'pages');
        echo '<p>Страница не найдена. <a href="' . e(a_url('pages')) . '">К списку</a></p>';
        admin_footer();
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        try {
            $saved = db_tx(static function () use ($page): bool {
                return page_save($page);
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'page_edit_conflict') {
                throw $e;
            }
            $fresh = page_get((int)$page['id']) ?: $page;
            flash('Изменения НЕ сохранены: пока вы правили, эта страница была изменена в другой вкладке или на другом устройстве '
                . '(в ' . date('H:i', (int)$fresh['updated_at']) . '). Открытая сейчас версия — свежая. Ваш набранный текст сохранился '
                . 'в черновике браузера — редактор предложит его восстановить; сравните и перенесите нужное вручную. '
                . 'Выбор и порядок фото нужно будет повторить.', 'err');
            redirect(a_url('page_edit', ['id' => $page['id']]));
        }
        if ($saved) {
            site_touch();
        }
        $tabPost = $_POST['tab'] ?? 'content';
        redirect(a_url('page_edit', ['id' => $page['id'], 'tab' => is_string($tabPost) ? $tabPost : 'content']));
    }

    $id = (int)$page['id'];
    $isAlbum = $page['type'] === 'album';
    $isHome = (int)$page['is_home'] === 1;
    $tabGet = $_GET['tab'] ?? '';
    $tab = is_string($tabGet) && in_array($tabGet, ['content', 'settings', 'seo'], true) ? $tabGet : 'content';
    $scroll = $_SESSION['scroll_to'] ?? '';
    unset($_SESSION['scroll_to']);

    if (!empty($_SESSION['old']) && is_array($_SESSION['old'])) {
        foreach (['canonical', 'jsonld_extra'] as $oldKey) {
            if (isset($_SESSION['old'][$oldKey]) && is_string($_SESSION['old'][$oldKey])) {
                $page[$oldKey] = $_SESSION['old'][$oldKey];
            }
        }
        unset($_SESSION['old']);
    }




    $h1Count = 0;
    foreach (page_sections($id) as $hs) {
        $hsrc = $hs['type'] === 'text' ? (string)$hs['data']['html'] : ($hs['type'] === 'html' ? (string)$hs['data']['code'] : '');
        if ($hsrc !== '') {
            preg_match_all('~<h1(?:\s|>)~i', $hsrc, $hm);
            $h1Count += count($hm[0]);
        }
    }
    if ($isAlbum) {
        preg_match_all('~<h1(?:\s|>)~i', (string)$page['body_html'], $hm);
        $h1Count += count($hm[0]);
    }

    admin_header('Править: ' . $page['title'], 'pages');
    if ($h1Count > 1): ?>
<div class="flash err">В содержимом страницы <?= $h1Count ?> заголовков H1 — поисковикам лучше один. Замените лишние на H2 (кнопка «H2» в редакторе текста).</div>
    <?php endif;
    ?>
<form method="post" id="pageForm" data-scroll="<?= e($scroll) ?>">
<?= csrf_field() ?>
<input type="hidden" name="tab" value="<?= e($tab) ?>" id="tabField">
<?php  ?>
<input type="hidden" name="form_updated_at" value="<?= (int)$page['updated_at'] ?>">
<div class="bar sticky">
  <h1><?= e($page['title']) ?> <span class="tag"><?= $isAlbum ? 'альбом' : ($isHome ? 'главная' : 'страница') ?></span><?= (int)($page['is_service'] ?? 0) ? ' <span class="tag ok">услуга</span>' : '' ?>
    <?php if (!$isHome): ?><span class="tag <?= $page['is_published'] ? 'ok' : 'warn' ?>"><?= $page['is_published'] ? 'опубликована' : 'черновик' ?></span><?php endif; ?></h1>
  <span class="grow"></span>
  <?php if (!$isHome): ?><label class="chk pubchk" title="Статус публикации"><input type="checkbox" name="is_published" value="1" <?= $page['is_published'] ? 'checked' : '' ?>> Опубликована</label><?php endif; ?>
  <button type="button" class="btn" data-preview="<?= e(a_url('page_preview', ['id' => $page['id']])) ?>" data-preview-form="pageForm" title="Открыть страницу с текущими (ещё не сохранёнными) правками в новой вкладке — так, как она будет выглядеть на сайте">Предпросмотр</button>
  <a class="btn" href="<?= e(page_url($page)) ?>" target="_blank" rel="noopener">Посмотреть ↗</a>
  <button class="btn primary" name="action" value="save">Сохранить</button>
</div>

<div class="tabs" role="tablist">
  <button type="button" data-tab="content" class="<?= $tab === 'content' ? 'on' : '' ?>"><?= $isAlbum ? 'Фото и разделы' : 'Разделы' ?></button>
  <button type="button" data-tab="settings" class="<?= $tab === 'settings' ? 'on' : '' ?>">Страница</button>
  <button type="button" data-tab="seo" class="<?= $tab === 'seo' ? 'on' : '' ?>">SEO</button>
</div>


<section data-pane="content" <?= $tab === 'content' ? '' : 'hidden' ?>>
  <?php if ($isAlbum): ?>
    <div class="card">
      <h2>Текст над фотографиями</h2>
      <p class="muted">Необязательно, но короткое описание съёмки (имена, место, дата, история) хорошо работает для поиска.</p>
      <?= rte_field('body_html', (string)$page['body_html'], 'page:' . (int)$page['id'] . ':body', (int)$page['updated_at']) ?>
    </div>
    <div class="card">
      <h2>Фотографии</h2>
      <p class="muted">Перетаскивайте миниатюры, чтобы менять порядок. Первое фото станет обложкой в списках, если не выбрана другая на вкладке «Страница».</p>
      <?= gallery_editor('page', gallery_images('page', $id)) ?>
    </div>
    <?php  ?>
    <h2 class="sechead">Разделы под фотографиями</h2>
    <p class="muted sechead-note">Необязательно. Текст соображений, кнопка «Обсудить съёмку», форма связи — выводятся на странице альбома под галереей.</p>
    <?php foreach (page_sections($id) as $n => $sec): section_editor($sec, $n, 'page:' . $id, (int)$page['updated_at']); endforeach; ?>
    <div class="card addsec">
      <strong>Добавить раздел:</strong>
      <?php foreach (section_types() as $k => $label): ?>
        <button class="btn" name="action" value="add_section:<?= e($k) ?>">+ <?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php foreach (page_sections($id) as $n => $sec): section_editor($sec, $n, 'page:' . $id, (int)$page['updated_at']); endforeach; ?>
    <div class="card addsec">
      <strong>Добавить раздел:</strong>
      <?php foreach (section_types() as $k => $label): ?>
        <button class="btn" name="action" value="add_section:<?= e($k) ?>">+ <?= e($label) ?></button>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>


<section data-pane="settings" <?= $tab === 'settings' ? '' : 'hidden' ?>>
  <div class="card cols2">
    <div>
      <?= f_input('title', 'Название', $page['title'], ['maxlength' => 150, 'help' => 'Используется в меню, списках и как заголовок по умолчанию.']) ?>
      <?php if (!$isHome): ?>
        <?= f_input('slug', 'Адрес страницы', $page['slug'], ['help_html' => 'Будет доступна как <code>/' . e($page['slug']) . '</code>. При смене адреса старый автоматически перенаправляется (301).']) ?>
        <?= f_select('parent_id', 'Раздел (родитель)', [0 => '— нет —'] + page_options($id, true), (int)($page['parent_id'] ?? 0)) ?>
        <?= f_check('is_published', 'Опубликована', (bool)$page['is_published'], 'Черновики видны только вам и не попадают в меню и sitemap.') ?>
      <?php endif; ?>
    </div>
    <div>
      <?= f_input('h1', 'Заголовок H1', $page['h1'], ['help' => 'Если пусто — берётся название. На странице должен быть ровно один H1.']) ?>
      <?= f_check('show_title', 'Показывать H1 на странице', (bool)$page['show_title'], 'Если выключено, H1 есть в коде для поисковиков, но визуально скрыт.') ?>
      <?= f_textarea('short_text', 'Короткое описание', $page['short_text'], ['rows' => 2, 'help' => 'Показывается строкой под названием в карточках портфолио (список страниц); запасной вариант для meta description.']) ?>
      <?= f_input('badge', 'Бейдж на карточке', $page['badge'] ?? '', ['maxlength' => 60, 'help' => 'Короткая цветная плашка в углу обложки на карточках списков: «35 мм плёнка», «плёнка + цифра», «новое». Пусто — без бейджа.']) ?>
      <?= f_input('stamp_text', 'Свой текст штампа на фото', $page['stamp_text'] ?? '', ['maxlength' => 40, 'help' => 'Короткий текст LED-штампа в углу кадров этой страницы вместо даты: «V&M», «LOVE STORY», «12.10.26» — своя дата или любой текст. Имеет приоритет над EXIF и работает даже при выключенной общей настройке штампа. Пусто — дата съёмки из EXIF (если она есть и штамп включён), иначе ничего.']) ?>
      <?= f_input('shoot_note', 'Данные съёмки', $page['shoot_note'] ?? '', ['maxlength' => 80, 'help' => 'Камера · плёнка · дата съёмки: «Contax 645 · Portra 400 · 12.10.2026». Показывается один раз под заголовком альбома моноширинным шрифтом — вместо штампа на каждом кадре. Настоящие данные автора, не из EXIF.']) ?>
      <?= f_image('cover_image_id', 'Обложка в списках', $page['cover_image_id']) ?>
      <?php if ($isAlbum): ?>
        <?= f_select('album_layout', 'Раскладка фотографий', [
            '' => 'Masonry — кадры в своих пропорциях (по умолчанию)',
            'grid' => 'Ровная сетка — квадратные превью',
            'lineup' => 'Крупно — по одному кадру в строке',
            'contactsheet' => 'Контактный лист — плотная сетка в рамках плёнки с перфорацией',
            'hung' => 'Развеска — три формата и сдвиги из фиксированного набора',
        ], (string)($page['album_layout'] ?? ''), ['help' => 'Как показывать фотографии альбома. Тот же выбор есть у раздела «Галерея» на секционных страницах.']) ?>
        <?= f_select('album_columns', 'Колонок в альбоме', [0 => 'как в настройках сайта', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'], (int)$page['album_columns']) ?>
        <?= f_check('album_related', 'Показывать внизу «Другие работы» из того же раздела', (bool)$page['album_related']) ?>
      <?php endif; ?>
    </div>
  </div>
  <?php if (!$isHome): ?>
  <div class="card"><h2>Опасная зона</h2>
    <p class="muted">Удаление нельзя отменить. Фотографии останутся в медиатеке.</p>
    <button type="submit" class="btn danger" form="delForm" data-confirm-btn="Удалить страницу «<?= e($page['title']) ?>»?">Удалить страницу</button>
  </div>
  <?php endif; ?>
</section>


<section data-pane="seo" <?= $tab === 'seo' ? '' : 'hidden' ?>>
  <div class="card cols2">
    <div>
      <?= f_input('seo_title', 'Title', $page['seo_title'], ['data-count' => '60', 'data-count-min' => '30', 'maxlength' => 200, 'help' => 'Заголовок в выдаче поисковиков. Вписывается как есть. Пусто — соберётся по шаблону из настроек. Оптимально до 60 знаков.']) ?>
      <?= f_textarea('seo_description', 'Description', $page['seo_description'], ['rows' => 4, 'data-count' => '160', 'data-count-min' => '120', 'help' => 'Описание в выдаче поисковиков. Оптимально 120–160 знаков. Пусто — возьмётся короткое описание или начало текста.']) ?>
      <?= f_image('og_image_id', 'Картинка для соцсетей (Open Graph)', $page['og_image_id'], 'Пусто — используется обложка или первое фото страницы.') ?>
    </div>
    <div>
      <div class="f"><span class="fl">Так может выглядеть в поиске</span>
        <div class="serp" id="serp"><div class="serp-url"><?= e(base_url() . page_url($page)) ?></div><div class="serp-title" id="serpTitle"></div><div class="serp-desc" id="serpDesc"></div></div>
        <script type="application/json" id="serpDefaults"><?= json_encode(['title' => seo_title(array_merge($page, ['seo_title' => ''])), 'desc' => seo_description(array_merge($page, ['seo_description' => ''])), 'pageTitle' => $page['title']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
      </div>
      <?= f_input('canonical', 'Canonical (необязательно)', $page['canonical'], ['help' => 'Нужен только если страница дублирует другую. Обычно оставляйте пустым — подставится её собственный адрес.']) ?>
      <?= f_select('schema_type', 'Тип микроразметки (Schema.org)', page_schema_types(), (string)($page['schema_type'] ?? ''), ['help' => 'Яндекс формирует по разметке навигационную цепочку и расширенный сниппет. Для «О нас» выберите AboutPage, для «Контактов» — ContactPage.']) ?>
      <?= f_textarea('jsonld_extra', 'Свой JSON-LD (необязательно)', (string)($page['jsonld_extra'] ?? ''), ['rows' => 3, 'code' => true, 'help' => 'Дополнительный блок микроразметки, например FAQPage или VideoObject. Один объект {…} или массив [{…},{…}]. Проверить — валидатором в Яндекс.Вебмастере.']) ?>
      <?= f_jsonld_presets() ?>
      <?= f_check('noindex', 'Скрыть от поисковиков (noindex)', (bool)$page['noindex'], 'Страница не попадёт в sitemap.xml, в код добавится meta robots noindex.') ?>
    </div>
  </div>
  <div class="card">
    <h2>Услуга (разметка Service)</h2>
    <?php if ($isHome): ?>
      <p class="muted">Главная уже размечается как организация (Настройки → Организация) — отдельная услуга ей не нужна. Услуги размечайте на их собственных страницах.</p>
    <?php else: ?>
      <p class="muted">Включите, если страница описывает услугу («Свадебная фотосъёмка», «Love Story»): поисковики получат её структурированное описание — название, цену «от», город — со ссылкой на данные организации из Настройки → Организация. Проверить результат — валидатором микроразметки в Яндекс.Вебмастере.</p>
      <?php if ((int)($page['is_service'] ?? 0) && (int)$page['noindex']): ?>
        <p><span class="tag warn">разметка не сработает</span> <span class="muted">— страница закрыта от индекса (выше стоит noindex), поисковики её не увидят.</span></p>
      <?php endif; ?>
      <div class="cols2">
        <div>
          <?= f_check('is_service', 'Эта страница — услуга', (bool)($page['is_service'] ?? false), 'На страницу добавится блок микроразметки Service.') ?>
          <?= f_input('service_name', 'Название услуги', (string)($page['service_name'] ?? ''), ['maxlength' => 200, 'help' => 'Пусто — возьмётся заголовок H1 или название страницы.']) ?>
          <?= f_textarea('service_desc', 'Краткое описание услуги', (string)($page['service_desc'] ?? ''), ['rows' => 2, 'data-count' => '160', 'help' => '1–2 предложения, что входит в услугу. Пусто — возьмётся Description.']) ?>
        </div>
        <div>
          <?= f_input('service_price', 'Цена «от»', (string)($page['service_price'] ?? ''), ['help' => 'Только число, без «от» и знака валюты: 30000. Пусто — цена не выводится.']) ?>
          <?= f_select('service_currency', 'Валюта', service_currencies(), (string)($page['service_currency'] ?? 'RUB')) ?>
          <?= f_input('service_area', 'Где оказывается (города)', (string)($page['service_area'] ?? ''), ['maxlength' => 200, 'help' => 'Через запятую: «Москва, Московская область». Пусто — город из Настройки → Контакты.']) ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php  ?>
<input type="hidden" name="form_end" value="1">
</form>
<?php if (!$isHome): ?>
<form method="post" id="delForm" action="<?= e(a_url('pages')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"></form>
<?php endif; ?>
    <?php
    admin_footer();
}





function admin_page_preview(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        admin_header('Предпросмотр', 'pages');
        echo '<p>Предпросмотр открывается кнопкой из редактора страницы.</p>';
        admin_footer();
        return;
    }
    csrf_check();
    $page = page_get((int)($_GET['id'] ?? 0));
    if (!$page) {
        http_response_code(404);
        admin_header('Не найдено', 'pages');
        echo '<p>Страница не найдена.</p>';
        admin_footer();
        return;
    }
    session_write_close();
    $id = (int)$page['id'];
    $isAlbum = $page['type'] === 'album';
    $pdo = db();
    $pdo->beginTransaction();
    try {

        $albumLayoutRaw = $_POST['album_layout'] ?? (string)($page['album_layout'] ?? '');
        $albumLayout = is_string($albumLayoutRaw) ? $albumLayoutRaw : '';


        if (!in_array($albumLayout, ['', 'masonry', 'grid', 'lineup', 'contactsheet', 'hung'], true)) {
            $albumLayout = '';
        }
        db_run('UPDATE pages SET title=?, h1=?, show_title=?, body_html=?, album_columns=?, album_layout=?, badge=?, stamp_text=?, shoot_note=? WHERE id=?', [
            post_str('title', 150) !== '' ? post_str('title', 150) : (string)$page['title'],
            post_str('h1', 200),
            empty($_POST['show_title']) ? 0 : 1,
            $isAlbum ? sanitize_html(post_str('body_html')) : (string)$page['body_html'],
            max(0, min(6, (int)($_POST['album_columns'] ?? 0))),
            $albumLayout,
            post_str('badge', 60),
            post_str('stamp_text', 40),
            post_str('shoot_note', 80),
            $id,
        ]);

        foreach (post_arr('sec') as $sid => $f) {
            $s = db_one("SELECT * FROM sections WHERE id = ? AND owner_type = 'page' AND page_id = ?", [(int)$sid, $id]);
            if ($s && is_array($f)) {
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db(section_collect((string)$s['type'], $f)), $s['id']]);
            }
        }

        $allowed = $isAlbum ? ['page'] : [];
        foreach (db_all("SELECT id FROM sections WHERE owner_type = 'page' AND page_id = ? AND type = ?", [$id, 'gallery']) as $s) {
            $allowed[] = 's' . $s['id'];
        }
        $galPost = post_arr('gal');
        $galJsonRaw = $_POST['gal_json'] ?? [];
        $galJson = is_array($galJsonRaw) ? $galJsonRaw : [];
        foreach (post_arr('gal_keys') as $key) {
            $key = is_scalar($key) ? (string)$key : '';
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (isset($galJson[$key]) && is_string($galJson[$key])) {
                $ids = json_decode($galJson[$key], true);
                $ids = is_array($ids) ? $ids : [];
            } else {
                $ids = is_array($galPost[$key] ?? null) ? $galPost[$key] : [];
            }
            if ($key === 'page') {
                gallery_replace('page', $id, $ids);
            } else {
                gallery_replace('section', (int)substr($key, 1), $ids);
            }
        }
        page_sections_cache_flush($id);

        $fresh = page_get($id);
        $html = render_page($fresh ?: $page);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        page_sections_cache_flush($id);
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $style = '<style>.preview-note{position:sticky;top:0;z-index:90;background:#fff8d6;border-bottom:1px solid #e0d28a;padding:10px 16px;font:14px/1.4 sans-serif;text-align:center}</style>';
    $note = '<div class="preview-note">Это предпросмотр несохранённой страницы — её видите только вы. Чтобы опубликовать правки, вернитесь в редактор и нажмите «Сохранить».</div>';
    $html = (string)preg_replace('~</head>~i', $style . '</head>', $html, 1);
    $html = (string)preg_replace('~<body([^>]*)>~i', '<body$1>' . $note, $html, 1);
    echo public_csp_send($html);
}



function rte_field(string $name, string $html, string $draftKey = '', int $draftUpdated = 0, string $taId = '', string $wcTarget = ''): string
{


    $draft = $draftKey !== '' ? ' data-draft-key="' . e($draftKey) . '"' . ($draftUpdated > 0 ? ' data-draft-updated="' . $draftUpdated . '"' : '') : '';
    $extra = ($taId !== '' ? ' id="' . e($taId) . '"' : '') . ($wcTarget !== '' ? ' data-wc="' . e($wcTarget) . '"' : '');
    return '<div class="rte-wrap" data-rte>'
        . '<div class="rte-bar"><button type="button" data-cmd="bold"><b>B</b></button><button type="button" data-cmd="italic"><i>I</i></button>'

        . '<button type="button" data-cmd="h2">H2</button><button type="button" data-cmd="h3">H3</button>'
        . '<button type="button" data-cmd="insertUnorderedList">• список</button><button type="button" data-cmd="insertOrderedList">1. список</button>'
        . '<button type="button" data-cmd="blockquote">цитата</button><button type="button" data-cmd="createLink">ссылка</button>'
        . '<button type="button" data-cmd="unlink">без ссылки</button><button type="button" data-cmd="removeFormat">очистить</button>'
        . '<button type="button" data-cmd="html" class="right">&lt;/&gt; HTML</button></div>'




        . '<div class="rte" contenteditable="true"></div>'
        . '<textarea name="' . e($name) . '" class="rte-src code" rows="10"' . $draft . $extra . ' hidden>' . e($html) . '</textarea></div>';
}

function gallery_editor(string $key, array $items): string
{
    $h = '<div class="galed" data-key="' . e($key) . '"><input type="hidden" name="gal_keys[]" value="' . e($key) . '">';
    $h .= '<div class="galed-grid">';
    foreach ($items as $img) {
        $h .= gallery_tile($key, $img);
    }
    $h .= '</div><div class="galed-bar"><button type="button" class="btn primary" data-add-photos>+ Добавить фото</button> <span class="muted galed-count"></span></div></div>';
    return $h;
}

function gallery_tile(string $key, array $img): string
{

    return '<div class="gi" draggable="true" data-id="' . (int)$img['id'] . '"><img src="' . e(thumb_url($img)) . '" alt="" loading="lazy">'
        . '<div class="gi-mv"><button type="button" data-mv="-1" title="Раньше" aria-label="Переместить раньше">◀</button>'
        . '<button type="button" data-mv="1" title="Позже" aria-label="Переместить позже">▶</button></div>'
        . '<button type="button" class="gi-del" title="Убрать из галереи">×</button>'
        . '<input type="hidden" name="gal[' . e($key) . '][]" value="' . (int)$img['id'] . '"></div>';
}

function section_editor(array $sec, int $n, string $draftNs, int $pageUpdated = 0): void
{



    $sid = (int)$sec['id'];
    $d = $sec['data'];
    $pfx = 'sec[' . $sid . ']';
    $types = section_types();
    $spaces = ['none' => 'нет', 'sm' => 'малый', 'md' => 'средний', 'lg' => 'большой'];
    ?>
<div class="card sec-card" id="s<?= $sid ?>">
  <div class="sec-head">
    <strong><?= e($types[$sec['type']] ?? $sec['type']) ?></strong>
    <span class="grow"></span>
    <button class="ico" name="action" value="up_section:<?= $sid ?>" title="Выше">↑</button>
    <button class="ico" name="action" value="down_section:<?= $sid ?>" title="Ниже">↓</button>
    <button class="ico danger" name="action" value="del_section:<?= $sid ?>" data-confirm-btn="Удалить этот раздел?" title="Удалить">✕</button>
  </div>
    <?php
    switch ($sec['type']) {
        case 'text':
            echo rte_field($pfx . '[html]', (string)$d['html'], $draftNs . ':sec:' . $sid, $pageUpdated);
            echo '<div class="row">' . f_select($pfx . '[width]', 'Ширина текста', ['narrow' => 'узкая', 'medium' => 'средняя', 'wide' => 'на всю ширину'], $d['width'])
                . f_select($pfx . '[align]', 'Выравнивание', ['left' => 'по левому краю', 'center' => 'по центру'], $d['align']) . '</div>';
            break;
        case 'gallery':
            echo gallery_editor('s' . $sid, gallery_images('section', $sid));
            echo '<div class="row">' . f_select($pfx . '[layout]', 'Вид', ['masonry' => 'Кирпичики (сохраняет пропорции)', 'grid' => 'Сетка (квадраты)', 'lineup' => 'В одну колонку', 'strip' => 'Лента (слайдшоу)', 'slides' => 'Слайдшоу на весь экран', 'contactsheet' => 'Контактный лист (плёнка с перфорацией)'], $d['layout'])
                . f_select($pfx . '[columns]', 'Колонок', [0 => 'как в настройках', 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'], (int)$d['columns'])
                . f_select($pfx . '[lightbox]', 'По щелчку', ['' => 'как в настройках сайта', '1' => 'открывать крупно', '0' => 'просто показывать'], (string)$d['lightbox']) . '</div>';

            echo '<div class="row" data-strip-only>'
                . f_select($pfx . '[autoplay]', 'Лента: автопрокрутка', ['0' => 'выключена', '1' => 'включена'], (string)$d['autoplay'])
                . f_select($pfx . '[autoplay_dir]', 'Лента: направление', ['fwd' => 'вперёд (кадры едут справа налево)', 'back' => 'назад (кадры едут слева направо)'], (string)$d['autoplay_dir'])
                . f_select($pfx . '[autoplay_speed]', 'Лента: секунд на кадр', ['3' => '3', '4' => '4', '5' => '5', '6' => '6', '8' => '8', '10' => '10', '15' => '15'], (string)$d['autoplay_speed'])
                . '</div>';

            echo '<div class="row" data-slides-only>'
                . f_select($pfx . '[fx]', 'Слайдшоу: смена кадра', ['fade' => 'плавное наложение', 'cut' => 'резкая смена', 'slide' => 'сдвиг'], (string)$d['fx'])
                . f_select($pfx . '[speed]', 'Слайдшоу: секунд на кадр', ['3' => '3', '4' => '4', '5' => '5', '6' => '6', '8' => '8', '10' => '10', '15' => '15'], (string)$d['speed'])
                . f_select($pfx . '[height]', 'Слайдшоу: высота', ['full' => 'весь экран', 'tall' => 'три четверти экрана', 'half' => 'половина экрана'], (string)$d['height'])
                . '</div>';
            break;
        case 'photo':
            echo f_image($pfx . '[image_id]', 'Фотография', (int)$d['image_id'], 'Одно фото из медиатеки. Для нескольких добавьте раздел «Галерея».');
            echo '<div class="row">' . f_select($pfx . '[size]', 'Размещение', photo_size_presets(), (string)$d['size'])
                . f_select($pfx . '[lightbox]', 'По щелчку', ['' => 'как в настройках сайта', '1' => 'открывать крупно', '0' => 'просто показывать'], (string)$d['lightbox']) . '</div>';
            echo f_input($pfx . '[caption]', 'Подпись (необязательно)', (string)$d['caption'], ['maxlength' => 200]);
            echo '<div class="row">' . f_input($pfx . '[link]', 'Своя ссылка по клику (необязательно)', (string)($d['link'] ?? ''), ['maxlength' => 500, 'help' => 'Например, /contacts или https://…. Заполнено — клик ведёт по ссылке, а не открывает лайтбокс.'])
                . f_check($pfx . '[link_new_tab]', 'Открывать ссылку в новой вкладке', !empty($d['link_new_tab'])) . '</div>';
            break;
        case 'buttons':
            echo '<div class="btnrows" data-prefix="' . e($pfx) . '" data-icons="' . e(json_encode(button_icons(), JSON_UNESCAPED_UNICODE)) . '">';
            foreach (array_values((array)$d['items']) as $i => $b) {
                echo button_row($pfx, (string)$i, $b);
            }
            echo '</div><button type="button" class="btn" data-add-btn>+ Кнопка</button>';
            echo '<div class="row">' . f_select($pfx . '[style]', 'Стиль', ['outline' => 'контурная', 'solid' => 'залитая'], $d['style'])
                . f_select($pfx . '[align]', 'Выравнивание', ['center' => 'по центру', 'left' => 'по левому краю'], $d['align']) . '</div>';
            echo '<p class="muted">Для звонка укажите ссылку вида <code>tel:+79990001122</code>, для почты — <code>mailto:name@site.ru</code>.</p>';
            break;
        case 'listing':

            echo f_input($pfx . '[kicker]', 'Надзаголовок (необязательно)', (string)($d['kicker'] ?? ''), ['maxlength' => 80, 'placeholder' => 'Избранные работы']);
            echo f_input($pfx . '[heading]', 'Заголовок (необязательно)', (string)($d['heading'] ?? ''), ['maxlength' => 150]);
            echo '<div class="row">' . f_select($pfx . '[mode]', 'Источник', ['pages' => 'Выбранные страницы', 'children' => 'Все вложенные страницы раздела'], $d['mode']) . '</div>';
            echo '<label class="f" data-listing-pages><span class="fl">Выбранные страницы (Ctrl/Cmd — несколько)</span><select name="' . e($pfx) . '[page_ids][]" multiple size="8">';
            foreach (page_options() as $pid => $t) {
                echo '<option value="' . $pid . '"' . (in_array($pid, array_map('intval', (array)$d['page_ids']), true) ? ' selected' : '') . '>' . e($t) . '</option>';
            }
            echo '</select><small>Порядок карточек — как в списке «Страницы» (меняется стрелками ↑↓ там, эту секцию пересохранять не нужно). Для режима «вложенные» выберите раздел ниже.</small></label>';
            echo '<div class="row" data-listing-children>' . f_select($pfx . '[parent_id]', 'Раздел (для режима «вложенные»)', [0 => 'эта же страница'] + page_options(), (int)$d['parent_id']) . '</div>';
            echo '<div class="row">' . f_select($pfx . '[format]', 'Формат обложек', ['portrait' => 'вертикальный 2:3', 'landscape' => 'горизонтальный 3:2', 'square' => 'квадрат', 'original' => 'как в оригинале'], $d['format'])
                . f_select($pfx . '[columns]', 'Колонок', [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'], (int)$d['columns']) . '</div>';
            break;
        case 'html':
            echo f_textarea($pfx . '[code]', 'HTML-код (виджеты, карты, формы)', (string)$d['code'], ['rows' => 8, 'code' => true,
                'help' => 'Код вставляется на сайт без проверки, включая <script>. Вставляйте только код из источников, которым доверяете, — он получает полный доступ к странице. Пока таких секций нет, сайт работает с блокирующей CSP-защитой.']);
            break;
        case 'contact':
            echo f_input($pfx . '[kicker]', 'Надзаголовок (необязательно)', (string)($d['kicker'] ?? ''), ['maxlength' => 80]);
            echo f_input($pfx . '[heading]', 'Заголовок (необязательно)', (string)$d['heading'], ['maxlength' => 150]);
            echo f_input($pfx . '[intro]', 'Текст над формой (необязательно)', (string)$d['intro'], ['maxlength' => 300]);
            echo f_input($pfx . '[button]', 'Текст кнопки', (string)$d['button'], ['maxlength' => 40, 'help' => 'Пусто — «Отправить».']);
            echo '<p class="muted">Поля формы: имя, телефон, email, дата съёмки, сообщение. Заявки приходят на «Почту для заявок» из Настройки → Контакты (пусто — на основной email); там же подключается дубль в Telegram-бота.</p>';
            break;
        case 'faq':
            echo f_input($pfx . '[kicker]', 'Надзаголовок (необязательно)', (string)($d['kicker'] ?? ''), ['maxlength' => 80]);
            echo f_input($pfx . '[heading]', 'Заголовок (необязательно)', (string)$d['heading'], ['maxlength' => 150]);
            echo '<div class="faqrows" data-prefix="' . e($pfx) . '">';
            foreach (array_values((array)$d['items']) as $i => $it) {
                echo faq_row($pfx, (string)$i, is_array($it) ? $it : []);
            }
            echo '</div><button type="button" class="btn" data-add-faq>+ Вопрос</button>';
            echo f_check($pfx . '[schema]', 'Добавить микроразметку FAQPage', !empty($d['schema']), 'Вопросы и ответы будут автоматически скопированы из этой секции в JSON-LD.');
            echo '<p class="muted">На сайте ответы раскрываются по щелчку. Включайте разметку только для вопросов, которые действительно видны посетителям.</p>';
            break;
        case 'pricing':
            echo f_input($pfx . '[kicker]', 'Надзаголовок (необязательно)', (string)($d['kicker'] ?? ''), ['maxlength' => 80]);
            echo f_input($pfx . '[heading]', 'Заголовок (необязательно)', (string)$d['heading'], ['maxlength' => 150, 'placeholder' => 'Форматы съёмки']);
            echo '<div class="row">' . f_select($pfx . '[currency]', 'Валюта', service_currencies(), (string)$d['currency']) . '</div>';
            echo '<div class="pricerows" data-prefix="' . e($pfx) . '">';
            foreach (array_values((array)$d['items']) as $i => $it) {
                echo pricing_row($pfx, (string)$i, is_array($it) ? $it : []);
            }
            echo '</div><button type="button" class="btn" data-add-price>+ Пакет</button>';
            echo '<p class="muted">Цены попадают в микроразметку OfferCatalog (у организации на главной, у услуги — на странице услуги): поисковики могут показать их прямо в сниппете. «Цена до» необязательна — заполните обе, чтобы показать вилку. Своя ссылка необязательна: пусто — карточка ведёт на эту же страницу. Публикуйте только реальные цены.</p>';
            break;
        case 'video':
            $opts = [0 => '— не выбрано —'];
            foreach (db_all('SELECT id, title FROM videos ORDER BY id DESC') as $v) {
                $opts[(int)$v['id']] = trim((string)$v['title']) !== '' ? (string)$v['title'] : ('Видео #' . (int)$v['id']);
            }
            echo f_select($pfx . '[video_id]', 'Видео', $opts, (int)$d['video_id'],
                ['help_html' => 'Ролики загружаются в разделе <a href="' . e(a_url('videos')) . '">«Видео»</a>. Название, обложка и описание задаются там же.']);
            break;
    }
    echo '<div class="row sm">' . f_select($pfx . '[space]', 'Отступ сверху', $spaces, $d['space']) . '</div>';
    echo '</div>';
}

function button_row(string $pfx, string $i, array $b): string
{
    $n = $pfx . '[btn][' . $i . ']';
    $h = '<div class="btnrow">';
    $h .= '<input name="' . e($n) . '[label]" value="' . e($b['label'] ?? '') . '" placeholder="Текст кнопки">';
    $h .= '<input name="' . e($n) . '[url]" value="' . e($b['url'] ?? '') . '" placeholder="Ссылка: https://… или tel:+7…">';
    $h .= '<select name="' . e($n) . '[icon]">';
    foreach (button_icons() as $k => $t) {
        $h .= '<option value="' . e($k) . '"' . (($b['icon'] ?? '') === $k ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    $h .= '</select><label class="chk"><input type="checkbox" name="' . e($n) . '[newtab]" value="1"' . (!empty($b['newtab']) ? ' checked' : '') . '> в новой вкладке</label>';
    return $h . '<button type="button" class="ico danger" data-del-btn title="Убрать">✕</button></div>';
}



function faq_row(string $pfx, string $i, array $it): string
{
    $n = $pfx . '[qa][' . $i . ']';
    $h = '<div class="faqrow">';
    $h .= '<div class="row"><input name="' . e($n) . '[q]" value="' . e($it['q'] ?? '') . '" placeholder="Вопрос" maxlength="300" style="flex:1">';
    $h .= '<button type="button" class="ico" data-faq-mv="-1" title="Выше" aria-label="Переместить выше">↑</button>';
    $h .= '<button type="button" class="ico" data-faq-mv="1" title="Ниже" aria-label="Переместить ниже">↓</button>';
    $h .= '<button type="button" class="ico danger" data-del-faq title="Убрать">✕</button></div>';
    $h .= '<textarea name="' . e($n) . '[a]" rows="2" placeholder="Ответ" maxlength="2000" style="width:100%">' . e($it['a'] ?? '') . '</textarea>';
    return $h . '</div>';
}



function pricing_row(string $pfx, string $i, array $it): string
{
    $n = $pfx . '[price][' . $i . ']';
    $h = '<div class="pricerow">';
    $h .= '<div class="row"><input name="' . e($n) . '[name]" value="' . e($it['name'] ?? '') . '" placeholder="Название: «Свадебный день»" maxlength="120" style="flex:1">';
    $h .= '<input name="' . e($n) . '[price_from]" value="' . e($it['price_from'] ?? '') . '" placeholder="Цена от" inputmode="numeric" maxlength="9" style="width:110px">';
    $h .= '<input name="' . e($n) . '[price_to]" value="' . e($it['price_to'] ?? '') . '" placeholder="до" inputmode="numeric" maxlength="9" style="width:110px">';
    $h .= '<input name="' . e($n) . '[duration]" value="' . e($it['duration'] ?? '') . '" placeholder="Длительность: 8 часов" maxlength="60" style="width:170px">';
    $h .= '<button type="button" class="ico" data-price-mv="-1" title="Выше" aria-label="Переместить выше">↑</button>';
    $h .= '<button type="button" class="ico" data-price-mv="1" title="Ниже" aria-label="Переместить ниже">↓</button>';
    $h .= '<button type="button" class="ico danger" data-del-price title="Убрать">✕</button></div>';
    $h .= '<textarea name="' . e($n) . '[desc]" rows="2" placeholder="Что входит: часы, число фото, сроки…" maxlength="600" style="width:100%">' . e($it['desc'] ?? '') . '</textarea>';
    $h .= '<input name="' . e($n) . '[url]" value="' . e($it['url'] ?? '') . '" placeholder="Ссылка карточки (необязательно): /contacts или https://…" maxlength="500" style="width:100%">';
    $h .= f_image($n . '[image_id]', 'Фото карточки (необязательно)', (int)($it['image_id'] ?? 0), 'Кадр 4:5 сверху карточки, кадрирование по фокусной точке.');
    return $h . '</div>';
}
