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

function a_url(string $p, array $q = []): string
{
    return admin_base() . '?' . http_build_query(['p' => $p] + $q);
}

/** Одноразовое значение для CSP: inline-скрипты админки без этого nonce браузер не выполнит. */
function admin_csp_nonce(): string
{
    static $n = null;
    if ($n === null) {
        $n = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    return $n;
}

/**
 * Ключ логина для login_log: хэш, а не набранная строка. В поле «логин» люди по ошибке
 * вставляют и пароль — открытым текстом в базе ему не место (ревизия 1.9.34, п.12).
 * Счётчики по логину работают как раньше: одна строка → один хэш. Служебные ключи
 * («pwchange:ID» из Аккаунта) пишутся как есть — это не пользовательский ввод.
 */
function login_log_key(string $login): string
{
    return $login === '' ? '' : 'lh:' . hash('sha256', $login);
}

function login_attempt_reserve(string $ip, string $loginKey, string $login): bool
{
    $pdo = db();
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        db_run('DELETE FROM login_log WHERE ts < ?', [now() - LOGIN_WINDOW]);
        $byIp = (int)db_val('SELECT COUNT(*) FROM login_log WHERE ip = ?', [$ip]);
        $byLogin = $login === '' ? 0 : (int)db_val('SELECT COUNT(*) FROM login_log WHERE login = ? OR login = ?', [$loginKey, $login]);
        $byLoginIp = $login === '' ? 0 : (int)db_val('SELECT COUNT(*) FROM login_log WHERE (login = ? OR login = ?) AND ip = ?', [$loginKey, $login, $ip]);
        if ($byIp >= LOGIN_MAX_PER_IP || ($byLogin >= LOGIN_MAX_PER_LOGIN && $byLoginIp > 0)) {
            $pdo->exec('ROLLBACK');
            return false;
        }
        db_run('INSERT INTO login_log (ip, ts, login) VALUES (?, ?, ?)', [$ip, now(), $loginKey]);
        $pdo->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $e;
    }
}

function admin_login(): void
{
    $err = '';
    /* куда вернуться после входа: только внутренний путь админки, иначе open redirect */
    $returnRaw = $_GET['return'] ?? $_POST['return'] ?? '';
    $return = is_string($returnRaw) ? $returnRaw : ''; // ?return[]=x — иначе «Array to string conversion» в журнал
    $ab = admin_base();
    if (strncmp($return, $ab, strlen($ab)) !== 0 || strncmp($return, $ab . '/', strlen($ab) + 1) === 0) {
        $return = '';
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        /* IP берём через client_ip(): за прокси/Cloudflare REMOTE_ADDR — это адрес прокси, и чужие неудачи
           блокировали бы вход всем (включая владельца). Заголовкам верим только от доверенных прокси (config.php). */
        $ip = substr(client_ip(), 0, 64);
        $loginRaw = $_POST['login'] ?? '';
        $login = mb_strtolower(mb_substr(trim(is_string($loginRaw) ? $loginRaw : ''), 0, 64)); // ?login[]=x — иначе warning в журнал
        $loginKey = login_log_key($login); // в журнал пишем хэш, а не сам набранный логин (п.12)
        /* Попытка резервируется атомарно до проверки пароля; успешный вход удаляет запись позже. */
        if (!login_attempt_reserve($ip, $loginKey, $login)) {
            $err = 'Слишком много попыток входа. Подождите 15 минут.';
        } else {
            $pwRaw = $_POST['password'] ?? '';
            $pw = is_string($pwRaw) ? $pwRaw : ''; // ?password[]=x — иначе warning в журнал
            /* bcrypt читает только первые 72 байта пароля: длинный хвост молча отрезается, и два пароля
               с общими 72 байтами неотличимы. Отклоняем такие пароли явно, а не делаем вид, что они длинные. */
            if (strlen($pw) > 72) {
                $err = 'Пароль длиннее 72 байт не поддерживается — задайте более короткий в настройках аккаунта.';
            } else {
            /* COLLATE NOCASE: логин при установке может быть «Admin», а в форме вводят «admin» —
               счётчик попыток и так работает с нижним регистром, пусть и поиск совпадает с ним */
            $u = db_one('SELECT * FROM users WHERE login = ? COLLATE NOCASE', [$login]);
            /* логина нет — всё равно тратим столько же времени на bcrypt, иначе по скорости ответа
               можно отличить существующий логин от несуществующего. Проверяем о готовый
               dummy-хэш: password_hash() на каждый запрос плодил бы по дорогому
               bcrypt-хэшу — бесплатный обогреватель CPU для атакующего. С 1.9.35 заглушка
               генерируется на сервере (dummy_password_hash), а не зашита в пакете (ревизия 1.9.34, п.13) */
            $good = password_verify($pw, $u ? (string)$u['pass_hash'] : dummy_password_hash());
            if ($u && $good) {
                /* Rehash только если проверенный ранее хеш не сменился параллельной сменой пароля. */
                if (password_needs_rehash((string)$u['pass_hash'], PASSWORD_DEFAULT)) {
                    $oldHash = (string)$u['pass_hash'];
                    $newHash = password_hash($pw, PASSWORD_DEFAULT);
                    $updated = db_run('UPDATE users SET pass_hash = ? WHERE id = ? AND pass_hash = ?', [$newHash, (int)$u['id'], $oldHash]);
                    if ($updated->rowCount() === 1) {
                        $u['pass_hash'] = $newHash;
                    } else {
                        $u = null;
                    }
                }
                if ($u) {
                    session_regenerate_id(true);
                    unset($_SESSION['csrf']); // CSRF-токен анонимной сессии не должен пережить вход
                    $_SESSION['uid'] = (int)$u['id'];
                    $_SESSION['pwv'] = pass_version((string)$u['pass_hash']);
                    $_SESSION['t0'] = $_SESSION['last'] = time();
                    admin_session_ok(true);
                    is_admin(true); // кэш is_admin() — тоже (аудит 1.9.40, п.11)
                    db_run('DELETE FROM login_log WHERE ip = ? OR login = ? OR login = ?', [$ip, $loginKey, $login]); // и хэш, и старые «сырые» записи
                    redirect($return !== '' ? $return : admin_base());
                }
            }
            $err = 'Неверный логин или пароль.';
            }
        }
    }
    ?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Вход</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>"></head>
<body class="login"><form method="post" class="login-box">
<h1>Вход в админку</h1>
<?php if ($err): ?><div class="flash err" role="alert"><?= e($err) ?></div><?php endif; ?>
<?= csrf_field() ?>
<?php if ($return !== ''): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
<label>Логин<input name="login" autocomplete="username" autofocus required></label>
<label>Пароль<span class="pass"><input name="password" type="password" autocomplete="current-password" required><button type="button" class="btn sm" id="showLoginPass" aria-label="Показать или скрыть пароль">Показать</button></span></label>
<button class="btn primary" type="submit">Войти</button>
<p class="login-ver">CMS · версия <?= e(APP_VERSION) ?></p>
<script nonce="<?= e(admin_csp_nonce()) ?>">/* admin.js на странице входа не подключён — маленький локальный переключатель */
(function(){var b=document.getElementById('showLoginPass'),i=b.parentNode.querySelector('input');b.addEventListener('click',function(){var s=i.type==='password';i.type=s?'text':'password';b.textContent=s?'Скрыть':'Показать';i.focus();});})();</script>
</form></body></html>
    <?php
}

function admin_header(string $title, string $active = ''): void
{
    $nav = [
        'dashboard' => 'Панель', 'pages' => 'Страницы', 'posts' => 'Блог', 'sections' => 'Разделы', 'media' => 'Медиа', 'videos' => 'Видео', 'menu' => 'Меню',
        'settings' => 'Настройки', 'redirects' => 'Редиректы', 'account' => 'Аккаунт',
    ];
    ?>
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= e($title) ?> — админка</title>
<link rel="stylesheet" href="<?= e(asset('assets/css/admin.css')) ?>"></head>
<body>
<header class="top">
  <a class="logo" href="<?= e(a_url('dashboard')) ?>"><?= e(setting('site_name')) ?></a>
  <button class="anav-btn" type="button" aria-label="Меню админки" aria-expanded="false" aria-controls="anav"><span></span><span></span><span></span></button>
  <nav id="anav">
    <?php foreach ($nav as $k => $label): ?>
      <a href="<?= e(a_url($k)) ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <span class="grow"></span>
  <a href="/" target="_blank" rel="noopener">Открыть сайт ↗</a>
  <form method="post" action="<?= e(a_url('logout')) ?>" class="inline"><?= csrf_field() ?><button class="link" type="submit">Выйти</button></form>
</header>
<main class="wrap">
<?php foreach (flashes() as [$type, $msg]): ?>
  <div class="flash <?= $type === 'err' ? 'err' : 'ok' ?>" role="<?= $type === 'err' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
    <?php
}

function admin_footer(): void
{
    ?>
</main>
<footer class="afoot">CMS · версия <?= e(APP_VERSION) ?> · © Sergei Kolesnikov</footer>
<script nonce="<?= e(admin_csp_nonce()) ?>">window.ADMIN = {csrf: <?= json_encode(csrf_token()) ?>, base: <?= json_encode(admin_base()) ?>, mediaUrl: <?= json_encode(a_url('media_json')) ?>, uploadUrl: <?= json_encode(a_url('upload')) ?>, altUrl: <?= json_encode(a_url('image_alt')) ?>, delUrl: <?= json_encode(a_url('image_delete')) ?>, focusUrl: <?= json_encode(a_url('image_focus')) ?>, pingUrl: <?= json_encode(a_url('ping')) ?>, warmUrl: <?= json_encode(a_url('warm_thumbs')) ?>, albums: <?= json_encode(array_map(static fn($a) => ['id' => (int)$a['id'], 'title' => (string)$a['title'], 'photos' => (int)$a['photos']], albums_with_counts()), JSON_UNESCAPED_UNICODE) ?>};</script>
<script src="<?= e(asset('assets/js/admin.js')) ?>"></script>
</body></html>
    <?php
}

/* ---------- мини-конструктор форм ---------- */

/** Подсказка под полем: 'help' — текст (экранируется), 'help_html' — готовая разметка (выводится как есть). */
function f_help(array $o): string
{
    if (!empty($o['help'])) {
        return '<small>' . e($o['help']) . '</small>';
    }
    if (!empty($o['help_html'])) {
        return '<small>' . $o['help_html'] . '</small>';
    }
    return '';
}

function f_input(string $name, string $label, $val, array $o = []): string
{
    $type = $o['type'] ?? 'text';
    $attr = '';
    foreach (['placeholder', 'maxlength', 'min', 'max', 'step', 'data-count', 'data-count-min'] as $a) {
        if (isset($o[$a])) {
            $attr .= ' ' . $a . '="' . e($o[$a]) . '"';
        }
    }
    return '<label class="f"><span class="fl">' . e($label) . '</span><input type="' . e($type) . '" name="' . e($name) . '" value="' . e($val) . '"' . $attr . '>'
        . f_help($o) . '</label>';
}

function f_textarea(string $name, string $label, $val, array $o = []): string
{
    $rows = (int)($o['rows'] ?? 3);
    $attr = isset($o['data-count']) ? ' data-count="' . e($o['data-count']) . '"' : '';
    $attr .= isset($o['data-count-min']) ? ' data-count-min="' . e($o['data-count-min']) . '"' : '';
    $cls = !empty($o['code']) ? ' class="code"' : '';
    return '<label class="f"><span class="fl">' . e($label) . '</span><textarea name="' . e($name) . '" rows="' . $rows . '"' . $attr . $cls . '>' . e($val) . '</textarea>'
        . f_help($o) . '</label>';
}

function f_select(string $name, string $label, array $options, $val, array $o = []): string
{
    $h = '<label class="f"><span class="fl">' . e($label) . '</span><select name="' . e($name) . '">';
    foreach ($options as $k => $t) {
        $h .= '<option value="' . e((string)$k) . '"' . ((string)$k === (string)$val ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $h . '</select>' . f_help($o) . '</label>';
}

function f_check(string $name, string $label, bool $on, string $help = ''): string
{
    return '<label class="f chk"><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '> <span>' . e($label) . '</span>'
        . ($help !== '' ? '<small>' . e($help) . '</small>' : '') . '</label>';
}

/** Селект «Вставить заготовку JSON-LD» рядом с полем «Свой JSON-LD» (обработчик — admin.js). */
function f_jsonld_presets(): string
{
    $h = '<div class="f"><span class="fl">Заготовки микроразметки</span><select data-jsonld-preset><option value="">Вставить заготовку…</option>';
    foreach (array_keys(jsonld_presets()) as $name) {
        $h .= '<option value="' . e($name) . '">' . e($name) . '</option>';
    }
    $h .= '</select><script type="application/json" data-jsonld-presets>'
        . json_encode(jsonld_presets(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)
        . '</script><small>Заготовка добавится в поле «Свой JSON-LD» выше — замените значения-примеры на свои.</small></div>';
    return $h;
}

function thumb_url(array $img): string
{
    return image_url($img, 440, 'jpg');
}

function f_image(string $name, string $label, $imageId, string $help = ''): string
{
    $img = $imageId ? image_get((int)$imageId) : null;
    $h = '<div class="f"><span class="fl">' . e($label) . '</span><div class="imgpick">';
    $h .= '<input type="hidden" name="' . e($name) . '" value="' . ($img ? (int)$img['id'] : '') . '">';
    $h .= '<div class="imgpick-prev">' . ($img ? '<img src="' . e(thumb_url($img)) . '" alt="">' : '<span>нет</span>') . '</div>';
    $h .= '<div class="imgpick-btns"><button type="button" class="btn" data-pick>Выбрать…</button> <button type="button" class="btn ghost" data-clear>Убрать</button></div>';
    $h .= '</div>' . ($help !== '' ? '<small>' . e($help) . '</small>' : '') . '</div>';
    return $h;
}

function page_options(?int $exceptId = null, bool $onlyParentable = false): array
{
    $opts = [];
    foreach (db_all('SELECT id, title, slug, type, is_published FROM pages ORDER BY position, id') as $p) {
        if ($exceptId !== null && (int)$p['id'] === $exceptId) {
            continue;
        }
        if ($onlyParentable && $p['type'] === 'album') {
            continue;
        }
        $opts[(int)$p['id']] = $p['title'] . ' (/' . $p['slug'] . ')' . ((int)$p['is_published'] ? '' : ' — черновик');
    }
    return $opts;
}
