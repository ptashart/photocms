<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function setting_defaults(): array
{
    return [
        // основное / SEO
        'site_name' => 'Мой сайт',
        'title_template' => '%title% | %site%',
        'home_title' => '',
        'default_description' => '',
        'base_url' => '',
        'lang' => 'ru',
        'locale' => 'ru_RU',
        'default_og_image_id' => '',
        'favicon_image_id' => '',
        'robots_extra' => '',
        'verify_yandex' => '',
        'verify_google' => '',
        'verify_bing' => '',
        'twitter_site' => '',
        'schema_type' => 'Photographer',
        'business_name' => '',
        // реквизиты для разметки организации (1.9.63): legalName, taxID, identifier
        'business_legal_name' => '',
        'business_inn' => '',
        'business_ogrnip' => '',
        'person_name' => '',
        'person_job' => '',
        // экспертность владельца в разметке Person (1.9.65): knowsAbout и award, списки через запятую
        'person_knows_about' => '',
        'person_awards' => '',
        'city' => '',
        'country' => 'RU',
        'price_range' => '',
        // локальное SEO (1.9.43): адрес студии, координаты, часы, города обслуживания
        'street_address' => '',
        'postal_code' => '',
        'geo_lat' => '',
        'geo_lng' => '',
        'opening_hours' => '',
        'cities_served' => '',
        // код
        'head_code' => '',
        'body_code' => '',
        // контакты
        'phone' => '',
        'email' => '',
        'contact_email' => '',
        'tg_bot_token' => '',
        'tg_chat_id' => '',
        'social_vk' => '',
        'social_telegram' => '',
        'social_instagram' => '',
        'social_whatsapp' => '',
        'social_youtube' => '',
        // дополнительные профили (1.9.63): попадают в sameAs разметки и в подвал
        'social_mywed' => '',
        'social_yandex_maps' => '',
        'social_tiktok' => '',
        'social_max' => '',
        // оформление
        'theme' => 'classic',
        'menu_brand' => '',
        'logo_image_id' => '',
        'brand_mode' => 'text',
        'footer_text' => '',
        'footer_col1_title' => '',
        'footer_col1_links' => '',
        'footer_col2_title' => '',
        'footer_col2_links' => '',
        'footer_col3_title' => '',
        'footer_col3_links' => '',
        'footer_col4_title' => '',
        'footer_col4_links' => '',
        'color_bg' => '#ffffff',
        'color_text' => '#252525',
        'font_heading' => 'Tenor Sans',
        'font_body' => 'Golos Text',
        'font_scale_heading' => '100', // масштаб заголовков, % (50–150): 100 — как задумано
        'font_scale_body' => '100', // масштаб основного текста, % (50–150)
        'font_scale_heading_mobile' => '100', // то же для экранов ≤900px (50–150)
        'font_scale_body_mobile' => '100',
        'sidebar_width' => '240',
        'gallery_gap' => '6',
        'gallery_columns' => '3',
        'gallery_columns_mobile' => '2',
        'photo_corners' => 'rounded', // rounded — скруглённые (в яблочной теме), square — прямые углы без рамки
        'related_title' => 'Другие работы',
        'related_limit' => '3',
        'lightbox' => '1',
        'lightbox_download' => '1', // кнопка «Скачать» в просмотрщике фото
        'logo_height' => '48',
        // блог
        'blog_title' => 'Блог',
        'blog_description' => '',
        'blog_post_meta' => '0', // 1 — мета-строка над заголовком заметки (рубрика-чипс + дата), в стиле WedWed
        'blog_meta_section' => '1', // 1 — чипс рубрики в мета-строке (только вид; на JSON-LD не влияет)
        'blog_meta_published' => '1', // 1 — дата публикации в мета-строке (JSON-LD datePublished сохраняется)
        'blog_meta_updated' => '1', // 1 — дата обновления в мета-строке (JSON-LD dateModified сохраняется)
        'blog_meta_reading' => '1', // 1 — «N мин чтения» в мета-строке (JSON-LD wordCount сохраняется)
        'share_buttons' => '0', // 1 — кнопки «Поделиться» (Telegram, VK, WhatsApp, копировать ссылку) в заметках
        'fx_grain' => 'off', // off | paper (тёплый тон и зернистость бумаги на фоне страницы)
        'fx_paper_tone' => 'warm', // clean | warm | cream — теплота бумаги на фоне
        'fx_grain_strength' => 'normal', // subtle | normal | strong — сила зернистости бумаги
        'fx_lofi' => 'off', // off | soft (карточки-отпечатки с неровными полями) | print (плюс наклон и карандашный кружок)
        'fx_tilt' => '4', // амплитуда наклона карточек в десятых градуса (0–30): 4 = ±0.4°, только режим «Печать»
        'fx_shift' => '22', // вертикальный разброс карточек, px (0–40)
        'fx_fields' => 'uneven', // even | uneven | wild — неровность белых полей отпечатков
        'fx_number' => '1', // 1 — карандашный «№ 01» на нижнем поле отпечатка
        'fx_hover' => '1', // 1 — отпечаток распрямляется и приподнимается при наведении
        'fx_photo_frame' => '0', // 1 — белые рамки-отпечатки у фото в текстах страниц и заметках блога
        'fx_gallery_prints' => '0', // 1 — фото в галереях альбомов как отпечатки с полями и номером кадра
        'fx_heading_marker' => 'off', // off | red | graphite — заголовки H2/H3 в текстах, выделенные «маркером»
        'fx_link_pencil' => '0', // 1 — волнистое карандашное подчёркивание ссылок в текстах
        'fx_hand_font' => 'serif', // serif (Georgia) | neucha (рукописный) — шрифт карандашных пометок
        'fx_pencil_color' => 'red', // red | sepia | graphite | blue — цвет карандашного кружка и ссылок-пометок
        'fx_tape' => '0', // 1 — кусочки скотча с рваными краями на верхнем крае части отпечатков (seed)
        'fx_lightleak' => '0', // 1 — тёплая засветка на части кадров галерей и обложек (seed)
        'fx_datestamp' => '0', // 1 — LED-штамп даты СЪЁМКИ из EXIF оригинала (1.9.95); нет EXIF — нет штампа
        'fx_datestamp_pos' => 'rb', // rb | lb | rt | lt — угол кадра
        'fx_datestamp_format' => 'dmy_short', // dmy_short «12 10 26» | dmy_dots «12.10.26» | dmy_full «12.10.2026» | ymd «26 10 12»
        'fx_datestamp_size' => 'normal', // small | normal | large
        'fx_datestamp_color' => 'orange', // orange | red | yellow | white
        'fx_text_drift' => 'off', // off | soft | wild — лёгкие наклоны только заголовков (1.9.95), абзацы чёткие
        'post_sections' => '[{"slug":"blog","title":"","description":""}]',
        // изображения
        'jpg_quality' => '82',
        'webp_quality' => '80',
        'avif' => '1',
        'avif_quality' => '50',
        'sharpen' => '50', // резкость превью после уменьшения, % (0 — выкл; 50 — как было до 1.9.25)
    ];
}

function settings_all(bool $reload = false, ?array $set = null): array
{
    static $cache = null;
    if ($set !== null) {
        /* обновить кэш на месте после записи, без повторного чтения всей таблицы */
        if ($cache !== null) {
            foreach ($set as $k => $v) {
                $cache[(string)$k] = (string)$v;
            }
        }
        return $cache ?? [];
    }
    if ($cache === null || $reload) {
        $cache = setting_defaults();
        try {
            foreach (db_all('SELECT k, v FROM settings') as $r) {
                $cache[$r['k']] = (string)$r['v'];
            }
        } catch (Throwable $e) {
            /* глотаем только «таблицы ещё нет» (сайт не установлен). «База занята» или битая база —
               не повод рендерить страницы с дефолтными настройками (пустой base_url, чужое имя сайта)
               и отдавать их клиенту с кодом 200: пусть будет честная 500. */
            if (stripos($e->getMessage(), 'no such table') === false) {
                throw $e;
            }
        }
    }
    return $cache;
}

function setting(string $key): string
{
    $all = settings_all();
    if ($key === 'theme') {
        /* «Плёночная» тема удалена (1.9.97): любое устаревшее/незнакомое значение
           трактуем как «Яблочную» — сайт не остаётся на несуществующей теме */
        $t = (string)($all[$key] ?? '');
        return in_array($t, ['classic', 'apple'], true) ? $t : 'apple';
    }
    return (string)($all[$key] ?? '');
}

function set_setting(string $key, string $value): void
{
    /* INSERT OR REPLACE — работает на любых версиях SQLite (ON CONFLICT требует SQLite 3.24+, на старых хостингах падает) */
    db_run('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
    /* Внутри транзакции кэш не трогаем: при откате в нём осталось бы незакоммиченное значение.
       Отмечаем «грязность» — db_tx() перечитает настройки после commit и после rollback. */
    if (db()->inTransaction()) {
        $GLOBALS['settings_dirty'] = true;
    } else {
        settings_all(false, [$key => $value]);
    }
    /* правка настроек меняет публичные страницы (тема, контакты, коды) — отмечаем для
       Last-Modified/ETag-логики; site_rev сам через set_setting не пишем — была бы рекурсия */
    if ($key !== 'site_rev') {
        site_touch();
    }
}
