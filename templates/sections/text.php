<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php /** @var array $sec @var array $d */
$w = in_array($d['width'], ['narrow', 'medium', 'wide'], true) ? $d['width'] : 'narrow';
$al = $d['align'] === 'center' ? ' t-center' : '';
?>
<section class="<?= sec_classes($sec, 'sec-text w-' . $w . $al) ?>" id="s<?= (int)$sec['id'] ?>">
  <div class="wysiwyg"><?= $d['html'] ?></div>
</section>
