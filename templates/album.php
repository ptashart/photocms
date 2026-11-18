<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
/** @var array $page */
$photos = gallery_images('page', (int)$page['id']);
$cols = (int)$page['album_columns'] > 0 ? (int)$page['album_columns'] : (int)setting('gallery_columns');
$colsM = (int)setting('gallery_columns_mobile');
$h1 = trim((string)$page['h1']) !== '' ? (string)$page['h1'] : (string)$page['title'];
/* Разделы под галереей (1.9.52): текст, кнопки, FAQ, форма и т.п. is_first не даём —
   первым медиа и так считается сама галерея альбома (eager/preload у её первых кадров). */
$sections = page_sections((int)$page['id']);
/* свой текст LED-штампа альбома (1.9.89): заменяет дату на всех кадрах страницы —
   и в основной галерее, и в разделах ниже; ставим до любого вывода фото,
   даже если основная галерея пуста (1.9.93) */
fx_stamp_text(trim((string)($page['stamp_text'] ?? '')));
/* Если в описании альбома или в разделах уже есть свой <h1>, автоматический не добавляем —
   H1 на странице должен быть один */
$hasH1 = (bool)preg_match('~<h1(?:\s|>)~i', (string)$page['body_html']);
if (!$hasH1) {
    foreach ($sections as $s) {
        $src = $s['type'] === 'text' ? (string)$s['data']['html'] : ($s['type'] === 'html' ? (string)$s['data']['code'] : '');
        if ($src !== '' && preg_match('~<h1(?:\s|>)~i', $src)) {
            $hasH1 = true;
            break;
        }
    }
}
$related = album_related_pages($page);
?>
<article class="album">
  <?php
  $hasBody = trim(strip_tags((string)$page['body_html'])) !== '';
  /* Описание альбома центрировано (700px) — заголовок, крошки и счётчик выравниваем по той же оси */
  $axis = $hasBody ? ' t-axis' : '';
  ?>
  <?= page_crumbs_html($page, $hasBody ? 't-axis' : '') ?>
  <?php if (!$hasH1): ?>
  <h1 class="<?= !empty($page['show_title']) ? 'page-title' . $axis : 'vh' ?>"><?= e($h1) ?></h1>
  <?php endif; ?>
  <?php $shootNote = trim((string)($page['shoot_note'] ?? '')); ?>
  <?php if ($shootNote !== ''): ?>
  <p class="shoot-note<?= $axis ?>"><?= e($shootNote) ?></p>
  <?php endif; ?>
  <?php if ($photos): ?>
  <p class="album-count<?= $axis ?>"><?= count($photos) . ' ' . plural_form(count($photos), 'фотография', 'фотографии', 'фотографий') ?></p>
  <?php endif; ?>
  <?php if ($hasBody): ?>
    <div class="sec sec-text w-narrow sp-md"><div class="wysiwyg"><?= $page['body_html'] ?></div></div>
  <?php endif; ?>
  <?php if ($photos): ?>
    <?php
    /* Раскладка альбома (1.9.76): masonry по умолчанию; grid и lineup — теми же
       рендерерами, что у раздела «Галерея» на секционных страницах;
       1.9.86 — contactsheet (контактный лист: плотная сетка в рамках плёнки);
       1.9.96 — hung (развеска: три формата и сдвиги из фиксированного набора) */
    $layout = in_array($page['album_layout'] ?? '', ['masonry', 'grid', 'lineup', 'contactsheet', 'hung'], true) ? (string)$page['album_layout'] : 'masonry';
    $lb = setting('lightbox') === '1';
    ?>
    <?php if ($layout === 'hung'): ?>
      <?= hung_html($photos, (string)$page['title'], $lb) ?>
    <?php elseif ($layout === 'masonry'): ?>
      <?= masonry_html($photos, $cols, $colsM, (string)$page['title'], $lb, true) ?>
    <?php else: ?>
      <?php
      if ($layout === 'contactsheet') {
          /* контактные листы плотные: добавляем пару колонок к обычной сетке */
          $colsD = max(3, min(8, $cols + 2));
          $colsMc = max(2, min(3, $colsM + 1));
      } else {
          $colsD = max(1, min(6, $cols));
          $colsMc = max(1, min(3, $colsM));
      }
      /* sizes — от тех же колонок, что у разметки ($colsD/$colsMc): у контактного листа
         их больше, иначе браузер грузил кадры на ~40% крупнее нужного (1.9.93) */
      $sizes = gallery_sizes($layout === 'contactsheet' ? 'grid' : $layout, $colsD, $colsMc);
      $cls = $layout === 'grid' ? 'grid-gallery' : ($layout === 'contactsheet' ? 'contactsheet' : 'lineup');
      ?>
      <div class="<?= $cls ?> js-gallery" style="--cols:<?= $colsD ?>;--cols-m:<?= $colsMc ?>">
        <?php foreach ($photos as $i => $img): ?>
          <?= photo_html($img, $i, $sizes, $lb, (string)$page['title'], $i < 2, $i === 0) ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</article>
<?php foreach ($sections as $sec): ?>
  <?= render_section($sec, $page) ?>
<?php endforeach; ?>
<?php if ($related): ?>
  <section class="sec sp-lg related">
    <div class="related-head">
      <?php if (trim(setting('related_title')) !== ''): ?>
        <h2 class="related-title"><?= e(setting('related_title')) ?></h2>
      <?php endif; ?>
      <?php $parent = page_get((int)$page['parent_id']); ?>
      <?php if ($parent && !empty($parent['is_published'])): ?>
        <a class="related-all" href="<?= e(page_url($parent)) ?>"><?= e($parent['title']) ?> — все работы →</a>
      <?php endif; ?>
    </div>
    <?= related_html($related) ?>
  </section>
<?php endif; ?>
