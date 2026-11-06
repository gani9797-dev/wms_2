<?php
declare(strict_types=1);
/**
 * Биллинг 3PL (модуль 16): движок расчёта начислений.
 *
 * Таблицы (имена вольные, но соответствуют ТЗ §12):
 *   billing_uom, billing_calc_type, billing_service_group, billing_service,
 *   billing_client, billing_tariff, billing_tariff_line, billing_tariff_tier,
 *   billing_event, billing_stock_daily, billing_charge, billing_period,
 *   billing_run, billing_calendar, billing_conversion, billing_adj_reason.
 *
 * Алгоритм (ТЗ §6.6):
 *   разовое начисление: qty → free_qty → ступени → скидка → коэффициент календаря →
 *                        округление → min/max по линии за период;
 *   суточное хранение: qty_start + qty_in − qty_out по режиму (END/BEGIN/AVERAGE),
 *                        бесплатные сутки по партии, коэффициент, ступени, НДС;
 *   прогон: dry-run / полный, идемпотентный (старые ACTIVE → SUPERSEDED),
 *           контроль закрытого периода, минимальная сумма счёта.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pg.php';

/** PDO для биллинга (в PG — общая схема, в SQLite — БД номенклатуры). */
function billing_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = function_exists('wms_nomenclature_pdo') ? wms_nomenclature_pdo() : wms_pg_pdo();
    billing_ensure_schema($pdo);
    billing_seed($pdo);
    return $pdo;
}

function billing_driver(PDO $pdo): string
{
    return function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function billing_now_sql(PDO $pdo): string
{
    return function_exists('wms_now_sql') ? wms_now_sql($pdo) : "datetime('now')";
}

/** Схема таблиц (PG / SQLite). */
function billing_ensure_schema(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    $done[$id] = true;
    $pg = billing_driver($pdo) === 'pgsql';
    $auto = $pg ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $text = $pg ? 'TEXT' : 'TEXT';
    $num = $pg ? 'NUMERIC(18,4)' : 'REAL';
    $d = $pg ? 'DATE' : 'TEXT';
    $ts = $pg ? 'TIMESTAMP' : 'TEXT';
    $now = $pg ? 'NOW()' : "datetime('now')";
    $ddl = [
        "CREATE TABLE IF NOT EXISTS billing_uom (id {$auto}, code VARCHAR(20) UNIQUE NOT NULL, name VARCHAR(100) NOT NULL, precision INTEGER DEFAULT 2)",
        "CREATE TABLE IF NOT EXISTS billing_calc_type (id {$auto}, code VARCHAR(30) UNIQUE NOT NULL, name VARCHAR(120) NOT NULL, is_periodic SMALLINT DEFAULT 0, formula VARCHAR(255) NULL, description TEXT NULL)",
        "CREATE TABLE IF NOT EXISTS billing_service_group (id {$auto}, code VARCHAR(30) UNIQUE NOT NULL, name VARCHAR(120) NOT NULL, sort_order INTEGER DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS billing_service (id {$auto}, code VARCHAR(40) UNIQUE NOT NULL, name VARCHAR(200) NOT NULL, group_id BIGINT NULL, calc_type_id BIGINT NULL, uom_id BIGINT NULL, driver_code VARCHAR(30) NULL, event_type_code VARCHAR(40) NULL, default_price {$num} DEFAULT 0, vat_rate {$num} DEFAULT 20, is_active SMALLINT DEFAULT 1, comment TEXT NULL, sort_order INTEGER DEFAULT 0, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_client (id {$auto}, counterparty_id BIGINT NULL, code VARCHAR(50) NULL, name VARCHAR(255) NOT NULL, inn VARCHAR(20) NULL, currency VARCHAR(10) DEFAULT 'RUB', status VARCHAR(20) DEFAULT 'active', created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_tariff (id {$auto}, client_id BIGINT NOT NULL, number VARCHAR(32) NOT NULL, date_from {$d} NOT NULL, date_to {$d} NULL, currency VARCHAR(10) DEFAULT 'RUB', status VARCHAR(20) DEFAULT 'draft', period_type VARCHAR(20) DEFAULT 'month', cut_off_time VARCHAR(8) DEFAULT '23:59:59', storage_day_basis VARCHAR(20) DEFAULT 'END_OF_DAY', free_storage_days INTEGER DEFAULT 0, rounding_rule VARCHAR(20) DEFAULT 'ROUND', rounding_rule_qty VARCHAR(20) DEFAULT 'NONE', min_charge_amount {$num} NULL, notes TEXT NULL, version INTEGER DEFAULT 1, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_tariff_line (id {$auto}, tariff_id BIGINT NOT NULL, service_id BIGINT NOT NULL, is_enabled SMALLINT DEFAULT 1, price {$num} DEFAULT 0, min_charge {$num} NULL, max_charge {$num} NULL, discount_percent {$num} DEFAULT 0, min_qty_threshold {$num} NULL, free_qty {$num} DEFAULT 0, date_from {$d} NULL, date_to {$d} NULL, comment TEXT NULL)",
        "CREATE TABLE IF NOT EXISTS billing_tariff_tier (id {$auto}, line_id BIGINT NOT NULL, threshold_from {$num} DEFAULT 0, threshold_to {$num} NULL, price {$num} DEFAULT 0, mode VARCHAR(20) DEFAULT 'VOLUME', basis VARCHAR(20) DEFAULT 'PER_DAY')",
        "CREATE TABLE IF NOT EXISTS billing_event (id {$auto}, external_id VARCHAR(100) NULL, source VARCHAR(20) DEFAULT 'MANUAL', client_id BIGINT NOT NULL, event_type_code VARCHAR(40) NOT NULL, event_date {$d} NOT NULL, quantity {$num} DEFAULT 0, uom_code VARCHAR(20) NULL, sku VARCHAR(50) NULL, pallet_count {$num} DEFAULT 0, box_count {$num} DEFAULT 0, piece_count {$num} DEFAULT 0, sticker_qty {$num} DEFAULT 0, weight_kg {$num} DEFAULT 0, volume_m3 {$num} DEFAULT 0, doc_type VARCHAR(50) NULL, doc_no VARCHAR(100) NULL, status VARCHAR(30) DEFAULT 'NEW', reason TEXT NULL, payload TEXT NULL, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_stock_daily (id {$auto}, client_id BIGINT NOT NULL, service_id BIGINT NOT NULL, uom_code VARCHAR(20) NULL, day {$d} NOT NULL, qty_start {$num} DEFAULT 0, qty_in {$num} DEFAULT 0, qty_out {$num} DEFAULT 0, qty_billable {$num} DEFAULT 0, is_free_day SMALLINT DEFAULT 0, day_no INTEGER DEFAULT 0, price {$num} DEFAULT 0, amount_net {$num} DEFAULT 0, charge_id BIGINT NULL, tariff_id BIGINT NULL, run_id BIGINT NULL, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_charge (id {$auto}, client_id BIGINT NOT NULL, service_id BIGINT NOT NULL, charge_date {$d} NULL, period_from {$d} NULL, period_to {$d} NULL, quantity {$num} DEFAULT 0, price {$num} DEFAULT 0, amount_net {$num} DEFAULT 0, vat_rate {$num} DEFAULT 20, vat_amount {$num} DEFAULT 0, amount_gross {$num} DEFAULT 0, tariff_id BIGINT NULL, tariff_line_id BIGINT NULL, tier_id BIGINT NULL, event_id BIGINT NULL, stock_daily_id BIGINT NULL, calc_trace TEXT NULL, status VARCHAR(20) DEFAULT 'ACTIVE', adjustment_of_id BIGINT NULL, run_id BIGINT NULL, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_period (id {$auto}, client_id BIGINT NULL, period_from {$d} NOT NULL, period_to {$d} NOT NULL, status VARCHAR(20) DEFAULT 'OPEN', closed_at {$ts} NULL, control_json TEXT NULL, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_run (id {$auto}, period_from {$d} NOT NULL, period_to {$d} NOT NULL, client_scope TEXT NULL, mode VARCHAR(20) DEFAULT 'FULL', status VARCHAR(20) DEFAULT 'DONE', started_at {$ts} NULL, finished_at {$ts} NULL, stats TEXT NULL, created_at {$ts} DEFAULT {$now})",
        "CREATE TABLE IF NOT EXISTS billing_calendar (id {$auto}, day {$d} UNIQUE NOT NULL, is_working SMALLINT DEFAULT 1, surcharge_factor {$num} DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS billing_conversion (id {$auto}, from_code VARCHAR(20) NOT NULL, to_code VARCHAR(20) NOT NULL, factor {$num} DEFAULT 1)",
        "CREATE TABLE IF NOT EXISTS billing_adj_reason (id {$auto}, code VARCHAR(30) UNIQUE NOT NULL, name VARCHAR(200) NOT NULL, requires_document SMALLINT DEFAULT 0)",
    ];
    foreach ($ddl as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable) {
        }
    }
    foreach ([
        'CREATE INDEX IF NOT EXISTS idx_bill_event_client ON billing_event (client_id, event_date)',
        'CREATE INDEX IF NOT EXISTS idx_bill_event_status ON billing_event (status)',
        'CREATE INDEX IF NOT EXISTS idx_bill_charge_client ON billing_charge (client_id, charge_date)',
        'CREATE INDEX IF NOT EXISTS idx_bill_charge_period ON billing_charge (period_from, period_to)',
        'CREATE INDEX IF NOT EXISTS idx_bill_stock_day ON billing_stock_daily (client_id, day)',
        'CREATE INDEX IF NOT EXISTS idx_bill_line_tariff ON billing_tariff_line (tariff_id)',
    ] as $ix) {
        try {
            $pdo->exec($ix);
        } catch (Throwable) {
        }
    }
}

/** Стартовые справочники и услуги (идемпотентно). */
function billing_seed(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    $done[$id] = true;
    try {
        $cnt = (int)$pdo->query('SELECT COUNT(*) FROM billing_service')->fetchColumn();
    } catch (Throwable) {
        return;
    }
    if ($cnt > 0) {
        return;
    }
    $uoms = [
        ['pallet', 'паллета'], ['pallet_place', 'паллетоместо'], ['box', 'короб'],
        ['piece', 'штука'], ['kg', 'кг'], ['m3', 'м³'], ['scan', 'скан'],
        ['sticker', 'стикер'], ['document', 'документ'], ['line', 'строка'],
        ['month', 'месяц'], ['day', 'сутки'], ['kit', 'набор'],
    ];
    $groups = [
        ['in', 'Приёмка'], ['storage', 'Хранение'], ['pick', 'Отбор и комплектация'],
        ['label', 'Маркировка'], ['out', 'Отгрузка'], ['add', 'Доп. обработка'],
        ['sub', 'Абонентские'], ['misc', 'Прочее'],
    ];
    $types = [
        ['PER_UNIT', 'За единицу события', 0],
        ['PER_DOCUMENT', 'За документ', 0],
        ['PER_LINE', 'За строку документа', 0],
        ['STORAGE_DAY', 'Суточное хранение', 1],
        ['PERIOD_FIXED', 'Фиксированная сумма за период', 1],
        ['PERCENT_OF_VALUE', 'Процент от стоимости товара', 1],
        ['TIERED_PERIOD', 'Ступенчатая за период', 1],
        ['FORMULA', 'Пользовательская формула', 0],
        ['PACKAGE', 'Пакет услуг', 0],
    ];
    $drv = billing_driver($pdo);
    $now = billing_now_sql($pdo);
    $uomId = [];
    foreach ($uoms as [$c, $n]) {
        try {
            $pdo->prepare("INSERT INTO billing_uom (code,name) VALUES (:c,:n)")->execute([':c' => $c, ':n' => $n]);
        } catch (Throwable) {
        }
        $uomId[$c] = (int)($pdo->query("SELECT id FROM billing_uom WHERE code=" . $pdo->quote($c))->fetchColumn() ?: 0);
    }
    $grpId = [];
    foreach ($groups as $i => [$c, $n]) {
        try {
            $pdo->prepare("INSERT INTO billing_service_group (code,name,sort_order) VALUES (:c,:n,:o)")->execute([':c' => $c, ':n' => $n, ':o' => $i]);
        } catch (Throwable) {
        }
        $grpId[$c] = (int)($pdo->query("SELECT id FROM billing_service_group WHERE code=" . $pdo->quote($c))->fetchColumn() ?: 0);
    }
    $typeId = [];
    foreach ($types as [$c, $n, $p]) {
        try {
            $pdo->prepare("INSERT INTO billing_calc_type (code,name,is_periodic) VALUES (:c,:n,:p)")->execute([':c' => $c, ':n' => $n, ':p' => $p]);
        } catch (Throwable) {
        }
        $typeId[$c] = (int)($pdo->query("SELECT id FROM billing_calc_type WHERE code=" . $pdo->quote($c))->fetchColumn() ?: 0);
    }
    // Стартовый набор услуг (ТЗ §6.1.5).
    $services = [
        ['IN_PALLET', 'Паллетная приёмка', 'in', 'PER_UNIT', 'pallet', 'PALLET', 'IN_PALLET', 300],
        ['IN_BOX', 'Коробочная приёмка', 'in', 'PER_UNIT', 'box', 'BOX', 'IN_BOX', 25],
        ['IN_PIECE', 'Штучная приёмка', 'in', 'PER_UNIT', 'piece', 'PIECE', 'IN_PIECE', 3],
        ['IN_SN_SCAN', 'Сканирование серийного номера при приёмке', 'in', 'PER_UNIT', 'scan', 'SN_SCAN', 'IN_SN_SCAN', 2],
        ['IN_UNLOAD', 'Разгрузка ТС (за паллету)', 'in', 'PER_UNIT', 'pallet', 'PALLET', 'IN_UNLOAD', 120],
        ['IN_DOC', 'Оформление приёмки (за документ)', 'in', 'PER_DOCUMENT', 'document', 'DOCUMENT', 'IN_DOC', 500],
        ['IN_PLACEMENT', 'Размещение на место хранения', 'in', 'PER_UNIT', 'pallet', 'PALLET', 'IN_PLACEMENT', 90],
        ['STORAGE_PALLET_DAY', 'Хранение, паллетоместо/сутки', 'storage', 'STORAGE_DAY', 'pallet_place', 'PALLET_PLACE', 'STORAGE_DAY', 380],
        ['STORAGE_BOX_DAY', 'Хранение, короб/сутки', 'storage', 'STORAGE_DAY', 'box', 'BOX', 'STORAGE_DAY', 12],
        ['STORAGE_PIECE_DAY', 'Хранение, штука/сутки', 'storage', 'STORAGE_DAY', 'piece', 'PIECE', 'STORAGE_DAY', 1],
        ['STORAGE_M3_DAY', 'Хранение, м³/сутки', 'storage', 'STORAGE_DAY', 'm3', 'M3', 'STORAGE_DAY', 900],
        ['STORAGE_MIN_MONTH', 'Минимальный месячный сбор за хранение', 'storage', 'PERIOD_FIXED', 'month', 'MONTH', 'STORAGE_MIN', 5000],
        ['PICK_PIECE', 'Отбор, штука', 'pick', 'PER_UNIT', 'piece', 'PIECE', 'PICK_PIECE', 4],
        ['PICK_BOX', 'Отбор, короб', 'pick', 'PER_UNIT', 'box', 'BOX', 'PICK_BOX', 30],
        ['PICK_PALLET', 'Отбор, паллета', 'pick', 'PER_UNIT', 'pallet', 'PALLET', 'PICK_PALLET', 200],
        ['PICK_LINE', 'Комплектация, строка заказа', 'pick', 'PER_LINE', 'line', 'ORDER_LINE', 'PICK_LINE', 15],
        ['PICK_ORDER', 'Обработка заказа (сборка)', 'pick', 'PER_DOCUMENT', 'document', 'DOCUMENT', 'PICK_ORDER', 250],
        ['LBL_STICKER', 'Стикеровка (стикер, по типу/размеру)', 'label', 'PER_UNIT', 'sticker', 'STICKER', 'LBL_STICKER', 7],
        ['LBL_SN_SCAN', 'Сканирование SN при отгрузке', 'label', 'PER_UNIT', 'scan', 'SN_SCAN', 'LBL_SN_SCAN', 2],
        ['LBL_RELABEL', 'Перемаркировка / замена этикетки', 'label', 'PER_UNIT', 'sticker', 'STICKER', 'LBL_RELABEL', 10],
        ['OUT_PALLET', 'Паллетная отгрузка', 'out', 'PER_UNIT', 'pallet', 'PALLET', 'OUT_PALLET', 300],
        ['OUT_BOX', 'Отгрузка коробами', 'out', 'PER_UNIT', 'box', 'BOX', 'OUT_BOX', 25],
        ['OUT_PIECE', 'Отгрузка штучно', 'out', 'PER_UNIT', 'piece', 'PIECE', 'OUT_PIECE', 3],
        ['OUT_LOAD', 'Погрузка ТС', 'out', 'PER_UNIT', 'pallet', 'PALLET', 'OUT_LOAD', 150],
        ['OUT_DOC', 'Оформление отгрузочных документов', 'out', 'PER_DOCUMENT', 'document', 'DOCUMENT', 'OUT_DOC', 500],
        ['ADD_REPACK', 'Переупаковка', 'add', 'PER_UNIT', 'box', 'BOX', 'ADD_REPACK', 40],
        ['ADD_KIT', 'Комплектация набора (китование)', 'add', 'PER_UNIT', 'kit', 'KIT', 'ADD_KIT', 60],
        ['ADD_CHECK', 'Проверка качества / пересчёт', 'add', 'PER_UNIT', 'piece', 'PIECE', 'ADD_CHECK', 5],
        ['ADD_INVENTORY', 'Инвентаризация', 'add', 'PER_UNIT', 'pallet', 'PALLET', 'ADD_INVENTORY', 100],
        ['SUB_BASE', 'Базовое абонентское обслуживание', 'sub', 'PERIOD_FIXED', 'month', 'MONTH', 'SUB_BASE', 15000],
        ['MISC_CUSTOM', 'Прочая услуга (свободная)', 'misc', 'PER_UNIT', 'piece', 'PIECE', 'MISC_CUSTOM', 0],
    ];
    $ins = $pdo->prepare(
        "INSERT INTO billing_service (code,name,group_id,calc_type_id,uom_id,driver_code,event_type_code,default_price,sort_order)
         VALUES (:c,:n,:g,:t,:u,:d,:e,:p,:o)"
    );
    $i = 0;
    foreach ($services as [$code, $name, $g, $t, $u, $driver, $evt, $price]) {
        try {
            $ins->execute([':c' => $code, ':n' => $name, ':g' => $grpId[$g] ?? null, ':t' => $typeId[$t] ?? null,
                ':u' => $uomId[$u] ?? null, ':d' => $driver, ':e' => $evt, ':p' => $price, ':o' => $i++]);
        } catch (Throwable) {
        }
    }
    // Коэффициенты пересчёта: 1 паллета = 48 коробов = 960 штук.
    foreach ([['pallet', 'box', 48], ['pallet', 'piece', 960], ['box', 'piece', 20], ['box', 'pallet_place', 1 / 48]] as [$a, $b, $f]) {
        try {
            $pdo->prepare("INSERT INTO billing_conversion (from_code,to_code,factor) VALUES (:a,:b,:f)")
                ->execute([':a' => $a, ':b' => $b, ':f' => $f]);
        } catch (Throwable) {
        }
    }
    // Календарь: рабочие дни с коэффициентом 1 (наценку выходного можно включить в настройках).
    $start = new DateTimeImmutable('-60 days');
    $cal = $pdo->prepare("INSERT INTO billing_calendar (day,is_working,surcharge_factor) VALUES (:d,:w,:f)");
    for ($k = 0; $k < 400; $k++) {
        $day = $start->modify('+' . $k . ' days');
        $dow = (int)$day->format('N');
        try {
            $cal->execute([':d' => $day->format('Y-m-d'), ':w' => $dow >= 6 ? 0 : 1, ':f' => 1]);
        } catch (Throwable) {
        }
    }
    // Причины корректировок.
    foreach ([['REVERSAL', 'Сторно (отмена операции)'], ['RECALC', 'Перерасчёт по причине'], ['DISCOUNT', 'Скидка / претензия']] as [$c, $n]) {
        try {
            $pdo->prepare("INSERT INTO billing_adj_reason (code,name) VALUES (:c,:n)")->execute([':c' => $c, ':n' => $n]);
        } catch (Throwable) {
        }
    }
    billing_seed_demo($pdo);
}

/** Демо-клиенты, тариф и события текущего месяца — чтобы алгоритм сразу было видно. */
function billing_seed_demo(PDO $pdo): void
{
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM billing_client')->fetchColumn() > 0) {
            return;
        }
    } catch (Throwable) {
        return;
    }
    $clients = [];
    try {
        $t = function_exists('wms_ext_table') ? wms_ext_table($pdo, 'counterparties') : 'counterparties';
        foreach ($pdo->query("SELECT id, name, inn FROM $t WHERE ctype='client' ORDER BY id LIMIT 3") as $r) {
            $clients[] = ['cp' => (int)$r['id'], 'name' => (string)$r['name'], 'inn' => (string)($r['inn'] ?? '')];
        }
    } catch (Throwable) {
    }
    if (!$clients) {
        $clients[] = ['cp' => null, 'name' => 'ООО «Демо-Клиент»', 'inn' => '7700000000'];
    }
    $insCl = $pdo->prepare('INSERT INTO billing_client (counterparty_id,code,name,inn,currency,status) VALUES (:cp,:c,:n,:i,:cur,:st)');
    $clientIds = [];
    foreach ($clients as $i => $cl) {
        try {
            $insCl->execute([':cp' => $cl['cp'], ':c' => 'CL-' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT),
                ':n' => $cl['name'], ':i' => $cl['inn'], ':cur' => 'RUB', ':st' => 'active']);
            $clientIds[] = (int)$pdo->lastInsertId();
        } catch (Throwable) {
        }
    }
    if (!$clientIds) {
        return;
    }
    // Демо-период — прошлый (полный) месяц.
    $first = date('Y-m-01', strtotime('first day of previous month'));
    $today = date('Y-m-t', strtotime('first day of previous month'));
    try {
        $pdo->prepare("INSERT INTO billing_tariff (client_id,number,date_from,date_to,currency,status,period_type,
                cut_off_time,storage_day_basis,free_storage_days,rounding_rule,rounding_rule_qty,min_charge_amount,notes,version)
             VALUES (:c,:n,:f,NULL,'RUB','approved','month','23:59:59','END_OF_DAY',0,'ROUND','NONE',0,:no,1)")
            ->execute([':c' => $clientIds[0], ':n' => 'ТС-ДЕМО-' . date('Y'), ':f' => $first, ':no' => 'Демонстрационный прайс']);
        $tariffId = (int)$pdo->lastInsertId();
    } catch (Throwable) {
        return;
    }
    $svcId = function (string $code) use ($pdo): int {
        try {
            $q = $pdo->prepare('SELECT id FROM billing_service WHERE code=:c');
            $q->execute([':c' => $code]);
            return (int)($q->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    };
    $lines = [
        ['IN_PALLET', 300, 0, null, null], ['IN_BOX', 25, 0, null, null], ['IN_DOC', 500, 0, null, null],
        ['PICK_PIECE', 4, 0, null, null], ['PICK_LINE', 15, 0, null, null],
        ['OUT_PALLET', 300, 0, null, null], ['LBL_STICKER', 7, 0, null, null],
        ['STORAGE_PALLET_DAY', 380, 0, null, null],
    ];
    $insLine = $pdo->prepare('INSERT INTO billing_tariff_line (tariff_id,service_id,is_enabled,price,min_charge,max_charge,free_qty) VALUES (:t,:s,1,:p,:mn,:mx,:fq)');
    $storageLine = 0;
    foreach ($lines as [$code, $price, $min, $max, $free]) {
        $sid = $svcId($code);
        if ($sid <= 0) {
            continue;
        }
        try {
            $insLine->execute([':t' => $tariffId, ':s' => $sid, ':p' => $price, ':mn' => $min, ':mx' => $max, ':fq' => $free]);
            if ($code === 'STORAGE_PALLET_DAY') {
                $storageLine = (int)$pdo->lastInsertId();
            }
        } catch (Throwable) {
        }
    }
    // Ступени хранения: 0–100 → 380, 101+ → 350 (VOLUME — вся масса по достигнутой ступени).
    if ($storageLine > 0) {
        $insTier = $pdo->prepare('INSERT INTO billing_tariff_tier (line_id,threshold_from,threshold_to,price,mode,basis) VALUES (:l,:f,:t,:p,:m,:b)');
        try {
            $insTier->execute([':l' => $storageLine, ':f' => 0, ':t' => 100, ':p' => 380, ':m' => 'VOLUME', ':b' => 'PER_DAY']);
            $insTier->execute([':l' => $storageLine, ':f' => 101, ':t' => null, ':p' => 350, ':m' => 'VOLUME', ':b' => 'PER_DAY']);
        } catch (Throwable) {
        }
    }
    // Демо-события текущего месяца.
    try {
        if ((int)$pdo->query('SELECT COUNT(*) FROM billing_event')->fetchColumn() > 0) {
            return;
        }
    } catch (Throwable) {
        return;
    }
    $events = [
        [$first, 'IN_PALLET', 10, 'pallet', 'A-ДЕМО-1'],
        [date('Y-m-02', strtotime($first)), 'IN_BOX', 100, 'box', 'A-ДЕМО-2'],
        [date('Y-m-05', strtotime($first)), 'PICK_PIECE', 50, 'piece', 'S-ДЕМО-1'],
        [date('Y-m-10', strtotime($first)), 'OUT_PALLET', 2, 'pallet', 'S-ДЕМО-2'],
        [date('Y-m-12', strtotime($first)), 'LBL_STICKER', 24, 'sticker', 'S-ДЕМО-2'],
    ];
    $insEv = $pdo->prepare("INSERT INTO billing_event (external_id,source,client_id,event_type_code,event_date,quantity,uom_code,doc_type,doc_no,status)
        VALUES (:e,'DEMO',:c,:t,:d,:q,:u,'DEMO',:doc,'NEW')");
    $n = 0;
    foreach ($events as [$d, $type, $q, $uom, $doc]) {
        try {
            $insEv->execute([':e' => 'DEMO-' . (++$n), ':c' => $clientIds[0], ':t' => $type, ':d' => $d,
                ':q' => $q, ':u' => $uom, ':doc' => $doc]);
        } catch (Throwable) {
        }
    }
}

// ============================================================
// РАСЧЁТ
// ============================================================

/** Коэффициент календаря на дату (наценка выходного/праздника). */
function billing_calendar_factor(PDO $pdo, string $day): float
{
    try {
        $st = $pdo->prepare('SELECT is_working, surcharge_factor FROM billing_calendar WHERE day=:d LIMIT 1');
        $st->execute([':d' => substr($day, 0, 10)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            return (float)($r['surcharge_factor'] ?? 1) > 0 ? (float)$r['surcharge_factor'] : 1.0;
        }
    } catch (Throwable) {
    }
    return 1.0;
}

/** Округление суммы по правилу (ROUND / CEIL). */
function billing_round(float $v, string $rule = 'ROUND'): float
{
    if (strtoupper($rule) === 'CEIL') {
        return ceil($v * 100) / 100;
    }
    return round($v, 2);
}

/**
 * Цена по ступеням.
 * @param array<int,array> $tiers threshold_from, threshold_to, price, mode
 * @return array{amount:float, price:float, tier_id:?int, detail:string}
 */
function billing_tier_amount(array $tiers, float $qty, float $basePrice): array
{
    if (!$tiers || $qty <= 0) {
        return ['amount' => $qty * $basePrice, 'price' => $basePrice, 'tier_id' => null, 'detail' => 'без ступеней'];
    }
    usort($tiers, fn($a, $b) => (float)$a['threshold_from'] <=> (float)$b['threshold_from']);
    $mode = strtoupper((string)($tiers[0]['mode'] ?? 'VOLUME'));
    if ($mode === 'PROGRESSIVE') {
        $amount = 0.0;
        $last = null;
        foreach ($tiers as $t) {
            $from = (float)$t['threshold_from'];
            $to = ($t['threshold_to'] === null || $t['threshold_to'] === '') ? INF : (float)$t['threshold_to'];
            if ($qty < $from) {
                break;
            }
            // Диапазон включительно: 0–100 = 100 единиц, 101–500 = 500-101+1.
            $extraUnit = $from > 0 ? 1.0 : 0.0;
            $units = max(0.0, min($qty, $to) - $from + $extraUnit);
            if ($units > 0) {
                $amount += $units * (float)$t['price'];
                $last = $t;
            }
        }
        if ($last === null) {
            return ['amount' => $qty * $basePrice, 'price' => $basePrice, 'tier_id' => null, 'detail' => 'вне ступеней'];
        }
        return ['amount' => $amount, 'price' => (float)$last['price'], 'tier_id' => (int)$last['id'], 'detail' => 'PROGRESSIVE'];
    }
    // VOLUME: вся масса по цене достигнутой ступени.
    $hit = null;
    foreach ($tiers as $t) {
        $from = (float)$t['threshold_from'];
        $to = ($t['threshold_to'] === null || $t['threshold_to'] === '') ? INF : (float)$t['threshold_to'];
        if ($qty >= $from && $qty <= $to) {
            $hit = $t;
            break;
        }
    }
    if ($hit === null) {
        return ['amount' => $qty * $basePrice, 'price' => $basePrice, 'tier_id' => null, 'detail' => 'вне ступеней'];
    }
    return ['amount' => $qty * (float)$hit['price'], 'price' => (float)$hit['price'], 'tier_id' => (int)$hit['id'], 'detail' => 'VOLUME'];
}

/** Действующее тарифное соглашение клиента на дату. */
function billing_tariff_for(PDO $pdo, int $clientId, string $day): ?array
{
    try {
        $st = $pdo->prepare(
            "SELECT * FROM billing_tariff
              WHERE client_id=:c AND status IN ('approved','draft')
                AND (date_from IS NULL OR date_from<=:d)
                AND (date_to IS NULL OR date_to>=:d)
              ORDER BY CASE WHEN status='approved' THEN 0 ELSE 1 END, date_from DESC, id DESC LIMIT 1"
        );
        $st->execute([':c' => $clientId, ':d' => substr($day, 0, 10)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** Линия тарифа для услуги (по дате события). */
function billing_line_for(PDO $pdo, int $tariffId, int $serviceId, string $day): ?array
{
    try {
        $st = $pdo->prepare(
            "SELECT * FROM billing_tariff_line
              WHERE tariff_id=:t AND service_id=:s
                AND (date_from IS NULL OR date_from<=:d)
                AND (date_to IS NULL OR date_to>=:d)
              ORDER BY date_from DESC, id DESC LIMIT 1"
        );
        $st->execute([':t' => $tariffId, ':s' => $serviceId, ':d' => substr($day, 0, 10)]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** Ступени линии. */
function billing_tiers(PDO $pdo, int $lineId): array
{
    try {
        $st = $pdo->prepare('SELECT * FROM billing_tariff_tier WHERE line_id=:l ORDER BY threshold_from');
        $st->execute([':l' => $lineId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Начисление по одному событию.
 * Порядок: qty → free_qty → порог → ступени → скидка → коэффициент календаря → округление.
 * @return array{amount:float, qty:float, price:float, vat:float, trace:array, error?:string}
 */
function billing_event_amount(PDO $pdo, array $event, array $service, array $line, array $tariff): array
{
    $qty = (float)($event['quantity'] ?? 0);
    $free = (float)($line['free_qty'] ?? 0);
    if ($free > 0) {
        $qty = max(0.0, $qty - $free);
    }
    $threshold = $line['min_qty_threshold'];
    if ($threshold !== null && $threshold !== '' && $qty < (float)$threshold) {
        return ['amount' => 0.0, 'qty' => $qty, 'price' => 0.0, 'vat' => 0.0, 'error' => 'ниже порога ' . $threshold];
    }
    $tiers = billing_tiers($pdo, (int)$line['id']);
    $calc = billing_tier_amount($tiers, $qty, (float)$line['price']);
    $amount = (float)$calc['amount'];
    $discount = (float)($line['discount_percent'] ?? 0);
    if ($discount > 0) {
        $amount *= (1 - $discount / 100);
    }
    $coef = billing_calendar_factor($pdo, (string)$event['event_date']);
    $amount *= $coef;
    $rule = (string)($tariff['rounding_rule'] ?? 'ROUND');
    $amount = billing_round($amount, $rule);
    $vat = (float)($service['vat_rate'] ?? 20);
    return [
        'amount' => $amount,
        'qty' => $qty,
        'price' => (float)$calc['price'],
        'vat' => $vat,
        'trace' => [
            'qty_in' => (float)($event['quantity'] ?? 0),
            'free_qty' => $free,
            'qty_billed' => $qty,
            'tier' => $calc['detail'],
            'tier_id' => $calc['tier_id'],
            'price' => $calc['price'],
            'discount_percent' => $discount,
            'calendar_factor' => $coef,
            'rounding_rule' => $rule,
            'amount_net' => $amount,
        ],
    ];
}

/** Множитель пересчёта единиц (from → to). */
function billing_factor(PDO $pdo, string $from, string $to): float
{
    if ($from === '' || $to === '' || $from === $to) {
        return 1.0;
    }
    try {
        $st = $pdo->prepare('SELECT factor FROM billing_conversion WHERE from_code=:f AND to_code=:t ORDER BY id LIMIT 1');
        $st->execute([':f' => $from, ':t' => $to]);
        $v = $st->fetchColumn();
        if ($v !== false) {
            return (float)$v;
        }
    } catch (Throwable) {
    }
    return 1.0;
}

/** Вставка начисления. */
function billing_add_charge(PDO $pdo, array $c): int
{
    $now = billing_now_sql($pdo);
    $st = $pdo->prepare(
        "INSERT INTO billing_charge (client_id,service_id,charge_date,period_from,period_to,quantity,price,amount_net,
            vat_rate,vat_amount,amount_gross,tariff_id,tariff_line_id,tier_id,event_id,stock_daily_id,calc_trace,status,
            adjustment_of_id,run_id,created_at)
         VALUES (:cl,:sv,:cd,:pf,:pt,:q,:p,:net,:vr,:va,:gr,:tid,:lid,:tier,:eid,:sd,:tr,:stt,:adj,:run,$now)"
    );
    $net = round((float)($c['amount_net'] ?? 0), 2);
    $vatRate = (float)($c['vat_rate'] ?? 20);
    $vat = round($net * $vatRate / 100, 2);
    $st->execute([
        ':cl' => (int)$c['client_id'], ':sv' => (int)$c['service_id'], ':cd' => $c['charge_date'] ?? null,
        ':pf' => $c['period_from'] ?? null, ':pt' => $c['period_to'] ?? null,
        ':q' => (float)($c['quantity'] ?? 0), ':p' => (float)($c['price'] ?? 0), ':net' => $net,
        ':vr' => $vatRate, ':va' => $vat, ':gr' => round($net + $vat, 2),
        ':tid' => $c['tariff_id'] ?? null, ':lid' => $c['tariff_line_id'] ?? null, ':tier' => $c['tier_id'] ?? null,
        ':eid' => $c['event_id'] ?? null, ':sd' => $c['stock_daily_id'] ?? null,
        ':tr' => json_encode($c['calc_trace'] ?? [], JSON_UNESCAPED_UNICODE),
        ':stt' => $c['status'] ?? 'ACTIVE', ':adj' => $c['adjustment_of_id'] ?? null, ':run' => $c['run_id'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Биллинговый прогон.
 * @param array $opts from, to, clients ([] = все), mode (DRY_RUN|FULL), user
 * @return array stats
 */
function billing_run(PDO $pdo, array $opts): array
{
    $from = substr((string)($opts['from'] ?? ''), 0, 10);
    $to = substr((string)($opts['to'] ?? ''), 0, 10);
    $clients = array_values(array_filter(array_map('intval', (array)($opts['clients'] ?? []))));
    $mode = strtoupper((string)($opts['mode'] ?? 'FULL')) === 'DRY_RUN' ? 'DRY_RUN' : 'FULL';
    if ($from === '' || $to === '' || $from > $to) {
        throw new RuntimeException('Укажите период (с и по)');
    }
    // Закрытый период — запрет.
    foreach ($clients ?: [0] as $cl) {
        if (billing_period_status($pdo, $from, $to, $cl) === 'CLOSED') {
            throw new RuntimeException('Период закрыт — изменения только через корректировки');
        }
    }
    $stats = [
        'mode' => $mode, 'from' => $from, 'to' => $to, 'events' => 0, 'charged' => 0,
        'no_tariff' => 0, 'service_disabled' => 0, 'storage_days' => 0, 'amount_net' => 0.0,
        'min_extra' => 0.0, 'problems' => [],
    ];
    $inTx = false;
    try {
        $pdo->beginTransaction();
        $inTx = true;
    } catch (Throwable) {
    }
    try {
        $runId = 0;
        if ($mode === 'FULL') {
            $st = $pdo->prepare(
                "INSERT INTO billing_run (period_from,period_to,client_scope,mode,status,started_at)
                 VALUES (:f,:t,:cs,:m,'RUNNING'," . billing_now_sql($pdo) . ')'
            );
            $st->execute([':f' => $from, ':t' => $to, ':cs' => json_encode($clients), ':m' => $mode]);
            $runId = (int)$pdo->lastInsertId();
            // Идемпотентность: прошлые активные начисления периода → SUPERSEDED.
            $w = "status='ACTIVE' AND period_from=:f AND period_to=:t";
            $p = [':f' => $from, ':t' => $to];
            if ($clients) {
                $ph = [];
                foreach ($clients as $i => $cl) {
                    $k = ':cl' . $i;
                    $ph[] = $k;
                    $p[$k] = (int)$cl;
                }
                $w .= ' AND client_id IN (' . implode(',', $ph) . ')';
            }
            $upd = $pdo->prepare("UPDATE billing_charge SET status='SUPERSEDED' WHERE $w");
            $upd->execute($p);
            $pdo->prepare("UPDATE billing_event SET status='NEW', reason=NULL
                            WHERE event_date BETWEEN :f AND :t AND status IN ('CHARGED','NO_TARIFF','SERVICE_DISABLED')")
                ->execute([':f' => $from, ':t' => $to]);
            // Расшифровка хранения за прошлый прогон заменяется новой.
            $dw = 'day BETWEEN :f AND :t';
            $dp = [':f' => $from, ':t' => $to];
            if ($clients) {
                $ph = [];
                foreach ($clients as $i => $cl) {
                    $k = ':scl' . $i;
                    $ph[] = $k;
                    $dp[$k] = (int)$cl;
                }
                $dw .= ' AND client_id IN (' . implode(',', $ph) . ')';
            }
            try {
                $pdo->prepare("DELETE FROM billing_stock_daily WHERE $dw")->execute($dp);
            } catch (Throwable) {
            }
        }
        // --- 1. Разовые события ---
        $w = "event_date BETWEEN :f AND :t AND status IN ('NEW','NO_TARIFF','SERVICE_DISABLED')";
        $p = [':f' => $from, ':t' => $to];
        if ($clients) {
            $ph = [];
            foreach ($clients as $i => $cl) {
                $k = ':cl' . $i;
                $ph[] = $k;
                $p[$k] = (int)$cl;
            }
            $w .= ' AND client_id IN (' . implode(',', $ph) . ')';
        }
        $st = $pdo->prepare("SELECT * FROM billing_event WHERE $w ORDER BY event_date, id");
        $st->execute($p);
        $events = $st->fetchAll(PDO::FETCH_ASSOC);
        $serviceByEvent = billing_services_by_event($pdo);
        $eventCache = [];
        foreach ($events as $ev) {
            $stats['events']++;
            $clientId = (int)$ev['client_id'];
            $type = (string)$ev['event_type_code'];
            $day = substr((string)$ev['event_date'], 0, 10);
            // Служебные типы (хранение/сверка) не тарифицируются как события.
            if (str_starts_with($type, 'STORAGE_') || $type === 'ADJUSTMENT') {
                continue;
            }
            $tariff = billing_tariff_for($pdo, $clientId, $day);
            if (!$tariff) {
                $stats['no_tariff']++;
                $stats['problems'][] = ['event_id' => (int)$ev['id'], 'reason' => 'нет тарифного соглашения', 'client_id' => $clientId, 'type' => $type, 'date' => $day];
                if ($mode === 'FULL') {
                    $pdo->prepare("UPDATE billing_event SET status='NO_TARIFF', reason='нет тарифного соглашения' WHERE id=:id")->execute([':id' => (int)$ev['id']]);
                }
                continue;
            }
            $svc = $serviceByEvent[$type] ?? null;
            if (!$svc) {
                $stats['no_tariff']++;
                $stats['problems'][] = ['event_id' => (int)$ev['id'], 'reason' => 'нет услуги для типа события ' . $type, 'client_id' => $clientId, 'type' => $type, 'date' => $day];
                if ($mode === 'FULL') {
                    $pdo->prepare("UPDATE billing_event SET status='NO_TARIFF', reason='нет услуги для типа события' WHERE id=:id")->execute([':id' => (int)$ev['id']]);
                }
                continue;
            }
            $line = billing_line_for($pdo, (int)$tariff['id'], (int)$svc['id'], $day);
            if (!$line || (int)$line['is_enabled'] !== 1) {
                $stats['service_disabled']++;
                $stats['problems'][] = ['event_id' => (int)$ev['id'], 'reason' => $line ? 'услуга отключена' : 'нет цены в тарифе', 'client_id' => $clientId, 'type' => $type, 'date' => $day, 'service' => $svc['code']];
                if ($mode === 'FULL') {
                    $pdo->prepare("UPDATE billing_event SET status='SERVICE_DISABLED', reason=:r WHERE id=:id")
                        ->execute([':r' => $line ? 'услуга отключена' : 'нет цены в тарифе', ':id' => (int)$ev['id']]);
                }
                continue;
            }
            $calc = billing_event_amount($pdo, $ev, $svc, $line, $tariff);
            if (isset($calc['error'])) {
                $stats['problems'][] = ['event_id' => (int)$ev['id'], 'reason' => $calc['error'], 'client_id' => $clientId, 'type' => $type, 'date' => $day, 'service' => $svc['code']];
                if ($mode === 'FULL') {
                    $pdo->prepare("UPDATE billing_event SET status='ANOMALY', reason=:r WHERE id=:id")->execute([':r' => $calc['error'], ':id' => (int)$ev['id']]);
                }
                continue;
            }
            $stats['charged']++;
            $stats['amount_net'] += $calc['amount'];
            if ($mode === 'FULL') {
                $chargeId = billing_add_charge($pdo, [
                    'client_id' => $clientId, 'service_id' => (int)$svc['id'], 'charge_date' => $day,
                    'period_from' => $from, 'period_to' => $to, 'quantity' => $calc['qty'],
                    'price' => $calc['price'], 'amount_net' => $calc['amount'], 'vat_rate' => $calc['vat'],
                    'tariff_id' => (int)$tariff['id'], 'tariff_line_id' => (int)$line['id'],
                    'tier_id' => $calc['trace']['tier_id'] ?? null, 'event_id' => (int)$ev['id'],
                    'calc_trace' => $calc['trace'] + ['service' => $svc['code'], 'event_type' => $type],
                    'status' => 'ACTIVE', 'run_id' => $runId,
                ]);
                $pdo->prepare("UPDATE billing_event SET status='CHARGED', reason=NULL WHERE id=:id")->execute([':id' => (int)$ev['id']]);
            }
        }
        // --- 2. Суточное хранение ---
        $storage = billing_storage_run($pdo, $from, $to, $clients, $mode, $runId);
        $stats['storage_days'] = $storage['days'];
        $stats['amount_net'] += $storage['amount'];
        foreach ($storage['problems'] as $pb) {
            $stats['problems'][] = $pb;
        }
        // --- 3. Минимальные/максимальные сборы по линиям ---
        $extra = billing_apply_line_minmax($pdo, $from, $to, $clients, $mode, $runId);
        $stats['min_extra'] += $extra['amount'];
        $stats['amount_net'] += $extra['amount'];
        // --- 4. Минимальная сумма счёта (ТС) ---
        $minInvoice = billing_apply_tariff_min($pdo, $from, $to, $clients, $mode, $runId);
        $stats['min_extra'] += $minInvoice;
        $stats['amount_net'] += $minInvoice;
        $stats['amount_net'] = round($stats['amount_net'], 2);
        if ($mode === 'FULL') {
            $pdo->prepare("UPDATE billing_run SET status='DONE', finished_at=" . billing_now_sql($pdo) . ', stats=:s WHERE id=:id')
                ->execute([':s' => json_encode($stats, JSON_UNESCAPED_UNICODE), ':id' => $runId]);
        }
        if ($inTx) {
            $mode === 'DRY_RUN' ? $pdo->rollBack() : $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($inTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $stats;
}

/** Карта «тип события → услуга». */
function billing_services_by_event(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query('SELECT * FROM billing_service WHERE is_active=1 AND event_type_code IS NOT NULL') as $r) {
            $code = trim((string)$r['event_type_code']);
            if ($code !== '' && !isset($out[$code])) {
                $out[$code] = $r;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Суточное хранение по формуле заказчика.
 * @return array{days:int, amount:float, problems:array}
 */
function billing_storage_run(PDO $pdo, string $from, string $to, array $clients, string $mode, int $runId): array
{
    $res = ['days' => 0, 'amount' => 0.0, 'problems' => []];
    // Услуги хранения (STORAGE_DAY) — по одной на единицу учёта.
    $storages = [];
    try {
        foreach ($pdo->query("SELECT s.*, u.code AS uom_code FROM billing_service s
                               LEFT JOIN billing_uom u ON u.id=s.uom_id
                               JOIN billing_calc_type c ON c.id=s.calc_type_id
                              WHERE c.code='STORAGE_DAY' AND s.is_active=1 ORDER BY s.id") as $r) {
            $storages[] = $r;
        }
    } catch (Throwable) {
    }
    if (!$storages) {
        return $res;
    }
    // Клиенты периода.
    $cw = '1=1';
    $cp = [];
    if ($clients) {
        $cw = 'c.id IN (' . implode(',', array_fill(0, count($clients), '?')) . ')';
    }
    $st = $pdo->prepare("SELECT c.* FROM billing_client c WHERE $cw ORDER BY c.id");
    $st->execute($clients);
    $clientRows = $st->fetchAll(PDO::FETCH_ASSOC);
    $day = $from;
    $days = [];
    while ($day <= $to) {
        $days[] = $day;
        $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }
    if (!$days) {
        return $res;
    }
    foreach ($clientRows as $cl) {
        $clientId = (int)$cl['id'];
        // Первое поступление клиента — для бесплатных суток.
        $firstIn = null;
        try {
            $q = $pdo->prepare("SELECT MIN(event_date) FROM billing_event WHERE client_id=:c AND event_type_code LIKE 'IN_%'");
            $q->execute([':c' => $clientId]);
            $v = $q->fetchColumn();
            $firstIn = $v ? substr((string)$v, 0, 10) : null;
        } catch (Throwable) {
        }
        foreach ($storages as $svc) {
            $uom = (string)($svc['uom_code'] ?? '');
            // Тариф и линия на первый день периода.
            $tariff = billing_tariff_for($pdo, $clientId, $from);
            if (!$tariff) {
                continue;
            }
            $line = billing_line_for($pdo, (int)$tariff['id'], (int)$svc['id'], $from);
            if (!$line || (int)$line['is_enabled'] !== 1) {
                continue;
            }
            $tiers = billing_tiers($pdo, (int)$line['id']);
            // Остаток на начало периода: все движения до from.
            $start = billing_movement_balance($pdo, $clientId, $uom, $from, false);
            $qty = $start;
            $freeDays = (int)($tariff['free_storage_days'] ?? 0);
            $basis = strtoupper((string)($tariff['storage_day_basis'] ?? 'END_OF_DAY'));
            foreach ($days as $d) {
                if ($d > date('Y-m-d')) {
                    break;
                }
                [$in, $out] = billing_day_movements($pdo, $clientId, $uom, $d);
                $end = $qty + $in - $out;
                if ($basis === 'BEGIN_OF_DAY') {
                    $billable = $qty;
                } elseif ($basis === 'AVERAGE') {
                    $billable = ($qty + $end) / 2;
                } else {
                    $billable = $end;
                }
                $dayNo = $firstIn ? (int)floor((strtotime($d) - strtotime($firstIn)) / 86400) + 1 : 0;
                $isFree = ($freeDays > 0 && $dayNo > 0 && $dayNo <= $freeDays) ? 1 : 0;
                if ($billable < 0) {
                    $billable = 0;
                    $res['problems'][] = ['reason' => 'отрицательный остаток', 'client_id' => $clientId, 'date' => $d, 'uom' => $uom];
                }
                $amount = 0.0;
                $price = (float)$line['price'];
                $tierId = null;
                if (!$isFree && $billable > 0) {
                    $calc = billing_tier_amount($tiers, $billable, $price);
                    $amount = (float)$calc['amount'];
                    $price = (float)$calc['price'];
                    $tierId = $calc['tier_id'];
                    $discount = (float)($line['discount_percent'] ?? 0);
                    if ($discount > 0) {
                        $amount *= (1 - $discount / 100);
                    }
                    $amount *= billing_calendar_factor($pdo, $d);
                    $amount = billing_round($amount, (string)($tariff['rounding_rule'] ?? 'ROUND'));
                }
                $res['days']++;
                $res['amount'] += $amount;
                if ($mode === 'FULL') {
                    $trace = [
                        'qty_start' => round($qty, 4), 'qty_in' => round($in, 4), 'qty_out' => round($out, 4),
                        'qty_billable' => round($billable, 4), 'basis' => $basis, 'day_no' => $dayNo,
                        'free_storage_days' => $freeDays, 'is_free_day' => $isFree,
                        'price' => $price, 'tier_id' => $tierId, 'service' => $svc['code'],
                    ];
                    $ins = $pdo->prepare(
                        "INSERT INTO billing_stock_daily (client_id,service_id,uom_code,day,qty_start,qty_in,qty_out,qty_billable,
                            is_free_day,day_no,price,amount_net,tariff_id,run_id)
                         VALUES (:c,:s,:u,:d,:qs,:qi,:qo,:qb,:fr,:dn,:p,:a,:t,:r)"
                    );
                    $ins->execute([':c' => $clientId, ':s' => (int)$svc['id'], ':u' => $uom, ':d' => $d,
                        ':qs' => round($qty, 4), ':qi' => round($in, 4), ':qo' => round($out, 4),
                        ':qb' => round($billable, 4), ':fr' => $isFree, ':dn' => $dayNo,
                        ':p' => $price, ':a' => $amount, ':t' => (int)$tariff['id'], ':r' => $runId]);
                    $sdId = (int)$pdo->lastInsertId();
                    if ($amount > 0) {
                        $chId = billing_add_charge($pdo, [
                            'client_id' => $clientId, 'service_id' => (int)$svc['id'], 'charge_date' => $d,
                            'period_from' => $from, 'period_to' => $to, 'quantity' => $billable,
                            'price' => $price, 'amount_net' => $amount, 'vat_rate' => (float)($svc['vat_rate'] ?? 20),
                            'tariff_id' => (int)$tariff['id'], 'tariff_line_id' => (int)$line['id'],
                            'tier_id' => $tierId, 'stock_daily_id' => $sdId,
                            'calc_trace' => $trace, 'status' => 'ACTIVE', 'run_id' => $runId,
                        ]);
                        $pdo->prepare('UPDATE billing_stock_daily SET charge_id=:ch WHERE id=:id')
                            ->execute([':ch' => $chId, ':id' => $sdId]);
                    }
                }
                $qty = $end;
            }
        }
    }
    return $res;
}


/** Приход и расход клиента за сутки с пересчётом в единицу хранения. */
function billing_day_movements(PDO $pdo, int $clientId, string $uom, string $day): array
{
    $in = 0.0;
    $out = 0.0;
    try {
        $q = $pdo->prepare("SELECT event_type_code, quantity, uom_code FROM billing_event
                             WHERE client_id=:c AND event_date=:d");
        $q->execute([':c' => $clientId, ':d' => $day]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $type = (string)$r['event_type_code'];
            $f = billing_factor($pdo, (string)($r['uom_code'] ?? ''), $uom);
            if (str_starts_with($type, 'IN_')) {
                $in += (float)$r['quantity'] * $f;
            } elseif (str_starts_with($type, 'OUT_')) {
                $out += (float)$r['quantity'] * $f;
            }
        }
    } catch (Throwable) {
    }
    return [$in, $out];
}
/**
 * Остаток по единице учёта: до даты (до from) или приход за дату.
 */
function billing_movement_balance(PDO $pdo, int $clientId, string $uom, string $day, bool $incomingOnly = false, bool $inclusive = true): float
{
    try {
        if ($incomingOnly) {
            $q = $pdo->prepare("SELECT event_type_code, quantity, uom_code FROM billing_event
                                 WHERE client_id=:c AND event_date=:d AND event_type_code LIKE 'IN_%'");
            $q->execute([':c' => $clientId, ':d' => $day]);
            $sum = 0.0;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sum += (float)$r['quantity'] * billing_factor($pdo, (string)($r['uom_code'] ?? ''), $uom);
            }
            return $sum;
        }
        $cmp = $inclusive ? '<' : '<';
        $q = $pdo->prepare("SELECT event_type_code, quantity, uom_code FROM billing_event
                             WHERE client_id=:c AND event_date < :d
                               AND (event_type_code LIKE 'IN_%' OR event_type_code LIKE 'OUT_%')");
        $q->execute([':c' => $clientId, ':d' => $day]);
        $sum = 0.0;
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $f = billing_factor($pdo, (string)($r['uom_code'] ?? ''), $uom);
            $sum += str_starts_with((string)$r['event_type_code'], 'OUT_') ? -(float)$r['quantity'] * $f : (float)$r['quantity'] * $f;
        }
        return $sum;
    } catch (Throwable) {
        return 0.0;
    }
}

/** Минимальные/максимальные сборы по тарифной линии за период. */
function billing_apply_line_minmax(PDO $pdo, string $from, string $to, array $clients, string $mode, int $runId): array
{
    $out = ['amount' => 0.0];
    $w = "c.status='ACTIVE' AND c.period_from=:f AND c.period_to=:t AND c.adjustment_of_id IS NULL";
    $p = [':f' => $from, ':t' => $to];
    if ($clients) {
        $ph = [];
        foreach ($clients as $i => $cl) {
            $k = ':cl' . $i;
            $ph[] = $k;
            $p[$k] = (int)$cl;
        }
        $w .= ' AND c.client_id IN (' . implode(',', $ph) . ')';
    }
    try {
        $st = $pdo->prepare(
            "SELECT c.client_id, c.service_id, c.tariff_line_id, SUM(c.amount_net) AS total
               FROM billing_charge c WHERE $w AND c.tariff_line_id IS NOT NULL
              GROUP BY c.client_id, c.service_id, c.tariff_line_id"
        );
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lineId = (int)$r['tariff_line_id'];
            $q = $pdo->prepare('SELECT min_charge, max_charge, tariff_id FROM billing_tariff_line WHERE id=:id');
            $q->execute([':id' => $lineId]);
            $line = $q->fetch(PDO::FETCH_ASSOC);
            if (!$line) {
                continue;
            }
            $total = (float)$r['total'];
            $min = $line['min_charge'] !== null && $line['min_charge'] !== '' ? (float)$line['min_charge'] : null;
            $max = $line['max_charge'] !== null && $line['max_charge'] !== '' ? (float)$line['max_charge'] : null;
            $delta = 0.0;
            $why = '';
            if ($min !== null && $total < $min) {
                $delta = $min - $total;
                $why = 'доначисление до минимума по услуге';
            } elseif ($max !== null && $total > $max) {
                $delta = $max - $total;
                $why = 'ограничение сверху по услуге';
            }
            if (abs($delta) < 0.005) {
                continue;
            }
            $out['amount'] += $delta;
            if ($mode === 'FULL') {
                billing_add_charge($pdo, [
                    'client_id' => (int)$r['client_id'], 'service_id' => (int)$r['service_id'],
                    'charge_date' => $to, 'period_from' => $from, 'period_to' => $to,
                    'quantity' => 0, 'price' => 0, 'amount_net' => $delta,
                    'vat_rate' => 20, 'tariff_id' => (int)$line['tariff_id'], 'tariff_line_id' => $lineId,
                    'calc_trace' => ['adjust' => $why, 'total_before' => $total, 'min_charge' => $min, 'max_charge' => $max],
                    'status' => 'ACTIVE', 'run_id' => $runId,
                ]);
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

/** Минимальная сумма счёта по ТС за период. */
function billing_apply_tariff_min(PDO $pdo, string $from, string $to, array $clients, string $mode, int $runId): float
{
    $extra = 0.0;
    $w = "status='ACTIVE' AND period_from=:f AND period_to=:t";
    $p = [':f' => $from, ':t' => $to];
    if ($clients) {
        $ph = [];
        foreach ($clients as $i => $cl) {
            $k = ':cl' . $i;
            $ph[] = $k;
            $p[$k] = (int)$cl;
        }
        $w .= ' AND client_id IN (' . implode(',', $ph) . ')';
    }
    try {
        $st = $pdo->prepare("SELECT client_id, SUM(amount_net) AS total FROM billing_charge WHERE $w GROUP BY client_id");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $clientId = (int)$r['client_id'];
            $tariff = billing_tariff_for($pdo, $clientId, $to);
            if (!$tariff || $tariff['min_charge_amount'] === null || $tariff['min_charge_amount'] === '') {
                continue;
            }
            $min = (float)$tariff['min_charge_amount'];
            $total = (float)$r['total'];
            if ($total >= $min - 0.005) {
                continue;
            }
            $delta = $min - $total;
            $extra += $delta;
            if ($mode === 'FULL') {
                $svc = 0;
                try {
                    $q = $pdo->prepare("SELECT s.id FROM billing_service s JOIN billing_calc_type c ON c.id=s.calc_type_id
                                         WHERE c.code='PERIOD_FIXED' ORDER BY s.id LIMIT 1");
                    $q->execute();
                    $svc = (int)($q->fetchColumn() ?: 0);
                } catch (Throwable) {
                }
                billing_add_charge($pdo, [
                    'client_id' => $clientId, 'service_id' => $svc, 'charge_date' => $to,
                    'period_from' => $from, 'period_to' => $to, 'quantity' => 0, 'price' => 0,
                    'amount_net' => $delta, 'vat_rate' => 20, 'tariff_id' => (int)$tariff['id'],
                    'calc_trace' => ['adjust' => 'доначисление до минимальной суммы счёта', 'min_charge_amount' => $min, 'total_before' => $total],
                    'status' => 'ACTIVE', 'run_id' => $runId,
                ]);
            }
        }
    } catch (Throwable) {
    }
    return $extra;
}

/** Статус периода (CLOSED/OPEN) для клиента. */
function billing_period_status(PDO $pdo, string $from, string $to, int $clientId): string
{
    try {
        $st = $pdo->prepare(
            "SELECT status FROM billing_period
              WHERE period_from=:f AND period_to=:t AND (client_id IS NULL OR client_id=:c)
              ORDER BY CASE WHEN status='CLOSED' THEN 0 ELSE 1 END LIMIT 1"
        );
        $st->execute([':f' => $from, ':t' => $to, ':c' => $clientId]);
        return (string)($st->fetchColumn() ?: 'OPEN');
    } catch (Throwable) {
        return 'OPEN';
    }
}

/** Закрытие периода с контрольными проверками. */
function billing_close_period(PDO $pdo, string $from, string $to): array
{
    $problems = [];
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM billing_event WHERE event_date BETWEEN :f AND :t
              AND status IN ('NO_TARIFF','SERVICE_DISABLED','ANOMALY')"
        );
        $st->execute([':f' => $from, ':t' => $to]);
        $bad = (int)$st->fetchColumn();
        if ($bad > 0) {
            $problems[] = 'События без тарификации: ' . $bad . ' — устраните перед закрытием';
        }
    } catch (Throwable) {
    }
    if ($problems) {
        throw new RuntimeException(implode('; ', $problems));
    }
    $now = billing_now_sql($pdo);
    try {
        $st = $pdo->prepare("SELECT id FROM billing_period WHERE period_from=:f AND period_to=:t AND client_id IS NULL LIMIT 1");
        $st->execute([':f' => $from, ':t' => $to]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE billing_period SET status='CLOSED', closed_at=$now WHERE id=:id")->execute([':id' => $id]);
        } else {
            $pdo->prepare("INSERT INTO billing_period (client_id,period_from,period_to,status,closed_at) VALUES (NULL,:f,:t,'CLOSED',$now)")
                ->execute([':f' => $from, ':t' => $to]);
        }
    } catch (Throwable $e) {
        throw new RuntimeException('Не удалось закрыть период: ' . $e->getMessage());
    }
    return ['ok' => true];
}

/** Повторное открытие периода (с причиной). */
function billing_open_period(PDO $pdo, string $from, string $to, string $reason = ''): array
{
    if (trim($reason) === '') {
        throw new RuntimeException('Укажите причину повторного открытия');
    }
    $pdo->prepare("UPDATE billing_period SET status='REOPENED', control_json=:r WHERE period_from=:f AND period_to=:t AND client_id IS NULL")
        ->execute([':r' => json_encode(['reopen_reason' => $reason], JSON_UNESCAPED_UNICODE), ':f' => $from, ':t' => $to]);
    return ['ok' => true];
}

/** Корректировка начисления (сторно/перерасчёт) — новое начисление со ссылкой. */
function billing_adjustment(PDO $pdo, int $chargeId, float $amount, string $reason, int $userId = 0): int
{
    $st = $pdo->prepare('SELECT * FROM billing_charge WHERE id=:id');
    $st->execute([':id' => $chargeId]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) {
        throw new RuntimeException('Начисление не найдено');
    }
    if (abs($amount) < 0.005) {
        throw new RuntimeException('Укажите сумму корректировки');
    }
    return billing_add_charge($pdo, [
        'client_id' => (int)$c['client_id'], 'service_id' => (int)$c['service_id'],
        'charge_date' => $c['charge_date'], 'period_from' => $c['period_from'], 'period_to' => $c['period_to'],
        'quantity' => 0, 'price' => 0, 'amount_net' => $amount, 'vat_rate' => (float)($c['vat_rate'] ?? 20),
        'tariff_id' => $c['tariff_id'], 'tariff_line_id' => $c['tariff_line_id'],
        'calc_trace' => ['adjustment' => $reason, 'source_charge_id' => $chargeId, 'user_id' => $userId],
        'status' => 'ACTIVE', 'adjustment_of_id' => $chargeId,
    ]);
}
