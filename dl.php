<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
/**
 * Скачивание оригинала фото (1.9.25, идея из dl.php Koken CMS).
 * Оригиналы лежат в storage/originals и закрыты от веба корневым .htaccess — этот файл
 * единственная дверь к ним. Доступ только у вошедшего в админку (сессия проверяется тем же
 * admin_session_ok(), что и вся админка): публичной ссылки на оригинал не существует.
 */
require __DIR__ . '/app/bootstrap.php';

/* Сессию bootstrap сам не стартует (публичным страницам она не нужна) — без session_boot()
   $_SESSION пуст, и admin_session_ok() ниже всегда отвечал 403 даже вошедшему (тест 1.9.26). */
session_boot();

if (!admin_session_ok()) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('Скачивание оригиналов доступно после входа в админку.');
}

/* readfile ниже на большом файле идёт минутами — и всё это время открытая сессия держала бы
   эксклюзивную блокировку своего файла: параллельные запросы админки (включая ping) вставали
   в очередь. Авторизация уже проверена — закрываем сессию до отдачи (ревизия 1.9.34, п.10). */
session_write_close();

$id = (int)($_GET['id'] ?? 0);
$img = $id > 0 ? image_get($id) : null;
if (!$img) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}

/* realpath-проверка: отдаём только файл внутри storage/originals, никаких обходов каталога.
   Путь строится из целого id и расширения из базы, поэтому traversal тут неоткуда взяться,
   но проверка дешёвая — оставляем вторым рубежом (как в Koken). */
$base = originals_dir();
$realBase = realpath($base);
$realFile = realpath(image_original_path($img));
if (!$realBase || !$realFile || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}

$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$mime = $mimes[strtolower((string)$img['ext'])] ?? 'application/octet-stream';

/* Имя скачиваемого файла: оригинальное, но очищенное — в заголовке Content-Disposition
   недопустимы кавычки, слэши и переводы строк; не-ASCII отдаём через filename* (RFC 5987) */
$name = trim((string)$img['orig_name']);
if ($name === '') {
    $name = 'photo-' . (int)$img['id'] . '.' . $img['ext'];
}
$name = str_replace(["\r", "\n", '"', '\\', '/'], '', $name);
$ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: ('photo-' . (int)$img['id']);

$size = filesize($realFile);
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
if ($size !== false) {
    header('Content-Length: ' . $size);
}
header('Cache-Control: private, no-store'); // оригиналы не для публичных кэшей (прокси, CDN)
header('X-Content-Type-Options: nosniff');

if (function_exists('set_time_limit')) {
    set_time_limit(0); // большой RAW-экспорт в JPEG на медленном диске не должен умереть на 30-й секунде
}
if (@readfile($realFile) === false) {
    app_log_error('dl.php: readfile не смог отдать ' . $realFile);
}
exit;
