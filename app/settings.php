<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function setting_defaults(): array
{
    return [

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
        'schema_type' => 'LocalBusiness',
        'business_name' => '',

        'business_legal_name' => '',
        'business_inn' => '',
        'business_ogrnip' => '',
        'person_name' => '',
        'person_job' => '',
        'photo_author' => '',
        'photo_copyright' => '',
        'photo_credit' => '',
        'photo_license' => '',

        'person_knows_about' => '',
        'person_awards' => '',
        'city' => '',
        'country' => 'RU',
        'price_range' => '',

        'street_address' => '',
        'postal_code' => '',
        'geo_lat' => '',
        'geo_lng' => '',
        'opening_hours' => '',
        'cities_served' => '',

        'head_code' => '',
        'body_code' => '',

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

        'social_mywed' => '',
        'social_yandex_maps' => '',
        'social_tiktok' => '',
        'social_max' => '',

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
        'font_scale_heading' => '100',
        'font_scale_body' => '100',
        'font_scale_heading_mobile' => '100',
        'font_scale_body_mobile' => '100',
        'sidebar_width' => '240',
        'gallery_gap' => '6',
        'gallery_columns' => '3',
        'gallery_columns_mobile' => '2',
        'photo_corners' => 'rounded',
        'related_title' => 'Другие работы',
        'related_limit' => '3',
        'lightbox' => '1',
        'lightbox_download' => '1',
        'logo_height' => '48',

        'blog_title' => 'Блог',
        'blog_description' => '',
        'blog_post_meta' => '0',
        'blog_meta_section' => '1',
        'blog_meta_published' => '1',
        'blog_meta_updated' => '1',
        'blog_meta_reading' => '1',
        'share_buttons' => '0',
        'fx_grain' => 'off',
        'fx_paper_tone' => 'warm',
        'fx_grain_strength' => 'normal',
        'fx_lofi' => 'off',
        'fx_fields' => 'uneven',
        'fx_number' => '1',
        'fx_hover' => '1',
        'fx_photo_frame' => '0',
        'fx_gallery_prints' => '0',
        'fx_heading_marker' => 'off',
        'fx_link_pencil' => '0',
        'fx_hand_font' => 'serif',
        'fx_pencil_color' => 'red',
        'fx_tape' => '0',
        'fx_lightleak' => '0',
        'fx_datestamp' => '0',
        'fx_datestamp_pos' => 'rb',
        'fx_datestamp_format' => 'dmy_short',
        'fx_datestamp_size' => 'normal',
        'fx_datestamp_color' => 'orange',
        'fx_text_drift' => 'off',
        'post_sections' => '[{"slug":"blog","title":"","description":""}]',

        'jpg_quality' => '82',
        'webp_quality' => '80',
        'avif' => '1',
        'avif_quality' => '50',
        'sharpen' => '50',
    ];
}

function settings_all(bool $reload = false, ?array $set = null): array
{
    static $cache = null;
    if ($set !== null) {

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


        $t = (string)($all[$key] ?? '');
        return in_array($t, ['classic', 'apple'], true) ? $t : 'apple';
    }
    return (string)($all[$key] ?? '');
}

function set_setting(string $key, string $value): void
{

    db_run('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', [$key, $value]);


    if (db()->inTransaction()) {
        $GLOBALS['settings_dirty'] = true;
    } else {
        settings_all(false, [$key => $value]);
    }


    if ($key !== 'site_rev') {
        site_touch();
    }
}
