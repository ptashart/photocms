<?php
/* PhotoCMS — photographer portfolio CMS.
 * Copyright (C) 2025–2026 Sergei Kolesnikov · https://www.ptash.art
 * SPDX-License-Identifier: GPL-3.0-only
 * Full license: LICENSE · Additional attribution terms: NOTICE.md. */
declare(strict_types=1);

function db_path(): string
{
    return (string)cfg('db', ROOT . '/storage/site.sqlite');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        /* SQLite сам создаёт пустой файл при подключении: раньше любой запрос до установки
           оставлял пустую болванку site.sqlite, и is_installed() по «файл есть» начинал путаться.
           Создавать файл разрешено только установщику (он объявляет PTS_DB_CREATE). */
        if (!is_file(db_path()) && !defined('PTS_DB_CREATE')) {
            throw new RuntimeException('База данных не найдена — сайт ещё не установлен. Откройте /install.php');
        }
        $pdo = new PDO('sqlite:' . db_path(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        /* WAL: читатели не блокируются во время записи — меньше случайных 500 на шаред-хостингах */
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
        } catch (Throwable $ignore) {
        }
        /* 1.9.31: SQLite создаёт файлы с правами из umask (обычно 0644) — на шаред-хостинге база
           с хэшами паролей и черновиками была бы читаема другим локальным пользователям.
           Веб-доступ закрыт .htaccess, здесь закрываем локальный. -wal/-shm пересоздаются
           SQLite при каждом подключении, поэтому проверка прав — на каждое подключение,
           но сам chmod — только когда права реально не те: fileperms идёт по stat-кэшу
           и бесплатен, а chmod — лишний syscall на каждый запрос (ревизия 1.9.34, п.20). */
        foreach (['', '-wal', '-shm'] as $dbSfx) {
            $dbf = db_path() . $dbSfx;
            if (is_file($dbf) && (fileperms($dbf) & 0777) !== 0600) {
                @chmod($dbf, 0600);
            }
        }
    }
    return $pdo;
}

function db_run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    /* execute($params) биндит все значения строками. SQLite при сравнении выражения без аффинности
       типа (например, CASE WHEN … END) со строкой считает INTEGER < TEXT всегда истинным —
       из-за этого ломалась постнавигация блога ($ts < ? срабатавало на любую заметку).
       Биндим с явным типом: int → PARAM_INT, null → PARAM_NULL, остальное → PARAM_STR.
       В обратную сторону аффинность колонки сама приводит тип, так что это безопасно. */
    foreach ($params as $i => $v) {
        /* bool — тоже в INT (аудит 1.9.40, п.15): как PARAM_STR false превращался в пустую
           строку, и INSERT писал '' вместо 0 */
        $type = is_int($v) || is_bool($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue(is_int($i) ? $i + 1 : (string)$i, is_bool($v) ? (int)$v : $v, $type);
    }
    $st->execute();
    return $st;
}

function db_all(string $sql, array $params = []): array
{
    return db_run($sql, $params)->fetchAll();
}

/**
 * SELECT … IN (?,?,…) порциями: в SQLite до 3.32 лимит параметров — 999, а установщик заявляет
 * поддержку 3.7+. $sql должен содержать метку {IN} вместо списка плейсхолдеров:
 * db_all_in('SELECT * FROM images WHERE id IN ({IN})', $ids).
 */
function db_all_in(string $sql, array $ids, int $chunk = 500): array
{
    $out = [];
    foreach (array_chunk(array_values($ids), $chunk) as $part) {
        $in = implode(',', array_fill(0, count($part), '?'));
        foreach (db_all(str_replace('{IN}', $in, $sql), $part) as $r) {
            $out[] = $r;
        }
    }
    return $out;
}

/** Колонка есть в таблице? Для миграций: вместо «выполнить ALTER и проглотить любую ошибку». */
function db_column_exists(string $table, string $column): bool
{
    /* В PRAGMA плейсхолдеры не подставить — имя таблицы идёт в SQL строкой. Сейчас сюда приходят
       только захардкоженные имена из migrate_steps(), но функция общая: валидируем, чтобы при
       будущем использовании с внешним вводом она не стала вектором SQL-инъекции */
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $table)) {
        throw new InvalidArgumentException('db_column_exists: недопустимое имя таблицы');
    }
    foreach (db()->query('PRAGMA table_info(' . $table . ')') as $r) {
        if ((string)$r['name'] === $column) {
            return true;
        }
    }
    return false;
}

function db_one(string $sql, array $params = []): ?array
{
    $r = db_run($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function db_val(string $sql, array $params = [])
{
    $r = db_run($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}

function db_insert(string $sql, array $params = []): int
{
    db_run($sql, $params);
    return (int)db()->lastInsertId();
}

/**
 * Выполняет $fn в транзакции: либо все записи применяются, либо (при исключении) ни одна.
 * Вложенный вызов просто выполняется внутри уже открытой транзакции.
 * ВАЖНО: внутри $fn нельзя вызывать redirect()/json_out()/exit — незавершённая транзакция откатывается.
 *
 * @return mixed результат $fn
 */
function db_tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        /* set_setting() внутри транзакции не трогает кэш, а лишь ставит флаг «грязно» —
           перечитываем настройки после коммита, и только если они реально писались:
           безусловный settings_all(true) на КАЖДУЮ транзакцию (сохранение страницы,
           удаление фото…) даром перечитывал всю таблицу settings (ревизия 1.9.34, п.20) */
        if (!empty($GLOBALS['settings_dirty']) && function_exists('settings_all')) {
            $GLOBALS['settings_dirty'] = false;
            settings_all(true);
        }
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        /* и после отката: кэш мог успеть обновиться частичными записями */
        if (!empty($GLOBALS['settings_dirty']) && function_exists('settings_all')) {
            $GLOBALS['settings_dirty'] = false;
            settings_all(true);
        }
        throw $e;
    }
}

/**
 * Сайт установлен? Ошибки БД здесь НЕ глотаем: раньше любой временный сбой (база занята, ошибка диска)
 * превращался в «не установлено», и install.php создавал второго администратора в рабочей базе.
 * Теперь сбой даёт обычную страницу 500, а после успешной установки пишется storage/installed.lock.
 *
 * Замок проверяем ПЕРВЫМ: если база пропала или обнулилась (недокачали по FTP, восстановили бэкап,
 * сбой диска), а замок остался, сайт обязан показать ошибку, а не установщик — иначе первый же
 * посетитель заводит собственного администратора и захватывает сайт. Для осознанной переустановки
 * владелец удаляет storage/installed.lock по FTP.
 */
function is_installed(): bool
{
    $lock = ROOT . '/storage/installed.lock';
    if (is_file($lock)) {
        return true;
    }
    $path = db_path();
    if (!is_file($path) || (int)filesize($path) === 0) {
        return false;
    }
    if (!db_val("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'users'")) {
        return false; // файл базы есть, но схемы ещё нет
    }
    if ((int)db_val('SELECT COUNT(*) FROM users') <= 0) {
        return false;
    }
    @file_put_contents($lock, (string)time()); // старые установки «дозаписывают» замок при первом обращении
    return true;
}

function install_schema(): void
{
    $pdo = db();
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '');

    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        login TEXT NOT NULL UNIQUE,
        pass_hash TEXT NOT NULL,
        created_at INTEGER NOT NULL
    );

    CREATE TABLE IF NOT EXISTS images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL DEFAULT 'photo',
        ext TEXT NOT NULL DEFAULT 'jpg',
        orig_name TEXT NOT NULL DEFAULT '',
        w INTEGER NOT NULL DEFAULT 0,
        h INTEGER NOT NULL DEFAULT 0,
        color TEXT NOT NULL DEFAULT '#cccccc',
        alt TEXT NOT NULL DEFAULT '',
        focus_x REAL NOT NULL DEFAULT 0.5,
        focus_y REAL NOT NULL DEFAULT 0.5,
        created_at INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL DEFAULT 'page',
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        parent_id INTEGER,
        is_home INTEGER NOT NULL DEFAULT 0,
        is_published INTEGER NOT NULL DEFAULT 1,
        position INTEGER NOT NULL DEFAULT 0,
        cover_image_id INTEGER,
        short_text TEXT NOT NULL DEFAULT '',
        body_html TEXT NOT NULL DEFAULT '',
        h1 TEXT NOT NULL DEFAULT '',
        show_title INTEGER NOT NULL DEFAULT 0,
        seo_title TEXT NOT NULL DEFAULT '',
        seo_description TEXT NOT NULL DEFAULT '',
        og_image_id INTEGER,
        noindex INTEGER NOT NULL DEFAULT 0,
        canonical TEXT NOT NULL DEFAULT '',
        schema_type TEXT NOT NULL DEFAULT '',
        jsonld_extra TEXT NOT NULL DEFAULT '',
        album_columns INTEGER NOT NULL DEFAULT 0,
        album_layout TEXT NOT NULL DEFAULT '',
        badge TEXT NOT NULL DEFAULT '',
        stamp_text TEXT NOT NULL DEFAULT '',
        shoot_note TEXT NOT NULL DEFAULT '',
        album_related INTEGER NOT NULL DEFAULT 1,
        is_service INTEGER NOT NULL DEFAULT 0,
        service_name TEXT NOT NULL DEFAULT '',
        service_price TEXT NOT NULL DEFAULT '',
        service_currency TEXT NOT NULL DEFAULT 'RUB',
        service_desc TEXT NOT NULL DEFAULT '',
        service_area TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS sections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_type TEXT NOT NULL DEFAULT 'page',
        page_id INTEGER NOT NULL,
        type TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        data TEXT NOT NULL DEFAULT '{}'
    );
    CREATE INDEX IF NOT EXISTS idx_sections_page ON sections(page_id, position);
    CREATE INDEX IF NOT EXISTS idx_sections_owner ON sections(owner_type, page_id, position);

    CREATE TABLE IF NOT EXISTS gallery_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_type TEXT NOT NULL,
        owner_id INTEGER NOT NULL,
        image_id INTEGER NOT NULL,
        position INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_gallery_owner ON gallery_items(owner_type, owner_id, position);

    CREATE TABLE IF NOT EXISTS menu_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER,
        title TEXT NOT NULL,
        page_id INTEGER,
        url TEXT NOT NULL DEFAULT '',
        new_tab INTEGER NOT NULL DEFAULT 0,
        position INTEGER NOT NULL DEFAULT 0
    );

    CREATE TABLE IF NOT EXISTS redirects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        from_path TEXT NOT NULL UNIQUE,
        to_url TEXT NOT NULL,
        code INTEGER NOT NULL DEFAULT 301
    );

    CREATE TABLE IF NOT EXISTS login_log (ip TEXT NOT NULL, ts INTEGER NOT NULL, login TEXT NOT NULL DEFAULT '');
    CREATE INDEX IF NOT EXISTS idx_login_log_ts ON login_log(ts);
    /* счётчики входа идут по ip/login/(login,ip) на КАЖДОЙ попытке — без этих индексов
       разросшийся журнал сам становится источником нагрузки */
    CREATE INDEX IF NOT EXISTS idx_login_log_ip ON login_log(ip);
    CREATE INDEX IF NOT EXISTS idx_login_log_login ON login_log(login);
    CREATE INDEX IF NOT EXISTS idx_login_log_login_ip ON login_log(login, ip);

    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        slug TEXT NOT NULL UNIQUE,
        section TEXT NOT NULL DEFAULT 'blog',
        body TEXT NOT NULL DEFAULT '',
        excerpt TEXT NOT NULL DEFAULT '',
        is_published INTEGER NOT NULL DEFAULT 0,
        seo_title TEXT NOT NULL DEFAULT '',
        seo_description TEXT NOT NULL DEFAULT '',
        og_image_id INTEGER,
        noindex INTEGER NOT NULL DEFAULT 0,
        schema_type TEXT NOT NULL DEFAULT '',
        jsonld_extra TEXT NOT NULL DEFAULT '',
        canonical TEXT NOT NULL DEFAULT '',
        published_at INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE INDEX IF NOT EXISTS idx_posts_section ON posts(section, is_published, created_at);
    CREATE INDEX IF NOT EXISTS idx_gallery_image ON gallery_items(image_id);
    CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id);

    CREATE TABLE IF NOT EXISTS post_links (
        from_id INTEGER NOT NULL,
        target TEXT NOT NULL,
        target_lc TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_post_links_target ON post_links(target_lc);
    CREATE INDEX IF NOT EXISTS idx_post_links_from ON post_links(from_id);

    /* видеотека (1.9.48): файл в media/video/<id>-<случайные 4 байта hex>.mp4,
       created_at — ISO 8601 (напрямую годится для uploadDate в VideoObject) */
    CREATE TABLE IF NOT EXISTS videos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file TEXT NOT NULL DEFAULT '',
        title TEXT NOT NULL DEFAULT '',
        description TEXT NOT NULL DEFAULT '',
        poster_image_id INTEGER,
        duration INTEGER,
        created_at TEXT NOT NULL DEFAULT ''
    );
    ");
}

/** Актуальная версия схемы. Целый счётчик 1, 2, 3… — НЕ версия приложения (та — APP_VERSION в формате
    MAJOR.MINOR.PATCH, см. app/bootstrap.php). Поднимите число при изменении структуры и добавьте шаг в migrate(). */
const SCHEMA_V = 18;

/** Догоняет схему старых установок до актуальной (вызывается после проверки is_installed()). */
function migrate(): void
{
    /* Версию схемы читаем напрямую, а не через кэш settings_all(): при сбое БД кэш вернул бы
       дефолт ('' → 0), и вся цепочка миграций перезапустилась бы на рабочей базе. */
    if ((int)db_val("SELECT v FROM settings WHERE k = 'schema_v'") >= SCHEMA_V) {
        return;
    }
    /* Параллельные запросы не должны гнать миграцию одновременно: второй ждёт на замке и выходит. */
    $lockDir = ROOT . '/storage/locks';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0700, true);
    }
    $lock = @fopen($lockDir . '/migrate.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Не удалось заблокировать миграцию БД. Проверьте права на storage/locks.');
    }
    try {
        $v = (int)db_val("SELECT v FROM settings WHERE k = 'schema_v'"); // перечитываем уже под замком
        if ($v >= SCHEMA_V) {
            return;
        }
        /* Все шаги — одной транзакцией, schema_v пишется только после успеха: половинчатая
           миграция, помеченная завершённой, хуже повторной попытки при следующем обращении. */
        db_tx(static function () use ($v): void {
            migrate_steps($v);
            foreach (['stamp_text', 'shoot_note'] as $column) {
                if (!db_column_exists('pages', $column)) {
                    throw new RuntimeException('Миграция не создала обязательную колонку pages.' . $column);
                }
            }
            set_setting('schema_v', (string)SCHEMA_V);
        });
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

function migrate_steps(int $v): void
{
    if ($v < 2) {
        db()->exec("
        CREATE TABLE IF NOT EXISTS posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            body TEXT NOT NULL DEFAULT '',
            excerpt TEXT NOT NULL DEFAULT '',
            is_published INTEGER NOT NULL DEFAULT 0,
            seo_title TEXT NOT NULL DEFAULT '',
            seo_description TEXT NOT NULL DEFAULT '',
            og_image_id INTEGER,
            noindex INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL DEFAULT 0,
            updated_at INTEGER NOT NULL DEFAULT 0
        );");
    }
    if ($v < 3) {
        /* рестайлинг 1.2.0: переводим старые дефолты оформления на новые,
           но только если владелец не менял их вручную */
        $swap = [
            'font_heading' => ['Forum', 'Tenor Sans'],
            'font_body' => ['Ubuntu', 'Golos Text'],
            'color_text' => ['#1e1e1e', '#252525'],
        ];
        foreach ($swap as $k => [$old, $new]) {
            $cur = db_val('SELECT v FROM settings WHERE k = ?', [$k]);
            if ($cur === $old) {
                set_setting($k, $new);
            }
        }
    }
    if ($v < 4) {
        /* 1.3.0: разделы заметок и настраиваемая микроразметка.
           Колонку проверяем явно: «выполнить ALTER и проглотить исключение» маскировало
           любую ошибку (не только «колонка уже есть») под успех. */
        foreach ([
            ['posts', 'section', "ALTER TABLE posts ADD COLUMN section TEXT NOT NULL DEFAULT 'blog'"],
            ['posts', 'schema_type', "ALTER TABLE posts ADD COLUMN schema_type TEXT NOT NULL DEFAULT ''"],
            ['posts', 'jsonld_extra', "ALTER TABLE posts ADD COLUMN jsonld_extra TEXT NOT NULL DEFAULT ''"],
            ['pages', 'schema_type', "ALTER TABLE pages ADD COLUMN schema_type TEXT NOT NULL DEFAULT ''"],
            ['pages', 'jsonld_extra', "ALTER TABLE pages ADD COLUMN jsonld_extra TEXT NOT NULL DEFAULT ''"],
        ] as [$table, $column, $sql]) {
            if (!db_column_exists($table, $column)) {
                db()->exec($sql);
            }
        }
    }
    if ($v < 5) {
        /* 1.6.0: название и описание блога живут только в настройках. Если раньше их правили
           на странице «Разделы» — переносим значения, чтобы ничего не пропало. */
        $raw = json_decode((string)db_val("SELECT v FROM settings WHERE k = 'post_sections'"), true);
        if (is_array($raw)) {
            foreach ($raw as $s) {
                if (is_array($s) && ($s['slug'] ?? '') === 'blog') {
                    if (trim((string)($s['title'] ?? '')) !== '') {
                        set_setting('blog_title', trim((string)$s['title']));
                    }
                    if (trim((string)($s['description'] ?? '')) !== '') {
                        set_setting('blog_description', trim((string)$s['description']));
                    }
                }
            }
        }
    }
    if ($v < 6) {
        /* 1.7.0: дата публикации заметок, индекс вики-ссылок, индексы, журнал входов с логином */
        foreach ([
            ['posts', 'published_at', "ALTER TABLE posts ADD COLUMN published_at INTEGER NOT NULL DEFAULT 0"],
            ['login_log', 'login', "ALTER TABLE login_log ADD COLUMN login TEXT NOT NULL DEFAULT ''"],
        ] as [$table, $column, $sql]) {
            if (!db_column_exists($table, $column)) {
                db()->exec($sql);
            }
        }
        db()->exec("
        CREATE TABLE IF NOT EXISTS post_links (
            from_id INTEGER NOT NULL,
            target TEXT NOT NULL,
            target_lc TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_post_links_target ON post_links(target_lc);
        CREATE INDEX IF NOT EXISTS idx_post_links_from ON post_links(from_id);
        CREATE INDEX IF NOT EXISTS idx_posts_section ON posts(section, is_published, created_at);
        CREATE INDEX IF NOT EXISTS idx_gallery_image ON gallery_items(image_id);
        CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id);
        CREATE INDEX IF NOT EXISTS idx_login_log_ts ON login_log(ts);");
        /* у уже опубликованных заметок дата публикации = дата создания, как показывалось раньше */
        db()->exec('UPDATE posts SET published_at = created_at WHERE is_published = 1 AND published_at = 0');
        try {
            foreach (db_all('SELECT id, body FROM posts') as $p) {
                post_links_reindex((int)$p['id'], (string)$p['body']);
            }
        } catch (Throwable $e) {
            /* индекс ссылок перестраивается при каждом сохранении заметки — не делаем из-за него
               всю миграцию неудачной, но фиксируем в журнале */
            app_log_error('migrate v6: не удалось построить индекс вики-ссылок: ' . $e->getMessage());
        }
    }
    if ($v < 7) {
        /* 1.9.6: журнал входов сканируется по ip/login/(login,ip) при каждой попытке входа,
           а индекс был только по ts — на раздутой таблице сам троттлинг становился нагрузкой */
        db()->exec("
        CREATE INDEX IF NOT EXISTS idx_login_log_ip ON login_log(ip);
        CREATE INDEX IF NOT EXISTS idx_login_log_login ON login_log(login);
        CREATE INDEX IF NOT EXISTS idx_login_log_login_ip ON login_log(login, ip);");
    }
    if ($v < 8) {
        /* 1.9.25: фокусная точка кадра (0..1 по каждой оси, центр 0.5) — обложки и миниатюры
           с object-fit:cover позиционируются на неё, а не всегда на центр (идея из Koken CMS) */
        foreach ([
            ['images', 'focus_x', "ALTER TABLE images ADD COLUMN focus_x REAL NOT NULL DEFAULT 0.5"],
            ['images', 'focus_y', "ALTER TABLE images ADD COLUMN focus_y REAL NOT NULL DEFAULT 0.5"],
        ] as [$table, $column, $sql]) {
            if (!db_column_exists($table, $column)) {
                db()->exec($sql);
            }
        }
    }
    if ($v < 9) {
        /* 1.9.42: страницы-услуги — структурированная разметка Service (цена «от», город
           оказания, описание) вместо ручной вставки JSON-LD через jsonld_extra */
        foreach ([
            ['pages', 'is_service', "ALTER TABLE pages ADD COLUMN is_service INTEGER NOT NULL DEFAULT 0"],
            ['pages', 'service_name', "ALTER TABLE pages ADD COLUMN service_name TEXT NOT NULL DEFAULT ''"],
            ['pages', 'service_price', "ALTER TABLE pages ADD COLUMN service_price TEXT NOT NULL DEFAULT ''"],
            ['pages', 'service_currency', "ALTER TABLE pages ADD COLUMN service_currency TEXT NOT NULL DEFAULT 'RUB'"],
            ['pages', 'service_desc', "ALTER TABLE pages ADD COLUMN service_desc TEXT NOT NULL DEFAULT ''"],
            ['pages', 'service_area', "ALTER TABLE pages ADD COLUMN service_area TEXT NOT NULL DEFAULT ''"],
        ] as [$table, $column, $sql]) {
            if (!db_column_exists($table, $column)) {
                db()->exec($sql);
            }
        }
    }
    if ($v < 10) {
        /* 1.9.48: видеотека для раздела «Видео» (та же таблица, что в install_schema —
           свежие установки получают её сразу, schema_v ставится в обход миграций) */
        db()->exec("
        CREATE TABLE IF NOT EXISTS videos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file TEXT NOT NULL DEFAULT '',
            title TEXT NOT NULL DEFAULT '',
            description TEXT NOT NULL DEFAULT '',
            poster_image_id INTEGER,
            duration INTEGER,
            created_at TEXT NOT NULL DEFAULT ''
        );");
    }
    if ($v < 11) {
        /* 1.9.53: тело заметки — HTML из визуального редактора вместо wiki-разметки.
           Конвертируем тем же blog_render_body, но БЕЗ развёртывания медиа: шорткоды
           {photo:…}/{gallery:…} остаются текстовыми токенами в <p> и работают дальше
           (их разворачивает blog_render_html при показе). [[Ссылки]] превращаются в обычные
           <a> на slug/название заметки, заголовки ##/### — в h2/h3 с id-якорями.
           Ссылка на несуществующую заметку «застывает» пунктирным <span class="wiki-new"> —
           динамического оживления, как у wiki, больше нет. */
        try {
            foreach (db_all('SELECT id, body FROM posts') as $p) {
                $body = (string)$p['body'];
                if (trim($body) === '') {
                    continue;
                }
                $new = blog_render_body($body, '', false, false);
                if ($new !== $body) {
                    db_run('UPDATE posts SET body = ? WHERE id = ?', [$new, (int)$p['id']]);
                }
                post_links_reindex((int)$p['id'], $new);
            }
        } catch (Throwable $e) {
            /* Ошибку конвертации фиксируем и передаём в db_tx: частичная миграция
               не должна помечаться завершённой. */
            app_log_error('migrate v11: не удалось конвертировать тела заметок в HTML: ' . $e->getMessage());
            throw $e;
        }
    }
    if ($v < 12) {
        /* 1.9.54: заметки блога собираются из секций (текст, фото, галерея, кнопки…),
           как и страницы. В sections появляется владелец: owner_type='page' (как раньше)
           или 'post'. Тело заметки (HTML из редактора 1.9.53) режется на секции: абзацы-
           токены {photo:…}/{gallery:…} становятся настоящими секциями «Фото»/«Галерея»,
           остальной текст — секциями «Текст». Колонка posts.body остаётся как архив
           (для отката), но код её больше не читает. */
        if (!db_column_exists('sections', 'owner_type')) {
            db()->exec("ALTER TABLE sections ADD COLUMN owner_type TEXT NOT NULL DEFAULT 'page'");
        }
        db()->exec("CREATE INDEX IF NOT EXISTS idx_sections_owner ON sections(owner_type, page_id, position)");
        try {
            foreach (db_all("SELECT id, body FROM posts WHERE TRIM(body) <> ''") as $p) {
                $pos = 0;
                $linksHtml = '';
                foreach (post_body_to_sections((string)$p['body']) as $s) {
                    $pos++;
                    if ($s['type'] === 'photo') {
                        $d = section_defaults('photo');
                        $d['image_id'] = (int)$s['image_id'];
                        $o = (array)$s['opts'];
                        if (array_key_exists((string)($o['size'] ?? ''), photo_size_presets())) {
                            $d['size'] = (string)$o['size'];
                        }
                        if (($o['lb'] ?? null) !== null) {
                            $d['lightbox'] = $o['lb'] ? '1' : '0';
                        }
                        if (!empty($o['link']['url'])) {
                            $d['link'] = (string)$o['link']['url'];
                            $d['link_new_tab'] = !empty($o['link']['new_tab']);
                        }
                        db_insert("INSERT INTO sections (owner_type, page_id, type, position, data) VALUES ('post',?,?,?,?)", [(int)$p['id'], 'photo', $pos, json_db($d)]);
                    } elseif ($s['type'] === 'gallery') {
                        $d = section_defaults('gallery');
                        if ((int)($s['opts']['cols'] ?? 0) > 0) {
                            $d['columns'] = (int)$s['opts']['cols'];
                        }
                        if (($s['opts']['lb'] ?? null) !== null) {
                            $d['lightbox'] = $s['opts']['lb'] ? '1' : '0';
                        }
                        $sid = db_insert("INSERT INTO sections (owner_type, page_id, type, position, data) VALUES ('post',?,?,?,?)", [(int)$p['id'], 'gallery', $pos, json_db($d)]);
                        gallery_replace('section', $sid, (array)$s['ids']);
                    } else {
                        $d = section_defaults('text');
                        $d['html'] = (string)$s['html'];
                        $linksHtml .= $d['html'] . "\n";
                        db_insert("INSERT INTO sections (owner_type, page_id, type, position, data) VALUES ('post',?,?,?,?)", [(int)$p['id'], 'text', $pos, json_db($d)]);
                    }
                }
                /* индекс ссылок: ссылки переехали из body в текстовые секции */
                post_links_reindex((int)$p['id'], $linksHtml);
            }
        } catch (Throwable $e) {
            /* Ошибка откатит всю транзакцию: старые записи останутся для следующей попытки. */
            app_log_error('migrate v12: не удалось разнести тексты заметок по секциям: ' . $e->getMessage());
            throw $e;
        }
    }
    if ($v < 13) {
        /* 1.9.55: свой canonical у заметок — как у страниц (заметка-дубль может указать
           на первоисточник). Тот же набор правил валидации, что в page_save. */
        if (!db_column_exists('posts', 'canonical')) {
            db()->exec("ALTER TABLE posts ADD COLUMN canonical TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($v < 14) {
        /* 1.9.76: раскладка галереи альбома — masonry (по умолчанию, ''), grid или lineup.
           У разделов-галерей выбор раскладки был давно, у альбомов — только masonry. */
        if (!db_column_exists('pages', 'album_layout')) {
            db()->exec("ALTER TABLE pages ADD COLUMN album_layout TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($v < 15) {
        /* 1.9.77: бейдж страницы («35 мм плёнка», «плёнка + цифра»…) — цветная плашка
           в углу обложки на карточках списков и «Других работ»; пусто — без бейджа */
        if (!db_column_exists('pages', 'badge')) {
            db()->exec("ALTER TABLE pages ADD COLUMN badge TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($v < 16) {
        if (!db_column_exists('pages', 'stamp_text')) {
            db()->exec("ALTER TABLE pages ADD COLUMN stamp_text TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($v < 17) {
        /* 1.9.96: данные съёмки у страницы (камера · плёнка · дата) — выводятся
           один раз под заголовком альбома; настоящие данные автора, не из EXIF */
        if (!db_column_exists('pages', 'shoot_note')) {
            db()->exec("ALTER TABLE pages ADD COLUMN shoot_note TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($v < 18) {
        /* 1.9.102: установки, уже отмеченные v17 без stamp_text, догоняем отдельно. */
        if (!db_column_exists('pages', 'stamp_text')) {
            db()->exec("ALTER TABLE pages ADD COLUMN stamp_text TEXT NOT NULL DEFAULT ''");
        }
    }
}

/**
 * Согласованный снимок базы в файл $dest (с 1.9.10; до этого логика жила только в кнопке
 * «Скачать копию базы»). VACUUM INTO даёт целостный снимок с учётом WAL; на SQLite старше
 * 3.27 его нет — тогда сначала переносим WAL в основной файл (checkpoint) и копируем файл.
 * Копировать site.sqlite «на лету» нельзя: свежие записи могут лежать в -wal и в копию не попасть.
 * true — файл создан; при неудаче недоделанного файла не остаётся.
 */
function db_snapshot_to(string $dest): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        app_log_error('snapshot: не удалось создать каталог ' . $dir);
        return false;
    }
    @chmod($dir, 0700);
    $tmp = $dest . '.tmp-' . bin2hex(random_bytes(6));
    @unlink($tmp);
    $ok = false;
    try {
        db()->exec('VACUUM INTO ' . db()->quote($tmp));
        $ok = is_file($tmp);
    } catch (Throwable $e) {
        app_log_error('snapshot: VACUUM INTO недоступен (' . $e->getMessage() . '), копирую файл после checkpoint');
    }
    if (!$ok) {
        try {
            $res = db()->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch();
            $ok = is_array($res) && (int)($res['busy'] ?? 1) === 0;
        } catch (Throwable $e) {
            app_log_error('snapshot: WAL-checkpoint не удался (' . $e->getMessage() . ')');
            $ok = false;
        }
        if ($ok) {
            /* Между checkpoint и copy() другой запрос мог успеть записать в базу — копия вышла бы
               неконсистентной (аудит 1.9.26). BEGIN IMMEDIATE берёт RESERVED-блокировку: другие
               писатели не смогут зафиксировать транзакцию, пока идёт копирование (читатели в WAL
               не мешают). Транзакцию ничего не пишет — только держит блокировку. */
            $locked = false;
            try {
                db()->exec('BEGIN IMMEDIATE');
                $locked = true;
                $ok = @copy(db_path(), $tmp);
                db()->exec('COMMIT');
                $locked = false;
            } catch (Throwable $e) {
                if ($locked) {
                    try { db()->exec('ROLLBACK'); } catch (Throwable $ignored) {}
                }
                app_log_error('snapshot: копирование под блокировкой не удалось (' . $e->getMessage() . ')');
                $ok = false;
            }
        }
    }
    if ($ok) {
        @chmod($tmp, 0600);
        $ok = @rename($tmp, $dest);
        if ($ok) {
            @chmod($dest, 0600);
        }
    }
    if (!$ok) {
        @unlink($tmp);
        app_log_error('snapshot: не удалось создать снимок в ' . $dest);
    }
    return $ok;
}
