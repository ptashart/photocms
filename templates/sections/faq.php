<!--
PhotoCMS — photographer portfolio CMS.
Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
SPDX-License-Identifier: GPL-3.0-only
Full license: LICENSE · Additional attribution terms: NOTICE.md.
-->
<?php /** @var array $sec @var array $d */
/* Вопросы и ответы (1.9.48): ответы раскрываются по щелчку (<details>); те же пары
   попадают в JSON-LD FAQPage — см. jsonld_section_blocks() в app/seo.php. */
$items = array_filter((array)$d['items'], static fn($it) => is_array($it)
    && trim((string)($it['q'] ?? '')) !== '' && trim((string)($it['a'] ?? '')) !== '');
if (!$items) { return; }
?>
<section class="<?= sec_classes($sec, 'sec-faq') ?>" id="s<?= (int)$sec['id'] ?>">
  <?php if (trim((string)($d['kicker'] ?? '')) !== ''): ?><p class="sec-kicker"><?= e($d['kicker']) ?></p><?php endif; ?>
  <?php if (trim((string)$d['heading']) !== ''): ?><h2 class="faq-h"><?= e($d['heading']) ?></h2><?php endif; ?>
  <div class="faq">
    <?php foreach ($items as $it): ?>
    <details class="faq-i">
      <summary class="faq-q"><?= e($it['q']) ?></summary>
      <div class="faq-a"><?= nl2br(e($it['a'])) ?></div>
    </details>
    <?php endforeach; ?>
  </div>
</section>
