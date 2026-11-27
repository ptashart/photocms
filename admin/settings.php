<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

if (!defined('ROOT')) {
    http_response_code(404);
    exit;
}

function settings_spec(): array
{
    return [
        'Основное' => [
            'site_name' => ['text', 'Название сайта', 'Используется в title, Open Graph и разметке.'],
            'menu_brand' => ['text', 'Надпись над меню', 'Необязательно. Если пусто — над меню ничего нет (на телефоне слева от кнопки меню).'],
            'logo_image_id' => ['image', 'Логотип над меню', 'Необязательно. Лучше PNG с прозрачным фоном, шириной от 300 px.'],
            'brand_mode' => ['select', 'Показывать над меню', 'Если выбранное невозможно (логотип не загружен или надпись пуста), показывается то, что есть.', ['text' => 'Только надпись', 'logo' => 'Только логотип', 'both' => 'Логотип и надпись']],
            'logo_height' => ['number', 'Высота логотипа, px (яблочная тема)', '24–80. Применяется только при яблочной теме.'],
            'footer_text' => ['textarea', 'Текст в подвале', 'Например: © 2026 Имя Фамилия. Переносы строк сохраняются.'],
            'lang' => ['text', 'Язык сайта (lang)', 'ru, en …'],
            'locale' => ['text', 'Локаль для соцсетей (og:locale)', 'ru_RU, en_US …'],
            'base_url' => ['text', 'Адрес сайта с https://', 'Например https://example.ru — без слэша в конце. От него строятся canonical, sitemap, Open Graph. Обязательно заполните после переезда на боевой домен.'],
        ],
        'SEO' => [
            'title_template' => ['text', 'Шаблон title', 'Для страниц без своего Title. %title% — название страницы, %site% — название сайта.'],
            'home_title' => ['text', 'Title главной', 'Полный заголовок главной страницы в выдаче. Свой Title на вкладке SEO главной имеет приоритет.'],
            'default_description' => ['textarea', 'Описание по умолчанию', 'Используется, если у страницы нет своего описания и взять текст неоткуда.'],
            'default_og_image_id' => ['image', 'Картинка для соцсетей по умолчанию', 'Показывается в превью ссылок, если у страницы нет фото.'],
            'site_alternate_name' => ['text', 'Альтернативное название сайта', 'Необязательно. Например: «Свадебный фотограф Иван Петров». Уйдёт в WebSite.alternateName.'],
            'favicon_image_id' => ['image', 'Иконка сайта (favicon)', 'Лучше квадратная PNG от 180×180. Будет доступна как /favicon.ico.'],
            'verify_yandex' => ['text', 'Яндекс.Вебмастер: код подтверждения', 'Только значение content из мета-тега yandex-verification.'],
            'verify_google' => ['text', 'Google Search Console: код подтверждения', 'Только значение content из мета-тега google-site-verification.'],
            'verify_bing' => ['text', 'Bing Webmaster: код подтверждения', 'Значение content из мета-тега msvalidate.01.'],
            'robots_extra' => ['textarea', 'Дополнительно в robots.txt', 'Добавляется перед строкой Sitemap. Ссылка на sitemap.xml и запрет /admin/ уже есть.'],
            'twitter_site' => ['text', 'Twitter/X: аккаунт сайта', 'Необязательно. Например @ivanov_foto — уйдёт в мета-тег twitter:site.'],
        ],
        'Организация (разметка)' => [
            'schema_type' => ['select', 'Тип организации (schema.org)', 'Для фотографа по умолчанию используется LocalBusiness с профессией в additionalType. Photographer в schema.org не существует.', ['LocalBusiness' => 'LocalBusiness (локальный бизнес)', 'ProfessionalService' => 'ProfessionalService (старые установки)', 'Organization' => 'Organization']],
            'business_name' => ['text', 'Название для разметки', 'Имя фотографа или название бизнеса, а не профессия. Если пусто — название сайта. Если здесь дословно повторена профессия, CMS подставит имя владельца, а альтернативное название сайта сохранит в alternateName.'],
            'person_name' => ['text', 'Имя владельца', 'Например: Иван Петров.'],
            'person_job' => ['text', 'Профессия', 'Например: Свадебный и портретный фотограф.'],
            'person_url' => ['text', 'Страница владельца', 'Необязательно. Например: /about_me или адрес личного сайта. Пусто — главная страница.'],
            'person_image_id' => ['image', 'Портрет владельца для разметки', 'Необязательно. Используется только в Person.image, не заменяет логотип сайта.'],
            'person_same_as' => ['textarea', 'Личные профили владельца (sameAs)', 'Необязательно. По одной ссылке на строку: личный сайт, MyWed и другие страницы именно этого человека. Ссылки организации выше не копируются автоматически.'],
            'person_knows_about' => ['text', 'Специализация (через запятую)', 'Необязательно. Например: свадебная фотография, плёночная фотография, портретная съёмка. Уйдёт в разметку владельца (knowsAbout) — поисковики поймут, в чём вы эксперт.'],
            'person_awards' => ['text', 'Награды и статусы (через запятую)', 'Необязательно и только реальные: место в рейтинге MyWed, премии, публикации, членство в ассоциациях. Уйдут в разметку владельца (award). Выдуманные регалии поисковики распознают по независимым источникам — это хуже, чем пустое поле.'],
            'city' => ['text', 'Город', 'Помогает локальной выдаче. Например: Москва.'],
            'cities_served' => ['text', 'Города, где работаете', 'Через запятую: Москва, Санкт-Петербург. Уйдут в разметку организации (areaServed). Если пусто — используется один город выше.'],
            'street_address' => ['text', 'Адрес студии (улица, дом)', 'Необязательно — если принимаете клиентов в студии. Например: ул. Примерная, д. 5, оф. 12. Уйдёт в разметку и в подвал сайта.'],
            'postal_code' => ['text', 'Индекс студии', 'Необязательно. Например: 123456.'],
            'geo_lat' => ['text', 'Координаты студии: широта', 'Необязательно. Скопируйте из Яндекс.Карт: правый клик по точке — числа вида 55.751244. Работает вместе с долготой.'],
            'geo_lng' => ['text', 'Координаты студии: долгота', 'Например: 37.618423.'],
            'opening_hours' => ['text', 'Часы работы', 'Необязательно. Например: Пн-Пт 10:00-19:00, Сб 11:00-16:00. Дни недели можно писать по-русски — в разметку уйдёт формат schema.org.'],
            'country' => ['text', 'Страна (код)', 'RU, BY, KZ …'],
            'price_range' => ['text', 'Ценовой диапазон', 'Необязательно. Формат свободный: «₽₽» или конкретнее — «от 15 000 ₽».'],
            'business_legal_name' => ['text', 'Юрлицо для разметки (legalName)', 'Необязательно. Например: ИП Иванова Анна Сергеевна. Усиливает доверие поисковиков к организации.'],
            'business_inn' => ['text', 'ИНН', 'Необязательно, только цифры. Уйдёт в разметку организации (taxID).'],
            'business_ogrnip' => ['text', 'ОГРНИП / ОГРН', 'Необязательно, только цифры. Уйдёт в разметку организации (identifier).'],
        ],
        'Контакты и соцсети' => [
            'phone' => ['text', 'Телефон', 'Показывается в подвале и в разметке.'],
            'email' => ['text', 'Почта', ''],
            'contact_email' => ['text', 'Почта для заявок из формы связи', 'Куда отправлять сообщения из раздела «Форма связи». Пусто — на основную почту выше.'],
            'tg_bot_token' => ['text', 'Telegram-бот для заявок (токен)', 'Необязательно, дублирование заявок в Telegram. Создайте бота через @BotFather, впишите токен.'],
            'tg_chat_id' => ['text', 'Telegram chat_id', 'Напишите своему боту любое сообщение, затем откройте api.telegram.org/bot<ТОКЕН>/getUpdates и возьмите chat → id.'],
            'social_vk' => ['text', 'ВКонтакте (ссылка)', ''],
            'social_telegram' => ['text', 'Telegram (ссылка)', ''],
            'social_instagram' => ['text', 'Instagram (ссылка)', ''],
            'social_whatsapp' => ['text', 'WhatsApp (ссылка)', ''],
            'social_youtube' => ['text', 'YouTube (ссылка)', ''],
            'social_mywed' => ['text', 'MyWed (ссылка)', 'Профиль вида https://mywed.com/ru/photographer/…. Уйдёт в sameAs разметки.'],
            'social_yandex_maps' => ['text', 'Яндекс Карты: организация (ссылка)', 'Ссылка на карточку организации вида https://yandex.ru/maps/org/…. Уйдёт в sameAs разметки.'],
            'social_tiktok' => ['text', 'TikTok (ссылка)', ''],
            'social_max' => ['text', 'MAX (ссылка)', 'Мессенджер MAX: ссылка на канал/чат.'],
        ],
        'Оформление' => [
            'theme' => ['select', 'Тема оформления', '«Яблочная» — липкая полупрозрачная планка меню сверху, округлые углы, кнопки-пилюли. Логика сайта, тексты, шрифты и цвета работают во всех темах.', ['classic' => 'Классическая (меню слева)', 'apple' => 'Яблочная (меню сверху)']],
            'color_bg' => ['color', 'Цвет фона', ''],
            'color_text' => ['color', 'Цвет текста', ''],
            'font_heading' => ['text', 'Шрифт заголовков', 'В комплекте: Tenor Sans. Другой шрифт можно загрузить через tools/download_fonts.php (см. README).'],
            'font_body' => ['text', 'Шрифт текста', ''],
            'font_scale_heading' => ['number', 'Размер заголовков, %', '50–150. 100 — как задумано дизайном. Если заголовки кажутся крупными, поставьте 85–90. Действует на все заголовки сайта (H1–H4, названия заметок).'],
            'font_scale_body' => ['number', 'Размер основного текста, %', '50–150. 100 — по умолчанию. Действует на тексты страниц и заметок.'],
            'font_scale_heading_mobile' => ['number', 'Размер заголовков на телефоне, %', '50–150. Отдельное значение для экранов до 900 px — обычно на мобильном заголовки хочется скромнее, чем на компьютере.'],
            'font_scale_body_mobile' => ['number', 'Размер текста на телефоне, %', '50–150. Отдельное значение для экранов до 900 px.'],
            'sidebar_width' => ['number', 'Ширина колонки меню, px', '160–360.'],
            'gallery_gap' => ['number', 'Расстояние между фото, px', '0–40.'],
            'gallery_columns' => ['select', 'Колонок в галереях (компьютер)', 'Можно переопределить в конкретном альбоме.', ['2' => '2', '3' => '3', '4' => '4', '5' => '5']],
            'gallery_columns_mobile' => ['select', 'Колонок в галереях (телефон)', 'Одна колонка лучше показывает портреты и детали. Две подходят для плотного репортажа. Сохранённое значение не меняется при обновлении CMS.', ['1' => '1', '2' => '2']],
            'photo_corners' => ['select', 'Углы у фотографий', 'Действует на все фото сайта: галереи, обложки альбомов, карточки, фото в заметках, блок «Пакеты и цены». «Скруглённые» — как сейчас в яблочной теме. «Прямые» — углы прямые, без рамок. Крупный просмотр фото не меняется.', ['rounded' => 'Скруглённые', 'square' => 'Прямые']],
            'related_title' => ['text', 'Заголовок блока «Другие работы»', 'Пусто — без заголовка.'],
            'related_limit' => ['number', 'Альбомов в блоке «Другие работы»', '2–6, берутся ближайшие по порядку в разделе. Блок компактный и по центру — карточки не растягиваются на всю ширину.'],
            'lightbox' => ['check', 'Открывать фото крупно по клику', 'Общее правило для всех фото и галерей. Отдельно для каждого фото можно выбрать другое поведение — в разделе «Фото»/«Галерея» на странице и при вставке фото в заметку блога.'],
            'lightbox_download' => ['check', 'Скачивание фото посетителями', 'Вкл.: в крупном просмотре есть кнопка «Скачать» (отдаётся самый большой публичный размер до 2520 px, исходники сайт не отдаёт). Выкл.: кнопки нет, плюс блокируются правый клик, перетаскивание и долгий тап по фото. Полной защиты не существует: через инструменты разработчика показанный кадр всё равно можно сохранить.'],
            'blog_post_meta' => ['check', 'Мета-строка над заголовком заметки', 'Вкл.: над H1 заметки появляются чипс рубрики, дата и время чтения (как в крупных блогах). Выкл.: скромная строка даты под заголовком, как раньше.'],
            'blog_meta_section' => ['check', 'Мета-строка: рубрика', 'Чипс раздела (например, «Блог») в мета-строке — показывается в любом режиме: и в полной строке над заголовком, и в короткой строке даты. Влияет только на внешний вид, не на разметку.'],
            'blog_meta_published' => ['check', 'Мета-строка: дата публикации', 'Дата первой публикации заметки. Отключение влияет только на вид: datePublished в JSON-LD для поисковиков остаётся.'],
            'blog_meta_updated' => ['check', 'Мета-строка: дата обновления', 'Показывать «обновлено …», если заметка правилась после публикации. Отключение влияет только на вид: dateModified в JSON-LD остаётся.'],
            'blog_meta_reading' => ['check', 'Мета-строка: время чтения', '«N мин чтения» по числу слов. Отключение влияет только на вид: wordCount в JSON-LD остаётся.'],
            'share_buttons' => ['check', 'Кнопки «Поделиться» в заметках', 'Вкл.: в заметках блога — Telegram, ВКонтакте, WhatsApp и «Копировать ссылку». Чистые ссылки, без сторонних скриптов соцсетей.'],
            'fx_tape' => ['check', 'Скотч с рваными краями', 'Кусочки полупрозрачного скотча с рваными краями на верхнем крае части карточек и фото галерей (примерно каждый пятый, выбор стабилен). Работает на любой теме.'],
            'fx_stamp_once' => ['check', 'Свой текст штампа только на первом кадре галереи', 'Включено по умолчанию: меньше повторов, фотографии важнее подписи. Отключите, чтобы показывать текст на каждом кадре. Одиночные фото сохраняют штамп; на EXIF-даты настройка не влияет.'],
            'fx_datestamp' => ['check', 'LED-штамп даты на кадрах', 'Только честная дата съёмки из EXIF оригинала — не дата загрузки файла. Если в файле даты нет, штамп не показывается: лучше никак, чем неверно. На конкретной странице можно задать свой текст штампа (вкладка «Страница» в редакторе). Галереи, контактный лист, лента и одиночные фото; слайдшоу не штампуется. Работает на любой теме.'],
            'fx_datestamp_pos' => ['select', 'Штамп даты: угол', 'В каком углу кадра стоит дата.', ['rb' => 'Справа снизу', 'lb' => 'Слева снизу', 'rt' => 'Справа сверху', 'lt' => 'Слева сверху']],
            'fx_datestamp_format' => ['select', 'Штамп даты: формат', 'Как пишется дата.', ['dmy_short' => '12 10 26', 'dmy_dots' => '12.10.26', 'dmy_full' => '12.10.2026', 'ymd' => '26 10 12']],
            'fx_datestamp_size' => ['select', 'Штамп даты: размер', 'Крупнее — заметнее, как у дешёвых мыльниц.', ['small' => 'Мелкий', 'normal' => 'Обычный', 'large' => 'Крупный']],
            'fx_datestamp_color' => ['select', 'Штамп даты: цвет', 'Классический — оранжевый; у некоторых камер был красный или жёлтый.', ['orange' => 'Оранжевый', 'red' => 'Красный', 'yellow' => 'Жёлтый', 'white' => 'Белый']],
        ],
        'Фото' => [
            'photo_author' => ['text', 'Автор фотографий', 'Имя автора записывается в метаданные создаваемых изображений и ImageObject JSON-LD. Например: Сергей Колесников.'],
            'photo_copyright' => ['text', 'Копирайт фотографий', 'Например: © 2026 Sergei Kolesnikov. Все права на снимки принадлежат автору.'],
            'photo_credit' => ['text', 'Кредит / источник', 'Текст кредита для поисковиков, например: ptash.art.'],
            'photo_license' => ['text', 'Лицензия на использование', 'Например ссылка на условия использования фотографий: https://www.ptash.art/license.'],
            'jpg_quality' => ['number', 'Качество JPEG, %', '40–95. Действует на вновь создаваемые размеры; чтобы пересоздать старые, запустите tools/regen.php.'],
            'webp_quality' => ['number', 'Качество WebP, %', '40–95.'],
            'avif' => ['check', 'Формат AVIF', 'Дополнительные копии превью в AVIF — на 20–30% легче WebP при том же качестве. Создаются, когда выбранный движок (GD или Imagick) поддерживает AVIF; генерация заметно медленнее, места на диске нужно больше. Действует на вновь создаваемые размеры.'],
            'avif_quality' => ['number', 'Качество AVIF, %', '30–80. AVIF жмёт сильнее, поэтому цифры ниже, чем у JPEG/WebP: 50 — хорошее начало.'],
            'sharpen' => ['number', 'Резкость превью после уменьшения, %', '0–100 (0 — выключить). Уменьшение в GD слегка мылит фото; поднятие резкости возвращает «звон» миниатюрам. 50 — умеренное значение по умолчанию. Действует на вновь создаваемые размеры; чтобы пересчитать старые, запустите tools/regen.php.'],
        ],
        'Подвал (колонки ссылок)' => [
            'footer_col1_title' => ['text', 'Колонка 1 — заголовок', 'Например: «Портфолио». Пустая колонка (без заголовка и ссылок) не показывается.'],
            'footer_col1_links' => ['textarea', 'Колонка 1 — ссылки', 'Каждая строка: Текст ссылки | /адрес или https://… Например: Свадьбы | /weddings'],
            'footer_col2_title' => ['text', 'Колонка 2 — заголовок', ''],
            'footer_col2_links' => ['textarea', 'Колонка 2 — ссылки', ''],
            'footer_col3_title' => ['text', 'Колонка 3 — заголовок', ''],
            'footer_col3_links' => ['textarea', 'Колонка 3 — ссылки', ''],
            'footer_col4_title' => ['text', 'Колонка 4 — заголовок', ''],
            'footer_col4_links' => ['textarea', 'Колонка 4 — ссылки', ''],
        ],
        'Код и счётчики' => [
            'head_code' => ['textarea_code', 'Код в <head>', 'Сюда вставляют счётчики (Яндекс.Метрика, Google Analytics), пиксели. Вставляется как есть, включая <script>: такой код получает полный доступ ко всем страницам сайта — вставляйте только из доверенных источников. Публичная часть всегда работает с блокирующей CSP: вставленные здесь скрипты получают nonce, а для Метрики и GA разрешены сетевые запросы.'],
            'body_code' => ['textarea_code', 'Код перед </body>', 'Например, виджеты чатов. Те же правила осторожности, что и для кода в <head>.'],
        ],
    ];
}

function admin_settings(): void
{
    $spec = settings_spec();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();




        $rejected = 0;

        db_tx(static function () use ($spec, &$rejected): void {
        foreach ($spec as $fields) {
            foreach ($fields as $key => $def) {
                $type = $def[0];
                $raw = $_POST[$key] ?? null;
                if ($raw === null && $type !== 'check') {
                    continue;
                }
                $v = is_string($raw) ? $raw : '';
                switch ($type) {
                    case 'check':
                        $v = !empty($raw) ? '1' : '0';
                        break;
                    case 'number':
                        $v = (string)(int)$v;
                        break;
                    case 'image':
                        $v = (int)$v > 0 && image_get((int)$v) ? (string)(int)$v : '';
                        break;
                    case 'color':
                        $v = safe_color(trim($v), setting_defaults()[$key]);
                        break;
                    case 'select':
                        $v = array_key_exists($v, $def[3]) ? $v : (string)setting_defaults()[$key];
                        break;
                    case 'textarea_code':
                        break;
                    default:
                        $v = trim($v);
                }
                if (strpos($key, 'social_') === 0) {
                    $v = normalize_url($v);
                }
                if ($key === 'business_inn' || $key === 'business_ogrnip') {
                    $v = preg_replace('~\D~', '', $v);
                }
                if ($key === 'lang') {
                    $v = preg_match('~^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$~', $v) ? $v : 'ru';
                }
                if ($key === 'locale') {
                    $v = preg_match('~^[a-z]{2,3}_[A-Z]{2}$~', $v) ? $v : 'ru_RU';
                }
                if ($key === 'base_url') {
                    $v = rtrim($v, '/');




                    if ($v === '' && trim((string)setting('base_url')) !== '') {
                        $_SESSION['old']['setting_' . $key] = trim((string)setting('base_url'));
                        $rejected++;
                        flash('Адрес сайта нельзя очистить — без него поисковики увидят дубли всех страниц (сломаются canonical, sitemap и склейка зеркал). Если сайт переехал, впишите новый адрес вместо старого. Поле не сохранено.', 'err');
                        continue;
                    }
                    if ($v !== '' && !preg_match('~^https?://~i', $v)) {
                        $v = 'https://' . $v;
                    }





                    $pu = $v === '' ? false : parse_url($v);
                    $badUrl = $v !== '' && ($pu === false || empty($pu['host'])
                        || !in_array(strtolower((string)($pu['scheme'] ?? '')), ['http', 'https'], true)
                        || isset($pu['path']) || isset($pu['query']) || isset($pu['fragment'])
                        || isset($pu['user']) || isset($pu['pass']));
                    if ($badUrl) {
                        $_SESSION['old']['setting_' . $key] = $v;
                        $rejected++;
                        flash('Адрес сайта должен быть вида https://example.ru (без пути, слэша в конце и прочих добавок). Поле не сохранено.', 'err');
                        continue;
                    }
                }
                if ($key === 'twitter_site') {
                    $v = ltrim(trim($v), '@');
                    $v = preg_match('~^[A-Za-z0-9_]{1,15}$~', $v) ? $v : '';
                }
                if ($key === 'geo_lat' || $key === 'geo_lng') {


                    $v = str_replace(',', '.', trim($v));
                    if ($v !== '') {
                        $lim = $key === 'geo_lat' ? 90 : 180;
                        $v = is_numeric($v) && abs((float)$v) <= $lim ? (string)(float)$v : '';
                    }
                }
                if ($key === 'jpg_quality' || $key === 'webp_quality') {
                    $v = (string)max(IMG_QUALITY_MIN, min(IMG_QUALITY_MAX, (int)$v));
                }
                if ($key === 'avif_quality') {
                    $v = (string)max(30, min(80, (int)$v));
                }
                if ($key === 'sharpen') {
                    $v = (string)max(0, min(100, (int)$v));
                }
                if ($type !== 'textarea_code' && mb_strlen($v) > 2000) {
                    $v = mb_substr($v, 0, 2000);
                }
                if ($key === 'sidebar_width') {
                    $v = (string)max(160, min(360, (int)$v));
                }
                if ($key === 'logo_height') {
                    $v = (string)max(24, min(80, (int)$v));
                }

                if ($key === 'gallery_gap') {
                    $v = (string)max(0, min(40, (int)$v));
                }
                if ($key === 'font_scale_heading' || $key === 'font_scale_body'
                    || $key === 'font_scale_heading_mobile' || $key === 'font_scale_body_mobile') {
                    $v = (string)max(50, min(150, (int)$v));
                }
                set_setting($key, $v);
            }
        }
        });
        flash(empty($rejected) ? 'Настройки сохранены.' : 'Сохранено, кроме отклонённых полей — исправьте их (значения возвращены в поля) и сохраните ещё раз.', empty($rejected) ? 'ok' : 'err');
        redirect(a_url('settings', ['tab' => post_str('tab', 100)]));
    }

    $groups = array_keys($spec);
    $activeRaw = $_GET['tab'] ?? $groups[0];
    $active = is_string($activeRaw) ? $activeRaw : $groups[0];
    if (!in_array($active, $groups, true)) {
        $active = $groups[0];
    }
    admin_header('Настройки', 'settings');
    if (trim(setting('base_url')) === ''): ?>
<div class="flash err">Не заполнен «Адрес сайта с https://» (вкладка «Основное»). Без него canonical, sitemap и Open Graph
  строятся от адреса текущего запроса, а зеркала сайта (www, http, другие алиасы) не склеиваются 301-редиректом —
  поисковики видят дубли всех страниц.</div>
    <?php endif; ?>
<form method="post">
<?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($active) ?>" id="tabField">
<div class="bar sticky"><h1>Настройки</h1><span class="grow"></span><button class="btn primary">Сохранить</button></div>
    <?php

    $groupHints = [
        'Основное' => 'Название, адрес сайта, логотип, язык',
        'SEO' => 'Шаблоны title, описания, верификация, robots',
        'Организация (разметка)' => 'Тип бизнеса, владелец, город, адрес, часы — в микроразметку',
        'Контакты и соцсети' => 'Телефон, почта, профили — в подвал и sameAs',
        'Оформление' => 'Тема, цвета, шрифты, галереи, лайтбокс',
        'Фото' => 'Качество JPEG и WebP для новых загрузок',
        'Подвал (колонки ссылок)' => 'До четырёх колонок ссылок внизу сайта',
        'Код и счётчики' => 'Метрика, виджеты, произвольный код',
    ];
    ?>
<div class="setlay">
  <nav class="setnav" aria-label="Группы настроек">
    <?php foreach ($groups as $g): ?>
  <button type="button" data-tab="<?= e($g) ?>" class="<?= $g === $active ? 'on' : '' ?>">
    <span class="setnav-t"><?= e($g) ?></span>
        <?php if (isset($groupHints[$g])): ?><span class="setnav-h"><?= e($groupHints[$g]) ?></span><?php endif; ?>
  </button>
    <?php endforeach; ?>
  </nav>
  <div class="setmain">
    <?php foreach ($spec as $group => $fields): ?>
<section data-pane="<?= e($group) ?>" <?= $group === $active ? '' : 'hidden' ?>>
  <h2 class="seth"><?= e($group) ?></h2>
  <div class="card cols2">
        <?php foreach ($fields as $key => $def):
            [$type, $label] = $def;
            $help = $def[2] ?? '';


            if ($type === 'heading') {
                echo '<h3 class="setsub">' . e($label) . '</h3>';
                continue;
            }
            if ($type === 'note') {
                echo '<p class="setnote">' . e($label) . '</p>';
                continue;
            }
            $val = setting($key);

            if (isset($_SESSION['old']['setting_' . $key]) && is_string($_SESSION['old']['setting_' . $key])) {
                $val = $_SESSION['old']['setting_' . $key];
            }
            echo '<div>';
            if ($type === 'text') {
                echo f_input($key, $label, $val, ['help' => $help]);
            } elseif ($type === 'number') {

                $ranges = ['sidebar_width' => [160, 360], 'gallery_gap' => [0, 40], 'related_limit' => [2, 6], 'jpg_quality' => [60, 95], 'webp_quality' => [60, 95], 'logo_height' => [24, 80], 'sharpen' => [0, 100], 'font_scale_heading' => [50, 150], 'font_scale_body' => [50, 150], 'font_scale_heading_mobile' => [50, 150], 'font_scale_body_mobile' => [50, 150]];
                $numOpts = ['type' => 'number', 'help' => $help];
                if (isset($ranges[$key])) {
                    $numOpts['min'] = $ranges[$key][0];
                    $numOpts['max'] = $ranges[$key][1];
                }
                echo f_input($key, $label, $val, $numOpts);
            } elseif ($type === 'color') {
                echo f_input($key, $label, $val, ['type' => 'color', 'help' => $help]);
            } elseif ($type === 'textarea') {
                echo f_textarea($key, $label, $val, ['rows' => 3, 'help' => $help]);
            } elseif ($type === 'textarea_code') {
                echo f_textarea($key, $label, $val, ['rows' => 7, 'code' => true, 'help' => $help]);
            } elseif ($type === 'select') {
                echo f_select($key, $label, $def[3], $val, ['help' => $help]);
            } elseif ($type === 'check') {
                echo f_check($key, $label, $val === '1', $help);
            } elseif ($type === 'image') {
                echo f_image($key, $label, $val, $help);
            }
            echo '</div>';
        endforeach; ?>
  </div>
</section>
    <?php endforeach; ?>
  </div><?php  ?>
</div><?php  ?>
    <?php unset($_SESSION['old']); ?>
</form>
    <?php
    admin_footer();
}
