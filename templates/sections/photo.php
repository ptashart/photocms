<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php
$img = (int)($d['image_id'] ?? 0) > 0 ? image_get((int)$d['image_id']) : null;
if (!$img) { return; }


$link = null;
$linkUrl = normalize_url((string)($d['link'] ?? ''));
if ($linkUrl !== '') {
    $link = ['url' => $linkUrl, 'new_tab' => !empty($d['link_new_tab'])];
}
$lb = $link !== null ? false : lb_opt($d['lightbox'] ?? '');

$first = !empty($sec['is_first']);
$size = (string)($d['size'] ?? 'wide');
$base = ($sec['owner_type'] ?? 'page') === 'post' ? 700 : 1100;
$float = in_array($size, ['left', 'right'], true);
?>
<section class="<?= sec_classes($sec, 'sec-photo') . ($float ? ' sec-photo-float' : '') ?>" id="s<?= (int)$sec['id'] ?>">
  <?= single_photo_html($img, $size, $lb, (string)$page['title'], $base, (string)($d['caption'] ?? ''), $first, $first, $link) ?>
</section>
