<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
/** @var array $page */
$sections = page_sections((int)$page['id']);
/* свой текст LED-штампа страницы (1.9.89): действует на все фото и галереи страницы */
fx_stamp_text(trim((string)($page['stamp_text'] ?? '')));
$hasH1 = false;
foreach ($sections as $s) {
    /* H1 ищем и в текстовых секциях, и в секциях «HTML-код» — раньше <h1> в HTML-секции
       не замечался, и на странице оказывалось два H1 */
    $src = $s['type'] === 'text' ? (string)$s['data']['html'] : ($s['type'] === 'html' ? (string)$s['data']['code'] : '');
    /* именно открывающий тег <h1 с пробелом или сразу '>': stripos('<h1') ловил ещё и
       <h10…, <h1something, а также вхождения внутри атрибутов и комментариев */
    if ($src !== '' && preg_match('~<h1(?:\s|>)~i', $src)) {
        $hasH1 = true;
        break;
    }
}
$h1 = trim((string)$page['h1']) !== '' ? (string)$page['h1'] : (string)$page['title'];
/* Единая визуальная ось: если на странице есть центрированные текстовые секции (narrow/medium),
   заголовок и крошки выравниваем по той же колонке 700px — иначе заголовок «уезжал» влево */
$centerAxis = false;
foreach ($sections as $s) {
    if ($s['type'] !== 'text') {
        continue;
    }
    $w = (string)($s['data']['width'] ?? 'narrow');
    if (!in_array($w, ['narrow', 'medium', 'wide'], true)) {
        $w = 'narrow';
    }
    if ($w !== 'wide') {
        $centerAxis = true;
        break;
    }
}
$axis = $centerAxis ? ' t-axis' : '';
?>
<?= page_crumbs_html($page, $centerAxis ? 't-axis' : '') ?>
<?php if (!$hasH1): ?>
  <h1 class="<?= !empty($page['show_title']) ? 'page-title' . $axis : 'vh' ?>"><?= e($h1) ?></h1>
<?php endif; ?>
<?php $firstMedia = sections_first_media($sections, $page); ?>
<?php $firstMediaId = (int)($firstMedia['section']['id'] ?? 0); ?>
<?php foreach ($sections as $sec): ?>
  <?php $sec['is_first'] = (int)$sec['id'] === $firstMediaId; ?>
  <?= render_section($sec, $page) ?>
<?php endforeach; ?>
