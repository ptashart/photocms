<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php
$items = gallery_images('section', (int)$sec['id']);
if (!$items) { return; }
$cols = (int)$d['columns'] > 0 ? (int)$d['columns'] : (int)setting('gallery_columns');
$colsM = (int)setting('gallery_columns_mobile');
$lb = lb_opt($d['lightbox'] ?? '') ?? (setting('lightbox') === '1');
$layout = in_array($d['layout'], ['masonry', 'grid', 'lineup', 'strip', 'slides', 'contactsheet'], true) ? $d['layout'] : 'masonry';

$first = !empty($sec['is_first']);

fx_stamp_text(trim((string)($page['stamp_text'] ?? '')));
?>
<section class="<?= sec_classes($sec, 'sec-gallery') ?>" id="s<?= (int)$sec['id'] ?>">
<?php if ($layout === 'masonry'): ?>
  <?= masonry_html($items, $cols, $colsM, (string)$page['title'], $lb, $first) ?>
<?php elseif ($layout === 'strip'): ?>
  <?php






  $apAttr = '';
  if (($d['autoplay'] ?? '0') === '1') {
      $apDir = ($d['autoplay_dir'] ?? 'fwd') === 'back' ? 'back' : 'fwd';
      $apSpeed = (int)($d['autoplay_speed'] ?? 5);
      $apSpeed = in_array($apSpeed, [3, 4, 5, 6, 8, 10, 15], true) ? $apSpeed : 5;
      $apAttr = ' data-autoplay="' . $apDir . '" data-autoplay-speed="' . $apSpeed . '"';
  }
  ?>
  <div class="strip-wrap js-gallery" data-strip<?= $apAttr ?>>
    <?php
 ?>
    <div class="strip" data-strip-track tabindex="0" role="region" aria-label="Лента фотографий">
      <?php foreach ($items as $i => $img): ?>
        <?php $sizes = gallery_sizes('strip', $cols, $colsM, $img); ?>
        <?= photo_html($img, $i, $sizes, $lb, (string)$page['title'], $first && $i < 3, $first && $i === 0) ?>
      <?php endforeach; ?>
    </div>
    <?php if (count($items) > 1): ?>
      <?php
 ?>
      <button class="strip-btn strip-prev" type="button" data-strip-prev aria-label="Предыдущее фото" disabled><?= icon('prev', 20) ?></button>
      <button class="strip-btn strip-next" type="button" data-strip-next aria-label="Следующее фото"><?= icon('next', 20) ?></button>
      <?php if ($apAttr !== ''): ?>
        <button class="strip-btn strip-toggle" type="button" data-strip-toggle aria-label="Приостановить автопрокрутку"><?= icon('pause', 20) ?></button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php elseif ($layout === 'slides'): ?>
  <?php



  $fx = in_array($d['fx'] ?? '', ['fade', 'cut', 'slide'], true) ? $d['fx'] : 'fade';
  $spd = (int)($d['speed'] ?? 5);
  $spd = in_array($spd, [3, 4, 5, 6, 8, 10, 15], true) ? $spd : 5;
  $hgt = in_array($d['height'] ?? '', ['full', 'tall', 'half'], true) ? $d['height'] : 'full';
  ?>
  <div class="slides slides-h-<?= $hgt ?> slides-fx-<?= $fx ?>" data-slides data-speed="<?= $spd ?>"
       role="region" aria-roledescription="слайдшоу" aria-label="Фотографии: <?= e((string)$page['title']) ?>">
    <?php foreach ($items as $i => $img): ?>
      <?php $alt = trim((string)$img['alt']) !== '' ? (string)$img['alt'] : (string)$page['title']; ?>
      <div class="slides-item<?= $i === 0 ? ' on' : '' ?>" style="--c:<?= e(safe_color((string)$img['color'])) ?>">
        <?= img_html($img, '100vw', $alt, $first && $i === 0, 2520, $first && $i === 0) ?>
      </div>
    <?php endforeach; ?>
    <?php if (count($items) > 1): ?>
      <button class="slides-btn slides-prev" type="button" data-slides-prev aria-label="Предыдущее фото"><?= icon('prev', 20) ?></button>
      <button class="slides-btn slides-next" type="button" data-slides-next aria-label="Следующее фото"><?= icon('next', 20) ?></button>
    <?php endif; ?>
  </div>
<?php else: ?>
  <?php
  if ($layout === 'contactsheet') {

      $colsD = max(3, min(8, $cols + 2));
      $colsMc = max(2, min(3, $colsM + 1));
  } else {
      $colsD = max(1, min(6, $cols));
      $colsMc = max(1, min(3, $colsM));
  }
  $cls = $layout === 'grid' ? 'grid-gallery' : ($layout === 'contactsheet' ? 'contactsheet' : 'lineup');




  $sizes = gallery_sizes($layout === 'contactsheet' ? 'grid' : $layout, $colsD, $colsMc);
  ?>
  <div class="<?= $cls ?> js-gallery" style="--cols:<?= $colsD ?>;--cols-m:<?= $colsMc ?>">
    <?php foreach ($items as $i => $img): ?>
      <?= photo_html($img, $i, $sizes, $lb, (string)$page['title'], $first && $i < 2, $first && $i === 0) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
