<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

/* Публичная форма связи (секция «Форма связи», 1.9.48). Разметка — templates/sections/contact.php,
   приём POST — корневой contact.php; здесь токен, троттлинг и доставка (почта + Telegram).
   Сессий у анонимных посетителей нет (публичные страницы её не поднимают), поэтому вместо
   CSRF-токена сессии — HMAC от даты и адреса страницы. */

/** Секрет для токенов формы: генерируется один раз и лежит в settings (база закрыта от веба). */
function form_secret(): string
{
    $s = setting('form_secret');
    if (strlen($s) >= 32) {
        return $s;
    }
    /* Гонка первого рендера: два процесса могут сгенерировать разные секреты, и HTML
       с «проигравшим» токеном способен лечь в файловый кэш страниц — форма была бы
       мертва до смены ключа кэша. Поэтому пишем INSERT OR IGNORE (побеждает первый
       писатель) и перечитываем базу: все процессы работают с одним секретом. Напрямую,
       без set_setting(): создание секрета не правка контента, site_touch() не нужен. */
    $s = bin2hex(random_bytes(32));
    db_run('INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)', ['form_secret', $s]);
    settings_all(true);
    $stored = setting('form_secret');
    return strlen($stored) >= 32 ? $stored : $s;
}

/** Токен формы: HMAC от текущей даты и адреса страницы с формой. */
function contact_token(string $pagePath): string
{
    return hash_hmac('sha256', date('Ymd') . '|' . $pagePath, form_secret());
}

/**
 * Принимаются токены последних 8 суток: форму могли открыть вчера до полуночи, а с файловым
 * кэшем страниц (pagecache.php, 1.9.48) HTML с токеном живёт до 7 суток — иначе форма на
 * закэшированной странице начала бы отклонять реальных посетителей через пару дней.
 * Окно не ослабляет защиту заметно: основную работу делают ловушка и троттлинг.
 */
function contact_token_ok(string $token, string $pagePath): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return false;
    }
    $secret = form_secret();
    for ($i = 0; $i < 8; $i++) {
        if (hash_equals(hash_hmac('sha256', date('Ymd', time() - $i * 86400) . '|' . $pagePath, $secret), $token)) {
            return true;
        }
    }
    return false;
}

/** Файл счётчиков троттлинга формы (формат — как у storage/locks/img-gen.json: подсеть → метки времени). */
function contact_throttle_file(): string
{
    $dir = ROOT . '/storage/throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/contact.json';
}

/**
 * Лог событий формы, не чаще раза в 5 минут на ключ: бот с битым токеном иначе флудит
 * error.log сотнями строк в час, и ротация (1 МБ) вымывает реальные ошибки сайта.
 * Метки — в JSON рядом со счётчиками троттлинга; любой сбой чтения/записи не мешает логу.
 */
function contact_log_throttled(string $key, string $msg): void
{
    $file = dirname(contact_throttle_file()) . '/contact-log.json';
    $all = [];
    $raw = @file_get_contents($file);
    if (is_string($raw)) {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $all = $j;
        }
    }
    $now = time();
    if (isset($all[$key]) && $now - (int)$all[$key] < 300) {
        return;
    }
    $all[$key] = $now;
    @file_put_contents($file, json_encode($all), LOCK_EX);
    app_log_error($msg);
}

/**
 * Поле формы: обрезка до $max и вырезание управляющих символов — CRLF в имени/email
 * это вектор подстановки лишних заголовков в письмо (header injection).
 * $keepNewlines — только для текста сообщения: там переводы строк легитимны.
 */
function contact_clean(string $s, int $max, bool $keepNewlines = false): string
{
    $re = $keepNewlines ? '/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u';
    return clip((string)preg_replace($re, '', $s), $max);
}

/** Адрес возврата после отправки: только локальный путь (не «//host», не «/\host»), иначе главная. */
function contact_back_url(string $back): string
{
    if ($back !== '' && $back[0] === '/'
        && !str_starts_with($back, '//') && !str_starts_with($back, '/\\')
        && !preg_match('/[\x00-\x1f\x7f]/', $back)) {
        return $back;
    }
    return '/';
}

/** Добавляет параметр к адресу возврата, не ломая #якорь: «/page#s12» → «/page?sent=1#s12». */
function contact_back_with(string $back, string $qs): string
{
    $frag = '';
    $p = strpos($back, '#');
    if ($p !== false) {
        $frag = substr($back, $p);
        $back = substr($back, 0, $p);
    }
    return $back . (str_contains($back, '?') ? '&' : '?') . $qs . $frag;
}

/** Текст заявки, общий для письма и Telegram. */
function contact_message_text(array $m): string
{
    $lines = ['Имя: ' . $m['name']];
    if ($m['phone'] !== '') {
        $lines[] = 'Телефон: ' . $m['phone'];
    }
    if ($m['email'] !== '') {
        $lines[] = 'Email: ' . $m['email'];
    }
    if ($m['date'] !== '') {
        $lines[] = 'Дата съёмки: ' . $m['date'];
    }
    $lines[] = 'Страница: ' . $m['page'];
    $lines[] = '';
    $lines[] = $m['message'];
    return implode("\n", $lines);
}

/**
 * Письмо владельцу. Адрес — «Почта для заявок» из настроек (пусто — основной email).
 * From — технический no-reply@хост-сайта (не подменяем чужой домен — SPF/DKIM такое режут);
 * адрес отправителя формы уходит в Reply-To, чтобы «Ответить» в почте работало.
 */
function contact_send_mail(array $m): bool
{
    $to = trim(setting('contact_email'));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $to = trim(setting('email'));
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $host = (string)preg_replace('/:\d+$/', '', request_host());
    $from = 'no-reply@' . ($host !== '' ? $host : 'localhost');
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . $m['name']) . '?=';
    $headers = "From: " . $from . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";
    if ($m['email'] !== '' && filter_var($m['email'], FILTER_VALIDATE_EMAIL)) {
        $headers .= "Reply-To: " . $m['email'] . "\r\n";
    }
    try {
        $ok = @mail($to, $subject, contact_message_text($m), $headers);
        if (!$ok) {
            app_log_error('contact: mail() вернул false — вероятно, отправка почты не настроена на хостинге');
        }
        return $ok;
    } catch (Throwable $e) {
        app_log_error('contact: mail() — ' . $e->getMessage());
        return false;
    }
}

/**
 * Дубль заявки в Telegram-бота (настройки tg_bot_token/tg_chat_id). Текст отправляем без
 * parse_mode: в полях произвольный ввод, а разметка Markdown/HTML в нём ломала бы форматирование.
 */
function contact_send_telegram(array $m): bool
{
    $token = trim(setting('tg_bot_token'));
    $chat = trim(setting('tg_chat_id'));
    if (!preg_match('~^\d+:[A-Za-z0-9_\-]{20,}$~', $token)
        || !preg_match('~^(-?\d+|@[A-Za-z0-9_]{5,})$~', $chat)) {
        return false; // не настроено — не ошибка
    }
    $text = 'Заявка с сайта «' . setting('site_name') . '»' . "\n" . contact_message_text($m);
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']);
    try {
        $r = false;
        /* На части хостингов allow_url_fopen=Off — пробуем cURL, он обычно разрешён (аудит 1.9.57) */
        if (!ini_get('allow_url_fopen') && function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $r = curl_exec($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 5,
                'ignore_errors' => true, // иначе тело ответа при 4xx не прочитать
            ]]);
            $r = @file_get_contents($url, false, $ctx);
        }
        $j = is_string($r) ? json_decode($r, true) : null;
        if (is_array($j) && !empty($j['ok'])) {
            return true;
        }
        /* токен/чат в ответ не включаем: журнал читаем глазами, лишнего там быть не должно */
        app_log_error('contact: Telegram API отклонил сообщение'
            . (is_array($j) && isset($j['description']) ? ' — ' . (string)$j['description'] : ''));
        return false;
    } catch (Throwable $e) {
        app_log_error('contact: Telegram — ' . $e->getMessage());
        return false;
    }
}

/**
 * Приём POST формы связи. Порядок: ловушка для ботов → токен → троттлинг → валидация →
 * доставка. После любой ветки — редирект обратно на страницу (PRG: F5 не отправляет форму
 * повторно); успех = доставлено хотя бы в один канал.
 */
function contact_handle(): void
{
    header('Cache-Control: no-store'); // ответ зависит от POST — прокси/браузер не должны его кэшировать
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('/');
    }
    $backRaw = $_POST['back'] ?? '';
    $back = contact_back_url(is_string($backRaw) ? $backRaw : '');

    /* Скрытое поле-ловушка «company»: люди его не видят (выносится за экран стилем),
       заполняют только боты. Отвечаем как об успехе — бот не должен понять, что раскрыт. */
    $hp = $_POST['company'] ?? '';
    if (is_string($hp) && trim($hp) !== '') {
        redirect(contact_back_with($back, 'sent=1'));
    }

    $pagePath = $_POST['cp'] ?? '';
    $pagePath = is_string($pagePath) ? $pagePath : '';
    $token = $_POST['ct'] ?? '';
    $token = is_string($token) ? $token : '';
    /* Токен не привязан к посетителю (сессия сломала бы pagecache): проверяем браузерный источник. */
    $source = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($source === '') {
        $source = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    }
    $sourceOk = true;
    if ($source !== '') {
        $hostNorm = static function (string $host): string {
            $host = strtolower((string)preg_replace('/:\d+$/', '', $host));
            return (string)preg_replace('/^www\./', '', $host);
        };
        $sourceHost = parse_url($source, PHP_URL_HOST);
        $ownHosts = array_map($hostNorm, [request_host(), (string)parse_url(base_url(), PHP_URL_HOST)]);
        $sourceOk = is_string($sourceHost) && in_array($hostNorm($sourceHost), $ownHosts, true);
    }
    if (!$sourceOk || $pagePath === '' || !contact_token_ok($token, $pagePath)) {
        contact_log_throttled('token', 'contact: POST с недействительным токеном (ip ' . client_ip() . ')');
        redirect(contact_back_with($back, 'err=1'));
    }

    /* лимит 5 заявок в час с подсети (/24, как у генерации миниатюр): форма — типичная
       точка спама, а каждая заявка — это письмо и сообщение в Telegram */
    if (!img_throttle_reserve(contact_throttle_file(), client_ip(), 5)) {
        contact_log_throttled('limit', 'contact: превышен лимит отправок или ошибка счётчика ' . client_ip());
        http_response_code(503);
        exit;
    }

    $raw = static function (string $k): string {
        $v = $_POST[$k] ?? '';
        return is_string($v) ? $v : '';
    };
    $m = [
        'name' => contact_clean($raw('name'), 100),
        'phone' => contact_clean($raw('phone'), 50),
        'email' => contact_clean($raw('email'), 200),
        'date' => contact_clean($raw('date'), 50),
        'message' => contact_clean($raw('message'), 5000, true),
        'page' => $pagePath,
    ];
    $valid = $m['name'] !== '' && $m['message'] !== ''
        && ($m['phone'] !== '' || $m['email'] !== '') // иначе ответить некуда
        && ($m['email'] === '' || filter_var($m['email'], FILTER_VALIDATE_EMAIL));
    if (!$valid) {
        redirect(contact_back_with($back, 'err=1'));
    }

    $okMail = contact_send_mail($m);
    $okTg = contact_send_telegram($m);
    if ($okMail || $okTg) {
        redirect(contact_back_with($back, 'sent=1'));
    }
    /* оба канала молчат: настраиваемый случай — вообще не заданы почта/Telegram, остальное (сбой
       mail(), отказ Telegram API) уже залогировано в contact_send_* — здесь фиксируем сам факт потери */
    if (trim(setting('contact_email')) === '' && trim(setting('email')) === '' && trim(setting('tg_bot_token')) === '') {
        app_log_error('contact: заявка потеряна — не настроен ни один канал доставки (Настройки → Контакты)');
    }
    redirect(contact_back_with($back, 'err=1'));
}
