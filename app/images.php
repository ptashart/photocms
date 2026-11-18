<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

/** Ширины вариантов. Адрес варианта: /media/{id}/{ширина}/{slug}.{jpg|webp|avif|png} — это файл на вашем домене. */
const IMG_WIDTHS = [48, 180, 280, 440, 600, 900, 1240, 1880, 2520];

/** Движок обработки изображений (1.9.30): Imagick предпочтителен — при нехватке памяти он сам
    уходит в дисковый кэш пикселей, а GD падает (на шаред-хостингах с ~120 МБ на процесс каскадное
    уменьшение 24-Мп оригиналов не влезало в лимит и миниатюры не рождались вовсе). GD — запасной
    путь. Принудительно выбрать движок: 'img_engine' => 'gd' (или 'imagick') в config.php. */
function img_engine(): string
{
    static $e = null;
    if ($e === null) {
        $forced = strtolower(trim((string)cfg('img_engine', '')));
        if (in_array($forced, ['imagick', 'gd'], true)) {
            return $e = $forced;
        }
        $e = (extension_loaded('imagick') && class_exists('Imagick')) ? 'imagick' : 'gd';
    }
    return $e;
}

/** Размер вида «768M»/«1G»/«512» в байтах; 0, -1 или мусор — 0. */
function image_parse_size(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '-1') {
        return 0;
    }
    $n = (int)$raw;
    switch (strtolower(substr($raw, -1))) {
        case 'g':
            $n *= 1073741824;
            break;
        case 'm':
            $n *= 1048576;
            break;
        case 'k':
            $n *= 1024;
            break;
    }
    /* безумные значения вроде «99999999999G» (ошибка админа в ini) переполняют int64 при
       умножении и превращаются во float, а при strict_types это TypeError на return
       (аудит 1.9.48, п.11) — зажимаем в потолок */
    if ($n >= PHP_INT_MAX) {
        return PHP_INT_MAX;
    }
    return $n > 0 ? (int)$n : 0;
}

/** Действующий memory_limit в байтах; 0 — без ограничения. */
function image_memory_limit(): int
{
    return image_parse_size((string)ini_get('memory_limit'));
}

/**
 * Лимит памяти, от которого считается допустимое разрешение фото. По умолчанию равен
 * реальному memory_limit PHP. Владелец может осознанно поднять его в config.php:
 *   'img_memory_limit' => '512M'
 * Автоматически лимит НЕ поднимаем: раньше код молча ставил 768M, и на хостинге со 128–256 МБ
 * допускались фото до ~90 Мп — несколько одновременных генераций съедали гигабайты RAM
 * и роняли PHP-FPM (img.php — публичная точка входа).
 */
function image_memory_limit_work(): int
{
    $cur = image_memory_limit();
    $want = image_parse_size((string)cfg('img_memory_limit', ''));
    return ($want > 0 && ($cur === 0 || $want > $cur)) ? $want : $cur;
}

/** Поднимает memory_limit до рабочего — только если владелец явно задал img_memory_limit в config.php. */
function image_raise_memory(): void
{
    $eff = image_memory_limit_work();
    $cur = image_memory_limit();
    if ($eff > 0 && $cur > 0 && $cur < $eff) {
        @ini_set('memory_limit', (string)$eff);
    }
}

/**
 * Предел разрешения оригинала (пикселей). GD держит картинку в памяти целиком: 4 байта на пиксель,
 * а при повороте по EXIF одновременно живут две копии (≈8 Б/px) + запас на сам PHP и декодер.
 * Поэтому предел считаем от рабочего memory_limit, а не берём константой.
 */
function image_max_pixels(): int
{
    /* 1.9.30: при движке Imagick память почти не ограничивает разрешение — при нехватке RAM
       он сам переходит на дисковый кэш пикселей; оставляем только жёсткий потолок от абсурда */
    if (img_engine() === 'imagick') {
        return IMG_MAX_PIXELS_CAP;
    }
    $lim = image_memory_limit_work();
    if ($lim <= 0) {
        return IMG_MAX_PIXELS_CAP;
    }
    $px = (int)floor(max(0, $lim - 48 * 1048576) / 8);
    return max(4000000, min(IMG_MAX_PIXELS_CAP, $px));
}

/** Можно ли отдавать вариант такой ширины: не плодим копии оригинала под разными URL. */
function image_width_allowed(array $img, int $w): bool
{
    if (!in_array($w, IMG_WIDTHS, true)) {
        return false;
    }
    $ow = (int)$img['w'];
    if ($w <= $ow || in_array($w, [48, 180, 440], true)) { // 48/180 — favicon, 440 — миниатюры админки
        return true;
    }
    foreach (IMG_WIDTHS as $step) { // у мелких фото разрешена ближайшая ступень вверх
        if ($step >= $ow) {
            return $w === $step;
        }
    }
    return false;
}

/** Блокировка на «слот»: одновременно создаётся не больше IMG_LOCK_SLOTS вариантов — защита от лавины декодирований.
 *  $wait = false — слот занят: вернуть false сразу, не вставая в очередь (публичные запросы, висящие в flock,
 *  выкашивают пул PHP-воркеров). false и null для image_unlock() равнозначны. */
function image_lock(int $id, bool $wait = true)
{
    $dir = ROOT . '/storage/locks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $h = @fopen($dir . '/img-' . ($id % IMG_LOCK_SLOTS) . '.lock', 'c');
    if (!$h) {
        return null;
    }
    if (!flock($h, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
        fclose($h);
        return $wait ? null : false;
    }
    return $h;
}

function image_unlock($h): void
{
    if ($h) {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

/**
 * Ждёт свободный слот генерации до $seconds секунд короткими попытками с паузой 200 мс.
 * Возвращает хендл лока; false — слот так и не освободился; null — файл лока недоступен (права на storage/).
 */
function image_lock_wait(int $id, float $seconds)
{
    $deadline = microtime(true) + $seconds;
    do {
        $h = image_lock($id, false);
        if ($h !== false && $h !== null) {
            return $h;
        }
        if ($h === null) { // нет даже файла лока — повторять бессмысленно
            return null;
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    return false;
}

/**
 * Ближайший к $wantW уже сгенерированный вариант фото — запасной ответ, пока слот генерации занят.
 * Сначала ищем в запрошенном формате, потом в jpg (он есть у всех). Варианты мельче 280 px
 * в подмену не годятся: растянутые на весь экран они выглядят хуже, чем отказ.
 */
function image_existing_variant(array $img, string $fmt, int $wantW): ?string
{
    $widths = array_values(array_filter(IMG_WIDTHS, static fn($w) => $w >= 280));
    usort($widths, static fn($a, $b) => abs($a - $wantW) <=> abs($b - $wantW));
    foreach ([$fmt, 'jpg'] as $f) {
        if ($f === 'jpg' && $fmt === 'jpg') {
            continue; // jpg уже проверен первым проходом
        }
        foreach ($widths as $w) {
            $p = image_variant_path($img, $w, $f);
            if (is_file($p)) {
                return $p;
            }
        }
    }
    return null;
}

/* Троттлинг генерации вариантов по IP (с 1.9.13 — в app/images.php: раньше жил в img.php,
   и favicon-эндпоинт index.php не мог им пользоваться). Хранилище — json-файлы в
   storage/locks/: img-fails.json (неудачи) и img-gen.json (успешные). */

/** Лимит НЕУДАЧНЫХ генераций на IP: fail-маркер останавливает повторную попытку того же
   варианта, но злоумышленник может перебирать РАЗНЫЕ несуществующие комбинации — каждая
   до маркера декодирует оригинал. Сверх лимита — быстрый 404 без декодирования. */
function img_throttle_allow(string $ip): bool
{
    $count = img_throttle_count(ROOT . '/storage/locks/img-fails.json', $ip);
    return $count !== null && $count < IMG_FAIL_MAX_PER_IP;
}

function img_throttle_hit(string $ip): bool
{
    return img_throttle_bump(ROOT . '/storage/locks/img-fails.json', $ip) > 0;
}

/**
 * Ключ троттлинга: вместо точного IP — его подсеть (IPv4 /24, IPv6 /48). Ботнет из адресов
 * одного блока перебором последнего октета обходил лимиты генерации — каждый новый IP начинал
 * с чистого счётчика (ревизия 1.9.34, п.17). Побочный эффект принят: легитимные посетители
 * за общим NAT (офис, мобильный оператор) делят один счётчик — лимиты (300 генераций/час)
 * для обычного просмотра готовых миниатюр недостижимы, генерация нужна только на новых файлах.
 */
function img_throttle_key(string $ip): string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $bin = @inet_pton($ip);
        /* /48, а не /64: у клиента провайдера обычно целый /48 (65 тысяч подсетей /64),
           и перебор /64-префиксов обходил лимит, раздувая json-счётчиков (аудит 1.9.40, п.13) */
        return $bin === false ? $ip : 'v6:' . bin2hex(substr($bin, 0, 6));
    }
    $p = explode('.', $ip);
    return count($p) === 4 ? $p[0] . '.' . $p[1] . '.' . $p[2] . '.0/24' : $ip;
}

/**
 * Счётчик событий по IP в json-файле (скользящее окно час): добавляет событие
 * и возвращает, сколько их теперь за окно. Общий для fail- и gen-троттлинга.
 */
function img_throttle_bump(string $f, string $ip): int
{
    if (!img_throttle_reserve($f, $ip, PHP_INT_MAX)) {
        return 0;
    }
    return (int)img_throttle_count($f, $ip);
}

/** Атомарная проверка и резервирование одного события. При ошибке хранилища работа запрещается. */
function img_throttle_reserve(string $f, string $ip, int $max): bool
{
    $ip = img_throttle_key($ip);
    $dir = dirname($f);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return false;
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }
    $fh = @fopen($f, 'c+');
    if (!$fh) {
        return false;
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return false;
        }
        $raw = stream_get_contents($fh);
        if ($raw === false) {
            return false;
        }
        $data = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        $now = time();
        foreach ($data as $k => $list) {
            $data[$k] = array_values(array_filter((array)$list, static fn($t) => $now - (int)$t < 3600));
            if (!$data[$k]) {
                unset($data[$k]);
            }
        }
        $n = count($data[$ip] ?? []);
        if ($n >= $max) {
            return false;
        }
        $data[$ip][] = $now;
        $json = json_encode($data);
        if ($json === false || !ftruncate($fh, 0) || !rewind($fh)) {
            return false;
        }
        return fwrite($fh, $json) === strlen($json);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Сколько событий IP уже имеет за скользящий час; null означает ошибку хранилища. */
function img_throttle_count(string $f, string $ip): ?int
{
    $ip = img_throttle_key($ip);
    if (!is_file($f)) {
        return 0;
    }
    $fh = @fopen($f, 'r');
    if (!$fh) {
        return null;
    }
    if (!flock($fh, LOCK_SH)) {
        fclose($fh);
        return null;
    }
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($raw === false) {
        return null;
    }
    $data = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    $now = time();
    $n = 0;
    foreach ((array)($data[$ip] ?? []) as $t) {
        if ($now - (int)$t < 3600) {
            $n++;
        }
    }
    return $n;
}

function media_dir(): string
{
    return ROOT . '/media';
}

function originals_dir(): string
{
    return ROOT . '/storage/originals';
}

function webp_supported(): bool
{
    return function_exists('imagewebp') && function_exists('imagecreatefromwebp');
}

/** Умеет ли ВЫБРАННЫЙ движок писать AVIF (1.9.48). Imagick: формат должен быть в queryFormats
    (сборки ImageMagick без libheif/libaom AVIF не кодируют). GD: imageavif() появилась в
    PHP 8.1 и требует GD, собранного с libavif. Результат кэшируем: опрос на каждую картинку
    страницы был бы лишней работой. */
function avif_supported(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = img_engine() === 'imagick'
            ? (extension_loaded('imagick') && in_array('AVIF', Imagick::queryFormats('AVIF'), true))
            : function_exists('imageavif');
    }
    return $ok;
}

/** AVIF включён настройкой И поддержан движком — единая проверка для генерации вариантов,
    разметки <picture> (img_html в app/render.php) и публичной точки img.php (1.9.48). */
function avif_enabled(): bool
{
    return setting('avif') === '1' && avif_supported();
}

/** Качество AVIF: настройка «Качество AVIF», коридор 30–80 (по умолчанию 50).
    Пустое/мусорное значение из базы → 50, как в setting_defaults(). */
function avif_quality(): int
{
    $q = (int)setting('avif_quality');
    if ($q <= 0) {
        $q = 50;
    }
    return max(30, min(80, $q));
}

/**
 * Теги <source> для <picture> (1.9.48): AVIF первым (на 20–30 % легче WebP при том же
 * качестве — браузер берёт первый поддерживаемый формат), затем WebP; сам <img> с
 * JPEG-вариантами добавляет img_html() в app/render.php. Гварды те же, что там:
 * у AVIF — настройка + поддержка движком, у WebP — поддержка выбранным движком.
 */
function picture_sources_html(array $img, string $sizes, array $widths): string
{
    $html = '';
    if (avif_enabled()) {
        $html .= '<source type="image/avif" srcset="' . e(image_srcset($img, 'avif', $widths)) . '" sizes="' . e($sizes) . '">';
    }
    /* webp-гвард по движку (1.9.30): при Imagick решает его поддержка формата, а не GD */
    if (img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported()) {
        $html .= '<source type="image/webp" srcset="' . e(image_srcset($img, 'webp', $widths)) . '" sizes="' . e($sizes) . '">';
    }
    return $html;
}

/** Кэш строк images на время запроса (общий для image_get и images_prime). */
function &image_cache(): array
{
    static $cache = [];
    return $cache;
}

function image_get(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $cache = &image_cache();
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = db_one('SELECT * FROM images WHERE id = ?', [$id]);
    }
    return $cache[$id];
}

/**
 * Пакетная загрузка изображений в кэш image_get() одним запросом — для списков
 * (заметки, галереи, RSS), где иначе на каждый элемент уходит по SELECT'у.
 */
function images_prime(array $ids): void
{
    $cache = &image_cache();
    $need = [];
    foreach (array_map('intval', $ids) as $id) {
        if ($id > 0 && !array_key_exists($id, $cache)) {
            $need[$id] = true;
        }
    }
    if (!$need) {
        return;
    }
    foreach (db_all_in('SELECT * FROM images WHERE id IN ({IN})', array_keys($need)) as $r) {
        $cache[(int)$r['id']] = $r;
    }
}

/** Сброс кэша: вызывается при любой записи в images (alt, удаление), чтобы не отдать старое. */
function image_cache_flush(): void
{
    $cache = &image_cache();
    $cache = [];
}

function image_url(array $img, int $w, string $ext = 'jpg'): string
{
    $url = '/media/' . (int)$img['id'] . '/' . $w . '/' . $img['slug'] . '.' . $ext;
    $path = image_variant_path($img, $w, $ext);
    if (is_file($path)) {
        $url .= '?v=' . (int)@filemtime($path);
    }
    return $url;
}

function image_original_path(array $img): string
{
    return originals_dir() . '/' . (int)$img['id'] . '.' . $img['ext'];
}

/** Дата съёмки из EXIF оригинала (DateTimeOriginal, запасно DateTimeDigitized/IFD0 DateTime).
    0 — метаданных нет. Дата ЗАГРУЗКИ файла сознательно не используется: она не дата съёмки.
    Читается только когда включён LED-штамп «Плёночной» темы; кэш — на запрос, по id. */
function image_shot_at(array $img): int
{
    static $cache = [];
    $id = (int)($img['id'] ?? 0);
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $ts = 0;
    $path = image_original_path($img);
    $ext = strtolower((string)($img['ext'] ?? ''));
    if (function_exists('exif_read_data') && in_array($ext, ['jpg', 'jpeg', 'tif', 'tiff'], true) && is_file($path)) {
        $ex = @exif_read_data($path, 'EXIF', true);
        $raw = $ex['EXIF']['DateTimeOriginal'] ?? $ex['EXIF']['DateTimeDigitized'] ?? $ex['IFD0']['DateTime'] ?? '';
        if (is_string($raw) && preg_match('/^(\d{4})[:\-](\d{2})[:\-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $raw, $m)) {
            $candidate = strtotime("$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]");
            /* год 0000/1970 и даты из будущего EXIF не принимаем */
            if ($candidate !== false && $candidate > 946684800 && $candidate <= time() + 86400) {
                $ts = $candidate;
            }
        }
    }
    return $cache[$id] = $ts;
}

function image_variant_path(array $img, int $w, string $ext): string
{
    return media_dir() . '/' . (int)$img['id'] . '/' . $w . '/' . $img['slug'] . '.' . $ext;
}

/** Ширины для srcset: не больше оригинала (без апскейла). */
function image_widths(array $img, int $max = 2520): array
{
    $ow = (int)$img['w'];
    $list = array_values(array_filter(IMG_WIDTHS, static fn($w) => $w >= 280 && $w <= $max && $w <= $ow));
    if ($list) {
        return $list;
    }
    /* Фото уже 280 px (логотипы, иконки): отдаём ближайшую ступень вверх — именно её разрешает
       image_width_allowed(). Жёсткий [280] раньше давал 404 на всех src/srcset таких картинок. */
    foreach (IMG_WIDTHS as $step) {
        if ($step >= $ow) {
            return [$step];
        }
    }
    return [280];
}

function image_pick_width(array $widths, int $target): int
{
    foreach ($widths as $w) {
        if ($w >= $target) {
            return $w;
        }
    }
    return (int)end($widths);
}

function image_srcset(array $img, string $ext, array $widths): string
{
    $parts = [];
    foreach ($widths as $w) {
        $parts[] = image_url($img, $w, $ext) . ' ' . $w . 'w';
    }
    return implode(', ', $parts);
}

/**
 * Фокусная точка кадра (1.9.25, идея из Koken CMS): inline-стиль object-position для картинок
 * с object-fit:cover (обложки, миниатюры) — кадрирование идёт вокруг выбранной точки, а не центра.
 * Точка по умолчанию — центр (0.5/0.5): тогда стиль не нужен, возвращаем пустую строку.
 */
function image_focus_style(array $img): string
{
    $fx = (float)($img['focus_x'] ?? 0.5);
    $fy = (float)($img['focus_y'] ?? 0.5);
    if (abs($fx - 0.5) < 0.004 && abs($fy - 0.5) < 0.004) {
        return '';
    }
    /* клампим в 0..1: в базу значения попадают через валидацию, но не строим CSS на слепой вере */
    $fx = max(0.0, min(1.0, $fx));
    $fy = max(0.0, min(1.0, $fy));
    return 'object-position:' . round($fx * 100, 1) . '% ' . round($fy * 100, 1) . '%';
}

/** Самый большой доступный вариант (для лайтбокса и og:image). */
function image_large_url(array $img, int $max = 2520, string $ext = 'jpg'): string
{
    $w = (int)max(image_widths($img, $max));
    return image_url($img, $w, $ext);
}

/* ---------- GD ---------- */

/** С PHP 8.5 imagedestroy() объявлена deprecated (изображения — объекты, память отпускает сборщик);
    на 8.0–8.4 вызов безвреден, на 7.x реально освобождал память. Единая обёртка — чтобы не спамить лог. */
function gd_free($im): void
{
    if (PHP_VERSION_ID < 80500 && $im instanceof \GdImage) {
        imagedestroy($im);
    }
}

function gd_load(string $path)
{
    $info = @getimagesize($path);
    if (!$info) {
        app_log_error('gd_load: не удалось прочитать заголовок изображения ' . $path);
        return false;
    }
    $type = $info[2];
    $im = false;
    if ($type === IMAGETYPE_JPEG) {
        $im = @imagecreatefromjpeg($path);
    } elseif ($type === IMAGETYPE_PNG) {
        $im = @imagecreatefrompng($path);
    } elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        $im = @imagecreatefromwebp($path);
    } elseif ($type === IMAGETYPE_GIF) {
        $im = @imagecreatefromgif($path);
    }
    if (!$im) {
        $e = error_get_last();
        app_log_error('gd_load: не удалось декодировать ' . $path . ($e ? ' — ' . $e['message'] : ''));
        return false;
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($path);
        $o = (int)($ex['Orientation'] ?? 1);
        /* Зеркальные ориентации (2, 4, 5, 7) сначала отзеркаливаем, затем поворачиваем:
           5 (transpose) = flip H + 90° CCW, 7 (transverse) = flip H + 90° CW.
           GD крутит ПРОТИВ часовой (imagerotate положителен в CCW), поэтому знаки такие.
           До 1.9.35 углы для 5 и 7 были перепутаны местами — зеркальные портреты с телефона
           поворачивались не туда (ревизия 1.9.34, п.5). */
        $flips = [2 => IMG_FLIP_HORIZONTAL, 4 => IMG_FLIP_VERTICAL, 5 => IMG_FLIP_HORIZONTAL, 7 => IMG_FLIP_HORIZONTAL];
        if (isset($flips[$o]) && function_exists('imageflip')) {
            @imageflip($im, $flips[$o]);
        }
        $angles = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90];
        if (isset($angles[$o])) {
            $rot = imagerotate($im, $angles[$o], 0);
            if ($rot) {
                gd_free($im); // оригинал больше не нужен: не держим две копии многомегапиксельного кадра
                $im = $rot;
            }
        }
    }
    if (!imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    return $im;
}

/**
 * Сила поднятия резкости после уменьшения (1.9.25): настройка «Резкость превью, %» (0–100,
 * по умолчанию 50). 50 соответствует прежнему зашитому ядру (−0.25/2.0), 0 — отключает полностью.
 * Даунскейл в GD всегда слегка мылит; ядро с суммой 1 возвращает «звон» без сдвига яркости.
 */
function gd_sharpen_amount(): float
{
    $pct = max(0, min(100, (int)setting('sharpen')));
    return $pct > 0 ? 0.005 * $pct : 0.0; // 50 % → 0.25, как было зашито до настройки
}

/** Применяет к $out ядро резкости силой $k (0 — ничего не делает). */
function gd_sharpen($out, float $k): void
{
    if ($k > 0 && function_exists('imageconvolution')) {
        @imageconvolution($out, [[0.0, -$k, 0.0], [-$k, 1.0 + 4 * $k, -$k], [0.0, -$k, 0.0]], 1.0, 0.0);
    }
}

/** Атомарное перемещение tmp → dest. rename() не работает между разными файловыми системами
    (storage/tmp и media/ на разных маунтах — аудит 1.9.40, п.15): тогда copy во временный
    файл в каталоге назначения, rename и unlink исходника. */
function move_file_inplace(string $tmp, string $dest): bool
{
    if (@rename($tmp, $dest)) {
        return true;
    }
    $stage = $dest . '.tmp-' . getmypid();
    if (@copy($tmp, $stage)) {
        if (@rename($stage, $dest)) {
            @unlink($tmp);
            return true;
        }
    }
    @unlink($stage);
    return false;
}

function gd_save_variant($im, int $w, string $ext, string $dest, bool $allowSharpen = false): bool
{
    $sw = imagesx($im);
    $sh = imagesy($im);
    if ($sw < 1 || $sh < 1) {
        return false;
    }
    $tw = min($w, $sw);
    $th = max(1, (int)round($sh * $tw / $sw));
    /* AVIF умеет альфу — для PNG-оригиналов прозрачность сохраняем, как у webp/png (1.9.48) */
    $keepAlpha = ($ext === 'png' || $ext === 'webp' || $ext === 'avif');

    if ($keepAlpha && $tw === $sw) {
        $out = $im;
        imagealphablending($out, false);
        imagesavealpha($out, true);
    } else {
        /* Один проход imagecopyresampled при сильном уменьшении (2520 → 280) даёт ступеньки и муар.
           Уменьшаем вдвое, пока до цели не останется меньше двух раз, и только потом доводим до точного размера.
           Промежуточные кадры уничтожаем сразу: на больших оригиналах каждый — десятки мегабайт памяти. */
        $cur = $im;
        $cw = $sw;
        $ch = $sh;
        while ($cw > $tw * 2) {
            $nw = intdiv($cw, 2);
            $nh = max(1, (int)round($ch * $nw / $cw));
            $next = gd_resample($cur, $nw, $nh, $keepAlpha);
            if ($next === false) {
                /* 1.9.30: промежуточный кадр не влез в память (шаред-хостинг с ~120 МБ на процесс).
                   Деградируем до одного прохода из исходника — крошечный целевой кадр почти всегда
                   аллоцируется; качество чуть ниже (без каскада), но вариант родится. */
                if ($cur !== $im) {
                    gd_free($cur);
                }
                $cur = $im;
                break;
            }
            if ($cur !== $im) {
                gd_free($cur);
            }
            $cur = $next;
            $cw = $nw;
            $ch = $nh;
        }
        $out = gd_resample($cur, $tw, $th, $keepAlpha);
        if ($cur !== $im) {
            gd_free($cur);
        }
        if ($out === false) {
            return false; // памяти нет даже на целевой кадр — img.php поставит .fail и отдаст 404/503
        }
        /* после заметного уменьшения слегка возвращаем резкость (ядро с суммой 1 — яркость не меняется).
           С 1.9.25 — по настройке «Резкость превью» и не только для JPEG: webp-варианты JPG-оригиналов
           раньше оставались мягкими, потому что ветка keepAlpha пропускала их мимо нерезкости */
        if ($allowSharpen && $sw >= $tw * 1.5) {
            gd_sharpen($out, gd_sharpen_amount());
        }
    }

    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        app_log_error('gd_save_variant: не удалось создать папку ' . $dir . ' (проверьте права на media/)');
        return false;
    }
    /* Временный файл — вне публичной media/ (раньше лежал рядом с готовым вариантом и был
       доступен из веба, пока идёт кодирование). storage/ закрыт корневым .htaccess. */
    $tmpDir = ROOT . '/storage/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0700, true);
    }
    $tmp = (is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : $dir) . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if ($ext === 'jpg') {
        imageinterlace($out, true);
        $ok = imagejpeg($out, $tmp, max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
    } elseif ($ext === 'webp') {
        $ok = function_exists('imagewebp') && imagewebp($out, $tmp, max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('webp_quality'))));
    } elseif ($ext === 'avif') {
        /* imageavif() есть с PHP 8.1 при GD с libavif; коридор качества 30–80 (avif_quality),
           у AVIF шкала плотнее JPEG — 50 уже близко к webp 80 по визуалу */
        $ok = function_exists('imageavif') && imageavif($out, $tmp, avif_quality());
    } else {
        $ok = imagepng($out, $tmp, 6);
    }
    if ($ok) {
        $ok = move_file_inplace($tmp, $dest);
    }
    if (!$ok) {
        app_log_error('gd_save_variant: не удалось записать ' . $dest . ' (место на диске / права)');
        @unlink($tmp);
    }
    if ($out !== $im) { // ветка keepAlpha без ресайза отдаёт $out = $im — его освобождает вызывающий
        gd_free($out);
    }
    return (bool)$ok;
}

/** Новое truecolor-изображение $tw×$th; для png/webp/avif сохраняет прозрачность, для jpg заливает белым.
    При нехватке памяти imagecreatetruecolor возвращает false — отдаём false наверх (1.9.30: раньше
    это превращалось в TypeError на imagefill, и вся генерация падала фатально). */
function gd_resample($src, int $tw, int $th, bool $keepAlpha)
{
    $out = imagecreatetruecolor($tw, $th);
    if (!$out) {
        return false;
    }
    if ($keepAlpha) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    }
    imagecopyresampled($out, $src, 0, 0, 0, 0, $tw, $th, imagesx($src), imagesy($src));
    return $out;
}

/**
 * Источник для варианта. Мелкие размеры (≤ 900 px) у JPEG-оригиналов делаем из уже готового варианта 1240,
 * а не из оригинала в десятки мегапикселей: быстрее и почти без памяти. У PNG/WebP/GIF берём оригинал (там бывает прозрачность).
 */
function image_source_for(array $img, int $w)
{
    if ($img['ext'] === 'jpg' && $w <= 900) {
        $big = image_variant_path($img, 1240, 'jpg');
        if (is_file($big)) {
            $im = gd_load($big);
            if ($im) {
                return $im;
            }
        }
    }
    return gd_load(image_original_path($img));
}

/** EXIF-поворот для Imagick (1.9.30). autoOrientImage() есть не во всех сборках расширения
    (в php-imagick под ImageMagick 7 его нет), поэтому поворачиваем вручную по коду ориентации —
    та же логика, что у EXIF-обработки в gd_load: после применения код сбрасываем в 1,
    чтобы следующий поворот не случился дважды. */
function imagick_auto_orient(Imagick $im): void
{
    $o = (int)$im->getImageOrientation();
    /* Порядок — как в gd_load: зеркальные ориентации сначала отзеркаливаем, затем поворачиваем;
       оба движка обязаны дать одинаковый кадр. rotateImage положителен ПО часовой (в отличие от
       imagerotate): 5 (transpose) = flip H + 90° CCW → 270, 7 (transverse) = flip H + 90° CW → 90.
       До 1.9.35 углы 5 и 7 были перепутаны местами (ревизия 1.9.34, п.5). */
    switch ($o) {
        case 2: @$im->flopImage(); break;                                    // зеркало по горизонтали
        case 3: @$im->rotateImage('#000000', 180); break;                    // 180°
        case 4: @$im->flipImage(); break;                                    // зеркало по вертикали
        case 5: @$im->flopImage(); @$im->rotateImage('#000000', 270); break; // flip H + 90° CCW
        case 6: @$im->rotateImage('#000000', 90);  break;                    // 90° CW — портрет с телефона
        case 7: @$im->flopImage(); @$im->rotateImage('#000000', 90);  break; // flip H + 90° CW
        case 8: @$im->rotateImage('#000000', 270); break;                    // 90° CCW
    }
    if ($o > 1) {
        @$im->setImageOrientation(1); // поворот применён — сбрасываем, чтобы не повернуть дважды
    }
}

/** Есть ли у Imagick поддержка webp (1.9.30). */
function imagick_webp_supported(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = img_engine() === 'imagick' && in_array('WEBP', Imagick::queryFormats('WEBP'), true);
    }
    return $ok;
}

/**
 * Бандлированный sRGB ICC-профиль (app/icc/srgb.icc) — цель конверсии для широкогамутных
 * оригиналов (Adobe RGB, Display P3). Свободно распространяемый профиль sRGB IEC 61966-2-1.
 */
function imagick_srgb_profile(): ?string
{
    static $p = null;
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        $f = ROOT . '/app/icc/srgb.icc';
        $c = is_file($f) ? @file_get_contents($f) : false;
        $p = is_string($c) && $c !== '' ? $c : null;
    }
    return $p;
}

/**
 * У JPEG есть ICC-профиль шире sRGB? Эвристика для GD-пути (GD ICC не применяет вообще):
 * маркер «ICC_PROFILE» в начале файла есть, а подписи «sRGB» в профиле нет (Adobe RGB,
 * Display P3). Нужна для предупреждения при загрузке (аудит 1.9.40, п.1).
 */
function image_icc_wide_gamut(string $path): bool
{
    $head = @file_get_contents($path, false, null, 0, 1048576);
    if (!is_string($head) || stripos($head, 'ICC_PROFILE') === false) {
        return false;
    }
    return stripos($head, 'sRGB') === false;
}

/** Вариант через Imagick (1.9.30): EXIF-поворот, уменьшение, мягкая резкость, запись во временный
    файл с атомарным rename — те же договорённости, что у GD-пути. Imagick при нехватке памяти
    сам переходит на дисковый кэш пикселей, поэтому 24-Мп оригиналы обрабатываются и на
    шаред-хостингах с маленьким лимитом памяти на процесс. */
function imagick_make_variant(array $img, int $w, string $ext): bool
{
    if (!in_array($w, IMG_WIDTHS, true) || !in_array($ext, ['jpg', 'webp', 'avif', 'png'], true)) {
        return false;
    }
    if ($ext === 'webp' && !imagick_webp_supported()) {
        return false;
    }
    /* AVIF: настройка выключена или сборка ImageMagick без кодера — не генерируем вовсе,
       иначе запросы с img.php ставили бы .fail-маркеры на заведомо нерабочий формат */
    if ($ext === 'avif' && !avif_enabled()) {
        return false;
    }
    $dest = image_variant_path($img, $w, $ext);
    if (is_file($dest)) {
        return true;
    }
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }
    try {
        /* Источник: мелкие варианты (≤ 900 px) у JPEG-оригиналов делаем из уже готового
           1240-го — как GD-путь в image_source_for(): чтение многомегапиксельного
           оригинала в разы дороже, а визуально цепочка 1240→w неотличима (ревизия 1.9.34, п.9). */
        $src = image_original_path($img);
        if ($img['ext'] === 'jpg' && $w <= 900) {
            $big = image_variant_path($img, 1240, 'jpg');
            if (is_file($big)) {
                $src = $big;
            }
        }
        $im = new Imagick();
        /* Лимиты ресурсов декодера (аудит 1.9.40, п.4): у IM по умолчанию их нет, и «бомба
           распаковки» (PNG/GIF сверхбольших размеров) могла надолго занять процесс. */
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);  // 256 МБ
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);     // 512 МБ
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 60);
        /* jpeg:size — подсказка декодеру libjpeg прочитать сразу уменьшенный (DCT-масштабом
           /2, /4, /8) кадр, когда цель заметно меньше оригинала: 24-Мп jpeg декодируется
           как 6-Мп — в 4 раза меньше памяти и времени. Только для исходного jpeg: готовый
           1240-й и так мал (там же). Просим двойной размер — запас под качественный Lanczos. */
        if ($src === image_original_path($img) && $w * 2 < max(1, (int)($img['w'] ?? 0))) {
            $im->setOption('jpeg:size', ($w * 2) . 'x' . ($w * 2));
        }
        /* [0] — только первый кадр: у анимированного GIF берём первый, как и GD-путь.
           Формат задаём явно по расширению оригинала (его тип проверен по содержимому при
           загрузке): без префикса IM распознавал бы файл по своим делегатам, включая те,
           что мы не проверяли (аудит 1.9.40, п.4). Готовый 1240-й вариант — всегда jpeg. */
        $decoder = $src === image_original_path($img)
            ? (['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'][(string)$img['ext']] ?? null)
            : 'jpeg';
        $im->readImage(($decoder !== null ? $decoder . ':' : '') . $src . '[0]');
        imagick_auto_orient($im); // EXIF-ориентация, как imagerotate в gd_load
        /* Цветовой профиль: stripImage() ниже вырезает ICC — и без приведения к sRGB широкий
           гамут (Adobe RGB, Display P3) после вырезания тускнел: браузер трактует голые
           пиксели как sRGB. Конверсия — profileImage() с бандлированным sRGB-профилем:
           transformImageColorspace() здесь менял лишь ТЕГ пространства, не применяя ICC,
           и цвета съезжали (живой тест 1.9.40: P3-файл rgb(30,209,79) уходил в вариант
           без конверсии вместо sRGB rgb(1,213,53)). Без файла профиля — как раньше. */
        try {
            if (in_array('icc', $im->getImageProfiles('*', false), true)
                && stripos((string)$im->getImageProfile('icc'), 'sRGB') === false) {
                $srgb = imagick_srgb_profile();
                if ($srgb !== null) {
                    $im->profileImage('icc', $srgb);
                }
            }
        } catch (Throwable $ignore) {
            // профиль не читается/не применяется — считаем пиксели sRGB, как было до 1.9.35
        }
        $sw = $im->getImageWidth();
        $sh = $im->getImageHeight();
        if ($sw < 1 || $sh < 1) {
            $im->clear();
            return false;
        }
        $tw = min($w, $sw);
        $th = max(1, (int)round($sh * $tw / $sw));
        if ($tw !== $sw) {
            // Lanczos честнее всего уменьшает фото; thumbnailImage быстрее, но заметно грубее
            $im->resizeImage($tw, $th, Imagick::FILTER_LANCZOS, 1.0);
        }
        /* резкость — по той же настройке «Резкость превью» и тем же правилам, что у GD:
           jpeg-варианты всегда, webp/avif — только из jpeg-оригиналов (нет альфы) */
        $allowSharpen = $ext === 'jpg' || (($ext === 'webp' || $ext === 'avif') && $img['ext'] === 'jpg');
        $pct = max(0, min(100, (int)setting('sharpen')));
        if ($allowSharpen && $pct > 0 && $sw >= $tw * 1.5) {
            @$im->unsharpMaskImage(0, 0.8, 0.01 * $pct, 0.02); // 50 % → 0.5 — мягкий подъём микроконтраста
        }
        if ($ext === 'jpg') {
            /* Прозрачность в JPEG уплощаем на белый — как GD-путь (gd_resample заливает фон
               белым). Иначе альфа PNG просто отбрасывалась без блендинга и полупрозрачные
               места «грязнили» (живой тест 1.9.40: rgba(255,0,0,.5) давал чистый красный
               вместо розового; аудит 1.9.40, п.6). */
            if ($im->getImageAlphaChannel()) {
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $flat = @$im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                if ($flat instanceof Imagick) {
                    $im->clear();
                    $im = $flat;
                }
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE); // прогрессивный jpeg, как imageinterlace
        } elseif ($ext === 'webp') {
            $im->setImageFormat('webp');
            $im->setImageCompressionQuality(max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('webp_quality'))));
        } elseif ($ext === 'avif') {
            /* альфа PNG-оригиналов сохраняется сама: уплощение на белый делаем только для jpeg (1.9.48) */
            $im->setImageFormat('avif');
            $im->setImageCompressionQuality(avif_quality());
        } else {
            $im->setImageFormat('png');
        }
        $im->stripImage(); // EXIF/ICC из миниатюр убираем: меньше вес, нет утечки метаданных (GD-путь их и не сохранял)
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            app_log_error('imagick_make_variant: не удалось создать папку ' . $dir);
            $im->clear();
            return false;
        }
        $tmpDir = ROOT . '/storage/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0700, true);
        }
        $tmp = (is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : $dir) . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $ok = @$im->writeImage($tmp);
        $im->clear();
        if ($ok) {
            $ok = move_file_inplace($tmp, $dest);
        }
        if (!$ok) {
            app_log_error('imagick_make_variant: не удалось записать ' . $dest);
            @unlink($tmp);
        }
        return (bool)$ok;
    } catch (Throwable $e) {
        app_log_error('imagick_make_variant: ' . $e->getMessage() . ' (фото #' . (int)$img['id'] . ')');
        return false;
    }
}

function image_make_variant(array $img, int $w, string $ext, $loaded = null): bool
{
    if (!in_array($w, IMG_WIDTHS, true) || !in_array($ext, ['jpg', 'webp', 'avif', 'png'], true)) {
        return false;
    }
    /* AVIF — только при включённой настройке и поддержке движком: ручные запросы к img.php
       с выключенным avif не должны запускать кодирование (оно самое тяжёлое из форматов) */
    if ($ext === 'avif' && !avif_enabled()) {
        return false;
    }
    if (img_engine() === 'imagick') {
        return imagick_make_variant($img, $w, $ext); // GD-объект $loaded сюда не подходит — грузим сами
    }
    if ($ext === 'webp' && !webp_supported()) {
        return false;
    }
    $dest = image_variant_path($img, $w, $ext);
    if (is_file($dest)) {
        return true;
    }
    image_raise_memory();
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }
    $own = $loaded === null; // источник загрузили сами — сами и освобождаем
    $im = $loaded ?: image_source_for($img, $w);
    if (!$im) {
        return false;
    }
    /* резкость — только там, где нет прозрачности, которую она «звенит» по краям:
       jpeg-варианты всегда, webp/avif — только из jpeg-оригиналов (альфы там нет по определению) */
    $allowSharpen = $ext === 'jpg' || (($ext === 'webp' || $ext === 'avif') && $img['ext'] === 'jpg');
    $ok = gd_save_variant($im, $w, $ext, $dest, $allowSharpen);
    if ($own) {
        gd_free($im);
    }
    return $ok;
}

/* ---------- кадр для соцсетей og:image 1200×630 (1.9.63) ---------- */

/**
 * Размеры og-кадра для фото: цель соцсетей 1200×630 (1.91:1). Для оригиналов уже 1200 px —
 * пропорционально меньше; совсем маленькие (< 600 px) не кадрируем вовсе (null) —
 * для них og:image остаётся обычным вариантом с честными размерами.
 */
function og_image_dims(array $img): ?array
{
    $ow = (int)($img['w'] ?? 0);
    if ($ow < 600) {
        return null;
    }
    $w = min(1200, $ow);
    return [$w, (int)round($w * 630 / 1200)];
}

/** Путь файла og-кадра: media/{id}/og-1200x630.jpg — Apache отдаёт как статику. */
function og_variant_path(array $img, int $w, int $h): string
{
    return media_dir() . '/' . (int)$img['id'] . '/og-' . $w . 'x' . $h . '.jpg';
}

/**
 * Готовый og-кадр фото (генерирует при первом обращении): обрезка 1.91:1 вокруг
 * фокусной точки кадра (focus_x/focus_y — та же, что у обложек карточек).
 * null — фото слишком маленькое или кадр не родился (тогда og:image — обычный вариант).
 */
function og_image_variant(array $img): ?array
{
    $dims = og_image_dims($img);
    if ($dims === null) {
        return null;
    }
    [$w, $h] = $dims;
    $dest = og_variant_path($img, $w, $h);
    $src = image_original_path($img);
    /* свежесть: оригинал новее файла (перезаливка той же записью фото) — пересоздаём */
    if (!is_file($dest) || (is_file($src) && (int)@filemtime($src) > (int)@filemtime($dest))) {
        $failMark = $dest . '.fail';
        if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
            return null;
        }
        $lock = image_lock_wait((int)$img['id'], IMG_LOCK_WAIT);
        if ($lock === false || $lock === null) {
            return null;
        }
        try {
            clearstatcache(true, $dest);
            if (is_file($dest) && (int)@filemtime($dest) >= (int)@filemtime($src)) {
                return ['url' => '/media/' . (int)$img['id'] . '/og-' . $w . 'x' . $h . '.jpg?v=' . (int)@filemtime($dest), 'w' => $w, 'h' => $h];
            }
            if ((is_file($failMark) && time() - (int)@filemtime($failMark) < 86400) || !img_throttle_allow(client_ip())) {
                return null;
            }
            $cold = !is_dir(media_dir() . '/' . (int)$img['id']);
            $thFile = $cold ? ROOT . '/storage/locks/img-gen-cold.json' : ROOT . '/storage/locks/img-gen.json';
            if (!img_throttle_reserve($thFile, client_ip(), $cold ? IMG_GEN_COLD_MAX_PER_IP : IMG_GEN_MAX_PER_IP)) {
                return null;
            }
            if (!og_variant_make($img, $w, $h, $dest)) {
                @touch($failMark);
                if (!img_throttle_hit(client_ip())) {
                    return null;
                }
                return null;
            }
            @unlink($failMark);
        } finally {
            image_unlock($lock);
        }
    }
    $url = '/media/' . (int)$img['id'] . '/og-' . $w . 'x' . $h . '.jpg';
    clearstatcache(true, $dest);
    if (is_file($dest)) {
        $url .= '?v=' . (int)@filemtime($dest);
    }
    return ['url' => $url, 'w' => $w, 'h' => $h];
}

/** Создаёт og-кадр: масштаб «cover» + обрезка окном 1200×H вокруг фокусной точки. */
function og_variant_make(array $img, int $w, int $h, string $dest): bool
{
    $src = image_original_path($img);
    $fx = max(0.0, min(1.0, (float)($img['focus_x'] ?? 0.5)));
    $fy = max(0.0, min(1.0, (float)($img['focus_y'] ?? 0.5)));
    if (function_exists('set_time_limit')) {
        set_time_limit(120);
    }

    if (img_engine() === 'imagick') {
        try {
            $im = new Imagick();
            /* те же лимиты декодера и явный формат, что в imagick_make_variant (аудит 1.9.40, п.4) */
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, 60);
            if ($w * 2 < max(1, (int)($img['w'] ?? 0))) {
                $im->setOption('jpeg:size', ($w * 2) . 'x' . ($w * 2));
            }
            $decoder = ['jpg' => 'jpeg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'][(string)$img['ext']] ?? null;
            if ($decoder === null) {
                return false;
            }
            $im->readImage($decoder . ':' . $src . '[0]');
            imagick_auto_orient($im);
            try {
                if (in_array('icc', $im->getImageProfiles('*', false), true)
                    && stripos((string)$im->getImageProfile('icc'), 'sRGB') === false) {
                    $srgb = imagick_srgb_profile();
                    if ($srgb !== null) {
                        $im->profileImage('icc', $srgb);
                    }
                }
            } catch (Throwable $ignore) {
                // профиль не читается — считаем пиксели sRGB, как в остальных вариантах
            }
            $sw = $im->getImageWidth();
            $sh = $im->getImageHeight();
            if ($sw < 1 || $sh < 1) {
                $im->clear();
                return false;
            }
            $scale = max($w / $sw, $h / $sh);
            $rw = max($w, (int)ceil($sw * $scale));
            $rh = max($h, (int)ceil($sh * $scale));
            if ($rw !== $sw || $rh !== $sh) {
                $im->resizeImage($rw, $rh, Imagick::FILTER_LANCZOS, 1.0);
            }
            $x = max(0, min($rw - $w, (int)round($fx * $rw - $w / 2)));
            $y = max(0, min($rh - $h, (int)round($fy * $rh - $h / 2)));
            $im->cropImage($w, $h, $x, $y);
            $pct = max(0, min(100, (int)setting('sharpen')));
            if ($pct > 0 && $sw >= $w * 1.5 && !$im->getImageAlphaChannel()) {
                @$im->unsharpMaskImage(0, 0.8, 0.01 * $pct, 0.02);
            }
            if ($im->getImageAlphaChannel()) {
                /* прозрачность уплощаем на белый — как остальные jpeg-варианты (аудит 1.9.40, п.6) */
                $im->setImageBackgroundColor('white');
                $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                $flat = @$im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
                if ($flat instanceof Imagick) {
                    $im->clear();
                    $im = $flat;
                }
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
            $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);
            $im->stripImage();
            $ok = og_variant_write($im->getImagesBlob(), $dest);
            $im->clear();
            return $ok;
        } catch (Throwable $e) {
            app_log_error('og_variant_make: ' . $e->getMessage() . ' (фото #' . (int)$img['id'] . ')');
            return false;
        }
    }

    /* GD-путь: сначала обрезка окном вокруг фокуса, потом уменьшение до точного размера —
       так целевой кадр всегда ровно $w×$h */
    image_raise_memory();
    $im = gd_load($src);
    if (!$im) {
        return false;
    }
    $sw = imagesx($im);
    $sh = imagesy($im);
    if ($sw < 1 || $sh < 1) {
        gd_free($im);
        return false;
    }
    $scale = max($w / $sw, $h / $sh);
    $cw = min($sw, $w / $scale);
    $ch = min($sh, $h / $scale);
    $cx = max(0, min($sw - $cw, (int)round($fx * $sw - $cw / 2)));
    $cy = max(0, min($sh - $ch, (int)round($fy * $sh - $ch / 2)));
    $out = imagecreatetruecolor($w, $h);
    if ($out === false) {
        gd_free($im);
        return false;
    }
    $white = imagecolorallocate($out, 255, 255, 255);
    if ($white !== false) {
        imagefill($out, 0, 0, $white); // полупрозрачные места PNG — на белом, как gd_resample
    }
    if (!imagecopyresampled($out, $im, 0, 0, (int)$cx, (int)$cy, $w, $h, (int)$cw, (int)$ch)) {
        gd_free($im);
        gd_free($out);
        return false;
    }
    gd_free($im);
    $pct = max(0, min(100, (int)setting('sharpen')));
    if ($pct > 0 && $sw >= $w * 1.5) {
        gd_sharpen($out, gd_sharpen_amount());
    }
    ob_start();
    imageinterlace($out, true);
    $ok = imagejpeg($out, null, max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)setting('jpg_quality'))));
    $blob = (string)ob_get_clean();
    gd_free($out);
    return $ok && og_variant_write($blob, $dest);
}

/** Атомарная запись og-кадра (tmp + rename) — как у остальных вариантов. */
function og_variant_write(string $blob, string $dest): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        app_log_error('og_variant_write: не удалось создать папку ' . $dir);
        return false;
    }
    $tmpDir = ROOT . '/storage/tmp';
    if (!is_dir($tmpDir)) {
        @mkdir($tmpDir, 0700, true);
    }
    $tmp = (is_dir($tmpDir) && is_writable($tmpDir) ? $tmpDir : $dir) . '/.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $blob) === false) {
        app_log_error('og_variant_write: не удалось записать ' . $dest);
        @unlink($tmp);
        return false;
    }
    $ok = move_file_inplace($tmp, $dest);
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}


/**
 * Принимает файл изображения, сохраняет оригинал в storage/originals (закрыт от веба),
 * сразу делает превью 440 и 1240 px, остальные размеры создаются при первом запросе.
 * Возвращает строку из таблицы images. При ошибке бросает RuntimeException.
 */
/** Анимированный ли GIF: больше одного кадра — значит, два и более блока Graphic Control
    Extension. GD читает только первый кадр, поэтому анимация на сайте «сдуется» в статику —
    админка предупреждает об этом при загрузке. */
function gif_is_animated(string $path): bool
{
    /* Читаем кусками по 64 КБ с переносом 2 байт: маркер \x21\xF9\x04 может попасть на стык
       блоков, а целиком многомегабайтный gif в память не тянем (ревизия 1.9.34, п.15).
       Маркер не может пересекаться сам с собой (ни один его суффикс не равен префиксу),
       поэтому перенос не даёт ни пропусков, ни двойных подсчётов. */
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $found = 0;
    $carry = '';
    while (!feof($fh)) {
        $chunk = $carry . (string)fread($fh, 65536);
        $found += substr_count($chunk, "\x21\xF9\x04");
        if ($found > 1) {
            break;
        }
        $carry = substr($chunk, -2);
    }
    fclose($fh);
    return $found > 1;
}

/**
 * Уборка временных файлов-«сирот» (ревизия 1.9.34, п.16): .up-* в originals остаются при обрыве
 * загрузки между copy() и rename(), .tmp-* в storage/tmp и media/{id}/ — при фатале/обрыве записи
 * варианта до атомарного rename(). Живой процесс держит такой файл секунды-минуты, поэтому
 * удаляем только старше суток. Вызывается из дашборда админки; возвращает число удалённых.
 */
function img_tmp_gc(int $maxAge = 86400): int
{
    $n = 0;
    $now = time();
    $spots = [originals_dir() . '/.up-*', ROOT . '/storage/tmp/.tmp-*'];
    foreach ((array)(glob(media_dir() . '/*', GLOB_ONLYDIR) ?: []) as $d) {
        if (preg_match('~/\d+$~', $d)) {
            $spots[] = $d . '/.tmp-*';
            foreach ((array)(glob($d . '/*', GLOB_ONLYDIR) ?: []) as $widthDir) {
                $spots[] = $widthDir . '/.tmp-*';
            }
        }
    }
    foreach ($spots as $pattern) {
        foreach ((array)(glob($pattern) ?: []) as $f) {
            if (is_file($f) && $now - (int)@filemtime($f) > $maxAge && @unlink($f)) {
                $n++;
            }
        }
    }
    /* сироты видеотеки: файл в media/video/ без строки в videos остаётся, если при загрузке
       ролика откат не смог удалить файл (сбойная БД — аудит 1.9.48, п.10). Тот же принцип:
       удаляем только старше суток, живой процесс держит такой файл секунды-минуты */
    $known = [];
    foreach (db_all('SELECT file FROM videos') as $r) {
        $known[basename((string)$r['file'])] = true;
    }
    foreach ((array)(glob(media_dir() . '/video/*.mp4') ?: []) as $f) {
        if (is_file($f) && !isset($known[basename($f)]) && $now - (int)@filemtime($f) > $maxAge && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}

function image_add_from_file(string $tmpPath, string $origName, string $alt = ''): array
{
    if (function_exists('set_time_limit')) {
        set_time_limit(180);
    }

    /* Декодирование оригинала — самая прожорливая по памяти операция: загрузки делим
       тот же пул слотов, что и публичная генерация вариантов (img.php), иначе пакетная
       загрузка пары десятков фото держала бы в памяти столько же декодированных кадров */
    $slot = image_lock(mt_rand(0, IMG_LOCK_SLOTS - 1), true);
    if ($slot === null) {
        throw new RuntimeException('Не удалось получить блокировку обработки изображения. Проверьте storage/locks.');
    }
    try {
    image_raise_memory(); // сработает, только если владелец задал img_memory_limit в config.php

    $info = @getimagesize($tmpPath);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Неподдерживаемый формат (нужны JPG, PNG, WebP или GIF): ' . $origName);
    }
    if ($info[2] === IMAGETYPE_WEBP && !function_exists('imagecreatefromwebp') && img_engine() !== 'imagick') {
        throw new RuntimeException('PHP на сервере не умеет читать WebP: ' . $origName);
    }
    $maxPx = image_max_pixels();
    if ((int)$info[0] * (int)$info[1] > $maxPx) {
        /* длинная сторона кадра 3:2 с допустимым числом пикселей — подсказка, до чего уменьшить */
        $side = (int)(floor(sqrt($maxPx * 1.5) / 100) * 100);
        /* при Imagick (1.9.30) предел — не память (он уходит в дисковый кэш), а абсолютный потолок */
        $why = img_engine() === 'imagick'
            ? 'это выше абсолютного потолка CMS (' . (int)(IMG_MAX_PIXELS_CAP / 1000000) . ' Мп)'
            : 'при текущем лимите памяти PHP сервер не сможет его обработать';
        throw new RuntimeException('Слишком большое разрешение (' . (int)$info[0] . '×' . (int)$info[1] . ' px) — ' . $why . '. '
            . 'Уменьшите фото до ' . $side . ' px по длинной стороне и загрузите снова: ' . $origName);
    }
    /* 1.9.30: при движке Imagick оригинал в GD не декодируем вообще — 24-Мп кадр в памяти GD
       занимает сотни мегабайт и на шаред-хостинге упирался в скрытый лимит RSS процесса
       (генерация падала фатально, см. CHANGELOG). Размеры и средний цвет берём самим Imagick:
       при нехватке памяти он сам переходит на дисковый кэш пикселей и не падает. */
    $im = null; // GD-объект оригинала; при Imagick-движке не используется (gd_free(null) безвреден)
    if (img_engine() === 'imagick') {
        try {
            $probe = new Imagick($tmpPath . '[0]'); // [0] — только первый кадр GIF, как в GD-пути
            imagick_auto_orient($probe); // EXIF-поворот, как в gd_load: размеры ниже — уже повёрнутые
            $w = $probe->getImageWidth();
            $h = $probe->getImageHeight();
            if ($w < 1 || $h < 1) {
                throw new RuntimeException('пустой кадр');
            }
            $color = '#cccccc';
            $one = clone $probe;
            $one->resizeImage(1, 1, Imagick::FILTER_BOX, 1.0); // средний цвет, аналог imagescale($im, 1, 1)
            $px = $one->getImagePixelColor(0, 0)->getColor();
            $color = sprintf('#%02x%02x%02x', $px['r'] & 255, $px['g'] & 255, $px['b'] & 255);
            $one->clear();
            $probe->clear();
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось прочитать изображение (возможно, повреждённый файл): ' . $origName);
        }
    } else {
        $im = gd_load($tmpPath);
        if (!$im) {
            throw new RuntimeException('Не удалось прочитать изображение (возможно, слишком большое для памяти PHP): ' . $origName);
        }
        $w = imagesx($im);
        $h = imagesy($im);

        $color = '#cccccc';
        $one = @imagescale($im, 1, 1);
        if ($one) {
            $rgb = imagecolorat($one, 0, 0);
            $color = sprintf('#%02x%02x%02x', ($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255);
            gd_free($one);
        }
    }

    $base = pathinfo($origName, PATHINFO_FILENAME);
    $slug = slugify($base, 60);
    if ($slug === '') {
        $slug = 'photo';
    }
    /* Криптостойкий суффикс: готовые файлы в media/ Apache отдаёт напрямую, минуя PHP,
       а имена из фотокамер (dsc-1234, img-001) предсказуемы — фото из черновиков и
       неопубликованных альбомов угадывались перебором id/ширины/slug (аудит 1.9.13,
       находка 2). Суффикс делает перебор бессмысленным; slug хранится в базе, поэтому
       все URL (существующие и новые) строятся корректно. */
    $slug .= '-' . bin2hex(random_bytes(16));

    if (!is_dir(originals_dir())) {
        @mkdir(originals_dir(), 0700, true);
    }
    @chmod(originals_dir(), 0700);
    /* Сначала файл во временное имя (медленный copy), потом INSERT и мгновенный rename:
       раньше запись в images появлялась ДО копирования, и обрыв процесса между ними
       оставлял «сироту» — строку в базе без файла-оригинала */
    $tmpDest = originals_dir() . '/.up-' . bin2hex(random_bytes(6)) . '.' . $types[$info[2]];
    if (!@copy($tmpPath, $tmpDest)) {
        gd_free($im);
        throw new RuntimeException('Не удалось сохранить файл в storage/originals (проверьте права на запись).');
    }
    @chmod($tmpDest, 0600);
    try {
        $id = db_insert(
            'INSERT INTO images (slug, ext, orig_name, w, h, color, alt, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [$slug, $types[$info[2]], mb_substr($origName, 0, 200), $w, $h, $color, $alt, now()]
        );
    } catch (Throwable $e) {
        @unlink($tmpDest);
        gd_free($im);
        throw $e;
    }
    $img = image_get($id);
    if (!move_file_inplace($tmpDest, image_original_path($img))) {
        db_run('DELETE FROM images WHERE id = ?', [$id]);
        @unlink($tmpDest);
        gd_free($im);
        throw new RuntimeException('Не удалось сохранить файл в storage/originals (проверьте права на запись).');
    }
    @chmod(image_original_path($img), 0600);

    /* webp-гвард по движку (1.9.30): при Imagick решает imagick_webp_supported(), а не GD */
    $canWebp = img_engine() === 'imagick' ? imagick_webp_supported() : webp_supported();
    /* AVIF (1.9.48): кодируется заметно дольше webp, поэтому при загрузке делаем только те же
       два размера (440/1240), что и для webp — остальные ширины родятся on-demand через img.php */
    $canAvif = avif_enabled();
    /* IMG_SKIP_UPLOAD_VARIANTS=1 — аварийный обход для пакетных CLI-импортов на хостингах, где
       CLI-сборка php-imagick нестабильна (зависает в нативных потоках libwebp/libaom), а через
       Apache всё кодируется нормально: фото регистрируются без вариантов, миниатюры затем
       прогреваются из админки («Кэш» → «Прогреть миниатюры») или on-demand через img.php. */
    if (getenv('IMG_SKIP_UPLOAD_VARIANTS')) {
        gd_free($im);
        return $img;
    }
    /* Сначала 1240, потом 440: мелкие варианты при Imagick берут источником готовый 1240-й
       (аудит 1.9.40, п.10) — иначе каждый декодировал многомегапиксельный оригинал заново */
    $formats = ['jpg'];
    if ($canWebp) {
        $formats[] = 'webp';
    }
    if ($canAvif) {
        $formats[] = 'avif';
    }
    foreach ([1240, 440] as $vw) {
        foreach ($formats as $format) {
            if (!image_make_variant($img, $vw, $format, $im)) {
                image_delete($id);
                gd_free($im);
                throw new RuntimeException('Не удалось создать ' . strtoupper($format) . '-превью ' . $vw . ' px: ' . $origName
                    . '. Проверьте свободное место на диске и права на media/.');
            }
        }
    }
    gd_free($im); // декодированный оригинал при пакетной загрузке десятков фото держал бы память до конца запроса
    return $img;
    } finally {
        image_unlock($slot);
    }
}

/**
 * Где используется каждое фото: [id => число мест]. Считает галереи, фото-секции (страниц и заметок),
 * обложки, картинки для соцсетей, иконку сайта и ссылки <img src="/media/ID/…"> в текстовых секциях.
 * Нужна медиатеке, чтобы не удалить фото, которое стоит в статье.
 */
function image_usage_map(bool $fresh = false): array
{
    /* карта строится полными LIKE-сканами текстов — кэшируем на 2 минуты (в сессии и на запрос);
       проверка перед удалением ($fresh = true) всегда считает заново, чтобы не удалить используемое фото */
    static $map = null;
    if ($map !== null && !$fresh) {
        return $map;
    }
    if (!$fresh && session_status() === PHP_SESSION_ACTIVE) {
        $c = $_SESSION['img_usage'] ?? null;
        if (is_array($c) && isset($c['t'], $c['m']) && is_array($c['m']) && time() - (int)$c['t'] < 120) {
            return $map = $c['m'];
        }
    }
    $use = [];
    $add = static function (int $id) use (&$use): void {
        if ($id > 0) {
            $use[$id] = ($use[$id] ?? 0) + 1;
        }
    };
    foreach (db_all('SELECT image_id, COUNT(*) AS n FROM gallery_items GROUP BY image_id') as $r) {
        $use[(int)$r['image_id']] = ($use[(int)$r['image_id']] ?? 0) + (int)$r['n'];
    }
    foreach (db_all('SELECT cover_image_id AS a, og_image_id AS b FROM pages') as $r) {
        $add((int)$r['a']);
        $add((int)$r['b']);
    }
    foreach (db_all('SELECT og_image_id AS a FROM posts') as $r) {
        $add((int)$r['a']);
    }
    /* logo_image_id добавлен в 1.9.35: без него логотип из шапки числился «не используется
       нигде» и мог быть удалён как мусор (ревизия 1.9.34, п.4) */
    foreach (['default_og_image_id', 'favicon_image_id', 'logo_image_id'] as $k) {
        $add((int)setting($k));
    }
    foreach (db_all("SELECT data FROM sections WHERE type = 'photo'") as $r) {
        $d = json_decode((string)$r['data'], true);
        $add((int)($d['image_id'] ?? 0));
    }
    foreach (db_all("SELECT data FROM sections WHERE type = 'pricing'") as $r) {
        $d = json_decode((string)$r['data'], true);
        foreach ((array)($d['items'] ?? []) as $item) {
            if (is_array($item)) {
                $add((int)($item['image_id'] ?? 0));
            }
        }
    }
    foreach (db_all('SELECT poster_image_id FROM videos') as $r) {
        $add((int)$r['poster_image_id']);
    }
    /* картинки, вставленные в HTML прямой ссылкой <img src="/media/ID/…"> (текстовые блоки, описания альбомов, заметки);
       в JSON слэши могут быть экранированы (\/media\/12\/), поэтому разделитель — любой из двух вариантов */
    $scan = static function (string $text) use ($add): void {
        if ($text !== '' && stripos($text, 'media') !== false && preg_match_all('~[\\\\/]media[\\\\/](\d+)[\\\\/]~', $text, $m)) {
            foreach ($m[1] as $one) {
                $add((int)$one);
            }
        }
    };
    foreach (db_all("SELECT data FROM sections WHERE data LIKE '%media%'") as $r) {
        $scan((string)$r['data']);
    }
    foreach (db_all("SELECT body_html AS t FROM pages WHERE body_html LIKE '%media%'") as $r) {
        $scan((string)$r['t']);
    }
    $map = $use;
    /* Кэш в сессии — только для разумного размера медиатеки (аудит 1.9.26): при десятках тысяч
       фото полный usage-map раздувал бы файл сессии на каждый запрос админки. Перешагнули порог —
       живём на кэше запроса (static выше), он бесплатен по хранению. */
    if (session_status() === PHP_SESSION_ACTIVE && count($use) <= 2000) {
        $_SESSION['img_usage'] = ['t' => time(), 'm' => $use];
    }
    return $map;
}

function image_delete(int $id): string
{
    $lock = image_lock($id, true);
    if ($lock === null || $lock === false) {
        throw new RuntimeException('Не удалось заблокировать удаление изображения. Проверьте storage/locks.');
    }
    try {
    $img = image_get($id);
    if (!$img) {
        return '';
    }
    db_tx(function () use ($id): void {
        $ts = now();
        db_run("UPDATE pages SET updated_at = ? WHERE id IN (
            SELECT owner_id FROM gallery_items WHERE owner_type = 'page' AND image_id = ?
            UNION SELECT s.page_id FROM gallery_items gi JOIN sections s ON s.id = gi.owner_id WHERE gi.owner_type = 'section' AND s.owner_type = 'page' AND gi.image_id = ?
            UNION SELECT id FROM pages WHERE cover_image_id = ? OR og_image_id = ?
            UNION SELECT page_id FROM sections WHERE owner_type = 'page' AND type IN ('photo', 'pricing')
                AND (data LIKE '%\"image_id\":" . $id . ",%' OR data LIKE '%\"image_id\":" . $id . "}%'))", [$ts, $id, $id, $id, $id]);
        db_run('UPDATE posts SET updated_at = ? WHERE og_image_id = ?', [$ts, $id]);
        db_run("UPDATE posts SET updated_at = ? WHERE id IN (
            SELECT page_id FROM sections WHERE owner_type = 'post' AND type = 'photo'
                AND (data LIKE '%\"image_id\":" . $id . ",%' OR data LIKE '%\"image_id\":" . $id . "}%')
            UNION SELECT s.page_id FROM gallery_items gi JOIN sections s ON s.id = gi.owner_id
                WHERE gi.owner_type = 'section' AND s.owner_type = 'post' AND gi.image_id = ?)", [$ts, $id]);
        db_run('DELETE FROM gallery_items WHERE image_id = ?', [$id]);
        db_run('UPDATE pages SET cover_image_id = NULL WHERE cover_image_id = ?', [$id]);
        db_run('UPDATE pages SET og_image_id = NULL WHERE og_image_id = ?', [$id]);
        db_run('UPDATE posts SET og_image_id = NULL WHERE og_image_id = ?', [$id]);
        db_run('UPDATE videos SET poster_image_id = NULL WHERE poster_image_id = ?', [$id]); // постер удалённого кадра не «висит» (1.9.48)
        foreach (['default_og_image_id', 'favicon_image_id', 'logo_image_id'] as $k) { // см. примечание в image_usage_map (ревизия 1.9.34, п.4)
            if ((int)setting($k) === $id) {
                set_setting($k, '');
            }
        }
        /* блоки «Фото» и карточки цен ссылались на удалённый кадр — обнуляем ссылки */
        foreach (db_all("SELECT id, data FROM sections WHERE type = 'photo'") as $r) {
            $d = json_decode((string)$r['data'], true);
            if (is_array($d) && (int)($d['image_id'] ?? 0) === $id) {
                $d['image_id'] = 0;
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($d), (int)$r['id']]);
                page_sections_cache_flush(); // секции разных страниц — сбрасываем кэш целиком (1.9.26)
            }
        }
        foreach (db_all("SELECT id, data FROM sections WHERE type = 'pricing'") as $r) {
            $d = json_decode((string)$r['data'], true);
            if (!is_array($d) || !isset($d['items']) || !is_array($d['items'])) {
                continue;
            }
            $changed = false;
            foreach ($d['items'] as &$item) {
                if (is_array($item) && (int)($item['image_id'] ?? 0) === $id) {
                    $item['image_id'] = 0;
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                db_run('UPDATE sections SET data = ? WHERE id = ?', [json_db($d), (int)$r['id']]);
                page_sections_cache_flush();
            }
        }
        db_run('DELETE FROM images WHERE id = ?', [$id]);
        image_cache_flush(); /* image_get($id) выше уже положил строку в кэш */
        gallery_cache_flush(); /* удалились строки gallery_items — кэш галерей протух */
    });
    /* файлы удаляем только после того, как запись в базе действительно удалена */
    $warning = [];
    $media = media_dir() . '/' . $id;
    rrmdir($media);
    clearstatcache(true, $media);
    if (is_dir($media)) {
        app_log_error('image_delete: не удалось удалить каталог ' . $media);
        $warning[] = 'каталог миниатюр';
    }
    $original = image_original_path($img);
    @unlink($original);
    clearstatcache(true, $original);
    if (is_file($original)) {
        app_log_error('image_delete: не удалось удалить оригинал ' . $original);
        $warning[] = 'оригинал';
    }
    site_touch();
    return $warning ? 'Фото удалено из базы, но остались файлы: ' . implode(', ', $warning) . '. Подробности в storage/error.log.' : '';
    } finally {
        image_unlock($lock);
    }
}
