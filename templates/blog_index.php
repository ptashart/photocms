<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
/** @var array $page @var array $posts @var array $sec @var int $pg @var int $pages */
$secTitle = section_title($sec);
$secDesc = section_description($sec);
$secBase = section_base($sec);
?>
<nav class="crumbs" aria-label="Хлебные крошки"><a href="/">Главная</a> <span aria-hidden="true">/</span> <span aria-current="page"><?= e($secTitle) ?></span></nav>
<h1 class="page-title"><?= e($secTitle) . e($pg > 1 ? ' — страница ' . $pg : '') ?></h1>
<?php if ($secDesc !== ''): ?>
  <p class="blog-desc"><?= e($secDesc) ?></p>
<?php endif; ?>
<?php if (!$posts): ?>
  <div class="sec sec-text w-narrow sp-md"><div class="wysiwyg"><p class="muted">Заметок пока нет.</p></div></div>
<?php else: ?>
  <div class="blog-list">
    <?php foreach ($posts as $pi => $p): $ex = post_excerpt($p); $th = post_thumb($p); $url = post_url($p); ?>
    <article class="bp<?= $th ? ' has-thumb' : '' ?>">
      <?php if ($th): ?>
      <?php /* первая миниатюра — вероятный LCP-элемент списка: её грузим сразу, остальные — lazy */ ?>
      <a class="bp-thumb" href="<?= e($url) ?>" tabindex="-1" aria-hidden="true" style="--c:<?= e(safe_color((string)$th['color'])) ?>"><?= img_html($th, '(max-width: 620px) 100vw, 200px', (string)$p['title'], $pi === 0, 600, $pi === 0) ?></a>
      <?php endif; ?>
      <div class="bp-text">
        <h2 class="bp-title"><a href="<?= e($url) ?>"><?= e($p['title']) ?></a></h2>
        <div class="post-meta"><time datetime="<?= e(date('Y-m-d', post_pub_ts($p))) ?>"><?= e(ru_date(post_pub_ts($p))) ?></time> · <?= post_reading_minutes($p) ?> мин чтения</div>
        <?php if ($ex !== ''): ?><p class="bp-ex"><?= e($ex) ?></p><?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Страницы списка">
    <?php /* rel="prev/next" Google не учитывает с 2019 года — оставляем обычные ссылки */ ?>
    <?php if ($pg > 1): ?><a href="<?= e($secBase . ($pg > 2 ? '?page=' . ($pg - 1) : '')) ?>">← Новее</a><?php else: ?><span></span><?php endif; ?>
    <span class="pager-nums">
      <?php /* номера страниц: текущая, по две соседние вокруг неё и крайние */ ?>
      <?php
      $shown = [1, $pages, $pg - 1, $pg, $pg + 1, $pg - 2, $pg + 2];
      $shown = array_values(array_unique(array_filter($shown, static fn ($n) => $n >= 1 && $n <= $pages)));
      sort($shown);
      $prevN = 0;
      foreach ($shown as $n):
          if ($prevN && $n > $prevN + 1): ?><span class="pager-gap">…</span><?php endif;
          if ($n === $pg): ?><span class="pager-now" aria-current="page"><?= $n ?></span><?php
          else: ?><a href="<?= e($secBase . ($n > 1 ? '?page=' . $n : '')) ?>"><?= $n ?></a><?php endif;
          $prevN = $n;
      endforeach; ?>
    </span>
    <?php if ($pg < $pages): ?><a href="<?= e($secBase . '?page=' . ($pg + 1)) ?>">Раньше →</a><?php else: ?><span></span><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
