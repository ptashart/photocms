<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

if (!defined('ROOT')) { // файл включается только из admin/index.php — прямой вызов запрещён
    http_response_code(404);
    exit;
}

/* Видеотека (1.9.48): ролики MP4 для секции «Видео» на страницах. Файлы лежат в
   media/video/<id>-<hex>.mp4 и отдаются веб-сервером как статика (как варианты фото). */

/**
 * Приём MP4 из формы. Сначала INSERT с пустым file (получаем id), потом файл
 * в media/video/<id>-<hex>.mp4 и UPDATE: «сирот» в базе не остаётся.
 */
function video_upload(): void
{
    session_write_close(); // приём файла долгий — не держим блокировку сессии (как в admin_upload)
    $f = $_FILES['file'] ?? null;
    if (!is_array($f)) {
        flash('Файл не получен: вероятно, он больше post_max_size в настройках PHP сервера.', 'err');
        return;
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
        flash($errs[(int)$f['error']] ?? 'Ошибка загрузки', 'err');
        return;
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) {
        flash('Некорректная загрузка.', 'err');
        return;
    }
    /* серверный потолок: ini-лимиты хостинга могут быть шире, а многогигабайтный файл
       съест диск ещё до всяких проверок */
    if ((int)$f['size'] > 1073741824) {
        flash('Файл больше 1 ГБ — сожмите видео (например, в H.264 с битрейтом ниже) и загрузите снова.', 'err');
        return;
    }
    /* mp4-контейнер: ищем бокс ftyp, идя по структуре боксов — перед ним легитимно бывают
       free/wide/skip (например, после прохода через некоторые конвертеры), и проверка строго
       по смещению 4 отклоняла валидные файлы (аудит 1.9.48, п.10). Доверяем подписи,
       а не имени и MIME от браузера. */
    $head = (string)@file_get_contents((string)$f['tmp_name'], false, null, 0, 4096);
    $fileSize = (int)@filesize((string)$f['tmp_name']);
    $isMp4 = false;
    $off = 0;
    $headLen = strlen($head);
    while ($off + 8 <= $headLen) {
        $boxSize = (int)(unpack('N', substr($head, $off, 4)) ?: [1 => 0])[1]; // unpack нумерует с 1
        if ($boxSize < 8 || $boxSize > $fileSize - $off) {
            break;
        }
        if (substr($head, $off + 4, 4) === 'ftyp') {
            /* major_brand и minor_version занимают 8 байт после заголовка; бренд — четыре печатных ASCII-символа */
            $isMp4 = $boxSize >= 16 && $off + 16 <= $headLen
                && preg_match('/^[\x20-\x7E]{4}$/D', substr($head, $off + 8, 4)) === 1;
            break;
        }
        if ($boxSize > $headLen - $off) { // следующий бокс уже за пределами прочитанного буфера
            break;
        }
        $off += $boxSize;
    }
    if (!$isMp4
        || strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'mp4') {
        flash('Это не файл MP4. Загрузите видео в формате MP4 (H.264) — его проигрывают все браузеры.', 'err');
        return;
    }
    $id = 0;
    $name = '';
    try {
        $id = db_insert(
            'INSERT INTO videos (file, title, created_at) VALUES (?,?,?)',
            ['', clip(pathinfo((string)$f['name'], PATHINFO_FILENAME), 200), date('c')]
        );
        $dir = media_dir() . '/video';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        /* случайный суффикс — как у slug фото: файл отдаётся напрямую веб-сервером,
           предсказуемое имя делало бы ролик угадываемым перебором */
        $name = $id . '-' . bin2hex(random_bytes(16)) . '.mp4';
        if (!@move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('move_uploaded_file не смог записать media/video/' . $name);
        }
        db_run('UPDATE videos SET file = ? WHERE id = ?', [$name, $id]);
    } catch (Throwable $e) {
        /* ручной откат: при сбое между INSERT и UPDATE в базе оставалась строка с пустым
           file, а на диске — файл-сирота, которого никто не чистит (аудит 1.9.48, п.10) */
        if ($id > 0) {
            try {
                db_run('DELETE FROM videos WHERE id = ?', [$id]);
            } catch (Throwable $ignore) {
            }
        }
        if ($name !== '') {
            @unlink(media_dir() . '/video/' . $name);
        }
        app_log_error('video upload: ' . get_class($e) . ': ' . $e->getMessage());
        flash('Сервер не смог сохранить файл — проверьте права на запись в папку media/. Подробности в storage/error.log.', 'err');
        return;
    }
    site_touch();
    flash('Видео загружено. Задайте название и обложку — и вставьте ролик на страницу разделом «Видео».');
}

function video_save_meta(int $id): void
{
    if (!video_get($id)) {
        flash('Видео не найдено.', 'err');
        return;
    }
    $poster = (int)($_POST['poster_image_id'] ?? 0);
    if ($poster > 0 && !image_get($poster)) {
        $poster = 0; // чужой/несуществующий id не принимаем
    }
    db_run('UPDATE videos SET title = ?, description = ?, duration = ?, poster_image_id = ? WHERE id = ?', [
        post_str('title', 200),
        post_str('description', 500),
        max(0, min(86400, (int)($_POST['duration'] ?? 0))),
        $poster > 0 ? $poster : null,
        $id,
    ]);
    /* название/обложка видны на публичных страницах и в JSON-LD — а прямой db_run site_rev не бампает */
    site_touch();
    flash('Сохранено.');
}

function video_delete(int $id): void
{
    $v = video_get($id);
    if (!$v) {
        return;
    }
    db_tx(static function () use ($id): void {
        /* разделы «Видео», ссылавшиеся на ролик, обнуляем — как image_delete обнуляет фото-секции */
        foreach (db_all("SELECT id, data FROM sections WHERE type = 'video'") as $r) {
            $d = json_decode((string)$r['data'], true);
            if (is_array($d) && (int)($d['video_id'] ?? 0) === $id) {
                $d['video_id'] = 0;
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($d), (int)$r['id']]);
            }
        }
        db_run('DELETE FROM videos WHERE id = ?', [$id]);
    });
    /* файл стираем только после того, как запись из базы действительно удалена */
    $f = basename((string)$v['file']);
    if ($f !== '') {
        @unlink(media_dir() . '/video/' . $f);
    }
    page_sections_cache_flush(); // секции разных страниц — сбрасываем кэш целиком (как image_delete)
    site_touch();
    flash('Видео удалено.');
}

function admin_videos(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $aRaw = $_POST['action'] ?? '';
        $a = is_string($aRaw) ? $aRaw : ''; // ?action[]=x — иначе «Array to string conversion» в журнал
        if ($a === 'upload') {
            video_upload();
        } elseif ($a === 'save') {
            video_save_meta((int)($_POST['id'] ?? 0));
        } elseif ($a === 'delete') {
            video_delete((int)($_POST['id'] ?? 0));
        }
        redirect(a_url('videos'));
    }

    $videos = db_all('SELECT * FROM videos ORDER BY id DESC');
    /* лимит загрузки — меньший из ini-лимитов PHP; 0/мусор означают «не задано» */
    $limU = image_parse_size((string)ini_get('upload_max_filesize'));
    $limP = image_parse_size((string)ini_get('post_max_size'));
    $lim = min($limU > 0 ? $limU : PHP_INT_MAX, $limP > 0 ? $limP : PHP_INT_MAX);

    admin_header('Видео', 'videos');
    ?>
<div class="bar"><h1>Видео</h1><span class="grow"></span></div>
<p class="muted">Ролики MP4 для раздела «Видео» на страницах. Хранятся на вашем сервере (media/video/) и отдаются с вашего домена — как фотографии.</p>
<div class="card narrow">
  <h2>Загрузить видео</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <label class="f"><span class="fl">Файл MP4</span><input type="file" name="file" accept="video/mp4,.mp4" required>
      <small>Только MP4 (H.264) — его проигрывают все браузеры.<?= $lim !== PHP_INT_MAX ? ' Лимит сервера: ' . e(fmt_mb($lim)) . ' (upload_max_filesize/post_max_size в настройках PHP).' : '' ?></small></label>
    <button class="btn primary">Загрузить</button>
  </form>
</div>
    <?php foreach ($videos as $v):
        $size = is_file(media_dir() . '/video/' . basename((string)$v['file'])) ? (int)filesize(media_dir() . '/video/' . basename((string)$v['file'])) : 0;
    ?>
<div class="card">
  <div class="row" style="align-items:flex-start;gap:22px;flex-wrap:wrap">
    <div style="flex:0 1 240px;min-width:200px">
      <video src="<?= e(video_url($v)) ?>" controls preload="metadata" style="width:100%;background:#000"></video>
      <small class="muted"><?= e(basename((string)$v['file'])) ?><?= $size > 0 ? ' · ' . e(fmt_mb($size)) : '' ?></small>
    </div>
    <form method="post" style="flex:1;min-width:260px">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
      <?= f_input('title', 'Название', $v['title'], ['maxlength' => 200, 'help' => 'Видно под роликом на сайте и в микроразметке VideoObject.']) ?>
      <?= f_textarea('description', 'Описание (необязательно)', $v['description'], ['rows' => 2, 'help' => 'Уходит в микроразметку VideoObject — на сайте не показывается.']) ?>
      <?= f_input('duration', 'Длительность, секунд', (int)$v['duration'] > 0 ? (int)$v['duration'] : '', ['type' => 'number', 'min' => 0, 'help' => 'Для микроразметки VideoObject. Пусто — не указывается.']) ?>
      <?= f_image('poster_image_id', 'Обложка (постер)', $v['poster_image_id'], 'Кадр до начала воспроизведения; задаёт и пропорции плеера.') ?>
      <button class="btn primary">Сохранить</button>
    </form>
    <form method="post" class="inline" data-confirm="Удалить видео «<?= e(trim((string)$v['title']) !== '' ? $v['title'] : '#' . (int)$v['id']) ?>»? Файл будет стёрт с сервера, разделы «Видео» с ним опустеют.">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
      <button class="btn sm danger">Удалить</button>
    </form>
  </div>
</div>
    <?php endforeach; ?>
    <?php if (!$videos): ?><p class="muted">Видео пока нет — загрузите первое.</p><?php endif; ?>
    <?php
    admin_footer();
}
