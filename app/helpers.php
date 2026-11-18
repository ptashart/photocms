<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

require_once __DIR__ . '/constants.php';








function app_log_error(string $msg): void
{
    $dir = defined('ROOT') ? ROOT . '/storage' : __DIR__ . '/../storage';





    $clean = preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $msg);
    if ($clean === null) {
        $clean = (string)preg_replace('/[\x00-\x1f\x7f]/', ' ', $msg);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $clean . "\n";
    $file = $dir . '/error.log';

    clearstatcache(true, $file);
    $new = !is_file($file);
    if (!$new && (int)@filesize($file) > ERROR_LOG_MAX) {
        if (@rename($file, $file . '.1')) {
            @chmod($file . '.1', 0600);
            $new = true;
        }
    }
    if ($new) {
        $fp = @fopen($file, 'ab');
        if ($fp) {
            fclose($fp);
            @chmod($file, 0600);
        }
    } else {
        @chmod($file, 0600);
    }
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}












function security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}


function app_register_error_handlers(): void
{


    set_error_handler(function (int $no, string $str, string $file, int $line): bool {
        if (!(error_reporting() & $no)) {
            return false;
        }
        if (in_array($no, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_USER_ERROR], true)) {
            app_log_error('PHP[' . $no . ']: ' . $str . ' в ' . $file . ':' . $line);
        }
        return false;
    });
    set_exception_handler(function (Throwable $e) {
        app_log_error(get_class($e) . ': ' . $e->getMessage() . ' в ' . $e->getFile() . ':' . $e->getLine());
        $debug = false;
        try {
            $debug = (bool)cfg('debug', false);
        } catch (Throwable $ignore) {
        }
        app_error_page($debug ? (string)$e : '');
    });
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            app_log_error($err['message'] . ' в ' . $err['file'] . ':' . $err['line']);

            if (!headers_sent()) {


                while (ob_get_level() > 0) {
                    if (!@ob_end_clean()) {
                        break;
                    }
                }
                app_error_page('');
            }
        }
    });
}

function app_error_page(string $details): void
{
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!doctype html><meta charset="utf-8"><p style="font:16px system-ui;padding:40px">'
        . 'Внутренняя ошибка сайта. Подробная причина записана в журнал ошибок (доступен владельцу сайта).'
        . ($details !== '' ? '</p><pre style="font:13px monospace;padding:0 40px">' . e($details) . '</pre>' : '</p>');
}


function cfg(string $key, $default = null)
{
    static $conf = null;
    if ($conf === null) {
        $f = ROOT . '/config.php';
        $conf = is_file($f) ? (array)(require $f) : [];
    }
    return $conf[$key] ?? $default;
}


function etag_matches(string $etag): bool
{
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm === '') {
        return false;
    }
    foreach (explode(',', $inm) as $t) {
        $t = trim($t);
        if (stripos($t, 'W/') === 0) {
            $t = trim(substr($t, 2));
        }
        if ($t === '*' || $t === $etag) {
            return true;
        }
    }
    return false;
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): int
{
    return time();
}





function content_lastmod(?int $ts = null): int
{
    static $max = 0;
    if ($ts !== null && $ts > $max) {
        $max = $ts;
    }
    return $max;
}








function site_touch(): void
{


    static $doneAt = 0;
    $t = time();
    if ($doneAt === $t) {
        return;
    }
    $doneAt = $t;
    try {



        $prev = (int)(db_val("SELECT v FROM settings WHERE k = 'site_rev'") ?? 0);
        $rev = (string)max($t, $prev + 1);
        db_run("INSERT OR REPLACE INTO settings (k, v) VALUES ('site_rev', ?)", [$rev]);


        if (db()->inTransaction()) {
            $GLOBALS['settings_dirty'] = true;
        } elseif (function_exists('settings_all')) {
            settings_all(false, ['site_rev' => $rev]);
        }
    } catch (Throwable $ignore) {

    }
}



function public_custom_code(string $code): string
{
    if ($code === '') {
        return '';
    }
    $code = preg_replace_callback('~<script\b([^>]*)>~i', static function (array $m): string {
        $attrs = preg_replace('~\s+nonce\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $m[1]);
        return '<script nonce="__PHOTOCMS_CSP_CUSTOM_NONCE__"' . $attrs . '>';
    }, $code) ?? $code;
    return '<!--PHOTOCMS_CSP_BEGIN-->' . $code . '<!--PHOTOCMS_CSP_END-->';
}


function public_csp_origins(string $html, string $tag, string $attr): array
{
    $origins = [];
    if (preg_match_all('~<' . $tag . '\\b[^>]*?\\b' . $attr . '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\'|([^\\s>]+))~i', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $url = html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : $match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($url, '//')) {
                $url = 'https:' . $url;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
                continue;
            }
            $host = strtolower((string)$parts['host']);
            if (!preg_match('~^(?:[a-z0-9.-]+|\[[0-9a-f:.]+\])$~i', $host)) {
                continue;
            }
            $origins[] = 'https://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
        }
    }
    $origins = array_values(array_unique($origins));
    sort($origins);
    return $origins;
}

function public_csp(string $trustedHtml, string $nonce): string
{
    $suffix = static fn(array $origins): string => $origins ? ' ' . implode(' ', $origins) : '';
    $script = public_csp_origins($trustedHtml, 'script', 'src');
    $style = public_csp_origins($trustedHtml, 'link', 'href');
    $frame = public_csp_origins($trustedHtml, 'iframe', 'src');
    $media = array_values(array_unique(array_merge(
        public_csp_origins($trustedHtml, 'video', 'src'),
        public_csp_origins($trustedHtml, 'audio', 'src'),
        public_csp_origins($trustedHtml, 'source', 'src')
    )));
    sort($media);
    $form = public_csp_origins($trustedHtml, 'form', 'action');
    $connect = $script;
    if (preg_match('~(?:mc\.yandex\.|metrika|\bym\s*\()~i', $trustedHtml)) {
        $connect = array_merge($connect, ['https://mc.yandex.ru', 'https://mc.yandex.com']);
    }
    if (preg_match('~(?:google-analytics\.com|googletagmanager\.com|gtag\s*\()~i', $trustedHtml)) {
        $connect = array_merge($connect, ['https://www.google-analytics.com', 'https://analytics.google.com', 'https://stats.g.doubleclick.net']);
    }
    $customConnect = cfg('csp_connect_origins', []);
    if (is_array($customConnect)) {
        $connect = array_merge($connect, array_values(array_filter(array_map('strval', $customConnect), static fn(string $v): bool => (bool)preg_match('~^https://[a-z0-9.-]+(?::\d+)?$~i', $v))));
    }
    $connect = array_values(array_unique($connect));
    sort($connect);
    return "Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline'" . $suffix($style)
        . "; script-src 'self' 'nonce-" . $nonce . "' 'strict-dynamic'" . $suffix($script)
        . "; font-src 'self' data:; connect-src 'self'" . $suffix($connect)
        . "; media-src 'self'" . $suffix($media)
        . "; frame-src 'self'" . $suffix($frame)
        . "; object-src 'none'; base-uri 'self'; form-action 'self'" . $suffix($form)
        . "; frame-ancestors 'self'";
}


function public_csp_send(string $html): string
{
    $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    $trustedParts = [];
    $html = preg_replace_callback('~<!--PHOTOCMS_CSP_BEGIN-->(.*?)<!--PHOTOCMS_CSP_END-->~s', static function (array $m) use ($nonce, &$trustedParts): string {
        $trustedParts[] = $m[1];
        return str_replace('__PHOTOCMS_CSP_CUSTOM_NONCE__', $nonce, $m[1]);
    }, $html) ?? $html;
    $html = str_replace('__PHOTOCMS_CSP_CORE_NONCE__', $nonce, $html);
    header(public_csp(implode("\n", $trustedParts), $nonce));
    return $html;
}



function json_db($value): string
{
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}








function dummy_password_hash(): string
{
    static $h = null;
    if ($h !== null) {
        return $h;
    }
    $f = ROOT . '/storage/.dummy-hash';
    $saved = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    if ($saved !== '' && !password_needs_rehash($saved, PASSWORD_DEFAULT)) {
        return $h = $saved;
    }
    $h = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    @file_put_contents($f, $h, LOCK_EX);
    @chmod($f, 0600);
    return $h;
}







function reserved_slugs(): array
{
    static $memo = [];
    $rawJson = (string)setting('post_sections');
    if (isset($memo[$rawJson])) {
        return $memo[$rawJson];
    }
    $r = RESERVED_SLUGS;



    foreach ([admin_slug(), strtolower(admin_slug())] as $as) {
        if (!in_array($as, $r, true)) {
            $r[] = $as;
        }
    }

    foreach (post_sections() as $s) {
        if (!in_array($s['slug'], $r, true)) {
            $r[] = $s['slug'];
        }
    }
    return $memo[$rawJson] = $r;
}

function str_limit(string $s, int $n): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    if (mb_strlen($s) <= $n) {
        return $s;
    }
    return rtrim(mb_substr($s, 0, $n - 1), " ,.;:-—") . '…';
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }


    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && is_trusted_proxy($remote) && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        return true;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function request_host(): string
{
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $h = (string)preg_replace('/[^a-z0-9.\-:\[\]]/', '', $h);


    $allowed = cfg('allowed_hosts', []);
    if (is_array($allowed) && $allowed) {
        $allowed = array_map(static fn($x) => strtolower((string)$x), $allowed);
        return in_array($h, $allowed, true) ? $h : (string)$allowed[0];
    }


    $base = '';
    try {
        $base = trim((string)setting('base_url'));
    } catch (Throwable $ignore) {
    }
    if ($base !== '') {
        $bh = strtolower((string)parse_url($base, PHP_URL_HOST));
        if ($bh !== '') {
            $bp = parse_url($base, PHP_URL_PORT);
            $bhFull = $bh . ($bp ? ':' . (int)$bp : '');
            if ($h !== $bh && $h !== $bhFull) {
                return $bhFull;
            }
        }
    }
    return $h !== '' ? $h : 'localhost';
}


function ip_in_cidr(string $ip, string $network): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    $parts = explode('/', $network, 2);
    $net = trim($parts[0]);
    if (!filter_var($net, FILTER_VALIDATE_IP)) {
        return false;
    }
    $ipBin = inet_pton($ip);
    $netBin = inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $max = strlen($ipBin) * 8;
    $prefix = count($parts) === 2 ? trim($parts[1]) : (string)$max;
    if ($prefix === '' || !ctype_digit($prefix) || (int)$prefix > $max) {
        return false;
    }
    $bits = (int)$prefix;
    $whole = intdiv($bits, 8);
    if ($whole > 0 && substr($ipBin, 0, $whole) !== substr($netBin, 0, $whole)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($ipBin[$whole]) & $mask) === (ord($netBin[$whole]) & $mask);
}


function is_trusted_proxy(string $ip): bool
{
    $list = cfg('trusted_proxies', []);
    if (!is_array($list)) {
        return false;
    }
    foreach ($list as $network) {
        if (is_string($network) && ip_in_cidr($ip, trim($network))) {
            return true;
        }
    }
    return false;
}





function client_ip(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($remote !== '' && is_trusted_proxy($remote)) {
        $cf = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $xff = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($xff as $ip) {
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !is_trusted_proxy($ip)) {
                return $ip;
            }
        }
    }
    return $remote !== '' ? $remote : '0.0.0.0';
}

function request_path(): string
{
    $p = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $p = rawurldecode((string)$p);
    return $p === '' ? '/' : $p;
}


function base_url(): string
{
    $b = trim((string)setting('base_url'));
    if ($b !== '') {
        return rtrim($b, '/');
    }
    return (is_https() ? 'https' : 'http') . '://' . request_host();
}

function abs_url(string $path): string
{
    return base_url() . '/' . ltrim($path, '/');
}


function fmt_mb(int $bytes): string
{
    if ($bytes < 1048576) {
        return number_format(max(0, $bytes) / 1024, 0, ',', ' ') . ' КБ';
    }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
}

function redirect(string $to, int $code = 302): void
{


    $to = (string)preg_replace('~[\x00-\x1f\x7f]~', '', $to);
    $c0 = $to[0] ?? '';
    $c1 = $to[1] ?? '';
    if ($to === '' || $c0 === '\\' || (($c0 === '/') && ($c1 === '/' || $c1 === '\\'))) {
        $to = '/';
    }
    header('Location: ' . $to, true, $code);
    exit;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function translit(string $s): string
{
    static $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    return strtr(mb_strtolower($s, 'UTF-8'), $map);
}

function slugify(string $s, int $max = 80): string
{
    $s = translit(trim($s));
    $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    if (strlen($s) > $max) {
        $s = trim(substr($s, 0, $max), '-');
    }
    return $s;
}


function slug_normalize(string $s, int $max = 90): string
{
    $s = translit(trim($s));
    $s = (string)preg_replace('/[^a-z0-9_\-]+/', '-', $s);
    $s = trim((string)preg_replace('/-{2,}/', '-', $s), '-_');
    if (strlen($s) > $max) {
        $s = trim(substr($s, 0, $max), '-_');
    }
    return $s;
}


function normalize_url(string $u): string
{
    $u = trim($u);
    if ($u === '') {
        return '';
    }


    if (strlen($u) > 1 && strpos('/\\', $u[0]) !== false && strpos('/\\', $u[1]) !== false) {
        return 'https://' . preg_replace('~^[/\\\\]+~', '', $u);
    }
    if (preg_match('~^(https?://|mailto:|tel:|/|#)~i', $u)) {
        return $u;
    }
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $u)) {
        return '';
    }
    return 'https://' . ltrim($u, '/');
}


function safe_href(string $u): string
{
    $u = normalize_url($u);
    return $u !== '' ? $u : '#';
}


function post_str(string $key, int $max = 0): string
{
    $v = $_POST[$key] ?? '';
    $v = is_string($v) ? trim($v) : '';
    return ($max > 0 && mb_strlen($v) > $max) ? mb_substr($v, 0, $max) : $v;
}


function post_arr(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}


function clip(string $s, int $max): string
{
    $s = trim($s);
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) && !is_link($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}



function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ptsid');


    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');



    $life = SESSION_LIFETIME;
    @ini_set('session.gc_maxlifetime', (string)$life);
    $dir = cfg('session_path', (defined('ROOT') ? ROOT : dirname(__DIR__)) . '/storage/sessions');
    $dir = is_string($dir) && $dir !== '' ? $dir : (defined('ROOT') ? ROOT : dirname(__DIR__)) . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @session_save_path($dir);




        if (mt_rand(1, 250) === 1) {
            $old = time() - $life - 86400;
            foreach (glob($dir . '/sess_*') ?: [] as $f) {
                if (@filemtime($f) < $old) {
                    @unlink($f);
                }
            }
        }
    }
    session_set_cookie_params([
        'lifetime' => $life,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    session_boot();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    session_boot();
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $have = (string)($_SESSION['csrf'] ?? '');

    if ($have === '' || !is_string($t) || $t === '' || !hash_equals($have, $t)) {




        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');


        exit('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Сессия устарела</title></head><body>'
            . '<div style="font:16px/1.6 system-ui;max-width:560px;margin:60px auto;padding:0 20px">'
            . '<p><b>Сессия устарела — форма не сохранена.</b></p>'
            . '<p>Черновики текстов заметок и страниц хранятся в браузере и будут предложены при повторном открытии '
            . 'редактора. Выбор и порядок фото, меню и настройки нужно будет повторить.</p>'
            . '<p><a href="javascript:history.back()" style="display:inline-block;padding:8px 16px;border:1px solid #999;border-radius:8px;text-decoration:none;color:inherit">← Назад к форме</a> '
            . '<a href="' . admin_base() . '?p=login" target="_blank" rel="noopener" style="display:inline-block;padding:8px 16px;border:1px solid #999;border-radius:8px;text-decoration:none;color:inherit">Войти в новой вкладке</a></p>'
            . '<p style="color:#777;font-size:14px">После входа в новой вкладке вернитесь сюда и отправьте форму ещё раз.</p>'
            . '</div></body></html>');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    session_boot();
    $_SESSION['flash'][] = [$type, $msg];
}

function flashes(): array
{
    session_boot();
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}


function is_admin(bool $reset = false): bool
{




    static $cached = null;
    if ($reset) {
        $cached = null;
        return false;
    }
    if ($cached !== null) {
        return $cached;
    }
    if (public_view()) {
        return $cached = false;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $sid = $_COOKIE['ptsid'] ?? '';
        if (!is_string($sid) || !preg_match('~^[A-Za-z0-9,-]{1,128}$~D', $sid)
            || !is_file(cfg('session_path', ROOT . '/storage/sessions') . '/sess_' . $sid)) {
            return $cached = false;
        }
    }
    session_boot();
    return $cached = admin_session_ok();
}






function public_view(?bool $on = null): bool
{
    static $force = false;
    if ($on !== null) {
        $force = $on;
    }
    return $force;
}


function pass_version(string $passHash): string
{
    return substr(hash('sha256', $passHash), 0, 16);
}


function session_clear(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
    admin_session_ok(true);
    is_admin(true);
}







function admin_session_ok(bool $reset = false, bool $touch = true): bool
{
    static $ok = null;
    if ($reset) {
        $ok = null;
        return false;
    }
    if ($ok !== null) {
        return $ok;
    }
    $uid = (int)($_SESSION['uid'] ?? 0);
    if ($uid <= 0) {
        return $ok = false;
    }
    $t = time();
    if ($t - (int)($_SESSION['t0'] ?? 0) > SESSION_LIFETIME || $t - (int)($_SESSION['last'] ?? 0) > SESSION_IDLE) {
        session_clear();
        return $ok = false;
    }
    $hash = db_val('SELECT pass_hash FROM users WHERE id = ?', [$uid]);
    if ($hash === null || $hash === false || !hash_equals((string)($_SESSION['pwv'] ?? ''), pass_version((string)$hash))) {
        session_clear();
        return $ok = false;
    }
    if ($touch) {
        $_SESSION['last'] = $t;
    }
    return $ok = true;
}












function admin_slug(): string
{
    $s = trim((string)cfg('admin_path', 'admin'), "/ \t");
    return preg_match('~^[A-Za-z0-9_-]{1,64}$~', $s) ? $s : 'admin';
}


function admin_base(): string
{
    return '/' . admin_slug() . '/';
}

function htaccess_template(): string
{





    $gate = '';
    $slug = admin_slug();
    if ($slug !== 'admin') {









        $gate = "\n# Админка спрятана (1.9.27): /admin/ снаружи отвечает 404, вход — по секретному адресу\n"
            . 'RewriteRule ^' . $slug . '(?:/(.*))?$ admin/$1 [L,QSA,E=ADMINGATE:1]' . "\n"
            . "RewriteCond %{ENV:REDIRECT_ADMINGATE} !^1\$\n"
            . "RewriteRule ^admin(/|\$) - [R=404,L]\n";
    }
    return str_replace('#__ADMIN_GATE__', $gate, <<<'HTACCESS'
# md (README/CHANGELOG/инструкция) закрыты: история версий и уязвимостей не для публичного доступа
# Apache 2.4 + mod_rewrite (для nginx см. README.md)
# -MultiViews обязателен: иначе Apache может отдать install.php/img.php
# вместо страницы, если её адрес совпал с именем файла
Options -Indexes -MultiViews
DirectoryIndex index.php

<IfModule mod_rewrite.c>
RewriteEngine On
# CMS рассчитана на установку в КОРЕНЬ домена (аудит 1.9.26): адреса, canonical,
# sitemap и админка строятся от «/», работа из подпапки (site.ru/foto/) не поддерживается.

# --- HTTPS и склейка зеркал (www/non-www) ---
# Когда в админке задан «Адрес сайта с https://» (Настройки → Основное),
# CMS сама делает 301 на канонический хост и схему (index.php) — здесь ничего
# включать не нужно. Правила ниже — запасной вариант на уровне Apache:
# RewriteCond %{HTTPS} off
# RewriteCond %{HTTP:X-Forwarded-Proto} !https
# RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
# RewriteCond %{HTTP_HOST} ^www\.(.+)$ [NC]
# RewriteRule ^ https://%1%{REQUEST_URI} [L,R=301]

# Закрытые каталоги и служебные файлы
RewriteRule ^(app|storage|templates|tools|import)(/|$) - [F,L]
RewriteRule ^(config\.php|config\.sample\.php|README\.md)$ - [F,L]
# Служебные каталоги систем контроля версий: при выкладке не zip-архивом, а прямо из git
# содержимое .git (HEAD, config, объекты) отдавалось бы как обычные файлы — утечка исходников
# и истории (аудит 1.9.18, находка 1). Остальные «точка-файлы» не трогаем: на хостингах
# по адресу /.well-known/acme-challenge/ живёт продление SSL-сертификата.
RewriteRule ^\.(git|svn|hg)(/|$) - [F,L]
#__ADMIN_GATE__
# Существующие файлы и папки отдаём как есть
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Вариант картинки ещё не создан — генерируем при первом запросе, дальше он отдаётся как статика
RewriteRule ^media/(\d+)/(\d+)/([A-Za-z0-9_\-]+)\.(jpg|webp|avif|png)$ img.php?id=$1&w=$2&slug=$3&fmt=$4 [L,QSA]

# Всё остальное — во фронт-контроллер
RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
# setifempty БЕЗ always: «always» правит отдельную таблицу err_headers_out, которая при
# успешном ответе СКЛЕИВАЕТСЯ с headers_out — одноимённый заголовок от PHP дублировался бы.
# Обычный setifempty видит уже выставленные PHP-заголовки и не дублирует их; цена —
# на служебных ответах самого Apache (403 от правил [F]) этих заголовков нет, что безопасно:
# все динамические ответы (включая 404 от CMS) их получают из PHP (1.9.20).
Header setifempty X-Content-Type-Options "nosniff"
Header setifempty Referrer-Policy "strict-origin-when-cross-origin"
Header setifempty X-Frame-Options "SAMEORIGIN"
Header setifempty Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=()"
# Когда сайт стабильно работает по HTTPS, раскомментируйте (браузеры запомнят, что http не нужен):
# Header always set Strict-Transport-Security "max-age=31536000"
<FilesMatch "\.(jpg|webp|avif|png|mp4|woff2|css|js|svg)$">
  Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>
</IfModule>

<IfModule mod_mime.c>
# Apache старых выпусков не знает MIME для .avif — без этого отдаст octet-stream (1.9.48)
AddType image/avif .avif
</IfModule>

<IfModule mod_deflate.c>
# text/javascript — официальный тип JS с RFC 9239 (часть серверов отдаёт его вместо application/javascript);
# rss+xml и ld+json добавлены: feed и разметка тоже заметно жмутся (ревизия 1.9.34, п.21)
AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json application/xml application/rss+xml application/ld+json image/svg+xml
</IfModule>

<FilesMatch "(?i)\.(sqlite|sqlite3|db|log|lock|off|md)$">
  Require all denied
</FilesMatch>

# Скрытые служебные файлы (.env, .gitignore, .htpasswd, .user.ini, .DS_Store и т.п.) —
# не для выдачи наружу, даже если случайно попали в вебрут при деплое (аудит 1.9.26).
# Проверка ACME-сертификата не ломается: файлы в .well-known/ сами по имени не dotfiles.
<FilesMatch "^\.">
  Require all denied
</FilesMatch>
HTACCESS
    ) . "\n";
}



function htaccess_needs_update(): bool
{
    $f = ROOT . '/.htaccess';
    if (!is_file($f)) {
        return false;
    }
    $c = (string)file_get_contents($f);
    if (!str_contains($c, 'jpg|webp|avif|png')) {
        return true;
    }
    $slug = admin_slug();
    if ($slug !== 'admin') {

        if (!str_contains($c, 'REDIRECT_ADMINGATE')) {
            return true;
        }

        return !str_contains($c, 'RewriteRule ^' . $slug . '(?:/');
    }
    return str_contains($c, '# Админка спрятана');
}


function htaccess_missing(): bool
{
    $f = ROOT . '/.htaccess';
    return !is_file($f) || filesize($f) < 50;
}


function htaccess_fix(): ?string
{



    $f = ROOT . '/.htaccess';
    if (is_file($f)) {
        @copy($f, ROOT . '/.htaccess.bak-' . date('Ymd-His'));
    }
    $tmp = ROOT . '/.htaccess.tmp-' . getmypid();
    if (@file_put_contents($tmp, htaccess_template()) === false || !@rename($tmp, $f)) {
        @unlink($tmp);
        return 'Не удалось записать файл — нет прав на запись в корень сайта. '
            . 'Создайте .htaccess вручную: возьмите его из архива пакета и загрузите рядом с index.php '
            . '(в FTP-клиенте включите показ скрытых файлов).';
    }
    return null;
}



function plural_form(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    $d = $n % 10;
    if ($n > 10 && $n < 20) {
        return $many;
    }
    if ($d > 1 && $d < 5) {
        return $few;
    }
    if ($d === 1) {
        return $one;
    }
    return $many;
}
