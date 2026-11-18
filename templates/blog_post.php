<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */

$backlinks = post_backlinks($post);
$updated = (int)$post['updated_at'] > post_pub_ts($post) + 60;
$sec = post_section($post);
$secBase = section_base($sec);
$secTitle = section_title($sec);
[$older, $newer] = post_neighbors($post);


$sections = post_content_sections((int)$post['id']);
$pageCtx = post_page_context($post);
$heads = post_content_heads($sections);




$hasH1 = false;
foreach ($sections as $s) {
    $src = $s['type'] === 'text' ? (string)$s['data']['html'] : ($s['type'] === 'html' ? (string)$s['data']['code'] : '');

    if ($src !== '' && preg_match('~<h1(?:\s|>)~i', $src)) {
        $hasH1 = true;
        break;
    }
}
?>
<nav class="crumbs" aria-label="Хлебные крошки"><a href="/">Главная</a> <span aria-hidden="true">/</span> <a href="<?= e($secBase) ?>"><?= e($secTitle) ?></a> <span aria-hidden="true">/</span> <span aria-current="page"><?= e($post['title']) ?></span></nav>
<article class="post">
  <?php







  $metaTop = setting('blog_post_meta') === '1';
  $shareOn = setting('share_buttons') === '1';
  $metaParts = [];
  if (setting('blog_meta_section') === '1') {
      $metaParts[] = '<a class="pm-chip" href="' . e($secBase) . '">' . e($secTitle) . '</a>';
  }
  if (setting('blog_meta_published') === '1') {
      $metaParts[] = '<time datetime="' . e(date('Y-m-d', post_pub_ts($post))) . '">' . e(ru_date(post_pub_ts($post))) . '</time>';
  }
  if ($updated && setting('blog_meta_updated') === '1') {
      $metaParts[] = 'обновлено <time datetime="' . e(date('Y-m-d', (int)$post['updated_at'])) . '">' . e(ru_date((int)$post['updated_at'])) . '</time>';
  }
  if (setting('blog_meta_reading') === '1') {
      $metaParts[] = post_reading_minutes($post) . ' мин чтения';
  }
  ?>
  <?php if ($metaTop && $metaParts): ?>
  <div class="post-meta post-meta-top">
    <?= implode(' <span class="pm-sep">·</span> ', $metaParts) ?>
  </div>
  <?php endif; ?>
  <?php if (!$hasH1): ?>
  <h1 class="page-title"><?= e($post['title']) ?></h1>
  <?php endif; ?>
  <?php if (!$metaTop): ?>
  <?php


  $metaOld = [];
  if (setting('blog_meta_section') === '1') {
      $metaOld[] = '<a class="pm-chip" href="' . e($secBase) . '">' . e($secTitle) . '</a>';
  }
  if (setting('blog_meta_published') === '1') {
      $metaOld[] = '<time datetime="' . e(date('Y-m-d', post_pub_ts($post))) . '">' . e(ru_date(post_pub_ts($post))) . '</time>';
  }
  if ($updated && setting('blog_meta_updated') === '1') {
      $metaOld[] = 'обновлено <time datetime="' . e(date('Y-m-d', (int)$post['updated_at'])) . '">' . e(ru_date((int)$post['updated_at'])) . '</time>';
  }
  if (setting('blog_meta_reading') === '1') {
      $metaOld[] = post_reading_minutes($post) . ' мин чтения';
  }
  if ($metaOld): ?>
  <div class="post-meta"><?= implode(' · ', $metaOld) ?></div>
  <?php endif; ?>
  <?php endif; ?>
  <?php if ($shareOn && $metaTop): ?><?= share_html(post_url($post), (string)$post['title']) ?><?php endif; ?>
  <?php  ?>
  <?php if (count($heads) >= 3): ?><?= toc_html($heads) ?><?php endif; ?>
  <?php $firstMedia = sections_first_media($sections, $pageCtx); ?>
  <?php $firstMediaId = (int)($firstMedia['section']['id'] ?? 0); ?>
  <div class="post-body">
  <?php foreach ($sections as $s): ?>
    <?php $s['is_first'] = (int)$s['id'] === $firstMediaId; ?>
    <?= render_section($s, $pageCtx) ?>
  <?php endforeach; ?>
  </div>
</article>
<?php if ($shareOn && !$metaTop): ?><?= share_html(post_url($post), (string)$post['title']) ?><?php endif; ?>
<?php if ($backlinks): ?>
<aside class="backlinks">
  <h2>Сюда ссылаются</h2>
  <ul>
    <?php foreach ($backlinks as $b): ?>
    <li><a href="<?= e(post_url($b)) ?>"><?= e($b['title']) ?></a></li>
    <?php endforeach; ?>
  </ul>
</aside>
<?php endif; ?>
<?php $relatedPosts = post_related($post); ?>
<?php if ($relatedPosts): ?>
<aside class="backlinks related-posts">
  <h2>Ещё по теме</h2>
  <ul>
    <?php foreach ($relatedPosts as $rp): ?>
    <li><a href="<?= e(post_url($rp)) ?>"><?= e($rp['title']) ?></a> <span class="muted">· <?= e(ru_date(post_pub_ts($rp))) ?></span></li>
    <?php endforeach; ?>
  </ul>
</aside>
<?php endif; ?>
<?php if ($older || $newer): ?>
<nav class="post-nav" aria-label="Другие заметки">
  <?php  ?>
  <?php if ($older): ?><a class="pn pn-prev" href="<?= e(post_url($older)) ?>"><span>← Предыдущая</span><?= e($older['title']) ?></a><?php else: ?><span></span><?php endif; ?>
  <?php if ($newer): ?><a class="pn pn-next" href="<?= e(post_url($newer)) ?>"><span>Следующая →</span><?= e($newer['title']) ?></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
<p class="post-back"><a href="<?= e($secBase) ?>">← Все заметки: <?= e($secTitle) ?></a></p>
