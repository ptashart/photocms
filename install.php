<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
/** Веб-установщик: создаёт базу, администратора и стартовую главную. После установки можно удалить этот файл. */
define('ROOT', __DIR__);
require __DIR__ . '/app/helpers.php';
/* перехватчик ошибок — как в bootstrap.php: фаталы в установке показываем текстом, а не пустым экраном */
app_register_error_handlers();
require __DIR__ . '/app/db.php';
require __DIR__ . '/app/settings.php';
require __DIR__ . '/app/images.php';
require __DIR__ . '/app/content.php';

/* mb_internal_encoding — только после проверки, что расширение есть (оно в списке ниже):
   без mbstring установщик обязан показать диагностику, а не упасть с fatal error */
if (extension_loaded('mbstring')) {
    mb_internal_encoding('UTF-8');
}
header('Content-Type: text/html; charset=utf-8');
session_boot(); /* сессию поднимаем до любого вывода HTML, иначе кука с CSRF-токеном не устанавливается (output_buffering=0) */

if (is_installed()) {
    http_response_code(403);
    exit('<!doctype html><meta charset="utf-8"><p style="font:16px system-ui;padding:40px">Сайт уже установлен. Удалите файл <code>install.php</code> с сервера. <a href="' . admin_base() . '">Войти в админку</a></p>');
}

$sqlite_ver = '';
try {
    $sqlite_ver = (string)(new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
} catch (Throwable $ignore) {
}
/* В свежей распаковке zip пустых каталогов может не быть (архиваторы их теряют) — создаём те,
   что движок всё равно создал бы сам при первой записи, и только потом проверяем права. */
foreach (['storage/originals', 'storage/locks', 'storage/sessions', 'storage/tmp'] as $mk) {
    if (!is_dir(__DIR__ . '/' . $mk)) {
        @mkdir(__DIR__ . '/' . $mk, 0700, true);
    }
}
$checks = [
    'PHP 8.0 или новее (сейчас ' . PHP_VERSION . ')' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Расширение pdo_sqlite' => extension_loaded('pdo_sqlite'),
    'SQLite 3.7 или новее (сейчас ' . ($sqlite_ver !== '' ? $sqlite_ver : 'не определена') . ')' => $sqlite_ver === '' || version_compare($sqlite_ver, '3.7.0', '>='),
    /* движок обработки фото — любой из двух: с 1.9.30 CMS умеет работать и на Imagick,
       поэтому отсутствие gd больше не блокирует установку (ревизия 1.9.34, п.21) */
    'Обработка фото (расширение gd или imagick)' => extension_loaded('gd') || extension_loaded('imagick'),
    'Расширение mbstring' => extension_loaded('mbstring'),
    'Расширение dom (очистка HTML)' => class_exists('DOMDocument'),
    'Папка storage/ доступна для записи' => is_writable(__DIR__ . '/storage'),
    'Папка storage/originals/ доступна для записи' => is_writable(__DIR__ . '/storage/originals'),
    'Папка media/ доступна для записи' => is_writable(__DIR__ . '/media'),
];
$warn = [
    'WebP в GD (быстрые картинки)' => function_exists('imagewebp'),
    'exif (поворот фото по EXIF)' => function_exists('exif_read_data'),
];
$ok = !in_array(false, $checks, true);
$errors = [];

/* Адрес сайта для предзаполнения поля установки: на localhost / IP / технических .local-доменах
   не подставляем (после переноса на хостинг canonical и sitemap указывали бы на них). */
$host = request_host();
$hostLocal = (bool)preg_match('~^(localhost|\[::1\]|\d{1,3}(\.\d{1,3}){3})(:\d+)?$~', $host)
    || (bool)preg_match('~\.(local|test|localhost)(:\d+)?$~', $host);
$detectedBase = $hostLocal ? '' : (is_https() ? 'https' : 'http') . '://' . $host;

if ($ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    /* храним логин в нижнем регистре: вход и счётчик попыток и так регистронезависимы (COLLATE NOCASE) */
    $login = mb_strtolower(trim(is_string($_POST['login'] ?? null) ? $_POST['login'] : ''));
    $pass = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    /* is_string-гард: из подделанной формы (site_name[]=x) поле приходит массивом —
       (string) по массиву дал бы warning «Array to string conversion» (аудит 1.9.78) */
    $site = trim(is_string($_POST['site_name'] ?? null) ? $_POST['site_name'] : '');
    /* Адрес сайта — из поля формы (аудит 1.9.40, п.7): раньше текущий хост записывался
       молча, и при установке через технический домен хостера весь сайт потом уходил на него
       301-редиректом, а очистить поле в настройках нельзя (аудит 1.9.22). Теперь владелец
       видит и правит адрес до записи; пустое поле = не записывать (Настройки → Основное). */
    $baseIn = trim(is_string($_POST['base_url'] ?? null) ? $_POST['base_url'] : '');
    if ($baseIn !== '') {
        $baseIn = rtrim($baseIn, '/');
        if (!preg_match('~^https?://~i', $baseIn)) {
            $baseIn = 'https://' . $baseIn;
        }
        $pu = parse_url($baseIn);
        if ($pu === false || empty($pu['host'])
            || !in_array(strtolower((string)($pu['scheme'] ?? '')), ['http', 'https'], true)
            || isset($pu['path']) || isset($pu['query']) || isset($pu['fragment'])
            || isset($pu['user']) || isset($pu['pass'])) {
            $errors[] = 'Адрес сайта должен быть вида https://example.ru (без пути и слэша в конце) — или очистите поле и впишите адрес позже в настройках.';
        }
    }
    if (!preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $login)) {
        $errors[] = 'Логин: 3–40 символов, латиница, цифры и . _ @ -';
    }
    if (mb_strlen($pass) < 8) {
        $errors[] = 'Пароль должен быть не короче 8 символов.';
    }
    if (strlen($pass) > 72) {
        $errors[] = 'Пароль длиннее 72 байт не поддерживается (ограничение bcrypt). Задайте более короткий.';
    }
    if ($site === '') {
        $errors[] = 'Укажите название сайта.';
    }
    if (!$errors) {
        define('PTS_DB_CREATE', true); // db() создаёт файл базы только с этим флагом
        install_schema();
        /* Гонка двух установок (две вкладки/ретрай): вторая проходит is_installed() выше,
           но упирается в «Сайт уже установлен» внутри транзакции — показываем текст ошибки,
           а не необработанное исключение. */
        try {
            /* Всё одной транзакцией: при сбое посередине не останется администратора без главной
               страницы (и повторная попытка установки не упрётся в «такой логин уже есть»). */
            $uid = (int)db_tx(function () use ($login, $pass, $site, $baseIn): int {
                if ((int)db_val('SELECT COUNT(*) FROM users') > 0) {
                    throw new RuntimeException('Сайт уже установлен.');
                }
                $uid = db_insert('INSERT INTO users (login, pass_hash, created_at) VALUES (?,?,?)', [$login, password_hash($pass, PASSWORD_DEFAULT), now()]);
                set_setting('site_name', $site);
                set_setting('home_title', $site);
                set_setting('schema_v', (string)SCHEMA_V);
                if ($baseIn !== '') { // проверен выше; пустое — адрес впишут позже в настройках
                    set_setting('base_url', $baseIn);
                }

                $t = now();
                $home = db_insert(
                    "INSERT INTO pages (type, title, slug, is_home, is_published, position, created_at, updated_at) VALUES ('page','Главная','home',1,1,0,?,?)",
                    [$t, $t]
                );
                $d = section_defaults('text');
                $d['html'] = '<h1>' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '</h1><p>Это стартовая страница. Откройте админку, чтобы заменить текст, добавить галереи и настроить SEO.</p>';
                $d['align'] = 'center';
                db_insert('INSERT INTO sections (page_id, type, position, data) VALUES (?,?,?,?)', [$home, 'text', 1, json_db($d)]);
                db_insert('INSERT INTO menu_items (title, page_id, position) VALUES (?,?,?)', ['Главная', $home, 1]);
                return $uid;
            });
            @file_put_contents(ROOT . '/storage/installed.lock', (string)time());
            /* Установка завершена — отключаем инсталлятор, чтобы он не оставался публично доступным.
               Файл переименовывается в install.php.off; вернуть можно по FTP (и .htaccess запрещает отдачу .off). */
            @rename(__FILE__, __FILE__ . '.off');

            session_boot();
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            $_SESSION['pwv'] = pass_version((string)db_val('SELECT pass_hash FROM users WHERE id = ?', [$uid]));
            $_SESSION['t0'] = $_SESSION['last'] = time();
            /* .htaccess часто теряется при загрузке по FTP — создадим сами, если его нет */
            if (htaccess_missing()) {
                htaccess_fix();
            }
            redirect(admin_base() . '?p=settings');
        } catch (Throwable $ex) {
            /* подробности (пути, SQL, строки подключения) — только в журнал: на публичной странице
               установки им не место */
            app_log_error('install: ' . $ex->getMessage() . ' в ' . $ex->getFile() . ':' . $ex->getLine());
            $errors[] = 'Во время установки произошла ошибка. Подробности записаны в storage/error.log.';
        }
    }
}
?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Установка сайта</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;background:#f4f4f2;color:#222;margin:0;padding:40px 16px}
.box{max-width:560px;margin:0 auto;background:#fff;border:1px solid #ddd;border-radius:10px;padding:28px}
h1{font-size:22px;margin:0 0 18px}ul{list-style:none;padding:0;margin:0 0 20px;font-size:14px}
li::before{content:"✔ ";color:#2a8a3e}li.bad::before{content:"✖ ";color:#c0392b}li.warn::before{content:"• ";color:#b7791f}
label{display:block;margin:14px 0 4px;font-size:14px;font-weight:600}
input{width:100%;padding:10px 12px;border:1px solid #bbb;border-radius:6px;font:inherit}
.hint{font-size:13px;color:#777;margin:4px 0 0}
button{margin-top:20px;padding:12px 22px;border:0;border-radius:6px;background:#111;color:#fff;font:inherit;cursor:pointer}
.err{background:#fdecea;color:#8a1f11;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:14px}
</style></head><body><div class="box">
<h1>Установка сайта</h1>
<ul>
<?php foreach ($checks as $label => $v): ?><li class="<?= $v ? '' : 'bad' ?>"><?= e($label) ?></li><?php endforeach; ?>
<?php foreach ($warn as $label => $v): ?><li class="<?= $v ? '' : 'warn' ?>"><?= e($label) ?><?= $v ? '' : ' — не найдено, сайт будет работать, но хуже' ?></li><?php endforeach; ?>
</ul>
<?php if (!$ok): ?>
  <div class="err">Исправьте отмеченное красным (расширения включаются в панели хостинга, права — на папки storage и media) и обновите страницу.</div>
<?php else: ?>
  <?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Название сайта</label><input name="site_name" value="<?= e(is_string($_POST['site_name'] ?? null) ? $_POST['site_name'] : '') ?>" required>
    <label>Адрес сайта (для canonical, sitemap и превью ссылок)</label>
    <input name="base_url" value="<?= e($_POST['base_url'] ?? $detectedBase) ?>" placeholder="https://example.ru">
    <p class="hint">Проверьте адрес: если ставите сайт через технический домен хостера, очистите поле — боевой адрес впишете позже (Настройки → Основное). Иначе весь сайт будет перенаправлять на указанный здесь домен.</p>
    <label>Логин администратора</label><input name="login" value="<?= e($_POST['login'] ?? '') ?>" autocomplete="username" required>
    <label>Пароль (от 8 символов)</label><input name="password" type="password" autocomplete="new-password" required>
    <button type="submit">Установить</button>
  </form>
<?php endif; ?>
</div></body></html>
