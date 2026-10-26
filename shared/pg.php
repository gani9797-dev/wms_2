<?php
declare(strict_types=1);

// PostgreSQL-слой WMS TiMLog.
// Переезд с SQLite: единая БД вместо 4 файлов (receipts/nomenclature/storage/tasks).
// Настройки через env (или .env рядом с корнем):
//   WMS_DB_DRIVER=pgsql
//   PG_HOST=127.0.0.1 PG_PORT=5432 PG_DB=wms PG_USER=wms PG_PASSWORD=secret
//   или DATABASE_URL=postgresql://wms:secret@127.0.0.1:5432/wms
// Совместимо: существующий код, который ветвится mysql vs sqlite,
// должен считать pgsql как "не-sqlite" (NOW(), ON CONFLICT, information_schema).

function wms_driver(PDO $pdo): string
{
    try {
        $d = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (is_string($d) && $d !== '') return $d;
    } catch (Throwable) {}
    return 'unknown';
}

function wms_is_sqlite(PDO $pdo): bool { return wms_driver($pdo) === 'sqlite'; }
function wms_is_mysql(PDO $pdo): bool { return wms_driver($pdo) === 'mysql'; }
function wms_is_pgsql(PDO $pdo): bool { return wms_driver($pdo) === 'pgsql'; }

/** NOW() для mysql/pgsql, datetime('now') для sqlite. */
function wms_now_sql(PDO $pdo): string
{
    return wms_is_sqlite($pdo) ? "datetime('now')" : 'NOW()';
}

/**
 * Идентификатор в кавычках текущей СУБД.
 * mysql -> `sum`, pgsql/sqlite -> "sum" (в mysql двойные кавычки — строковый литерал).
 */
function wms_quote_ident(PDO $pdo, string $name): string
{
    if (wms_is_mysql($pdo)) {
        return '`' . str_replace('`', '``', $name) . '`';
    }
    return '"' . str_replace('"', '""', $name) . '"';
}

// ============================================================
// СОРТИРОВКА ТАБЛИЦ: А→Я / Я→А
// ============================================================

/** Ключ сортировки «А→Я» без mbstring: латиница и кириллица в нижний регистр, Ё=Е.
 *  Порядок букв получается русский алфавитный (А,Б,В…Я, а,б,в…я). */
function wms_sort_key(string $s): string
{
    $s = strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $b = ord($s[$i]);
        if ($b === 0xD0 && $i + 1 < $len) {
            $cp = 0x400 + (ord($s[$i + 1]) - 0x80);       // код символа U+0400..
            if ($cp >= 0x410 && $cp <= 0x42F) {            // А..Я -> а..я
                $cp += 0x20;
                $out .= ($cp <= 0x43F)
                    ? "\xD0" . chr(0x80 + ($cp - 0x400))    // а..п
                    : "\xD1" . chr(0x80 + ($cp - 0x440));   // р..я
                $i++;
                continue;
            }
            if ($cp === 0x401) {                          // Ё -> Е (рядом по алфавиту)
                $out .= "\xD0\xB5";
                $i++;
                continue;
            }
        }
        $out .= $s[$i];
    }
    return $out;
}

/** SQLite: регистрация коллации RU (А→Я по-русски, без учёта регистра). */
function wms_register_ru_collation(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) return;
    $done[$id] = true;
    try {
        $pdo->sqliteCreateCollation('RU', function ($a, $b) {
            return strcmp(wms_sort_key((string)$a), wms_sort_key((string)$b));
        });
    } catch (Throwable) {}
}

/**
 * Выражение ORDER BY с учётом СУБД.
 *  $type = 't' — текст: sqlite -> COLLATE RU, mysql/pgsql -> LOWER()
 *  $type = 'n' — число: сортируем как есть
 *  $type = 'd' — дата/время: сортируем как есть (LOWER() по дате недопустим)
 */
function wms_order_expr(PDO $pdo, string $expr, string $type = 't'): string
{
    if (strpos($expr, ',') !== false) return $expr;   // составной ORDER BY — не оборачиваем
    if ($type === 't') {
        if (wms_is_sqlite($pdo)) {
            wms_register_ru_collation($pdo);
            return "$expr COLLATE RU";
        }
        return "LOWER($expr)";
    }
    return $expr;
}

/**
 * Собрать ORDER BY из карты сортировки.
 *  $map: ключ => [выражение, 't' (текст) | 'n' (число) | 'd' (дата)] либо строка (текст).
 *  Для дат пустые значения всегда уходят в конец (портируемый синтаксис).
 */
function wms_order_by(PDO $pdo, string $sort, string $dir, array $map, string $default, string $defaultType = 't'): string
{
    $expr = $default;
    $type = $defaultType;
    if (isset($map[$sort])) {
        $v = $map[$sort];
        if (is_array($v)) {
            $expr = (string)($v[0] ?? $default);
            $type = (string)($v[1] ?? 't');
        } else {
            $expr = (string)$v;
        }
    }
    $d = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';
    if ($type === 'd' && strpos($expr, ',') === false) {
        return "($expr IS NULL) ASC, $expr $d";
    }
    return wms_order_expr($pdo, $expr, $type) . ' ' . $d;
}

/** Портативная проверка наличия колонки (sqlite PRAGMA / mysql SHOW / pgsql information_schema). */
function wms_table_columns(PDO $pdo, string $table): array
{
    $d = wms_driver($pdo);
    try {
        if ($d === 'mysql') {
            $out = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $out[] = (string)($c['Field'] ?? '');
            }
            return $out;
        }
        if ($d === 'pgsql') {
            $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :t");
            $st->execute([':t' => $table]);
            return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        $out = [];
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[] = (string)($c['name'] ?? '');
        }
        return $out;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Портативная проверка «есть ли колонка» (sqlite PRAGMA / mysql SHOW / pgsql information_schema).
 * В отличие от голого PRAGMA table_info() работает на всех драйверах —
 * PRAGMA на PostgreSQL роняет соединение синтаксической ошибкой.
 */
function wms_has_column(PDO $pdo, string $table, string $col): bool
{
    return in_array($col, wms_table_columns($pdo, $table), true);
}

/**
 * Дотянуть колонку, если её нет: MySQL / SQLite / PostgreSQL.
 * DDL-фрагмент может приходить как с именем колонки, так и без него —
 * приводим оба варианта к рабочему виду (иначе ALTER молча падает в catch).
 */
function wms_ensure_column(PDO $pdo, string $table, string $col, string $ddlMysql, string $ddlSqlite, string $ddlPg): void
{
    try {
        if (in_array($col, wms_table_columns($pdo, $table), true)) return;
        $d = wms_driver($pdo);
        $norm = static function (string $ddl) use ($col): string {
            $ddl = trim($ddl);
            if ($ddl === '') return $ddl;
            $first = strtok($ddl, " \t\n\r(");
            if ($first !== false && strcasecmp((string)$first, $col) === 0) return $ddl;
            return $col . ' ' . $ddl;
        };
        if ($d === 'mysql') $pdo->exec("ALTER TABLE `$table` ADD COLUMN " . $norm($ddlMysql));
        elseif ($d === 'pgsql') $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN ' . $norm($ddlPg));
        else $pdo->exec("ALTER TABLE $table ADD COLUMN " . $norm($ddlSqlite));
    } catch (Throwable) {}
}

/** INSERT-игнор портированно: sqlite/pgsql = ON CONFLICT DO NOTHING, mysql = INSERT IGNORE. */
function wms_insert_ignore_prefix(PDO $pdo): string
{
    return wms_is_mysql($pdo) ? 'INSERT IGNORE INTO' : 'INSERT INTO';
}

function wms_insert_ignore_suffix(PDO $pdo, ?string $conflictTarget = null): string
{
    if (wms_is_mysql($pdo)) return '';
    if (wms_is_pgsql($pdo)) return $conflictTarget ? " ON CONFLICT $conflictTarget DO NOTHING" : ' ON CONFLICT DO NOTHING';
    return ' OR IGNORE';
}

/**
 * Создать таблицу, если её ещё нет — БЕЗ блокировки таблицы на каждый запрос.
 *
 * Обычный «CREATE TABLE IF NOT EXISTS» в PostgreSQL всё равно берёт
 * ACCESS EXCLUSIVE-блокировку на существующую таблицу. Если в этот момент
 * другая сессия держит транзакцию с блокировкой на той же таблице (например,
 * пишет в журнал серийных номеров), возникает взаимоблокировка: сессия ждёт
 * DDL, DDL ждёт транзакцию. Поэтому сначала проверяем наличие таблицы.
 */
function wms_ensure_table(PDO $pdo, string $table, string $ddl): void
{
    try {
        $d = wms_driver($pdo);
        if ($d === 'pgsql') {
            $st = $pdo->prepare(
                "SELECT 1 FROM information_schema.tables
                  WHERE table_schema = current_schema() AND table_name = :t LIMIT 1"
            );
            $st->execute([':t' => $table]);
            if ($st->fetchColumn() !== false) {
                return;
            }
        } elseif ($d === 'mysql') {
            $st = $pdo->prepare('SHOW TABLES LIKE :t');
            $st->execute([':t' => $table]);
            if ($st->fetchColumn() !== false) {
                return;
            }
        } else {
            // sqlite: имя таблины может содержать префикс подключённой БД (wms_common.x).
            $schema = 'main';
            $name = $table;
            if (str_contains($table, '.')) {
                [$schema, $name] = explode('.', $table, 2);
            }
            $st = $pdo->prepare("SELECT 1 FROM \"$schema\".sqlite_master WHERE type='table' AND name = :t LIMIT 1");
            $st->execute([':t' => $name]);
            if ($st->fetchColumn() !== false) {
                return;
            }
        }
        $pdo->exec($ddl);
    } catch (Throwable) {
    }
}

/**
 * Создать индекс, если его ещё нет — БЕЗ блокировки таблицы на каждый запрос
 * (см. wms_ensure_table — почему «IF NOT EXISTS» недостаточно).
 */
function wms_ensure_index(PDO $pdo, string $table, string $index, string $columns): void
{
    try {
        $d = wms_driver($pdo);
        if ($d === 'pgsql') {
            $st = $pdo->prepare(
                "SELECT 1 FROM pg_indexes
                  WHERE schemaname = current_schema() AND tablename = :t AND indexname = :i LIMIT 1"
            );
            $st->execute([':t' => $table, ':i' => $index]);
            if ($st->fetchColumn() !== false) {
                return;
            }
            $pdo->exec('CREATE INDEX IF NOT EXISTS "' . $index . '" ON "' . $table . '" (' . $columns . ')');
            return;
        }
        if ($d === 'mysql') {
            $st = $pdo->prepare('SHOW INDEX FROM `' . $table . '` WHERE Key_name = :i');
            $st->execute([':i' => $index]);
            if ($st->fetchColumn() !== false) {
                return;
            }
            $pdo->exec('CREATE INDEX `' . $index . '` ON `' . $table . '` (' . $columns . ')');
            return;
        }
        // sqlite: имя индекса и таблицы в CREATE INDEX не допускают префикс схемы —
        // таблица ищется во всех подключённых БД, индекс создаётся в её схеме.
        $schema = 'main';
        $name = $table;
        if (str_contains($table, '.')) {
            [$schema, $name] = explode('.', $table, 2);
        }
        $st = $pdo->prepare("SELECT 1 FROM \"$schema\".sqlite_master WHERE type='index' AND name = :i LIMIT 1");
        $st->execute([':i' => $index]);
        if ($st->fetchColumn() !== false) {
            return;
        }
        $pdo->exec("CREATE INDEX IF NOT EXISTS \"$index\" ON \"$name\" ($columns)");
    } catch (Throwable) {
    }
}

/** Загрузка .env из корня проекта в окружение (не перезаписывает системные env). */
function wms_load_env(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $ln = trim($ln);
        if ($ln === '' || str_starts_with($ln, '#') || !str_contains($ln, '=')) continue;
        [$k, $v] = explode('=', $ln, 2);
        $k = trim($k); $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
}

/** Подключение к PostgreSQL по env. */
function wms_pg_pdo(): PDO
{
    wms_load_env();
    $url = getenv('DATABASE_URL');
    if ($url) {
        // postgresql://user:pass@host:port/db
        $p = parse_url($url);
        $host = $p['host'] ?? '127.0.0.1';
        $port = (string)($p['port'] ?? '5432');
        $db = ltrim((string)($p['path'] ?? 'wms'), '/');
        $user = $p['user'] ?? 'wms';
        $pass = $p['pass'] ?? '';
    } else {
        // .env файл рядом с корнем (опционально)
        wms_load_env();
        $host = (string)(getenv('PG_HOST') ?: '127.0.0.1');
        $port = (string)(getenv('PG_PORT') ?: '5432');
        $db = (string)(getenv('PG_DB') ?: 'wms');
        $user = (string)(getenv('PG_USER') ?: 'wms');
        $pass = (string)(getenv('PG_PASSWORD') ?: 'secret');
    }
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Единый updated_at без MySQL-триггера ON UPDATE: обновляем вручную в коде.
    // Для совместимости: created_at/updated_at по умолчанию NOW().
    return $pdo;
}

/** Единая точка: если WMS_DB_DRIVER=pgsql — вернуть PG, иначе null (остаться на sqlite). */
function wms_main_pdo(): ?PDO
{
    $drv = strtolower((string)(getenv('WMS_DB_DRIVER') ?: ''));
    if ($drv === 'pgsql' || $drv === 'postgres' || $drv === 'postgresql') {
        return wms_pg_pdo();
    }
    return null;
}

// Автозагрузка .env при подключении слоя: веб-процесс (php -S) не имеет
// системных env-переменных, и без этого wms_use_pgsql() всегда видел бы
// пустой WMS_DB_DRIVER и молча работал с SQLite вместо PostgreSQL.
wms_load_env();
