<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php
$pages = listing_pages($d, $page);
if (!$pages) { return; }
$fmt = in_array($d['format'], ['portrait', 'landscape', 'square', 'original'], true) ? $d['format'] : 'portrait';

$first = !empty($sec['is_first']);
?>
<section class="<?= sec_classes($sec, 'sec-listing') ?>" id="s<?= (int)$sec['id'] ?>">
  <?php if (trim((string)($d['kicker'] ?? '')) !== ''): ?><p class="sec-kicker"><?= e($d['kicker']) ?></p><?php endif; ?>
  <?php if (trim((string)($d['heading'] ?? '')) !== ''): ?><h2 class="listing-h"><?= e($d['heading']) ?></h2><?php endif; ?>
  <?= listing_html($pages, $fmt, (int)$d['columns'], $first) ?>
</section>
