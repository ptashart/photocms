<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);


const IMG_WIDTHS = [48, 180, 280, 440, 600, 900, 1240, 1880, 2520];





function img_engine(): string
{
    static $e = null;
    if ($e === null) {
        $forced = strtolower(trim((string)cfg('img_engine', '')));
        if (in_array($forced, ['imagick', 'gd'], true)) {
            return $e = $forced;
        }
        $e = (extension_loaded('imagick') && class_exists('Imagick')) ? 'imagick' : 'gd';
    }
    return $e;
}


function image_parse_size(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '-1') {
        return 0;
    }
    $n = (int)$raw;
    switch (strtolower(substr($raw, -1))) {
        case 'g':
            $n *= 1073741824;
            break;
        case 'm':
            $n *= 1048576;
            break;
        case 'k':
            $n *= 1024;
            break;
    }



    if ($n >= PHP_INT_MAX) {
        return PHP_INT_MAX;
    }
    return $n > 0 ? (int)$n : 0;
}


function image_memory_limit(): int
{
    return image_parse_size((string)ini_get('memory_limit'));
}









function image_memory_limit_work(): int
{
    $cur = image_memory_limit();
    $want = image_parse_size((string)cfg('img_memory_limit', ''));
    return ($want > 0 && ($cur === 0 || $want > $cur)) ? $want : $cur;
}


function image_raise_memory(): void
{
    $eff = image_memory_limit_work();
    $cur = image_memory_limit();
    if ($eff > 0 && $cur > 0 && $cur < $eff) {
        @ini_set('memory_limit', (string)$eff);
    }
}






function image_max_pixels(): int
{


    if (img_engine() === 'imagick') {
        return IMG_MAX_PIXELS_CAP;
    }
    $lim = image_memory_limit_work();
    if ($lim <= 0) {
        return IMG_MAX_PIXELS_CAP;
    }
    $px = (int)floor(max(0, $lim - 48 * 1048576) / 8);
    return max(4000000, min(IMG_MAX_PIXELS_CAP, $px));
}


function image_width_allowed(array $img, int $w): bool
{
    $ow = (int)$img['w'];
    if ($ow > 0 && $ow < 280 && $w === $ow) {
        return true;
    }
    if (!in_array($w, IMG_WIDTHS, true)) {
        return false;
    }
    if ($w <= $ow || in_array($w, [48, 180, 440], true)) {
        return true;
    }
    foreach (IMG_WIDTHS as $step) {
        if ($step >= $ow) {
            return $w === $step;
        }
    }
    return false;
}




function image_lock(int $id, bool $wait = true)
{
    $dir = ROOT . '/storage/locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $h = @fopen($dir . '/img-' . ($id % IMG_LOCK_SLOTS) . '.lock', 'c');
    if (!$h) {
        return null;
    }
    if (!flock($h, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
        fclose($h);
        return $wait ? null : false;
    }
    return $h;
}

function image_unlock($h): void
{
    if ($h) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}





function image_lock_wait(int $id, float $seconds)
{
    $deadline = microtime(true) + $seconds;
    do {
        $h = image_lock($id, false);
        if ($h !== false && $h !== null) {
            return $h;
        }
        if ($h === null) {
            return null;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    return false;
}






function image_existing_variant(array $img, string $fmt, int $wantW): ?string
{
    $widths = array_values(array_filter(IMG_WIDTHS, static fn($w) => $w >= 280));
    $ow = (int)$img['w'];
    if ($ow > 0 && $ow < 280) {
        $widths[] = $ow;
    }
    usort($widths, static fn($a, $b) => abs($a - $wantW) <=> abs($b - $wantW));
    foreach (array_unique([$fmt, 'jpg']) as $f) {
        foreach ($widths as $w) {
            $p = image_variant_path($img, $w, $f);
            if (is_file($p)) {
                return $p;
            }
        }
    }
    return null;
}








function img_throttle_allow(string $ip): bool
{
    $count = img_throttle_count(ROOT . '/storage/locks/img-fails.json', $ip);
    return $count !== null && $count < IMG_FAIL_MAX_PER_IP;
}

function img_throttle_hit(string $ip): bool
{
    return img_throttle_bump(ROOT . '/storage/locks/img-fails.json', $ip) > 0;
}








function img_throttle_key(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);


        return $bin === false ? $ip : 'v6:' . bin2hex(substr($bin, 0, 6));
    }
    $p = explode('.', $ip);
    return count($p) === 4 ? $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24' : $ip;
}





function img_throttle_bump(string $f, string $ip): int
{
    if (!img_throttle_reserve($f, $ip, PHP_INT_MAX)) {
        return 0;
    }
    return (int)img_throttle_count($f, $ip);
}


function img_throttle_reserve(string $f, string $ip, int $max, bool $exactIp = false): bool
{
    $ip = $exactIp ? $ip : img_throttle_key($ip);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $fh = @fopen($f, 'c+');
    if (!$fh) {
        return false;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return false;
        }
        $raw = stream_get_contents($fh);
        if ($raw === false) {
            return false;
        }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        $now = time();
        foreach ($data as $k => $list) {
            $data[$k] = array_values(array_filter((array)$list, static fn($t) => $now - (int)$t < 3600));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $n = count($data[$ip] ?? []);
        if ($n >= $max) {
            return false;
        }
        $data[$ip][] = $now;
        $json = json_encode($data);
        if ($json === false || !ftruncate($fh, 0) || !rewind($fh)) {
            return false;
        }
        return fwrite($fh, $json) === strlen($json);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}


function img_throttle_count(string $f, string $ip): ?int
{
    $ip = img_throttle_key($ip);
    if (!is_file($f)) {
        return 0;
    }
    $fh = @fopen($f, 'r');
    if (!$fh) {
        return null;
    }
    if (!flock($fh, LOCK_SH)) {
        fclose($fh);
        return null;
    }
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($raw === false) {
        return null;
    }
    $data = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $now = time();
    $n = 0;
    foreach ((array)($data[$ip] ?? []) as $t) {
        if ($now - (int)$t < 3600) {
            $n++;
        }
    }
    return $n;
}

function media_dir(): string
{
    return ROOT . '/media';
}

function originals_dir(): string
{
    return ROOT . '/storage/originals';
}

function webp_supported(): bool
{
    return function_exists('imagewebp') && function_exists('imagecreatefromwebp');
}





function avif_supported(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = img_engine() === 'imagick'
            ? (extension_loaded('imagick') && in_array('AVIF', Imagick::queryFormats('AVIF'), true))
            : function_exists('imageavif');
    }
    return $ok;
}



function avif_enabled(): bool
{
    return setting('avif') === '1' && avif_supported();
}



function avif_quality(): int
{
    $q = (int)setting('avif_quality');
    if ($q <= 0) {
        $q = 50;
    }
    return max(30, min(80, $q));
}







function picture_sources_html(array $img, string $sizes, array $widths): string
{
    $html = '';
    if (avif_enabled()) {
        $html .= '<source type="image/avif" srcset="' . e(image_srcset($img, 'avif', $widths)) . '" sizes="' . e($sizes) . '">';
    }

    if (img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported()) {
        $html .= '<source type="image/webp" srcset="' . e(image_srcset($img, 'webp', $widths)) . '" sizes="' . e($sizes) . '">';
    }
    return $html;
}


function &image_cache(): array
{
    static $cache = [];
    return $cache;
}

function image_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $cache = &image_cache();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = db_one('SELECT * FROM images WHERE id = ?', [$id]);
    }
    return $cache[$id];
}





function images_prime(array $ids): void
{
    $cache = &image_cache();
    $need = [];
    foreach (array_map('intval', $ids) as $id) {
        if ($id > 0 && !array_key_exists($id, $cache)) {
            $need[$id] = true;
        }
    }
    if (!$need) {
        return;
    }
    foreach (db_all_in('SELECT * FROM images WHERE id IN ({IN})', array_keys($need)) as $r) {
        $cache[(int)$r['id']] = $r;
    }
}


function image_cache_flush(): void
{
    $cache = &image_cache();
    $cache = [];
}

function image_url(array $img, int $w, string $ext = 'jpg'): string
{
    static $versions = [];
    $id = (int)$img['id'];
    if (!array_key_exists($id, $versions)) {
        $versions[$id] = [];
        foreach (glob(media_dir() . '/' . $id . '/*/*', GLOB_NOSORT) ?: [] as $file) {
            if (is_file($file)) {
                $versions[$id][basename(dirname($file)) . '/' . basename($file)] = (int)@filemtime($file);
            }
        }
    }
    $url = '/media/' . $id . '/' . $w . '/' . $img['slug'] . '.' . $ext;
    $key = $w . '/' . $img['slug'] . '.' . $ext;
    if (isset($versions[$id][$key])) {
        $url .= '?v=' . $versions[$id][$key];
    }
    return $url;
}

function image_original_path(array $img): string
{
    return originals_dir() . '/' . (int)$img['id'] . '.' . $img['ext'];
}




function image_shot_at(array $img): int
{
    static $cache = [];
    $id = (int)($img['id'] ?? 0);
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $ts = 0;
    $path = image_original_path($img);
    $ext = strtolower((string)($img['ext'] ?? ''));
    if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff'], true) && is_file($path)) {
        $ex = @exif_read_data($path, 'EXIF', true);
        $raw = $ex['EXIF']['DateTimeOriginal'] ?? $ex['EXIF']['DateTimeDigitized'] ?? $ex['IFD0']['DateTime'] ?? '';
        if (is_string($raw) && preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            $candidate = strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]");

            if ($candidate !== false && $candidate > 946684800 && $candidate <= time() + 86400) {
                $ts = $candidate;
            }
        }
    }
    return $cache[$id] = $ts;
}

function image_variant_path(array $img, int $w, string $ext): string
{
    return media_dir() . '/' . (int)$img['id'] . '/' . $w . '/' . $img['slug'] . '.' . $ext;
}


function image_widths(array $img, int $max = 2520): array
{
    $ow = (int)$img['w'];
    if ($ow > 0 && $ow < 280) {
        return [$ow];
    }
    $list = array_values(array_filter(IMG_WIDTHS, static fn($w) => $w >= 280 && $w <= $max && $w <= $ow));
    if ($list) {
        return $list;
    }


    foreach (IMG_WIDTHS as $step) {
        if ($step >= $ow) {
            return [$step];
        }
    }
    return [280];
}

function image_prewarm_widths(array $img): array
{
    $widths = [1240, 440];
    $original = (int)($img['w'] ?? 0);
    if ($original > 0 && $original < 280) {
        $widths[] = $original;
    }
    return $widths;
}

function image_pick_width(array $widths, int $target): int
{
    foreach ($widths as $w) {
        if ($w >= $target) {
            return $w;
        }
    }
    return (int)end($widths);
}

function image_srcset(array $img, string $ext, array $widths): string
{
    $parts = [];
    foreach ($widths as $w) {
        $parts[] = image_url($img, $w, $ext) . ' ' . $w . 'w';
    }
    return implode(', ', $parts);
}






function image_focus_style(array $img): string
{
    $fx = (float)($img['focus_x'] ?? 0.5);
    $fy = (float)($img['focus_y'] ?? 0.5);
    if (abs($fx - 0.5) < 0.004 && abs($fy - 0.5) < 0.004) {
        return '';
    }

    $fx = max(0.0, min(1.0, $fx));
    $fy = max(0.0, min(1.0, $fy));
    return 'object-position:' . round($fx * 100, 1) . '% ' . round($fy * 100, 1) . '%';
}


function image_large_url(array $img, int $max = 2520, string $ext = 'jpg'): string
{
    $w = (int)max(image_widths($img, $max));
    return image_url($img, $w, $ext);
}





function gd_free($im): void
{
    if (PHP_VERSION_ID < 80500 && $im instanceof \GdImage) {
        imagedestroy($im);
    }
}

function gd_load(string $path)
{
    $info = @getimagesize($path);
    if (!$info) {
        app_log_error('gd_load: не удалось прочитать заголовок изображения ' . $path);
        return false;
    }
    $type = $info[2];
    $im = false;
    if ($type === IMAGETYPE_JPEG) {
        $im = @imagecreatefromjpeg($path);
    } elseif ($type === IMAGETYPE_PNG) {
        $im = @imagecreatefrompng($path);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($path);
    } elseif ($type === IMAGETYPE_GIF) {
        $im = @imagecreatefromgif($path);
    }
    if (!$im) {
        $e = error_get_last();
        app_log_error('gd_load: не удалось декодировать ' . $path . ($e ? ' — ' . $e['message'] : ''));
        return false;
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($path);
        $o = (int)($ex['Orientation'] ?? 1);





        $flips = [2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_VERTICAL, 5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_HORIZONTAL];
        if (isset($flips[$o]) && function_exists('imageflip')) {
            @imageflip($im, $flips[$o]);
        }
        $angles = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90];
        if (isset($angles[$o])) {
            $rot = imagerotate($im, $angles[$o], 0);
            if ($rot) {
                gd_free($im);
                $im = $rot;
            }
        }
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    return $im;
}






function gd_sharpen_amount(): float
{
    $pct = max(0, min(100, (int)setting('sharpen')));
    return $pct > 0 ? 0.005 * $pct : 0.0;
}


function gd_sharpen($out, float $k): void
{
    if ($k > 0 && function_exists('imageconvolution')) {
        @imageconvolution($out, [[0.0, -$k, 0.0], [-$k, 1.0 + 4 * $k, -$k], [0.0, -$k, 0.0]], 1.0, 0.0);
    }
}




function move_file_inplace(string $tmp, string $dest): bool
{
    if (@rename($tmp, $dest)) {
        return true;
    }
    $stage = $dest . '.tmp-' . getmypid();
    if (@copy($tmp, $stage)) {
        if (@rename($stage, $dest)) {
            @unlink($tmp);
            return true;
        }
    }
    @unlink($stage);
    return false;
}

function gd_save_variant($im, int $w, string $ext, string $dest, bool $allowSharpen = false): bool
{
    $sw = imagesx($im);
    $sh = imagesy($im);
    if ($sw < 1 || $sh < 1) {
        return false;
    }
    $tw = min($w, $sw);
    $th = max(1, (int)round($sh * $tw / $sw));

    $keepAlpha = ($ext === 'png' || $ext === 'webp' || $ext === 'avif');

    if ($keepAlpha && $tw === $sw) {
        $out = $im;
        imagealphablending($out, false);
        imagesavealpha($out, true);
    } else {



        $cur = $im;
        $cw = $sw;
        $ch = $sh;
        while ($cw > $tw * 2) {
            $nw = intdiv($cw, 2);
            $nh = max(1, (int)round($ch * $nw / $cw));
            $next = gd_resample($cur, $nw, $nh, $keepAlpha);
            if ($next === false) {



                if ($cur !== $im) {
                    gd_free($cur);
                }
                $cur = $im;
                break;
            }
            if ($cur !== $im) {
                gd_free($cur);
            }
            $cur = $next;
            $cw = $nw;
            $ch = $nh;
        }
        $out = gd_resample($cur, $tw, $th, $keepAlpha);
        if ($cur !== $im) {
            gd_free($cur);
        }
        if ($out === false) {
            return false;
        }



        if ($allowSharpen && $sw >= $tw * 1.5) {
            gd_sharpen($out, gd_sharpen_amount());
        }
    }

    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        app_log_error('gd_save_variant: не удалось создать папку ' . $dir . ' (проверьте права на media/)');
        return false;
    }


    $tmpDir = ROOT . '/storage/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0700, true);
    }
    $tmp = (is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : $dir) . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if ($ext === 'jpg') {
        imageinterlace($out, true);
        $ok = imagejpeg($out, $tmp, max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
    } elseif ($ext === 'webp') {
        $ok = function_exists('imagewebp') && imagewebp($out, $tmp, max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('webp_quality'))));
    } elseif ($ext === 'avif') {


        $ok = function_exists('imageavif') && imageavif($out, $tmp, avif_quality());
    } else {
        $ok = imagepng($out, $tmp, 6);
    }
    if ($ok) {
        $ok = move_file_inplace($tmp, $dest);
        if ($ok && !image_metadata_embed($dest, pathinfo($dest, PATHINFO_EXTENSION))) {
            app_log_error('metadata: не удалось записать права автора в ' . $dest);
        }
    }
    if (!$ok) {
        app_log_error('gd_save_variant: не удалось записать ' . $dest . ' (место на диске / права)');
        @unlink($tmp);
    }
    if ($out !== $im) {
        gd_free($out);
    }
    return (bool)$ok;
}




function gd_resample($src, int $tw, int $th, bool $keepAlpha)
{
    $out = imagecreatetruecolor($tw, $th);
    if (!$out) {
        return false;
    }
    if ($keepAlpha) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    }
    imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));
    return $out;
}


function image_source_for(array $img, int $w)
{
    return gd_load(image_original_path($img));
}





function imagick_auto_orient(Imagick $im): void
{
    $o = (int)$im->getImageOrientation();




    switch ($o) {
        case 2: @$im->flopImage(); break;
        case 3: @$im->rotateImage('#000000', 180); break;
        case 4: @$im->flipImage(); break;
        case 5: @$im->flopImage(); @$im->rotateImage('#000000', 270); break;
        case 6: @$im->rotateImage('#000000', 90);  break;
        case 7: @$im->flopImage(); @$im->rotateImage('#000000', 90);  break;
        case 8: @$im->rotateImage('#000000', 270); break;
    }
    if ($o > 1) {
        @$im->setImageOrientation(1);
    }
}


function imagick_webp_supported(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = img_engine() === 'imagick' && in_array('WEBP', Imagick::queryFormats('WEBP'), true);
    }
    return $ok;
}





function imagick_srgb_profile(): ?string
{
    static $p = null;
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $f = ROOT . '/app/icc/srgb.icc';
        $c = is_file($f) ? @file_get_contents($f) : false;
        $p = is_string($c) && $c !== '' ? $c : null;
    }
    return $p;
}






function image_icc_wide_gamut(string $path): bool
{
    $head = @file_get_contents($path, false, null, 0, 1048576);
    if (!is_string($head) || stripos($head, 'ICC_PROFILE') === false) {
        return false;
    }
    return stripos($head, 'sRGB') === false;
}





function imagick_make_variant(array $img, int $w, string $ext): bool
{
    $ow = (int)$img['w'];
    $validWidth = in_array($w, IMG_WIDTHS, true) || ($ow > 0 && $ow < 280 && $w === $ow);
    if (!$validWidth || !in_array($ext, ['jpg', 'webp', 'avif', 'png'], true)) {
        return false;
    }
    if ($ext === 'webp' && !imagick_webp_supported()) {
        return false;
    }


    if ($ext === 'avif' && !avif_enabled()) {
        return false;
    }
    $dest = image_variant_path($img, $w, $ext);
    if (is_file($dest)) {
        return true;
    }
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }
    try {
        $src = image_original_path($img);
        $im = new Imagick();


        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 60);




        if ($src === image_original_path($img) && $w * 2 < max(1, (int)($img['w'] ?? 0))) {
            $im->setOption('jpeg:size', ($w * 2) . 'x' . ($w * 2));
        }




        $decoder = $src === image_original_path($img)
            ? (['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'][(string)$img['ext']] ?? null)
            : 'jpeg';
        $im->readImage(($decoder !== null ? $decoder . ':' : '') . $src . '[0]');
        imagick_auto_orient($im);






        try {
            if (in_array('icc', $im->getImageProfiles('*', false), true)
                && stripos((string)$im->getImageProfile('icc'), 'sRGB') === false) {
                $srgb = imagick_srgb_profile();
                if ($srgb !== null) {
                    $im->profileImage('icc', $srgb);
                }
            }
        } catch (Throwable $ignore) {

        }
        $sw = $im->getImageWidth();
        $sh = $im->getImageHeight();
        if ($sw < 1 || $sh < 1) {
            $im->clear();
            return false;
        }
        $tw = min($w, $sw);
        $th = max(1, (int)round($sh * $tw / $sw));
        if ($tw !== $sw) {

            $im->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1.0);
        }


        $allowSharpen = $ext === 'jpg' || (($ext === 'webp' || $ext === 'avif') && $img['ext'] === 'jpg');
        $pct = max(0, min(100, (int)setting('sharpen')));
        if ($allowSharpen && $pct > 0 && $sw >= $tw * 1.5) {
            @$im->unsharpMaskImage(0, 0.8, 0.01 * $pct, 0.02);
        }
        if ($ext === 'jpg') {




            if ($im->getImageAlphaChannel()) {
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $flat = @$im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                if ($flat instanceof Imagick) {
                    $im->clear();
                    $im = $flat;
                }
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        } elseif ($ext === 'webp') {
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality(max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('webp_quality'))));
        } elseif ($ext === 'avif') {

            $im->setImageFormat('avif');
            $im->setImageCompressionQuality(avif_quality());
        } else {
            $im->setImageFormat('png');
        }
        $im->stripImage();
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            app_log_error('imagick_make_variant: не удалось создать папку ' . $dir);
            $im->clear();
            return false;
        }
        $tmpDir = ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }
        $tmp = (is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : $dir) . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $ok = @$im->writeImage($tmp);
        $im->clear();
        if ($ok) {
            $ok = move_file_inplace($tmp, $dest);
        if ($ok && !image_metadata_embed($dest, pathinfo($dest, PATHINFO_EXTENSION))) {
            app_log_error('metadata: не удалось записать права автора в ' . $dest);
        }
        }
        if (!$ok) {
            app_log_error('imagick_make_variant: не удалось записать ' . $dest);
            @unlink($tmp);
        }
        return (bool)$ok;
    } catch (Throwable $e) {
        app_log_error('imagick_make_variant: ' . $e->getMessage() . ' (фото #' . (int)$img['id'] . ')');
        return false;
    }
}

function image_make_variant(array $img, int $w, string $ext, $loaded = null): bool
{
    $ow = (int)$img['w'];
    $validWidth = in_array($w, IMG_WIDTHS, true) || ($ow > 0 && $ow < 280 && $w === $ow);
    if (!$validWidth || !in_array($ext, ['jpg', 'webp', 'avif', 'png'], true)) {
        return false;
    }


    if ($ext === 'avif' && !avif_enabled()) {
        return false;
    }
    if (img_engine() === 'imagick') {
        return imagick_make_variant($img, $w, $ext);
    }
    if ($ext === 'webp' && !webp_supported()) {
        return false;
    }
    $dest = image_variant_path($img, $w, $ext);
    if (is_file($dest)) {
        return true;
    }
    image_raise_memory();
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }
    $own = $loaded === null;
    $im = $loaded ?: image_source_for($img, $w);
    if (!$im) {
        return false;
    }


    $allowSharpen = $ext === 'jpg' || (($ext === 'webp' || $ext === 'avif') && $img['ext'] === 'jpg');
    $ok = gd_save_variant($im, $w, $ext, $dest, $allowSharpen);
    if ($own) {
        gd_free($im);
    }
    return $ok;
}








function og_image_dims(array $img): ?array
{
    $ow = (int)($img['w'] ?? 0);
    if ($ow < 600) {
        return null;
    }
    $w = min(1200, $ow);
    return [$w, (int)round($w * 630 / 1200)];
}


function og_variant_path(array $img, int $w, int $h): string
{
    return media_dir() . '/' . (int)$img['id'] . '/og-' . $w . 'x' . $h . '.jpg';
}






function og_image_variant(array $img): ?array
{
    $dims = og_image_dims($img);
    if ($dims === null) {
        return null;
    }
    [$w, $h] = $dims;
    $dest = og_variant_path($img, $w, $h);
    $src = image_original_path($img);

    if (!is_file($dest) || (is_file($src) && (int)@filemtime($src) > (int)@filemtime($dest))) {
        $failMark = $dest . '.fail';
        if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
            return null;
        }
        $lock = image_lock_wait((int)$img['id'], IMG_LOCK_WAIT);
        if ($lock === false || $lock === null) {
            return null;
        }
        try {
            $fresh = db_one('SELECT * FROM images WHERE id = ?', [(int)$img['id']]);
            if (!is_array($fresh)) {
                return null;
            }
            $img = $fresh;
            $dims = og_image_dims($img);
            if ($dims === null) {
                return null;
            }
            [$w, $h] = $dims;
            $dest = og_variant_path($img, $w, $h);
            $src = image_original_path($img);
            $failMark = $dest . '.fail';
            clearstatcache(true, $dest);
            if (is_file($dest) && (int)@filemtime($dest) >= (int)@filemtime($src)) {
                return ['url' => '/media/' . (int)$img['id'] . '/og-' . $w . 'x' . $h . '.jpg?v=' . (int)@filemtime($dest), 'w' => $w, 'h' => $h];
            }
            if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
                return null;
            }
            $cold = !is_dir(media_dir() . '/' . (int)$img['id']);
            $thFile = $cold ? ROOT . '/storage/locks/img-gen-cold.json' : ROOT . '/storage/locks/img-gen.json';
            if (!img_throttle_reserve($thFile, client_ip(), $cold ? IMG_GEN_COLD_MAX_PER_IP : IMG_GEN_MAX_PER_IP)) {
                return null;
            }
            if (!og_variant_make($img, $w, $h, $dest)) {
                @touch($failMark);
                if (!img_throttle_hit(client_ip())) {
                    return null;
                }
                return null;
            }
            @unlink($failMark);
        } finally {
            image_unlock($lock);
        }
    }
    $url = '/media/' . (int)$img['id'] . '/og-' . $w . 'x' . $h . '.jpg';
    clearstatcache(true, $dest);
    if (is_file($dest)) {
        $url .= '?v=' . (int)@filemtime($dest);
    }
    return ['url' => $url, 'w' => $w, 'h' => $h];
}


function og_variant_make(array $img, int $w, int $h, string $dest): bool
{
    $src = image_original_path($img);
    $fx = max(0.0, min(1.0, (float)($img['focus_x'] ?? 0.5)));
    $fy = max(0.0, min(1.0, (float)($img['focus_y'] ?? 0.5)));
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }

    if (img_engine() === 'imagick') {
        try {
            $im = new Imagick();

            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 60);
            if ($w * 2 < max(1, (int)($img['w'] ?? 0))) {
                $im->setOption('jpeg:size', ($w * 2) . 'x' . ($w * 2));
            }
            $decoder = ['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'][(string)$img['ext']] ?? null;
            if ($decoder === null) {
                return false;
            }
            $im->readImage($decoder . ':' . $src . '[0]');
            imagick_auto_orient($im);
            try {
                if (in_array('icc', $im->getImageProfiles('*', false), true)
                    && stripos((string)$im->getImageProfile('icc'), 'sRGB') === false) {
                    $srgb = imagick_srgb_profile();
                    if ($srgb !== null) {
                        $im->profileImage('icc', $srgb);
                    }
                }
            } catch (Throwable $ignore) {

            }
            $sw = $im->getImageWidth();
            $sh = $im->getImageHeight();
            if ($sw < 1 || $sh < 1) {
                $im->clear();
                return false;
            }
            $scale = max($w / $sw, $h / $sh);
            $rw = max($w, (int)ceil($sw * $scale));
            $rh = max($h, (int)ceil($sh * $scale));
            if ($rw !== $sw || $rh !== $sh) {
                $im->resizeImage($rw, $rh, Imagick::FILTER_LANCZOS, 1.0);
            }
            $x = max(0, min($rw - $w, (int)round($fx * $rw - $w / 2)));
            $y = max(0, min($rh - $h, (int)round($fy * $rh - $h / 2)));
            $im->cropImage($w, $h, $x, $y);
            $pct = max(0, min(100, (int)setting('sharpen')));
            if ($pct > 0 && $sw >= $w * 1.5 && !$im->getImageAlphaChannel()) {
                @$im->unsharpMaskImage(0, 0.8, 0.01 * $pct, 0.02);
            }
            if ($im->getImageAlphaChannel()) {

                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $flat = @$im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                if ($flat instanceof Imagick) {
                    $im->clear();
                    $im = $flat;
                }
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $im->stripImage();
            $ok = og_variant_write($im->getImagesBlob(), $dest);
            $im->clear();
            return $ok;
        } catch (Throwable $e) {
            app_log_error('og_variant_make: ' . $e->getMessage() . ' (фото #' . (int)$img['id'] . ')');
            return false;
        }
    }



    image_raise_memory();
    $im = gd_load($src);
    if (!$im) {
        return false;
    }
    $sw = imagesx($im);
    $sh = imagesy($im);
    if ($sw < 1 || $sh < 1) {
        gd_free($im);
        return false;
    }
    $scale = max($w / $sw, $h / $sh);
    $cw = min($sw, $w / $scale);
    $ch = min($sh, $h / $scale);
    $cx = max(0, min($sw - $cw, (int)round($fx * $sw - $cw / 2)));
    $cy = max(0, min($sh - $ch, (int)round($fy * $sh - $ch / 2)));
    $out = imagecreatetruecolor($w, $h);
    if ($out === false) {
        gd_free($im);
        return false;
    }
    $white = imagecolorallocate($out, 255, 255, 255);
    if ($white !== false) {
        imagefill($out, 0, 0, $white);
    }
    if (!imagecopyresampled($out, $im, 0, 0, (int)$cx, (int)$cy, $w, $h, (int)$cw, (int)$ch)) {
        gd_free($im);
        gd_free($out);
        return false;
    }
    gd_free($im);
    $pct = max(0, min(100, (int)setting('sharpen')));
    if ($pct > 0 && $sw >= $w * 1.5) {
        gd_sharpen($out, gd_sharpen_amount());
    }
    ob_start();
    imageinterlace($out, true);
    $ok = imagejpeg($out, null, max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
    $blob = (string)ob_get_clean();
    gd_free($out);
    return $ok && og_variant_write($blob, $dest);
}


function og_variant_write(string $blob, string $dest): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        app_log_error('og_variant_write: не удалось создать папку ' . $dir);
        return false;
    }
    $tmpDir = ROOT . '/storage/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0700, true);
    }
    $tmp = (is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : $dir) . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $blob) === false) {
        app_log_error('og_variant_write: не удалось записать ' . $dest);
        @unlink($tmp);
        return false;
    }
    $ok = move_file_inplace($tmp, $dest);
        if ($ok && !image_metadata_embed($dest, pathinfo($dest, PATHINFO_EXTENSION))) {
            app_log_error('metadata: не удалось записать права автора в ' . $dest);
        }
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}










function gif_is_animated(string $path): bool
{




    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $found = 0;
    $carry = '';
    while (!feof($fh)) {
        $chunk = $carry . (string)fread($fh, 65536);
        $found += substr_count($chunk, "\x21\xF9\x04");
        if ($found > 1) {
            break;
        }
        $carry = substr($chunk, -2);
    }
    fclose($fh);
    return $found > 1;
}







function img_tmp_gc(int $maxAge = 86400): int
{
    $n = 0;
    $now = time();
    $spots = [originals_dir() . '/.up-*', ROOT . '/storage/tmp/.tmp-*'];
    foreach ((array)(glob(media_dir() . '/*', GLOB_ONLYDIR) ?: []) as $d) {
        if (preg_match('~/\d+$~', $d)) {
            $spots[] = $d . '/.tmp-*';
            foreach ((array)(glob($d . '/*', GLOB_ONLYDIR) ?: []) as $widthDir) {
                $spots[] = $widthDir . '/.tmp-*';
            }
        }
    }
    foreach ($spots as $pattern) {
        foreach ((array)(glob($pattern) ?: []) as $f) {
            if (is_file($f) && $now - (int)@filemtime($f) > $maxAge && @unlink($f)) {
                $n++;
            }
        }
    }



    $known = [];
    foreach (db_all('SELECT file FROM videos') as $r) {
        $known[basename((string)$r['file'])] = true;
    }
    foreach ((array)(glob(media_dir() . '/video/*.mp4') ?: []) as $f) {
        if (is_file($f) && !isset($known[basename($f)]) && $now - (int)@filemtime($f) > $maxAge && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}

function image_add_from_file(string $tmpPath, string $origName, string $alt = ''): array
{
    if (function_exists('set_time_limit')) {
        set_time_limit(180);
    }




    $slot = image_lock(mt_rand(0, IMG_LOCK_SLOTS - 1), true);
    if ($slot === null) {
        throw new RuntimeException('Не удалось получить блокировку обработки изображения. Проверьте storage/locks.');
    }
    try {
    image_raise_memory();

    $info = @getimagesize($tmpPath);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Неподдерживаемый формат (нужны JPG, PNG, WebP или GIF): ' . $origName);
    }
    if ($info[2] === IMAGETYPE_WEBP && !function_exists('imagecreatefromwebp') && img_engine() !== 'imagick') {
        throw new RuntimeException('PHP на сервере не умеет читать WebP: ' . $origName);
    }
    $maxPx = image_max_pixels();
    if ((int)$info[0] * (int)$info[1] > $maxPx) {

        $side = (int)(floor(sqrt($maxPx * 1.5) / 100) * 100);

        $why = img_engine() === 'imagick'
            ? 'это выше абсолютного потолка CMS (' . (int)(IMG_MAX_PIXELS_CAP / 1000000) . ' Мп)'
            : 'при текущем лимите памяти PHP сервер не сможет его обработать';
        throw new RuntimeException('Слишком большое разрешение (' . (int)$info[0] . '×' . (int)$info[1] . ' px) — ' . $why . '. '
            . 'Уменьшите фото до ' . $side . ' px по длинной стороне и загрузите снова: ' . $origName);
    }




    $im = null;
    if (img_engine() === 'imagick') {
        try {
            $probe = new Imagick();
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 60);
            $decoder = ['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'][$types[$info[2]]];
            $probe->readImage($decoder . ':' . $tmpPath . '[0]');
            imagick_auto_orient($probe);
            $w = $probe->getImageWidth();
            $h = $probe->getImageHeight();
            if ($w < 1 || $h < 1) {
                throw new RuntimeException('пустой кадр');
            }
            $color = '#cccccc';
            $one = clone $probe;
            $one->resizeImage(1, 1, Imagick::FILTER_BOX, 1.0);
            $px = $one->getImagePixelColor(0, 0)->getColor();
            $color = sprintf('#%02x%02x%02x', $px['r'] & 255, $px['g'] & 255, $px['b'] & 255);
            $one->clear();
            $probe->clear();
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось прочитать изображение (возможно, повреждённый файл): ' . $origName);
        }
    } else {
        $im = gd_load($tmpPath);
        if (!$im) {
            throw new RuntimeException('Не удалось прочитать изображение (возможно, слишком большое для памяти PHP): ' . $origName);
        }
        $w = imagesx($im);
        $h = imagesy($im);

        $color = '#cccccc';
        $one = @imagescale($im, 1, 1);
        if ($one) {
            $rgb = imagecolorat($one, 0, 0);
            $color = sprintf('#%02x%02x%02x', ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
            gd_free($one);
        }
    }

    $base = pathinfo($origName, PATHINFO_FILENAME);
    $slug = slugify($base, 60);
    if ($slug === '') {
        $slug = 'photo';
    }





    $slug .= '-' . bin2hex(random_bytes(16));

    if (!is_dir(originals_dir())) {
        @mkdir(originals_dir(), 0700, true);
    }
    @chmod(originals_dir(), 0700);



    $tmpDest = originals_dir() . '/.up-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (!@copy($tmpPath, $tmpDest)) {
        gd_free($im);
        throw new RuntimeException('Не удалось сохранить файл в storage/originals (проверьте права на запись).');
    }
    @chmod($tmpDest, 0600);
    try {
        $id = db_insert(
            'INSERT INTO images (slug, ext, orig_name, w, h, color, alt, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$slug, $types[$info[2]], mb_substr($origName, 0, 200), $w, $h, $color, $alt, now()]
        );
    } catch (Throwable $e) {
        @unlink($tmpDest);
        gd_free($im);
        throw $e;
    }
    $img = image_get($id);
    if (!move_file_inplace($tmpDest, image_original_path($img))) {
        db_run('DELETE FROM images WHERE id = ?', [$id]);
        @unlink($tmpDest);
        gd_free($im);
        throw new RuntimeException('Не удалось сохранить файл в storage/originals (проверьте права на запись).');
    }
    @chmod(image_original_path($img), 0600);


    $canWebp = img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported();


    $canAvif = avif_enabled();




    if (getenv('IMG_SKIP_UPLOAD_VARIANTS')) {
        gd_free($im);
        return $img;
    }


    $formats = ['jpg'];
    if ($canWebp) {
        $formats[] = 'webp';
    }
    if ($canAvif) {
        $formats[] = 'avif';
    }
    foreach (image_prewarm_widths($img) as $vw) {
        foreach ($formats as $format) {
            if (!image_make_variant($img, $vw, $format, $im)) {
                image_unlock($slot);
                $slot = null;
                image_delete($id);
                gd_free($im);
                throw new RuntimeException('Не удалось создать ' . strtoupper($format) . '-превью ' . $vw . ' px: ' . $origName
                    . '. Проверьте свободное место на диске и права на media/.');
            }
        }
    }
    $ogDest = og_variant_path($img, 1200, 630);
    if (!is_file($ogDest) && !og_variant_make($img, 1200, 630, $ogDest)) {
        app_log_error('image_add_from_file: OG-кадр будет создан позже по запросу, фото #' . $id);
    }
    gd_free($im);
    return $img;
    } finally {
        image_unlock($slot);
    }
}






function image_usage_map(bool $fresh = false): array
{


    static $map = null;
    if ($map !== null && !$fresh) {
        return $map;
    }
    if (!$fresh && session_status() === PHP_SESSION_ACTIVE) {
        $c = $_SESSION['img_usage'] ?? null;
        if (is_array($c) && isset($c['t'], $c['m']) && is_array($c['m']) && time() - (int)$c['t'] < 120) {
            return $map = $c['m'];
        }
    }
    $use = [];
    $add = static function (int $id) use (&$use): void {
        if ($id > 0) {
            $use[$id] = ($use[$id] ?? 0) + 1;
        }
    };
    foreach (db_all('SELECT image_id, COUNT(*) AS n FROM gallery_items GROUP BY image_id') as $r) {
        $use[(int)$r['image_id']] = ($use[(int)$r['image_id']] ?? 0) + (int)$r['n'];
    }
    foreach (db_all('SELECT cover_image_id AS a, og_image_id AS b FROM pages') as $r) {
        $add((int)$r['a']);
        $add((int)$r['b']);
    }
    foreach (db_all('SELECT og_image_id AS a FROM posts') as $r) {
        $add((int)$r['a']);
    }


    foreach (['default_og_image_id', 'favicon_image_id', 'logo_image_id', 'person_image_id'] as $k) {
        $add((int)setting($k));
    }
    foreach (db_all("SELECT data FROM sections WHERE type = 'photo'") as $r) {
        $d = json_decode((string)$r['data'], true);
        $add((int)($d['image_id'] ?? 0));
    }
    foreach (db_all("SELECT data FROM sections WHERE type = 'pricing'") as $r) {
        $d = json_decode((string)$r['data'], true);
        foreach ((array)($d['items'] ?? []) as $item) {
            if (is_array($item)) {
                $add((int)($item['image_id'] ?? 0));
            }
        }
    }
    foreach (db_all('SELECT poster_image_id FROM videos') as $r) {
        $add((int)$r['poster_image_id']);
    }


    $scan = static function (string $text) use ($add): void {
        if ($text !== '' && stripos($text, 'media') !== false && preg_match_all('~[\\\\/]media[\\\\/](\d+)[\\\\/]~', $text, $m)) {
            foreach ($m[1] as $one) {
                $add((int)$one);
            }
        }
    };
    foreach (db_all("SELECT data FROM sections WHERE data LIKE '%media%'") as $r) {
        $scan((string)$r['data']);
    }
    foreach (db_all("SELECT body_html AS t FROM pages WHERE body_html LIKE '%media%'") as $r) {
        $scan((string)$r['t']);
    }
    $map = $use;



    if (session_status() === PHP_SESSION_ACTIVE && count($use) <= 2000) {
        $_SESSION['img_usage'] = ['t' => time(), 'm' => $use];
    }
    return $map;
}

function image_delete(int $id): string
{
    $lock = image_lock($id, true);
    if ($lock === null || $lock === false) {
        throw new RuntimeException('Не удалось заблокировать удаление изображения. Проверьте storage/locks.');
    }
    try {
    $img = image_get($id);
    if (!$img) {
        return '';
    }
    db_tx(function () use ($id): void {
        $ts = now();
        db_run("UPDATE pages SET updated_at = ? WHERE id IN (
            SELECT owner_id FROM gallery_items WHERE owner_type = 'page' AND image_id = ?
            UNION SELECT s.page_id FROM gallery_items gi JOIN sections s ON s.id = gi.owner_id WHERE gi.owner_type = 'section' AND s.owner_type = 'page' AND gi.image_id = ?
            UNION SELECT id FROM pages WHERE cover_image_id = ? OR og_image_id = ?
            UNION SELECT page_id FROM sections WHERE owner_type = 'page' AND type IN ('photo', 'pricing')
                AND (data LIKE '%\"image_id\":" . $id . ",%' OR data LIKE '%\"image_id\":" . $id . "}%'))", [$ts, $id, $id, $id, $id]);
        db_run('UPDATE posts SET updated_at = ? WHERE og_image_id = ?', [$ts, $id]);
        db_run("UPDATE posts SET updated_at = ? WHERE id IN (
            SELECT page_id FROM sections WHERE owner_type = 'post' AND type = 'photo'
                AND (data LIKE '%\"image_id\":" . $id . ",%' OR data LIKE '%\"image_id\":" . $id . "}%')
            UNION SELECT s.page_id FROM gallery_items gi JOIN sections s ON s.id = gi.owner_id
                WHERE gi.owner_type = 'section' AND s.owner_type = 'post' AND gi.image_id = ?)", [$ts, $id]);
        db_run('DELETE FROM gallery_items WHERE image_id = ?', [$id]);
        db_run('UPDATE pages SET cover_image_id = NULL WHERE cover_image_id = ?', [$id]);
        db_run('UPDATE pages SET og_image_id = NULL WHERE og_image_id = ?', [$id]);
        db_run('UPDATE posts SET og_image_id = NULL WHERE og_image_id = ?', [$id]);
        db_run('UPDATE videos SET poster_image_id = NULL WHERE poster_image_id = ?', [$id]);
        foreach (['default_og_image_id', 'favicon_image_id', 'logo_image_id', 'person_image_id'] as $k) {
            if ((int)setting($k) === $id) {
                set_setting($k, '');
            }
        }

        foreach (db_all("SELECT id, data FROM sections WHERE type = 'photo'") as $r) {
            $d = json_decode((string)$r['data'], true);
            if (is_array($d) && (int)($d['image_id'] ?? 0) === $id) {
                $d['image_id'] = 0;
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($d), (int)$r['id']]);
                page_sections_cache_flush();
            }
        }
        foreach (db_all("SELECT id, data FROM sections WHERE type = 'pricing'") as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d) || !isset($d['items']) || !is_array($d['items'])) {
                continue;
            }
            $changed = false;
            foreach ($d['items'] as &$item) {
                if (is_array($item) && (int)($item['image_id'] ?? 0) === $id) {
                    $item['image_id'] = 0;
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($d), (int)$r['id']]);
                page_sections_cache_flush();
            }
        }
        db_run('DELETE FROM images WHERE id = ?', [$id]);
        image_cache_flush();
        gallery_cache_flush();
    });

    $warning = [];
    $media = media_dir() . '/' . $id;
    rrmdir($media);
    clearstatcache(true, $media);
    if (is_dir($media)) {
        app_log_error('image_delete: не удалось удалить каталог ' . $media);
        $warning[] = 'каталог миниатюр';
    }
    $original = image_original_path($img);
    @unlink($original);
    clearstatcache(true, $original);
    if (is_file($original)) {
        app_log_error('image_delete: не удалось удалить оригинал ' . $original);
        $warning[] = 'оригинал';
    }
    site_touch();
    return $warning ? 'Фото удалено из базы, но остались файлы: ' . implode(', ', $warning) . '. Подробности в storage/error.log.' : '';
    } finally {
        image_unlock($lock);
    }
}
