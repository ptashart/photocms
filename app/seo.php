<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function first_text_excerpt(int $pageId, int $max = 155): string
{
    foreach (page_sections($pageId) as $s) {
        if ($s['type'] === 'text' && trim(strip_tags((string)$s['data']['html'])) !== '') {
            return plain_excerpt((string)$s['data']['html'], $max);
        }
    }
    return '';
}

function seo_title(array $page): string
{
    $t = trim((string)$page['seo_title']);
    if ($t !== '') {
        return $t;
    }
    if (!empty($page['is_home'])) {
        $ht = trim(setting('home_title'));
        return $ht !== '' ? $ht : setting('site_name');
    }
    return str_replace(['%title%', '%site%'], [(string)$page['title'], setting('site_name')], setting('title_template'));
}

function seo_description(array $page): string
{
    $d = trim((string)$page['seo_description']);
    if ($d !== '') {
        return $d;
    }
    /* Лимит 155: Google в сниппете показывает ~150–160 знаков — раньше хвост описания
       (170) обрезался многоточием прямо посреди фразы */
    if (trim((string)$page['short_text']) !== '') {
        return str_limit((string)$page['short_text'], 155);
    }
    if ($page['type'] === 'album' && trim(strip_tags((string)$page['body_html'])) !== '') {
        return plain_excerpt((string)$page['body_html'], 155);
    }
    if ($page['type'] !== 'album') {
        $t = first_text_excerpt((int)$page['id'], 155);
        if ($t !== '') {
            return $t;
        }
    }
    /* Последний запасной вариант — название сайта: пустой description означает, что сниппет
       в выдаче соберётся из случайного куска страницы (для главной и альбомов — потеря CTR) */
    $d = trim(setting('default_description'));
    return $d !== '' ? $d : trim(setting('site_name'));
}

function page_breadcrumbs(array $page): array
{
    $crumbs = [['name' => 'Главная', 'url' => '/']];
    if (!empty($page['is_home'])) {
        return $crumbs;
    }
    if (!empty($page['parent_id'])) {
        $par = page_get((int)$page['parent_id']);
        if ($par && (int)$par['is_published']) {
            $crumbs[] = ['name' => (string)$par['title'], 'url' => page_url($par)];
        }
    }
    $crumbs[] = ['name' => (string)$page['title'], 'url' => page_url($page)];
    return $crumbs;
}

/**
 * og:image для выбранного фото: кадр для соцсетей 1200×630 с обрезкой вокруг фокусной
 * точки (1.9.63) — вертикальные фото не уезжают в обрезку мессенджерами по центру среза.
 * Кадр не родился (мелкий оригинал, ошибка декодера) — обычный вариант с честными размерами:
 * размеры от реально выбранной ступени, а не от min(1240, оригинал) — иначе для оригинала
 * 1000 px в og:image:width писали 1000, а файл отдавали 900, и краулеры соцсетей из-за
 * расхождения игнорировали размеры.
 * Общая логика страниц (og_image_for ниже), заметок и разделов блога (post_meta,
 * blog_index_og_image) — до 1.9.67 блог копировал старую ветку без og-кадра (аудит 1.9.67, п.4).
 */
function og_image_meta(array $img, string $altFallback): array
{
    $alt = trim((string)$img['alt']) !== '' ? (string)$img['alt'] : $altFallback;
    $og = og_image_variant($img);
    if ($og !== null) {
        return ['url' => abs_url($og['url']), 'w' => $og['w'], 'h' => $og['h'], 'alt' => $alt];
    }
    $w = (int)max(image_widths($img, 1240));
    return [
        'url' => abs_url(image_url($img, $w, 'jpg')),
        'w' => $w,
        'h' => (int)$img['w'] > 0 ? (int)round((int)$img['h'] * $w / (int)$img['w']) : 0,
        'alt' => $alt,
    ];
}

function og_image_for(array $page): ?array
{
    $img = null;
    if (!empty($page['og_image_id'])) {
        $img = image_get((int)$page['og_image_id']);
    }
    if (!$img) {
        $img = page_cover($page);
    }
    if (!$img && setting('default_og_image_id') !== '') {
        $img = image_get((int)setting('default_og_image_id'));
    }
    if (!$img) {
        return null;
    }
    return og_image_meta($img, (string)$page['title']);
}

function social_links(): array
{
    $defs = [
        'vk' => 'VK', 'telegram' => 'Telegram', 'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp', 'youtube' => 'YouTube',
        /* 1.9.63: профили, которые усиливают sameAs организации */
        'mywed' => 'MyWed', 'yandex_maps' => 'Яндекс Карты', 'tiktok' => 'TikTok', 'max' => 'MAX',
    ];
    $out = [];
    foreach ($defs as $k => $label) {
        $u = normalize_url(setting('social_' . $k)); // javascript:/data: в адресе соцсети отбрасывается и при выводе
        if ($u !== '') {
            $out[] = ['key' => $k, 'label' => $label, 'url' => $u];
        }
    }
    return $out;
}

/**
 * Колонки ссылок в подвале (1–4). Ссылки задаются в настройках построчно: «Текст | /адрес».
 * Возвращает только заполненные колонки: [['title'=>…, 'links'=>[['text'=>…,'url'=>…], …]], …]
 */
function footer_columns(): array
{
    $cols = [];
    for ($i = 1; $i <= 4; $i++) {
        $title = trim(setting('footer_col' . $i . '_title'));
        $links = [];
        foreach (preg_split('~\R~', (string)setting('footer_col' . $i . '_links')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $text = $parts[0];
            $url = trim((string)($parts[1] ?? ''));
            if ($url === '' && preg_match('~^(https?://|/(?![/\\\\])|mailto:|tel:)~i', $text)) {
                $url = $text;
                $text = (string)preg_replace('~^https?://(www\.)?|/$~', '', $url);
            }
            /* «/» разрешён, но НЕ «//» и «/\» — это protocol-relative URL на чужой домен */
            if ($text === '' || !preg_match('~^(https?://|/(?![/\\\\])|#|mailto:|tel:)~i', $url)) {
                continue;
            }
            $links[] = ['text' => $text, 'url' => $url];
        }
        if ($title !== '' || $links) {
            $cols[] = ['title' => $title, 'links' => $links];
        }
    }
    return $cols;
}

function page_meta(array $page): array
{
    $noindex = !empty($page['noindex']) || !(int)$page['is_published'];
    $canonical = trim((string)$page['canonical']);
    if ($canonical === '' || str_starts_with($canonical, '//')) {
        /* «//host» — protocol-relative URL на ЧУЖОЙ сайт: в админке такое значение отклоняется
           (1.9.26), но если попало в базу раньше/извне — подставляем self-canonical, а не чужой домен */
        $canonical = abs_url(page_url($page));
    } elseif ($canonical[0] === '/') {
        $canonical = abs_url($canonical);
    }
    $meta = [
        'title' => seo_title($page),
        'description' => seo_description($page),
        'canonical' => $canonical,
        'noindex' => $noindex,
        'og_type' => 'website',
        'og_image' => og_image_for($page),
    ];
    $meta['jsonld'] = jsonld_blocks($page, $meta);
    return $meta;
}

/** Логотип для Organization/publisher в JSON-LD: картинка соцсетей по умолчанию, иначе favicon. */
function jsonld_logo(): ?array
{
    $img = null;
    if (setting('default_og_image_id') !== '') {
        $img = image_get((int)setting('default_og_image_id'));
    }
    if (!$img && (int)setting('favicon_image_id') > 0) {
        $img = image_get((int)setting('favicon_image_id'));
    }
    if (!$img) {
        return null;
    }
    $w = (int)image_pick_width(image_widths($img), 600);
    return [
        '@type' => 'ImageObject',
        'url' => abs_url(image_url($img, $w, 'jpg')),
        'width' => $w,
        'height' => (int)$img['w'] > 0 ? (int)round((int)$img['h'] * $w / (int)$img['w']) : 0,
    ];
}

/* Короткий блок WebSite — на каждой странице, а не только на главной: блоки страниц и блога
   ссылаются на #website через isPartOf, и части парсеров (Rich Results Test в пограничных
   случаях) лучше видят граф, когда сущность присутствует в том же ответе. Вынесено в функцию
   в 1.9.32: блог собирает JSON-LD вручную (blog.php) и без этой сущности ссылка была висячей. */
function jsonld_website_short(): array
{
    $base = base_url();
    return [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        '@id' => $base . '/#website',
        'url' => $base . '/',
        'name' => setting('site_name'),
    ];
}

/**
 * Часы работы из настройки → массив строк формата schema.org (1.9.43).
 * Дни недели можно писать по-русски («Пн-Пт 10:00-19:00» → «Mo-Fr 10:00-19:00»),
 * интервалы разделяются переводом строки или «;». \b вокруг кириллицы в PCRE не работает
 * без UCP, поэтому границы слова собраны вручную через (?<!\p{L})/(?!\p{L}).
 */
function opening_hours_schema(string $raw): array
{
    static $days = ['пн' => 'Mo', 'вт' => 'Tu', 'ср' => 'We', 'чт' => 'Th', 'пт' => 'Fr', 'сб' => 'Sa', 'вс' => 'Su'];
    $out = [];
    foreach (preg_split('~[;\r\n]+~u', $raw) ?: [] as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        $chunk = (string)preg_replace_callback(
            '~(?<!\p{L})(пн|вт|ср|чт|пт|сб|вс)(?!\p{L})~ui',
            static fn($m) => $days[mb_strtolower($m[1])],
            $chunk
        );
        $out[] = $chunk;
    }
    return $out;
}

/**
 * JSON-LD блоки из секций страницы или заметки: один FAQPage со всеми вопросами разделов
 * «Вопросы и ответы» и по VideoObject на каждый раздел «Видео». Разделы бывают и у
 * альбомов (1.9.52) — обслуживаются так же (аудит 1.9.67, п.3).
 */
function jsonld_section_blocks(array $page, array $meta, ?array $sections = null): array
{
    /* Ранняя отсечка альбомов убрана (аудит 1.9.67, п.3): разделы у альбомов есть с 1.9.52,
       их FAQ/видео видны на странице — значит, и разметка обязана быть, иначе видимое
       содержимое расходится со structured data (прямой запрет Google). */
    $faq = [];
    $blocks = [];
    foreach (($sections ?? page_sections((int)$page['id'])) as $sec) {
        $d = (array)$sec['data'];
        if ($sec['type'] === 'faq') {
            foreach ((array)($d['items'] ?? []) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $q = trim((string)($it['q'] ?? ''));
                /* ответ — плоский текст без переводов строк (как требуют валидаторы FAQPage) */
                $a = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($it['a'] ?? ''))));
                if ($q !== '' && $a !== '') {
                    $faq[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
                }
            }
        } elseif ($sec['type'] === 'video') {
            $v = (int)($d['video_id'] ?? 0) > 0 ? video_get((int)$d['video_id']) : null;
            if (!$v || trim((string)$v['file']) === '') {
                continue;
            }
            $vo = [
                '@context' => 'https://schema.org',
                '@type' => 'VideoObject',
                'name' => trim((string)$v['title']) !== '' ? trim((string)$v['title']) : $meta['title'],
                'uploadDate' => (string)$v['created_at'],
                'contentUrl' => abs_url(video_url($v)),
            ];
            if (trim((string)$v['description']) !== '') {
                $vo['description'] = trim((string)$v['description']);
            }
            $poster = (int)($v['poster_image_id'] ?? 0) > 0 ? image_get((int)$v['poster_image_id']) : null;
            if ($poster) {
                $vo['thumbnailUrl'] = abs_url(image_url($poster, (int)image_pick_width(image_widths($poster), 1240), 'jpg'));
            } elseif (!empty($meta['og_image']['url'])) {
                /* постера нет — картинка соцсетей по умолчанию (та же цепочка, что у og:image:
                   og_image_id страницы → обложка → default_og_image_id); thumbnailUrl у
                   VideoObject рекомендован, без него валидатор Google выдаёт предупреждение (1.9.78) */
                $vo['thumbnailUrl'] = $meta['og_image']['url'];
            }
            $dur = video_duration_iso($v['duration'] ?? 0);
            if ($dur !== '') {
                $vo['duration'] = $dur;
            }
            $blocks[] = $vo;
        }
    }
    if ($faq) {
        /* один FAQPage на страницу со всеми вопросами: несколько блоков-дублей одной
           сущности валидаторы считают ошибкой */
        $blocks[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faq];
    }
    return $blocks;
}

function jsonld_blocks(array $page, array $meta): array
{
    $base = base_url();
    $blocks = [];
    $siteName = setting('site_name');

    if (!empty($page['is_home'])) {
        $blocks[] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $base . '/#website',
            'url' => $base . '/',
            'name' => $siteName,
            'inLanguage' => setting('lang'),
        ];
        /* Типа «Photographer» в schema.org НЕТ (проверено по официальному словарю
           schemaorg-current-https.jsonld: существуют только Photograph и PhotographAction;
           https://schema.org/Photographer отдаёт 404). До 1.9.59 он выводился в паре с
           ProfessionalService «для Google» — валидаторы справедливо ругались на неизвестный
           тип. Теперь: сам тип — всем признанный ProfessionalService (подтип LocalBusiness),
           а профессия уточняется штатным additionalType со ссылкой на определение. */
        $schemaType = setting('schema_type') !== '' ? setting('schema_type') : 'Photographer';
        $isPhotog = $schemaType === 'Photographer';
        if ($isPhotog) {
            $schemaType = 'ProfessionalService';
        }
        $biz = [
            '@context' => 'https://schema.org',
            '@type' => $schemaType,
            '@id' => $base . '/#business',
            'name' => setting('business_name') !== '' ? setting('business_name') : $siteName,
            'url' => $base . '/',
        ];
        if ($isPhotog) {
            $biz['additionalType'] = 'https://en.wikipedia.org/wiki/Photographer';
        }
        if ($meta['og_image']) {
            $biz['image'] = $meta['og_image']['url'];
        }
        $logo = jsonld_logo(); // logo у Organization укрепляет разметку и нужен для publisher в статьях
        if ($logo) {
            $biz['logo'] = $logo;
        }
        if ($meta['description'] !== '') {
            $biz['description'] = $meta['description'];
        }
        $isLocal = in_array($schemaType, ['LocalBusiness', 'ProfessionalService'], true);
        foreach (['telephone' => 'phone', 'email' => 'email'] as $prop => $key) {
            if (trim(setting($key)) !== '') {
                $biz[$prop] = trim(setting($key));
            }
        }
        if ($isLocal && trim(setting('price_range')) !== '') {
            $biz['priceRange'] = trim(setting('price_range'));
        }
        /* реквизиты (1.9.63): legalName/taxID/identifier — доверие поисковиков к организации;
           публикуются только заполненными, пустых полей в разметке нет */
        if (trim(setting('business_legal_name')) !== '') {
            $biz['legalName'] = trim(setting('business_legal_name'));
        }
        if (trim(setting('business_inn')) !== '') {
            $biz['taxID'] = trim(setting('business_inn'));
        }
        if (trim(setting('business_ogrnip')) !== '') {
            $biz['identifier'] = [[
                '@type' => 'PropertyValue',
                'name' => 'ОГРНИП',
                'value' => trim(setting('business_ogrnip')),
            ]];
        }
        /* локальное SEO (1.9.43): адрес собираем из того, что заполнено — у выездного
           фотографа студии может не быть, тогда в разметке только город/страна */
        $addr = [];
        if (trim(setting('street_address')) !== '') {
            $addr['streetAddress'] = trim(setting('street_address'));
        }
        if (trim(setting('city')) !== '') {
            $addr['addressLocality'] = trim(setting('city'));
        }
        if (trim(setting('postal_code')) !== '') {
            $addr['postalCode'] = trim(setting('postal_code'));
        }
        if ($addr) {
            $biz['address'] = ['@type' => 'PostalAddress'] + $addr + ['addressCountry' => trim(setting('country'))];
        }
        /* города обслуживания списком («Города, где работаете»); пусто — как раньше, один город */
        $areas = array_values(array_filter(array_map('trim', explode(',', setting('cities_served')))));
        if (!$areas && trim(setting('city')) !== '') {
            $areas = [trim(setting('city'))];
        }
        if ($areas) {
            $biz['areaServed'] = $areas;
        }
        /* координаты студии — только парой: одинокая широта без долготы валидна синтаксически,
           но бессмысленна для карт */
        if ($isLocal && trim(setting('geo_lat')) !== '' && trim(setting('geo_lng')) !== '') {
            $biz['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float)setting('geo_lat'),
                'longitude' => (float)setting('geo_lng'),
            ];
        }
        $hours = $isLocal ? opening_hours_schema(setting('opening_hours')) : [];
        if ($hours) {
            $biz['openingHours'] = $hours;
        }
        $same = array_column(social_links(), 'url');
        if ($same) {
            $biz['sameAs'] = $same;
        }
        if (trim(setting('person_name')) !== '') {
            /* специализация (knowsAbout) и награды (award) — списки через запятую из настроек (1.9.65);
               пустые поля array_filter выбросит, разметка останется прежней */
            $knows = array_values(array_filter(array_map('trim', explode(',', setting('person_knows_about')))));
            $awards = array_values(array_filter(array_map('trim', explode(',', setting('person_awards')))));
            $biz['founder'] = array_filter([
                '@type' => 'Person',
                'name' => trim(setting('person_name')),
                'jobTitle' => trim(setting('person_job')),
                'url' => $base . '/',
                'knowsAbout' => $knows ?: null,
                'award' => $awards ?: null,
                /* те же профили, что у организации: поисковики связывают личность и бизнес */
                'sameAs' => array_column(social_links(), 'url') ?: null,
            ]);
        }
        /* пакеты и цены из разделов «Пакеты и цены» главной (1.9.63) — hasOfferCatalog,
           как у страниц-услуг: предложения показываются в сниппете организации */
        $homeOffers = jsonld_pricing_offers($page);
        if ($homeOffers) {
            $biz['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'itemListElement' => $homeOffers];
        }
        $blocks[] = $biz;
        $blocks = array_merge($blocks, jsonld_section_blocks($page, $meta));
        $extra = jsonld_extra_blocks((string)($page['jsonld_extra'] ?? ''));
        if ($extra) {
            $blocks = array_merge($blocks, $extra);
        }
        return $blocks;
    }

    $blocks[] = jsonld_website_short();

    $type = trim((string)($page['schema_type'] ?? ''));
    if (!array_key_exists($type, page_schema_types()) || $type === '') {
        $type = $page['type'] === 'album' ? 'ImageGallery' : 'WebPage';
    }
    $wp = [
        '@context' => 'https://schema.org',
        '@type' => $type,
        '@id' => explode('#', $meta['canonical'], 2)[0] . '#page',
        'url' => $meta['canonical'],
        'name' => $meta['title'],
        'inLanguage' => setting('lang'),
        'isPartOf' => ['@id' => $base . '/#website'],
    ];
    if ($meta['description'] !== '') {
        $wp['description'] = $meta['description'];
    }
    /* Связь страницы с бизнесом главной (#business): ContactPage без mainEntity —
       частая ошибка, поисковик не понимает, чьи это контакты (ревью 1.9.75) */
    if ($type === 'ContactPage') {
        $wp['mainEntity'] = ['@id' => $base . '/#business'];
    }
    if ($page['type'] === 'album') {
        $imgs = [];
        foreach (array_slice(gallery_images('page', (int)$page['id']), 0, 30) as $i => $img) {
            /* width/height — от реально отдаваемой ступени (contentUrl ограничен 2520px),
               как в og_image_for(): размеры, расходящиеся с файлом, — сигнал небрежной разметки */
            $w = (int)max(image_widths($img, 2520));
            $imgs[] = [
                '@type' => 'ImageObject',
                'contentUrl' => abs_url(image_url($img, $w, 'jpg')),
                'thumbnailUrl' => abs_url(image_url($img, (int)image_pick_width(image_widths($img), 440), 'jpg')),
                'name' => trim((string)$img['alt']) !== '' ? (string)$img['alt'] : $page['title'] . ' — фото ' . ($i + 1),
                'width' => $w,
                'height' => (int)$img['w'] > 0 ? (int)round((int)$img['h'] * $w / (int)$img['w']) : 0,
            ];
        }
        if ($imgs) {
            $wp['image'] = $imgs;
        }
    }
    $blocks[] = $wp;

    /* страница-услуга (1.9.42): блок Service со ссылкой на организацию главной (#business) —
       перекрёстная ссылка по @id, штатный механизм JSON-LD; сама организация описана на главной */
    if (!empty($page['is_service'])) {
        $svcName = trim((string)($page['service_name'] ?? ''));
        if ($svcName === '') {
            $svcName = trim((string)$page['h1']) !== '' ? trim((string)$page['h1']) : $page['title'];
        }
        $svc = [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
            '@id' => explode('#', $meta['canonical'], 2)[0] . '#service',
            'name' => $svcName,
            'url' => $meta['canonical'],
            'provider' => ['@id' => $base . '/#business'],
        ];
        $svcDesc = trim((string)($page['service_desc'] ?? ''));
        if ($svcDesc === '') {
            $svcDesc = $meta['description'];
        }
        if ($svcDesc !== '') {
            $svc['description'] = $svcDesc;
        }
        $areas = array_values(array_filter(array_map('trim', explode(',', (string)($page['service_area'] ?? '')))));
        if (!$areas && trim(setting('city')) !== '') {
            $areas = [trim(setting('city'))];
        }
        if ($areas) {
            $svc['areaServed'] = $areas;
        }
        if ($meta['og_image']) {
            $svc['image'] = $meta['og_image']['url'];
        }
        $svcPrice = preg_replace('~\D~', '', (string)($page['service_price'] ?? ''));
        if ($svcPrice !== '') {
            $svcCur = (string)($page['service_currency'] ?? 'RUB');
            if (!array_key_exists($svcCur, service_currencies())) {
                $svcCur = 'RUB';
            }
            /* поле в админке — «Цена от»: честнее был бы minPrice, но простой price понимают
               все потребители разметки; «от» остаётся в человекочитаемом описании услуги */
            $svc['offers'] = ['@type' => 'Offer', 'price' => $svcPrice, 'priceCurrency' => $svcCur];
        }
        /* развёрнутые пакеты из разделов «Пакеты и цены» (1.9.63): каталог предложений
           с вилками цен — как на главной у организации */
        $svcOffers = jsonld_pricing_offers($page);
        if ($svcOffers) {
            $svc['hasOfferCatalog'] = ['@type' => 'OfferCatalog', 'itemListElement' => $svcOffers];
        }
        $blocks[] = $svc;
    }

    /* страница без пометки «услуга», но с разделом «Пакеты и цены» (1.9.63):
       отдельный OfferCatalog */
    if (empty($page['is_service']) && ($otherOffers = jsonld_pricing_offers($page))) {
        $blocks[] = [
            '@context' => 'https://schema.org',
            '@type' => 'OfferCatalog',
            '@id' => explode('#', $meta['canonical'], 2)[0] . '#offers',
            'url' => $meta['canonical'],
            'itemListElement' => $otherOffers,
        ];
    }

    $blocks[] = jsonld_breadcrumbs(page_breadcrumbs($page));
    $blocks = array_merge($blocks, jsonld_section_blocks($page, $meta));
    $extra = jsonld_extra_blocks((string)($page['jsonld_extra'] ?? ''));
    if ($extra) {
        $blocks = array_merge($blocks, $extra);
    }
    return $blocks;
}

/**
 * Убирает символы, недопустимые в XML 1.0 (управляющие, кроме табуляции и переводов строк).
 * Один такой байт из текста заметки или названия делал невалидным весь sitemap.xml/feed.xml
 * (ревизия 1.9.34, п.11).
 */
function xml_clean(string $s): string
{
    $r = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return $r === null ? '' : $r; // null — при битом UTF-8: лучше пусто, чем невалидный XML целиком
}

function xml_e(string $s): string
{
    return htmlspecialchars(xml_clean($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** JSON-LD BreadcrumbList из списка крошек [['name'=>…, 'url'=>…], …] (три одинаковых блока сведены сюда). */
function jsonld_breadcrumbs(array $crumbs): array
{
    $list = [];
    foreach ($crumbs as $i => $c) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => abs_url($c['url'])];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/**
 * Подпись данных, влияющих на sitemap.xml и feed.xml. Изменение страниц, заметок, изображений,
 * видео или базовых настроек меняет подпись — и файловый кэш XML пересобирается.
 */
function xml_fresh_key(): string
{
    $p = db_one('SELECT COUNT(*) AS c, COALESCE(MAX(updated_at), 0) AS m FROM pages');
    $b = db_one('SELECT COUNT(*) AS c, COALESCE(MAX(updated_at), 0) AS m FROM posts');
    $i = db_one('SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM images');
    $v = db_one('SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS m FROM videos');
    $s = [];
    /* post_sections обязателен в ключе: при переименовании адреса раздела меняются URL всех
       его заметок — без настройки в подписи sitemap/feed продолжали бы отдавать старые адреса */
    foreach (['site_name', 'blog_title', 'blog_description', 'base_url', 'default_description', 'lang', 'post_sections', 'site_rev'] as $k) {
        $s[] = setting($k);
    }
    /* 1.9.65: llms.txt делится тем же кэшем и печатает данные из настроек (блок «О специалисте»,
       профили соцсетей) — без них в подписи правка настроек не пересобирала бы файл */
    foreach (['person_name', 'person_job', 'person_knows_about', 'person_awards', 'city', 'cities_served',
              'phone', 'email', 'social_vk', 'social_telegram', 'social_instagram', 'social_whatsapp',
              'social_youtube', 'social_mywed', 'social_yandex_maps', 'social_tiktok', 'social_max'] as $k) {
        $s[] = setting($k);
    }
    /* Эффективный базовый адрес — в подпись обязательно: при пустой настройке base_url и без
       allowed_hosts он строится из заголовка Host, то есть подчинён клиенту. Без этого один
       запрос с чужим Host отравлял файловый кэш sitemap/feed для ВСЕХ посетителей и ботов:
       подпись не зависела от фактического хоста (аудит 1.9.13, находка 1). Теперь ядовитый
       XML уходит только самому атакующему, легитимные запросы собирают свой кэш. Полностью
       класс закрывается заполненным «Адресом сайта» (владельцу — см. панель). */
    $s[] = base_url();
    /* APP_VERSION в ключе: формат XML меняется и при обновлении кода (как в 1.9.5 — caption,
       лимиты), а не только при смене контента — иначе после апдейта сайт отдавал бы старый кэш */
    return implode('|', [APP_VERSION, (int)$p['c'], (int)$p['m'], (int)$b['c'], (int)$b['m'], (int)$i['c'], (int)$i['m'], (int)$v['c'], (int)$v['m'], sha1(implode("\0", $s))]);
}

/** Дата последнего изменения контента — для Last-Modified у sitemap.xml/feed.xml. */
function xml_content_lastmod(): int
{
    return (int)max(
        (int)db_val('SELECT COALESCE(MAX(updated_at), 0) FROM pages'),
        (int)db_val('SELECT COALESCE(MAX(updated_at), 0) FROM posts')
    );
}

/**
 * Файловый кэш XML в storage/cache: пока подпись $freshKey() не изменилась, отдаём готовый файл,
 * не перестраивая XML запросами к базе на каждый хит ботов.
 * Подпись хранится первой строкой-комментарием и при отдаче срезается (<?xml обязан быть первым байтом).
 */
function xml_cache_fetch(string $name, callable $build): string
{
    $dir = ROOT . '/storage/cache';
    $file = $dir . '/' . preg_replace('~[^a-z0-9_.-]~i', '', $name);
    $key = sha1(xml_fresh_key());
    $raw = is_file($file) ? (string)@file_get_contents($file) : '';
    $nl = strpos($raw, "\n");
    if ($nl > 0 && substr($raw, 0, $nl) === '<!--' . $key . '-->') {
        return substr($raw, $nl + 1);
    }
    $xml = (string)$build();
    if (@mkdir($dir, 0775, true) || is_dir($dir)) {
        /* атомарная запись, как у вариантов изображений и бэкапов: LOCK_EX защищал только
           от конкурентной записи, а читатель другого процесса мог подхватить файл наполовину —
           бот получал бы обрезанный sitemap (аудит 1.9.12, находка 5) */
        $tmp = $file . '.tmp-' . getmypid();
        if (@file_put_contents($tmp, '<!--' . $key . "-->\n" . $xml, LOCK_EX) !== false) {
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        } else {
            @unlink($tmp);
        }
    }
    return $xml;
}

/** Допустимые типы Schema.org для обычных страниц (альбомы по умолчанию — ImageGallery). */
function page_schema_types(): array
{
    return [
        '' => 'Авто (WebPage, для альбомов — ImageGallery)',
        'WebPage' => 'WebPage — обычная страница',
        'AboutPage' => 'AboutPage — «о нас / обо мне»',
        'ContactPage' => 'ContactPage — контакты',
        'CollectionPage' => 'CollectionPage — подборка/рубрика',
        'ProfilePage' => 'ProfilePage — личная страница',
        'ItemPage' => 'ItemPage — страница одного объекта',
    ];
}

/** Валюты цены услуги (1.9.42): ключ — код ISO 4217 для разметки Offer, значение — подпись в админке. */
function service_currencies(): array
{
    return [
        'RUB' => '₽ — рубль (RUB)',
        'USD' => '$ — доллар (USD)',
        'EUR' => '€ — евро (EUR)',
        'KZT' => '₸ — тенге (KZT)',
        'UAH' => '₴ — гривна (UAH)',
        'BYN' => 'Br — бел. рубль (BYN)',
    ];
}

/** Символ валюты для вывода цены на сайте (1.9.63); неизвестный код возвращается как есть. */
function price_currency_symbol(string $code): string
{
    return ['RUB' => '₽', 'USD' => '$', 'EUR' => '€', 'KZT' => '₸', 'UAH' => '₴', 'BYN' => 'Br'][$code] ?? $code;
}

/**
 * Человекочитаемая цена пакета (1.9.63): «от 15 000 ₽», «40 000–72 000 ₽», «80 000 ₽».
 * Пусто — когда не заполнено даже «от»: тогда цену в карточке не показываем.
 */
function price_format_range(string $from, string $to, string $currency): string
{
    $from = preg_replace('~\D~', '', $from);
    $to = preg_replace('~\D~', '', $to);
    if ($from === '') {
        return '';
    }
    $sym = price_currency_symbol($currency);
    $f = number_format((int)$from, 0, '', ' ');
    if ($to !== '' && (int)$to === (int)$from) {
        return $f . ' ' . $sym;
    }
    if ($to !== '' && (int)$to > (int)$from) {
        return $f . '–' . number_format((int)$to, 0, '', ' ') . ' ' . $sym;
    }
    return 'от ' . $f . ' ' . $sym;
}

/**
 * Предложения Offer из разделов «Пакеты и цены» страницы (1.9.63) — для JSON-LD
 * OfferCatalog у организации (главная) или у услуги (страница с is_service).
 * Берутся только пакеты с названием и ценой «от»: без цены Offer бессмысленен,
 * сочинять её из описания нечестно. Разделы бывают и у альбомов (1.9.52) — их пакеты
 * размечаем так же (аудит 1.9.67, п.3): карточки видны на странице, разметка обязана совпадать.
 */
function jsonld_pricing_offers(array $page): array
{
    $offers = [];
    foreach (page_sections((int)$page['id']) as $sec) {
        if ($sec['type'] !== 'pricing') {
            continue;
        }
        $d = (array)$sec['data'];
        $cur = (string)($d['currency'] ?? 'RUB');
        if (!array_key_exists($cur, service_currencies())) {
            $cur = 'RUB';
        }
        foreach ((array)($d['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $name = trim((string)($it['name'] ?? ''));
            $from = preg_replace('~\D~', '', (string)($it['price_from'] ?? ''));
            if ($name === '' || $from === '') {
                continue;
            }
            $to = preg_replace('~\D~', '', (string)($it['price_to'] ?? ''));
            $o = ['@type' => 'Offer', 'name' => $name];
            $desc = trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)($it['desc'] ?? ''))));
            if ($desc !== '') {
                $o['description'] = $desc;
            }
            /* фото карточки (1.9.69) — то же, что видно на странице */
            $iid = (int)($it['image_id'] ?? 0);
            if ($iid > 0 && ($img = image_get($iid))) {
                $o['image'] = abs_url(image_url($img, image_pick_width(image_widths($img, 1240), 1240), 'jpg'));
            }
            $url = trim((string)($it['url'] ?? ''));
            if ($url === '') {
                $o['url'] = abs_url(page_url($page));
            } elseif (preg_match('~^[a-z][a-z0-9+.\-]*:~i', $url)) {
                /* внешняя ссылка (https://…, tel:) — как есть: abs_url() без проверки схемы
                   склеивал её с доменом сайта в «https://site.ru/https://t.me/…» (аудит 1.9.67, п.1) */
                $o['url'] = $url;
            } elseif ($url[0] === '#') {
                $o['url'] = abs_url(page_url($page)) . $url; // якорь — на этой же странице, а не на главной
            } else {
                $o['url'] = abs_url($url);
            }
            if ($to !== '' && (int)$to > (int)$from) {
                /* вилка — штатный PriceSpecification с min/max; одиночная цена —
                   простой price: его понимают все потребители разметки */
                $o['priceSpecification'] = [
                    '@type' => 'PriceSpecification',
                    'minPrice' => (int)$from,
                    'maxPrice' => (int)$to,
                    'priceCurrency' => $cur,
                ];
            } else {
                $o['price'] = (int)$from;
                $o['priceCurrency'] = $cur;
            }
            $offers[] = $o;
        }
    }
    return $offers;
}

/** Заготовки JSON-LD для админки: подставляются в поле «Свой JSON-LD» и дополняются своими значениями. */
function jsonld_presets(): array
{
    return [
        'FAQPage' => [
            '@type' => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Вопрос первый', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Ответ на первый вопрос.']],
                ['@type' => 'Question', 'name' => 'Вопрос второй', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Ответ на второй вопрос.']],
            ],
        ],
        'HowTo' => [
            '@type' => 'HowTo',
            'name' => 'Название инструкции',
            'step' => [
                ['@type' => 'HowToStep', 'name' => 'Шаг 1', 'text' => 'Что делаем на первом шаге.'],
                ['@type' => 'HowToStep', 'name' => 'Шаг 2', 'text' => 'Что делаем на втором шаге.'],
            ],
        ],
        /* Service из заготовок убран (1.9.42): для услуг есть штатный блок «Услуга»
           на вкладке SEO — двойная разметка одной услуги путала бы поисковиков */
        'VideoObject' => [
            '@type' => 'VideoObject',
            'name' => 'Название видео',
            'description' => 'Короткое описание видео.',
            'thumbnailUrl' => 'https://example.ru/media/1/1240/oblozhka.jpg',
            'uploadDate' => date('Y-m-d'),
            'contentUrl' => 'https://example.ru/video.mp4',
        ],
    ];
}

/** Свои блоки JSON-LD из текстового поля: один объект {...} или массив [{...},{...}]. */
function jsonld_extra_blocks(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $d = json_decode($raw, true);
    if (!is_array($d)) {
        return [];
    }
    if (isset($d['@type']) || isset($d['@context']) || array_key_exists('@graph', $d)) {
        $d = [$d];
    }
    $out = [];
    foreach ($d as $b) {
        if (!is_array($b) || $b === []) {
            continue;
        }
        if (!isset($b['@context'])) {
            $b = ['@context' => 'https://schema.org'] + $b;
        }
        $out[] = $b;
    }
    return $out;
}

function sitemap_xml(): string
{
    $base = base_url();
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $x .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    /* узкие выборки: SELECT * тянул тела страниц/заметок и все поля фото ради пары колонок */
    $pages = db_all('SELECT id, slug, is_home, is_published, noindex, canonical, updated_at, position, title FROM pages WHERE is_published = 1 AND noindex = 0 ORDER BY is_home DESC, position, id');
    /* Картинки всех страниц — двумя запросами вместо отдельного запроса на каждую страницу */
    $secPage = [];
    foreach (db_all("SELECT id, page_id FROM sections WHERE owner_type = 'page'") as $r) {
        $secPage[(int)$r['id']] = (int)$r['page_id'];
    }
    $imgsByPage = [];
    /* Выборка чанками по gi.id (keyset): одним запросом джойн на десятки тысяч фото
       съедал бы память целиком (аудит 1.9.12, находка 7). Порядок внутри страницы
       восстанавливаем usort'ом ниже — выдача не меняется. */
    $lastGi = 0;
    do {
        $chunk = db_all(
            'SELECT gi.id AS gi_id, gi.position AS gi_pos, gi.owner_type AS gi_type, gi.owner_id AS gi_owner, i.id, i.slug, i.w, i.h, i.alt
             FROM gallery_items gi JOIN images i ON i.id = gi.image_id
             WHERE gi.id > ? ORDER BY gi.id LIMIT 5000',
            [$lastGi]
        );
        foreach ($chunk as $r) {
            $lastGi = (int)$r['gi_id'];
            $pid = $r['gi_type'] === 'page' ? (int)$r['gi_owner'] : ($secPage[(int)$r['gi_owner']] ?? 0);
            /* Не больше 500 картинок на URL (Google позволяет 1000): на портфолио с огромными
               альбомами это защита от раздувания файла — лимит одного sitemap 50 МБ несжатого */
            if ($pid > 0 && count($imgsByPage[$pid] ?? []) < 500) {
                $imgsByPage[$pid][] = $r;
            }
        }
    } while (count($chunk) === 5000);
    /* Одиночные секции «Фото» (не галерея) в image sitemap раньше не попадали вовсе
       (аудит 1.9.26, SEO-находка 1): картинка была в HTML страницы, но поисковик не получал
       image-подсказку. Добавляем их к странице-владельцу, пропуская уже учтённые галереями. */
    $seen = [];
    foreach ($imgsByPage as $pidSeen => $listSeen) {
        foreach ($listSeen as $rowSeen) {
            $seen[$pidSeen][(int)$rowSeen['id']] = true;
        }
    }
    $photoRef = [];
    foreach (db_all("SELECT page_id, data FROM sections WHERE type = 'photo' AND owner_type = 'page'") as $r) {
        $d = json_decode((string)$r['data'], true);
        $iid = (int)($d['image_id'] ?? 0);
        $pid = (int)$r['page_id'];
        if ($iid > 0 && $pid > 0 && !isset($seen[$pid][$iid]) && count($imgsByPage[$pid] ?? []) < 500) {
            $photoRef[$pid][$iid] = true;
        }
    }
    if ($photoRef) {
        $photoIds = [];
        foreach ($photoRef as $pid => $set) {
            foreach (array_keys($set) as $iid) {
                $photoIds[$iid] = true;
            }
        }
        foreach (db_all_in('SELECT id, slug, w, h, alt FROM images WHERE id IN ({IN})', array_keys($photoIds)) as $imgRow) {
            foreach ($photoRef as $pid => $set) {
                if (isset($set[(int)$imgRow['id']])) {
                    /* gi_pos-полей у секции нет — usort ниже устойчив к отсутствию ключей (null == null) */
                    $imgsByPage[$pid][] = $imgRow;
                }
            }
        }
    }
    foreach ($imgsByPage as &$list) { // чанки шли по gi.id — возвращаем порядок по position
        /* у фото из секций «Фото» (добавлены выше, аудит 1.9.26) gi_pos/gi_id нет —
           сортируем их после галерейных, ?? гасит undefined key */
        usort($list, static fn($a, $b) => (($a['gi_pos'] ?? PHP_INT_MAX) <=> ($b['gi_pos'] ?? PHP_INT_MAX)) ?: (($a['gi_id'] ?? 0) <=> ($b['gi_id'] ?? 0)));
    }
    unset($list);
    /* Страховочный бюджет URL: формальный лимит файла — 50 000 <url>. Для этого движка
       недостижим, но дешёвый счётчик лучше, чем однажды отдать битый по правилам файл */
    $urlBudget = 45000;
    foreach ($pages as $p) {
        if ($urlBudget-- <= 0) {
            break;
        }
        $own = $base . page_url($p);
        $canon = trim((string)$p['canonical']);
        if ($canon !== '' && rtrim($canon, '/') !== rtrim($own, '/') && rtrim($canon, '/') !== rtrim(page_url($p), '/')) {
            continue;
        }
        $x .= "  <url>\n    <loc>" . xml_e($own) . "</loc>\n";
        if ((int)$p['updated_at'] > 0) {
            $x .= '    <lastmod>' . date('c', (int)$p['updated_at']) . "</lastmod>\n";
        }
        foreach ($imgsByPage[(int)$p['id']] ?? [] as $img) {
            $x .= '    <image:image><image:loc>' . xml_e($base . image_large_url($img)) . '</image:loc>';
            /* caption (расширенная подпись для image-поиска) — только когда есть осмысленный alt */
            $alt = trim((string)$img['alt']);
            $x .= '<image:title>' . xml_e($alt !== '' ? $alt : $p['title']) . '</image:title>';
            if ($alt !== '') {
                $x .= '<image:caption>' . xml_e($alt) . '</image:caption>';
            }
            $x .= "</image:image>\n";
        }
        $x .= "  </url>\n";
    }

    // заметки: списки разделов и сами заметки
    /* картинки заметок — из их секций (с 1.9.54 заметки собраны из секций, как страницы):
       фото-секции по data, галереи — через gallery_items */
    $publishedPosts = db_all('SELECT id, slug, title, section, updated_at, published_at, created_at, canonical, noindex FROM posts WHERE is_published = 1 ORDER BY ' . POST_TS_SQL . ' DESC, id DESC');
    $posts = array_values(array_filter($publishedPosts, static fn($p) => !(int)$p['noindex']));
    $pidToIdx = [];
    foreach ($posts as $i => $p) {
        $pidToIdx[(int)$p['id']] = $i;
    }
    $imgsByPost = [];
    $needImg = [];
    $addPostImg = static function (int $postId, int $iid) use (&$imgsByPost, &$needImg, $pidToIdx): void {
        if ($iid <= 0 || !isset($pidToIdx[$postId])) {
            return;
        }
        $i = $pidToIdx[$postId];
        if (count($imgsByPost[$i] ?? []) < 100) { // как у страниц: не больше 100 картинок на URL
            $imgsByPost[$i][$iid] = true;
            $needImg[$iid] = true;
        }
    };
    foreach (db_all("SELECT page_id, data FROM sections WHERE owner_type = 'post' AND type = 'photo' AND data LIKE '%\"image_id\"%'") as $r) {
        $d = json_decode((string)$r['data'], true);
        $addPostImg((int)$r['page_id'], (int)($d['image_id'] ?? 0));
    }
    foreach (db_all("SELECT s.page_id AS pid, gi.image_id AS iid FROM gallery_items gi JOIN sections s ON s.id = gi.owner_id WHERE gi.owner_type = 'section' AND s.owner_type = 'post'") as $r) {
        $addPostImg((int)$r['pid'], (int)$r['iid']);
    }
    $postImgRows = [];
    if ($needImg) {
        foreach (db_all_in('SELECT id, slug, w, h, alt FROM images WHERE id IN ({IN})', array_keys($needImg)) as $r) {
            $postImgRows[(int)$r['id']] = $r;
        }
    }
    foreach (post_sections() as $s) {
        if ($urlBudget-- <= 0) {
            break;
        }
        $last = 0;
        $count = 0;
        foreach ($publishedPosts as $p) {
            if ((string)($p['section'] ?? 'blog') === $s['slug']) {
                $last = max($last, (int)$p['updated_at']);
                $count++;
            }
        }
        if ($count === 0) {
            continue; // пустой список заметок в sitemap не нужен
        }
        $x .= "  <url>\n    <loc>" . xml_e($base . section_base($s)) . "</loc>\n";
        if ($last > 0) {
            $x .= '    <lastmod>' . date('c', $last) . "</lastmod>\n";
        }
        $x .= "  </url>\n";
    }
    foreach ($posts as $i => $p) {
        if ($urlBudget-- <= 0) {
            break;
        }
        $own = $base . post_url($p);
        /* как у страниц: canonical на другой адрес — заметку из sitemap убираем (1.9.55) */
        $canon = trim((string)($p['canonical'] ?? ''));
        if ($canon !== '' && rtrim($canon, '/') !== rtrim($own, '/') && rtrim($canon, '/') !== rtrim(post_url($p), '/')) {
            continue;
        }
        $x .= "  <url>\n    <loc>" . xml_e($own) . "</loc>\n";
        if ((int)$p['updated_at'] > 0) {
            $x .= '    <lastmod>' . date('c', (int)$p['updated_at']) . "</lastmod>\n";
        }
        foreach (array_keys($imgsByPost[$i] ?? []) as $iid) {
            $img = $postImgRows[$iid] ?? null;
            if (!$img) {
                continue; // фото удалено из медиатеки, а ссылка в тексте осталась
            }
            $x .= '    <image:image><image:loc>' . xml_e($base . image_large_url($img)) . '</image:loc>';
            $alt = trim((string)$img['alt']);
            $x .= '<image:title>' . xml_e($alt !== '' ? $alt : (string)$p['title']) . '</image:title>';
            if ($alt !== '') {
                $x .= '<image:caption>' . xml_e($alt) . '</image:caption>';
            }
            $x .= "</image:image>\n";
        }
        $x .= "  </url>\n";
    }
    return $x . "</urlset>\n";
}

function robots_txt(): string
{
    /* Disallow: /admin/ оставлен и при спрятанной админке (admin_path в config.php): это
       безобидный «макет» — настоящий секретный адрес в robots.txt НИКОГДА не пишем,
       иначе он стал бы публичным (1.9.27). /dl.php закрыт превентивно (1.9.32): скачивание
       и так отвечает 403 без сессии и публичных ссылок на него нет, но если URL когда-нибудь
       утечёт, робот не станет тратить на него обход. */
    $t = "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /img.php\nDisallow: /dl.php\n";
    /* Clean-param убрана в 1.9.37: Яндекс исключил директиву из документации ещё в 2018 и игнорирует
       её. Склейку дублей с utm/fbclid/gclid/yclid делает 301-редирект в index.php — этого достаточно. */
    $extra = trim(setting('robots_extra'));
    if ($extra !== '') {
        $t .= $extra . "\n\n";
    }
    return $t . 'Sitemap: ' . base_url() . "/sitemap.xml\n";
}

/**
 * llms.txt (1.9.64) — маркдаун-карта сайта для языковых моделей (llmstxt.org).
 * Стандарт пока неофициальный: AI-краулеры его не подтвердили, но файл безвреден,
 * а собирается из тех же данных, что sitemap, — пусть лежит на будущее.
 * Правила видимости те же, что в sitemap_xml(): только опубликованные и индексируемые,
 * с чужим canonical не включаем.
 */
function llms_txt(): string
{
    $base = base_url();
    /* Одна строка на элемент: маркдаун-списки не терпят переносов в описании, а скобки
       в заголовке ломали бы синтаксис ссылки */
    $line = static function (string $s): string {
        $s = trim(preg_replace('~\s+~u', ' ', strip_tags($s)));
        return strtr($s, ['[' => '(', ']' => ')']);
    };
    $home = db_all("SELECT seo_description, short_text FROM pages WHERE is_home = 1 AND is_published = 1 LIMIT 1")[0] ?? null;
    $summary = $line((string)($home['seo_description'] ?? '') !== '' ? (string)$home['seo_description'] : (string)($home['short_text'] ?? ''));
    $t = '# ' . $line(setting('site_name')) . "\n\n";
    if ($summary !== '') {
        $t .= '> ' . $summary . "\n\n";
    }
    /* Блок «О специалисте» (1.9.65): рукописные llms.txt конкурентов начинаются с эксперта
       и преимуществ — собираем то же самое из настроек, руками обновлять не нужно.
       Каждая строка выводится, только если заполнено соответствующее поле. */
    $about = [];
    $person = trim(setting('person_name'));
    if ($person !== '') {
        $who = $person . (trim(setting('person_job')) !== '' ? ' — ' . trim(setting('person_job')) : '');
        $geo = array_values(array_filter(array_map('trim', explode(',', setting('cities_served')))));
        if (!$geo && trim(setting('city')) !== '') {
            $geo = [trim(setting('city'))];
        }
        if ($geo) {
            $who .= '. География работы: ' . implode(', ', $geo);
        }
        $about[] = '- ' . $line($who);
    }
    $knows = array_values(array_filter(array_map('trim', explode(',', setting('person_knows_about')))));
    if ($knows) {
        $about[] = '- Специализация: ' . $line(implode(', ', $knows));
    }
    $awards = array_values(array_filter(array_map('trim', explode(',', setting('person_awards')))));
    if ($awards) {
        $about[] = '- Награды и статусы: ' . $line(implode(', ', $awards));
    }
    $contacts = array_filter([trim(setting('phone')), trim(setting('email'))]);
    if ($contacts) {
        $about[] = '- Контакты: ' . $line(implode(', ', $contacts));
    }
    $profiles = [];
    foreach (social_links() as $sl) {
        $profiles[] = $sl['label'] . ' (' . $sl['url'] . ')';
    }
    if ($profiles) {
        $about[] = '- Профили: ' . implode(', ', $profiles);
    }
    if ($about) {
        $t .= "## О специалисте\n\n" . implode("\n", $about) . "\n\n";
    }
    $pages = db_all('SELECT type, title, slug, is_home, canonical, seo_description, short_text FROM pages WHERE is_published = 1 AND noindex = 0 ORDER BY is_home DESC, position, id');
    $groups = ['page' => [], 'album' => []];
    foreach ($pages as $p) {
        $own = $base . page_url($p);
        $canon = trim((string)$p['canonical']);
        if ($canon !== '' && rtrim($canon, '/') !== rtrim($own, '/') && rtrim($canon, '/') !== rtrim(page_url($p), '/')) {
            continue;
        }
        $desc = trim((string)$p['seo_description']) !== '' ? (string)$p['seo_description'] : (string)$p['short_text'];
        $item = '- [' . $line((string)$p['title']) . '](' . $own . ')';
        if (($desc = $line($desc)) !== '') {
            $item .= ': ' . $desc;
        }
        $groups[$p['type'] === 'album' ? 'album' : 'page'][] = $item;
    }
    if ($groups['page']) {
        $t .= "## Страницы\n\n" . implode("\n", $groups['page']) . "\n\n";
    }
    if ($groups['album']) {
        $t .= "## Портфолио\n\n" . implode("\n", $groups['album']) . "\n\n";
    }
    $posts = db_all('SELECT title, slug, section, published_at, created_at, updated_at, canonical, excerpt, seo_description FROM posts WHERE is_published = 1 AND noindex = 0 ORDER BY ' . POST_TS_SQL . ' DESC, id DESC');
    $items = [];
    foreach ($posts as $p) {
        $own = $base . post_url($p);
        $canon = trim((string)($p['canonical'] ?? ''));
        if ($canon !== '' && rtrim($canon, '/') !== rtrim($own, '/') && rtrim($canon, '/') !== rtrim(post_url($p), '/')) {
            continue;
        }
        $desc = trim((string)($p['seo_description'] ?? '')) !== '' ? (string)$p['seo_description'] : (string)($p['excerpt'] ?? '');
        $item = '- [' . $line((string)$p['title']) . '](' . $own . ')';
        if (($desc = $line($desc)) !== '') {
            $item .= ': ' . $desc;
        }
        $items[] = $item;
    }
    if ($items) {
        $t .= "## Заметки\n\n" . implode("\n", $items) . "\n\n";
    }
    return $t;
}
