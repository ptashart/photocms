<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

require_once __DIR__ . '/constants.php';

/* Минимальная версия — PHP 8.0: код использует match, named arguments и str_* функции восьмёрки.
   Полифиллы str_* для PHP 7.x убраны: они создавали ложное ощущение поддержки семёрки,
   которая всё равно падала бы на match. */

/* ---------- журнал ошибок и человеческая страница вместо белого экрана ---------- */

/** Пишет ошибку в storage/error.log — на шаред-хостинге display_errors выключен, иначе причину 500 не увидеть. */
function app_log_error(string $msg): void
{
    $dir = defined('ROOT') ? ROOT . '/storage' : __DIR__ . '/../storage';
    /* Все управляющие символы — в пробелы, не только \r\n: в лог попадают имена загружаемых
       файлов из исключений, а ESC-последовательности (\x1b[…) исполняются эмулятором
       терминала при просмотре журнала (аудит 1.9.13, находка 3). Класс \x7f-\x9f режем
       с флагом /u (кодпоинты C1) — побайтово он портил бы кириллицу; если сообщение не
       UTF-8 (бинарное имя файла), откатываемся на побайтовую фильтрацию C0+DEL. */
    $clean = preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', $msg);
    if ($clean === null) {
        $clean = (string)preg_replace('/[\x00-\x1f\x7f]/', ' ', $msg);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $clean . "\n";
    $file = $dir . '/error.log';
    /* ротация: один архивный файл, чтобы журнал не рос бесконечно */
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

/**
 * Заголовки безопасности, которые .htaccess ставит через mod_headers.
 * Дублируем из PHP (1.9.20): без mod_headers (часть nginx-связок и редких сборок Apache)
 * блок <IfModule mod_headers.c> молча не срабатывал, и сайт оставался без nosniff,
 * Referrer-Policy, X-Frame-Options и Permissions-Policy. Когда mod_headers есть,
 * его `Header setifempty` выставляет их, только если PHP уже не выставил — дублей не возникает.
 * Ограничение: статику (уже созданные варианты фото, css, шрифты) PHP не обслуживает —
 * для неё заголовки по-прежнему даёт только веб-сервер, поэтому блок в .htaccess сохраняется.
 * Вызывается из bootstrap для всех динамических ответов: страницы, админка, img.php,
 * feed.xml, sitemap.xml, 404.
 */
function security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
}

/** Ставится как можно раньше (в bootstrap.php сразу после подключения helpers.php). */
function app_register_error_handlers(): void
{
    /* Предупреждения и notice тоже пишем в журнал (раньше терялись при display_errors=0).
       Подавленные «@» не логируем; deprecated не логируем, чтобы не засорять файл. Возвращаем false — штатная обработка продолжается. */
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
            /* заголовки ещё не ушли — выбрасываем недорисованный буфер и показываем страницу ошибки */
            if (!headers_sent()) {
                /* буфер может оказаться несъёмным (zlib.output_compression=On на шаред-хостинге):
                   без выхода по неудаче это бесконечный цикл (аудит 1.9.48, п.3) */
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
    /* Путь к журналу на публичной странице не называем: это подсказка, где искать служебные файлы */
    echo '<!doctype html><meta charset="utf-8"><p style="font:16px system-ui;padding:40px">'
        . 'Внутренняя ошибка сайта. Подробная причина записана в журнал ошибок (доступен владельцу сайта).'
        . ($details !== '' ? '</p><pre style="font:13px monospace;padding:0 40px">' . e($details) . '</pre>' : '</p>');
}

/** Необязательный config.php в корне: return ['db' => '...', 'debug' => false, 'timezone' => 'Europe/Moscow']; */
function cfg(string $key, $default = null)
{
    static $conf = null;
    if ($conf === null) {
        $f = ROOT . '/config.php';
        $conf = is_file($f) ? (array)(require $f) : [];
    }
    return $conf[$key] ?? $default;
}

/** Совпадает ли If-None-Match с ETag: по RFC заголовок — список тегов, может содержать W/-префикс и «*». */
function etag_matches(string $etag): bool
{
    $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($inm === '') {
        return false;
    }
    foreach (explode(',', $inm) as $t) {
        $t = trim($t);
        if (stripos($t, 'W/') === 0) {
            $t = trim(substr($t, 2)); // слабый тег считаем совпадением (тела побайтово одинаковы)
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

/**
 * Максимальная дата изменения контента текущего ответа (для Last-Modified).
 * Рендеры страниц/заметок/списков кладут сюда свой updated_at, фронт-контроллер читает итог.
 */
function content_lastmod(?int $ts = null): int
{
    static $max = 0;
    if ($ts !== null && $ts > $max) {
        $max = $ts;
    }
    return $max;
}

/**
 * Отметка «публичный контент изменился» (Unix-время в настройке site_rev). Фронт-контроллер
 * подмешивает её в Last-Modified: раньше правка настроек (тема, телефон, код вставок) или меню
 * не меняла дату ни одной страницы, и поисковик держал старый снимок до случайного переобхода
 * (ревизия 1.9.34, п.1). Вызывают set_setting() и записи меню; прямые UPDATE с timestamp'ом
 * (pages.updated_at и т.п.) в этом не нуждаются.
 */
function site_touch(): void
{
    /* Одна отметка на запрос (аудит 1.9.40, п.12): set_setting() вызывает нас на каждый ключ,
       и сохранение настроек (~60 полей) писало site_rev 60 раз подряд с тем же значением. */
    static $doneAt = 0;
    $t = time();
    if ($doneAt === $t) {
        return;
    }
    $doneAt = $t;
    try {
        /* rev обязан РАСТИ при каждой правке, а не просто равняться времени (1.9.72):
           две правки в одну секунду (скрипт-импорт, быстрая смена темы) давали тот же
           site_rev, и файловый кэш страниц отдавал HTML со старыми настройками */
        $prev = (int)(db_val("SELECT v FROM settings WHERE k = 'site_rev'") ?? 0);
        $rev = (string)max($t, $prev + 1);
        db_run("INSERT OR REPLACE INTO settings (k, v) VALUES ('site_rev', ?)", [$rev]);
        /* как set_setting(): внутри транзакции кэш не трогаем, лишь отмечаем «грязность» —
           db_tx() перечитает настройки после commit/rollback */
        if (db()->inTransaction()) {
            $GLOBALS['settings_dirty'] = true;
        } elseif (function_exists('settings_all')) {
            settings_all(false, ['site_rev' => $rev]);
        }
    } catch (Throwable $ignore) {
        // база ещё не готова (установка) — просто нечего отмечать
    }
}

/** Владельческий HTML помечается до сохранения в pagecache. На отдаче только он получит nonce
 * и внешние источники CSP; скрипт, попавший в страницу иным путём, доверенным не станет. */
function public_custom_code(string $code): string
{
    if ($code === '') {
        return '';
    }
    $code = preg_replace_callback('~<script\b([^>]*)>~i', static function (array $m): string {
        if (preg_match('~\bsrc\s*=~i', $m[1])) {
            return $m[0];
        }
        $attrs = preg_replace('~\s+nonce\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i', '', $m[1]);
        return '<script nonce="__PHOTOCMS_CSP_NONCE__"' . $attrs . '>';
    }, $code) ?? $code;
    return '<!--PHOTOCMS_CSP_BEGIN-->' . $code . '<!--PHOTOCMS_CSP_END-->';
}

/** HTTPS-origin из явно вставленного владельцем кода для конкретной CSP-директивы. */
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
    return "Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; "
        . "style-src 'self' 'unsafe-inline'" . $suffix($style)
        . "; script-src 'self' 'nonce-" . $nonce . "'" . $suffix($script)
        . "; font-src 'self' data:; media-src 'self'" . $suffix($media)
        . "; frame-src 'self'" . $suffix($frame)
        . "; object-src 'none'; base-uri 'self'; form-action 'self'" . $suffix($form)
        . "; frame-ancestors 'self'";
}

/** Nonce подставляется после чтения pagecache, поэтому кэш и ETag не зависят от случайного значения. */
function public_csp_send(string $html): string
{
    $trusted = '';
    if (preg_match_all('~<!--PHOTOCMS_CSP_BEGIN-->(.*?)<!--PHOTOCMS_CSP_END-->~s', $html, $m)) {
        $trusted = implode("\n", $m[1]);
    }
    $html = str_replace(['<!--PHOTOCMS_CSP_BEGIN-->', '<!--PHOTOCMS_CSP_END-->'], '', $html);
    $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    $html = str_replace('__PHOTOCMS_CSP_NONCE__', $nonce, $html);
    header(public_csp($trusted, $nonce));
    return $html;
}

/** json_encode для записи JSON в базу: кириллица читаемая, слэши не экранируются.
    Единая точка, чтобы новые места не забывали флаги (ревизия 1.9.34, п.14). */
function json_db($value): string
{
    return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Хэш-заглушка для password_verify() при несуществующем логине (выравнивание времени ответа).
 * До 1.9.35 был зашит константой в коде и светился в публичном архиве пакета; теперь
 * генерируется один раз и лежит в storage/.dummy-hash (файл закрыт .htaccess и правами 0600,
 * содержимое бесполезно: хэш случайной строки). При росте стоимости PASSWORD_DEFAULT
 * перегенерируется (ревизия 1.9.34, п.13).
 */
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

/**
 * Адреса, которые нельзя отдавать страницам: системные пути (RESERVED_SLUGS) + разделы заметок.
 * Живёт здесь, а не в content.php — список нужен и раннему роутингу (ревизия 1.9.34, п.22).
 * Мемоизировано по сырому JSON разделов (та же схема, что в post_sections()): список не
 * пересобирается десятки раз за запрос, а после смены настройки пересчитывается один раз.
 */
function reserved_slugs(): array
{
    static $memo = [];
    $rawJson = (string)setting('post_sections');
    if (isset($memo[$rawJson])) {
        return $memo[$rawJson];
    }
    $r = RESERVED_SLUGS;
    /* секретный адрес админки (admin_path в config.php, 1.9.27) тоже нельзя отдавать странице:
       rewrite-правило админки сильнее фронт-контроллера, и такая страница была бы недоступна
       (аудит 1.9.40, п.15). Slug страниц — в нижнем регистре, поэтому добавляем оба варианта. */
    foreach ([admin_slug(), strtolower(admin_slug())] as $as) {
        if (!in_array($as, $r, true)) {
            $r[] = $as;
        }
    }
    // адреса разделов заметок тоже нельзя отдавать страницам
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
    /* X-Forwarded-Proto подделывается клиентом — верим ему только от доверенного прокси
       (как в client_ip()), иначе атакующий «включает» себе https и обходит secure-куки/canonical */
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
    /* config.php: 'allowed_hosts' => ['site.ru', 'www.site.ru'] — чужой Host не попадёт в canonical/sitemap/OG.
       Проверяем ПЕРВЫМ делом и всегда, когда список задан. */
    $allowed = cfg('allowed_hosts', []);
    if (is_array($allowed) && $allowed) {
        $allowed = array_map(static fn($x) => strtolower((string)$x), $allowed);
        return in_array($h, $allowed, true) ? $h : (string)$allowed[0];
    }
    /* Списка нет — сверяемся с «Адресом сайта» из настроек: он задан владельцем, и подставной Host
       (Host-header poisoning в canonical/sitemap/RSS/OG) не должен маскироваться под чужой домен */
    $base = '';
    try {
        $base = trim((string)setting('base_url'));
    } catch (Throwable $ignore) { // база ещё не готова (установка) — просто нет с чем сверять
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

/** Точный IP или подсеть IPv4/IPv6 в нотации CIDR. */
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

/** Адрес или CIDR из списка доверенных прокси (config.php: 'trusted_proxies'). */
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

/**
 * IP клиента. Заголовкам CF-Connecting-IP / X-Forwarded-For верим, только если запрос пришёл
 * от доверенного прокси, иначе их подделывает любой посетитель.
 */
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

/** Канонический адрес сайта: из настроек, иначе по текущему запросу. */
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

/** Размер файла короткой строкой: «512 КБ», «3,4 МБ» (карточка «Здоровье базы», с 1.9.11). */
function fmt_mb(int $bytes): string
{
    if ($bytes < 1048576) {
        return number_format(max(0, $bytes) / 1024, 0, ',', ' ') . ' КБ';
    }
    return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
}

function redirect(string $to, int $code = 302): void
{
    /* Управляющие символы убираем. «//host» и «/\host» браузеры считают внешним адресом — это открытый редирект,
       поэтому такие значения (и всё, что начинается с обратного слеша) заменяем на главную. */
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

/** Приводит введённый вручную адрес к допустимому виду: «Свадьба в Москве» → «svadba-v-moskve». */
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

/** Ссылка из настроек: без схемы («t.me/name») → https://t.me/name; опасные схемы отбрасываются. */
function normalize_url(string $u): string
{
    $u = trim($u);
    if ($u === '') {
        return '';
    }
    /* «//host» и «/\host» — protocol-relative URL: выглядит как локальный путь, но браузер
       уходит на чужой домен. Делаем схему явной, чтобы проверки ниже видели реальный адрес. */
    if (strlen($u) > 1 && strpos('/\\', $u[0]) !== false && strpos('/\\', $u[1]) !== false) {
        return 'https://' . preg_replace('~^[/\\\\]+~', '', $u);
    }
    if (preg_match('~^(https?://|mailto:|tel:|/|#)~i', $u)) {
        return $u;
    }
    if (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $u)) {
        return ''; // javascript:, data: и т.п.
    }
    return 'https://' . ltrim($u, '/');
}

/** Ссылка, пригодная для href: опасные схемы (javascript:, data:) превращаются в «#». */
function safe_href(string $u): string
{
    $u = normalize_url($u);
    return $u !== '' ? $u : '#';
}

/** Строка из $_POST: массив/отсутствие → '', обрезка до $max символов (0 — без обрезки). */
function post_str(string $key, int $max = 0): string
{
    $v = $_POST[$key] ?? '';
    $v = is_string($v) ? trim($v) : '';
    return ($max > 0 && mb_strlen($v) > $max) ? mb_substr($v, 0, $max) : $v;
}

/** Массив из $_POST: не массив → []. */
function post_arr(string $key): array
{
    $v = $_POST[$key] ?? [];
    return is_array($v) ? $v : [];
}

/** Обрезка пользовательской строки до $max символов (серверный предел длины вместо одного maxlength в HTML). */
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

/* ---------- сессия, CSRF, флеш-сообщения ---------- */

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ptsid');
    /* strict_mode: PHP отбрасывает чужие/придуманные идентификаторы сессии (session fixation);
       use_only_cookies: id только в куке, никогда в URL */
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    /* Долгая сессия: по умолчанию PHP «забывает» вошедшего примерно через 24 минуты простоя —
       при долгом наборе заметки сохранение падало с ошибкой «Сессия устарела». Хранилище сессий
       кладём в storage/sessions (закрыто от веба), чтобы его не чистили системные скрипты хостинга. */
    $life = SESSION_LIFETIME;
    @ini_set('session.gc_maxlifetime', (string)$life);
    $dir = (defined('ROOT') ? ROOT : dirname(__DIR__)) . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        @session_save_path($dir);
        /* На части хостингов (Debian/Ubuntu-сборки PHP) штатная сборка мусора отключена, а системный cron
           чистит другой каталог — файлы сессий копились бы вечно. Чистим сами, но редко (1/~250 запусков,
           раньше 1/50): glob по каталогу — O(N) по числу сессионных файлов, на нагруженном сайте
           периодический скан был заметен (аудит 1.9.26). Сессии при этом всё равно чистятся раз в часы. */
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
    /* Раньше пустой токен в сессии и пустой токен в запросе считались равными ('' === '') — проверка проходила. */
    if ($have === '' || !is_string($t) || $t === '' || !hash_equals($have, $t)) {
        /* Был 419 («Authentication Timeout», нестандартный): Apache под mod_php превращает
           неизвестные ему коды в 500 — браузер и владелец видели «ошибку сервера» вместо
           понятной страницы. 403 стандартен, доезжает как есть и по смыслу верен:
           запрос отклонён из-за недействительного токена. */
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        /* честно: автодраф хранит только тексты редакторов (заметки и body_html страниц),
           а загрузки фото, порядок галереи, меню и настройки нужно повторить */
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

/** На публичной части сессию поднимаем только для существующего файла сессии (предпросмотр черновиков). */
function is_admin(bool $reset = false): bool
{
    /* Кэш результата на запрос (аудит 1.9.40, п.11): после session_write_close() (предпросмотр
       заметки/страницы, медиатека) повторный is_admin() заново открывал сессию через
       session_boot() — блокировка файла сессии бралась снова (смысл раннего освобождения
       терялся), а при output_buffering=0 после начала вывода шёл warning «headers already sent». */
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
            || !is_file(ROOT . '/storage/sessions/sess_' . $sid)) {
            return $cached = false;
        }
    }
    session_boot();
    return $cached = admin_session_ok();
}

/**
 * Принудительно «публичный» взгляд на контент: is_admin() начинает отвечать false независимо от сессии.
 * Включается в RSS и других кэшируемых выдачах: иначе владелец, открывший ленту залогиненным,
 * получил бы в ней пометки «(черновик)» и ссылки на админку — и они ушли бы в публичный кэш.
 */
function public_view(?bool $on = null): bool
{
    static $force = false;
    if ($on !== null) {
        $force = $on;
    }
    return $force;
}

/** Отпечаток пароля: смена пароля делает недействительными все остальные открытые сессии. */
function pass_version(string $passHash): string
{
    return substr(hash('sha256', $passHash), 0, 16);
}

/** Сбрасывает данные сессии (выход или принудительное завершение). */
function session_clear(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
    admin_session_ok(true);
    is_admin(true); // кэш is_admin() — тоже (аудит 1.9.40, п.11)
}

/**
 * Сессия действительна: пользователь существует, пароль не менялся с момента входа,
 * не вышел срок жизни и простой. Результат кэшируется на запрос; reset=true — сбросить кэш (вход/выход/смена пароля).
 * touch=false — не продлевать «последнюю активность»: так работает лёгкий ping из админки,
 * который сам по себе не должен отодвигать таймер простоя (ревизия 1.9.34, п.10).
 */
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

/* ---------- самовосстановление .htaccess ---------- */

/**
 * Эталонное содержимое корневого .htaccess.
 * ДЕРЖАТЬ В СИНХРОНЕ с файлом /.htaccess в корне пакета.
 * Нужно, потому что FTP-клиенты и файл-менеджеры часто «теряют» скрытый файл
 * при загрузке — и все виртуальные адреса (блог, альбомы, sitemap) начинают 404-ить.
 */
/** Адрес админки: по умолчанию «admin», переопределяется в config.php ключом 'admin_path'
   (1.9.27, security through obscurity как дополнительный слой). Только [A-Za-z0-9_-]:
   буквы/цифры/дефис/подчёркивание — безопасно для .htaccess и URL. Нечто иное → «admin». */
function admin_slug(): string
{
    $s = trim((string)cfg('admin_path', 'admin'), "/ \t");
    return preg_match('~^[A-Za-z0-9_-]{1,64}$~', $s) ? $s : 'admin';
}

/** Базовый путь админки с ведущим и замыкающим слэшами: '/admin/' или '/<slug>/'. */
function admin_base(): string
{
    return '/' . admin_slug() . '/';
}

function htaccess_template(): string
{
    /* Спрятанная админка (1.9.27): когда в config.php задан 'admin_path', физическая папка
       /admin/ снаружи отдаёт 404 (маяк для ботов исчезает), а секретный адрес внутренне
       переписывается на неё. Своё же правило /слаг/ → admin/ помечается env-флагом, чтобы
       запрет /admin/ его не задел при внутреннем rewrite. Внешнего 301 /admin/ → слаг
       нет НАМЕРЕННО: редирект сообщал бы сканеру настоящий адрес. */
    $gate = '';
    $slug = admin_slug();
    if ($slug !== 'admin') {
        /* Запрет через правило по декодированному пути + маркер-флаг вместо THE_REQUEST
           (аудит 1.9.48, п.6): THE_REQUEST — сырая строка запроса, и /%61dmin/ проходил мимо
           запрета, тогда как Apache сам путь декодирует. RewriteRule тестирует уже
           декодированный путь, а чтобы собственное правило слага (slug → admin/) не попало
           под запрет при внутреннем rewrite, ставим env-флаг: после внутреннего редиректа
           он виден как REDIRECT_ADMINGATE=1. Внешнего 301 /admin/ → слаг нет НАМЕРЕННО —
           редирект сообщал бы сканеру настоящий адрес. Цена: 404 для /admin/ серверная,
           без вёрстки сайта — принято, «отпечаток» такого ответа ничего не говорит о том,
           куда переехала админка. */
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

/** .htaccess существует, но не соответствует текущему admin_path: нет правил скрытия
   при заданном слаге — или они остались после возврата к «admin» (1.9.27). */
function htaccess_needs_update(): bool
{
    $f = ROOT . '/.htaccess';
    if (!is_file($f)) {
        return false; // это случай htaccess_missing()
    }
    $c = (string)file_get_contents($f);
    if (!str_contains($c, 'jpg|webp|avif|png')) {
        return true; // шаблон обновился (1.9.48: avif-варианты через img.php, кэш-заголовки mp4)
    }
    $slug = admin_slug();
    if ($slug !== 'admin') {
        /* старый шаблон без REDIRECT_ADMINGATE пропускал закодированный /%61dmin/ */
        if (!str_contains($c, 'REDIRECT_ADMINGATE')) {
            return true;
        }
        /* слаг сменился или правил скрытия ещё нет — оба случая требуют перезаписи */
        return !str_contains($c, 'RewriteRule ^' . $slug . '(?:/');
    }
    return str_contains($c, '# Админка спрятана');
}

/** Корневой .htaccess отсутствует или пуст (недозагружен). */
function htaccess_missing(): bool
{
    $f = ROOT . '/.htaccess';
    return !is_file($f) || filesize($f) < 50;
}

/** Создаёт/восстанавливает корневой .htaccess. Возвращает сообщение об ошибке или null при успехе. */
function htaccess_fix(): ?string
{
    /* Через временный файл + rename (аудит 1.9.40, п.15): прямая запись в .htaccess при обрыве
       (место кончилось, процесс убит) оставляла урезанный файл — и сайт падал с 500 до
       следующего захода в админку. */
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


/** Русская плюрализация: plural_form(5, 'фотография', 'фотографии', 'фотографий'). */
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
