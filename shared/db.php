<?php
declare(strict_types=1);

// Единая точка доступа к БД.
// Режимы:
//   SQLite (демо по умолчанию): 5 файлов (см. ниже) — по одному на предметную область.
//   Канонические файлы:
//     поступления+ТСД-приход  = 02-postupleniya/wms_receipts.sqlite (wms_receipts_pdo)
//     отгрузки+ТСД-отбор      = 03-otgruzki/wms_demo.sqlite (wms_shipments_pdo)
//     ячейки                  = 05-yacheyki-hraneniya/wms_storage.sqlite (wms_storage_pdo)
//     номенклатура+запас 8.1  = 08-nomenklatura/wms_nomenclature.sqlite (wms_nomenclature_pdo)
//     задания                 = 07-zadaniya/wms_tasks.sqlite (wms_tasks_pdo)
//     сотрудники (все модули) = shared/wms_users.sqlite (wms_users_pdo)
//   Легаси-дубли (receipts/, shipments/, tasks/) УДАЛЕНЫ — каждый модуль
//     существует в единственном экземпляре, в пронумерованной папке.
//   Все точки (ТСД, веб-модули) ходят только через wms_*_pdo().
//   PostgreSQL: WMS_DB_DRIVER=pgsql + PG_* в env/.env — все wms_*_pdo()
//     возвращают ОДНО подключение к единой БД `wms` (db/schema-pg.sql).
// В проде (MySQL, index.php) обе предметные области уже живут в одной БД `wms`,
// поэтому разделение файлов — только демо-режим SQLite.

require_once __DIR__ . '/pg.php';

function wms_use_pgsql(): bool
{
    $d = strtolower((string)(getenv('WMS_DB_DRIVER') ?: ''));
    return $d === 'pgsql' || $d === 'postgres' || $d === 'postgresql';
}

function wms_root(): string
{
    return dirname(__DIR__);
}

function wms_receipts_db_file(): string
{
    return wms_root() . '/02-postupleniya/wms_receipts.sqlite';
}

function wms_nomenclature_db_file(): string
{
    return wms_root() . '/08-nomenklatura/wms_nomenclature.sqlite';
}

function wms_storage_db_file(): string
{
    return wms_root() . '/05-yacheyki-hraneniya/wms_storage.sqlite';
}

// Канонический файл справочника сотрудников (модуль 10).
function wms_employees_db_file(): string
{
    return wms_root() . '/10-sotrudniki/wms_employees.sqlite';
}

// Канонический файл отгрузок: 03-otgruzki/wms_demo.sqlite.
// Его используют модуль 03 (03-otgruzki/demo_sqlite.php), заказы 03.1,
// ТСД (tsd/ship.php, tsd/otbor.php, tsd/info.php action=shipment) —
// все видят одни и те же отгрузки и один и тот же факт tsd_picks.
function wms_shipments_db_file(): string
{
    return wms_root() . '/03-otgruzki/wms_demo.sqlite';
}

/**
 * Канонический файл БД заданий: 07-zadaniya/wms_tasks.sqlite.
 * Миграция с прежнего расположения (tasks/wms_tasks.sqlite):
 * если нового файла ещё нет, но старый на месте — используем старый
 * (данные не теряются), файл создастся по новому пути при следующем развёртывании.
 */
function wms_tasks_db_file(): string
{
    $new = wms_root() . '/07-zadaniya/wms_tasks.sqlite';
    if (is_file($new)) {
        return $new;
    }
    $legacy = wms_root() . '/tasks/wms_tasks.sqlite';
    if (is_file($legacy)) {
        return $legacy;
    }
    return $new;
}

// ============================================================
// ЕДИНЫЙ СПРАВОЧНИК СОТРУДНИКОВ
// ============================================================
// Таблица users одна на все модули WMS.
//   PostgreSQL (боевой режим): users уже одна таблица в общей БД wms —
//     подключать ничего не нужно.
//   SQLite (демо): канонический файл shared/wms_users.sqlite, к нему
//     подключаются (ATTACH ... AS wms_users) все модульные БД. Локальные
//     копии users переносятся в каноническую и удаляются — после этого
//     запросы вида "JOIN users u" резолвятся в подключённую wms_users,
//     поэтому код модулей менять не нужно.

/** Канонический файл БД сотрудников (SQLite-режим). */
function wms_users_db_file(): string
{
    return wms_root() . '/shared/wms_users.sqlite';
}

/** Сиды справочника сотрудников (общие для всех модулей). */
function wms_users_seeds(): array
{
    return [
        [1, 'Иванов И.И.', 'Администратор', 'Администратор'],
        [2, 'Петров А.С.', 'Кладовщик', 'Приемщик'],
        [3, 'Сидоров К.В.', 'Кладовщик', 'Кладовщик'],
        [4, 'Козлов Д.Н.', 'Менеджер', 'Менеджер'],
        [5, 'Морозов А.Л.', 'Водитель', 'Водитель'],
        [6, 'Мишин Максим', 'Кладовщик', 'Кладовщик'],
        [11, '11 Ибрагимов Е.С.', 'Кладовщик', 'Кладовщик'],
        [233, '233 Петрова М.А.', 'Кладовщик', 'Кладовщик'],
        [258, '258 Смирнов А.С.', 'Кладовщик', 'Кладовщик'],
        [512, '512 - Иванов Михаил', 'Кладовщик', 'Кладовщик'],
        [819, '819 Шветцов А.В.', 'Кладовщик', 'Кладовщик'],
        [1220, '1220 Михайлова О.С.', 'Кладовщик', 'Кладовщик'],
        [1287, '1287 Ш Феничев Олег', 'Кладовщик', 'Кладовщик'],
        [1465, '1465 Ионова М.И.', 'Кладовщик', 'Кладовщик'],
        [1788, '1788 - Смирнов Николай', 'Менеджер', 'Менеджер'],
        [3315, '3315 Иванов П.М.', 'Кладовщик', 'Кладовщик'],
    ];
}

/**
 * База для выдачи PIN сотрудникам по умолчанию.
 * По умолчанию 1000 — PIN получается 1000+id (совпадает с прежним поведением).
 * ВАЖНО: эта схема ПРЕДСКАЗУЕМА. В рабочем контуре задайте WMS_PIN_BASE=0
 * (тогда PIN не выдаётся вовсе — его назначает администратор в модуле 10
 * «Сотрудники») либо задайте свой отступ.
 * @return int база PIN; 0 = автоматическая выдача отключена
 */
function wms_pin_base(): int
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (function_exists('wms_load_env')) {
        wms_load_env();
    }
    $raw = trim((string)(getenv('WMS_PIN_BASE') ?: ''));
    $base = ($raw === '') ? 1000 : (int)$raw;
    if ($base < 0) {
        $base = 0;
    }
    return $base;
}

/** Создаёт/дотягивает каноническую таблицу users и заливает сиды.
 *  $tbl — имя таблицы; для подключённой (ATTACH) БД: 'wms_users.users'. */
function wms_ensure_users_schema(PDO $pdo, string $tbl = 'users'): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pinBase = wms_pin_base();
    if ($drv === 'pgsql') {
        wms_ensure_column($pdo, 'users', 'position', 'VARCHAR(100)', 'position TEXT', 'VARCHAR(100)');
        wms_ensure_column($pdo, 'users', 'pin', 'VARCHAR(10)', 'pin TEXT', 'VARCHAR(10)');
        // Общие сиды сотрудников — как в SQLite-демо.
        try {
            $ins = $pdo->prepare("INSERT INTO users (id,fio,role,position,pin) VALUES (:id,:fio,:role,:pos,:pin)
                                  ON CONFLICT (id) DO NOTHING");
            foreach (wms_users_seeds() as $u) {
                $ins->execute([':id' => $u[0], ':fio' => $u[1], ':role' => $u[2], ':pos' => $u[3], ':pin' => null]);
            }
            $pdo->exec("SELECT setval(pg_get_serial_sequence('users','id'), GREATEST((SELECT COALESCE(MAX(id),0)+1 FROM users), 2), false)");
        } catch (Throwable) {
        }
        if ($pinBase > 0) {
            try {
                $pdo->exec("UPDATE users SET pin=LPAD((" . $pinBase . "+id)::text,4,'0') WHERE pin IS NULL OR pin=''");
            } catch (Throwable) {
            }
        }
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS $tbl (
      id INTEGER PRIMARY KEY AUTOINCREMENT, fio TEXT NOT NULL, role TEXT DEFAULT 'storekeeper',
      position TEXT, pin TEXT)");
    $ins = $pdo->prepare("INSERT INTO $tbl (id,fio,role,position,pin) VALUES (:id,:fio,:role,:pos,:pin)
                           ON CONFLICT(id) DO NOTHING");
    foreach (wms_users_seeds() as $u) {
        $ins->execute([':id' => $u[0], ':fio' => $u[1], ':role' => $u[2], ':pos' => $u[3], ':pin' => null]);
    }
    if ($pinBase > 0) {
        try {
            $pdo->exec("UPDATE $tbl SET pin=printf('%04d'," . $pinBase . "+id) WHERE pin IS NULL OR pin=''");
        } catch (Throwable) {
        }
    }
}

/** Подключение канонической БД сотрудников к модульной БД (SQLite). */
function wms_users_attach(PDO $pdo): void
{
    if (wms_use_pgsql()) {
        wms_ensure_users_schema($pdo);
        return;
    }
    $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv !== 'sqlite') {
        return;
    }
    // Уже подключено в этом соединении? Проверяем по списку баз:
    // spl_object_id() переиспользуется после освобождения PDO — не годится.
    try {
        foreach ($pdo->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if ((string)($d['name'] ?? '') === 'wms_users') {
                return;
            }
        }
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec("ATTACH DATABASE " . $pdo->quote(wms_users_db_file()) . " AS wms_users");
    } catch (Throwable $e) {
        return; // остаёмся на локальной копии — работа не ломается
    }
    try {
        // Схема + сиды канонической таблицы — прямо в подключённой БД.
        wms_ensure_users_schema($pdo, 'wms_users.users');
    } catch (Throwable $e) {
    }
    try {
        $hasLocal = (string)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn() !== '';
        if (!$hasLocal) {
            return;
        }
        // Перенос локальной копии в каноническую: канонические значения
        // приоритетны, из локальной берём только недостающие position/pin.
        $cols = [];
        foreach ($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[] = (string)$c['name'];
        }
        $rows = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        $up = $pdo->prepare("INSERT INTO wms_users.users (id,fio,role,position,pin) VALUES (:id,:fio,:role,:pos,:pin)
                                ON CONFLICT(id) DO UPDATE SET
                                  fio=COALESCE(NULLIF(wms_users.users.fio,''),EXCLUDED.fio),
                                  role=COALESCE(NULLIF(wms_users.users.role,''),EXCLUDED.role),
                                  position=COALESCE(NULLIF(wms_users.users.position,''),EXCLUDED.position),
                                  pin=COALESCE(NULLIF(wms_users.users.pin,''),EXCLUDED.pin)");
        foreach ($rows as $r) {
            if (!isset($r['id'])) {
                continue;
            }
            $up->execute([
                ':id' => (int)$r['id'],
                ':fio' => (string)($r['fio'] ?? ''),
                ':role' => (string)($r['role'] ?? ''),
                ':pos' => in_array('position', $cols, true) ? (string)($r['position'] ?? '') : '',
                ':pin' => in_array('pin', $cols, true) ? (string)($r['pin'] ?? '') : '',
            ]);
        }
        $pdo->exec("DROP TABLE users");
    } catch (Throwable $e) {
        // миграция не удалась — остаёмся на локальной копии, работаем как раньше
    }
}

/** Каноническая БД сотрудников (SQLite) / общая БД (PostgreSQL). */
function wms_users_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_ensure_users_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_users_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_ensure_users_schema($pdo);
    return $pdo;
}

// ============================================================
// МОДУЛЬ 10: СОТРУДНИКИ (реестр + карточка)
// ============================================================
/**
 * Схема справочника сотрудников: карточка, графики, права и роли,
 * история выходов (СКУД), присутствие и история изменений.
 * Идемпотентна: CREATE IF NOT EXISTS + ALTER при отсутствии колонки.
 */
function wms_ensure_employees_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        $ddl = [
            "CREATE TABLE IF NOT EXISTS employees (
              id BIGSERIAL PRIMARY KEY,
              fio VARCHAR(150) NOT NULL,
              last_name VARCHAR(100) NULL,
              first_name VARCHAR(100) NULL,
              middle_name VARCHAR(100) NULL,
              position VARCHAR(100) NULL,
              code VARCHAR(10) NULL,
              department VARCHAR(100) NULL,
              personnel_no VARCHAR(30) NULL,
              pin VARCHAR(10) NULL UNIQUE,
              photo_path VARCHAR(255) NULL,
              phone_internal VARCHAR(50) NULL,
              phone_mobile VARCHAR(50) NULL,
              email VARCHAR(150) NULL,
              birth_date DATE NULL,
              hired_at DATE NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'active',
              role VARCHAR(50) NOT NULL DEFAULT 'storekeeper',
              zone VARCHAR(50) NOT NULL DEFAULT 'Все зоны',
              warehouse_id BIGINT NULL,
              tsd_access SMALLINT NOT NULL DEFAULT 0,
              is_active SMALLINT NOT NULL DEFAULT 1,
              is_blocked SMALLINT NOT NULL DEFAULT 0,
              allow_unplanned_exit SMALLINT NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT NOW(),
              updated_at TIMESTAMP NOT NULL DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS employee_schedules (
              id BIGSERIAL PRIMARY KEY,
              employee_id BIGINT NOT NULL,
              date_from DATE NOT NULL, date_to DATE NOT NULL,
              pattern VARCHAR(10) NOT NULL, shift VARCHAR(30) NOT NULL,
              work_from TIME NULL, work_to TIME NULL,
              created_at TIMESTAMP NOT NULL DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS employee_access (
              id BIGSERIAL PRIMARY KEY,
              employee_id BIGINT NOT NULL,
              scope VARCHAR(10) NOT NULL DEFAULT 'wms',
              module_key VARCHAR(50) NOT NULL,
              level VARCHAR(20) NOT NULL DEFAULT 'none',
              created_at TIMESTAMP NOT NULL DEFAULT NOW(),
              UNIQUE (employee_id, scope, module_key))",
            "CREATE TABLE IF NOT EXISTS role_access (
              id BIGSERIAL PRIMARY KEY,
              role VARCHAR(50) NOT NULL,
              scope VARCHAR(10) NOT NULL DEFAULT 'wms',
              module_key VARCHAR(50) NOT NULL,
              level VARCHAR(20) NOT NULL DEFAULT 'none',
              created_at TIMESTAMP NOT NULL DEFAULT NOW(),
              UNIQUE (role, scope, module_key))",
            "CREATE TABLE IF NOT EXISTS employee_exits (
              id BIGSERIAL PRIMARY KEY,
              employee_id BIGINT NOT NULL,
              event_at TIMESTAMP NOT NULL,
              checkpoint VARCHAR(100) NULL,
              direction VARCHAR(10) NOT NULL DEFAULT 'in',
              created_at TIMESTAMP NOT NULL DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS employee_attendance (
              id BIGSERIAL PRIMARY KEY,
              employee_id BIGINT NOT NULL,
              work_date DATE NOT NULL,
              start_at TIME NULL, end_at TIME NULL,
              worked_minutes INTEGER NOT NULL DEFAULT 0,
              on_shift SMALLINT NOT NULL DEFAULT 0,
              created_at TIMESTAMP NOT NULL DEFAULT NOW())",
            "CREATE TABLE IF NOT EXISTS employee_history (
              id BIGSERIAL PRIMARY KEY,
              employee_id BIGINT NOT NULL,
              user_id BIGINT NULL, user_fio VARCHAR(150) NULL,
              field VARCHAR(50) NOT NULL, field_label VARCHAR(100) NULL,
              old_value TEXT NULL, new_value TEXT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT NOW())",
            "CREATE INDEX IF NOT EXISTS idx_emp_fio ON employees (fio)",
            "CREATE INDEX IF NOT EXISTS idx_emp_department ON employees (department)",
            "CREATE INDEX IF NOT EXISTS idx_emp_status ON employees (status)",
            "CREATE INDEX IF NOT EXISTS idx_esch_emp ON employee_schedules (employee_id)",
            "CREATE INDEX IF NOT EXISTS idx_eex_emp ON employee_exits (employee_id)",
            "CREATE INDEX IF NOT EXISTS idx_eatt_emp ON employee_attendance (employee_id)",
            "CREATE INDEX IF NOT EXISTS idx_eatt_date ON employee_attendance (work_date)",
            "CREATE INDEX IF NOT EXISTS idx_ehist_emp ON employee_history (employee_id)",
        ];
        foreach ($ddl as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable) {
            }
        }
        // DDL для дотягивания колонок: имя колонки + тип (MySQL / SQLite / PostgreSQL).
        $cols = [
            ['last_name', 'last_name VARCHAR(100) NULL', 'last_name TEXT NULL', 'last_name VARCHAR(100) NULL'],
            ['first_name', 'first_name VARCHAR(100) NULL', 'first_name TEXT NULL', 'first_name VARCHAR(100) NULL'],
            ['middle_name', 'middle_name VARCHAR(100) NULL', 'middle_name TEXT NULL', 'middle_name VARCHAR(100) NULL'],
            ['code', 'code VARCHAR(10) NULL', 'code TEXT NULL', 'code VARCHAR(10) NULL'],
            ['department', 'department VARCHAR(100) NULL', 'department TEXT NULL', 'department VARCHAR(100) NULL'],
            ['personnel_no', 'personnel_no VARCHAR(30) NULL', 'personnel_no TEXT NULL', 'personnel_no VARCHAR(30) NULL'],
            ['photo_path', 'photo_path VARCHAR(255) NULL', 'photo_path TEXT NULL', 'photo_path VARCHAR(255) NULL'],
            ['phone_internal', 'phone_internal VARCHAR(50) NULL', 'phone_internal TEXT NULL', 'phone_internal VARCHAR(50) NULL'],
            ['phone_mobile', 'phone_mobile VARCHAR(50) NULL', 'phone_mobile TEXT NULL', 'phone_mobile VARCHAR(50) NULL'],
            ['email', 'email VARCHAR(150) NULL', 'email TEXT NULL', 'email VARCHAR(150) NULL'],
            ['birth_date', 'birth_date DATE NULL', 'birth_date TEXT NULL', 'birth_date DATE NULL'],
            ['hired_at', 'hired_at DATE NULL', 'hired_at TEXT NULL', 'hired_at DATE NULL'],
            ['status', 'status VARCHAR(20) NOT NULL DEFAULT \'active\'', 'status TEXT DEFAULT \'active\'', 'status VARCHAR(20) NOT NULL DEFAULT \'active\''],
            ['role', 'role VARCHAR(50) NOT NULL DEFAULT \'storekeeper\'', 'role TEXT DEFAULT \'storekeeper\'', 'role VARCHAR(50) NOT NULL DEFAULT \'storekeeper\''],
            ['zone', 'zone VARCHAR(50) NOT NULL DEFAULT \'Все зоны\'', 'zone TEXT DEFAULT \'Все зоны\'', 'zone VARCHAR(50) NOT NULL DEFAULT \'Все зоны\''],
            ['warehouse_id', 'warehouse_id BIGINT UNSIGNED NULL', 'warehouse_id INTEGER NULL', 'warehouse_id BIGINT NULL'],
            ['tsd_access', 'tsd_access TINYINT(1) NOT NULL DEFAULT 0', 'tsd_access INTEGER DEFAULT 0', 'tsd_access SMALLINT NOT NULL DEFAULT 0'],
            ['is_blocked', 'is_blocked TINYINT(1) NOT NULL DEFAULT 0', 'is_blocked INTEGER DEFAULT 0', 'is_blocked SMALLINT NOT NULL DEFAULT 0'],
            ['allow_unplanned_exit', 'allow_unplanned_exit TINYINT(1) NOT NULL DEFAULT 0', 'allow_unplanned_exit INTEGER DEFAULT 0', 'allow_unplanned_exit SMALLINT NOT NULL DEFAULT 0'],
            ['updated_at', 'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'updated_at TEXT DEFAULT (datetime(\'now\'))', 'updated_at TIMESTAMP NOT NULL DEFAULT NOW()'],
        ];
        foreach ($cols as [$c, $ddlMysql, $ddlSqlite, $ddlPg]) {
            wms_ensure_column($pdo, 'employees', $c, $ddlMysql, $ddlSqlite, $ddlPg);
        }
        return;
    }
    // SQLite (демо)
    $ddl = [
        "CREATE TABLE IF NOT EXISTS employees (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          fio TEXT NOT NULL,
          last_name TEXT, first_name TEXT, middle_name TEXT,
          position TEXT, code TEXT, department TEXT, personnel_no TEXT, pin TEXT,
          photo_path TEXT, phone_internal TEXT, phone_mobile TEXT, email TEXT,
          birth_date TEXT, hired_at TEXT,
          status TEXT DEFAULT 'active', role TEXT DEFAULT 'storekeeper', zone TEXT DEFAULT 'Все зоны',
          warehouse_id INTEGER, tsd_access INTEGER DEFAULT 0,
          is_active INTEGER DEFAULT 1, is_blocked INTEGER DEFAULT 0,
          allow_unplanned_exit INTEGER DEFAULT 0,
          created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))",
        "CREATE TABLE IF NOT EXISTS employee_schedules (
          id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER NOT NULL,
          date_from TEXT NOT NULL, date_to TEXT NOT NULL,
          pattern TEXT NOT NULL, shift TEXT NOT NULL,
          work_from TEXT, work_to TEXT,
          created_at TEXT DEFAULT (datetime('now')))",
        "CREATE TABLE IF NOT EXISTS employee_access (
          id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER NOT NULL,
          scope TEXT NOT NULL DEFAULT 'wms', module_key TEXT NOT NULL,
          level TEXT NOT NULL DEFAULT 'none',
          created_at TEXT DEFAULT (datetime('now')))",
        "CREATE TABLE IF NOT EXISTS role_access (
          id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT NOT NULL,
          scope TEXT NOT NULL DEFAULT 'wms', module_key TEXT NOT NULL,
          level TEXT NOT NULL DEFAULT 'none',
          created_at TEXT DEFAULT (datetime('now')), UNIQUE(role, scope, module_key))",
        "CREATE TABLE IF NOT EXISTS employee_exits (
          id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER NOT NULL,
          event_at TEXT NOT NULL, checkpoint TEXT,
          direction TEXT DEFAULT 'in',
          created_at TEXT DEFAULT (datetime('now')))",
        "CREATE TABLE IF NOT EXISTS employee_attendance (
          id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER NOT NULL,
          work_date TEXT NOT NULL, start_at TEXT, end_at TEXT,
          worked_minutes INTEGER DEFAULT 0, on_shift INTEGER DEFAULT 0,
          created_at TEXT DEFAULT (datetime('now')))",
        "CREATE TABLE IF NOT EXISTS employee_history (
          id INTEGER PRIMARY KEY AUTOINCREMENT, employee_id INTEGER NOT NULL,
          user_id INTEGER, user_fio TEXT, field TEXT NOT NULL, field_label TEXT,
          old_value TEXT, new_value TEXT,
          created_at TEXT DEFAULT (datetime('now')))",
        "CREATE INDEX IF NOT EXISTS idx_emp_fio ON employees(fio)",
        "CREATE INDEX IF NOT EXISTS idx_esch_emp ON employee_schedules(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_eacc_emp ON employee_access(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_eex_emp ON employee_exits(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_eatt_emp ON employee_attendance(employee_id)",
        "CREATE INDEX IF NOT EXISTS idx_ehist_emp ON employee_history(employee_id)",
    ];
    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
        }
    }
}

/** БД справочника сотрудников (модуль 10): отдельный файл в демо, общая БД в PostgreSQL. */
function wms_employees_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_users_attach($pdo);
        wms_ensure_employees_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_employees_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_users_attach($pdo);
    wms_ensure_employees_schema($pdo);
    return $pdo;
}

/** Дотягивает схему отгрузок: флаг кроссдока, реестр марок ЧЗ, лог сортировки.
 *  Безопасно вызывать на каждый запрос: только CREATE IF NOT EXISTS / ALTER при отсутствии колонки. */
function wms_ensure_shipments_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        wms_ensure_column($pdo, 'shipments', 'is_crossdock', 'TINYINT(1) NOT NULL DEFAULT 0', 'is_crossdock INTEGER DEFAULT 0', 'is_crossdock SMALLINT NOT NULL DEFAULT 0');
        wms_ensure_table($pdo, 'shipment_marks', "CREATE TABLE IF NOT EXISTS shipment_marks (
              id BIGSERIAL PRIMARY KEY,
              shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
              sku VARCHAR(50) NOT NULL,
              marking_code TEXT NOT NULL,
              prod_date DATE NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'expected',
              accepted_at TIMESTAMP NULL,
              created_at TIMESTAMP NOT NULL DEFAULT NOW(),
              UNIQUE (shipment_id, marking_code))");
        wms_ensure_table($pdo, 'shipment_sort_log', "CREATE TABLE IF NOT EXISTS shipment_sort_log (
              id BIGSERIAL PRIMARY KEY,
              shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
              pallet_id VARCHAR(50) NULL,
              pallet_created_at TIMESTAMP NULL,
              user_id BIGINT NULL,
              item_id BIGINT NULL,
              serial_no VARCHAR(100) NULL,
              marking_code TEXT NULL,
              qty NUMERIC(12,3) NOT NULL DEFAULT 1,
              operation VARCHAR(30) NOT NULL DEFAULT 'sort',
              reason VARCHAR(100) NULL,
              created_at TIMESTAMP NOT NULL DEFAULT NOW())");
        wms_ensure_column($pdo, 'shipment_marks', 'prod_date', 'DATE NULL', 'prod_date TEXT', 'prod_date DATE NULL');
        return;
    }
    wms_ensure_column($pdo, 'shipments', 'is_crossdock', 'TINYINT(1) NOT NULL DEFAULT 0', 'is_crossdock INTEGER DEFAULT 0', 'is_crossdock SMALLINT NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_marks (
      id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, sku TEXT NOT NULL,
      marking_code TEXT NOT NULL, prod_date TEXT, status TEXT NOT NULL DEFAULT 'expected', accepted_at TEXT,
      created_at TEXT DEFAULT (datetime('now')))");
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_shipment_mark ON shipment_marks(shipment_id, marking_code)");
    } catch (Throwable) {
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_sort_log (
      id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, pallet_id TEXT,
      pallet_created_at TEXT, user_id INTEGER, item_id INTEGER, serial_no TEXT, marking_code TEXT,
      qty REAL NOT NULL DEFAULT 1, operation TEXT NOT NULL DEFAULT 'sort', reason TEXT,
      created_at TEXT DEFAULT (datetime('now')))");
    try {
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ssl_shipment ON shipment_sort_log(shipment_id)");
    } catch (Throwable) {
    }
}

function wms_receipts_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_users_attach($pdo);
        wms_ensure_receipt_schema($pdo);
        wms_ensure_ext_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_receipts_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_users_attach($pdo);
    wms_ensure_receipt_schema($pdo);
    wms_ensure_ext_schema($pdo);
    return $pdo;
}

function wms_nomenclature_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_users_attach($pdo);
        wms_ensure_ext_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_nomenclature_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_users_attach($pdo);
    wms_ensure_ext_schema($pdo);
    return $pdo;
}

/**
 * Штрихкоды товаров из номенклатуры (поле «Штрихкоды (через запятую)» -> product_barcodes)
 * по списку SKU. Ключ — SKU, значение — массив штрихкодов.
 * Сверка выполняется без учета пробелов: «4 673192631679» = «4673192631679».
 * @param array<int,string> $skus
 * @return array<string,string[]>
 */
function wms_nomenclature_barcodes(array $skus): array
{
    $skus = array_values(array_unique(array_filter(
        array_map(fn($s) => trim((string)$s), $skus),
        fn($s) => $s !== ''
    )));
    if (!$skus) {
        return [];
    }
    $out = [];
    try {
        $pdo = wms_nomenclature_pdo();
        $in = implode(',', array_fill(0, count($skus), '?'));
        $st = $pdo->prepare(
            "SELECT p.sku AS sku, b.barcode AS barcode
             FROM products p JOIN product_barcodes b ON b.product_id = p.id
             WHERE p.sku IN ($in) ORDER BY b.id"
        );
        $st->execute($skus);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sku = trim((string)($r['sku'] ?? ''));
            $bc = trim((string)($r['barcode'] ?? ''));
            if ($sku === '' || $bc === '') {
                continue;
            }
            $out[$sku] ??= [];
            if (!in_array($bc, $out[$sku], true)) {
                $out[$sku][] = $bc;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

function wms_storage_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_users_attach($pdo);
        wms_ensure_storage_schema($pdo);
        wms_ensure_ext_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_storage_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_users_attach($pdo);
    wms_ensure_storage_schema($pdo);
    wms_ensure_ext_schema($pdo);
    return $pdo;
}

function wms_tasks_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_users_attach($pdo);
        wms_ensure_tasks_schema($pdo);
        wms_ensure_ext_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_tasks_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_users_attach($pdo);
    wms_ensure_tasks_schema($pdo);
    wms_ensure_ext_schema($pdo);
    return $pdo;
}

function wms_shipments_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        wms_users_attach($pdo);
        wms_ensure_shipments_schema($pdo);
        wms_ensure_ext_schema($pdo);
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . wms_shipments_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_users_attach($pdo);
    wms_ensure_shipments_schema($pdo);
    wms_ensure_ext_schema($pdo);
    return $pdo;
}

/**
 * Ячейки приёмки (ворота) DOK-01…DOK-10 — зона receiving в таблице ячеек хранения.
 * ТСД «Приход» принимает только эти ячейки: ворота = ячейка хранения.
 */
function wms_receiving_cells(PDO $pdo): array
{
    try {
        $st = $pdo->prepare("SELECT code FROM storage_cells WHERE zone='receiving' AND COALESCE(is_archived,0)=0 ORDER BY code");
        $st->execute();
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return [];
    }
}

/**
 * Коды реальных ячеек зоны приёмки (DOK-01…DOK-10).
 * Результат кэшируется на время запроса: функция зовётся на каждый паллет
 * при пробросе прихода, а получение БД ячеек тянет DDL-дотяжку схемы.
 * @return string[]
 */
function wms_receiving_cell_codes(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $pdo = wms_storage_pdo();
        $st = $pdo->prepare(
            "SELECT code FROM storage_cells WHERE zone='receiving' AND COALESCE(is_archived,0)=0 ORDER BY code"
        );
        $st->execute();
        $cache = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        $cache = [];
    }
    return $cache;
}

/**
 * Первая реальная ячейка зоны приёмки (DOK-01…DOK-10).
 * Нужна как запасной вариант, когда у поступления не указаны ворота:
 * строка 'ЗОНА-ПРИЕМКИ' не существует в storage_cells, и товар «проваливался»
 * в несуществующее место.
 */
function wms_first_receiving_cell(): string
{
    $cells = wms_receiving_cell_codes();
    return $cells[0] ?? '';
}

/**
 * Проверить, что код — реальная ячейка зоны приёмки.
 */
function wms_is_receiving_cell(string $code): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    return in_array($code, wms_receiving_cell_codes(), true);
}

/** Создаёт 10 ячеек приёмки DOK-01…DOK-10, если их ещё нет. Идемпотентно. */
function wms_ensure_receiving_cells(PDO $pdo): void
{
    try {
        $drv = wms_driver($pdo);
        $wh = 1;
        try {
            $w = $pdo->query("SELECT id FROM warehouses ORDER BY id LIMIT 1")->fetchColumn();
            if ($w !== false && $w !== null) {
                $wh = (int)$w;
            }
        } catch (Throwable) {
        }
        for ($i = 1; $i <= 10; $i++) {
            $code = 'DOK-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $st = $pdo->prepare("SELECT id FROM storage_cells WHERE code=:c AND warehouse_id=:w LIMIT 1");
            $st->execute([':c' => $code, ':w' => $wh]);
            if ($st->fetchColumn() !== false) {
                continue;
            }
            try {
                if ($drv === 'pgsql') {
                    $pdo->prepare("INSERT INTO storage_cells (code,warehouse_id,zone,cell_type,status,capacity,comment) VALUES (:c,:w,'receiving','pallet','free',0,'Ячейка приёмки (ворота)') ON CONFLICT DO NOTHING")
                        ->execute([':c' => $code, ':w' => $wh]);
                } elseif ($drv === 'mysql') {
                    $pdo->prepare("INSERT IGNORE INTO storage_cells (code,warehouse_id,zone,cell_type,status,capacity,comment) VALUES (:c,:w,'receiving','pallet','free',0,'Ячейка приёмки (ворота)')")
                        ->execute([':c' => $code, ':w' => $wh]);
                } else {
                    $pdo->prepare("INSERT OR IGNORE INTO storage_cells (code,warehouse_id,zone,cell_type,status,capacity,comment) VALUES (:c,:w,'receiving','pallet','free',0,'Ячейка приёмки (ворота)')")
                        ->execute([':c' => $code, ':w' => $wh]);
                }
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

// Дотягивает схему поступлений до полной (база + tsd_* + guard sync).
// Безопасно вызывать на каждый запрос: только CREATE TABLE IF NOT EXISTS / ALTER при отсутствии колонки.
// Для PostgreSQL схема ведётся через db/schema-pg.sql — здесь только дотяжка колонок.
function wms_ensure_receipt_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        wms_ensure_column($pdo, 'receipt_items', 'qty_fact', 'DOUBLE', 'qty_fact REAL DEFAULT 0', 'NUMERIC(12,3) NOT NULL DEFAULT 0');
        wms_ensure_column($pdo, 'receipt_items', 'line_status', 'VARCHAR(20)', "line_status TEXT DEFAULT 'ok'", "VARCHAR(20) NOT NULL DEFAULT 'ok'");
        wms_ensure_column($pdo, 'receipt_items', 'quality', 'VARCHAR(20)', 'quality TEXT', 'VARCHAR(20)');
        wms_ensure_column($pdo, 'receipt_items', 'prod_group', 'VARCHAR(100)', 'prod_group TEXT', 'VARCHAR(100)');
        wms_ensure_column($pdo, 'receipt_items', 'prod_date', 'DATE', 'prod_date TEXT', 'DATE');
        wms_ensure_column($pdo, 'receipt_items', 'supplier_batch', 'VARCHAR(100)', 'supplier_batch TEXT', 'VARCHAR(100)');
        wms_ensure_column($pdo, 'receipt_items', 'deviation', 'VARCHAR(20)', "deviation TEXT", "deviation VARCHAR(20) NULL");
        wms_ensure_column($pdo, 'receipts', 'excess_qty', 'DOUBLE DEFAULT 0', 'excess_qty REAL DEFAULT 0', 'excess_qty NUMERIC(14,3) NOT NULL DEFAULT 0');
        wms_ensure_column($pdo, 'receipts', 'shortage_qty', 'DOUBLE DEFAULT 0', 'shortage_qty REAL DEFAULT 0', 'shortage_qty NUMERIC(14,3) NOT NULL DEFAULT 0');
        wms_ensure_column($pdo, 'receipt_operations', 'supplier_batch', 'VARCHAR(100)', 'supplier_batch TEXT', 'VARCHAR(100)');
        wms_ensure_column($pdo, 'receipt_operations', 'reason', 'TEXT', 'reason TEXT', 'TEXT');
        wms_ensure_column($pdo, 'receipt_flags', 'status', 'VARCHAR(20)', "status TEXT DEFAULT 'годный'", "VARCHAR(20) NOT NULL DEFAULT 'годный'");
        wms_ensure_column($pdo, 'receipt_flags', 'reason', 'TEXT', 'reason TEXT', 'TEXT');
        wms_ensure_column($pdo, 'tsd_act_rasx', 'supplier_batch', 'VARCHAR(100)', "supplier_batch TEXT DEFAULT ''", "supplier_batch VARCHAR(100) NOT NULL DEFAULT ''");
        // Гард идемпотентного проброса прихода в Номенклатуру (shared/StockSync.php).
        // Без него повторное закрытие документа прибавляло бы остаток дважды.
        wms_ensure_table($pdo, 'receipt_stock_sync', "CREATE TABLE IF NOT EXISTS receipt_stock_sync (
          receipt_id BIGINT PRIMARY KEY,
          synced_at TIMESTAMP NOT NULL DEFAULT NOW(),
          payload TEXT NOT NULL
        )");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contractors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    // users — единый справочник сотрудников (shared/wms_users.sqlite), подключается выше.
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipts (
      id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, external_no TEXT,
      doc_date TEXT NOT NULL, plan_delivery_date TEXT, fact_delivery_date TEXT,
      arrival_date TEXT, accept_date TEXT, place_date TEXT,
      vehicle_arrival_plan TEXT, vehicle_arrival_fact TEXT, unload_date TEXT,
      supplier_id INTEGER, contractor_id INTEGER, contract_no TEXT, warehouse_id INTEGER NOT NULL,
      status TEXT NOT NULL DEFAULT 'draft', receipt_type TEXT DEFAULT 'supply', accept_type TEXT,
      unload_type TEXT, vehicle_plan TEXT, vehicle_fact TEXT, vehicle TEXT, gate TEXT,
      cargo_places_plan INTEGER DEFAULT 0, cargo_places_fact INTEGER DEFAULT 0,
      total REAL DEFAULT 0, vat_rate INTEGER DEFAULT 20, vat_sum REAL DEFAULT 0,
      total_with_vat REAL DEFAULT 0, paid REAL DEFAULT 0, debt REAL DEFAULT 0,
      items_count INTEGER DEFAULT 0, sku_count INTEGER DEFAULT 0, qty_total REAL DEFAULT 0, boxes INTEGER DEFAULT 0,
      accepted_pct INTEGER DEFAULT 0, placed_pct INTEGER DEFAULT 0,
      accepted_qty REAL DEFAULT 0, placed_qty REAL DEFAULT 0, deviation_qty REAL DEFAULT 0,
      excess_qty REAL DEFAULT 0, shortage_qty REAL DEFAULT 0,
      priority TEXT DEFAULT 'normal', volume REAL DEFAULT 0, quarantine INTEGER DEFAULT 0,
      has_diff INTEGER DEFAULT 0, serial_scan INTEGER DEFAULT 0, marking_scan INTEGER DEFAULT 0,
      creator_id INTEGER, unloader_id INTEGER, responsible_id INTEGER,
      is_posted INTEGER DEFAULT 0, comment TEXT, created_by INTEGER,
      created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, line_no INTEGER DEFAULT 0,
      code TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
      qty REAL NOT NULL, boxes INTEGER DEFAULT 0, price REAL DEFAULT 0, vat_rate INTEGER DEFAULT 20,
      vat_sum_unit REAL DEFAULT 0, price_wvat REAL DEFAULT 0, sum_novat REAL DEFAULT 0,
      vat_sum REAL DEFAULT 0, sum_vat REAL DEFAULT 0, deviation TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_operations (
      id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, line_no INTEGER DEFAULT 0,
      sku TEXT, accept_status TEXT, accept_date TEXT, receiver_id INTEGER, pallet TEXT,
      sku_qty INTEGER DEFAULT 0, qty REAL DEFAULT 0, place_status TEXT, place_date TEXT,
      storekeeper_id INTEGER, cell TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_documents (
      id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, name TEXT NOT NULL,
      format TEXT DEFAULT 'pdf', pages INTEGER DEFAULT 1, file_path TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_flags (
      id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, item_id INTEGER,
      flag_type TEXT NOT NULL, serial_no TEXT, marking_code TEXT, sku TEXT, receiver TEXT, flag_date TEXT,
      created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_history (
      id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, user_id INTEGER,
      user_fio TEXT, position TEXT, action TEXT NOT NULL, field TEXT,
      old_value TEXT, new_value TEXT, old_status TEXT, new_status TEXT, created_at TEXT DEFAULT (datetime('now')))");
    // Реестр ожидаемых марок ЧЗ (загрузка файлом на документ; ТСД сверяет сканы с ним).
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_marks (
      id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sku TEXT NOT NULL,
      marking_code TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'expected',
      accepted_at TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_receipt_mark ON receipt_marks(receipt_id, marking_code)");

    // ТСД-расширения (раньше создавались только внутри tsd/prihod.php).
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_pallets (id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sscc TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'Заполняется', qty_total REAL DEFAULT 0, weight REAL DEFAULT 0, created_at TEXT DEFAULT (datetime('now')), closed_at TEXT)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_pallet_rec_sscc ON tsd_pallets(receipt_id, sscc)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_crossdock (id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, gate_id TEXT, sscc TEXT, status TEXT NOT NULL DEFAULT 'создана', created_at TEXT DEFAULT (datetime('now')))");
    // Акт расхождений. supplier_batch — партия поставщика: акт о недостаче/излишке
    // относится к конкретной партии позиции, иначе 2 партии одного SKU не развести.
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_act_rasx (id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sku TEXT NOT NULL, supplier_batch TEXT DEFAULT '', type TEXT NOT NULL, qty_diff REAL NOT NULL, created_at TEXT DEFAULT (datetime('now')))");
    // tsd_stock УДАЛЕНА (дубль receipt_operations): живой факт приёмки считается
    // запросом SUM(receipt_operations WHERE pallet=:s AND accept_status='Принято'),
    // оприходованный остаток — product_stock в БД номенклатуры (модуль 8.1).
    // DROP — чтобы подчистить старые файлы.
    try {
        $pdo->exec("DROP TABLE IF EXISTS tsd_stock");
    } catch (Throwable) {
    }
    // Guard идемпотентного проброса в номенклатуру (защита от двойного прибавления при повторном close).
    $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_stock_sync (receipt_id INTEGER PRIMARY KEY, synced_at TEXT DEFAULT (datetime('now')), payload TEXT NOT NULL)");

    $colsOf = function (string $table) use ($pdo): array {
        $out = [];
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = $r['name'];
        }
        return $out;
    };
    $ensureCol = function (string $table, string $col, string $ddl) use ($pdo, $colsOf): void {
        if (!in_array($col, $colsOf($table), true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $ddl");
        }
    };
    $ensureCol('receipt_items', 'qty_fact', "qty_fact REAL DEFAULT 0");
    $ensureCol('receipt_items', 'line_status', "line_status TEXT DEFAULT 'ok'");
    $ensureCol('receipt_items', 'quality', "quality TEXT");
    $ensureCol('receipt_items', 'prod_group', "prod_group TEXT");
    $ensureCol('receipt_items', 'prod_date', "prod_date TEXT");
    $ensureCol('receipt_items', 'supplier_batch', "supplier_batch TEXT");
    $ensureCol('receipt_items', 'deviation', "deviation TEXT");
    $ensureCol('receipts', 'excess_qty', "excess_qty REAL DEFAULT 0");
    $ensureCol('receipts', 'shortage_qty', "shortage_qty REAL DEFAULT 0");
    $ensureCol('receipt_operations', 'supplier_batch', "supplier_batch TEXT");
    $ensureCol('receipt_operations', 'reason', "reason TEXT");
    $ensureCol('receipt_flags', 'status', "status TEXT DEFAULT 'годный'");
    $ensureCol('receipt_flags', 'reason', "reason TEXT");
    $ensureCol('tsd_act_rasx', 'supplier_batch', "supplier_batch TEXT DEFAULT ''");
    try {
        // users — единый справочник: pin проставляем в общей базе сотрудников.
        // База PIN берётся из конфигурации (WMS_PIN_BASE), см. wms_pin_base().
        $pinBase = wms_pin_base();
        if ($pinBase > 0) {
            $u = wms_users_pdo();
            $u->exec("UPDATE users SET pin=printf('%04d'," . $pinBase . "+id) WHERE pin IS NULL OR pin=''");
        }
    } catch (Throwable $e) {
    }
}

// Дотягивает схему заданий (модуль 07 / ТСД / tasks). Безопасно на каждый запрос.
// Для PostgreSQL схема ведётся через db/schema-pg.sql.
function wms_ensure_tasks_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    wms_ensure_task_lists_schema($pdo);
    if ($drv === 'pgsql') return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
      id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, type TEXT NOT NULL DEFAULT 'сбор',
      status TEXT NOT NULL DEFAULT 'new', priority TEXT NOT NULL DEFAULT 'normal',
      warehouse_id INTEGER NOT NULL, doc_ref TEXT, order_ref TEXT,
      executor_id INTEGER, executor_fio TEXT,
      plan_qty REAL DEFAULT 0, fact_qty REAL DEFAULT 0, progress INTEGER DEFAULT 0,
      cells_count INTEGER DEFAULT 0, volume REAL DEFAULT 0, weight REAL DEFAULT 0,
      equipment TEXT, flags TEXT, need_check INTEGER DEFAULT 0, comment TEXT,
      due_date TEXT, done_at TEXT, paused_at TEXT,
      created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
      code TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
      cell_from TEXT, cell_to TEXT, pallet TEXT,
      plan_qty REAL DEFAULT 0, fact_qty REAL DEFAULT 0,
      state TEXT, executor_fio TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_history (
      id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, user_id INTEGER,
      action TEXT NOT NULL, field TEXT, field_label TEXT, old_value TEXT, new_value TEXT,
      created_at TEXT DEFAULT (datetime('now')))");
    // Закрепление заданий за сотрудниками ТСД + журнал проблем/сортировки.
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_task_locks (task_id INTEGER PRIMARY KEY, user_id INTEGER, user_fio TEXT, status TEXT NOT NULL DEFAULT 'work', updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_task_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, cell TEXT, sku TEXT, user_id INTEGER, reason TEXT, created_at TEXT DEFAULT (datetime('now')))");
    // Списки заданий (ТСД «Отбор по зоне»): PK-000001, PK-000002 ...
    // В списке — одна отгрузка (нарядов может быть несколько), тип списка = тип отбора ячеек.
    wms_ensure_task_lists_schema($pdo);
}

/**
 * Таблицы списков заданий (ТСД «Отбор по зоне»).
 * task_lists: код списка PK-000001 (буквы не меняются), тип = manual|driver,
 *   в списке ровно одна отгрузка (нарядов может быть несколько).
 * task_list_items: задания списка.
 */
function wms_ensure_task_lists_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_lists (
          id BIGSERIAL PRIMARY KEY,
          code VARCHAR(20) NOT NULL UNIQUE,
          name VARCHAR(255) NULL,
          type VARCHAR(20) NOT NULL DEFAULT 'manual',
          shipment_id BIGINT NULL,
          shipment_doc_no VARCHAR(50) NULL,
          zone VARCHAR(30) NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'new',
          created_by BIGINT NULL,
          created_by_fio VARCHAR(150) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          updated_at TIMESTAMP NOT NULL DEFAULT NOW())");
        $pdo->exec("CREATE TABLE IF NOT EXISTS task_list_items (
          id BIGSERIAL PRIMARY KEY,
          list_id BIGINT NOT NULL REFERENCES task_lists (id) ON DELETE CASCADE,
          task_id BIGINT NOT NULL,
          seq INTEGER NOT NULL DEFAULT 0,
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          UNIQUE (list_id, task_id))");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_lists (
      id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT,
      type TEXT NOT NULL DEFAULT 'manual', shipment_id INTEGER, shipment_doc_no TEXT,
      zone TEXT, status TEXT NOT NULL DEFAULT 'new',
      created_by INTEGER, created_by_fio TEXT,
      created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS task_list_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT, list_id INTEGER NOT NULL, task_id INTEGER NOT NULL,
      seq INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')))");
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_task_list_items ON task_list_items(list_id, task_id)");
    } catch (Throwable) {
    }
}

// Дотягивает схему ячеек хранения (модуль 05). Безопасно на каждый запрос.
// Для PostgreSQL схема ведётся через db/schema-pg.sql — здесь только дотяжка колонок.
function wms_ensure_storage_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        wms_ensure_column($pdo, 'storage_cells', 'beam_width', 'DOUBLE', 'beam_width REAL DEFAULT 3.2', 'NUMERIC(3,1) NOT NULL DEFAULT 3.2');
        wms_ensure_column($pdo, 'storage_cells', 'cell_height_mm', 'INT', 'cell_height_mm INTEGER', 'INTEGER');
        wms_ensure_column($pdo, 'storage_cells', 'allowed_pallet_types', 'VARCHAR(30)', "allowed_pallet_types TEXT DEFAULT 'euro,fin'", "allowed_pallet_types VARCHAR(30) NOT NULL DEFAULT 'euro,fin'");
        wms_ensure_column($pdo, 'storage_cells', 'pallet_type', 'VARCHAR(10)', 'pallet_type TEXT', 'pallet_type VARCHAR(10)');
        // Настройки ячейки: несколько типов паллет / разные товары в одной ячейке.
        wms_ensure_column($pdo, 'storage_cells', 'allow_multi_pallet_types', 'TINYINT(1) NOT NULL DEFAULT 1', 'allow_multi_pallet_types INTEGER DEFAULT 1', 'allow_multi_pallet_types SMALLINT NOT NULL DEFAULT 1');
        wms_ensure_column($pdo, 'storage_cells', 'allow_mixed_goods', 'TINYINT(1) NOT NULL DEFAULT 1', 'allow_mixed_goods INTEGER DEFAULT 1', 'allow_mixed_goods SMALLINT NOT NULL DEFAULT 1');
        // Ячейки приёмки (ворота) DOK-01…DOK-10 — та же таблица ячеек хранения.
        wms_ensure_cell_zones_schema($pdo);
        wms_ensure_receiving_cells($pdo);
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cells (
      id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, warehouse_id INTEGER NOT NULL,
      zone TEXT DEFAULT 'storage', cell_type TEXT DEFAULT 'pallet', category TEXT,
      status TEXT DEFAULT 'free', capacity REAL DEFAULT 0, qty REAL DEFAULT 0,
      reserved_qty REAL DEFAULT 0, pallet_count INTEGER DEFAULT 0,
      building TEXT, row_no TEXT, level_no TEXT, place_no TEXT, coord_x INTEGER, coord_y INTEGER,
      temp_from REAL, temp_to REAL, is_locked INTEGER DEFAULT 0, lock_reason TEXT,
      allowed_goods TEXT DEFAULT 'Все', max_weight_kg REAL, max_pallets INTEGER,
      hazmat_allowed INTEGER DEFAULT 0, comment TEXT, is_archived INTEGER DEFAULT 0,
      beam_width REAL DEFAULT 3.2, cell_height_mm INTEGER DEFAULT NULL,
      allowed_pallet_types TEXT DEFAULT 'euro,fin', pallet_type TEXT DEFAULT NULL,
      allow_multi_pallet_types INTEGER DEFAULT 1, allow_mixed_goods INTEGER DEFAULT 1,
      created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_cell_code_wh ON storage_cells(code, warehouse_id)");
    // Миграция старых файлов: ширина балки / высота ячейки / типы тары / факт. тип паллеты.
    foreach ([
        'beam_width' => 'beam_width REAL DEFAULT 3.2',
        'cell_height_mm' => 'cell_height_mm INTEGER',
        'allowed_pallet_types' => "allowed_pallet_types TEXT DEFAULT 'euro,fin'",
        'pallet_type' => 'pallet_type TEXT',
        'allow_multi_pallet_types' => 'allow_multi_pallet_types INTEGER DEFAULT 1',
        'allow_mixed_goods' => 'allow_mixed_goods INTEGER DEFAULT 1',
    ] as $col => $ddl) {
        try {
            $has = false;
            foreach ($pdo->query("PRAGMA table_info(storage_cells)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (($c['name'] ?? '') === $col) { $has = true; break; }
            }
            if (!$has) $pdo->exec("ALTER TABLE storage_cells ADD COLUMN $ddl");
        } catch (Throwable) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS cell_content (
      id INTEGER PRIMARY KEY AUTOINCREMENT, cell_id INTEGER NOT NULL,
      product_code TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
      qty REAL DEFAULT 0, reserved_qty REAL DEFAULT 0, barcode TEXT, pallet TEXT,
      tasks_count INTEGER DEFAULT 0, updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cell_history (
      id INTEGER PRIMARY KEY AUTOINCREMENT, cell_id INTEGER NOT NULL, user_id INTEGER,
      user_fio TEXT, action TEXT NOT NULL, field TEXT, field_label TEXT,
      old_value TEXT, new_value TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cell_movements (
      id INTEGER PRIMARY KEY AUTOINCREMENT, cell_id INTEGER NOT NULL, doc_date TEXT NOT NULL,
      doc_type TEXT NOT NULL, doc_no TEXT NOT NULL, sku TEXT, qty_change REAL DEFAULT 0,
      balance REAL DEFAULT 0, cell_from TEXT, cell_to TEXT)");
    // Дополнительные зоны ячейки (несколько зон на ячейку):
    // manual = Ручной отбор, driver = Отбор водителем.
    wms_ensure_cell_zones_schema($pdo);
    // Ячейки приёмки (ворота) DOK-01…DOK-10 — та же таблица ячеек хранения.
    wms_ensure_receiving_cells($pdo);
}

/**
 * Таблица дополнительных зон ячейки (ТСД «Отбор по зоне»).
 * У ячейки может быть несколько зон: manual (ручной отбор) и driver (отбор водителем).
 */
function wms_ensure_cell_zones_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cell_zones (
          id BIGSERIAL PRIMARY KEY,
          cell_id BIGINT NOT NULL REFERENCES storage_cells (id) ON DELETE CASCADE,
          zone VARCHAR(30) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          UNIQUE (cell_id, zone))");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cell_zones (
      id INTEGER PRIMARY KEY AUTOINCREMENT, cell_id INTEGER NOT NULL,
      zone TEXT NOT NULL, created_at TEXT DEFAULT (datetime('now')))");
    try {
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_storage_cell_zone ON storage_cell_zones(cell_id, zone)");
    } catch (Throwable) {
    }
}

/**
 * Общая БД сквозных справочников и журналов (SQLite-демо).
 * В PostgreSQL все таблицы живут в общей схеме public, отдельного файла нет.
 */
function wms_common_db_file(): string
{
    return __DIR__ . '/wms_common.sqlite';
}

/**
 * Подключение общей БД (контрагенты, журналы, буфер отбора) к модульной БД.
 * Аналог wms_users_attach: в SQLite каждая модульная БД отдельный файл,
 * поэтому сквозные таблицы живут в shared/wms_common.sqlite.
 */
function wms_common_attach(PDO $pdo): void
{
    if (wms_use_pgsql()) {
        return;
    }
    if ((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        return;
    }
    try {
        foreach ($pdo->query("PRAGMA database_list")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            if ((string)($d['name'] ?? '') === 'wms_common') {
                return;
            }
        }
    } catch (Throwable) {
    }
    try {
        $pdo->exec("ATTACH DATABASE " . $pdo->quote(wms_common_db_file()) . " AS wms_common");
    } catch (Throwable) {
    }
}

/**
 * Имя сквозной таблицы с учётом драйвера:
 * PostgreSQL — обычное имя в public, SQLite — префикс подключённой базы wms_common.
 */
function wms_ext_table(PDO $pdo, string $name): string
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    return $drv === 'sqlite' ? 'wms_common.' . $name : $name;
}

/**
 * Расширенная схема WMS (дотягивается на каждый запрос, идемпотентна).
 *
 * Добавлено под новые требования:
 *   - products: флаги сканирования серийных номеров на входе/выходе
 *     (+ «точечные» override, чтобы снять глобальный флаг клиента на артикуле),
 *     контроль даты производства, привязка артикула к контрагенту;
 *   - counterparties: принцип резервирования (FIFO/FEFO/LIFO), глобальные флаги
 *     сканирования с/н, зоны размещения;
 *   - storage_cells: «разные товары в одной ячейке», «несколько типов паллет»;
 *   - product_stock: дата производства партии;
 *   - receipt_operations: приёмка без с/н + причина отбраковки;
 *   - wms_journal / wms_serial_journal: журнал склада и журнал серийных номеров;
 *   - tsd_pick_buffers: виртуальная промежуточная ячейка и сборочная паллета.
 *
 * В PostgreSQL всё живёт в общей схеме public. В SQLite-демо новые «сквозные»
 * таблицы вынесены в общий файл shared/wms_common.sqlite и подключаются
 * как база wms_common — иначе контрагенты/журналы разъезжались бы по модулям.
 */
function wms_ensure_ext_schema(PDO $pdo): void
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'sqlite') {
        wms_common_attach($pdo);
    }
    $cp = wms_ext_table($pdo, 'counterparties');

    // --- products: сканирование с/н, дата производства, клиент ---
    wms_ensure_column($pdo, 'products', 'scan_serial_in', 'TINYINT(1) NOT NULL DEFAULT 0', 'scan_serial_in INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
    wms_ensure_column($pdo, 'products', 'scan_serial_out', 'TINYINT(1) NOT NULL DEFAULT 0', 'scan_serial_out INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
    wms_ensure_column($pdo, 'products', 'scan_serial_in_override', 'TINYINT(1) NULL', 'scan_serial_in_override INTEGER', 'SMALLINT NULL');
    wms_ensure_column($pdo, 'products', 'scan_serial_out_override', 'TINYINT(1) NULL', 'scan_serial_out_override INTEGER', 'SMALLINT NULL');
    wms_ensure_column($pdo, 'products', 'control_prod_date', 'TINYINT(1) NOT NULL DEFAULT 0', 'control_prod_date INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
    wms_ensure_column($pdo, 'products', 'contractor_id', 'BIGINT NULL', 'contractor_id INTEGER', 'BIGINT NULL');

    // --- counterparties: резервирование, глобальные флаги с/н, зоны размещения ---
    wms_ensure_column($pdo, $cp, 'reserve_strategy', "VARCHAR(10) NOT NULL DEFAULT 'FIFO'", "reserve_strategy TEXT DEFAULT 'FIFO'", "reserve_strategy VARCHAR(10) NOT NULL DEFAULT 'FIFO'");
    wms_ensure_column($pdo, $cp, 'scan_serial_in', 'TINYINT(1) NOT NULL DEFAULT 0', 'scan_serial_in INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
    wms_ensure_column($pdo, $cp, 'scan_serial_out', 'TINYINT(1) NOT NULL DEFAULT 0', 'scan_serial_out INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
    wms_ensure_column($pdo, $cp, 'placement_zones', 'VARCHAR(255) NULL', 'placement_zones TEXT', 'VARCHAR(255) NULL');
    wms_ensure_column($pdo, $cp, 'placement_cell_codes', 'TEXT NULL', 'placement_cell_codes TEXT', 'TEXT NULL');

    // --- storage_cells: смешанные товары / несколько типов паллет / качество ---
    wms_ensure_column($pdo, 'storage_cells', 'allow_mixed_goods', 'TINYINT(1) NOT NULL DEFAULT 1', 'allow_mixed_goods INTEGER DEFAULT 1', 'SMALLINT NOT NULL DEFAULT 1');
    wms_ensure_column($pdo, 'storage_cells', 'allow_multi_pallet_types', 'TINYINT(1) NOT NULL DEFAULT 1', 'allow_multi_pallet_types INTEGER DEFAULT 1', 'SMALLINT NOT NULL DEFAULT 1');
    wms_ensure_column($pdo, 'storage_cells', 'quality_lock', 'TINYINT(1) NOT NULL DEFAULT 0', 'quality_lock INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');

    // --- product_stock: тара, партия, дата паллеты, качество, дата производства ---
    // Раньше эти колонки дотягивались только через PRAGMA (SQLite), поэтому на
    // PostgreSQL/MySQL «Товарный запас» падал, если база была создана старой схемой.
    wms_ensure_column($pdo, 'product_stock', 'pallet_type', 'VARCHAR(10) NULL', 'pallet_type TEXT', 'VARCHAR(10) NULL');
    wms_ensure_column($pdo, 'product_stock', 'supplier_batch', 'VARCHAR(100) NULL', 'supplier_batch TEXT', 'VARCHAR(100) NULL');
    wms_ensure_column($pdo, 'product_stock', 'pallet_date', 'DATE NULL', 'pallet_date TEXT', 'DATE NULL');
    wms_ensure_column($pdo, 'product_stock', 'quality', "VARCHAR(20) NULL DEFAULT 'новый'", "quality TEXT DEFAULT 'новый'", "VARCHAR(20) NULL DEFAULT 'новый'");
    wms_ensure_column($pdo, 'product_stock', 'prod_date', 'DATE NULL', 'prod_date TEXT', 'DATE NULL');
    wms_ensure_column($pdo, 'products', 'shelf_life_days', 'INTEGER NULL', 'shelf_life_days INTEGER', 'INTEGER NULL');

    // --- приёмка: приёмка без с/н + причина отбраковки по строке ---
    wms_ensure_column($pdo, 'receipt_operations', 'no_serial', 'TINYINT(1) NOT NULL DEFAULT 0', 'no_serial INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
    wms_ensure_column($pdo, 'receipt_operations', 'reject_reason', 'TEXT NULL', 'reject_reason TEXT', 'TEXT NULL');
    wms_ensure_column($pdo, 'receipt_flags', 'pallet', 'VARCHAR(50) NULL', 'pallet TEXT', 'VARCHAR(50) NULL');
    wms_ensure_column($pdo, 'receipt_flags', 'qty', 'DECIMAL(14,3) NULL', 'qty REAL', 'NUMERIC(14,3) NULL');
    wms_ensure_column($pdo, 'shipment_flags', 'pallet', 'VARCHAR(50) NULL', 'pallet TEXT', 'VARCHAR(50) NULL');
    // Артикул в факте отгрузки: по серийнику видно, что именно отгружено.
    wms_ensure_column($pdo, 'shipment_flags', 'sku', 'VARCHAR(50) NULL', 'sku TEXT', 'VARCHAR(50) NULL');

    // --- контрагенты и грузополучатели ---
    $csg = wms_ext_table($pdo, 'consignees');
    if ($drv === 'pgsql') {
        wms_ensure_table($pdo, 'counterparties', "CREATE TABLE IF NOT EXISTS counterparties (
          id BIGSERIAL PRIMARY KEY,
          name VARCHAR(255) NOT NULL,
          inn VARCHAR(20) NULL,
          ctype VARCHAR(30) NOT NULL DEFAULT 'client',
          phone VARCHAR(50) NULL,
          is_active SMALLINT NOT NULL DEFAULT 1,
          reserve_strategy VARCHAR(10) NOT NULL DEFAULT 'FIFO',
          scan_serial_in SMALLINT NOT NULL DEFAULT 0,
          scan_serial_out SMALLINT NOT NULL DEFAULT 0,
          placement_zones VARCHAR(255) NULL,
          placement_cell_codes TEXT NULL)");
        wms_ensure_table($pdo, 'consignees', "CREATE TABLE IF NOT EXISTS consignees (
          id BIGSERIAL PRIMARY KEY,
          counterparty_id BIGINT NULL REFERENCES counterparties (id) ON DELETE SET NULL,
          name VARCHAR(255) NOT NULL,
          address VARCHAR(255) NULL,
          phone VARCHAR(50) NULL)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $cp (
          id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, inn TEXT,
          ctype TEXT NOT NULL DEFAULT 'client', phone TEXT, is_active INTEGER DEFAULT 1,
          reserve_strategy TEXT DEFAULT 'FIFO',
          scan_serial_in INTEGER DEFAULT 0, scan_serial_out INTEGER DEFAULT 0,
          placement_zones TEXT, placement_cell_codes TEXT)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS $csg (
          id INTEGER PRIMARY KEY AUTOINCREMENT, counterparty_id INTEGER, name TEXT NOT NULL,
          address TEXT, phone TEXT)");
    }

    // --- журнал склада ---
    $jt = wms_ext_table($pdo, 'wms_journal');
    if ($drv === 'pgsql') {
        wms_ensure_table($pdo, 'wms_journal', "CREATE TABLE IF NOT EXISTS wms_journal (
          id BIGSERIAL PRIMARY KEY,
          doc_date TIMESTAMP NOT NULL DEFAULT NOW(),
          op_type VARCHAR(30) NOT NULL,
          doc_type VARCHAR(30) NULL,
          doc_no VARCHAR(100) NULL,
          sku VARCHAR(50) NULL,
          product_name VARCHAR(255) NULL,
          qty NUMERIC(14,3) NOT NULL DEFAULT 0,
          pallet VARCHAR(50) NULL,
          sscc VARCHAR(50) NULL,
          cell_from VARCHAR(50) NULL,
          cell_to VARCHAR(50) NULL,
          zone VARCHAR(30) NULL,
          user_id BIGINT NULL,
          user_fio VARCHAR(150) NULL,
          comment TEXT NULL,
          quality VARCHAR(20) NULL DEFAULT 'новый',
          created_at TIMESTAMP NOT NULL DEFAULT NOW())");
        wms_ensure_index($pdo, 'wms_journal', 'idx_journal_date', 'doc_date');
        wms_ensure_index($pdo, 'wms_journal', 'idx_journal_op', 'op_type');
        wms_ensure_index($pdo, 'wms_journal', 'idx_journal_sku', 'sku');
    } else {
        wms_ensure_table($pdo, $jt, "CREATE TABLE IF NOT EXISTS $jt (
          id INTEGER PRIMARY KEY AUTOINCREMENT, doc_date TEXT NOT NULL, op_type TEXT NOT NULL,
          doc_type TEXT, doc_no TEXT, sku TEXT, product_name TEXT, qty REAL DEFAULT 0,
          pallet TEXT, sscc TEXT, cell_from TEXT, cell_to TEXT, zone TEXT,
          user_id INTEGER, user_fio TEXT, comment TEXT,
          quality TEXT DEFAULT 'новый',
          created_at TEXT DEFAULT (datetime('now')))");
        wms_ensure_index($pdo, $jt, 'idx_journal_date', 'doc_date');
        wms_ensure_index($pdo, $jt, 'idx_journal_op', 'op_type');
    }
    // Старые БД: журнал создан без качества — без этой колонки INSERT журнала
    // молча падал, и операции (приход/размещение/перемещение) не отображались.
    wms_ensure_column($pdo, $jt, 'quality', "VARCHAR(20) NULL DEFAULT 'новый'", "quality TEXT DEFAULT 'новый'", "VARCHAR(20) NULL DEFAULT 'новый'");

    // --- журнал серийных номеров ---
    $sj = wms_ext_table($pdo, 'wms_serial_journal');
    if ($drv === 'pgsql') {
        wms_ensure_table($pdo, 'wms_serial_journal', "CREATE TABLE IF NOT EXISTS wms_serial_journal (
          id BIGSERIAL PRIMARY KEY,
          serial_no VARCHAR(100) NOT NULL,
          sku VARCHAR(50) NULL,
          product_name VARCHAR(255) NULL,
          op_type VARCHAR(20) NOT NULL DEFAULT 'Приход',
          receipt_doc_no VARCHAR(100) NULL,
          shipment_doc_no VARCHAR(100) NULL,
          pallet VARCHAR(50) NULL,
          qty NUMERIC(14,3) NOT NULL DEFAULT 1,
          cell VARCHAR(50) NULL,
          doc_date TIMESTAMP NOT NULL DEFAULT NOW(),
          user_id BIGINT NULL,
          user_fio VARCHAR(150) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT NOW())");
        wms_ensure_index($pdo, 'wms_serial_journal', 'idx_sj_serial', 'serial_no');
        wms_ensure_index($pdo, 'wms_serial_journal', 'idx_sj_op', 'op_type');
        wms_ensure_index($pdo, 'wms_serial_journal', 'idx_sj_date', 'doc_date');
    } else {
        wms_ensure_table($pdo, $sj, "CREATE TABLE IF NOT EXISTS $sj (
          id INTEGER PRIMARY KEY AUTOINCREMENT, serial_no TEXT NOT NULL, sku TEXT, product_name TEXT,
          op_type TEXT NOT NULL DEFAULT 'Приход', receipt_doc_no TEXT, shipment_doc_no TEXT,
          pallet TEXT, qty REAL DEFAULT 1, cell TEXT, doc_date TEXT NOT NULL,
          user_id INTEGER, user_fio TEXT, created_at TEXT DEFAULT (datetime('now')))");
        wms_ensure_index($pdo, $sj, 'idx_sj_serial', 'serial_no');
    }

    // --- буфер отбора: виртуальная промежуточная ячейка + сборочная паллета ---
    $bt = wms_ext_table($pdo, 'tsd_pick_buffers');
    if ($drv === 'pgsql') {
        wms_ensure_table($pdo, 'tsd_pick_buffers', "CREATE TABLE IF NOT EXISTS tsd_pick_buffers (
          id BIGSERIAL PRIMARY KEY,
          shipment_id BIGINT NULL,
          task_id BIGINT NULL,
          sku VARCHAR(50) NULL,
          product_name VARCHAR(255) NULL,
          qty NUMERIC(14,3) NOT NULL DEFAULT 0,
          user_id BIGINT NULL,
          user_fio VARCHAR(150) NULL,
          buffer_cell VARCHAR(100) NOT NULL,
          assembly_sscc VARCHAR(50) NULL,
          gate VARCHAR(30) NULL,
          status VARCHAR(30) NOT NULL DEFAULT 'picked',
          created_at TIMESTAMP NOT NULL DEFAULT NOW(),
          updated_at TIMESTAMP NOT NULL DEFAULT NOW())");
        wms_ensure_index($pdo, 'tsd_pick_buffers', 'idx_pbuf_ship', 'shipment_id');
    } else {
        wms_ensure_table($pdo, $bt, "CREATE TABLE IF NOT EXISTS $bt (
          id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER, task_id INTEGER,
          sku TEXT, product_name TEXT, qty REAL DEFAULT 0, user_id INTEGER, user_fio TEXT,
          buffer_cell TEXT NOT NULL, assembly_sscc TEXT, gate TEXT,
          status TEXT NOT NULL DEFAULT 'picked',
          created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    }
}
