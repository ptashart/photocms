<!--
PhotoCMS — photographer portfolio CMS.
Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
SPDX-License-Identifier: GPL-3.0-only
Full license: LICENSE · Additional attribution terms: NOTICE.md.
-->
<?php /** @var array $sec @var array $d */
/* Пакеты и цены (1.9.63): карточки с вилкой цены. Те же данные попадают в JSON-LD
   OfferCatalog — см. jsonld_pricing_offers() в app/seo.php. Публикуем карточку, если
   заполнено хоть что-то из названия/цены/описания — тот же критерий, что у редактора
   при сохранении (аудит 1.9.67, п.5: раньше карточка с одним описанием сохранялась,
   но молча не показывалась). Фото карточки (1.9.69): необязательное, 4:5 с кадрированием
   по фокусной точке; то же фото уходит в Offer.image микроразметки. */
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
      <?php /* длительность выводим и без цены (пакет «бесплатно»/бесценный): раньше она
               пряталась внутри условия непустой цены (аудит 1.9.78) */ ?>
      <?php if ($price !== '' || $dur !== ''): ?><span class="price-val"><?= e($price) ?><?php if ($dur !== ''): ?><span class="price-dur"><?= $price !== '' ? ' · ' : '' ?><?= e($dur) ?></span><?php endif; ?></span><?php endif; ?>
      <?php if ($desc !== ''): ?><span class="price-desc"><?= nl2br(e($desc)) ?></span><?php endif; ?>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
</section>
