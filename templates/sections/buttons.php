<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php
$items = array_filter((array)$d['items'], static fn($b) => is_array($b) && trim((string)($b['label'] ?? '')) !== '');
if (!$items) { return; }
$style = $d['style'] === 'solid' ? 'btn-solid' : 'btn-outline';
$al = $d['align'] === 'left' ? '' : ' t-center';
?>
<section class="<?= sec_classes($sec, 'sec-buttons' . $al) ?>" id="s<?= (int)$sec['id'] ?>">
  <?php foreach ($items as $b): ?>
    <a class="btn <?= $style ?>" href="<?= e(safe_href((string)($b['url'] ?? ''))) ?>"<?= !empty($b['newtab']) ? ' target="_blank" rel="noopener"' : '' ?>>
      <?= icon((string)($b['icon'] ?? '')) ?><span><?= e($b['label']) ?></span>
    </a>
  <?php endforeach; ?>
</section>
