<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php /** @var array $sec @var array $d */
/* Видео (1.9.48): ролик из видеотеки (раздел «Видео» в админке). Пропорции плеера —
   от обложки (CSS-переменная --ar), без обложки — 16:9. Разметка VideoObject для этого
   же ролика собирается в jsonld_section_blocks() (app/seo.php). */
$v = (int)($d['video_id'] ?? 0) > 0 ? video_get((int)$d['video_id']) : null;
if (!$v || trim((string)$v['file']) === '') { return; }
$poster = (int)($v['poster_image_id'] ?? 0) > 0 ? image_get((int)$v['poster_image_id']) : null;
$posterUrl = '';
$ar = '';
if ($poster) {
    $posterUrl = image_url($poster, (int)image_pick_width(image_widths($poster), 1240), 'jpg');
    if ((int)$poster['w'] > 0 && (int)$poster['h'] > 0) {
        $ar = ' style="--ar:' . (int)$poster['w'] . '/' . (int)$poster['h'] . '"';
    }
}
?>
<section class="<?= sec_classes($sec, 'sec-video') ?>" id="s<?= (int)$sec['id'] ?>">
  <figure class="pp">
    <video controls preload="metadata" playsinline src="<?= e(video_url($v)) ?>"<?= $posterUrl !== '' ? ' poster="' . e($posterUrl) . '"' : '' ?><?= $ar ?>></video>
    <?php if (trim((string)$v['title']) !== ''): ?><figcaption><?= e($v['title']) ?></figcaption><?php endif; ?>
  </figure>
</section>
