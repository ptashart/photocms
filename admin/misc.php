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

/** Размеры кэшей для карточки «Кэш» на Панели (1.9.29):
    XML-кэш — плоские файлы в storage/cache (sitemap.xml, feed.xml);
    миниатюры — всё содержимое media/{id}/ (варианты + метки .fail), оригиналы лежат отдельно. */
function admin_cache_stats(bool $fresh = false): array
{
    $st = ['xml_files' => 0, 'xml_bytes' => 0, 'media_files' => 0, 'media_bytes' => 0];
    $xmlFiles = glob(ROOT . '/storage/cache/*') ?: [];
    foreach ($xmlFiles as $f) {
        if (is_file($f)) {
            $st['xml_files']++;
            $st['xml_bytes'] += (int)@filesize($f);
        }
    }
    /* Обход всех миниатюр RecursiveIterator'ом на большой медиатеке занимал секунды при КАЖДОМ
       открытии Панели (ревизия 1.9.34, п.8). Подсчёт media/ кэшируем на сутки в storage/cache-stats.json;
       принудительный пересчёт — кнопка «Пересчитать» рядом с цифрами (приходит $fresh=true).
       XML-часть считается живьём: там плоская папка, glob дешёвый. */
    $cf = ROOT . '/storage/cache-stats.json';
    if (!$fresh && is_file($cf) && time() - (int)@filemtime($cf) < 86400) {
        $c = json_decode((string)@file_get_contents($cf), true);
        if (is_array($c) && isset($c['media_files'], $c['media_bytes'])) {
            $st['media_files'] = (int)$c['media_files'];
            $st['media_bytes'] = (int)$c['media_bytes'];
            $st['media_cached_at'] = (int)@filemtime($cf);
            return $st;
        }
    }
    $media = media_dir();
    $idDirs = scandir($media) ?: [];
    foreach ($idDirs as $idDir) {
        if (!preg_match('~^\d+$~', $idDir) || !is_dir($media . '/' . $idDir)) {
            continue; // .htaccess и .keep — не трогаем
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($media . '/' . $idDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $st['media_files']++;
                $st['media_bytes'] += (int)$f->getSize();
            }
        }
    }
    @file_put_contents($cf, json_encode(['media_files' => $st['media_files'], 'media_bytes' => $st['media_bytes']]), LOCK_EX);
    $st['media_cached_at'] = time();
    return $st;
}

/** Очистка кэша страниц: XML (sitemap/feed пересоберутся при следующем обращении) и готовый
    HTML для анонимов (storage/cache/pages/, 1.9.48 — перегенерируется при следующем хите).
    Возвращает число удалённых файлов. */
function admin_cache_clear_xml(): int
{
    $n = 0;
    $xmlFiles = glob(ROOT . '/storage/cache/*') ?: [];
    foreach ($xmlFiles as $f) {
        if (is_file($f) && @unlink($f)) {
            $n++;
        }
    }
    foreach (glob(ROOT . '/storage/cache/pages/*') ?: [] as $f) { // HTML-кэш — в подкаталоге pages/
        if (is_file($f) && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}

/** Сброс готовых миниатюр: удаляем media/{id}/ целиком (варианты + метки .fail негативного кэша).
    .htaccess и .keep в корне media/ остаются; оригиналы в storage/originals/ не затрагиваются.
    Каждый вариант перегенерируется img.php при первом запросе. Возвращает число удалённых файлов. */
function admin_cache_clear_media(): int
{
    $st = admin_cache_stats(true); // точное «сколько удалено» — считаем живьём, а не по суточному кэшу
    $media = media_dir();
    $idDirs = scandir($media) ?: [];
    foreach ($idDirs as $idDir) {
        if (preg_match('~^\d+$~', $idDir) && is_dir($media . '/' . $idDir)) {
            rrmdir($media . '/' . $idDir);
        }
    }
    /* после сброса кэш подсчёта протух: удаляем — следующий заход на Панель пересчитает
       (media/ почти пуста, обход мгновенный) */
    @unlink(ROOT . '/storage/cache-stats.json');
    return $st['media_files'];
}

/** Прогрев миниатюр пачками из карточки «Кэш» (1.9.48): после «Сбросить миниатюры» первый
    посетитель большого альбома упирался в лимит генераций img.php (300/час на подсеть),
    и часть кадров получала 503 (аудит 1.9.40, п.20). Здесь генерирует сама админка —
    мимо публичного лимита, тем же набором размеров/форматов, что прегенерация при загрузке.
    JS вызывает пачками по 3 фото: запрос не упирается в max_execution_time шаред-хостинга. */
function admin_thumbs_warm(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { // как у соседних AJAX-эндпоинтов: GET не должен ничего делать (аудит 1.9.48, п.12)
        json_out(['ok' => false], 405);
    }
    csrf_check();
    session_write_close(); // прогрев длинный — не держим блокировку сессии, как у backup/upload
    header('Content-Type: application/json; charset=utf-8');
    $offset = max(0, (int)($_POST['offset'] ?? 0));
    $limit = 3;
    $total = (int)db_val('SELECT COUNT(*) FROM images');
    $rows = db_all('SELECT * FROM images ORDER BY id LIMIT ' . $limit . ' OFFSET ' . $offset);
    $canWebp = img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported();
    $canAvif = avif_enabled();
    $errors = 0;
    foreach ($rows as $img) {
        try {
            foreach ([1240, 440] as $w) { // 1240 первым: мелкие варианты Imagick делает из него (1.9.40, п.10)
                /* false = вариант не записался (диск/права): считаем ошибкой, иначе
                   прогрев отвечал errors:0 при фактически отсутствующих файлах (аудит 1.9.94) */
                if (!image_make_variant($img, $w, 'jpg')) {
                    $errors++;
                    app_log_error('thumbs_warm image #' . (int)$img['id'] . ": jpg $w not written");
                }
                if ($canWebp && !image_make_variant($img, $w, 'webp')) {
                    $errors++;
                    app_log_error('thumbs_warm image #' . (int)$img['id'] . ": webp $w not written");
                }
                if ($canAvif && !image_make_variant($img, $w, 'avif')) {
                    $errors++;
                    app_log_error('thumbs_warm image #' . (int)$img['id'] . ": avif $w not written");
                }
            }
        } catch (Throwable $e) {
            $errors++;
            app_log_error('thumbs_warm image #' . (int)$img['id'] . ': ' . $e->getMessage());
        }
    }
    $processed = $offset + count($rows);
    echo json_encode(['ok' => true, 'processed' => $processed, 'total' => $total, 'errors' => $errors, 'done' => $processed >= $total]);
    exit;
}

function admin_dashboard(): void
{
    // самовосстановление .htaccess: файл часто теряется при загрузке по FTP, и без него 404-ят блог/альбомы/sitemap
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fix_htaccess') {
        csrf_check();
        /* вкладка могла устареть: .htaccess уже появился и актуален (например, его загрузили
           по FTP) — не затираем его эталоном. А вот если он устарел для текущего admin_path
           (1.9.27) — перезапись и нужна. */
        if (!htaccess_missing() && !htaccess_needs_update()) {
            flash('Файл .htaccess уже на месте — ничего менять не нужно.');
            redirect(a_url('dashboard'));
        }
        $err = htaccess_fix();
        if ($err === null) {
            flash('Файл .htaccess создан. Проверьте: откройте /blog или /sitemap.xml — теперь должны работать.');
        } else {
            flash($err, 'err');
        }
        redirect(a_url('dashboard'));
    }
    // отключение инсталлятора из админки: install.php больше не нужен и не должен оставаться публичным
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_install') {
        csrf_check();
        $inst = ROOT . '/install.php';
        if (!is_file($inst)) {
            flash('Инсталлятор уже отключён.');
        } elseif (@rename($inst, $inst . '.off')) {
            flash('Инсталлятор отключён (install.php переименован в install.php.off). Вернуть можно по FTP.');
        } else {
            flash('Не удалось переименовать install.php — удалите или переименуйте его по FTP вручную.', 'err');
        }
        redirect(a_url('dashboard'));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup') {
        csrf_check();
        session_write_close();
        admin_send_backup(); // отдаёт файл и завершает скрипт
    }
    // скачивание автоматической копии из storage/backups/ (только чтение — поэтому GET)
    if (isset($_GET['dlbackup']) && is_string($_GET['dlbackup'])) { // ?dlbackup[]=x — иначе «Array to string conversion» в журнал
        session_write_close();
        admin_send_autobackup($_GET['dlbackup']); // отдаёт файл или редиректит с ошибкой
    }
    // проверка целостности базы по кнопке («Здоровье базы»)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dbcheck') {
        csrf_check();
        admin_db_check(); // флешит результат
        redirect(a_url('dashboard'));
    }
    // очистка кэшей по кнопке («Кэш», 1.9.29)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_cache') {
        csrf_check();
        $whatRaw = $_POST['what'] ?? '';
        $what = is_string($whatRaw) ? $whatRaw : ''; // ?what[]=x — иначе warning в журнал
        if ($what === 'xml') {
            flash('Кэш страниц очищен (' . admin_cache_clear_xml() . ' файлов). sitemap.xml, feed.xml и готовый HTML пересоберутся при следующем обращении.');
        } elseif ($what === 'media') {
            $n = admin_cache_clear_media();
            site_touch(); // URL вариантов получают ?v=filemtime: HTML-кэш должен перечитать их (аудит 1.9.94)
            flash('Миниатюры сброшены (' . $n . ' файлов). Нажмите «Прогреть миниатюры» — CMS создаст основные размеры заранее; иначе первые просмотры страниц будут медленными, а у большого альбома часть кадров упиралась бы в лимит генераций (аудит 1.9.40, п.20).');
        } else {
            flash('Не указано, что очищать.', 'err');
        }
        redirect(a_url('dashboard'));
    }
    // принудительный пересчёт размера media/ для карточки «Кэш» (суточный кэш подсчёта, 1.9.35)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cache_stats_refresh') {
        csrf_check();
        $st = admin_cache_stats(true);
        flash('Пересчитано: миниатюр ' . (int)$st['media_files'] . ', ' . fmt_mb((int)$st['media_bytes']) . '.');
        redirect(a_url('dashboard'));
    }

    $nPages = (int)db_val("SELECT COUNT(*) FROM pages WHERE type = 'page'");
    $nAlbums = (int)db_val("SELECT COUNT(*) FROM pages WHERE type = 'album'");
    $nPosts = (int)db_val('SELECT COUNT(*) FROM posts');
    $nDraft = (int)db_val('SELECT COUNT(*) FROM pages WHERE is_published = 0') + (int)db_val('SELECT COUNT(*) FROM posts WHERE is_published = 0');
    $nPostsPub = (int)db_val('SELECT COUNT(*) FROM posts WHERE is_published = 1');
    $nImgs = (int)db_val('SELECT COUNT(*) FROM images');
    $noAlt = (int)db_val("SELECT COUNT(*) FROM images WHERE alt = ''");
    // флагаем только страницы, у которых описание не соберётся автоматически (как seo_description())
    $noDesc = [];
    $noDescPages = db_all("SELECT id, type, title, body_html FROM pages WHERE is_published = 1 AND noindex = 0 AND seo_description = '' AND short_text = '' ORDER BY position, id");
    /* первый НЕПУСТОЙ текстовый блок для всех кандидатов — ОДНИМ запросом: раньше
       first_text_excerpt() на каждую страницу делала page_sections() с отдельным SELECT
       (ревизия 1.9.34, п.8). Пустые блоки пропускаем — точь-в-точь как first_text_excerpt(). */
    $firstText = [];
    foreach (db_all("SELECT page_id, data FROM sections WHERE owner_type = 'page' AND type = 'text' ORDER BY position, id") as $s) {
        $pid = (int)$s['page_id'];
        if (isset($firstText[$pid])) {
            continue;
        }
        $d = json_decode((string)$s['data'], true);
        $t = trim(strip_tags((string)($d['html'] ?? '')));
        if ($t !== '') {
            $firstText[$pid] = $t;
        }
    }
    foreach ($noDescPages as $p) {
        if ($p['type'] === 'album' && trim(strip_tags((string)$p['body_html'])) !== '') {
            continue;
        }
        if ($p['type'] !== 'album' && ($firstText[(int)$p['id']] ?? '') !== '') {
            continue;
        }
        $noDesc[] = $p;
    }

    $todo = [];
    if (trim(setting('base_url')) === '') {
        $todo[] = ['Не указан адрес сайта (canonical, sitemap и превью ссылок будут строиться по текущему домену).', a_url('settings', ['tab' => 'Основное'])];
    } elseif (stripos(setting('base_url'), 'https://') !== 0) {
        $todo[] = ['Адрес сайта указан без https:// — включите HTTPS на хостинге и обновите адрес.', a_url('settings', ['tab' => 'Основное'])];
    }
    if (setting('favicon_image_id') === '') {
        $todo[] = ['Не задана иконка сайта (favicon).', a_url('settings', ['tab' => 'SEO'])];
    }
    if (setting('default_og_image_id') === '') {
        $todo[] = ['Нет картинки для превью ссылок по умолчанию.', a_url('settings', ['tab' => 'SEO'])];
    }
    if (setting('verify_yandex') === '' && setting('verify_google') === '') {
        $todo[] = ['Сайт не подтверждён в Яндекс.Вебмастере / Google Search Console (вставьте коды в настройках SEO).', a_url('settings', ['tab' => 'SEO'])];
    }
    if (trim(setting('city')) === '' || trim(setting('phone')) === '') {
        $todo[] = ['Для локального SEO заполните город и телефон.', a_url('settings', ['tab' => 'Организация (разметка)'])];
    }
    if ($noAlt > 0) {
        $todo[] = [$noAlt . ' фото без alt-текста.', a_url('media', ['noalt' => 1])];
    }
    // разделы заметок с публикациями, на которые нет ссылки из меню: посетитель и поисковик их не найдут
    $menuUrls = array_map(static fn($r) => rtrim((string)$r['url'], '/'), db_all("SELECT url FROM menu_items WHERE url <> ''"));
    /* одним GROUP BY вместо COUNT на каждый раздел: при паре десятков разделов дашборд
       делал столько же лишних запросов (аудит 1.9.26) */
    $secCounts = [];
    foreach (db_all('SELECT section, COUNT(*) AS c FROM posts WHERE is_published = 1 GROUP BY section') as $r) {
        $secCounts[(string)$r['section']] = (int)$r['c'];
    }
    foreach (post_sections() as $s) {
        $cnt = (int)($secCounts[(string)$s['slug']] ?? 0);
        if ($cnt > 0 && !in_array(section_base($s), $menuUrls, true)) {
            $todo[] = ['Раздел «' . section_title($s) . '» (' . section_base($s) . ') не добавлен в меню сайта — добавьте пункт с этим адресом.', a_url('menu')];
        }
    }
    /* с 1.9.54 тексты заметок живут в секциях: отбираем кандидатов без description,
       подгружаем их секции одним запросом и считаем слова по текстовым секциям */
    $thinPosts = db_all("SELECT id, title FROM posts WHERE is_published = 1 AND noindex = 0 AND seo_description = '' ORDER BY id");
    if ($thinPosts) {
        sections_prime('post', array_map(static fn($r) => (int)$r['id'], $thinPosts));
        foreach ($thinPosts as $pp) {
            if (post_words($pp) < 120) {
                $todo[] = ['Заметка «' . $pp['title'] . '» очень короткая (меньше 120 слов) — поисковики плохо ранжируют такие тексты.', a_url('post_edit', ['id' => $pp['id']])];
            }
        }
    }
    foreach ($noDesc as $p) {
        $todo[] = ['У страницы «' . $p['title'] . '» нет описания (description).', a_url('page_edit', ['id' => $p['id'], 'tab' => 'seo'])];
    }

    /* раз в сутки при первом заходе в админку — автоматический снимок базы (см. карточку
       «Здоровье базы»). Заодно — уборка временных файлов-«сирот» загрузок/генераций
       (img_tmp_gc, ревизия 1.9.34, п.16). Ошибки не должны ломать панель: только пишем в журнал */
    try {
        admin_db_autobackup();
        $gcStamp = ROOT . '/storage/locks/tmp-gc.last';
        if (!is_file($gcStamp) || (int)@filemtime($gcStamp) < time() - 86400) {
            @touch($gcStamp);
            @chmod($gcStamp, 0600);
            img_tmp_gc();
        }
    } catch (Throwable $e) {
        app_log_error('autobackup: ' . $e->getMessage());
    }
    $dbSize = is_file(db_path()) ? (int)filesize(db_path()) : 0;
    $walSize = is_file(db_path() . '-wal') ? (int)filesize(db_path() . '-wal') : 0;
    $autoCopies = admin_db_autobackup_list();
    $cacheStat = admin_cache_stats(); // карточка «Кэш» (1.9.29)

    admin_header('Панель', 'dashboard');
    ?>
<h1>Панель</h1>
<?php if (htaccess_missing()): ?>
<div class="card" style="border-color:#c0392b">
  <h2 style="color:#c0392e">⚠ Не найден файл .htaccess</h2>
  <p>Из-за этого не работают блог, альбомы, sitemap.xml и другие «красивые» адреса (ошибка 404).
     Файл часто теряется при загрузке по FTP, потому что он скрытый (начинается с точки).</p>
  <form method="post" style="display:inline"><?= csrf_field() ?>
    <input type="hidden" name="action" value="fix_htaccess">
    <button class="btn primary">Создать .htaccess автоматически</button>
  </form>
  <p style="margin-top:10px"><small>Если после этого адреса всё равно не работают — спросите у хостинга,
     включены ли mod_rewrite и AllowOverride для вашего сайта.</small></p>
</div>
<?php endif; ?>
<?php if (!htaccess_missing() && htaccess_needs_update()): ?>
<div class="card" style="border-color:#c78a00">
  <h2 style="color:#c78a00">⚠ .htaccess не соответствует адресу админки</h2>
  <p>В config.php <?= admin_slug() !== 'admin' ? 'задан секретный адрес админки (<code>admin_path</code>), но в .htaccess нет правил скрытия — старый адрес <code>/admin/</code> всё ещё открыт' : 'снова обычный адрес админки, а в .htaccess остались правила скрытия' ?>.
     Перезапишите .htaccess актуальным содержимым. Старый файл сохранится в корне как <code>.htaccess.bak-&lt;дата&gt;</code>; свои правила из него при необходимости перенесите в новый файл вручную.</p>
  <form method="post" style="display:inline"><?= csrf_field() ?>
    <input type="hidden" name="action" value="fix_htaccess">
    <button class="btn primary">Обновить .htaccess</button>
  </form>
</div>
<?php endif; ?>
<?php if (is_file(ROOT . '/install.php')): ?>
<div class="card" style="border-color:#c0392b">
  <h2 style="color:#c0392e">⚠ Инсталлятор install.php доступен из интернета</h2>
  <p>Установка уже завершена, но файл <code>install.php</code> всё ещё лежит в корне сайта. Это лишняя точка входа:
     отключите его сейчас (файл будет переименован в <code>install.php.off</code>).</p>
  <form method="post" style="display:inline"><?= csrf_field() ?>
    <input type="hidden" name="action" value="disable_install">
    <button class="btn primary">Отключить install.php</button>
  </form>
</div>
<?php endif; ?>
<div class="stats">
  <div class="stat"><b><?= $nPages ?></b><span>страниц</span></div>
  <div class="stat"><b><?= $nAlbums ?></b><span>альбомов</span></div>
  <div class="stat"><b><?= $nPostsPub ?></b><span>заметок опубликовано<?= $nPosts > $nPostsPub ? ' (+' . ($nPosts - $nPostsPub) . ' черновиков)' : '' ?></span></div>
  <div class="stat"><b><?= $nImgs ?></b><span>фото</span></div>
  <div class="stat"><b><?= $nDraft ?></b><span>черновиков</span></div>
</div>
<div class="card">
  <h2>Что улучшить для поиска</h2>
    <?php if (!$todo): ?>
    <p>Всё основное заполнено 🎉</p>
    <?php else: ?>
    <ul class="todo">
        <?php foreach ($todo as $ti => [$text, $link]): ?>
      <li<?= $ti >= 5 ? ' class="todo-more" hidden' : '' ?>><a href="<?= e($link) ?>"><?= e($text) ?></a></li>
        <?php endforeach; ?>
    </ul>
        <?php if (count($todo) > 5): ?>
    <p><button type="button" class="btn sm ghost" data-todo-toggle>Показать все (<?= count($todo) ?>)</button></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
<div class="card">
  <h2>Резервная копия</h2>
  <p>База данных (страницы, заметки, настройки, меню) скачивается одним согласованным файлом. Оригиналы фото лежат в папке
     <code>storage/originals/</code> — их копируйте по FTP отдельно. Не копируйте <code>site.sqlite</code> руками «на лету»:
     часть свежих изменений может лежать в служебных файлах <code>-wal</code>/<code>-shm</code>.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn">Скачать копию базы</button></form>
</div>
<div class="card">
  <h2>Здоровье базы</h2>
  <p>Файл базы: <code>storage/site.sqlite</code> — <?= e(fmt_mb($dbSize)) ?><?= $walSize > 0 ? ', плюс служебный WAL ' . e(fmt_mb($walSize)) : '' ?>.
     Проверка <code>integrity_check</code> — штатное средство SQLite: читает базу целиком и сообщает,
     нет ли повреждённых страниц и индексов. Данные при проверке не меняются.</p>
  <form method="post" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="action" value="dbcheck"><button class="btn">Проверить базу</button></form>
  <h3 style="margin:0 0 6px">Автоматические копии</h3>
  <p>Раз в сутки при первом заходе в админку база копируется в <code>storage/backups/</code>, хранятся 7 последних копий.
     Если «записалось не то» — скачайте копию за нужный день и замените ею <code>site.sqlite</code> по FTP (старый файл переименуйте, не удаляйте).</p>
    <?php if (!$autoCopies): ?>
  <p class="muted">Копий пока нет — первая появится при следующем заходе в админку.</p>
    <?php else: ?>
  <p>
        <?php foreach ($autoCopies as $i => $cp): ?>
    <a class="btn sm" href="<?= e(a_url('dashboard', ['dlbackup' => $cp['name']])) ?>"><?= e($cp['date']) ?> · <?= e(fmt_mb($cp['size'])) ?></a>
        <?php endforeach; ?>
  </p>
    <?php endif; ?>
</div>
<div class="card">
  <h2>Кэш</h2>
  <p>Движок обработки фото: <b><?= img_engine() === 'imagick' ? 'Imagick' : 'GD' ?></b><?php if (img_engine() === 'imagick'): ?>
     (<?= e((string)(\Imagick::getVersion()['versionString'] ?? '')) ?>)<?php endif; ?>.
     При нехватке памяти Imagick сам уходит в дисковый кэш и не падает — на шаред-хостингах это надёжнее;
     принудительно выбрать движок можно настройкой <code>img_engine</code> в config.php (1.9.30).</p>
  <p>Кэш страниц для поисковиков (sitemap.xml, feed.xml): <b><?= (int)$cacheStat['xml_files'] ?></b> файлов,
     <?= e(fmt_mb((int)$cacheStat['xml_bytes'])) ?>. Очищать не обязательно — CMS сама следит за свежестью.</p>
  <p>Готовые миниатюры фото: <b><?= (int)$cacheStat['media_files'] ?></b> файлов,
     <?= e(fmt_mb((int)$cacheStat['media_bytes'])) ?> в <code>media/</code>
     <span class="muted">(подсчёт кэшируется на сутки — от <?= e(ru_date((int)$cacheStat['media_cached_at'])) ?>)</span>.
     Сброс нужен, если файлы в
     <code>media/</code> удаляли или портили вручную по FTP: после сброса каждая миниатюра создастся заново
     при первом показе, поэтому первые просмотры страниц будут медленнее — это нормально.
     Оригиналы фото в <code>storage/originals/</code> не затрагиваются.</p>
  <form method="post" style="display:inline"><?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_cache"><input type="hidden" name="what" value="xml">
    <button class="btn">Очистить кэш страниц</button>
  </form>
  <?php /* data-confirm вместо инлайн-onsubmit: CSP админки запрещает инлайн-обработчики,
           и подтверждение молча не срабатывало (ревизия 1.9.34, п.3) */ ?>
  <form method="post" style="display:inline" data-confirm="Удалить все готовые миниатюры? Они создадутся заново при показе — первые просмотры страниц будут медленнее."><?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_cache"><input type="hidden" name="what" value="media">
    <button class="btn danger">Сбросить миниатюры</button>
  </form>
  <form method="post" style="display:inline"><?= csrf_field() ?>
    <input type="hidden" name="action" value="cache_stats_refresh">
    <button class="btn sm" title="Подсчёт размера media/ кэшируется на сутки — обновить цифры сейчас">Пересчитать</button>
  </form>
  <?php /* прогрев (1.9.48): основные размеры создаются заранее пачками через AJAX (admin.js),
           а не в момент показа посетителю — после сброса миниатюр большие альбомы не упираются
           в лимит генераций img.php */ ?>
  <p style="margin-top:10px">
    <button class="btn" type="button" data-warm-thumbs>Прогреть миниатюры</button>
    <span class="muted" data-warm-status></span>
  </p>
  <p class="muted">Готовый HTML страниц для анонимных посетителей кэшируется в <code>storage/cache/pages/</code> (1.9.48) и обновляется сам при любых правках; кнопка «Очистить кэш страниц» чистит и его.</p>
</div>
<div class="card">
  <h2>Быстрые действия</h2>
  <a class="btn primary" href="<?= e(a_url('page_new', ['type' => 'album'])) ?>">+ Новый альбом</a>
  <a class="btn primary" href="<?= e(a_url('post_new')) ?>">+ Новая заметка</a>
  <a class="btn" href="<?= e(a_url('media')) ?>">Загрузить фото</a>
  <a class="btn" href="/sitemap.xml" target="_blank" rel="noopener">sitemap.xml ↗</a>
  <a class="btn" href="/robots.txt" target="_blank" rel="noopener">robots.txt ↗</a>
</div>
<div class="card">
  <h2>Разделы админки</h2>
  <p class="muted">Дублируют верхнее меню — пригодятся с телефона, если оно ещё не раскрыто.</p>
  <a class="btn" href="<?= e(a_url('pages')) ?>">Страницы</a>
  <a class="btn" href="<?= e(a_url('posts')) ?>">Блог</a>
  <a class="btn" href="<?= e(a_url('sections')) ?>">Разделы</a>
  <a class="btn" href="<?= e(a_url('menu')) ?>">Меню</a>
  <a class="btn" href="<?= e(a_url('settings')) ?>">Настройки</a>
  <a class="btn" href="<?= e(a_url('redirects')) ?>">Редиректы</a>
  <a class="btn" href="<?= e(a_url('account')) ?>">Аккаунт</a>
</div>
    <?php
    admin_footer();
}

/** Согласованная копия SQLite для скачивания (учитывает WAL). Завершает скрипт. */
function admin_send_backup(): void
{
    $tmp = ROOT . '/storage/backup-' . bin2hex(random_bytes(6)) . '.sqlite';
    /* страховка от «зависших» копий базы: при фатале/обрыве после создания файла удалим его при завершении запроса */
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });
    // сам снимок с 1.9.11 делает db_snapshot_to() — та же логика у автоматических копий
    if (!db_snapshot_to($tmp)) {
        flash('Не удалось создать копию: нет прав на запись в storage/ или база занята.', 'err');
        redirect(a_url('dashboard'));
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="site-' . date('Ymd-His') . '.sqlite"');
    /* filesize() теоретически может вернуть false (файл удалился между созданием и отдачей) —
       false конкатенировался бы в пустой заголовок молча (аудит 1.9.12, находка 9) */
    if (($sz = @filesize($tmp)) !== false) {
        header('Content-Length: ' . $sz);
    }
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** Каталог автоматических копий базы (внутри storage/ — уже закрыт от веба своим .htaccess). */
function admin_db_backup_dir(): string
{
    return ROOT . '/storage/backups';
}

/** Список автоматических копий, свежие первыми: [['name'=>…, 'date'=>…, 'size'=>…], …] */
function admin_db_autobackup_list(): array
{
    $out = [];
    foreach (glob(admin_db_backup_dir() . '/site-????-??-??.sqlite') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'date' => substr(basename($f), 5, 10), 'size' => (int)filesize($f)];
    }
    usort($out, static fn($a, $b) => strcmp($b['name'], $a['name'])); // дата в имени — сортировка строкой = по дате
    return $out;
}

/**
 * Раз в сутки (при первом заходе в админку) снимает согласованную копию базы в
 * storage/backups/ и оставляет 7 последних. «Сегодня уже есть» определяем по дате в имени,
 * а не по mtime — перенос файлов по FTP не будет провоцировать лишние копии.
 */
function admin_db_autobackup(): void
{
    $dir = admin_db_backup_dir();
    $today = date('Y-m-d');
    $lockDir = ROOT . '/storage/locks';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0700, true);
    }
    $lock = @fopen($lockDir . '/autobackup.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        app_log_error('autobackup: не удалось получить блокировку создания копии');
        return;
    }
    try {
        $list = admin_db_autobackup_list();
        if ($list && $list[0]['date'] === $today) {
            return;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            app_log_error('autobackup: не удалось создать каталог ' . $dir);
            return;
        }
        $dest = $dir . '/site-' . $today . '.sqlite';
        if (db_snapshot_to($dest)) {
            @chmod($dest, 0600);
        }
        // ротация: семь свежих, остальные в корзину (перечитываем список — сегодняшняя уже в нём)
        foreach (array_slice(admin_db_autobackup_list(), 7) as $old) {
            @unlink($dir . '/' . $old['name']);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Проверка целостности базы по кнопке: PRAGMA integrity_check читает базу целиком. */
function admin_db_check(): void
{
    session_write_close(); // на большой базе проверка идёт не мгновенно — не держим блокировку сессии
    $t0 = microtime(true);
    $errors = [];
    try {
        /* строк соответствия в выдаче нет: при здоровой базе возвращается одна строка «ok»;
           число строк с ошибками SQLite ограничивает сам (умолчательно до 100) */
        foreach (db()->query('PRAGMA integrity_check') as $row) {
            $line = trim((string)($row['integrity_check'] ?? ''));
            if ($line !== '' && strtolower($line) !== 'ok') {
                $errors[] = $line;
            }
        }
    } catch (Throwable $e) {
        $errors[] = 'сама проверка не выполнилась: ' . $e->getMessage();
    }
    $sec = number_format(microtime(true) - $t0, 1, ',', ' ');
    if (!$errors) {
        flash('База в порядке: integrity_check — ok (' . $sec . ' с). Повреждений нет.');
        return;
    }
    $shown = array_slice($errors, 0, 5);
    $more = count($errors) > 5 ? ' … и ещё ' . (count($errors) - 5) : '';
    flash('Найдены повреждения (' . count($errors) . '): ' . implode('; ', $shown) . $more
        . '. Ничего не записывайте в базу и замените site.sqlite копией из storage/backups/ или скачанной кнопкой выше.', 'err');
}

/** Отдаёт автоматическую копию из storage/backups/. Имя проверяется строго — иначе path traversal. */
function admin_send_autobackup(string $name): void
{
    $file = admin_db_backup_dir() . '/' . $name;
    /* модификатор D: без него «$» пропускал бы имя с переводом строки на конце (ревизия 1.9.34, п.21) */
    if (!preg_match('~^site-\d{4}-\d{2}-\d{2}\.sqlite$~D', $name) || !is_file($file)) {
        flash('Такой копии нет (возможно, её съела ротация — хранятся 7 последних).', 'err');
        redirect(a_url('dashboard'));
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    if (($sz = @filesize($file)) !== false) { // см. примечание у admin_send_backup (аудит 1.9.12, находка 9)
        header('Content-Length: ' . $sz);
    }
    readfile($file);
    exit;
}

function admin_menu(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $aRaw = $_POST['action'] ?? 'save';
        $a = is_string($aRaw) ? $aRaw : 'save'; // ?action[]=x — иначе warning в журнал
        $notNested = false;
        $newId = 0;
        /* Вся запись — одной транзакцией (аудит 1.9.40, п.12): десятки UPDATE подряд без неё
           давали частично сохранённое меню при сбое посередине и лишние fsync на слабом хостинге */
        db_tx(function () use ($a, &$notNested, &$newId): void {
        if ($a === 'add') {
            $pos = (int)db_val('SELECT COALESCE(MAX(position), 0) + 10 FROM menu_items');
            $newId = db_insert('INSERT INTO menu_items (title, position) VALUES (?, ?)', ['Новый пункт', $pos]);
        }
        foreach (post_arr('m') as $id => $f) {
            $id = (int)$id;
            if (!is_array($f) || !db_val('SELECT id FROM menu_items WHERE id = ?', [$id])) {
                continue;
            }
            if (!empty($f['delete'])) {
                db_run('UPDATE menu_items SET parent_id = NULL WHERE parent_id = ?', [$id]);
                db_run('DELETE FROM menu_items WHERE id = ?', [$id]);
                continue;
            }
            $parent = (int)($f['parent_id'] ?? 0);
            if ($parent === $id || !db_val('SELECT id FROM menu_items WHERE id = ? AND parent_id IS NULL', [$parent])) {
                $parent = null;
            }
            /* вложенность — два уровня: пункт, у которого уже есть подпункты, вкладывать нельзя (его дети пропали бы из меню) */
            if ($parent !== null && db_val('SELECT id FROM menu_items WHERE parent_id = ?', [$id])) {
                $parent = null;
                $notNested = true;
            }
            $pageId = (int)($f['page_id'] ?? 0);
            $pageId = $pageId > 0 && page_get($pageId) ? $pageId : null;
            $url = trim((string)($f['url'] ?? ''));
            if ($url !== '' && !preg_match('~^(https?://|mailto:|tel:|/|#)~i', $url)) {
                if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $url)) {
                    $url = ''; // javascript:, data: …
                } elseif (preg_match('~^[\w\-]+(\.[\w\-]+)+(/|$)~u', $url)) {
                    $url = 'https://' . $url; // site.ru → https://site.ru
                } else {
                    $url = '/' . ltrim($url, '/'); // blog → /blog
                }
            }
            db_run(
                'UPDATE menu_items SET title = ?, parent_id = ?, page_id = ?, url = ?, new_tab = ?, position = ? WHERE id = ?',
                [trim((string)($f['title'] ?? '')) ?: 'Пункт', $parent, $pageId, $pageId ? '' : $url, empty($f['new_tab']) ? 0 : 1, (int)($f['position'] ?? 0), $id]
            );
        }
        }); // конец db_tx: меню записано целиком или не записано вовсе
        /* меню есть на каждой странице, а updated_at страниц оно не трогает — без отметки
           поисковик по Last-Modified держал бы старый HTML (ревизия 1.9.34, п.1) */
        site_touch();
        flash($a === 'add' ? 'Пункт добавлен.' : 'Меню сохранено.');
        if ($notNested) {
            flash('Часть пунктов не вложена: у них уже есть подпункты, а вложенность — два уровня.', 'err');
        }
        /* после «+ Пункт» прокрутим к новой строке и поставим фокус в «Название» */
        redirect(a_url('menu', $newId ? ['new' => $newId] : []));
    }

    $items = db_all('SELECT * FROM menu_items ORDER BY position, id');
    $tops = [0 => '— верхний уровень —'];
    $childCount = []; // для подтверждения удаления родителя: его подпункты поднимутся наверх
    foreach ($items as $it) {
        if ($it['parent_id'] === null) {
            $tops[(int)$it['id']] = 'внутри: ' . $it['title'];
        } else {
            $childCount[(int)$it['parent_id']] = ($childCount[(int)$it['parent_id']] ?? 0) + 1;
        }
    }
    $newId = (int)($_GET['new'] ?? 0);
    $pages = [0 => '— не страница (рубрика или свой адрес) —'] + page_options();

    admin_header('Меню', 'menu');
    ?>
<form method="post">
<?= csrf_field() ?>
<?php /* Enter в любом поле формы отправляет ПЕРВУЮ кнопку в DOM — без этой невидимой «Сохранить» Enter добавлял пункт вместо сохранения */ ?>
<button type="submit" name="action" value="save" tabindex="-1" aria-hidden="true" style="position:absolute;width:1px;height:1px;padding:0;border:0;opacity:0;pointer-events:none">Сохранить</button>
<div class="bar sticky"><h1>Меню</h1><span class="grow"></span>
  <button class="btn" name="action" value="add">+ Пункт</button>
  <button class="btn primary" name="action" value="save">Сохранить</button></div>
<p class="muted">Порядок — кнопками ↑ ↓ или числом в колонке «№» (меньше — выше). Пункт без страницы и без адреса становится рубрикой-заголовком (например, «Портфолио») — он показывается, только если внутри есть видимые пункты. Вложенность — два уровня. Страницы-черновики в меню не выводятся.</p>
<table class="tbl menu-tbl">
<thead><tr><th></th><th>№</th><th>Название</th><th>Уровень</th><th>Ведёт на страницу</th><th>или на адрес</th><th>Новая вкладка</th><th>Удалить</th></tr></thead>
<tbody>
    <?php foreach ($items as $it):
        $n = 'm[' . (int)$it['id'] . ']'; ?>
<tr<?= (int)$it['id'] === $newId ? ' class="new-row"' : '' ?>>
  <td class="acts nowrap"><button type="button" class="ico" data-mv="-1" title="Выше">↑</button><button type="button" class="ico" data-mv="1" title="Ниже">↓</button></td>
  <td><input class="xs" type="number" name="<?= $n ?>[position]" value="<?= (int)$it['position'] ?>"></td>
  <td><input name="<?= $n ?>[title]" value="<?= e($it['title']) ?>"></td>
  <td><select name="<?= $n ?>[parent_id]">
        <?php foreach ($tops as $k => $t): if ($k === (int)$it['id']) { continue; } ?>
    <option value="<?= $k ?>"<?= (int)($it['parent_id'] ?? 0) === $k ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
  </select></td>
  <td><select name="<?= $n ?>[page_id]">
        <?php foreach ($pages as $k => $t): ?>
    <option value="<?= $k ?>"<?= (int)($it['page_id'] ?? 0) === $k ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
  </select></td>
  <td><input name="<?= $n ?>[url]" value="<?= e($it['url']) ?>" placeholder="https://… или /путь" list="menuUrls"></td>
  <td class="c"><input type="checkbox" name="<?= $n ?>[new_tab]" value="1"<?= (int)$it['new_tab'] ? ' checked' : '' ?>></td>
  <td class="c"><input type="checkbox" name="<?= $n ?>[delete]" value="1"<?= !empty($childCount[(int)$it['id']]) ? ' data-children="' . $childCount[(int)$it['id']] . '"' : '' ?>></td>
</tr>
    <?php endforeach; ?>
</tbody></table>
<datalist id="menuUrls">
    <?php foreach (post_sections() as $s): ?><option value="<?= e(section_base($s)) ?>"><?= e(section_title($s)) ?> — список заметок</option><?php endforeach; ?>
</datalist>
</form>
    <?php
    admin_footer();
}

function admin_redirects(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = post_str('action');
        if ($a === 'add') {
            $from = '/' . ltrim(post_str('from', 500), '/');
            $to = post_str('to', 1000);
            $code = (int)($_POST['code'] ?? 301) === 302 ? 302 : 301;
            if ($from === '/' || $to === '') {
                flash('Укажите старый путь (например /old-page) и новый адрес (/new-page или https://…).', 'err');
            } elseif (path_has_content($from)) {
                /* редирект с адреса живой страницы никогда не сработает — страница открывается раньше */
                flash('По адресу ' . $from . ' уже открывается страница сайта, редирект с него не сработает. Сначала смените адрес страницы или удалите её.', 'err');
            } else {
                $err = redirect_add($from, $to, $code);
                if ($err === null) {
                    flash('Редирект сохранён.');
                } else {
                    flash($err, 'err');
                }
            }
        } elseif ($a === 'delete') {
            db_run('DELETE FROM redirects WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('Редирект удалён.');
        }
        redirect(a_url('redirects'));
    }
    $rows = db_all('SELECT * FROM redirects ORDER BY id DESC');
    admin_header('Редиректы', 'redirects');
    ?>
<h1>Редиректы</h1>
<p class="muted">Нужны, если у страниц изменились адреса: поисковики и посетители со старых ссылок попадут на новые, а SEO-вес сохранится. При смене адреса страницы редирект создаётся автоматически.</p>
<form method="post" class="card row">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label class="f"><span class="fl">Старый путь</span><input name="from" placeholder="/old-page"></label>
  <label class="f"><span class="fl">Новый адрес</span><input name="to" placeholder="/new-page"></label>
  <label class="f"><span class="fl">Тип</span><select name="code"><option value="301">301 — навсегда</option><option value="302">302 — временно</option></select></label>
  <button class="btn primary">Добавить</button>
</form>
<table class="tbl">
<thead><tr><th>Откуда</th><th>Куда</th><th>Код</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
<tr><td><code><?= e($r['from_path']) ?></code></td><td><code><?= e($r['to_url']) ?></code></td><td><?= (int)$r['code'] ?></td>
<td><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">Удалить</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">Пока нет редиректов.</td></tr><?php endif; ?>
</tbody></table>
    <?php
    admin_footer();
}

function admin_messages(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (post_str('action') === 'delete') {
            db_run('DELETE FROM contact_messages WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
            flash('Заявка удалена.');
        }
        redirect(a_url('messages'));
    }
    $rows = db_all('SELECT * FROM contact_messages ORDER BY id DESC LIMIT 500');
    admin_header('Заявки', 'messages');
    ?>
<h1>Заявки</h1>
<p class="muted">Заявки сохраняются в базе даже при сбое почты и Telegram. Храните их только столько, сколько нужно для связи с клиентом; удаляйте обработанные записи.</p>
    <?php if (!$rows): ?><div class="card"><p class="muted">Заявок пока нет.</p></div><?php endif; ?>
    <?php foreach ($rows as $r): ?>
<div class="card">
  <div class="row"><strong>#<?= (int)$r['id'] ?> · <?= e($r['name']) ?></strong><span class="grow"></span><time><?= e(date('d.m.Y H:i', (int)$r['created_at'])) ?></time></div>
  <p><?php if ($r['phone'] !== ''): ?><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a><?php endif; ?>
     <?php if ($r['email'] !== ''): ?> · <a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a><?php endif; ?></p>
  <?php if ($r['shoot_date'] !== ''): ?><p>Дата съёмки: <?= e($r['shoot_date']) ?></p><?php endif; ?>
  <p><?= nl2br(e($r['message'])) ?></p>
  <p class="muted"><small>Страница: <?= e($r['page_path']) ?> · Внешняя доставка: <?= (int)$r['delivered'] ? 'да' : 'нет; заявка сохранена здесь' ?></small></p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm danger">Удалить</button></form>
</div>
    <?php endforeach; ?>
    <?php
    admin_footer();
}

function account_attempt_reserve(string $failKey): bool
{
    $pdo = db();
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        db_run('DELETE FROM login_log WHERE ts < ?', [now() - LOGIN_WINDOW]);
        if ((int)db_val('SELECT COUNT(*) FROM login_log WHERE login = ?', [$failKey]) >= PWCHANGE_MAX_ATTEMPTS) {
            $pdo->exec('ROLLBACK');
            return false;
        }
        db_run('INSERT INTO login_log (ip, ts, login) VALUES (?, ?, ?)', [client_ip(), now(), $failKey]);
        $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}

function admin_account(): void
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $u = db_one('SELECT * FROM users WHERE id = ?', [$uid]);
        $action = post_str('action') ?: 'password';
        $curRaw = $_POST['current'] ?? '';
        $cur = is_string($curRaw) ? $curRaw : '';
        /* Счётчик привязан к конкретной admin-сессии, чтобы захваченная сессия не могла
           заблокировать восстановление пароля владельцем в другой сессии. */
        $failKey = 'pwchange:' . $uid . ':' . hash('sha256', session_id());
        if (!$u || !password_verify($cur, (string)$u['pass_hash'])) {
            if (!account_attempt_reserve($failKey)) {
                flash('Слишком много неверных попыток. Повторите через ' . intdiv(LOGIN_WINDOW, 60) . ' минут.', 'err');
            } else {
                flash('Текущий пароль указан неверно.', 'err');
            }
            redirect(a_url('account'));
        }
        if ($action === 'login') {
            /* смена логина (с 1.9.11). Только ASCII: кириллические логины — рассадник
               опечаток и гомоглифов («а» русская/латинская). Регистр при входе не важен
               (COLLATE NOCASE), поэтому и сравнения ниже без учёта регистра. */
            $newLogin = post_str('new_login');
            if (!preg_match('~^[A-Za-z0-9_.\-]{3,32}$~', $newLogin)) {
                flash('Логин: 3–32 символа — латиница, цифры, точка, дефис, подчёркивание.', 'err');
            } elseif ($newLogin === (string)$u['login']) {
                flash('Новый логин совпадает с текущим.', 'err');
            } elseif (db_one('SELECT id FROM users WHERE login = ? COLLATE NOCASE AND id <> ?', [$newLogin, $uid])) {
                flash('Такой логин уже занят другим пользователем.', 'err');
            } else {
                db_run('UPDATE users SET login = ? WHERE id = ?', [$newLogin, $uid]);
                db_run('DELETE FROM login_log WHERE login = ?', [$failKey]); // успех — счётчик троттлинга обнуляем
                flash('Логин изменён на «' . $newLogin . '». При следующем входе используйте его (пароль прежний).');
            }
            redirect(a_url('account'));
        }
        $newRaw = $_POST['new'] ?? '';
        $new = is_string($newRaw) ? $newRaw : '';
        $new2Raw = $_POST['new2'] ?? '';
        $new2 = is_string($new2Raw) ? $new2Raw : '';
        if (mb_strlen($new) < 8) {
            flash('Новый пароль должен быть не короче 8 символов.', 'err');
        } elseif (strlen($new) > 72) {
            /* bcrypt молча обрезает пароль по 72 байта — лучше честный отказ, чем усечённый пароль */
            flash('Новый пароль длиннее 72 байт — bcrypt такой не поддерживает. Задайте более короткий.', 'err');
        } elseif ($new !== $new2) {
            flash('Новый пароль и повтор не совпадают.', 'err');
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            db_run('UPDATE users SET pass_hash = ? WHERE id = ?', [$newHash, $uid]);
            session_regenerate_id(true);
            /* новый «отпечаток» пароля только у этой сессии: все остальные открытые входы перестают действовать */
            $_SESSION['pwv'] = pass_version($newHash);
            admin_session_ok(true);
            is_admin(true); // кэш is_admin() — тоже (аудит 1.9.40, п.11)
            db_run('DELETE FROM login_log WHERE login = ?', [$failKey]); // успех — счётчик троттлинга обнуляем
            flash('Пароль изменён. Остальные открытые сеансы завершены.');
        }
        redirect(a_url('account'));
    }
    $u = db_one('SELECT login FROM users WHERE id = ?', [$uid]);
    admin_header('Аккаунт', 'account');
    ?>
<h1>Аккаунт</h1>
<div class="card narrow">
  <h2>Адрес админки</h2>
  <p>Текущий адрес: <strong><code><?= e(admin_base()) ?></code></strong></p>
  <p class="muted"><small>Меняется только вручную в файле <code>config.php</code> (ключ <code>admin_path</code>, 20–32 случайных символа) — через эту страницу изменить нельзя, чтобы нельзя было случайно потерять вход. После смены зайдите на «Панель» — CMS предложит обновить .htaccess; старый адрес начнёт отвечать 404.</small></p>
</div>
<form method="post" class="card narrow" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="login">
  <h2>Логин</h2>
  <p>Текущий логин: <strong><?= e($u['login'] ?? '') ?></strong></p>
  <?= f_input('new_login', 'Новый логин', '', ['maxlength' => 32, 'help' => '3–32 символа: латиница, цифры, точка, дефис, подчёркивание. Регистр при входе не важен.']) ?>
  <?= f_input('current', 'Текущий пароль (подтверждение)', '', ['type' => 'password']) ?>
  <button class="btn primary">Сменить логин</button>
</form>
<form method="post" class="card narrow" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="password">
  <h2>Пароль</h2>
  <?= f_input('current', 'Текущий пароль', '', ['type' => 'password']) ?>
  <?= f_input('new', 'Новый пароль (от 8 символов)', '', ['type' => 'password']) ?>
  <?= f_input('new2', 'Повторите новый пароль', '', ['type' => 'password']) ?>
  <button class="btn primary">Сменить пароль</button>
</form>
    <?php
    admin_footer();
}
