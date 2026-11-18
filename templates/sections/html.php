<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php /** @var array $sec @var array $d */ ?>
<section class="<?= sec_classes($sec, 'sec-html') ?>" id="s<?= (int)$sec['id'] ?>">
  <?= public_custom_code((string)$d['code']) ?>
</section>
