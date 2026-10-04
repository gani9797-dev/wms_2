<?php
declare(strict_types=1);

// Единая точка доступа к демо-БД (SQLite).
// Канонические файлы:
//   поступления:  02-postupleniya/wms_receipts.sqlite (владелец — модуль 02)
//   номенклатура: 08-nomenklatura/wms_nomenclature.sqlite
//   ячейки:      05-yacheyki-hraneniya/wms_storage.sqlite (владелец — модуль 05)
// Легаси-дубль receipts/wms_receipts.sqlite напрямую больше не использовать —
// все точки (ТСД, 02, receipts-legacy) ходят через wms_receipts_pdo().
// В проде (MySQL, index.php) обе предметные области уже живут в одной БД `wms`,
// поэтому разделение файлов — только демо-режим SQLite.

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

function wms_receipts_pdo(): PDO
{
    $pdo = new PDO('sqlite:' . wms_receipts_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_ensure_receipt_schema($pdo);
    return $pdo;
}

function wms_nomenclature_pdo(): PDO
{
    $pdo = new PDO('sqlite:' . wms_nomenclature_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function wms_storage_pdo(): PDO
{
    $pdo = new PDO('sqlite:' . wms_storage_db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    wms_ensure_storage_schema($pdo);
    return $pdo;
}

// Дотягивает схему поступлений до полной (база + tsd_* + guard sync).
// Безопасно вызывать на каждый запрос: только CREATE TABLE IF NOT EXISTS / ALTER при отсутствии колонки.
function wms_ensure_receipt_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS contractors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, fio TEXT NOT NULL, role TEXT DEFAULT 'storekeeper', position TEXT)");
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
      vat_sum REAL DEFAULT 0, sum_vat REAL DEFAULT 0)");
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_act_rasx (id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sku TEXT NOT NULL, type TEXT NOT NULL, qty_diff REAL NOT NULL, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_stock (id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sku TEXT NOT NULL, pallet TEXT, qty REAL NOT NULL, cell TEXT, created_at TEXT DEFAULT (datetime('now')))");

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
    $ensureCol('receipt_operations', 'reason', "reason TEXT");
    $ensureCol('receipt_flags', 'status', "status TEXT DEFAULT 'годный'");
    $ensureCol('receipt_flags', 'reason', "reason TEXT");
    $ensureCol('users', 'pin', "pin TEXT");
    try {
        $pdo->exec("UPDATE users SET pin=printf('%04d',1000+id) WHERE pin IS NULL OR pin=''");
    } catch (Throwable $e) {
    }
}

// Дотягивает схему ячеек хранения (модуль 05). Безопасно на каждый запрос.
function wms_ensure_storage_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, fio TEXT NOT NULL, role TEXT DEFAULT 'storekeeper')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cells (
      id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, warehouse_id INTEGER NOT NULL,
      zone TEXT DEFAULT 'storage', cell_type TEXT DEFAULT 'pallet', category TEXT,
      status TEXT DEFAULT 'free', capacity REAL DEFAULT 0, qty REAL DEFAULT 0,
      reserved_qty REAL DEFAULT 0, pallet_count INTEGER DEFAULT 0,
      building TEXT, row_no TEXT, level_no TEXT, place_no TEXT, coord_x INTEGER, coord_y INTEGER,
      temp_from REAL, temp_to REAL, is_locked INTEGER DEFAULT 0, lock_reason TEXT,
      allowed_goods TEXT DEFAULT 'Все', max_weight_kg REAL, max_pallets INTEGER,
      hazmat_allowed INTEGER DEFAULT 0, comment TEXT, is_archived INTEGER DEFAULT 0,
      created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_cell_code_wh ON storage_cells(code, warehouse_id)");
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
      doc_type TEXT NOT NULL, doc_no TEXT NOT NULL, sku TEXT, qty_change REAL NOT NULL,
      balance REAL NOT NULL, cell_from TEXT, cell_to TEXT)");
}
