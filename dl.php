<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);






require __DIR__ . '/app/bootstrap.php';



session_boot();

if (!admin_session_ok()) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('Скачивание оригиналов доступно после входа в админку.');
}




session_write_close();

$id = (int)($_GET['id'] ?? 0);
$img = $id > 0 ? image_get($id) : null;
if (!$img) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}




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
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (function_exists('set_time_limit')) {
    set_time_limit(0);
}
if (@readfile($realFile) === false) {
    app_log_error('dl.php: readfile не смог отдать ' . $realFile);
}
exit;
