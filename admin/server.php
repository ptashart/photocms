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

require_once ROOT . '/app/server_components.php';

function server_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $number = (float)preg_replace('/[^0-9.]+$/', '', $value);
    $unit = strtolower(substr($value, strlen((string)$number)));
    $factor = match (trim($unit)) {
        'g', 'gb', 'gib' => 1073741824,
        'm', 'mb', 'mib' => 1048576,
        'k', 'kb', 'kib' => 1024,
        default => 1,
    };
    return (int)round($number * $factor);
}

function server_format_bytes(float $bytes): string
{
    if ($bytes < 0) {
        return 'не сообщается';
    }
    foreach (['B', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $unit) {
        if ($bytes < 1024 || $unit === 'ТБ') {
            return ($bytes < 10 && $unit !== 'B' ? number_format($bytes, 1, ',', '') : (string)round($bytes)) . ' ' . $unit;
        }
        $bytes /= 1024;
    }
    return (string)$bytes;
}

function server_cms_update(): array
{
    $file = ROOT . '/storage/cache/update-check.json';
    $cached = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    if (is_array($cached) && time() - (int)($cached['checked_at'] ?? 0) < 43200) {
        return $cached;
    }
    $result = ['checked_at' => time(), 'latest' => '', 'url' => 'https://github.com/ptashart/photocms/releases'];
    $raw = false;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.github.com/repos/ptashart/photocms/releases/latest');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'PhotoCMS/' . APP_VERSION,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
        ]);
        $raw = curl_exec($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
    } elseif (ini_get('allow_url_fopen')) {
        $raw = @file_get_contents('https://api.github.com/repos/ptashart/photocms/releases/latest', false, stream_context_create(['http' => ['timeout' => 4, 'user_agent' => 'PhotoCMS/' . APP_VERSION]]));
    }
    if (is_string($raw) && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json) && is_string($json['tag_name'] ?? null)) {
            $result['latest'] = ltrim($json['tag_name'], 'vV');
            $result['url'] = (string)($json['html_url'] ?? $result['url']);
        }
    }
    @file_put_contents($file, json_db($result), LOCK_EX);
    return $result;
}

function server_collect(): array
{
    $warnings = [];
    if (PHP_VERSION_ID < 80000) {
        $warnings[] = 'PHP старше 8.0: CMS не поддерживает эту версию. Обновите PHP в панели хостинга.';
    } elseif (PHP_VERSION_ID < 80200) {
        $warnings[] = 'PHP ' . PHP_VERSION . ' устарел для новой установки. Рекомендуется PHP 8.3 или новее.';
    }
    foreach (['pdo_sqlite' => 'PDO SQLite', 'mbstring' => 'mbstring', 'dom' => 'DOM'] as $extension => $label) {
        if (!extension_loaded($extension)) {
            $warnings[] = 'Не найден обязательный компонент: ' . $label . '.';
        }
    }
    if (!extension_loaded('gd') && !extension_loaded('imagick')) {
        $warnings[] = 'Нет GD или ImageMagick: обработка фотографий невозможна.';
    }
    foreach ([ROOT . '/storage', ROOT . '/media'] as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            $warnings[] = 'Нет записи в ' . $dir . '.';
        }
    }
    $memoryLimit = server_bytes((string)ini_get('memory_limit'));
    if ($memoryLimit >= 0 && $memoryLimit < 268435456) {
        $warnings[] = 'memory_limit PHP меньше 256 МБ. Большие фотографии могут не обрабатываться.';
    }
    $upload = server_bytes((string)ini_get('upload_max_filesize'));
    $post = server_bytes((string)ini_get('post_max_size'));
    if ($upload > 0 && $post > 0 && $upload > $post) {
        $warnings[] = 'upload_max_filesize больше post_max_size: загрузки будут ограничены POST-лимитом.';
    }
    $cores = 0;
    if (is_readable('/proc/cpuinfo')) {
        $cores = preg_match_all('/^processor\s*:/m', (string)file_get_contents('/proc/cpuinfo'));
    }
    $load = sys_getloadavg();
    $load = is_array($load) ? $load : [];
    if ($cores > 0 && isset($load[0]) && $load[0] > $cores * 2) {
        $warnings[] = 'Средняя загрузка CPU за минуту превышает удвоенное число ядер.';
    }
    $memory = [];
    if (is_readable('/proc/meminfo')) {
        foreach (preg_split('/\R/', (string)file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB/', $line, $m)) {
                $memory[$m[1]] = (int)$m[2] * 1024;
            }
        }
        if (isset($memory['MemTotal'], $memory['MemAvailable']) && $memory['MemAvailable'] < $memory['MemTotal'] * .1) {
            $warnings[] = 'Доступной памяти сервера меньше 10%.';
        }
    }
    $diskFree = disk_free_space(ROOT);
    $diskTotal = disk_total_space(ROOT);
    if ($diskFree !== false && $diskTotal > 0 && ($diskFree < 524288000 || $diskFree < $diskTotal * .1)) {
        $warnings[] = 'Свободного места на диске мало. Удалите старые копии и ненужные файлы.';
    }
    $reboot = file_exists('/var/run/reboot-required') || file_exists('/run/reboot-required');
    if ($reboot) {
        $warnings[] = 'Операционная система сообщает, что требуется перезагрузка. Выполните её через панель хостера, а не через CMS.';
    }
    return [
        'warnings' => $warnings,
        'load' => $load,
        'cores' => $cores,
        'memory' => $memory,
        'disk_free' => $diskFree,
        'disk_total' => $diskTotal,
        'reboot' => $reboot,
    ];
}

function admin_server(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && post_str('action') === 'server_refresh') {
        csrf_check();
        component_versions(APP_VERSION, true);
        flash('Информация о релизах обновлена.');
        redirect(a_url('server'));
    }
    $state = server_collect();
    $componentData = component_versions(APP_VERSION);
    $components = $componentData['components'];
    $updates = array_filter($components, static fn(array $component): bool => $component['status']['state'] === 'update');
    $gd = extension_loaded('gd') ? gd_info() : [];
    $web = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));
    if ($web === '' && function_exists('apache_get_version')) {
        $web = trim((string)apache_get_version());
    }
    if ($web === '') {
        $web = 'Не сообщается (PHP-FPM или reverse proxy)';
    }
    $imagick = 'не установлен';
    if (extension_loaded('imagick')) {
        $v = Imagick::getVersion();
        $imagick = 'ImageMagick ' . (string)($v['versionString'] ?? '') . ' · PHP Imagick ' . (string)phpversion('imagick');
    }
    $uptime = is_readable('/proc/uptime') ? (float)explode(' ', (string)file_get_contents('/proc/uptime'))[0] : 0;
    $dbSize = is_file(db_path()) ? (int)filesize(db_path()) : 0;
    $opcache = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    admin_header('Сервер', 'server');
    ?>
<h1>Состояние сервера</h1>
<p class="muted">Показания только для чтения. На shared hosting часть данных о сервере может быть недоступна — CMS не перезагружает сервер и не устанавливает системные пакеты.</p>
    <?php if ($state['warnings']): ?>
<div class="flash err" role="alert"><strong>Нужно внимание:</strong><ul><?php foreach ($state['warnings'] as $warning): ?><li><?= e($warning) ?></li><?php endforeach; ?></ul></div>
    <?php else: ?>
<div class="flash ok" role="status">Признаков проблем в доступных показаниях не найдено.</div>
    <?php endif; ?>
<div class="card">
  <div class="row"><div><h2>Компоненты и версии</h2><p class="muted">Установленные версии и стабильные релизы из официальных источников.</p></div><span class="grow"></span><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="server_refresh"><button class="btn">Проверить обновления</button></form></div>
    <?php if ($updates): ?><div class="flash err" role="status"><strong>Доступны обновления: <?= count($updates) ?>.</strong> Перед установкой сделайте резервную копию и проверьте совместимость.</div><?php else: ?><div class="flash ok" role="status"><strong>Новых версий не обнаружено.</strong> Если источник недоступен, статус компонента будет «Нет данных о релизах».</div><?php endif; ?>
    <?php if ($componentData['errors']): ?><p class="muted">Некоторые источники недоступны. Текущая установка продолжает работать; повторите проверку позже.</p><?php endif; ?>
  <div class="component-grid">
    <?php foreach ($components as $key => $component): ?>
    <article class="component-card is-<?= e($component['status']['state']) ?>">
      <div class="row"><h3><?= e($component['name']) ?></h3><span class="grow"></span><span class="component-status is-<?= e($component['status']['state']) ?>"><?= e($component['status']['label']) ?></span></div>
      <dl class="component-versions"><div><dt>Установлено</dt><dd><?= e($component['current'] ?? 'Не установлено') ?></dd></div><div><dt>Актуальный стабильный релиз</dt><dd><?= e($component['latest'] ?? 'Нет данных') ?></dd></div></dl>
      <p><?= e($component['note']) ?></p>
      <?php if (!empty($componentData['errors'][$key])): ?><p class="muted"><small><?= e($componentData['errors'][$key]) ?></small></p><?php endif; ?>
      <?php if ($component['source'] !== ''): ?><a href="<?= e($component['source']) ?>" target="_blank" rel="noopener noreferrer">Официальный источник ↗</a><?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <p class="muted"><small>Проверка не чаще одного раза в 6 часов; при ошибке источника — через 15 минут. Последняя проверка: <?= $componentData['checked_at'] ? e(date('d.m.Y H:i:s', $componentData['checked_at'])) : 'ещё не выполнялась' ?>. Новый релиз сам по себе не означает автоматическую уязвимость текущего.</small></p>
</div>
<div class="card">
  <h2>Нагрузка и ресурсы</h2>
  <table class="tbl"><tbody>
    <tr><td>Загрузка CPU (1 / 5 / 15 минут)</td><td><?= $state['load'] ? e(implode(' / ', array_map(static fn($v) => number_format((float)$v, 2, '.', ''), $state['load']))) : 'не сообщается' ?></td></tr>
    <tr><td>Доступные ядра CPU</td><td><?= $state['cores'] > 0 ? (int)$state['cores'] : 'не сообщается' ?></td></tr>
    <tr><td>Память сервера</td><td><?php if (isset($state['memory']['MemTotal'])): ?>доступно <?= e(server_format_bytes($state['memory']['MemAvailable'] ?? -1)) ?> из <?= e(server_format_bytes($state['memory']['MemTotal'])) ?><?php else: ?>не сообщается shared hosting<?php endif; ?></td></tr>
    <tr><td>Память текущего PHP-запроса</td><td><?= e(server_format_bytes(memory_get_usage(true))) ?> · пик <?= e(server_format_bytes(memory_get_peak_usage(true))) ?> · лимит <?= e((string)ini_get('memory_limit')) ?></td></tr>
    <tr><td>Свободно на диске</td><td><?= e(server_format_bytes($state['disk_free'])) ?><?= $state['disk_total'] ? ' из ' . e(server_format_bytes($state['disk_total'])) : '' ?></td></tr>
    <tr><td>Uptime</td><td><?= $uptime > 0 ? e(floor($uptime / 86400) . ' дн. ' . gmdate('H:i', $uptime % 86400)) : 'не сообщается' ?></td></tr>
    <tr><td>Перезагрузка</td><td><?= $state['reboot'] ? 'требуется по данным ОС' : (file_exists('/run/systemd/system') ? 'не требуется' : 'не определяется на этом хостинге') ?></td></tr>
    <tr><td>Время сервера</td><td><?= e(date('Y-m-d H:i:s')) ?> · <?= e(date_default_timezone_get()) ?></td></tr>
  </tbody></table>
</div>
<div class="card">
  <h2>Компоненты</h2>
  <table class="tbl"><tbody>
    <tr><td>PHP</td><td><?= e(PHP_VERSION) ?> · SAPI <?= e(PHP_SAPI) ?></td></tr>
    <tr><td>SQLite</td><td><?= e((string)db_val('SELECT sqlite_version()')) ?></td></tr>
    <tr><td>GD</td><td><?= $gd ? e((string)($gd['GD Version'] ?? 'установлена')) : 'не установлена' ?></td></tr>
    <tr><td>ImageMagick</td><td><?= e($imagick) ?></td></tr>
    <tr><td>Веб-сервер</td><td><?= e($web) ?></td></tr>
    <tr><td>ОС</td><td><?= e(PHP_OS_FAMILY) ?> · <?= e(php_uname('s') . ' ' . php_uname('r')) ?></td></tr>
    <tr><td>OPcache</td><td><?= $opcache ? 'включён · использовано ' . e(server_format_bytes((float)($opcache['memory_usage']['used_memory'] ?? 0))) : 'не сообщается или выключен' ?></td></tr>
    <tr><td>cURL / OpenSSL</td><td><?= function_exists('curl_init') ? 'cURL да' : 'cURL нет' ?> · <?= extension_loaded('openssl') ? 'OpenSSL да' : 'OpenSSL нет' ?></td></tr>
    <tr><td>mbstring / DOM / EXIF / fileinfo / ZIP</td><td><?= e(implode(' / ', array_map(static fn($e) => $e . ': ' . (extension_loaded($e) ? 'да' : 'нет'), ['mbstring', 'dom', 'exif', 'fileinfo', 'zip']))) ?></td></tr>
    <tr><td>WebP / AVIF / HEIC</td><td><?php if (extension_loaded('imagick')): ?><?= e(implode(' / ', array_map(static fn($f) => $f . ': ' . (Imagick::queryFormats($f) ? 'да' : 'нет'), ['WEBP', 'AVIF', 'HEIC']))) ?><?php else: ?>через ImageMagick не проверяются<?php endif; ?></td></tr>
    <tr><td>Лимиты загрузки</td><td>upload_max_filesize <?= e((string)ini_get('upload_max_filesize')) ?> · post_max_size <?= e((string)ini_get('post_max_size')) ?> · max_file_uploads <?= e((string)ini_get('max_file_uploads')) ?></td></tr>
    <tr><td>Лимиты выполнения</td><td>max_execution_time <?= e((string)ini_get('max_execution_time')) ?> · max_input_time <?= e((string)ini_get('max_input_time')) ?> · max_input_vars <?= e((string)ini_get('max_input_vars')) ?></td></tr>
    <tr><td>Временный каталог загрузок</td><td><?= e((string)(ini_get('upload_tmp_dir') ?: sys_get_temp_dir())) ?></td></tr>
    <tr><td>Сессии</td><td><?= e(session_save_path() ?: 'системный каталог') ?></td></tr>
    <tr><td>База данных</td><td><?= e(server_format_bytes($dbSize)) ?> · journal <?= e((string)db_val('PRAGMA journal_mode')) ?> · busy_timeout <?= e((string)db_val('PRAGMA busy_timeout')) ?></td></tr>
    <tr><td>open_basedir</td><td><?= e((string)(ini_get('open_basedir') ?: 'не задано')) ?></td></tr>
    <tr><td>Отключённые функции</td><td><?= e((string)(ini_get('disable_functions') ?: 'нет')) ?></td></tr>
  </tbody></table>
</div>
    <?php
    admin_footer();
}
