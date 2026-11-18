<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);




require __DIR__ . '/app/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$w = (int)($_GET['w'] ?? 0);
$slug = (string)($_GET['slug'] ?? '');
$fmt = (string)($_GET['fmt'] ?? 'jpg');
$types = ['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif', 'png' => 'image/png'];






function serve_variant(string $path, string $mime, int $maxAge = 31536000): void
{


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




try {
    $img = $id > 0 ? image_get($id) : null;
} catch (Throwable $e) {
    $img = null;
}


if (!$img || $img['slug'] !== $slug || !isset($types[$fmt]) || !image_width_allowed($img, $w)) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}




if ($fmt === 'png' && $img['ext'] !== 'png'
    && $id !== (int)setting('favicon_image_id') && $id !== (int)setting('logo_image_id')) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}




if ($fmt === 'avif' && !avif_enabled()) {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit('Not found');
}

$path = image_variant_path($img, $w, $fmt);
$failMark = $path . '.fail';
if (!is_file($path)) {




    if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit('Not found');
    }









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




    $lock = image_lock_wait($id, IMG_LOCK_WAIT);
    if (is_file($path)) {
        image_unlock($lock);
        serve_variant($path, $types[$fmt]);
    }
    if ($lock === false || $lock === null) {
        $fallback = image_existing_variant($img, $fmt, $w);
        if ($fallback !== null) {
            $fm = $types[strtolower(pathinfo($fallback, PATHINFO_EXTENSION))] ?? 'image/jpeg';
            serve_variant($fallback, $fm, 60);
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
