<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);







function form_secret(): string
{
    $s = setting('form_secret');
    if (strlen($s) >= 32) {
        return $s;
    }





    $s = bin2hex(random_bytes(32));
    db_run('INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)', ['form_secret', $s]);
    settings_all(true);
    $stored = setting('form_secret');
    return strlen($stored) >= 32 ? $stored : $s;
}


function contact_token(string $pagePath): string
{
    return hash_hmac('sha256', date('Ymd') . '|' . $pagePath, form_secret());
}







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


function contact_throttle_file(): string
{
    $dir = ROOT . '/storage/throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/contact.json';
}






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






function contact_clean(string $s, int $max, bool $keepNewlines = false): string
{
    $re = $keepNewlines ? '/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/u' : '/[\x00-\x1f\x7f]/u';
    return clip((string)preg_replace($re, '', $s), $max);
}


function contact_back_url(string $back): string
{
    if ($back !== '' && $back[0] === '/'
        && !str_starts_with($back, '//') && !str_starts_with($back, '/\\')
        && !preg_match('/[\x00-\x1f\x7f]/', $back)) {
        return $back;
    }
    return '/';
}


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

function contact_old_store(array $m): void
{
    session_boot();
    $_SESSION['contact_old'] = [
        'name' => $m['name'], 'phone' => $m['phone'], 'email' => $m['email'],
        'date' => $m['date'], 'message' => $m['message'],
    ];
    session_write_close();
}

function contact_old_read(): array
{
    $sid = $_COOKIE['ptsid'] ?? '';
    if (!is_string($sid) || !preg_match('~^[A-Za-z0-9,-]{1,128}$~D', $sid)
        || !is_file(cfg('session_path', ROOT . '/storage/sessions') . '/sess_' . $sid)) {
        return [];
    }
    session_boot();
    $old = is_array($_SESSION['contact_old'] ?? null) ? $_SESSION['contact_old'] : [];
    unset($_SESSION['contact_old']);
    session_write_close();
    return $old;
}


function contact_attribution(string $raw): string
{
    if ($raw === '' || strlen($raw) > 20000) {
        return '';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return '';
    }
    $out = [];
    if (is_array($data['params'] ?? null)) {
        foreach (array_slice($data['params'], 0, 30, true) as $key => $value) {
            if (is_string($key) && is_tracking_param($key) && is_scalar($value)) {
                $out['params'][$key] = contact_clean((string)$value, 300);
            }
        }
    }
    foreach (['landing', 'referrer', 'at'] as $key) {
        if (is_string($data[$key] ?? null)) {
            $out[$key] = contact_clean($data[$key], $key === 'at' ? 40 : 1000);
        }
    }
    return $out ? json_db($out) : '';
}

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
    $source = json_decode((string)($m['attribution'] ?? ''), true);
    if (is_array($source)) {
        foreach ((array)($source['params'] ?? []) as $key => $value) {
            $lines[] = 'Метка ' . $key . ': ' . $value;
        }
        if (!empty($source['landing'])) {
            $lines[] = 'Первая страница: ' . $source['landing'];
        }
        if (!empty($source['referrer'])) {
            $lines[] = 'Источник перехода: ' . $source['referrer'];
        }
    }
    $lines[] = '';
    $lines[] = $m['message'];
    return implode("\n", $lines);
}






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





function contact_send_telegram(array $m): bool
{
    $token = trim(setting('tg_bot_token'));
    $chat = trim(setting('tg_chat_id'));
    if (!preg_match('~^\d+:[A-Za-z0-9_\-]{20,}$~', $token)
        || !preg_match('~^(-?\d+|@[A-Za-z0-9_]{5,})$~', $chat)) {
        return false;
    }
    $text = 'Заявка с сайта «' . setting('site_name') . '»' . "\n" . contact_message_text($m);
    $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
    $body = http_build_query(['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']);
    try {
        $r = false;

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
                'ignore_errors' => true,
            ]]);
            $r = @file_get_contents($url, false, $ctx);
        }
        $j = is_string($r) ? json_decode($r, true) : null;
        if (is_array($j) && !empty($j['ok'])) {
            return true;
        }

        app_log_error('contact: Telegram API отклонил сообщение'
            . (is_array($j) && isset($j['description']) ? ' — ' . (string)$j['description'] : ''));
        return false;
    } catch (Throwable $e) {
        app_log_error('contact: Telegram — ' . $e->getMessage());
        return false;
    }
}





function contact_origin_matches(string $source, string $expected): bool
{
    $sourceParts = parse_url($source);
    $expectedParts = parse_url($expected);
    if (!is_array($sourceParts) || !is_array($expectedParts)) {
        return false;
    }
    $sourceScheme = strtolower((string)($sourceParts['scheme'] ?? ''));
    $expectedScheme = strtolower((string)($expectedParts['scheme'] ?? ''));
    if (!in_array($sourceScheme, ['http', 'https'], true) || $sourceScheme !== $expectedScheme) {
        return false;
    }
    if (isset($sourceParts['user']) || isset($sourceParts['pass'])) {
        return false;
    }
    $sourceHost = strtolower((string)($sourceParts['host'] ?? ''));
    $expectedHost = strtolower((string)($expectedParts['host'] ?? ''));
    $sourceHost = (string)preg_replace('/^www\./', '', $sourceHost);
    $expectedHost = (string)preg_replace('/^www\./', '', $expectedHost);
    if ($sourceHost === '' || $sourceHost !== $expectedHost) {
        return false;
    }
    $sourcePort = (int)($sourceParts['port'] ?? ($sourceScheme === 'https' ? 443 : 80));
    $expectedPort = (int)($expectedParts['port'] ?? ($expectedScheme === 'https' ? 443 : 80));
    return $sourcePort === $expectedPort;
}


function contact_handle(): void
{
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('/');
    }
    $backRaw = $_POST['back'] ?? '';
    $back = contact_back_url(is_string($backRaw) ? $backRaw : '');

    $hp = $_POST['p48x2'] ?? '';
    if (is_string($hp) && trim($hp) !== '') {
        redirect(contact_back_with($back, 'sent=1'));
    }

    $pagePath = $_POST['cp'] ?? '';
    $pagePath = is_string($pagePath) ? $pagePath : '';
    $token = $_POST['ct'] ?? '';
    $token = is_string($token) ? $token : '';
    $source = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($source === '') {
        $source = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    }
    $sourceOk = true;
    if ($source !== '') {
        $requestOrigin = (is_https() ? 'https' : 'http') . '://' . request_host();
        $sourceOk = contact_origin_matches($source, $requestOrigin) || contact_origin_matches($source, base_url());
    }
    if (!$sourceOk || $pagePath === '' || !contact_token_ok($token, $pagePath)) {
        contact_log_throttled('token', 'contact: POST с недействительным токеном (ip ' . client_ip() . ')');
        redirect(contact_back_with($back, 'err=1'));
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
        'attribution' => contact_attribution($raw('attribution')),
    ];
    $valid = $m['name'] !== '' && $m['message'] !== ''
        && ($m['phone'] !== '' || $m['email'] !== '')
        && ($m['email'] === '' || filter_var($m['email'], FILTER_VALIDATE_EMAIL));
    if (!$valid) {
        contact_old_store($m);
        redirect(contact_back_with($back, 'err=1'));
    }

    if (!img_throttle_reserve(contact_throttle_file(), client_ip(), 5, true)) {
        contact_log_throttled('limit', 'contact: превышен лимит отправок или ошибка счётчика ' . client_ip());
        contact_old_store($m);
        redirect(contact_back_with($back, 'err=limit'));
    }

    try {
        $id = db_insert('INSERT INTO contact_messages
            (created_at, name, phone, email, shoot_date, message, page_path, ip_hash, source_json, delivered)
            VALUES (?,?,?,?,?,?,?,?,?,0)', [
            time(), $m['name'], $m['phone'], $m['email'], $m['date'], $m['message'], $m['page'],
            hash('sha256', client_ip() . '|' . form_secret()), $m['attribution'],
        ]);
    } catch (Throwable $e) {
        app_log_error('contact: не удалось сохранить заявку — ' . $e->getMessage());
        contact_old_store($m);
        redirect(contact_back_with($back, 'err=save'));
    }

    $okMail = contact_send_mail($m);
    $okTg = contact_send_telegram($m);
    db_run('UPDATE contact_messages SET delivered = ? WHERE id = ?', [$okMail || $okTg ? 1 : 0, $id]);
    if (!$okMail && !$okTg) {
        app_log_error('contact: заявка #' . $id . ' сохранена в админке, но внешняя доставка не выполнена');
    }
    redirect(contact_back_with($back, 'sent=1'));
}
