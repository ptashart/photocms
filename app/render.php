<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function icon(string $name, int $size = 18): string
{
    static $paths = [
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'telegram' => '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>',
        'mail' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="m22 6-10 7L2 6"/>',
        'whatsapp' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
        'instagram' => '<rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><path d="M17.5 6.5h.01"/>',
        'youtube' => '<path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><path d="m9.75 15.02 5.75-3.27-5.75-3.27z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'arrow-up' => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
        'prev' => '<path d="m15 18-6-6 6-6"/>',
        'next' => '<path d="m9 18 6-6-6-6"/>',
        'close' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

function button_icons(): array
{
    return ['' => 'Без иконки', 'phone' => 'Телефон', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'mail' => 'Почта', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'link' => 'Ссылка'];
}




function img_html(array $img, string $sizes, string $alt = '', bool $eager = false, int $maxW = 2520, bool $highPriority = false): string
{
    $alt = $alt !== '' ? $alt : (string)$img['alt'];
    $widths = image_widths($img, $maxW);
    $src = image_url($img, image_pick_width($widths, 1240), 'jpg');

    $html = '<picture>' . picture_sources_html($img, $sizes, $widths);



    $focus = image_focus_style($img);
    $html .= '<img src="' . e($src) . '" srcset="' . e(image_srcset($img, 'jpg', $widths)) . '" sizes="' . e($sizes) . '"'
        . ' width="' . (int)$img['w'] . '" height="' . (int)$img['h'] . '" alt="' . e($alt) . '"'
        . ($focus !== '' ? ' style="' . $focus . '"' : '')
        . ($eager ? ($highPriority ? ' fetchpriority="high"' : '') : ' loading="lazy"') . ' decoding="async">';


    return $html . '</picture>';
}

function theme_is_apple_family(): bool
{
    return (string)setting('theme') === 'apple';
}



function content_width_expr(): string
{
    if (theme_is_apple_family()) {
        return 'min(1124px, 100vw - 56px)';
    }
    $sb = max(160, min(360, (int)setting('sidebar_width')));
    return 'calc(100vw - ' . $sb . 'px)';
}









function gallery_sizes(string $layout, int $cols, int $colsM, ?array $img = null): string
{


    $colsD = max(1, min(8, $cols));
    $colsMc = max(1, min(3, $colsM));
    if ($layout === 'slides') {

        return '100vw';
    }
    if ($layout === 'strip') {
        if (!$img) {
            return '100vw';
        }


        $r = max(1, (int)($img['w'] ?? 0)) / max(1, (int)($img['h'] ?? 0));
        $n = static fn(float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
        return '(max-width: 700px) min(' . $n(56 * $r) . 'vh, ' . $n(460 * $r) . 'px)'
            . ', clamp(' . $n(300 * $r) . 'px, ' . $n(60 * $r) . 'vh, ' . $n(640 * $r) . 'px)';
    }
    if ($layout === 'lineup') {
        return '(max-width: 900px) 100vw, ' . content_width_expr();
    }

    return '(max-width: 700px) ' . (int)round(100 / $colsMc) . 'vw'
        . ', (max-width: 900px) ' . (int)round(100 / $colsD) . 'vw'
        . ', calc((' . content_width_expr() . ') / ' . $colsD . ')';
}

function safe_color(string $c, string $fallback = '#cccccc'): string
{
    return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $c) ? $c : $fallback;
}


function rgba_css(string $c, float $alpha, string $fallback): string
{
    if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})/', safe_color($c, ''), $m)) {
        return 'rgba(' . hexdec($m[1]) . ',' . hexdec($m[2]) . ',' . hexdec($m[3]) . ',' . $alpha . ')';
    }
    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', safe_color($c, ''), $m)) {
        return 'rgba(' . hexdec($m[1] . $m[1]) . ',' . hexdec($m[2] . $m[2]) . ',' . hexdec($m[3] . $m[3]) . ',' . $alpha . ')';
    }
    return $fallback;
}





function fx_stamp_text(?string $set = null): string
{
    static $v = '';
    if ($set !== null) {
        $v = $set;
    }
    return $v;
}





function photo_html(array $img, int $index, string $sizes, bool $lightbox, string $fallbackAlt, bool $eager = false, bool $highPriority = false, string $caption = '', ?array $link = null): string
{
    $alt = trim((string)$img['alt']) !== '' ? (string)$img['alt'] : trim($fallbackAlt . ' — фото ' . ($index + 1));


    $style = '--ar:' . max(1, (int)$img['w']) . ' / ' . max(1, (int)$img['h'])
        . ';--r:' . round(max(1, (int)$img['w']) / max(1, (int)$img['h']), 4)
        . ';--c:' . safe_color((string)$img['color']);

    if (!empty($img['id']) && setting('fx_tape') === '1') {
        $style .= ';' . fx_vars((int)$img['id']);
    }




    $stamp = '';
    if (setting('fx_datestamp') === '1') {
        $ts = image_shot_at($img);
        if ($ts > 0) {
            $fmt = (string)setting('fx_datestamp_format');
            $text = match ($fmt) {
                'dmy_dots' => date('d.m.y', $ts),
                'dmy_full' => date('d.m.Y', $ts),
                'ymd'      => date('y m d', $ts),
                default    => date('d m y', $ts),
            };
            $stamp = '<span class="ph-date" aria-hidden="true">' . e($text) . '</span>';
        }
    }

    $customStamp = fx_stamp_text();
    if ($customStamp !== '' && ($index === 0 || setting('fx_stamp_once') !== '1')) {
        $stamp = '<span class="ph-date" aria-hidden="true">' . e($customStamp) . '</span>';
    }
    $pic = img_html($img, $sizes, $alt, $eager, 2520, $highPriority);
    if ($link !== null && ($link['url'] ?? '') !== '') {
        $ext = !empty($link['new_tab']) ? ' target="_blank" rel="noopener"' : '';
        return '<a class="ph ph-link" href="' . e((string)$link['url']) . '"' . $ext . ' style="' . $style . '">' . $pic . $stamp . '</a>';
    }
    if ($lightbox) {



        return '<a class="ph js-lb" data-i="' . $index . '" href="' . e(image_large_url($img)) . '"'
            . ' data-srcset="' . e(image_srcset($img, 'jpg', image_widths($img))) . '" data-alt="' . e($alt) . '"'
            . (trim($caption) !== '' ? ' data-caption="' . e(trim($caption)) . '"' : '')
            . ' style="' . $style . '">' . $pic . $stamp . '</a>';
    }
    return '<div class="ph" data-i="' . $index . '" style="' . $style . '">' . $pic . $stamp . '</div>';
}




function photo_size_presets(): array
{
    return [
        'wide' => 'Во всю ширину',
        'medium' => 'Среднее, по центру',
        'small' => 'Компактное, по центру',
        'left' => 'Слева, текст обтекает',
        'right' => 'Справа, текст обтекает',
    ];
}


function photo_size_width(string $size, int $base): int
{
    return match ($size) {
        'medium' => max(280, (int)round($base * 0.72)),
        'small' => max(280, (int)round($base * 0.5)),
        'left', 'right' => max(280, (int)round($base * 0.45)),
        default => $base,
    };
}


function lb_opt($v): ?bool
{
    return $v === '' || $v === null ? null : (string)$v === '1';
}






function single_photo_html(array $img, string $size, ?bool $lb, string $fallbackAlt, int $base = 700, string $caption = '', bool $eager = false, bool $highPriority = false, ?array $link = null): string
{
    if (!array_key_exists($size, photo_size_presets())) {
        $size = 'wide';
    }
    if ($link !== null && trim((string)($link['url'] ?? '')) === '') {
        $link = null;
    }
    $lb = $link !== null ? false : ($lb ?? (setting('lightbox') === '1'));
    $w = photo_size_width($size, $base);
    $sizes = '(max-width: 700px) 100vw, ' . $w . 'px';
    $html = photo_html($img, 0, $sizes, $lb, $fallbackAlt, $eager, $highPriority, $caption, $link);
    $cap = trim($caption);
    if ($cap !== '') {
        $html .= '<figcaption>' . e($cap) . '</figcaption>';
    }
    return '<figure class="pp pp--' . $size . ' js-gallery">' . $html . "</figure>\n";
}


function masonry_html(array $items, int $cols, int $colsMobile, string $fallbackAlt, bool $lightbox, bool $firstSection = false): string
{
    $cols = max(1, min(6, $cols));
    $colsMobile = max(1, min(3, $colsMobile));
    $heights = array_fill(0, $cols, 0.0);
    $columns = array_fill(0, $cols, '');


    $sizes = gallery_sizes('masonry', $cols, $colsMobile);




    foreach ($items as $i => $img) {
        $ar = (int)$img['w'] > 0 ? (int)$img['h'] / (int)$img['w'] : 1.0;
        $k = (int)array_search(min($heights), $heights, true);
        $heights[$k] += $ar;


        $columns[$k] .= photo_html($img, $i, $sizes, $lightbox, $fallbackAlt, $firstSection && $i < $cols, $firstSection && $i === 0);
    }
    $html = '<div class="masonry js-gallery" data-cols="' . $cols . '" data-cols-m="' . $colsMobile . '">';
    foreach ($columns as $c) {
        $html .= '<div class="mcol">' . $c . '</div>';
    }
    return $html . '</div>';
}



function hung_item_span(int $idx, array $img): array
{
    if ($idx % 5 === 4) {
        return ['S', 2];
    }
    return (int)$img['w'] >= (int)$img['h'] ? ['L', 5] : ['M', 3];
}



function hung_item_sizes(string $size, int $span): string
{
    return '(max-width: 700px) ' . ($size === 'L' ? '100vw' : '50vw')
        . ', (max-width: 900px) ' . (int)round($span / 12 * 100) . 'vw'
        . ', calc((' . content_width_expr() . ') * ' . number_format($span / 12, 4, '.', '') . ')';
}




function hung_html(array $items, string $fallbackAlt, bool $lightbox): string
{
    $rows = [];
    $cur = [];
    $used = 0;
    $idx = 0;
    foreach ($items as $img) {
        [$size, $span] = hung_item_span($idx++, $img);
        $gap = $cur === [] ? 0 : abs(crc32((int)$img['id'] . ':gap')) % 3;
        if ($used + $gap + $span > 12) {
            $rows[] = $cur;
            $cur = [];
            $used = 0;
            $gap = 0;
        }
        $start = $used + $gap + 1;
        $cur[] = ['img' => $img, 'size' => $size, 'span' => $span, 'start' => $start];
        $used = $start + $span - 1;
    }
    if ($cur) {
        $rows[] = $cur;
    }
    $html = '';
    $i = 0;
    $total = count($items);
    foreach ($rows as $row) {
        $html .= '<div class="hung-row">';
        foreach ($row as $it) {
            $img = $it['img'];
            $dy = abs(crc32((int)$img['id'] . ':dy')) % 3;
            $html .= '<figure class="hung-item hung-' . $it['size'] . '" style="grid-column:' . $it['start'] . ' / span ' . $it['span'] . ';--dy:' . $dy . '">'
                . photo_html($img, $i, hung_item_sizes($it['size'], $it['span']), $lightbox, $fallbackAlt, $i < 2, $i === 0)
                . '<figcaption class="hung-num">' . sprintf('%02d', $i + 1) . ' / ' . $total . '</figcaption>'
                . '</figure>';
            $i++;
        }
        $html .= '</div>';
    }
    return '<div class="hung js-gallery">' . $html . '</div>';
}


function fx_lofi_level(): string
{
    $v = (string)setting('fx_lofi');
    if ($v === '1') { $v = 'soft'; }
    return in_array($v, ['soft', 'print'], true) ? $v : 'off';
}


function fx_grain_mode(): string
{
    $v = (string)setting('fx_grain');
    if ($v === '1') { $v = 'paper'; }
    return in_array($v, ['paper', 'film'], true) ? 'paper' : 'off';
}


function fx_vars(int $id): string
{
    $n = static fn(int $k): float => (abs(crc32($id . ':' . $k)) % 10000) / 10000;
    $tilt = 0.0;
    $shift = 0;
    switch ((string)setting('fx_fields')) {
        case 'even': $lo = 8; $hi = 8; $blo = 32; $bhi = 32; break;
        case 'wild': $lo = 5; $hi = 18; $blo = 26; $bhi = 44; break;
        default:     $lo = 7; $hi = 12; $blo = 30; $bhi = 36;
    }


    $pick = static fn(int $k, array $set) => $set[(int)floor($n($k) * count($set))];
    $f = static fn($v): string => number_format((float)$v, 1, '.', '');
    $mid = static fn(float $a, float $b): float => ($a + $b) / 2;
    return '--jr:' . number_format((float)$pick(1, [-$tilt, 0.0, $tilt * 0.6]), 2, '.', '') . 'deg'
        . ';--mt:' . (int)$pick(2, [0, (int)round($shift / 2), $shift]) . 'px'
        . ';--pt:' . $f($pick(3, [$lo, $mid($lo, $hi), $hi])) . 'px'
        . ';--pr:' . $f($pick(4, [$lo, $mid($lo, $hi), $hi])) . 'px'
        . ';--pl:' . $f($pick(5, [$lo, $mid($lo, $hi), $hi])) . 'px'
        . ';--pb:' . $f($pick(6, [$blo, $mid($blo, $bhi), $bhi])) . 'px'
        . ';--tape:' . ($n(7) < .22 ? 1 : 0)
        . ';--leak:' . ($n(8) < .20 ? 1 : 0);
}

function listing_html(array $pages, string $format, int $cols, bool $firstSection = false): string
{
    $cols = max(1, min(5, $cols));
    $fixed = ['portrait' => '2 / 3', 'landscape' => '3 / 2', 'square' => '1 / 1'];

    $covers = pages_covers($pages);

    $counts = pages_photo_counts(array_map(static fn($p) => (int)$p['id'], $pages));
    $fxOn = setting('fx_tape') === '1';
    $html = '<div class="listing" style="--cols:' . $cols . '">';
    foreach ($pages as $i => $p) {
        $pid = (int)$p['id'];
        $cover = $covers[$pid] ?? null;
        $ar = $fixed[$format] ?? null;
        if ($cover && $ar === null) {
            $ar = max(1, (int)$cover['w']) . ' / ' . max(1, (int)$cover['h']);
        }

        $eager = $firstSection && $i < $cols + 1;
        $highPriority = $firstSection && $i === 0;



        $fx = $fxOn ? ' style="' . fx_vars($pid) . '"' : '';
        $html .= '<a class="li' . ($cover ? '' : ' li-empty') . '" href="' . e(page_url($p)) . '"' . $fx . '>';
        $html .= '<span class="li-media" style="--ar:' . ($ar ?? '2 / 3') . ';--c:' . ($cover ? safe_color((string)$cover['color']) : '#ddd') . '">';
        if ($cover) {


            $sizes = '(max-width: 480px) 100vw, (max-width: 900px) 50vw'
                . ', calc((' . content_width_expr() . ') / ' . $cols . ')';
            $html .= img_html($cover, $sizes, (string)$p['title'], $eager, 1240, $highPriority);
        }

        $badge = trim((string)($p['badge'] ?? ''));
        if ($badge !== '') {
            $html .= '<span class="li-badge">' . e($badge) . '</span>';
        }

        if (($p['type'] ?? '') === 'album' && !empty($counts[$pid])) {
            $html .= '<span class="li-count">' . $counts[$pid] . ' фото</span>';
        }
        $html .= '<span class="li-title">' . e($p['title']);
        $sub = trim((string)($p['short_text'] ?? ''));
        if ($sub !== '') {
            $html .= '<span class="li-sub">' . e(clip($sub, 140)) . '</span>';
        }
        $html .= '</span></span></a>';
    }
    return $html . '</div>';
}

function album_related_pages(array $page): array
{
    if (!(int)$page['album_related'] || empty($page['parent_id'])) {
        return [];
    }
    $limit = max(2, min(6, (int)setting('related_limit')));
    $myPos = (int)($page['position'] ?? 0);
    $sib = [];
    foreach (child_pages((int)$page['parent_id'], true) as $s) {
        if ((int)$s['id'] !== (int)$page['id']) {
            $sib[] = $s;
        }
    }

    usort($sib, static fn($a, $b) => (abs((int)$a['position'] - $myPos) <=> abs((int)$b['position'] - $myPos)) ?: ((int)$a['id'] <=> (int)$b['id']));
    $related = array_slice($sib, 0, $limit);
    usort($related, static fn($a, $b) => ((int)$a['position'] <=> (int)$b['position']) ?: ((int)$a['id'] <=> (int)$b['id']));
    return $related;
}






function related_html(array $pages): string
{
    if (!$pages) {
        return '';
    }
    $covers = pages_covers($pages);
    $counts = pages_photo_counts(array_map(static fn($p) => (int)$p['id'], $pages));
    $html = '<div class="rel-grid">';
    foreach ($pages as $p) {
        $pid = (int)$p['id'];
        $cover = $covers[$pid] ?? null;


        $html .= '<a class="rel" href="' . e(page_url($p)) . '">';
        $html .= '<span class="rel-media" style="--c:' . ($cover ? safe_color((string)$cover['color']) : '#ddd') . '">';
        if ($cover) {
            $html .= img_html($cover, '(max-width: 620px) 44vw, 300px', (string)$p['title'], false, 600);
        }
        $badge = trim((string)($p['badge'] ?? ''));
        if ($badge !== '') {
            $html .= '<span class="li-badge">' . e($badge) . '</span>';
        }
        $html .= '</span>';
        $html .= '<span class="rel-title">' . e($p['title']) . '</span>';
        if (($p['type'] ?? '') === 'album' && !empty($counts[$pid])) {
            $html .= '<span class="rel-meta">' . $counts[$pid] . ' фото</span>';
        }
        $html .= '</a>';
    }
    return $html . '</div>';
}



function share_html(string $url, string $title): string
{
    $u = rawurlencode(abs_url($url));
    $t = rawurlencode($title);
    return '<div class="share"><span class="share-l">Поделиться:</span>'
        . '<a class="share-b" href="https://t.me/share/url?url=' . $u . '&text=' . $t . '" target="_blank" rel="noopener">Telegram</a>'
        . '<a class="share-b" href="https://vk.com/share.php?url=' . $u . '" target="_blank" rel="noopener">ВКонтакте</a>'
        . '<a class="share-b" href="https://wa.me/?text=' . $t . '%20' . $u . '" target="_blank" rel="noopener">WhatsApp</a>'
        . '<button class="share-b" type="button" data-copy="' . e(abs_url($url)) . '">Копировать ссылку</button>'
        . '</div>';
}


function page_crumbs_html(array $page, string $class = ''): string
{


    if (!empty($page['is_home'])) {
        return '';
    }
    $crumbs = page_breadcrumbs($page);
    if (count($crumbs) < 2) {
        return '';
    }
    $last = count($crumbs) - 1;
    $h = '<nav class="crumbs' . ($class !== '' ? ' ' . e($class) : '') . '" aria-label="Хлебные крошки">';
    foreach ($crumbs as $i => $c) {
        if ($i > 0) {
            $h .= ' <span aria-hidden="true">/</span> ';
        }
        $h .= $i === $last
            ? '<span aria-current="page">' . e($c['name']) . '</span>'
            : '<a href="' . e($c['url']) . '">' . e($c['name']) . '</a>';
    }
    return $h . "</nav>\n";
}

function sec_classes(array $sec, string $extra): string
{
    $sp = (string)($sec['data']['space'] ?? 'md');
    if (!in_array($sp, ['none', 'sm', 'md', 'lg'], true)) {
        $sp = 'md';
    }
    return 'sec ' . $extra . ' sp-' . $sp;
}

function render_section(array $sec, array $page): string
{
    $file = ROOT . '/templates/sections/' . preg_replace('/[^a-z]/', '', (string)$sec['type']) . '.php';
    if (!is_file($file)) {
        return '';
    }
    $d = $sec['data'];
    ob_start();
    include $file;
    return (string)ob_get_clean();
}

function menu_html(array $nodes, int $level = 0): string
{
    $html = '<ul class="menu lvl-' . $level . '">';
    foreach ($nodes as $n) {
        $cls = [];
        if (!empty($n['active'])) {
            $cls[] = 'is-active';
        }
        if (!empty($n['children'])) {
            $cls[] = 'has-sub';
        }
        $html .= '<li' . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . '>';
        if ($n['href'] !== null) {
            $ext = $n['new_tab'] ? ' target="_blank" rel="noopener"' : '';
            $cur = !empty($n['active']) ? (!empty($n['exact']) ? ' aria-current="page"' : ' aria-current="true"') : '';
            $html .= '<a href="' . e($n['href']) . '"' . $ext . $cur . '>' . e($n['title']) . '</a>';
        } else {


            $tab = theme_is_apple_family() ? ' tabindex="0"' : '';
            $html .= '<span class="cat"' . $tab . '>' . e($n['title']) . '</span>';
        }
        if (!empty($n['children'])) {
            $html .= menu_html($n['children'], $level + 1);
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
}

function font_stack(string $name, string $generic): string
{
    $name = trim((string)preg_replace('/[^A-Za-z0-9 \-]/', '', $name));
    return $name !== '' ? '"' . $name . '", ' . ($generic === 'serif' ? 'Georgia, serif' : 'system-ui, -apple-system, "Segoe UI", sans-serif') : $generic;
}

function theme_css(): string
{






    $apple = theme_is_apple_family();
    $text = safe_color(setting('color_text'), $apple ? '#1d1d1f' : '#252525');
    if ($apple && strcasecmp($text, '#252525') === 0) {
        $text = '#1d1d1f';
    }
    $fontHead = setting('font_heading');
    $fontBody = setting('font_body');
    if ($apple) {


        if (strcasecmp($fontBody, 'Golos Text') === 0) {
            $fontBody = '';
        }
    }
    $appleStack = '-apple-system, BlinkMacSystemFont, "SF Pro Display", "Segoe UI", system-ui, sans-serif';


    $headStack = $fontHead !== '' ? font_stack($fontHead, 'sans-serif') : '"Tenor Sans", "Helvetica Neue", Arial, sans-serif';
    $bodyStack = $fontBody !== '' ? font_stack($fontBody, 'sans-serif') : ($apple ? $appleStack : '"Golos Text", system-ui, sans-serif');
    return ':root{'
        . '--bg:' . safe_color(setting('color_bg'), '#ffffff') . ';'
        . '--text:' . $text . ';'




        . '--muted:' . rgba_css($text, 0.75, 'rgba(37,37,37,.75)') . ';'
        . '--line:' . rgba_css($text, 0.18, 'rgba(37,37,37,.18)') . ';'
        . '--font-head:' . $headStack . ';'
        . '--font-body:' . $bodyStack . ';'






        . '--h-scale:' . (max(50, min(150, (int)setting('font_scale_heading'))) / 100) . ';'
        . '--t-scale:' . (max(50, min(150, (int)setting('font_scale_body'))) / 100) . ';'
        . '--sidebar:' . max(160, min(360, (int)setting('sidebar_width'))) . 'px;'

        . '--fx-pencil:' . (['red' => '#c4402a', 'sepia' => '#8a5a2b', 'graphite' => '#3c3c3c', 'blue' => '#2b4f9e'][(string)setting('fx_pencil_color')] ?? '#c4402a') . ';'
        . '--gap:' . max(0, min(40, (int)setting('gallery_gap'))) . 'px}'
        . '@media (max-width:900px){:root{'
        . '--h-scale:' . (max(50, min(150, (int)setting('font_scale_heading_mobile'))) / 100) . ';'
        . '--t-scale:' . (max(50, min(150, (int)setting('font_scale_body_mobile'))) / 100) . '}}';
}

function asset(string $path): string
{
    $f = ROOT . '/' . ltrim($path, '/');
    return '/' . ltrim($path, '/') . (is_file($f) ? '?v=' . filemtime($f) : '');
}

function section_display_image(array $sec, array $page): ?array
{
    $d = $sec['data'];
    if ($sec['type'] === 'photo') {
        return (int)($d['image_id'] ?? 0) > 0 ? image_get((int)$d['image_id']) : null;
    }
    if ($sec['type'] === 'gallery') {
        return gallery_images('section', (int)$sec['id'])[0] ?? null;
    }
    if ($sec['type'] === 'listing') {
        $first = listing_pages($d, $page)[0] ?? null;
        return $first ? (pages_covers([$first])[(int)$first['id']] ?? null) : null;
    }
    return null;
}


function sections_first_media(array $sections, array $page): ?array
{
    static $cache = [];
    if (!$sections) {
        return null;
    }
    $key = (string)($sections[0]['owner_type'] ?? 'page') . ':' . (int)($sections[0]['page_id'] ?? 0);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    foreach ($sections as $sec) {
        if (!in_array($sec['type'], ['photo', 'gallery', 'listing'], true)) {
            continue;
        }
        $img = section_display_image($sec, $page);
        if ($img) {
            return $cache[$key] = ['section' => $sec, 'image' => $img];
        }
    }
    return $cache[$key] = null;
}

function page_preload_image(array $img, array $widths, int $width, string $sizes): array
{
    $canWebp = img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported();
    $format = avif_enabled() ? 'avif' : ($canWebp ? 'webp' : 'jpg');
    $out = [
        'src' => image_url($img, image_pick_width($widths, $width), $format),
        'srcset' => image_srcset($img, $format, $widths),
        'sizes' => $sizes,
    ];
    if ($format !== 'jpg') {
        $out['type'] = 'image/' . $format;
    }
    return $out;
}


function page_first_media_preload(array $page): ?array
{
    $found = sections_first_media(page_sections((int)$page['id']), $page);
    if (!$found) {
        return null;
    }
    $sec = $found['section'];
    $d = $sec['data'];
    $cw = content_width_expr();
    if ($sec['type'] === 'photo') {
        $img = $found['image'];
        $size = array_key_exists((string)($d['size'] ?? ''), photo_size_presets()) ? (string)$d['size'] : 'wide';
        $w = photo_size_width($size, 1100);
        return page_preload_image($img, image_widths($img), $w, '(max-width: 700px) 100vw, ' . $w . 'px');
    }
    if ($sec['type'] === 'gallery') {
        $img = $found['image'];
        $cols = (int)($d['columns'] ?? 0) > 0 ? (int)$d['columns'] : (int)setting('gallery_columns');
        $layout = in_array($d['layout'] ?? '', ['masonry', 'grid', 'lineup', 'strip', 'slides', 'contactsheet'], true) ? $d['layout'] : 'masonry';
        $colsM = (int)setting('gallery_columns_mobile');
        if ($layout === 'contactsheet') {
            $cols = max(3, min(8, $cols + 2));
            $colsM = max(2, min(3, $colsM + 1));
            $layout = 'grid';
        }
        return page_preload_image($img, image_widths($img), 1240, gallery_sizes($layout, $cols, $colsM, $img));
    }
    if ($sec['type'] === 'listing') {
        $cover = $found['image'];
        $cols = max(1, min(5, (int)($d['columns'] ?? 3)));
        return page_preload_image($cover, image_widths($cover, 1240), 1240,
            '(max-width: 480px) 100vw, (max-width: 900px) 50vw, calc((' . $cw . ') / ' . $cols . ')');
    }
    return null;
}

function render_page(array $page): string
{
    $template = $page['type'] === 'album' ? 'album' : 'page';
    $meta = page_meta($page);
    content_lastmod((int)$page['updated_at']);




    if (!empty($page['is_home'])) {
        content_lastmod((int)db_val('SELECT COALESCE(MAX(updated_at), 0) FROM pages WHERE is_published = 1'));
        content_lastmod((int)db_val('SELECT COALESCE(MAX(updated_at), 0) FROM posts WHERE is_published = 1'));
    } else {
        foreach (page_sections((int)$page['id']) as $s) {
            if ($s['type'] !== 'listing') {
                continue;
            }
            foreach (listing_pages($s['data'], $page) as $lp) {
                content_lastmod((int)$lp['updated_at']);
            }
        }
        if ($page['type'] === 'album') {
            foreach (album_related_pages($page) as $rp) {
                content_lastmod((int)$rp['updated_at']);
            }
        }
    }


    if ($page['type'] === 'album') {
        $first = gallery_images('page', (int)$page['id'])[0] ?? null;
        if ($first) {
            $widths = image_widths($first);



            $cols = (int)$page['album_columns'] > 0 ? (int)$page['album_columns'] : (int)setting('gallery_columns');
            $colsM = (int)setting('gallery_columns_mobile');



            $albumLayout = in_array($page['album_layout'] ?? '', ['masonry', 'grid', 'lineup', 'contactsheet', 'hung'], true) ? (string)$page['album_layout'] : 'masonry';
            if ($albumLayout === 'hung') {

                [$hs, $hspan] = hung_item_span(0, $first);
                $albumSizes = hung_item_sizes($hs, $hspan);
            } elseif ($albumLayout === 'contactsheet') {
                $albumSizes = gallery_sizes('grid', max(3, min(8, $cols + 2)), max(2, min(3, $colsM + 1)));
            } else {
                $albumSizes = gallery_sizes($albumLayout, $cols, $colsM);
            }
            $meta['preload_image'] = page_preload_image($first, $widths, 1240, $albumSizes);
        }
    } else {

        $pm = page_first_media_preload($page);
        if ($pm) {
            $meta['preload_image'] = $pm;
        }
    }
    ob_start();
    include ROOT . '/templates/' . $template . '.php';
    $content = (string)ob_get_clean();
    return render_layout($meta, $content, $page);
}


function serve_not_found(string $path): void
{
    $rd = db_one('SELECT to_url, code FROM redirects WHERE from_path = ?', [$path]);
    if ($rd) {
        redirect((string)$rd['to_url'], (int)$rd['code'] === 302 ? 302 : 301);
    }
    http_response_code(404);
    header('X-Robots-Tag: noindex');
    echo public_csp_send(render_404());
    exit;
}

function render_404(): string
{
    $page = ['id' => 0, 'type' => 'page', 'title' => 'Страница не найдена', 'slug' => '404', 'is_home' => 0, 'is_published' => 1];
    ob_start();
    include ROOT . '/templates/404.php';
    $content = (string)ob_get_clean();
    $meta = [
        'title' => 'Страница не найдена | ' . setting('site_name'),
        'description' => '',
        'canonical' => '',
        'noindex' => true,
        'og_type' => 'website',
        'og_image' => null,
        'jsonld' => [],
    ];
    return render_layout($meta, $content, $page);
}

function render_layout(array $meta, string $content, array $page): string
{
    $menu = menu_tree(request_path());
    $bodyClass = 'type-' . ($page['type'] ?? 'page') . (!empty($page['is_home']) ? ' is-home' : '');


    if (theme_is_apple_family()) {
        $bodyClass .= ' theme-apple';
    }

    if (setting('photo_corners') === 'square') {
        $bodyClass .= ' corners-square';
    }


    if (setting('fx_tape') === '1') {
        $bodyClass .= ' fx-tape';
    }


    if (setting('fx_datestamp') === '1' || trim((string)($page['stamp_text'] ?? '')) !== '') {
        $bodyClass .= ' fx-ds';
        $dsp = (string)setting('fx_datestamp_pos');
        if (in_array($dsp, ['lb', 'rt', 'lt'], true)) { $bodyClass .= ' fx-ds-' . $dsp; }
        $dss = (string)setting('fx_datestamp_size');
        if ($dss === 'small' || $dss === 'large') { $bodyClass .= ' fx-ds-' . $dss; }
        $dsc = (string)setting('fx_datestamp_color');
        if (in_array($dsc, ['red', 'yellow', 'white'], true)) { $bodyClass .= ' fx-ds-' . $dsc; }
    }
    ob_start();
    include ROOT . '/templates/layout.php';
    return (string)ob_get_clean();
}
