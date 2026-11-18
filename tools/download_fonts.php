<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);







if (PHP_SAPI !== 'cli') {
    exit('Только из командной строки.');
}
$specs = array_slice($argv, 1) ?: ['Tenor Sans:400', 'Golos Text:400,500,600'];
$dir = dirname(__DIR__) . '/assets/fonts';
@mkdir($dir, 0755, true);

function fetch(string $url): ?string
{
    $ctx = stream_context_create(['http' => [
        'timeout' => 60,

        'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36\r\n",
    ]]);
    $b = @file_get_contents($url, false, $ctx);
    return $b === false ? null : $b;
}




$errors = 0;
$blocks = [];
$existing = is_file("$dir/fonts.css") ? (string)file_get_contents("$dir/fonts.css") : '';
if ($existing !== '' && preg_match_all('~/\*\s*([a-z\-]+)\s+(\d+)\s*\*/\s*(@font-face\s*\{.*?\})~s', $existing, $old, PREG_SET_ORDER)) {
    foreach ($old as $x) {
        if (preg_match('~font-family:\s*[\'"]?([^\'";]+)[\'"]?\s*;~', $x[3], $fm)) {
            $blocks[strtolower(trim($fm[1])) . '|' . $x[2] . '|' . $x[1]] = "/* {$x[1]} {$x[2]} */\n{$x[3]}\n";
        }
    }
}
foreach ($specs as $spec) {
    [$family, $weights] = array_pad(explode(':', $spec, 2), 2, '400');
    $url = 'https://fonts.googleapis.com/css2?family=' . str_replace(' ', '+', $family) . ':wght@' . str_replace(',', ';', $weights) . '&display=swap&subset=cyrillic,latin';
    $src = fetch($url);
    if ($src === null) {
        $errors++;
        fwrite(STDERR, "Не удалось получить CSS для $family (нет интернета или неверное имя).\n");
        continue;
    }



    preg_match_all('~/\*\s*([a-z\-]+)\s*\*/\s*(@font-face\s*\{.*?\})~s', $src, $m, PREG_SET_ORDER);
    foreach ($m as $b) {
        $subset = $b[1];
        if (!in_array($subset, ['cyrillic', 'latin', 'cyrillic-ext', 'latin-ext'], true)) {
            continue;
        }
        $block = $b[2];
        if (!preg_match('~url\((https://[^)]+\.woff2)\)~', $block, $um)) {
            continue;
        }
        preg_match('~font-weight:\s*(\d+)~', $block, $wm);
        $weight = $wm[1] ?? '400';
        $file = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $family)) . "-$weight-$subset.woff2";
        $bin = fetch($um[1]);
        if ($bin === null) {
            $errors++;
            fwrite(STDERR, "Не скачался $file\n");
            continue;
        }
        $written = @file_put_contents("$dir/$file", $bin);
        if ($written === false || $written !== strlen($bin)) {
            $errors++;
            fwrite(STDERR, "Не удалось записать $file\n");
            continue;
        }
        $block = str_replace($um[1], "/assets/fonts/$file", $block);
        $blocks[strtolower(trim($family)) . '|' . $weight . '|' . $subset] = "/* $subset $weight */\n$block\n";
        echo "✓ $file\n";
    }
}
$css = "/* локальные шрифты — OFL, fonts.google.com. Обновляется tools/download_fonts.php: добавляет и заменяет блоки, остальные не удаляет */\n"
    . implode('', $blocks);
$written = @file_put_contents("$dir/fonts.css", $css);
if ($written === false || $written !== strlen($css)) {
    fwrite(STDERR, "Не удалось записать assets/fonts/fonts.css\n");
    exit(1);
}
if ($errors > 0) {
    fwrite(STDERR, "Завершено с ошибками: $errors.\n");
    exit(1);
}
echo "Готово: assets/fonts/fonts.css. Названия шрифтов укажите в админке: Настройки → Оформление.\n";
