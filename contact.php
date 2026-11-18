<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);
/**
 * Приём формы связи (1.9.48): POST от секции «Форма связи». Точка входа публичная и без
 * сессии (анонимные посетители); защита — токен по дате (HMAC), скрытое поле-ловушка
 * и троттлинг по подсети — всё в app/contact.php.
 */
require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    http_response_code(503);
    exit('Сайт ещё не установлен.');
}
contact_handle();
