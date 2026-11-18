<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);






if (PHP_SAPI !== 'cli') {
    exit('Только из командной строки.');
}
require dirname(__DIR__) . '/app/bootstrap.php';
@ini_set('memory_limit', '1024M');
if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

$warm = in_array('--warm', $argv, true);
$imgs = db_all('SELECT * FROM images ORDER BY id');
$errors = 0;
$makeVariant = static function (array $img, int $w, string $ext, $loaded = null) use (&$errors): void {
    if (!image_get((int)$img['id'])) {
        return;
    }
    if (!image_make_variant($img, $w, $ext, $loaded)) {
        $errors++;
        fwrite(STDERR, 'Не удалось создать ' . $ext . ' ' . $w . ' для фото #' . (int)$img['id'] . "\n");
    }
};
$canWebp = img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported();
$canAvif = avif_enabled();

foreach ($imgs as $n => $img) {
    $id = (int)$img['id'];
    $lock = image_lock($id, true);
    if ($lock === null || $lock === false) {
        $errors++;
        fwrite(STDERR, "Не удалось заблокировать фото #$id\n");
        continue;
    }
    try {
        rrmdir(media_dir() . '/' . $id);
        if ($warm && image_get($id)) {
            if (img_engine() === 'imagick') {
                foreach (image_widths($img) as $w) {
                    $makeVariant($img, $w, 'jpg');
                    if ($canWebp) {
                        $makeVariant($img, $w, 'webp');
                    }
                    if ($canAvif) {
                        $makeVariant($img, $w, 'avif');
                    }
                }
            } else {
                $im = gd_load(image_original_path($img));
                if (!$im) {
                    $errors++;
                    fwrite(STDERR, "Не прочитался оригинал #$id\n");
                    continue;
                }
                $widths = image_widths($img);
                $big = array_filter($widths, static fn(int $w): bool => $w > 900);
                foreach ($big as $w) {
                    $makeVariant($img, $w, 'jpg', $im);
                    if ($canWebp) {
                        $makeVariant($img, $w, 'webp', $im);
                    }
                    if ($canAvif) {
                        $makeVariant($img, $w, 'avif', $im);
                    }
                }
                gd_free($im);
                $im = null;
                foreach (array_diff($widths, $big) as $w) {
                    $makeVariant($img, $w, 'jpg');
                    if ($canWebp) {
                        $makeVariant($img, $w, 'webp');
                    }
                    if ($canAvif) {
                        $makeVariant($img, $w, 'avif');
                    }
                }
            }
        }
    } finally {
        image_unlock($lock);
    }
    echo ($n + 1) . '/' . count($imgs) . "\r";
}
echo "\nГотово. Обработано фото: " . count($imgs) . ". Ошибок: $errors.\n";
site_touch();
if ($errors > 0) {
    exit(1);
}
