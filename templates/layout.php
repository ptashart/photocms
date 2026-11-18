<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */

$favId = (int)setting('favicon_image_id');
$fav = $favId ? image_get($favId) : null;
$socials = social_links();
?>
<!DOCTYPE html>
<html lang="<?= e(trim(setting('lang')) !== '' ? trim(setting('lang')) : 'ru')  ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e(safe_color(setting('color_bg'), '#ffffff')) ?>">
<?php
 ?>
<meta name="color-scheme" content="light">
<title><?= e($meta['title']) ?></title>
<?php if ($meta['description'] !== ''): ?>
<meta name="description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<?php
 ?>
<meta name="robots" content="<?= e($meta['robots'] ?? ($meta['noindex'] ? 'noindex,follow,max-image-preview:large,max-snippet:-1' : 'index,follow,max-image-preview:large,max-snippet:-1')) ?>">
<?php if ($meta['canonical'] !== ''): ?>
<link rel="canonical" href="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<meta property="og:site_name" content="<?= e(setting('site_name')) ?>">
<meta property="og:type" content="<?= e($meta['og_type']) ?>">
<meta property="og:locale" content="<?= e(setting('locale')) ?>">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<?php if ($meta['description'] !== ''): ?>
<meta property="og:description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<?php if ($meta['canonical'] !== ''): ?>
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<?php if (!empty($meta['og_image'])): $og = $meta['og_image']; ?>
<meta property="og:image" content="<?= e($og['url']) ?>">
<?php
$ogType = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][strtolower(pathinfo((string)parse_url($og['url'], PHP_URL_PATH), PATHINFO_EXTENSION))] ?? ''; ?>
<?php if ($ogType !== ''): ?><meta property="og:image:type" content="<?= $ogType ?>"><?php endif; ?>
<?php if (str_starts_with($og['url'], 'https://')): ?>
<meta property="og:image:secure_url" content="<?= e($og['url']) ?>">
<?php endif; ?>
<meta property="og:image:width" content="<?= (int)$og['w'] ?>">
<meta property="og:image:height" content="<?= (int)$og['h'] ?>">
<meta property="og:image:alt" content="<?= e($og['alt']) ?>">
<meta name="twitter:image" content="<?= e($og['url']) ?>">
<meta name="twitter:image:alt" content="<?= e($og['alt']) ?>">
<?php endif; ?>
<?php  ?>
<meta name="twitter:card" content="<?= !empty($meta['og_image']) ? 'summary_large_image' : 'summary' ?>">
<?php if (trim(setting('twitter_site')) !== ''): ?><meta name="twitter:site" content="@<?= e(ltrim(trim(setting('twitter_site')), '@')) ?>">
<?php endif; ?>
<meta name="twitter:title" content="<?= e($meta['title']) ?>">
<?php if ($meta['canonical'] !== ''): ?><meta name="twitter:url" content="<?= e($meta['canonical']) ?>"><?php endif;  ?>
<?php if ($meta['description'] !== ''): ?>
<meta name="twitter:description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<?php if (!empty($meta['article'])): ?>
<meta property="article:published_time" content="<?= e($meta['article']['published']) ?>">
<meta property="article:modified_time" content="<?= e($meta['article']['modified']) ?>">
<?php endif; ?>
<?php  ?>
<link rel="alternate" type="application/rss+xml" title="<?= e(setting('site_name')) ?>" href="<?= e(abs_url('/feed.xml')) ?>">
<?php if (trim(setting('verify_yandex')) !== ''): ?><meta name="yandex-verification" content="<?= e(setting('verify_yandex')) ?>">
<?php endif; ?>
<?php if (trim(setting('verify_google')) !== ''): ?><meta name="google-site-verification" content="<?= e(setting('verify_google')) ?>">
<?php endif; ?>
<?php if (trim(setting('verify_bing')) !== ''): ?><meta name="msvalidate.01" content="<?= e(setting('verify_bing')) ?>">
<?php endif; ?>
<?php if ($fav): ?>
<link rel="icon" type="image/png" sizes="48x48" href="<?= e(image_url($fav, 48, 'png')) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= e(image_url($fav, 180, 'png')) ?>">
<?php endif; ?>
<?php
if (!empty($meta['preload_image'])): ?>
<link rel="preload" as="image" href="<?= e($meta['preload_image']['src']) ?>" imagesrcset="<?= e($meta['preload_image']['srcset']) ?>" imagesizes="<?= e($meta['preload_image']['sizes']) ?>"<?php if (!empty($meta['preload_image']['type'])): ?> type="<?= e($meta['preload_image']['type']) ?>"<?php endif; ?> fetchpriority="high">
<?php endif; ?>
<?php
if (str_starts_with(setting('lang'), 'ru')):
    foreach ([['font_body', 'Golos Text', 'golos-text-400-cyrillic'], ['font_heading', 'Tenor Sans', 'tenor-sans-400-cyrillic']] as [$fk, $fname, $ffile]):


        if (setting($fk) === $fname && !(theme_is_apple_family() && $fk === 'font_body') && is_file(ROOT . '/assets/fonts/' . $ffile . '.woff2')): ?>
<link rel="preload" href="/assets/fonts/<?= e($ffile) ?>.woff2" as="font" type="font/woff2" crossorigin>
<?php   endif;
    endforeach;
endif; ?>
<?php if (is_file(ROOT . '/assets/fonts/fonts.css')): ?>
<link rel="stylesheet" href="<?= e(asset('assets/fonts/fonts.css')) ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/site.css')) ?>">
<?php

if (theme_is_apple_family()): ?>
<link rel="stylesheet" href="<?= e(asset('assets/css/theme-apple.css')) ?>">
<?php endif; ?>
<style><?= theme_css() ?></style>
<?php foreach ($meta['jsonld'] as $block): ?>
<script type="application/ld+json"><?= json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php endforeach; ?>
<?php if (theme_is_apple_family()): ?>
<style>:root{--logo-height:<?= (int)setting('logo_height') ?>px}</style>
<?php endif; ?>
<?= public_custom_code((string)setting('head_code')) ?>

</head>
<?php

 ?>
<body class="<?= e($bodyClass) ?>"<?= setting('lightbox_download') === '1' ? ' data-lb-dl' : ' data-no-save' ?>>
<a class="skip" href="#main">К содержимому</a>
<div class="layout">
  <aside class="sidebar">
    <header class="sb-bar">
      <?php



      $brandText = trim(setting('menu_brand'));
      $logoImg = (($lid = (int)setting('logo_image_id')) > 0) ? image_get($lid) : null;
      $brandMode = setting('brand_mode');
      $showLogo = $logoImg !== null && in_array($brandMode, ['logo', 'both'], true);
      $showText = $brandText !== '' && in_array($brandMode, ['text', 'both'], true);
      if (!$showLogo && !$showText) {
          $showLogo = $logoImg !== null;
          $showText = !$showLogo && $brandText !== '';
      }
      ?>
      <?php if ($showLogo || $showText): ?>
        <a class="brand<?= $showLogo && $showText ? ' brand-both' : '' ?>" href="/">
          <?php if ($showLogo):



              $lw = image_widths($logoImg, 900);
              $lsrcset = [];
              foreach ($lw as $w) {
                  $lsrcset[] = image_url($logoImg, $w, 'png') . ' ' . $w . 'w';
              }
          ?>
            <img class="brand-logo" src="<?= e(image_url($logoImg, $lw[0], 'png')) ?>" srcset="<?= e(implode(', ', $lsrcset)) ?>" sizes="(max-width: 900px) 180px, 320px" width="<?= (int)$logoImg['w'] ?>" height="<?= (int)$logoImg['h'] ?>" alt="<?= e($brandText !== '' ? $brandText : setting('site_name')) ?>" decoding="async">
          <?php endif; ?>
          <?php if ($showText): ?>
            <span class="brand-text"><?= e($brandText) ?></span>
          <?php endif; ?>
        </a>
      <?php else: ?>
        <span></span>
      <?php endif; ?>
      <button class="burger" type="button" aria-label="Меню" aria-expanded="false" aria-controls="site-nav" data-burger><?= icon('menu', 24) ?></button>
    </header>
    <nav class="nav" id="site-nav" aria-label="Главное меню">
      <?= menu_html($menu) ?>
    </nav>
  </aside>

  <div class="main">
    <main id="main" tabindex="-1">
      <?= $content ?>
    </main>

    <footer class="footer">
      <?php $fcols = footer_columns(); ?>
      <?php if ($fcols): ?>
        <nav class="footer-cols" aria-label="Карта сайта">
          <?php foreach ($fcols as $c): ?>
            <div class="fcol">
              <?php
 ?>
              <div class="fcol-title"><?= $c['title'] !== '' ? e($c['title']) : '&nbsp;' ?></div>
              <?php foreach ($c['links'] as $l): $ext = (bool)preg_match('~^https?://~i', $l['url']); ?>
                <a class="fcol-link" href="<?= e($l['url']) ?>"<?= $ext ? ' target="_blank" rel="noopener"' : '' ?>><?= e($l['text']) ?></a>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <?php







      $footerText = trim(setting('footer_text'));
      $footParts = [];
      $napAddress = implode(', ', array_filter(array_map('trim', [setting('street_address'), setting('city'), setting('postal_code')])));
      if ($napAddress !== '') {
          $footParts[] = e($napAddress);
      }
      if (trim(setting('phone')) !== '') {
          $footParts[] = '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', setting('phone'))) . '">' . e(setting('phone')) . '</a>';
      }
      if (trim(setting('email')) !== '') {
          $footParts[] = '<a href="mailto:' . e(setting('email')) . '">' . e(setting('email')) . '</a>';
      }
      foreach ($socials as $s) {
          $footParts[] = '<a href="' . e($s['url']) . '" target="_blank" rel="noopener me">' . e($s['label']) . '</a>';
      }
      ?>
      <?php if ($footerText !== '' || $footParts): ?>
        <p class="footer-links<?= $footerText !== '' && $footParts ? ' has-copy' : '' ?>">
          <?php if ($footerText !== ''): ?><span class="footer-copy"><?= nl2br(e($footerText)) ?></span><?php endif; ?>
          <?php if ($footParts): ?><span class="footer-rest"><?= implode('<span class="footer-sep" aria-hidden="true">·</span>', $footParts) ?></span><?php endif; ?>
        </p>
      <?php endif; ?>
      <a class="author-credit" href="https://www.ptash.art" target="_blank" rel="noopener nofollow">ptash.art</a>
    </footer>
  </div>
</div>

<button class="to-top" type="button" aria-label="Наверх" data-top hidden><?= icon('arrow-up', 20) ?></button>

<?php if ((!empty($page['id']) || !empty($page['edit_url'])) && is_admin()): ?>
  <?php $editLabel = ($page['type'] ?? '') === 'blog' ? 'К заметкам' : (($page['type'] ?? '') === 'post' ? 'Править заметку' : 'Править страницу'); ?>
  <a class="admin-link" href="<?= e($page['edit_url'] ?? (admin_base() . '?p=page_edit&id=' . (int)$page['id'])) ?>"><?= $editLabel ?><?= empty($page['is_published']) ? ' (черновик)' : '' ?></a>
<?php endif; ?>

<?= public_custom_code((string)setting('body_code')) ?>

<script src="<?= e(asset('assets/js/site.js')) ?>" nonce="__PHOTOCMS_CSP_CORE_NONCE__" defer></script>
</body>
</html>
