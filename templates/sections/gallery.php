<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php /** @var array $sec @var array $d @var array $page */
$items = gallery_images('section', (int)$sec['id']);
if (!$items) { return; }
$cols = (int)$d['columns'] > 0 ? (int)$d['columns'] : (int)setting('gallery_columns');
$colsM = (int)setting('gallery_columns_mobile');
$lb = lb_opt($d['lightbox'] ?? '') ?? (setting('lightbox') === '1');
$layout = in_array($d['layout'], ['masonry', 'grid', 'lineup', 'strip', 'slides', 'contactsheet'], true) ? $d['layout'] : 'masonry';
/* Галерея первой секции — почти наверняка LCP-кадр: грузим сразу и с высоким приоритетом */
$first = !empty($sec['is_first']);
/* свой текст LED-штампа страницы (1.9.89): заменяет дату на кадрах этой галереи */
fx_stamp_text(trim((string)($page['stamp_text'] ?? '')));
?>
<section class="<?= sec_classes($sec, 'sec-gallery') ?>" id="s<?= (int)$sec['id'] ?>">
<?php if ($layout === 'masonry'): ?>
  <?= masonry_html($items, $cols, $colsM, (string)$page['title'], $lb, $first) ?>
<?php elseif ($layout === 'strip'): ?>
  <?php
  /* Лента-слайдшоу (1.9.34): высота слайдов фиксированная, ширина — по пропорциям
     кадра (соседние фото «подглядывают» по краям).
     Стрелки и драг — в site.js (data-strip); крупный просмотр — штатный лайтбокс (js-gallery). */
  /* sizes считаем для каждого кадра по его пропорциям; preload использует ту же функцию. */
  /* автопрокрутка (1.9.45): направление и пауза уходят в data-атрибуты, логика — в site.js;
     при выключенной никаких атрибутов нет — лента как раньше */
  $apAttr = '';
  if (($d['autoplay'] ?? '0') === '1') {
      $apDir = ($d['autoplay_dir'] ?? 'fwd') === 'back' ? 'back' : 'fwd';
      $apSpeed = (int)($d['autoplay_speed'] ?? 5);
      $apSpeed = in_array($apSpeed, [3, 4, 5, 6, 8, 10, 15], true) ? $apSpeed : 5;
      $apAttr = ' data-autoplay="' . $apDir . '" data-autoplay-speed="' . $apSpeed . '"';
  }
  ?>
  <div class="strip-wrap js-gallery" data-strip<?= $apAttr ?>>
    <?php /* tabindex+role: прокручиваемая лента в Safari иначе не получает фокус
       с клавиатуры — стрелки ← → недоступны (ревизия 1.9.35, п.14) */ ?>
    <div class="strip" data-strip-track tabindex="0" role="region" aria-label="Лента фотографий">
      <?php foreach ($items as $i => $img): ?>
        <?php $sizes = gallery_sizes('strip', $cols, $colsM, $img); ?>
        <?= photo_html($img, $i, $sizes, $lb, (string)$page['title'], $first && $i < 3, $first && $i === 0) ?>
      <?php endforeach; ?>
    </div>
    <?php if (count($items) > 1): ?>
      <?php /* SVG-иконки вместо текстовых ‹ ›: в разных шрифтах символы стояли по-разному,
         а в лайтбоксе и «наверх» уже SVG (ревизия 1.9.35, п.15) */ ?>
      <button class="strip-btn strip-prev" type="button" data-strip-prev aria-label="Предыдущее фото" disabled><?= icon('prev', 20) ?></button>
      <button class="strip-btn strip-next" type="button" data-strip-next aria-label="Следующее фото"><?= icon('next', 20) ?></button>
    <?php endif; ?>
  </div>
<?php elseif ($layout === 'slides'): ?>
  <?php
  /* Полноэкранное слайдшоу (1.9.46): кадры сложены стопкой на всю ширину окна, смена —
     по таймеру в site.js (data-slides); эффект (fx) и высота — классами, темп — data-speed.
     Кадрирование cover идёт вокруг фокусной точки из медиатеки (img_html сам ставит object-position). */
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
      /* контактные листы плотные: добавляем пару колонок к обычной сетке (1.9.86) */
      $colsD = max(3, min(8, $cols + 2));
      $colsMc = max(2, min(3, $colsM + 1));
  } else {
      $colsD = max(1, min(6, $cols));
      $colsMc = max(1, min(3, $colsM));
  }
  $cls = $layout === 'grid' ? 'grid-gallery' : ($layout === 'contactsheet' ? 'contactsheet' : 'lineup');
  /* sizes — из единой точки gallery_sizes() (аудит 1.9.40, п.9): телефон — мобильные колонки,
     планшет 701–900 px — десктопные колонки на всю ширину, десктоп — контентная колонка;
     contactsheet по сетке считается как grid. Колонки — те же, что у разметки ($colsD/$colsMc):
     у контактного листа их больше, иначе браузер грузил кадры на ~40% крупнее нужного (1.9.93) */
  $sizes = gallery_sizes($layout === 'contactsheet' ? 'grid' : $layout, $colsD, $colsMc);
  ?>
  <div class="<?= $cls ?> js-gallery" style="--cols:<?= $colsD ?>;--cols-m:<?= $colsMc ?>">
    <?php foreach ($items as $i => $img): ?>
      <?= photo_html($img, $i, $sizes, $lb, (string)$page['title'], $first && $i < 2, $first && $i === 0) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
