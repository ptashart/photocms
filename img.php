<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
/**
 * Создаёт вариант изображения при первом обращении.
 * Вызывается через .htaccess только если файла /media/{id}/{w}/{slug}.{ext} ещё нет.
 */
require __DIR__ . '/app/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$w = (int)($_GET['w'] ?? 0);
$slug = (string)($_GET['slug'] ?? '');
$fmt = (string)($_GET['fmt'] ?? 'jpg');
$types = ['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif', 'png' => 'image/png'];

/**
 * Отдаёт готовый файл варианта и завершает запрос.
 * Content-Length ставим, только если размер удалось прочитать: между is_file() и отдачей
 * файл теоретически может удалить regen.php — filesize() тогда вернёт false и warning.
 */
function serve_variant(string $path, string $mime, int $maxAge = 31536000): void
{
    /* между проверкой is_file() у вызывающего и отдачей файл теоретически может удалить
       regen.php или удаление фото — тогда честный 404 вместо warning'а и битого ответа */
    $size = @filesize($path);
    if ($size === false || !is_readable($path)) {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit('Not found');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=' . $maxAge . ($maxAge >= 86400 ? ', immutable' : ''));
    if (@readfile($path) === false) {
        app_log_error('serve_variant: readfile не смог отдать ' . $path);
        if (!headers_sent()) {
            http_response_code(404);
        }
    }
    exit;
}

/* Троттлинг генераций по IP (img_throttle_allow/hit/bump/count) с 1.9.13 живёт в
   app/images.php — теми же функциями пользуется favicon-эндпоинт в index.php. */

try {
    $img = $id > 0 ? image_get($id) : null;
} catch (Throwable $e) {
    $img = null; // база недоступна — честный 404 с no-store, а не пустой 500
}
/* Ширина проверяется по оригиналу: без этого каждая из 9 ширин × 4 форматов создавала бы копию
   фото под отдельным публичным URL — лёгкий способ забить диск и процессор. */
if (!$img || $img['slug'] !== $slug || !isset($types[$fmt]) || !image_width_allowed($img, $w)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}
/* PNG-варианты — только там, где они реально нужны (аудит 1.9.40, п.5): у PNG-оригиналов
   (прозрачность), у логотипа и favicon (layout.php строит им png-адреса). Для обычной
   фотографии /media/id/2520/slug.png — это ~10 МБ без потерь на КАЖДЫЙ размер, а slug
   виден в HTML опубликованных страниц: так можно было забить диск, несмотря на троттлинг. */
if ($fmt === 'png' && $img['ext'] !== 'png'
    && $id !== (int)setting('favicon_image_id') && $id !== (int)setting('logo_image_id')) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}
/* AVIF — только когда включён настройкой и поддержан движком (1.9.48): при выключенном avif
   разметка (img_html) avif-source не выводит, поэтому сюда могут прийти лишь ручные запросы —
   им честный 404 без генерации: кодирование AVIF — самое тяжёлое из форматов, и плодить файлы
   (и fail-маркеры) по ручному перебору не стоит. Проверка до троттлинга и .fail-логики. */
if ($fmt === 'avif' && !avif_enabled()) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}

$path = image_variant_path($img, $w, $fmt);
$failMark = $path . '.fail';
if (!is_file($path)) {
    /* Негативный кэш: вариант недавно не получился (битый оригинал, нехватка памяти) —
       не декодируем оригинал заново на каждый запрос. Маркер живёт сутки (раньше — час:
       перебор несуществующих комбинаций успевал снова нагружать CPU) и снимается при
       успехе/регенерации. */
    if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit('Not found');
    }
    /* Лимит УСПЕШНЫХ генераций на IP: перебор «существующее фото × допустимые ширины × форматы»
       гоняет тяжёлый GD, а fail-троттлинг его не видит (генерации-то успешные). Потолок с запасом
       выше честного сценария (пара больших альбомов с телефона); сверх — ближайший готовый вариант.
       Холодный старт (после «Сбросить миниатюры»): у фото нет НИ ОДНОГО варианта (каталог media/{id}/
       создаётся только вместе с первым файлом), fallback отдать нечего, а общий лимит превращал
       первый просмотр большого альбома в 503, которые браузер не перезапрашивает (аудит 1.9.40, п.20).
       Но и совсем без потолка холодный путь был неограниченным CPU-флудом (аудит 1.9.48, п.2) —
       поэтому отдельный счётчик с более мягким потолком: «холодной» бывает лишь ПЕРВАЯ генерация
       фото, и честному первому обходу альбома (по одной на фото) его хватает с большим запасом. */
    $cold = !is_dir(media_dir() . '/' . $id);
    $thFile = $cold ? ROOT . '/storage/locks/img-gen-cold.json' : ROOT . '/storage/locks/img-gen.json';
    $genCount = img_throttle_count($thFile, client_ip());
    if ($genCount === null || $genCount >= ($cold ? IMG_GEN_COLD_MAX_PER_IP : IMG_GEN_MAX_PER_IP)) {
        $fallback = $cold ? null : image_existing_variant($img, $fmt, $w);
        if ($fallback !== null) {
            $fm = $types[strtolower(pathinfo($fallback, PATHINFO_EXTENSION))] ?? 'image/jpeg';
            serve_variant($fallback, $fm, 300);
        }
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        exit('Busy');
    }
    /* Декодирование большого оригинала тяжёлое: одновременно допускаем лишь несколько. Слот занят — ждём
       короткими попытками до IMG_LOCK_WAIT секунд (не вечным flock: висящие запросы выкашивают пул PHP-воркеров).
       Браузеры НЕ перезапрашивают картинку после 503, поэтому если слот так и не освободился, отдаём ближайший
       готовый вариант этого же фото: чуть не тот размер лучше, чем битое фото на первом визите. */
    $lock = image_lock_wait($id, IMG_LOCK_WAIT);
    if (is_file($path)) { // пока ждали слот, вариант создал соседний запрос — генерировать не нужно
        image_unlock($lock);
        serve_variant($path, $types[$fmt]);
    }
    if ($lock === false || $lock === null) {
        $fallback = image_existing_variant($img, $fmt, $w);
        if ($fallback !== null) {
            $fm = $types[strtolower(pathinfo($fallback, PATHINFO_EXTENSION))] ?? 'image/jpeg';
            serve_variant($fallback, $fm, 60); // короткий кэш: точный вариант скоро появится
        }
        http_response_code(503);
        header('Retry-After: 2');
        header('Cache-Control: no-store');
        exit('Busy');
    }
    if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
        image_unlock($lock);
        http_response_code(404);
        header('Cache-Control: no-store');
        exit('Not found');
    }
    $genCount = img_throttle_count($thFile, client_ip());
    if ($genCount === null || $genCount >= ($cold ? IMG_GEN_COLD_MAX_PER_IP : IMG_GEN_MAX_PER_IP)) {
        image_unlock($lock);
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        exit('Busy');
    }
    if (!img_throttle_reserve($thFile, client_ip(), $cold ? IMG_GEN_COLD_MAX_PER_IP : IMG_GEN_MAX_PER_IP)) {
        image_unlock($lock);
        http_response_code(503);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
        exit('Busy');
    }
    try {
        $ok = image_make_variant($img, $w, $fmt);
    } finally {
        image_unlock($lock);
    }
    if (!$ok) {
        /* папка media/{id}/{w}/ появляется только после успешного декода в gd_save_variant() —
           при битом оригинале её нет, и touch молча не срабатывал: негативный кэш для этого
           класса ошибок не работал вовсе (аудит 1.9.12, находка 1) */
        $failDir = dirname($failMark);
        if (!is_dir($failDir)) {
            @mkdir($failDir, 0755, true);
        }
        @touch($failMark);
        if (!img_throttle_hit(client_ip())) {
            http_response_code(503);
            header('Retry-After: 60');
            exit('Throttle storage unavailable');
        }
        http_response_code(404);
        header('Cache-Control: no-store');
        exit('Not found');
    }
    @unlink($failMark);
}

serve_variant($path, $types[$fmt]);
