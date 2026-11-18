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







function unique_post_slug(string $base, ?int $exceptId = null): string
{
    $base = slugify($base, 90);
    if ($base === '') {
        $base = 'zametka';
    }
    $slug = $base;
    $i = 2;
    while (in_array($slug, reserved_slugs(), true)
        || db_val('SELECT id FROM posts WHERE slug = ? AND id <> ?', [$slug, (int)$exceptId])) {
        $slug = $base . '-' . $i++;
    }
    return $slug;
}

function admin_posts(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $aRaw = $_POST['action'] ?? '';
        $a = is_string($aRaw) ? $aRaw : '';
        if ($a === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $p = post_get($id);
            if ($p) {
                db_tx(static function () use ($p, $id): void {

                    foreach (db_all("SELECT id FROM sections WHERE owner_type = 'post' AND page_id = ?", [$id]) as $s) {
                        db_run("DELETE FROM gallery_items WHERE owner_type = 'section' AND owner_id = ?", [$s['id']]);
                    }
                    db_run("DELETE FROM sections WHERE owner_type = 'post' AND page_id = ?", [$id]);
                    db_run('DELETE FROM redirects WHERE from_path = ? OR to_url = ?', [post_url($p), post_url($p)]);
                    db_run('DELETE FROM post_links WHERE from_id = ?', [$id]);
                    db_run('DELETE FROM posts WHERE id = ?', [$id]);
                });
                site_touch();
                page_sections_cache_flush($id, 'post');
                flash('Заметка «' . $p['title'] . '» удалена.');
            }
        }
        redirect(a_url('posts'));
    }

    $sections = post_sections();
    $filterRaw = $_GET['section'] ?? '';
    $filter = is_string($filterRaw) ? $filterRaw : '';
    if ($filter !== '' && !section_get($filter)) {
        $filter = '';
    }


    $cols = 'id, title, slug, section, updated_at, is_published, noindex';
    $rows = $filter === ''
        ? db_all("SELECT $cols FROM posts ORDER BY updated_at DESC, id DESC")
        : db_all("SELECT $cols FROM posts WHERE section = ? ORDER BY updated_at DESC, id DESC", [$filter]);
    $backCounts = post_backlink_counts(array_map(static fn($r) => (int)$r['id'], $rows));

    admin_header('Блог', 'posts');
    ?>
<div class="bar"><h1>Заметки</h1><span class="grow"></span>
  <a class="btn" href="<?= e(a_url('sections')) ?>">Разделы</a>
  <a class="btn primary" href="<?= e(a_url('post_new', $filter !== '' ? ['section' => $filter] : [])) ?>">+ Новая заметка</a></div>
<p class="muted">Заметки-статьи. Текст собирается из блоков — как страница сайта: текст, фото, галерея, кнопки и т. д.
Ссылки на другие заметки ставятся кнопкой «ссылка» в редакторе текста (адрес заметки — <code>/раздел/адрес-заметки</code>).
Внизу каждой заметки автоматически собирается блок «Сюда ссылаются».</p>
<?php if (count($sections) > 1): ?>
<p class="muted">Раздел:
  <a href="<?= e(a_url('posts')) ?>"><?= $filter === '' ? '<strong>Все</strong>' : 'Все' ?></a><?php foreach ($sections as $s): ?> ·
  <a href="<?= e(a_url('posts', ['section' => $s['slug']])) ?>"><?= $filter === $s['slug'] ? '<strong>' . e(section_title($s)) . '</strong>' : e(section_title($s)) ?></a><?php endforeach; ?>
</p>
<?php endif; ?>
<p class="filterline"><input type="search" data-filter="#postsTbl tbody tr" placeholder="Быстрый фильтр по названию или адресу…"></p>
<table class="tbl" id="postsTbl">
<thead><tr><th>Название</th><th>Адрес</th><th>Раздел</th><th>Обновлена</th><th>Статус</th><th></th></tr></thead>
<tbody>
    <?php foreach ($rows as $p): $ps = post_section($p);

        $nBack = (int)($backCounts[(int)$p['id']] ?? 0);
        $confirmDel = 'Удалить заметку «' . $p['title'] . '»? Это нельзя отменить.'
            . ($nBack ? "\n\nНа неё ссылаются другие заметки: " . $nBack . ' — их ссылки сломаются.' : '')
            . "\nВнешние ссылки на неё дадут 404 — после удаления добавьте редирект в разделе «Редиректы»."; ?>
<tr>
  <td><a href="<?= e(a_url('post_edit', ['id' => $p['id']])) ?>"><strong><?= e($p['title']) ?></strong></a></td>
  <td><a href="<?= e(post_url($p)) ?>" target="_blank" rel="noopener"><?= e(post_url($p)) ?></a></td>
  <td><span class="tag"><?= e(section_title($ps)) ?></span></td>
  <td><?= e(ru_date((int)$p['updated_at'])) ?></td>
  <td><?= (int)$p['is_published'] ? '<span class="tag ok">опубликована</span>' : '<span class="tag warn">черновик</span>' ?><?= (int)$p['noindex'] ? ' <span class="tag">noindex</span>' : '' ?></td>
  <td class="acts">
    <a class="btn sm" href="<?= e(a_url('post_edit', ['id' => $p['id']])) ?>">Править</a>
    <form method="post" class="inline" data-confirm="<?= e($confirmDel) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm danger">Удалить</button></form>
  </td>
</tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">Заметок пока нет — нажмите «Новая заметка».</td></tr><?php endif; ?>
</tbody></table>
<p class="muted">Чтобы раздел появился в меню сайта: <a href="<?= e(a_url('menu')) ?>">Меню</a> → «+ Пункт» → в поле адреса укажите адрес раздела, например <code>/blog</code>. RSS-лента всех заметок: <a href="/feed.xml" target="_blank" rel="noopener">/feed.xml</a>.</p>
    <?php
    admin_footer();
}

function admin_post_new(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $title = post_str('title', 150);
        if ($title === '') {
            flash('Введите название.', 'err');
            redirect(a_url('post_new'));
        }
        $section = post_str('section') ?: 'blog';
        if (!section_get($section)) {
            $section = 'blog';
        }
        $t = now();
        $slugNew = unique_post_slug($title);


        try {
            $id = db_insert(
                'INSERT INTO posts (title, slug, section, body, is_published, created_at, updated_at) VALUES (?,?,?,?,0,?,?)',
                [$title, $slugNew, $section, '', $t, $t]
            );
        } catch (PDOException $e) {

            if (stripos($e->getMessage(), 'unique') === false) {
                throw $e;
            }
            $slugNew = unique_post_slug($title);
            $id = db_insert(
                'INSERT INTO posts (title, slug, section, body, is_published, created_at, updated_at) VALUES (?,?,?,?,0,?,?)',
                [$title, $slugNew, $section, '', $t, $t]
            );
        }
        redirect_clear_from(section_base(section_get($section)) . '/' . $slugNew);
        flash('Заметка создана как черновик. Добавьте блоки содержимого и отметьте «Опубликована».');
        redirect(a_url('post_edit', ['id' => $id]));
    }
    $preRaw = $_GET['title'] ?? '';
    $pre = trim(is_string($preRaw) ? $preRaw : '');
    $preSecRaw = $_GET['section'] ?? 'blog';
    $preSec = is_string($preSecRaw) ? $preSecRaw : 'blog';
    if (!section_get($preSec)) {
        $preSec = 'blog';
    }
    $secOpts = [];
    foreach (post_sections() as $s) {
        $secOpts[$s['slug']] = section_title($s) . ' (' . section_base($s) . ')';
    }
    admin_header('Новая заметка', 'posts');
    ?>
<h1>Новая заметка</h1>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <?= f_input('title', 'Название', $pre, ['maxlength' => 150, 'help_html' => 'На заметку можно будет сослаться из другой заметки обычной ссылкой (кнопка «ссылка» в редакторе текста) — адрес подсказан в списке заметок.']) ?>
  <?= f_select('section', 'Раздел', $secOpts, $preSec, ['help_html' => 'Разделы настраиваются на странице <a href="' . e(a_url('sections')) . '">Разделы</a>.']) ?>
  <button class="btn primary">Создать и писать</button>
</form>
    <?php
    admin_footer();
}

function post_collect(array $post, bool $preview = false): array
{
    $id = (int)$post['id'];
    $title = post_str('title', 150);
    if ($title === '') {
        $title = (string)$post['title'];
        if (!$preview) {
            flash('Название не может быть пустым — оставлено прежнее.', 'err');
        }
    }

    $slug = (string)$post['slug'];
    $in = slug_normalize(post_str('slug', 120));
    if ($in === '') {
        $in = unique_post_slug($title, $id);
    }
    if ($in !== $slug) {
        if (!valid_slug($in)) {
            if (!$preview) {
                flash('Адрес «' . $in . '» недопустим (это служебное слово). Оставлен прежний.', 'err');
            }
        } elseif (db_val('SELECT id FROM posts WHERE slug = ? AND id <> ?', [$in, $id])) {
            if (!$preview) {
                flash('Адрес «' . $in . '» уже занят другой заметкой. Оставлен прежний.', 'err');
            }
        } else {
            $slug = $in;
        }
    }


    $oldSection = (string)($post['section'] ?? 'blog');
    $section = post_str('section') ?: $oldSection;
    if (!section_get($section)) {
        $section = $oldSection;
    }


    $schemaType = post_str('schema_type');
    if (!in_array($schemaType, ['', 'BlogPosting', 'Article', 'NewsArticle'], true)) {
        $schemaType = '';
    }


    $jsonldExtra = post_str('jsonld_extra');
    $rejected = 0;
    if ($jsonldExtra !== '' && !jsonld_extra_blocks($jsonldExtra)) {
        if (!$preview) {
            $_SESSION['old']['jsonld_extra'] = $jsonldExtra;
            $rejected++;
            flash('Свой JSON-LD не сохранён: это не JSON-объект. Проверьте запятую, кавычки и скобки.', 'err');
        }
        $jsonldExtra = (string)($post['jsonld_extra'] ?? '');
    }



    $canonical = post_str('canonical', 500);
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
            $canonErr = 'Canonical должен быть путём вида /blog/post или полным адресом этого сайта (https://…). Поле очищено.';
        }
        if ($canonErr !== '') {
            if (!$preview) {
                $_SESSION['old']['canonical'] = $canonical;
                $rejected++;
                flash($canonErr, 'err');
            }
            $canonical = '';
        }
    }

    $isPub = empty($_POST['is_published']) ? 0 : 1;



    $pubAt = (int)($post['published_at'] ?? 0);
    $pubIn = post_str('published_at_local');
    if ($isPub === 1 && $pubIn !== '') {
        $ts = strtotime($pubIn);
        if ($ts !== false && $ts > 0) {
            $pubAt = $ts;
        } elseif (!$preview) {
            flash('Дата публикации не распознана — оставлена прежняя.', 'err');
        }
    } elseif ($isPub === 1 && $pubAt <= 0) {
        $pubAt = now();
    }

    return [
        'title' => $title,
        'slug' => $slug,
        'section' => $section,
        'excerpt' => post_str('excerpt', 500),
        'is_published' => $isPub,
        'published_at' => $pubAt,
        'seo_title' => post_str('seo_title', 200),
        'seo_description' => post_str('seo_description', 400),
        'og_image_id' => ((int)($_POST['og_image_id'] ?? 0)) ?: null,
        'noindex' => empty($_POST['noindex']) ? 0 : 1,
        'schema_type' => $schemaType,
        'jsonld_extra' => $jsonldExtra,
        'canonical' => $canonical,
        'rejected' => $rejected,
    ];
}

function post_save(array $post): bool
{
    $id = (int)$post['id'];



    if (!isset($_POST['form_end'])) {
        flash('Форма пришла не полностью: хостинг обрезал её по лимиту max_input_vars. '
            . 'Ничего не сохранено — увеличьте max_input_vars в настройках PHP (например, до 5000) и повторите.', 'err');
        return false;
    }





    $formUpd = (int)($_POST['form_updated_at'] ?? 0);
    if ($formUpd > 0 && (int)$post['updated_at'] !== $formUpd) {
        throw new RuntimeException('post_edit_conflict');
    }

    $fields = post_collect($post);
    $slug = $fields['slug'];
    $section = $fields['section'];
    $oldSection = (string)($post['section'] ?? 'blog');
    $rejected = $fields['rejected'];



    $saveSql = 'UPDATE posts SET title=?, slug=?, section=?, excerpt=?, is_published=?, published_at=?, seo_title=?, seo_description=?, og_image_id=?, noindex=?, schema_type=?, jsonld_extra=?, canonical=?, updated_at=? WHERE id=?';
    $saveParams = [
        $fields['title'],
        $slug,
        $section,
        $fields['excerpt'],
        $fields['is_published'],
        $fields['published_at'],
        $fields['seo_title'],
        $fields['seo_description'],
        $fields['og_image_id'],
        $fields['noindex'],
        $fields['schema_type'],
        $fields['jsonld_extra'],
        $fields['canonical'],
        max(now(), (int)$post['updated_at'] + 1),
        $id,
    ];
    if ($formUpd > 0) {
        $saveSql .= ' AND updated_at=?';
        $saveParams[] = $formUpd;
    }
    try {
        $st = db_run($saveSql, $saveParams);
    } catch (PDOException $e) {


        if ($slug !== (string)$post['slug'] && stripos($e->getMessage(), 'unique') !== false) {
            flash('Адрес «' . $slug . '» только что заняла другая заметка. Оставлен прежний.', 'err');
            $slug = (string)$post['slug'];
            $saveParams[1] = $slug;
            $st = db_run($saveSql, $saveParams);
        } else {
            throw $e;
        }
    }
    if ($formUpd > 0 && $st->rowCount() === 0) {
        throw new RuntimeException('post_edit_conflict');
    }




    $usedIds = [];
    foreach (post_arr('sec') as $sid => $f) {
        $s = db_one("SELECT * FROM sections WHERE id = ? AND owner_type = 'post' AND page_id = ?", [(int)$sid, $id]);
        if ($s && is_array($f)) {
            $data = section_collect((string)$s['type'], $f);
            if ($s['type'] === 'text') {
                $data['html'] = blog_html_prepare((string)$data['html'], $usedIds);
            }
            db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($data), $s['id']]);
        }
    }



    $allowed = [];
    foreach (db_all("SELECT id FROM sections WHERE owner_type = 'post' AND page_id = ? AND type = 'gallery'", [$id]) as $s) {
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
        gallery_replace('section', (int)substr($key, 1), $ids);
    }


    $act = post_str('action') ?: 'save';
    if (strpos($act, ':') !== false) {
        [$verb, $arg] = explode(':', $act, 2);
        if ($verb === 'add_section' && isset(post_section_types()[$arg])) {
            $pos = (int)db_val("SELECT COALESCE(MAX(position), 0) + 1 FROM sections WHERE owner_type = 'post' AND page_id = ?", [$id]);
            $new = db_insert("INSERT INTO sections (owner_type, page_id, type, position, data) VALUES ('post',?,?,?,?)", [$id, $arg, $pos, json_db(section_defaults($arg))]);
            $_SESSION['scroll_to'] = 's' . $new;
        } elseif ($verb === 'del_section') {
            $sid = (int)$arg;
            if (db_val("SELECT id FROM sections WHERE id = ? AND owner_type = 'post' AND page_id = ?", [$sid, $id])) {
                db_run("DELETE FROM gallery_items WHERE owner_type = 'section' AND owner_id = ?", [$sid]);
                db_run('DELETE FROM sections WHERE id = ?', [$sid]);
            }
        } elseif ($verb === 'up_section' || $verb === 'down_section') {
            $ids = array_map('intval', array_column(db_all("SELECT id FROM sections WHERE owner_type = 'post' AND page_id = ? ORDER BY position, id", [$id]), 'id'));
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
    }
    page_sections_cache_flush($id, 'post');

    post_links_reindex($id, post_text_html(post_get($id) ?: $post));


    $oldUrl = post_url($post);
    $newUrl = section_base(section_get($section)) . '/' . $slug;
    if ($newUrl !== $oldUrl) {

        redirect_clear_from($newUrl);
        if ((int)$post['is_published']) {
            $rerr = redirect_add($oldUrl, $newUrl);
            if ($rerr !== null) {
                flash('Редирект со старого адреса не создан: ' . $rerr, 'err');
            } elseif ($section !== $oldSection) {
                flash('Заметка перенесена: ' . $oldUrl . ' → ' . $newUrl . '. Со старого адреса настроен редирект 301.');
            }
        }
    }
    if ($newUrl !== $oldUrl) {



        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $oldUrl);
        $candidates = db_all(
            "SELECT id, page_id, data FROM sections WHERE owner_type = 'post' AND type = 'text' AND page_id <> ? AND data LIKE ? ESCAPE '\\'",
            [$id, '%href="' . $esc . '"%']
        );
        $fixedPosts = [];
        foreach ($candidates as $secRow) {
            $d = json_decode((string)$secRow['data'], true);
            if (!is_array($d) || !is_string($d['html'] ?? null)) {
                continue;
            }
            $newHtml = str_replace('href="' . $oldUrl . '"', 'href="' . $newUrl . '"', $d['html']);
            if ($newHtml !== $d['html']) {
                $d['html'] = $newHtml;
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($d), (int)$secRow['id']]);
                $fixedPosts[(int)$secRow['page_id']] = true;
            }
        }


        foreach (array_keys($fixedPosts) as $fpId) {
            page_sections_cache_flush($fpId, 'post');
            $fp = post_get($fpId);
            if ($fp) {
                post_links_reindex($fpId, post_text_html($fp));
            }
        }
        if ($fixedPosts) {
            flash('Адрес изменён. Ссылки на него обновлены в заметках: ' . count($fixedPosts) . '.');
        }
    }
    flash($rejected ? 'Сохранено, кроме отклонённых полей — исправьте их (значения возвращены в поля) и сохраните ещё раз.' : 'Сохранено.', $rejected ? 'err' : 'ok');
    return true;
}

function admin_post_edit(): void
{
    $post = post_get((int)($_GET['id'] ?? 0));
    if (!$post) {
        http_response_code(404);
        admin_header('Не найдено', 'posts');
        echo '<p>Заметка не найдена. <a href="' . e(a_url('posts')) . '">К списку</a></p>';
        admin_footer();
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        try {
            $saved = db_tx(static function () use ($post): bool {
                return post_save($post);
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'post_edit_conflict') {
                throw $e;
            }
            $fresh = post_get((int)$post['id']) ?: $post;
            flash('Изменения НЕ сохранены: пока вы правили, эта заметка была изменена в другой вкладке или на другом устройстве '
                . '(в ' . date('H:i', (int)$fresh['updated_at']) . '). Открытая сейчас версия — свежая. Ваш набранный текст сохранился '
                . 'в черновике браузера — редактор предложит его восстановить; сравните и перенесите нужное вручную.', 'err');
            redirect(a_url('post_edit', ['id' => $post['id']]));
        }
        if ($saved) {
            site_touch();
        }
        redirect(a_url('post_edit', ['id' => $post['id']]));
    }


    if (!empty($_SESSION['old']) && is_array($_SESSION['old'])) {
        foreach (['jsonld_extra', 'canonical'] as $oldKey) {
            if (isset($_SESSION['old'][$oldKey]) && is_string($_SESSION['old'][$oldKey])) {
                $post[$oldKey] = $_SESSION['old'][$oldKey];
            }
        }
        unset($_SESSION['old']);
    }
    $scroll = $_SESSION['scroll_to'] ?? '';
    unset($_SESSION['scroll_to']);

    $id = (int)$post['id'];
    $postSections = post_content_sections($id);


    $links = post_body_links(post_text_html($post));
    $missing = [];
    foreach ($links as $l) {
        if (!post_find($l['target']) && !in_array($l['target'], $missing, true)) {
            $missing[] = $l['target'];
        }
    }
    $backlinks = post_backlinks($post);
    $secOpts = [];
    foreach (post_sections() as $s) {
        $secOpts[$s['slug']] = section_title($s) . ' (' . section_base($s) . ')';
    }


    $h1Count = 0;
    foreach ($postSections as $hs) {
        $hsrc = $hs['type'] === 'text' ? (string)$hs['data']['html'] : ($hs['type'] === 'html' ? (string)$hs['data']['code'] : '');
        if ($hsrc !== '') {
            preg_match_all('~<h1(?:\s|>)~i', $hsrc, $hm);
            $h1Count += count($hm[0]);
        }
    }

    admin_header('Заметка: ' . $post['title'], 'posts');
    if ($h1Count > 0): ?>
<div class="flash err">В содержимом заметки <?= $h1Count ?> заголовков H1, а заголовок первого уровня уже выводится названием заметки — поисковикам лучше один. Замените их на H2 (кнопка «H2» в редакторе текста).</div>
    <?php endif;
    ?>
<form method="post" id="postForm" data-scroll="<?= e($scroll) ?>">
<?= csrf_field() ?>
<?php  ?>
<input type="hidden" name="form_updated_at" value="<?= (int)$post['updated_at'] ?>">
<div class="bar sticky">
  <h1><?= e($post['title']) ?> <span class="tag">заметка</span>
    <span class="tag <?= $post['is_published'] ? 'ok' : 'warn' ?>"><?= $post['is_published'] ? 'опубликована' : 'черновик' ?></span></h1>
  <span class="grow"></span>
  <label class="chk pubchk" title="Статус публикации"><input type="checkbox" name="is_published" value="1" <?= $post['is_published'] ? 'checked' : '' ?>> Опубликована</label>
  <button type="button" class="btn" data-preview="<?= e(a_url('post_preview', ['id' => $post['id']])) ?>" data-preview-form="postForm" title="Открыть заметку с текущими (ещё не сохранёнными) правками в новой вкладке — так, как она будет выглядеть на сайте">Предпросмотр</button>
  <a class="btn" href="<?= e(post_url($post)) ?>" target="_blank" rel="noopener">Посмотреть ↗</a>
  <button class="btn primary" name="action" value="save">Сохранить</button>
</div>

<h2 class="sechead">Содержимое заметки</h2>
<p class="muted sechead-note">Заметка собирается из блоков, как страница сайта: текст, фото, галерея, кнопки… Перетаскивания нет — порядок меняется стрелками ↑ ↓ в шапке блока. Ссылки на другие заметки — кнопкой «ссылка» в редакторе текста (адрес заметки виден в списке заметок).</p>
<?php foreach ($postSections as $n => $sec): section_editor($sec, $n, 'post:' . $id, (int)$post['updated_at']); endforeach; ?>
<div class="card addsec">
  <strong>Добавить блок:</strong>
  <?php foreach (post_section_types() as $k => $label): ?>
    <button class="btn" name="action" value="add_section:<?= e($k) ?>">+ <?= e($label) ?></button>
  <?php endforeach; ?>
</div>
<?php if ($missing): ?>
<div class="card">
  <p class="muted">Ссылки на несуществующие заметки (посетитель попадёт на 404):
    <?php foreach ($missing as $m): ?>
      <a href="<?= e(a_url('post_new', ['title' => $m])) ?>"><?= e($m) ?></a><?= $m === end($missing) ? '' : ', ' ?>
    <?php endforeach; ?>
  — нажмите, чтобы создать заметку с таким названием, или поправьте ссылку.</p>
</div>
<?php endif; ?>

<div class="card cols2">
  <div>
    <?= f_input('title', 'Название', $post['title'], ['maxlength' => 150]) ?>
    <?= f_select('section', 'Раздел', $secOpts, (string)($post['section'] ?? 'blog'), ['help' => 'При переносе в другой раздел со старого адреса автоматически настроится редирект 301.']) ?>
    <?= f_input('slug', 'Адрес заметки', $post['slug'], ['help_html' => 'Полный адрес: <code>' . e(post_url($post)) . '</code>. При смене адреса старый автоматически перенаправляется (301).']) ?>
    <?= f_textarea('excerpt', 'Анонс (необязательно)', $post['excerpt'], ['rows' => 2, 'help' => 'Показывается в списке заметок и в RSS. Пусто — возьмётся начало текста.']) ?>
    <?= f_check('is_published', 'Опубликована', (bool)$post['is_published'], 'Черновики видны только вам и не попадают в список, RSS и sitemap.') ?>
    <?= f_input('published_at_local', 'Дата публикации', (int)($post['published_at'] ?? 0) > 0 ? date('Y-m-d\TH:i', (int)$post['published_at']) : '', ['type' => 'datetime-local', 'help' => 'Влияет на порядок в списке, RSS и дату в микроразметке. Пусто — проставится автоматически при публикации.']) ?>
  </div>
  <div>
    <?= f_input('seo_title', 'Title', $post['seo_title'], ['data-count' => '60', 'data-count-min' => '30', 'maxlength' => 200, 'help' => 'Заголовок в выдаче поисковиков. Пусто — соберётся по шаблону из настроек.']) ?>
    <?= f_textarea('seo_description', 'Description', $post['seo_description'], ['rows' => 3, 'data-count' => '160', 'data-count-min' => '120', 'help' => 'Описание в выдаче поисковиков. Пусто — возьмётся анонс.']) ?>
    <div class="f"><span class="fl">Так может выглядеть в поиске</span>
      <div class="serp" id="serp"><div class="serp-url"><?= e(base_url() . post_url($post)) ?></div><div class="serp-title" id="serpTitle"></div><div class="serp-desc" id="serpDesc"></div></div>
      <script type="application/json" id="serpDefaults"><?= json_encode(['title' => post_seo_title(array_merge($post, ['seo_title' => ''])), 'desc' => post_seo_description(array_merge($post, ['seo_description' => ''])), 'pageTitle' => $post['title']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
    </div>
    <?= f_image('og_image_id', 'Картинка для соцсетей (Open Graph)', $post['og_image_id'], 'Пусто — первое фото заметки или картинка по умолчанию из настроек SEO.') ?>
    <?= f_input('canonical', 'Canonical (необязательно)', (string)($post['canonical'] ?? ''), ['help' => 'Нужен только если заметка дублирует другую страницу. Обычно оставляйте пустым — подставится её собственный адрес.']) ?>
    <?= f_select('schema_type', 'Тип микроразметки (Schema.org)', ['' => 'Авто (BlogPosting)', 'BlogPosting' => 'BlogPosting — заметка блога', 'Article' => 'Article — статья', 'NewsArticle' => 'NewsArticle — новость'], (string)($post['schema_type'] ?? ''), ['help' => 'Яндекс и Google учитывают её при формировании расширенного сниппета.']) ?>
    <?= f_textarea('jsonld_extra', 'Свой JSON-LD (необязательно)', (string)($post['jsonld_extra'] ?? ''), ['rows' => 3, 'code' => true, 'help' => 'Дополнительный блок микроразметки, например FAQPage. Один объект {…} или массив [{…},{…}].']) ?>
    <?= f_jsonld_presets() ?>
    <?= f_check('noindex', 'Скрыть от поисковиков (noindex)', (bool)$post['noindex'], 'Заметка не попадёт в sitemap.xml, в код добавится meta robots noindex.') ?>
  </div>
</div>

<?php if ($backlinks): ?>
<div class="card"><h2>Сюда ссылаются</h2>
  <ul class="todo">
    <?php foreach ($backlinks as $b): ?><li><a href="<?= e(a_url('post_edit', ['id' => $b['id']])) ?>"><?= e($b['title']) ?></a></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="card"><h2>Опасная зона</h2>
  <button type="submit" class="btn danger" form="delPostForm" data-confirm-btn="Удалить заметку «<?= e($post['title']) ?>»?">Удалить заметку</button>
</div>
<?php  ?>
<input type="hidden" name="form_end" value="1">
</form>
<form method="post" id="delPostForm" action="<?= e(a_url('posts')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$post['id'] ?>"></form>
    <?php
    admin_footer();
}





function admin_post_preview(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        admin_header('Предпросмотр', 'posts');
        echo '<p>Предпросмотр открывается кнопкой из редактора заметки.</p>';
        admin_footer();
        return;
    }
    csrf_check();
    $post = post_get((int)($_GET['id'] ?? 0));
    if (!$post) {
        http_response_code(404);
        admin_header('Не найдено', 'posts');
        echo '<p>Заметка не найдена.</p>';
        admin_footer();
        return;
    }
    $fields = post_collect($post, true);
    session_write_close();
    $id = (int)$post['id'];
    $pdo = db();
    $pdo->beginTransaction();
    try {

        db_run('UPDATE posts SET title=?, slug=?, section=?, excerpt=?, is_published=?, published_at=?, seo_title=?, seo_description=?, og_image_id=?, noindex=?, schema_type=?, jsonld_extra=?, canonical=? WHERE id=?', [
            $fields['title'],
            $fields['slug'],
            $fields['section'],
            $fields['excerpt'],
            $fields['is_published'],
            $fields['published_at'],
            $fields['seo_title'],
            $fields['seo_description'],
            $fields['og_image_id'],
            $fields['noindex'],
            $fields['schema_type'],
            $fields['jsonld_extra'],
            $fields['canonical'],
            $id,
        ]);


        $usedIds = [];
        foreach (post_arr('sec') as $sid => $f) {
            $s = db_one("SELECT * FROM sections WHERE id = ? AND owner_type = 'post' AND page_id = ?", [(int)$sid, $id]);
            if ($s && is_array($f)) {
                $data = section_collect((string)$s['type'], $f);
                if ($s['type'] === 'text') {
                    $data['html'] = blog_html_prepare((string)$data['html'], $usedIds);
                }
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($data), $s['id']]);
            }
        }

        $allowed = [];
        foreach (db_all("SELECT id FROM sections WHERE owner_type = 'post' AND page_id = ? AND type = 'gallery'", [$id]) as $s) {
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
            gallery_replace('section', (int)substr($key, 1), $ids);
        }
        page_sections_cache_flush($id, 'post');

        gallery_cache_flush();
        $fresh = post_get($id);
        $html = render_blog_post($fresh ?: $post);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        page_sections_cache_flush($id, 'post');
        gallery_cache_flush();
    }
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    $style = '<style>.preview-note{position:sticky;top:0;z-index:90;background:#fff8d6;border-bottom:1px solid #e0d28a;padding:10px 16px;font:14px/1.4 sans-serif;text-align:center}</style>';
    $note = '<div class="preview-note">Это предпросмотр несохранённой заметки — её видите только вы. Чтобы опубликовать правки, вернитесь в редактор и нажмите «Сохранить».</div>';
    $html = (string)preg_replace('~</head>~i', $style . '</head>', $html, 1);
    $html = (string)preg_replace('~<body([^>]*)>~i', '<body$1>' . $note, $html, 1);
    echo public_csp_send($html);
}




function section_slug_taken(string $slug): string
{
    if (section_get($slug)) {
        return 'Такой раздел уже есть.';
    }
    if (in_array($slug, reserved_slugs(), true)) {
        return 'Этот адрес занят системой.';
    }
    if (page_by_slug($slug)) {
        return 'Этот адрес занят страницей сайта.';
    }
    return '';
}

function sections_save(array $sections): void
{
    set_setting('post_sections', json_db(array_values($sections)));
}

function admin_sections(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post_str('action');
        if ($a === 'add') {
            $title = post_str('title', 80);
            $slug = slug_normalize(post_str('slug', 80), 40);
            if ($slug === '') {
                $slug = slugify($title, 40);
            }
            if ($title === '') {
                flash('Введите название раздела.', 'err');
            } elseif (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,49}$/', $slug)) {
                flash('Адрес раздела: только латиница, цифры, - и _.', 'err');
            } elseif (($err = section_slug_taken($slug)) !== '') {
                flash($err, 'err');
            } else {
                $sections = post_sections();
                $sections[] = ['slug' => $slug, 'title' => $title, 'description' => post_str('description', 500)];
                sections_save($sections);
                redirect_clear_from('/' . $slug);
                flash('Раздел «' . $title . '» создан: /' . $slug . '. Добавьте его в меню сайта (Меню → «+ Пункт»).');
                redirect(a_url('sections'));
            }
        }
        if ($a === 'delete') {
            $slug = post_str('slug');
            if ($slug === 'blog') {
                flash('Раздел «Блог» удалить нельзя.', 'err');
            } elseif ((int)db_val('SELECT COUNT(*) FROM posts WHERE section = ?', [$slug]) > 0) {
                flash('В разделе есть заметки — сначала перенесите их в другой раздел (в редакторе заметки поле «Раздел»).', 'err');
            } else {
                $sections = array_filter(post_sections(), static fn($s) => $s['slug'] !== $slug);
                sections_save($sections);
                db_run('DELETE FROM redirects WHERE from_path = ?', ['/' . $slug]);
                flash('Раздел удалён.');



                $orphans = db_all('SELECT title FROM menu_items WHERE url = ? OR url = ?', ['/' . $slug, '/' . $slug . '/']);
                if ($orphans) {
                    $names = implode(', ', array_map(static fn($r) => '«' . (string)$r['title'] . '»', $orphans));
                    flash('В меню остались пункты на удалённый раздел: ' . $names . ' — теперь они отдают 404. Поправьте или удалите их в разделе «Меню».', 'err');
                }
                redirect(a_url('sections'));
            }
        }
        redirect(a_url('sections'));
    }

    $sections = post_sections();
    admin_header('Разделы заметок', 'sections');
    ?>
<div class="bar"><h1>Разделы заметок</h1><span class="grow"></span><a class="btn" href="<?= e(a_url('posts')) ?>">К заметкам</a></div>
<p class="muted">У каждого раздела свой адрес и список: <code>/blog</code>, <code>/news</code>, <code>/articles</code>… Заметка живёт в одном разделе;
перенос — в редакторе заметки (поле «Раздел»), со старого адреса автоматически ставится редирект 301. Ссылки между заметками работают между любыми разделами.</p>
<table class="tbl">
<thead><tr><th>Название</th><th>Адрес</th><th>Заметок</th><th></th></tr></thead>
<tbody>
    <?php

        $counts = [];
        foreach (db_all('SELECT section, COUNT(*) AS n FROM posts GROUP BY section') as $r) {
            $counts[(string)$r['section']] = (int)$r['n'];
        }
        foreach ($sections as $s): $cnt = $counts[$s['slug']] ?? 0; ?>
<tr>
  <td><a href="<?= e(a_url('section_edit', ['slug' => $s['slug']])) ?>"><strong><?= e(section_title($s)) ?></strong></a></td>
  <td><a href="<?= e(section_base($s)) ?>" target="_blank" rel="noopener"><?= e(section_base($s)) ?></a></td>
  <td><?= $cnt ?></td>
  <td class="acts">
    <a class="btn sm" href="<?= e(a_url('section_edit', ['slug' => $s['slug']])) ?>">Править</a>
    <a class="btn sm" href="<?= e(a_url('post_new', ['section' => $s['slug']])) ?>">+ Заметка</a>
    <?php if ($s['slug'] !== 'blog'): ?>
    <form method="post" class="inline" data-confirm="Удалить раздел «<?= e(section_title($s)) ?>»?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="slug" value="<?= e($s['slug']) ?>"><button class="btn sm danger">Удалить</button></form>
    <?php endif; ?>
  </td>
</tr>
    <?php endforeach; ?>
</tbody></table>

<div class="card narrow"><h2>Новый раздел</h2>
<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <?= f_input('title', 'Название', '', ['maxlength' => 80, 'help' => 'Например: Новости, Статьи, Советы.']) ?>
  <?= f_input('slug', 'Адрес (необязательно)', '', ['help_html' => 'Латиница, цифры, - и _. Пусто — придумается из названия: «Новости» → <code>novosti</code>.']) ?>
  <button class="btn primary">Создать раздел</button>
</form>
</div>
    <?php
    admin_footer();
}

function admin_section_edit(): void
{
    $slugRaw = $_GET['slug'] ?? '';
    $slug = is_string($slugRaw) ? $slugRaw : '';
    $sec = section_get($slug);
    if (!$sec) {
        http_response_code(404);
        admin_header('Не найдено', 'sections');
        echo '<p>Раздел не найден. <a href="' . e(a_url('sections')) . '">К списку</a></p>';
        admin_footer();
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $title = post_str('title', 80);
        $desc = post_str('description', 500);
        $newSlug = $slug;
        if ($slug !== 'blog') {
            $in = slug_normalize(post_str('slug', 80), 40);
            if ($in !== '' && $in !== $slug) {
                if (!preg_match('/^[a-z0-9][a-z0-9_\-]{0,49}$/', $in)) {
                    flash('Адрес раздела: только латиница, цифры, - и _. Оставлен прежний.', 'err');
                } elseif (($err = section_slug_taken($in)) !== '') {
                    flash($err . ' Оставлен прежний.', 'err');
                } else {
                    $newSlug = $in;
                }
            }
        }
        $sections = post_sections();
        foreach ($sections as &$s) {
            if ($s['slug'] === $slug) {

                $s = $slug === 'blog'
                    ? ['slug' => 'blog', 'title' => '', 'description' => '']
                    : ['slug' => $newSlug, 'title' => $title, 'description' => $desc];
            }
        }
        unset($s);


        $rerr = null;
        db_tx(static function () use ($sections, $slug, $newSlug, $title, $desc, &$rerr): void {
            sections_save($sections);
            if ($slug === 'blog') {
                set_setting('blog_title', $title);
                set_setting('blog_description', $desc);
            }
            if ($newSlug !== $slug) {

                $now = now();
                foreach (db_all('SELECT * FROM posts WHERE section = ?', [$slug]) as $p) {
                    db_run('UPDATE posts SET section = ?, updated_at = ? WHERE id = ?', [$newSlug, $now, (int)$p['id']]);
                    post_links_reindex((int)$p['id'], post_text_html($p));
                    redirect_clear_from('/' . $newSlug . '/' . $p['slug']);
                    if ((int)$p['is_published']) {
                        $rerr = redirect_add('/' . $slug . '/' . $p['slug'], '/' . $newSlug . '/' . $p['slug']) ?? $rerr;
                    }
                }
                redirect_clear_from('/' . $newSlug);
                $rerr = redirect_add('/' . $slug, '/' . $newSlug) ?? $rerr;
            }
        });
        if ($rerr !== null) {
            flash('Часть редиректов не создана: ' . $rerr, 'err');
        }
        if ($newSlug !== $slug) {
            flash('Адрес раздела изменён: /' . $slug . ' → /' . $newSlug . '. Со всех старых адресов настроены редиректы 301.');
        } else {
            flash('Сохранено.');
        }
        redirect(a_url('section_edit', ['slug' => $newSlug]));
    }

    admin_header('Раздел: ' . section_title($sec), 'sections');
    ?>
<div class="bar"><h1><?= e(section_title($sec)) ?> <span class="tag">раздел</span></h1><span class="grow"></span>
  <a class="btn" href="<?= e(section_base($sec)) ?>" target="_blank" rel="noopener">Открыть <?= e(section_base($sec)) ?> ↗</a>
  <a class="btn" href="<?= e(a_url('sections')) ?>">Все разделы</a></div>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <?= f_input('title', 'Название', section_title($sec), ['maxlength' => 80, 'help' => 'Заголовок страницы раздела и пункт хлебных крошек.']) ?>
  <?php if ($sec['slug'] === 'blog'): ?>
    <div class="f"><span class="fl">Адрес</span><div><code>/blog</code> <span class="muted">— у основного раздела адрес не меняется.</span></div></div>
  <?php else: ?>
    <?= f_input('slug', 'Адрес раздела', $sec['slug'], ['help_html' => 'Полный адрес: <code>/' . e($sec['slug']) . '</code>. При смене все заметки раздела переедут, со старых адресов — редиректы 301.']) ?>
  <?php endif; ?>
  <?= f_textarea('description', 'Описание (необязательно)', section_description($sec), ['rows' => 3, 'help' => 'Показывается на странице раздела под заголовком и идёт в её description.']) ?>
  <button class="btn primary">Сохранить</button>
</form>
    <?php
    admin_footer();
}
