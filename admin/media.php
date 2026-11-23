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

function img_json(array $img): array
{
    return [
        'id' => (int)$img['id'],
        'thumb' => thumb_url($img),
        'alt' => (string)$img['alt'],
        'w' => (int)$img['w'],
        'h' => (int)$img['h'],
        'name' => (string)$img['orig_name'],
        'fx' => (float)($img['focus_x'] ?? 0.5),
        'fy' => (float)($img['focus_y'] ?? 0.5),
    ];
}

function admin_upload(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'error' => 'Только POST'], 405);
    }
    csrf_check();
    session_write_close();
    $f = $_FILES['file'] ?? null;
    if (!$f) {
        json_out(['ok' => false, 'error' => 'Файл не получен: вероятно, он больше post_max_size на сервере.'], 400);
    }
    $errs = [
        UPLOAD_ERR_INI_SIZE => 'Файл больше upload_max_filesize в настройках PHP.',
        UPLOAD_ERR_FORM_SIZE => 'Файл слишком большой.',
        UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью, повторите.',
        UPLOAD_ERR_NO_FILE => 'Файл не выбран.',
        UPLOAD_ERR_NO_TMP_DIR => 'На сервере нет временной папки.',
        UPLOAD_ERR_CANT_WRITE => 'Сервер не смог записать файл.',
    ];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        json_out(['ok' => false, 'error' => $errs[(int)$f['error']] ?? 'Ошибка загрузки'], 400);
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) {
        json_out(['ok' => false, 'error' => 'Некорректная загрузка'], 400);
    }


    if ((int)$f['size'] > UPLOAD_MAX_BYTES) {
        json_out(['ok' => false, 'error' => 'Файл больше ' . intdiv(UPLOAD_MAX_BYTES, 1048576) . ' МБ — сожмите фото перед загрузкой.'], 422);
    }
    try {
        $img = image_add_from_file((string)$f['tmp_name'], (string)$f['name']);
        $resp = ['ok' => true, 'image' => img_json($img)];

        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) === 'gif' && gif_is_animated((string)$f['tmp_name'])) {
            $resp['note'] = 'анимированный GIF — на сайте останется только первый кадр';
        }


        if (img_engine() === 'gd' && (string)$img['ext'] === 'jpg' && image_icc_wide_gamut(image_original_path($img))) {
            $resp['note'] = isset($resp['note']) ? $resp['note'] . '; ' : '';
            $resp['note'] .= 'цветовой профиль шире sRGB: без расширения Imagick цвета превью станут тусклее — включите Imagick в панели хостинга или экспортируйте фото в sRGB';
        }
        json_out($resp);
    } catch (PDOException $e) {
        app_log_error('upload: ' . get_class($e) . ': ' . $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine());
        json_out(['ok' => false, 'error' => 'Внутренняя ошибка при обработке фото. Подробности в storage/error.log.'], 500);
    } catch (RuntimeException $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 422);
    } catch (Throwable $e) {

        app_log_error('upload: ' . get_class($e) . ': ' . $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine());
        json_out(['ok' => false, 'error' => 'Внутренняя ошибка при обработке фото. Подробности в storage/error.log.'], 500);
    }
}

function admin_media_json(): void
{
    session_write_close();


    $album = max(0, (int)($_GET['album'] ?? 0));
    $limit = 60;
    if ($album > 0) {


        if (!db_val("SELECT id FROM pages WHERE id = ? AND type = 'album'", [$album])) {
            json_out(['ok' => false, 'error' => 'Альбом не найден'], 404);
        }
        $off = max(0, (int)($_GET['off'] ?? 0));
        $rows = db_all("SELECT i.* FROM gallery_items gi JOIN images i ON i.id = gi.image_id
            WHERE gi.owner_type = 'page' AND gi.owner_id = ?
            ORDER BY gi.position, gi.id LIMIT " . ($limit + 1) . ' OFFSET ' . $off, [$album]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        json_out([
            'items' => array_map('img_json', $rows),
            'total' => (int)db_val("SELECT COUNT(*) FROM gallery_items WHERE owner_type = 'page' AND owner_id = ?", [$album]),
            'next' => $off + count($rows),
            'more' => $more,
        ]);
    }
    $before = max(0, (int)($_GET['before'] ?? 0));
    $rows = $before > 0
        ? db_all('SELECT * FROM images WHERE id < ? ORDER BY id DESC LIMIT ' . ($limit + 1), [$before])
        : db_all('SELECT * FROM images ORDER BY id DESC LIMIT ' . ($limit + 1));
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    json_out([
        'items' => array_map('img_json', $rows),
        'total' => (int)db_val('SELECT COUNT(*) FROM images'),
        'next' => $rows ? (int)$rows[count($rows) - 1]['id'] : 0,
        'more' => $more,
    ]);
}

function admin_image_alt(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false], 405);
    }
    csrf_check();
    session_write_close();
    $id = (int)($_POST['id'] ?? 0);
    if (!image_get($id)) {
        json_out(['ok' => false], 404);
    }
    db_run('UPDATE images SET alt = ? WHERE id = ?', [post_str('alt', 250), $id]);
    image_cache_flush();


    site_touch();
    json_out(['ok' => true]);
}





function admin_image_focus(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false], 405);
    }
    csrf_check();
    session_write_close();
    $id = (int)($_POST['id'] ?? 0);
    if (!image_get($id)) {
        json_out(['ok' => false], 404);
    }
    $fx = (float)str_replace(',', '.', (string)($_POST['fx'] ?? '0.5'));
    $fy = (float)str_replace(',', '.', (string)($_POST['fy'] ?? '0.5'));
    $fx = max(0.0, min(1.0, $fx));
    $fy = max(0.0, min(1.0, $fy));
    $lock = image_lock($id, true);
    if ($lock === null || $lock === false) {
        json_out(['ok' => false, 'error' => 'Не удалось заблокировать фото. Повторите после завершения обработки.'], 503);
    }
    $response = ['ok' => true, 'fx' => $fx, 'fy' => $fy];
    try {
        if (!db_one('SELECT id FROM images WHERE id = ?', [$id])) {
            $response = ['ok' => false, 'error' => 'Фото не найдено.'];
        } else {
            db_run('UPDATE images SET focus_x = ?, focus_y = ? WHERE id = ?', [$fx, $fy, $id]);
            $failed = [];
            $files = array_merge(glob(media_dir() . '/' . $id . '/og-*.jpg') ?: [], glob(media_dir() . '/' . $id . '/og-*.jpg.fail') ?: []);
            foreach ($files as $ogf) {
                if (is_file($ogf) && !@unlink($ogf)) {
                    clearstatcache(true, $ogf);
                    if (is_file($ogf)) {
                        $failed[] = basename($ogf);
                    }
                }
            }
            if ($failed) {
                app_log_error('image_focus: не удалось удалить OG-файлы для фото #' . $id . ': ' . implode(', ', $failed));
                $response['warning'] = 'Точка кадра сохранена, но старые OG-файлы или маркеры ошибок остались. Подробности в storage/error.log.';
            }
            image_cache_flush();
            site_touch();
        }
    } finally {
        image_unlock($lock);
    }
    json_out($response, !empty($response['ok']) ? 200 : 404);
}

function admin_image_delete(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false], 405);
    }
    csrf_check();
    session_write_close();
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && empty($_POST['force'])) {
        $n = (int)(image_usage_map(true)[$id] ?? 0);
        if ($n > 0) {
            json_out(['ok' => false, 'in_use' => $n, 'error' => 'Фото используется на сайте (мест: ' . $n . ').'], 409);
        }
    }
    $warning = image_delete($id);
    $resp = ['ok' => true];
    if ($warning !== '') {
        $resp['warning'] = $warning;
    }
    json_out($resp);
}

function admin_media(): void
{
    $per = 60;
    $noalt = !empty($_GET['noalt']);

    $qRaw = $_GET['q'] ?? '';
    $q = trim(is_string($qRaw) ? $qRaw : '');
    $cond = [];
    $args = [];
    if ($noalt) {
        $cond[] = "alt = ''";
    }
    if ($q !== '') {
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $cond[] = "(orig_name LIKE ? ESCAPE '\\' OR alt LIKE ? ESCAPE '\\')";
        $args[] = '%' . $esc . '%';
        $args[] = '%' . $esc . '%';
    }
    $where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';


    $albumRaw = (int)($_GET['album'] ?? 0);
    $album = $albumRaw > 0 ? $albumRaw : 0;
    $noalbum = $albumRaw === -1 || !empty($_GET['noalbum']);
    $albumRow = $album > 0 ? db_one("SELECT id, title FROM pages WHERE id = ? AND type = 'album'", [$album]) : null;
    if ($album > 0 && !$albumRow) {
        $album = 0;
    }
    if ($album > 0) {

        $joinWhere = ($where !== '' ? $where . ' AND' : 'WHERE') . " gi.owner_type = 'page' AND gi.owner_id = " . $album;
        $total = (int)db_val("SELECT COUNT(*) FROM gallery_items gi JOIN images i ON i.id = gi.image_id $joinWhere", $args);
        $pages = max(1, (int)ceil($total / $per));
        $pg = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $imgs = db_all("SELECT i.* FROM gallery_items gi JOIN images i ON i.id = gi.image_id $joinWhere ORDER BY gi.position, gi.id LIMIT $per OFFSET " . (($pg - 1) * $per), $args);
    } else {
        if ($noalbum) {
            $where .= ($where !== '' ? ' AND' : 'WHERE') . " id NOT IN (SELECT image_id FROM gallery_items WHERE owner_type = 'page')";
        }
        $total = (int)db_val("SELECT COUNT(*) FROM images $where", $args);
        $pages = max(1, (int)ceil($total / $per));
        $pg = min($pages, max(1, (int)($_GET['page'] ?? 1)));
        $imgs = db_all("SELECT i.* FROM images i $where ORDER BY id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $args);
    }
    $use = image_usage_map();
    foreach ($imgs as &$im) {
        $im['uses'] = (int)($use[(int)$im['id']] ?? 0);
    }
    unset($im);
    $noAltTotal = (int)db_val("SELECT COUNT(*) FROM images WHERE alt = ''");
    $albums = albums_with_counts();

    $flt = static function (array $extra = []) use ($q, $noalt, $album, $noalbum): array {
        return array_filter(array_merge([
            'q' => $q !== '' ? $q : null,
            'noalt' => $noalt ? 1 : null,
            'album' => $album > 0 ? $album : null,
            'noalbum' => $noalbum ? 1 : null,
        ], $extra), static fn($v): bool => $v !== null && $v !== '');
    };

    admin_header('Медиа', 'media');
    ?>
<div class="bar"><h1>Медиатека <span class="muted" id="mediaTotal">(<?= $total ?>)</span></h1><span class="grow"></span>
  <?php if ($albumRow): ?><a class="btn" href="<?= e(a_url('page_edit', ['id' => (int)$albumRow['id']])) ?>" title="Порядок фото, обложка и разделы альбома">Открыть альбом «<?= e($albumRow['title']) ?>»</a><?php endif; ?>
  <a class="btn <?= $noalt ? 'primary' : '' ?>" id="noaltLink" href="<?= e(a_url('media', $flt(['noalt' => $noalt ? null : 1]))) ?>"><?= $noalt ? 'Показать все' : 'Без alt: ' . $noAltTotal ?></a>
  <button class="btn danger" type="button" id="delSel" hidden>Удалить выбранные</button>
  <button class="btn primary" type="button" data-upload-lib>+ Загрузить фото</button></div>
<form method="get" class="filterline" action="">
  <input type="hidden" name="p" value="media">
  <?= $noalt ? '<input type="hidden" name="noalt" value="1">' : '' ?>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по имени файла или alt — по всей медиатеке…">
  <?php if ($albums): ?>
  <select name="album" data-auto-submit title="Показать фото одного альбома в его порядке">
    <option value="">Все фото</option>
    <?php foreach ($albums as $ab): ?>
    <option value="<?= (int)$ab['id'] ?>" <?= $album === (int)$ab['id'] ? 'selected' : '' ?>>Альбом: <?= e($ab['title']) ?> (<?= (int)$ab['photos'] ?>)</option>
    <?php endforeach; ?>
    <option value="-1" <?= $noalbum ? 'selected' : '' ?>>Не в альбомах</option>
  </select>
  <?php endif; ?>
  <button class="btn sm" type="submit">Найти</button>
  <?php if ($q !== '' || $album > 0 || $noalbum): ?><a class="btn sm" href="<?= e(a_url('media', $noalt ? ['noalt' => 1] : [])) ?>">Сбросить</a><?php endif; ?>
</form>
<?php if ($albumRow): ?>
<p class="muted">Альбом «<?= e($albumRow['title']) ?>» — фото в порядке альбома. Обложка, порядок и разделы под галереей — в <a href="<?= e(a_url('page_edit', ['id' => (int)$albumRow['id']])) ?>">редакторе альбома</a>. Подписи (alt) правятся прямо здесь.</p>
<?php endif; ?>
<p class="muted">Все файлы хранятся на вашем сервере и отдаются с вашего домена. Текст <strong>alt</strong> описывает фото для поисковиков и незрячих пользователей — заполняйте коротко и по делу (например, «Жених и невеста у озера в усадьбе Орехово»).</p>
<div class="dropzone" id="libDrop">Перетащите фотографии сюда или нажмите «Загрузить фото»<div class="upl-status" id="uplStatus"></div></div>
<div class="media-grid" id="mediaGrid">
    <?php foreach ($imgs as $img): ?>
    <?php $largeW = image_width_allowed($img, 1240) ? 1240 : (int)max(image_widths($img, 1240)); ?>
  <div class="mi" data-id="<?= (int)$img['id'] ?>" data-uses="<?= (int)$img['uses'] ?>"
       data-fx="<?= e((string)(float)($img['focus_x'] ?? 0.5)) ?>" data-fy="<?= e((string)(float)($img['focus_y'] ?? 0.5)) ?>"
       data-large="<?= e(image_url($img, $largeW, 'jpg')) ?>">
    <?php $fcs = image_focus_style($img); ?>
    <img src="<?= e(thumb_url($img)) ?>" alt="" loading="lazy" style="background:<?= e(safe_color((string)$img['color'])) ?><?= $fcs !== '' ? ';' . $fcs : '' ?>">
    <input class="mi-alt" value="<?= e($img['alt']) ?>" placeholder="alt — кратко, что изображено на фото" maxlength="250">
    <label class="mi-sel"><input type="checkbox" class="mi-cb"> выбрать</label>
    <div class="mi-meta"><span><?= (int)$img['w'] ?>×<?= (int)$img['h'] ?></span><span><?= (int)$img['uses'] ? 'исп.: ' . (int)$img['uses'] : 'не используется' ?></span></div>
    <div class="mi-actions">
      <button type="button" class="btn sm" data-focus title="Точка, вокруг которой кадрируются обложки и миниатюры этого фото">Точка кадра</button>
      <a class="btn sm" href="/dl.php?id=<?= (int)$img['id'] ?>" title="Скачать исходный файл в полном разрешении (оригиналы закрыты от посетителей)">Оригинал</a>
      <button type="button" class="btn sm danger" data-del>удалить</button>
    </div>
  </div>
    <?php endforeach; ?>
    <?php if (!$imgs): ?><p class="muted"><?= $q !== '' ? 'По запросу «' . e($q) . '» ничего не нашлось.' : ($albumRow ? 'В этом альбоме пока нет фото — добавьте их в редакторе альбома.' : ($noalbum ? 'Таких фото нет: всё разложено по альбомам.' : 'Фото пока нет — загрузите первое.')) ?></p><?php endif; ?>
</div>
    <?php if ($pages > 1):



        $pFrom = max(1, $pg - 5);
        $pTo = min($pages, $pg + 5);
        $pageLink = static fn(int $i): string => e(a_url('media', $flt(['page' => $i])));
    ?>
<div class="pager">
        <?php if ($pFrom > 1): ?>
  <a href="<?= $pageLink(1) ?>">1</a><?= $pFrom > 2 ? '<span class="muted">…</span>' : '' ?>
        <?php endif; ?>
        <?php for ($i = $pFrom; $i <= $pTo; $i++): ?>
  <a class="<?= $i === $pg ? 'on' : '' ?>" href="<?= $pageLink($i) ?>"><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($pTo < $pages): ?>
  <?= $pTo < $pages - 1 ? '<span class="muted">…</span>' : '' ?><a href="<?= $pageLink($pages) ?>"><?= $pages ?></a>
        <?php endif; ?>
</div>
    <?php endif; ?>
    <?php
    admin_footer();
}
