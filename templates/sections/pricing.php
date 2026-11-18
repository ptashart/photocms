<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
?>
<?php






$items = array_values(array_filter((array)$d['items'], static fn($it) => is_array($it)
    && (trim((string)($it['name'] ?? '')) !== '' || trim((string)($it['price_from'] ?? '')) !== ''
        || trim((string)($it['desc'] ?? '')) !== '' || (int)($it['image_id'] ?? 0) > 0)));
if (!$items) { return; }
$cur = (string)($d['currency'] ?? 'RUB');
?>
<section class="<?= sec_classes($sec, 'sec-pricing') ?>" id="s<?= (int)$sec['id'] ?>">
  <?php if (trim((string)($d['kicker'] ?? '')) !== ''): ?><p class="sec-kicker"><?= e($d['kicker']) ?></p><?php endif; ?>
  <?php if (trim((string)$d['heading']) !== ''): ?><h2 class="pricing-h"><?= e($d['heading']) ?></h2><?php endif; ?>
  <div class="pricing">
    <?php foreach ($items as $it):
      $name = trim((string)($it['name'] ?? ''));
      $desc = trim((string)($it['desc'] ?? ''));
      $price = price_format_range((string)($it['price_from'] ?? ''), (string)($it['price_to'] ?? ''), $cur);
      $dur = trim((string)($it['duration'] ?? ''));
      $url = trim((string)($it['url'] ?? ''));
      $tag = $url !== '' ? 'a' : 'div';
      $href = $url !== '' ? ' href="' . e(safe_href($url)) . '"' : '';
      $img = (int)($it['image_id'] ?? 0) > 0 ? image_get((int)$it['image_id']) : null;
    ?>
    <<?= $tag ?> class="price-card"<?= $href ?>>
      <?php if ($img): ?>
      <span class="price-img"><?= img_html($img, '(max-width: 700px) 92vw, 300px', $name, false, 900) ?></span>
      <?php endif; ?>
      <?php if ($name !== ''): ?><span class="price-name"><?= e($name) ?></span><?php endif; ?>
      <?php
 ?>
      <?php if ($price !== '' || $dur !== ''): ?><span class="price-val"><?= e($price) ?><?php if ($dur !== ''): ?><span class="price-dur"><?= $price !== '' ? ' · ' : '' ?><?= e($dur) ?></span><?php endif; ?></span><?php endif; ?>
      <?php if ($desc !== ''): ?><span class="price-desc"><?= nl2br(e($desc)) ?></span><?php endif; ?>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
</section>
