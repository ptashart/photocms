<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

/* Пороги и «магические» числа проекта в одном месте. */

/* Вход в админку */
const SESSION_LIFETIME = 604800;      // 7 суток — абсолютный срок жизни входа
const SESSION_IDLE = 43200;           // 12 часов без действий — вход сбрасывается
const LOGIN_MAX_PER_IP = 8;           // неудачных попыток с одного IP за окно
const LOGIN_MAX_PER_LOGIN = 30;       // неудачных попыток на один логин (со всех IP) за окно
const LOGIN_WINDOW = 900;             // окно подсчёта, секунд (15 минут)
const PWCHANGE_MAX_ATTEMPTS = 5;      // неудачных смен пароля за окно (подбор текущего пароля)

/* Заглушка bcrypt-хэша для входа с несуществующим логином (выравнивание времени ответа)
   больше не константа: значение торчало в публичном архиве пакета. С 1.9.35 она генерируется
   на сервере и хранится в storage/.dummy-hash — см. dummy_password_hash() (ревизия 1.9.34, п.13). */

/* Картинки */
const UPLOAD_MAX_BYTES = 104857600;   // 100 МБ — серверный потолок на один загружаемый файл (фото)
const IMG_MAX_PIXELS_CAP = 90000000;  // жёсткий потолок; реальный лимит считается от memory_limit
const IMG_LOCK_SLOTS = 4;             // сколько вариантов одновременно «рождается» из оригиналов
const IMG_LOCK_WAIT = 8;              // сколько секунд публичный запрос ждёт свободный слот, прежде чем отдать запасной вариант
const IMG_GEN_MAX_PER_IP = 300;       // успешных генераций вариантов с одного IP в час (fail-троттлинг ловил только неудачи)
const IMG_GEN_COLD_MAX_PER_IP = 1200; // то же для «холодных» фото после сброса миниатюр (вариантов нет вовсе, fallback отдать нечего)
const IMG_FAIL_MAX_PER_IP = 12;       // неудачных генераций с одного IP в час (перебор несуществующих комбинаций гонял тяжёлый декод)
const IMG_QUALITY_MIN = 40;
const IMG_QUALITY_MAX = 95;

/* Блог и лента */
const POSTS_PER_PAGE = 24;
const RSS_ITEMS = 20;

/* Журнал ошибок */
const ERROR_LOG_MAX = 1048576;        // 1 МБ, после чего файл ротируется в error.log.1

/* SQL-выражение «дата публикации заметки»: published_at, а для старых/черновиков — created_at */
const POST_TS_SQL = 'CASE WHEN published_at > 0 THEN published_at ELSE created_at END';

/* Адреса, которые нельзя занимать страницам и разделам */
const RESERVED_SLUGS = ['admin', 'media', 'assets', 'app', 'storage', 'templates', 'tools', 'sitemap.xml', 'robots.txt',
    'favicon.ico', 'index', 'index.php', 'img.php', 'install', 'install.php', 'config', 'config.php',
    'blog', 'feed', 'feed.xml', 'import'];
