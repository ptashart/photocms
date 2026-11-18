<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */

$sections = page_sections((int)$page['id']);

fx_stamp_text(trim((string)($page['stamp_text'] ?? '')));
$hasH1 = false;
foreach ($sections as $s) {


    $src = $s['type'] === 'text' ? (string)$s['data']['html'] : ($s['type'] === 'html' ? (string)$s['data']['code'] : '');


    if ($src !== '' && preg_match('~<h1(?:\s|>)~i', $src)) {
        $hasH1 = true;
        break;
    }
}
$h1 = trim((string)$page['h1']) !== '' ? (string)$page['h1'] : (string)$page['title'];


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
