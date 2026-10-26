<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
// ТСД «Приход» — мастер приёмки по схеме «Приход ТСД» (drawio).
// Маппинг схемы на таблицы (ЕДИНАЯ БД поступлений 02-postupleniya/wms_receipts.sqlite, см. shared/db.php):
//   doc_priemka            -> receipts (статус «В работе» = accepting, «Закрыт» = ready_place)
//   ячейка ворот           -> receipts.gate
//   line (позиция)         -> receipt_items (+qty_fact, +line_status: ok|unclaimed|confirmed)
//   line_qty               -> receipt_operations (accept_status: Принято|Брак, +reason)
//   mark_log               -> receipt_flags (+status: годный|брак|нечитаема, +reason)
//   pallet                 -> tsd_pallets (sscc, status Заполняется|Закрыта, qty_total, weight)
//   line_crossdock         -> tsd_crossdock (gate_id, sscc, status)
//   act_rasxa              -> tsd_act_rasx (тип: недостача|излишек|не_заявлен|брак, qty_diff,
//                             +supplier_batch — акт относится к партии позиции)
//   остаток живого факта   -> receipt_operations (агрегат SUM(qty) по sku/pallet —
//                             единый источник, читают ТСД-инфо и StockSync;
//                             отдельно в Номенклатуру: product_stock (модуль 8.1).
//   (таблица-дубль tsd_stock удалена — расходилась с фактом и с 8.1).
// Порядок этапов на ТСД: 1 ворота = ячейка приёмки (DOK-01…DOK-10, зона «Приёмка»
// в таблице ячеек хранения) → 2 документы с этими воротами / ШК документа
// (ворота проставляются в receipts.gate) → 3 скан ШК товара → 4 кросс-док? →
// 5 «Приёмка»: количество вручную + тарное место (2 — недостача, 3 — излишки) →
// 6 качество → 7 подтверждение позиции и закрытие тарного места.
// Марки ЧЗ (если требуются) сканируются перед приёмкой. Количество единиц вводится
// только вручную — отдельного скана ШК единицы нет.
// Частичная приёмка (10 из 100) — НЕ недостача: принимаем частями, пока не примем всю строку.
// Принятые паллеты сразу попадают в товарный запас 8.1 (product_stock, UPDATE) —
// в ячейку приёмки документа; отмена паллеты снимает приёмку и остаток.
// Акты расхождений формируются ТОЛЬКО при закрытии документа (shared/ReceiptClose.php):
// статус «Закрыто» (всё принято по документу) либо «Закрыто с отклонением».
// Шаги API: docs[?gate=], doc_by_code, start, receiving_cells, ean, unclaimed,
// pallet_open, sscc_accept, mark, qty, fact, fix[mode=exact|shortage|excess], confirm,
// pallet_close, pallet_info, pallet_cancel, crossdock, crossdock_move, close,
// measure_save, state.

const PRIHOD_START_FROM = ['expected', 'arrived', 'unloading', 'on_stock', 'ready_accept', 'accepting'];

// Общая логика приёмки: позиции-партии, отклонения, закрытие документа и акты.
// (sku_plan/sku_fact/receipt_scopes/act_upsert/wms_close_receipt и др.)
require_once __DIR__ . '/../shared/ReceiptClose.php';

function tok_ok(): bool
{
    if (wms_tsd_token_ok()) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return isset($_SESSION['wms_auth_user']);
}

function j(mixed $d, int $c = 200): void
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): PDO
{
    // Единая БД поступлений (владелец — 02-postupleniya). См. shared/db.php.
    // При WMS_DB_DRIVER=pgsql — единая БД wms (db/schema-pg.sql).
    require_once __DIR__ . '/../shared/db.php';
    return wms_receipts_pdo();
}

function tsd_pallet_ensure(PDO $pdo, int $rid, string $sscc): void
{
    $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if ($drv === 'pgsql') {
        $pdo->prepare("INSERT INTO tsd_pallets (receipt_id,sscc,status) VALUES (:r,:s,'Заполняется') ON CONFLICT (receipt_id, sscc) DO NOTHING")->execute([':r' => $rid, ':s' => $sscc]);
    } elseif ($drv === 'mysql') {
        $pdo->prepare("INSERT IGNORE INTO tsd_pallets (receipt_id,sscc,status) VALUES (:r,:s,'Заполняется')")->execute([':r' => $rid, ':s' => $sscc]);
    } else {
        $pdo->prepare("INSERT OR IGNORE INTO tsd_pallets (receipt_id,sscc,status) VALUES (:r,:s,'Заполняется')")->execute([':r' => $rid, ':s' => $sscc]);
    }
}

function hist(PDO $pdo, int $rid, $uid, string $action, ?string $field, ?string $old, ?string $new): void
{
    // ФИО пользователя — для вкладки «История» в модуле поступлений.
    $fio = null;
    if ($uid) {
        try {
            $u = $pdo->prepare("SELECT fio FROM users WHERE id=:id");
            $u->execute([':id' => (int)$uid]);
            $v = $u->fetchColumn();
            $fio = ($v === false || $v === null) ? null : (string)$v;
        } catch (Throwable) {
        }
    }
    $pdo->prepare("INSERT INTO receipt_history (receipt_id,user_id,user_fio,action,field,old_value,new_value) VALUES (:r,:u,:f,:a,:fl,:o,:n)")
        ->execute([':r' => $rid, ':u' => $uid, ':f' => $fio, ':a' => $action, ':fl' => $field, ':o' => $old, ':n' => $new]);
}

/** Нормализовать партию из запроса: null — не указана, '' — без партии, иначе строка. */
function req_batch(array $body): ?string
{
    if (!array_key_exists('batch', $body) && !array_key_exists('supplier_batch', $body)) {
        return null;
    }
    $v = $body['batch'] ?? $body['supplier_batch'] ?? null;
    if ($v === null) {
        return null;
    }
    return trim((string)$v);
}

/** INSERT в receipt_operations с партией; на старых БД без колонки — без нее. */
function op_insert(PDO $pdo, int $rid, string $sku, ?string $batch, string $status, $uid, ?string $pallet, float $qty, ?string $reason = null, int $noSerial = 0, ?string $rejectReason = null): void
{
    $b = $batch ?? '';
    try {
        $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,supplier_batch,accept_status,accept_date,receiver_id,pallet,sku_qty,qty,reason,no_serial,reject_reason) VALUES (:r,:s,:b,:st,datetime('now'),:u,:p,1,:q,:o,:ns,:rr)")
            ->execute([':r' => $rid, ':s' => $sku, ':b' => $b, ':st' => $status, ':u' => $uid, ':p' => $pallet, ':q' => $qty, ':o' => $reason,
                ':ns' => $noSerial ? 1 : 0, ':rr' => ($rejectReason !== null && $rejectReason !== '') ? $rejectReason : null]);
        return;
    } catch (Throwable) {
    }
    try {
        $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,supplier_batch,accept_status,accept_date,receiver_id,pallet,sku_qty,qty,reason,no_serial) VALUES (:r,:s,:b,:st,datetime('now'),:u,:p,1,:q,:o,:ns)")
            ->execute([':r' => $rid, ':s' => $sku, ':b' => $b, ':st' => $status, ':u' => $uid, ':p' => $pallet, ':q' => $qty, ':o' => $reason,
                ':ns' => $noSerial ? 1 : 0]);
        return;
    } catch (Throwable) {
    }
    $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty,reason) VALUES (:r,:s,:st,datetime('now'),:u,:p,1,:q,:o)")
        ->execute([':r' => $rid, ':s' => $sku, ':st' => $status, ':u' => $uid, ':p' => $pallet, ':q' => $qty, ':o' => $reason]);
}

/**
 * Подключение к номенклатуре (карточки товаров, флаги сканирования с/н).
 * Кэшируется: подключение к БД создаётся один раз на запрос.
 */
function nom_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        require_once __DIR__ . '/../shared/db.php';
        $pdo = wms_nomenclature_pdo();
    }
    return $pdo;
}

/**
 * Эффективные флаги позиции: сканирование с/н на входе и контроль даты производства.
 * Наследование: override артикула NULL -> глобальный флаг клиента (counterparties).
 * @return array{sku:string,in:int,out:int,prod_date:int,contractor_id:?int,inherited_in:bool,inherited_out:bool}
 */
function sku_flags(PDO $pdo, string $sku): array
{
    try {
        require_once __DIR__ . '/../shared/Counterparties.php';
        return WmsCounterparties::productFlags(nom_db(), $sku);
    } catch (Throwable) {
        return ['sku' => $sku, 'in' => 0, 'out' => 0, 'prod_date' => 0,
            'contractor_id' => null, 'inherited_in' => true, 'inherited_out' => true];
    }
}

/**
 * Глобальный флаг клиента на уровне документа (поступление от клиента/поставщика).
 * Основной источник — артикул; флаг документа — дополнение.
 * @return array{in:int,out:int,contractor_id:?int,name:?string}
 */
function doc_client_flags(PDO $pdo, int $rid): array
{
    $out = ['in' => 0, 'out' => 0, 'contractor_id' => null, 'name' => null];
    try {
        $h = $pdo->prepare("SELECT contractor_id FROM receipts WHERE id=:id");
        $h->execute([':id' => $rid]);
        $cid = $h->fetchColumn();
        if ($cid === false || $cid === null || (int)$cid <= 0) {
            return $out;
        }
        $cid = (int)$cid;
        $out['contractor_id'] = $cid;
        require_once __DIR__ . '/../shared/Counterparties.php';
        $cp = WmsCounterparties::find($pdo, $cid);
        if (!$cp) {
            // Клиент заведён в старом справочнике contractors — ищем по названию.
            try {
                $c = $pdo->prepare("SELECT name FROM contractors WHERE id=:id");
                $c->execute([':id' => $cid]);
                $nm = $c->fetchColumn();
                if ($nm !== false && $nm !== null) {
                    $cp = WmsCounterparties::findByName($pdo, (string)$nm);
                    $out['name'] = (string)$nm;
                }
            } catch (Throwable) {
            }
        } else {
            $out['name'] = (string)($cp['name'] ?? '');
        }
        if ($cp) {
            $f = WmsCounterparties::serialFlags($pdo, $cid);
            $out['in'] = (int)$f['in'];
            $out['out'] = (int)$f['out'];
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Итоговое решение по позиции: нужны ли серийные номера и дата производства.
 * Основной источник — артикул (override + глобальный флаг клиента).
 * Флаг клиента на уровне документа подключается, только если артикул
 * не привязан к клиенту (тогда клиента документа — единственный источник).
 * @return array{in:int,prod_date:int,src:string,contractor:?int,doc_in:int}
 */
function pos_requirements(PDO $pdo, int $rid, string $sku): array
{
    $flags = sku_flags($pdo, $sku);
    $doc = doc_client_flags($pdo, $rid);
    $in = (int)$flags['in'];
    $src = 'article';
    if ($in === 0 && !$flags['contractor_id'] && (int)$doc['in'] === 1) {
        $in = 1;
        $src = 'document';
    }
    return ['in' => $in, 'prod_date' => (int)$flags['prod_date'], 'src' => $src,
        'contractor' => $flags['contractor_id'], 'doc_in' => (int)$doc['in'],
        'doc_name' => $doc['name']];
}

/**
 * Требуется ли по позиции сканирование Честного знака (лёгкая промышленность).
 * Источник — группа товара в строке приёмки и/или chz_group карточки номенклатуры.
 */
function pos_chz_required(PDO $pdo, int $rid, string $sku): bool
{
    $itemGroup = null;
    try {
        $g = $pdo->prepare("SELECT prod_group FROM receipt_items WHERE receipt_id=:r AND sku=:s LIMIT 1");
        $g->execute([':r' => $rid, ':s' => $sku]);
        $v = $g->fetchColumn();
        $itemGroup = ($v === false || $v === null || trim((string)$v) === '') ? null : (string)$v;
    } catch (Throwable) {
    }
    $measure = null;
    try {
        require_once __DIR__ . '/../shared/CsvUpload.php';
        $measure = csv_upload_measure(wms_nomenclature_pdo(), $sku, $pdo);
    } catch (Throwable) {
    }
    return function_exists('csv_upload_chz_required')
        && csv_upload_chz_required($itemGroup, $measure['chz_group'] ?? null);
}

/** Список серийных номеров из тела запроса (массив или строка через запятую). */
function req_serials(array $body): array
{
    $raw = $body['serials'] ?? ($body['serial_numbers'] ?? null);
    if ($raw === null) {
        return [];
    }
    if (is_string($raw)) {
        $raw = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $s) {
        $s = trim((string)$s);
        if ($s === '' || in_array($s, $out, true)) {
            continue;
        }
        $out[] = $s;
    }
    return $out;
}

/** Дата в формате YYYY-MM-DD; пустая строка — если не задана. */
function norm_date(mixed $v): string
{
    $s = trim((string)($v ?? ''));
    if ($s === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return '';
}

/**
 * Сохранить дату производства по позиции документа (receipt_items.prod_date).
 */
function save_prod_date(PDO $pdo, int $rid, string $sku, ?string $batch, string $prodDate, $uid): void
{
    if ($prodDate === '' || $sku === '') {
        return;
    }
    $sql = "UPDATE receipt_items SET prod_date=:d WHERE receipt_id=:r AND sku=:s";
    $p = [':d' => $prodDate, ':r' => $rid, ':s' => $sku];
    if ($batch !== null) {
        $sql .= " AND COALESCE(supplier_batch,'')=:b";
        $p[':b'] = (string)$batch;
    }
    try {
        $pdo->prepare($sql)->execute($p);
        hist($pdo, $rid, $uid, 'prod_date', 'Дата производства · ' . $sku, '', $prodDate);
    } catch (Throwable) {
    }
}

/**
 * Записать отсканированные серийные номера приёмки:
 *   receipt_flags (flag_type='serial': serial_no, sku, pallet, qty)
 *   + журнал серийных номеров (WmsJournal::logSerial, операция «Приход»).
 */
function write_receipt_serials(PDO $pdo, int $rid, string $sku, array $serials, ?string $pallet, $uid, ?string $userFio = null): void
{
    if (!$serials) {
        return;
    }
    $itemId = null;
    try {
        $st = $pdo->prepare("SELECT id FROM receipt_items WHERE receipt_id=:r AND sku=:s ORDER BY id LIMIT 1");
        $st->execute([':r' => $rid, ':s' => $sku]);
        $v = $st->fetchColumn();
        $itemId = ($v !== false && $v !== null) ? (int)$v : null;
    } catch (Throwable) {
    }
    $docNo = '';
    try {
        $h = $pdo->prepare("SELECT doc_no FROM receipts WHERE id=:id");
        $h->execute([':id' => $rid]);
        $v = $h->fetchColumn();
        $docNo = ($v === false || $v === null) ? '' : (string)$v;
    } catch (Throwable) {
    }
    $cell = '';
    try {
        $h = $pdo->prepare("SELECT gate FROM receipts WHERE id=:id");
        $h->execute([':id' => $rid]);
        $v = $h->fetchColumn();
        $cell = ($v === false || $v === null) ? '' : (string)$v;
    } catch (Throwable) {
    }
    if ($userFio === null || $userFio === '') {
        $userFio = '';
        try {
            if ($uid) {
                $u = $pdo->prepare("SELECT fio FROM users WHERE id=:id");
                $u->execute([':id' => (int)$uid]);
                $v = $u->fetchColumn();
                $userFio = ($v === false || $v === null) ? '' : (string)$v;
            }
        } catch (Throwable) {
        }
    }
    $ins = null;
    try {
        $ins = $pdo->prepare(
            "INSERT INTO receipt_flags (receipt_id,item_id,flag_type,serial_no,sku,pallet,qty,status,flag_date,created_at)
             VALUES (:r,:i,'serial',:s,:sku,:pal,1,'годный',datetime('now'),datetime('now'))"
        );
    } catch (Throwable) {
        $ins = null;
    }
    foreach ($serials as $sn) {
        if ($ins) {
            try {
                $ins->execute([':r' => $rid, ':i' => $itemId, ':s' => $sn, ':sku' => $sku,
                    ':pal' => ($pallet !== null && $pallet !== '') ? $pallet : null]);
            } catch (Throwable) {
            }
        }
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            WmsJournal::logSerial($pdo, [
                'serial_no' => $sn,
                'sku' => $sku,
                'op_type' => 'Приход',
                'receipt_doc_no' => $docNo,
                'pallet' => $pallet,
                'qty' => 1,
                'cell' => $cell,
                'user_id' => $uid,
                'user_fio' => $userFio,
            ]);
        } catch (Throwable) {
        }
    }
}


/**
 * Ячейки приёмки (ворота) — из ТОЙ ЖЕ таблицы ячеек хранения (storage_cells),
 * зона «Приёмка» (receiving). Приёмка принимает только эти ячейки.
 */
function receiving_cells(): array
{
    try {
        return wms_receiving_cells(wms_storage_pdo());
    } catch (Throwable) {
        return [];
    }
}

/**
 * Проверка ячейки приёмки (ворота). Возвращает канонический код ячейки
 * или null, если такой ячейки нет (пустое значение — не проверяем).
 */
function receiving_cell_code(string $code): ?string
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    $cells = receiving_cells();
    if (!$cells) {
        return $code; // справочник недоступен — принимаем как есть
    }
    foreach ($cells as $c) {
        if (strcasecmp($c, $code) === 0) {
            return $c;
        }
    }
    return null;
}

/**
 * Синхронизация паллеты в товарный запас (модуль 8.1, product_stock).
 * Остаток обновляется (UPDATE) по факту приёмки — в ячейке приёмки документа.
 */
function sync_pallet_stock(PDO $pdo, int $rid, string $sku, string $pallet, $uid): void
{
    if (trim($sku) === '') {
        return;
    }
    try {
        require_once __DIR__ . '/../shared/StockSync.php';
        wms_sync_pallet_stock($pdo, wms_nomenclature_pdo(), $rid, $sku, $pallet, $uid, 'ТСД');
    } catch (Throwable $e) {
    }
}

function refresh_head(PDO $pdo, int $rid): array
{
    $plan = (float)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=$rid")->fetchColumn();
    $fact = (float)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=$rid AND accept_status='Принято'")->fetchColumn();
    $pct = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    $diff = (int)$pdo->query("SELECT COUNT(*) FROM tsd_act_rasx WHERE receipt_id=$rid")->fetchColumn() > 0 || round($fact - $plan, 3) != 0 ? 1 : 0;
    $pdo->prepare("UPDATE receipts SET accepted_qty=:f, accepted_pct=:p, deviation_qty=:d, has_diff=:h WHERE id=:id")
        ->execute([':f' => $fact, ':p' => $pct, ':d' => round($fact - $plan, 3), ':h' => $diff, ':id' => $rid]);
    // Дата прибытия (факт): проставляется автоматически, как только принята хотя бы одна штука.
    if ($fact > 0) {
        $pdo->prepare("UPDATE receipts SET arrival_date = COALESCE(arrival_date, datetime('now')) WHERE id = :id")
            ->execute([':id' => $rid]);
    }
    return ['plan' => $plan, 'fact' => $fact, 'pct' => $pct];
}

if (!tok_ok()) {
    j(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
}
$pdo = db();
$action = $_GET['action'] ?? 'docs';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// Выбор документа поставки; ?gate= — только документы с указанными воротами (этап 2).
if ($action === 'docs') {
    $in = "'" . implode("','", PRIHOD_START_FROM) . "'";
    $gate = trim((string)($_GET['gate'] ?? ''));
    if ($gate !== '') {
        $st = $pdo->prepare(
            "SELECT r.id, r.doc_no, r.external_no, r.status, r.gate, w.name AS warehouse, sup.name AS supplier,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято') AS fact_qty
             FROM receipts r LEFT JOIN warehouses w ON w.id=r.warehouse_id LEFT JOIN contractors sup ON sup.id=r.supplier_id
             WHERE r.status IN ($in) AND r.gate = :g
               AND (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id)
                 > (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято')
             ORDER BY r.plan_delivery_date IS NULL, r.plan_delivery_date ASC, r.id DESC LIMIT 100"
        );
        $st->execute([':g' => $gate]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query(
            "SELECT r.id, r.doc_no, r.external_no, r.status, r.gate, w.name AS warehouse, sup.name AS supplier,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято') AS fact_qty
             FROM receipts r LEFT JOIN warehouses w ON w.id=r.warehouse_id LEFT JOIN contractors sup ON sup.id=r.supplier_id
             WHERE r.status IN ($in)
               AND (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id)
                 > (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято')
             ORDER BY r.plan_delivery_date IS NULL, r.plan_delivery_date ASC, r.id DESC LIMIT 100"
        )->fetchAll();
    }
    foreach ($rows as &$r) {
        $pl = (float)($r['plan_qty'] ?? 0);
        $fa = (float)($r['fact_qty'] ?? 0);
        $r['pct'] = $pl > 0 ? (int)min(100, round($fa / $pl * 100)) : 0;
    }
    j(['rows' => $rows]);
}

// Поиск документа по штрихкоду (номер документа или внешний №) среди документов в работе.
if ($action === 'doc_by_code') {
    $code = trim((string)($_GET['code'] ?? $body['code'] ?? ''));
    if ($code === '') {
        j(['error' => 'отсканируй штрихкод документа'], 422);
    }
    $in = "'" . implode("','", PRIHOD_START_FROM) . "'";
    $s = $pdo->prepare(
        "SELECT r.id, r.doc_no, r.external_no, r.status, r.gate, w.name AS warehouse, sup.name AS supplier,
                (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято') AS fact_qty
         FROM receipts r LEFT JOIN warehouses w ON w.id=r.warehouse_id LEFT JOIN contractors sup ON sup.id=r.supplier_id
         WHERE r.status IN ($in) AND (r.doc_no = :c OR r.external_no = :c)
           AND (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id)
             > (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято')
         LIMIT 1"
    );
    $s->execute([':c' => $code]);
    $doc = $s->fetch();
    if (!$doc) {
        $l = $pdo->prepare(
            "SELECT r.id, r.doc_no, r.external_no, r.status, r.gate, w.name AS warehouse, sup.name AS supplier,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято') AS fact_qty
             FROM receipts r LEFT JOIN warehouses w ON w.id=r.warehouse_id LEFT JOIN contractors sup ON sup.id=r.supplier_id
             WHERE r.status IN ($in) AND (r.doc_no LIKE :q OR r.external_no LIKE :q)
               AND (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id)
                 > (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято')
             LIMIT 1"
        );
        $l->execute([':q' => '%' . $code . '%']);
        $doc = $l->fetch();
    }
    if (!$doc) {
        j(['found' => false]);
    }
    $pl = (float)($doc['plan_qty'] ?? 0);
    $fa = (float)($doc['fact_qty'] ?? 0);
    $doc['pct'] = $pl > 0 ? (int)min(100, round($fa / $pl * 100)) : 0;
    j(['found' => true, 'doc' => $doc]);
}

// Ячейки приёмки (ворота) — для подсказки и проверки ввода на ТСД.
if ($action === 'receiving_cells') {
    $cells = receiving_cells();
    j(['cells' => $cells, 'count' => count($cells)]);
}

// Начало приёмки: ячейка ворот (она же ячейка хранения) + статус «В работе» (шаг 1 схемы)
/**
 * Гард записи по документу приёмки: операции факта (позиции, марки, паллеты,
 * количества) допустимы, пока документ в конвейере приёмки. Закрытый или
 * архивный документ принимать нельзя — иначе факт меняется ПОСЛЕ того, как
 * сформированы акты расхождений.
 * @return array{ id:int, doc_no:string, status:string }
 */
function prihod_check_open(PDO $pdo, int $rid): array
{
    if ($rid <= 0) {
        // Некорректный/отсутствующий receipt_id — это 422, его выдаст сам обработчик.
        return [];
    }
    $st = $pdo->prepare("SELECT id, doc_no, status FROM receipts WHERE id=:id");
    $st->execute([':id' => $rid]);
    $head = $st->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    if (!in_array((string)$head['status'], PRIHOD_START_FROM, true)) {
        j(['error' => 'Документ в статусе «' . $head['status'] . '» — приёмка закрыта, запись невозможна'], 409);
    }
    return $head;
}

if ($action === 'start' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $gate = trim((string)($body['gate'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $h = $pdo->prepare("SELECT id, status FROM receipts WHERE id=:id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    if (!in_array($head['status'], PRIHOD_START_FROM, true)) {
        j(['error' => 'Документ в статусе ' . $head['status'] . ' — приёмка запрещена'], 409);
    }
    // Ворота = ячейка приёмки. Проверяем по таблице ячеек хранения (зона «Приёмка»).
    $cells = receiving_cells();
    if ($gate !== '' && $cells) {
        $code = receiving_cell_code($gate);
        if ($code === null) {
            j(['error' => 'Ячейка приёмки не найдена: ' . $gate
                . '. Доступны ячейки приёмки: ' . implode(', ', $cells), 'cells' => $cells], 422);
        }
        $gate = $code;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE receipts SET gate=COALESCE(NULLIF(:g,''),gate), status='accepting', accept_date=COALESCE(accept_date,datetime('now')) WHERE id=:id")
            ->execute([':g' => $gate, ':id' => $rid]);
        hist($pdo, $rid, $uid, 'status', 'Статус', $head['status'], 'accepting');
        if ($gate !== '') {
            hist($pdo, $rid, $uid, 'gate', 'Ячейка приёмки', '', $gate);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'status' => 'accepting', 'gate' => $gate]);
}

// Сканирование штрихкода товара (EAN-13) -> карточка или «не числится»
if ($action === 'ean') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $ean = trim((string)($_GET['ean'] ?? $body['ean'] ?? ''));
    if ($rid <= 0 || $ean === '') {
        j(['error' => 'нужны receipt_id и ean'], 422);
    }
    try {
        $s = $pdo->prepare("SELECT id, line_no, code, sku, name, unit, qty, qty_fact, line_status, supplier_batch FROM receipt_items WHERE receipt_id=:r AND (code=:e OR sku=:e) LIMIT 1");
        $s->execute([':r' => $rid, ':e' => $ean]);
        $item = $s->fetch();
    } catch (Throwable) {
        $s = $pdo->prepare("SELECT id, line_no, code, sku, name, unit, qty, qty_fact, line_status FROM receipt_items WHERE receipt_id=:r AND (code=:e OR sku=:e) LIMIT 1");
        $s->execute([':r' => $rid, ':e' => $ean]);
        $item = $s->fetch();
    }
    if (!$item) {
        try {
            $l = $pdo->prepare("SELECT id, line_no, code, sku, name, unit, qty, qty_fact, line_status, supplier_batch FROM receipt_items WHERE receipt_id=:r AND (code LIKE :q OR sku LIKE :q) LIMIT 1");
            $l->execute([':r' => $rid, ':q' => '%' . $ean . '%']);
            $item = $l->fetch();
        } catch (Throwable) {
            $l = $pdo->prepare("SELECT id, line_no, code, sku, name, unit, qty, qty_fact, line_status FROM receipt_items WHERE receipt_id=:r AND (code LIKE :q OR sku LIKE :q) LIMIT 1");
            $l->execute([':r' => $rid, ':q' => '%' . $ean . '%']);
            $item = $l->fetch();
        }
    }
    if (!$item) {
        j(['found' => false]);
    }
    $item['fact'] = sku_fact($pdo, $rid, $item['sku']);
    $batches = sku_batches($pdo, $rid, $item['sku']);
    // Обмеры из номенклатуры: новый товар (need_measure) — требовать ввод на ТСД,
    // существующий — показать замеры для подтверждения.
    // Группа «Легкая промышленность» (из позиции или номенклатуры) — требовать сканирование ЧЗ.
    $measure = null;
    $itemGroup = null;
    try {
        require_once __DIR__ . '/../shared/CsvUpload.php';
        $measure = csv_upload_measure(wms_nomenclature_pdo(), (string)$item['sku'], $pdo);
        try {
            $g = $pdo->prepare("SELECT prod_group FROM receipt_items WHERE id = :id");
            $g->execute([':id' => $item['id']]);
            $v = $g->fetchColumn();
            $itemGroup = ($v === false || $v === null || trim((string)$v) === '') ? null : (string)$v;
        } catch (Throwable) {
        }
    } catch (Throwable) {
    }
    $chzRequired = false;
    if (function_exists('csv_upload_chz_required')) {
        $chzRequired = csv_upload_chz_required($itemGroup, $measure['chz_group'] ?? null);
    }
    // Эффективные флаги позиции: сканирование с/н на входе + контроль даты производства.
    $req = pos_requirements($pdo, $rid, (string)$item['sku']);
    j(['found' => true, 'item' => $item, 'batches' => $batches, 'measure' => $measure, 'chz_required' => $chzRequired,
        'flags' => [
            'serial_in' => (int)$req['in'],
            'serial_src' => (string)$req['src'],
            'prod_date' => (int)$req['prod_date'],
            'doc_serial_in' => (int)$req['doc_in'],
            'client' => $req['doc_name'],
        ]]);
}

// --- Состояние позиции на форме ТСД: с/н, признак «без с/н», причина, дата производства ---
if ($action === 'item_state') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $sku = trim((string)($_GET['sku'] ?? ''));
    $batch = array_key_exists('batch', $_GET) ? trim((string)($_GET['batch'] ?? '')) : null;
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $flags = sku_flags($pdo, $sku);
    $req = pos_requirements($pdo, $rid, $sku);
    // Уже отсканированные по документу серийные номера позиции.
    $serials = [];
    try {
        $st = $pdo->prepare(
            "SELECT serial_no FROM receipt_flags
              WHERE receipt_id=:r AND sku=:s AND flag_type='serial' AND serial_no IS NOT NULL ORDER BY id"
        );
        $st->execute([':r' => $rid, ':s' => $sku]);
        $serials = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
    }
    // Приёмка без с/н и причина отбраковки по строке.
    $noSerial = 0;
    $rejectReason = '';
    try {
        $st = $pdo->prepare(
            "SELECT no_serial, reject_reason FROM receipt_operations
              WHERE receipt_id=:r AND sku=:s ORDER BY id DESC"
        );
        $st->execute([':r' => $rid, ':s' => $sku]);
        foreach ($st->fetchAll() as $row) {
            if ((int)($row['no_serial'] ?? 0) === 1) {
                $noSerial = 1;
            }
            if ($rejectReason === '' && trim((string)($row['reject_reason'] ?? '')) !== '') {
                $rejectReason = trim((string)$row['reject_reason']);
            }
        }
    } catch (Throwable) {
    }
    // Дата производства позиции.
    $prodDate = '';
    try {
        $sql = "SELECT prod_date FROM receipt_items WHERE receipt_id=:r AND sku=:s";
        $p = [':r' => $rid, ':s' => $sku];
        if ($batch !== null) {
            $sql .= " AND COALESCE(supplier_batch,'')=:b";
            $p[':b'] = (string)$batch;
        }
        $st = $pdo->prepare($sql . " ORDER BY id LIMIT 1");
        $st->execute($p);
        $v = $st->fetchColumn();
        $prodDate = ($v === false || $v === null) ? '' : substr((string)$v, 0, 10);
    } catch (Throwable) {
    }
    $docFlags = doc_client_flags($pdo, $rid);
    j(['sku' => $sku, 'serial_in' => (int)$req['in'], 'prod_date_required' => (int)$req['prod_date'],
        'doc_serial_in' => (int)$req['doc_in'], 'serials' => $serials, 'scanned' => count($serials),
        'no_serial' => $noSerial, 'reject_reason' => $rejectReason, 'prod_date' => $prodDate]);
}

// --- Проверка серийного номера перед добавлением в позицию (дубли) ---
if ($action === 'serial_check') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $sku = trim((string)($_GET['sku'] ?? ''));
    $serial = trim((string)($_GET['serial'] ?? ''));
    if ($rid <= 0 || $sku === '' || $serial === '') {
        j(['error' => 'нужны receipt_id, sku и serial'], 422);
    }
    $d = $pdo->prepare(
        "SELECT id FROM receipt_flags
          WHERE receipt_id=:r AND flag_type='serial' AND serial_no=:s LIMIT 1"
    );
    $d->execute([':r' => $rid, ':s' => $serial]);
    if ($d->fetch()) {
        j(['error' => 'Серийный номер уже отсканирован: ' . $serial], 409);
    }
    j(['ok' => true, 'serial' => $serial]);
}

// --- Строки документа с признаками: с/н, приёмка без с/н, причина отбраковки ---
if ($action === 'lines') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    if ($rid <= 0) {
        j(['error' => 'нужен receipt_id'], 422);
    }
    $st = $pdo->prepare(
        "SELECT i.id, i.line_no, i.sku, i.name, i.unit, i.qty AS plan_qty, i.prod_date,
                COALESCE(i.supplier_batch,'') AS batch, i.line_status
           FROM receipt_items i WHERE i.receipt_id=:r ORDER BY i.line_no, i.id"
    );
    $st->execute([':r' => $rid]);
    $rows = $st->fetchAll();
    $skus = [];
    foreach ($rows as $r) {
        $skus[(string)$r['sku']] = true;
    }
    $flagsMap = [];
    try {
        require_once __DIR__ . '/../shared/Counterparties.php';
        $flagsMap = WmsCounterparties::productFlagsBatch(nom_db(), array_keys($skus));
    } catch (Throwable) {
    }
    $docFlags = doc_client_flags($pdo, $rid);
    foreach ($rows as &$r) {
        $sku = (string)$r['sku'];
        $f = $flagsMap[$sku] ?? ['in' => 0, 'prod_date' => 0, 'contractor_id' => null];
        $needSer = (int)($f['in'] ?? 0);
        if ($needSer === 0 && !($f['contractor_id'] ?? null) && (int)$docFlags['in'] === 1) {
            $needSer = 1;
        }
        $r['fact_qty'] = 0.0;
        $r['serials'] = [];
        $r['no_serial'] = 0;
        $r['reject_reason'] = '';
        try {
            $r['fact_qty'] = sku_fact($pdo, $rid, $sku, (string)$r['batch'] !== '' ? (string)$r['batch'] : null);
        } catch (Throwable) {
        }
        try {
            $q = $pdo->prepare(
                "SELECT serial_no FROM receipt_flags
                  WHERE receipt_id=:r AND sku=:s AND flag_type='serial' AND serial_no IS NOT NULL ORDER BY id"
            );
            $q->execute([':r' => $rid, ':s' => $sku]);
            $r['serials'] = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
        }
        try {
            $q = $pdo->prepare(
                "SELECT no_serial, reject_reason FROM receipt_operations
                  WHERE receipt_id=:r AND sku=:s ORDER BY id DESC"
            );
            $q->execute([':r' => $rid, ':s' => $sku]);
            foreach ($q->fetchAll() as $op) {
                if ((int)($op['no_serial'] ?? 0) === 1) {
                    $r['no_serial'] = 1;
                }
                if ($r['reject_reason'] === '' && trim((string)($op['reject_reason'] ?? '')) !== '') {
                    $r['reject_reason'] = trim((string)$op['reject_reason']);
                }
            }
        } catch (Throwable) {
        }
        $r['serial_in'] = $needSer;
        $r['prod_date_required'] = (int)($f['prod_date'] ?? 0);
        $r['scanned'] = count($r['serials']);
    }
    unset($r);
    j(['rows' => $rows]);
}

// --- Сохранение реквизитов позиции: дата производства и причина отбраковки ---
if ($action === 'item_meta' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    prihod_check_open($pdo, $rid);
    $sku = trim((string)($body['sku'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $batch = req_batch($body);
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $out = ['ok' => true];
    if (array_key_exists('prod_date', $body)) {
        $pd = norm_date($body['prod_date']);
        save_prod_date($pdo, $rid, $sku, $batch, $pd, $uid);
        $out['prod_date'] = $pd;
        // Дата производства — и в остаток по паллетам позиции.
        try {
            $q = $pdo->prepare(
                "SELECT DISTINCT pallet FROM receipt_operations
                  WHERE receipt_id=:r AND sku=:s AND accept_status='Принято' AND pallet IS NOT NULL AND pallet<>''"
            );
            $q->execute([':r' => $rid, ':s' => $sku]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $pal) {
                sync_pallet_stock($pdo, $rid, $sku, (string)$pal, $uid);
            }
        } catch (Throwable) {
        }
    }
    if (array_key_exists('reject_reason', $body)) {
        $rr = trim((string)($body['reject_reason'] ?? ''));
        try {
            $pdo->prepare(
                "UPDATE receipt_operations SET reject_reason=:r WHERE receipt_id=:rid AND sku=:s"
            )->execute([':r' => ($rr !== '' ? $rr : null), ':rid' => $rid, ':s' => $sku]);
        } catch (Throwable) {
        }
        hist($pdo, $rid, $uid, 'reject_reason', 'Причина отбраковки · ' . $sku, '', $rr);
        $out['reject_reason'] = $rr;
    }
    j($out);
}

// «Не заявлен»: оформить строку (шаг 3) + статус строки
if ($action === 'unclaimed' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $ean = trim((string)($body['ean'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $ean === '') {
        j(['error' => 'нужны receipt_id и ean'], 422);
    }
    $mx = $pdo->prepare("SELECT COALESCE(MAX(line_no),0)+1 FROM receipt_items WHERE receipt_id=:r");
    $mx->execute([':r' => $rid]);
    $ln = (int)$mx->fetchColumn();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO receipt_items (receipt_id,line_no,code,sku,name,unit,qty,line_status) VALUES (:r,:ln,:e,:e,:n,'шт',0,'unclaimed')")
            ->execute([':r' => $rid, ':ln' => $ln, ':e' => $ean, ':n' => 'Не заявлен: ' . $ean]);
        $iid = (int)$pdo->lastInsertId();
        hist($pdo, $rid, $uid, 'unclaimed', 'Строка', null, $ean);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'item_id' => $iid, 'sku' => $ean]);
}

// Тарное место: открыть паллету (шаг 4-5: INSERT pallet ... status Заполняется)
if ($action === 'pallet_open' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sscc = trim((string)($body['sscc'] ?? ''));
    if ($rid <= 0 || $sscc === '') {
        j(['error' => 'нужны receipt_id и sscc'], 422);
    }
    try {
        $pdo->prepare("INSERT INTO tsd_pallets (receipt_id,sscc,status) VALUES (:r,:s,'Заполняется')")->execute([':r' => $rid, ':s' => $sscc]);
    } catch (Throwable $e) {
    }
    $p = $pdo->prepare("SELECT * FROM tsd_pallets WHERE receipt_id=:r AND sscc=:s");
    $p->execute([':r' => $rid, ':s' => $sscc]);
    j(['ok' => true, 'pallet' => $p->fetch()]);
}

// Приём всей вложенности по SSCC без вскрытия (шаг 5): items=[{sku,qty,batch?}]
if ($action === 'sscc_accept' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sscc = trim((string)($body['sscc'] ?? ''));
    $items = (array)($body['items'] ?? []);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sscc === '' || !$items) {
        j(['error' => 'нужны receipt_id, sscc и items [{sku,qty}]'], 422);
    }
    // Проверка паллеты: если такой SSCC уже есть в товарном запасе — ошибка.
    try {
        require_once __DIR__ . '/../shared/db.php';
        $npdo = wms_nomenclature_pdo();
        $chk = $npdo->prepare("SELECT COUNT(*) FROM product_stock WHERE TRIM(pallet) = :p");
        $chk->execute([':p' => $sscc]);
        $pc = (int)$chk->fetchColumn();
        if ($pc > 0) {
            j(['error' => 'Паллета не пустая, используйте другую', 'pallet_busy' => true, 'pallet' => $sscc], 409);
        }
    } catch (Throwable) {
    }
    // Проверка превышения по документу (как при ручном вводе количества):
    // лишнее — принимаем заявленную часть, а излишек уходит в отдельную ветку.
    $excess = null;
    foreach ($items as $it) {
        $q = (float)($it['qty'] ?? 0);
        $sku = (string)($it['sku'] ?? '');
        if ($q <= 0 || $sku === '') {
            continue;
        }
        $b = isset($it['batch']) || isset($it['supplier_batch']) ? trim((string)($it['batch'] ?? $it['supplier_batch'] ?? '')) : '';
        $sb = scope_bounds($pdo, $rid, $sku, $b);
        if (isset($sb['need_batch'])) {
            continue;
        }
        $plan = (float)$sb['plan'];
        $fact = (float)$sb['fact'];
        $rest = round($plan - $fact, 3);
        if ($plan > 0 && $rest >= 0 && round($q - $rest, 3) > 0) {
            $excess = [
                'error' => 'Введено неправильное количество: по документу ожидалось '
                    . round(max(0, $rest), 3) . ' шт, введено ' . $q . ' шт'
                    . ($sb['batch'] !== '' ? ' (партия ' . $sb['batch'] . ')' : ''),
                'need_excess' => true, 'excess' => true, 'sku' => $sku,
                'plan' => $plan, 'fact' => $fact, 'remaining' => round(max(0, $rest), 3),
                'entered' => $q, 'total' => round($fact + $q, 3), 'batch' => $sb['batch'],
                'batch_mode' => !empty($sb['batch_mode']),
            ];
            break;
        }
    }
    $allowExcess = (int)($body['allow_excess'] ?? 0) === 1;
    if ($excess && !$allowExcess) {
        // Не принимаем частично: сначала оператор подтверждает излишек.
        j($excess, 409);
    }
    $pdo->beginTransaction();
    try {
        tsd_pallet_ensure($pdo, $rid, $sscc);
        foreach ($items as $it) {
            $q = (float)($it['qty'] ?? 0);
            if ($q <= 0 || empty($it['sku'])) {
                continue;
            }
            $b = isset($it['batch']) || isset($it['supplier_batch']) ? trim((string)($it['batch'] ?? $it['supplier_batch'] ?? '')) : '';
            $sku = (string)$it['sku'];
            // Излишек подтверждён оператором — принимаем введённое количество.
            if ($q > 0) {
                op_insert($pdo, $rid, $sku, $b, 'Принято', $uid, $sscc, $q);
            }
        }
        hist($pdo, $rid, $uid, 'sscc', 'SSCC приём', null, $sscc);
        if ($excess) {
            $exSku = (string)($excess['sku'] ?? '');
            $exBatch = (string)($excess['batch'] ?? '');
            $exMode = !empty($excess['batch_mode']);
            set_deviation($pdo, $rid, $exSku, $exBatch, $exMode, 'excess');
            hist($pdo, $rid, $uid, 'excess', 'Отклонение · ' . $exSku,
                (string)($excess['plan'] ?? ''), (string)($excess['total'] ?? '') . ' (излишек)');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    if ($excess) {
        try {
            $add = round(max(0, (float)($excess['entered'] ?? 0) - (float)($excess['remaining'] ?? 0)), 3);
            $pdo->prepare("UPDATE receipts SET excess_qty = COALESCE(excess_qty,0) + :ex, has_diff=1 WHERE id=:id")
                ->execute([':ex' => $add, ':id' => $rid]);
        } catch (Throwable) {
        }
    }
    // Товарный запас 8.1: принятые паллеты в ячейке приёмки (UPDATE остатка).
    foreach ($items as $it) {
        if (empty($it['sku'])) {
            continue;
        }
        sync_pallet_stock($pdo, $rid, (string)$it['sku'], $sscc, $uid);
    }
    j(['ok' => true, 'progress' => refresh_head($pdo, $rid),
        'excess' => (bool)$excess, 'plan' => $excess['plan'] ?? null,
        'fact' => $excess['total'] ?? null]);
}

// Марка DataMatrix (шаги 6-9): quality=good|unreadable|defect + reason + batch (партия поставщика)
if ($action === 'mark' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sku = trim((string)($body['sku'] ?? ''));
    $mark = trim((string)($body['mark'] ?? ''));
    $quality = (string)($body['quality'] ?? 'good');
    $reason = trim((string)($body['reason'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $batch = req_batch($body);
    if ($rid <= 0 || $sku === '' || $mark === '') {
        j(['error' => 'нужны receipt_id, sku и mark'], 422);
    }
    if (!in_array($quality, ['good', 'unreadable', 'defect'], true)) {
        j(['error' => 'quality: good|unreadable|defect'], 422);
    }
    // Партия: если по позиции их несколько — требуем выбор
    $bl = sku_batches($pdo, $rid, $sku);
    $known = array_column($bl, 'batch');
    if (count($known) > 1 && ($batch === null || !in_array($batch, $known, true))) {
        j(['error' => 'Выбери партию поставщика', 'need_batch' => true, 'batches' => $bl], 422);
    }
    if ($batch === null) {
        $batch = count($known) === 1 ? (string)$known[0] : '';
    }
    $d = $pdo->prepare("SELECT id FROM receipt_flags WHERE receipt_id=:r AND marking_code=:m LIMIT 1");
    $d->execute([':r' => $rid, ':m' => $mark]);
    if ($d->fetch()) {
        j(['error' => 'Марка уже отсканирована'], 409);
    }
    // Сверка с системой: если на документ загружен файл марок (реестр не пуст),
    // марка обязана быть в реестре и относиться к этому артикулу.
    $regCnt = 0;
    try {
        $rc = $pdo->prepare("SELECT COUNT(*) FROM receipt_marks WHERE receipt_id=:r");
        $rc->execute([':r' => $rid]);
        $regCnt = (int)$rc->fetchColumn();
    } catch (Throwable) {
        $regCnt = 0;
    }
    if ($regCnt > 0) {
        $rg = $pdo->prepare("SELECT sku FROM receipt_marks WHERE receipt_id=:r AND marking_code=:m LIMIT 1");
        $rg->execute([':r' => $rid, ':m' => $mark]);
        $regSku = $rg->fetchColumn();
        if ($regSku === false || $regSku === null) {
            j(['error' => 'Марки нет в системе: код не найден в файле марок документа'], 404);
        }
        if ($regSku !== $sku) {
            j(['error' => 'Марка не подходит: относится к товару ' . $regSku], 422);
        }
    }
    // Лимит: марок не больше, чем штук по плану (каждая марка = одна единица товара,
    // включая нечитаемые и брак). Не заявленный товар (план 0) — без лимита.
    $planQty = sku_plan($pdo, $rid, $sku);
    if ($planQty > 0) {
        $mc = $pdo->prepare("SELECT COUNT(*) FROM receipt_flags WHERE receipt_id=:r AND sku=:s AND flag_type='marking'");
        $mc->execute([':r' => $rid, ':s' => $sku]);
        $markCnt = (int)$mc->fetchColumn();
        if ($markCnt >= $planQty) {
            j(['error' => 'Лимит марок исчерпан: отсканировано ' . $markCnt . ' из ' . rtrim(rtrim((string)$planQty, '0'), '.') . ' шт по документу'], 409);
        }
    }
    $iid = $pdo->prepare("SELECT id FROM receipt_items WHERE receipt_id=:r AND sku=:s LIMIT 1");
    $iid->execute([':r' => $rid, ':s' => $sku]);
    $itemId = ($iid->fetchColumn() ?: null);
    $pdo->beginTransaction();
    try {
        if ($regCnt > 0) {
            $pdo->prepare("UPDATE receipt_marks SET status='accepted', accepted_at=datetime('now') WHERE receipt_id=:r AND marking_code=:m")
                ->execute([':r' => $rid, ':m' => $mark]);
        }
        if ($quality === 'good') {
            // Шаг 9: марка в буфер приёмки
            $pdo->prepare("INSERT INTO receipt_flags (receipt_id,item_id,flag_type,marking_code,sku,status,flag_date) VALUES (:r,:i,'marking',:m,:s,'годный',datetime('now'))")
                ->execute([':r' => $rid, ':i' => $itemId, ':m' => $mark, ':s' => $sku]);
        } elseif ($quality === 'unreadable') {
            // Нечитаемая: отделить от брака, отложить
            $pdo->prepare("INSERT INTO receipt_flags (receipt_id,item_id,flag_type,marking_code,sku,status,reason,flag_date) VALUES (:r,:i,'marking',:m,:s,'нечитаема','нечитаемая марка',datetime('now'))")
                ->execute([':r' => $rid, ':i' => $itemId, ':m' => $mark, ':s' => $sku]);
            op_insert($pdo, $rid, $sku, $batch, 'Принято', $uid, null, 1, 'нечитаемая марка (отложена)');
        } else {
            // Брак маркированного (шаг 7)
            if ($reason === '') {
                throw new RuntimeException('Укажи причину брака');
            }
            $pdo->prepare("INSERT INTO receipt_flags (receipt_id,item_id,flag_type,marking_code,sku,status,reason,flag_date) VALUES (:r,:i,'marking',:m,:s,'брак',:o,datetime('now'))")
                ->execute([':r' => $rid, ':i' => $itemId, ':m' => $mark, ':s' => $sku, ':o' => $reason]);
            op_insert($pdo, $rid, $sku, $batch, 'Брак', $uid, null, 1, $reason);
            // Акт о браке формируется только при закрытии документа (shared/ReceiptClose.php).
        }
        hist($pdo, $rid, $uid, 'mark', 'Марка ' . $quality . ($batch !== '' ? ' · партия ' . $batch : ''), $mark, $sku);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], $e instanceof RuntimeException ? 422 : 500);
    }
    j(['ok' => true, 'progress' => refresh_head($pdo, $rid)]);
}

// Ручной ввод количества немаркированного (шаг 8) + качество товара + партия.
// Если количество больше, чем осталось по документу (по выбранной партии) —
// НЕ записываем сразу: отвечаем 409 с excess=true, ТСД показывает уведомление
// «принято X / в документе Y» и уходит в ветку фиксации излишков.
//
// Серийные номера (новое требование): если по позиции включено сканирование
// с/н на входе — после количества оператор ЦИКЛИЧНО сканирует с/н, затем паллету.
// Тело запроса может нести:
//   serials[]      — отсканированные серийные номера (должно быть = qty);
//   no_serial=1    — приёмка без с/н (оператор ввёл «000»); тогда обязательна
//                    reject_reason — причина отбраковки по строке;
//   reject_reason  — комментарий по строке (причина отбраковки);
//   prod_date      — дата производства (обязательна при control_prod_date=1).
if ($action === 'qty' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sku = trim((string)($body['sku'] ?? ''));
    $qty = (float)($body['qty'] ?? 0);
    $quality = (string)($body['quality'] ?? 'good');
    $reason = trim((string)($body['reason'] ?? ''));
    $pallet = trim((string)($body['pallet'] ?? '')) ?: null;
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $batch = req_batch($body);
    $serials = req_serials($body);
    $noSerial = (int)($body['no_serial'] ?? 0) ? 1 : 0;
    $allowExcess = (int)($body['allow_excess'] ?? 0) === 1;
    $rejectReason = trim((string)($body['reject_reason'] ?? ''));
    $prodDate = norm_date($body['prod_date'] ?? '');
    if ($rid <= 0 || $sku === '' || $qty <= 0) {
        j(['error' => 'нужны receipt_id, sku и qty>0'], 422);
    }
    // Проверка паллеты: если такой SSCC уже есть в товарном запасе (любом приходе),
    // не разрешаем принимать на ту же паллету — операция без с/н недопустима.
    if ($pallet !== null && $pallet !== '') {
        try {
            require_once __DIR__ . '/../shared/db.php';
            $npdo = wms_nomenclature_pdo();
            $chk = $npdo->prepare("SELECT COUNT(*) FROM product_stock WHERE TRIM(pallet) = :p");
            $chk->execute([':p' => $pallet]);
            $pc = (int)$chk->fetchColumn();
            if ($pc > 0) {
                j(['error' => 'Паллета не пустая, используйте другую', 'pallet_busy' => true, 'pallet' => $pallet], 409);
            }
        } catch (Throwable) {
        }
    }
    // Эффективные флаги позиции (override артикула + глобальный флаг клиента).
    $req = pos_requirements($pdo, $rid, $sku);
    $needSerial = ((int)$req['in'] === 1);
    $isDefect = ($quality === 'defect');
    // Дата производства: у позиции с контролем — обязательна.
    if ((int)$req['prod_date'] === 1 && $prodDate === '' && !$isDefect) {
        j(['error' => 'Укажите дату производства', 'need_prod_date' => true, 'sku' => $sku], 422);
    }
    // Серийные номера: сверка количества, дублей и режима «приёмка без с/н».
    if ($noSerial) {
        if ($serials) {
            j(['error' => 'Приёмка без с/н: серийные номера указаны. Оставь только режим «000» или убери с/н'], 422);
        }
        if ($rejectReason === '') {
            j(['error' => 'Укажите причину отбраковки (приёмка без с/н)', 'need_reject_reason' => true, 'sku' => $sku], 422);
        }
    } elseif ($serials) {
        $need = (int)ceil($qty);
        if (count($serials) !== $need) {
            j(['error' => 'Отсканировано ' . count($serials) . ' из ' . $need . ' серийных номеров',
                'scanned' => count($serials), 'need' => $need], 422);
        }
        // Дубль серийника в документе — повторное сканирование не допускаем.
        foreach ($serials as $sn) {
            $d = $pdo->prepare(
                "SELECT id FROM receipt_flags
                  WHERE receipt_id=:r AND flag_type='serial' AND serial_no=:s LIMIT 1"
            );
            $d->execute([':r' => $rid, ':s' => $sn]);
            if ($d->fetch()) {
                j(['error' => 'Серийный номер уже отсканирован: ' . $sn], 409);
            }
        }
    } elseif ($needSerial && !$isDefect) {
        // По позиции включено сканирование с/н — без них приёмка невозможна.
        j(['error' => 'Отсканируй серийные номера по позиции или выбери «Приёмка без с/н»',
            'need_serials' => true, 'qty' => $qty, 'sku' => $sku], 422);
    }
    $bl = sku_batches($pdo, $rid, $sku);
    $known = array_column($bl, 'batch');
    if (count($known) > 1 && ($batch === null || !in_array($batch, $known, true))) {
        j(['error' => 'Выбери партию поставщика', 'need_batch' => true, 'batches' => $bl], 422);
    }
    if ($batch === null) {
        $batch = count($known) === 1 ? (string)$known[0] : '';
    }
    if ($isDefect) {
        // Шаг 6: брак немаркированного (бой / мятая упаковка)
        if ($reason === '') {
            j(['error' => 'Укажи причину брака: бой / мятая упаковка'], 422);
        }
        $pdo->beginTransaction();
        try {
            op_insert($pdo, $rid, $sku, $batch, 'Брак', $uid, $pallet, $qty, $reason);
            // Акт о браке формируется только при закрытии документа (shared/ReceiptClose.php).
            hist($pdo, $rid, $uid, 'defect', 'Брак' . ($batch !== '' ? ' · партия ' . $batch : ''), $sku, $reason . ' x' . $qty);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            j(['error' => $e->getMessage()], 500);
        }
        j(['ok' => true, 'progress' => refresh_head($pdo, $rid)]);
    }
    // Проверка превышения по документу (по выбранной партии).
    $sb = scope_bounds($pdo, $rid, $sku, $batch === '' ? '' : (string)$batch);
    if (isset($sb['need_batch'])) {
        j(['error' => 'Выбери партию поставщика', 'need_batch' => true, 'batches' => $sb['batches']], 422);
    }
    $plan = (float)$sb['plan'];
    $fact = (float)$sb['fact'];
    $rest = round($plan - $fact, 3);
    $over = $plan > 0 && $rest >= 0 && round($qty - $rest, 3) > 0;
    if ($over && !$allowExcess) {
        // Количество больше заявленного: НЕ принимаем частично, а возвращаем
        // оператору предупреждение. Подтверждение — отдельным действием
        // «Принять с излишком» (allow_excess=1).
        $total = round($fact + $qty, 3);
        j([
            'error' => 'Введено неправильное количество: по документу ожидалось '
                . round(max(0, $rest), 3) . ' шт, введено ' . $qty . ' шт'
                . ($sb['batch'] !== '' ? ' (партия ' . $sb['batch'] . ')' : ''),
            'need_excess' => true, 'excess' => true,
            'plan' => $plan, 'fact' => $fact, 'remaining' => round(max(0, $rest), 3),
            'entered' => $qty, 'total' => $total, 'batch' => $sb['batch'],
        ], 409);
    }
    $batchMode = !empty($sb['batch_mode']);
    $batchNorm = (string)($sb['batch'] ?? $batch);
    $newFact = round($fact + $qty, 3);
    $pdo->beginTransaction();
    try {
        // Приёмка без с/н: в позицию пишем признак no_serial и причину отбраковки.
        op_insert($pdo, $rid, $sku, $batch, 'Принято', $uid, $pallet, $qty, null, $noSerial,
            $rejectReason !== '' ? $rejectReason : null);
        // Серийные номера: receipt_flags + журнал серийных номеров («Приход»).
        write_receipt_serials($pdo, $rid, $sku, $serials, $pallet, $uid);
        // Дата производства партии — в позицию документа.
        if ($prodDate !== '') {
            save_prod_date($pdo, $rid, $sku, $batch, $prodDate, $uid);
        }
        // Излишек подтверждён оператором — фиксируем отклонение в строке.
        set_deviation($pdo, $rid, $sku, $batchNorm, $batchMode, $over ? 'excess' : '');
        // Факт в позиции документа (колонка «Отклонение» в модуле поступлений).
        apply_qty_fact($pdo, $rid, $sku, $batchNorm, $batchMode, $plan, $newFact);
        hist($pdo, $rid, $uid, 'qty', 'Кол-во' . ($batch !== '' ? ' · партия ' . $batch : ''), $sku, 'x' . $qty
            . ($serials ? (' · с/н ' . count($serials)) : '') . ($noSerial ? ' · без с/н' : ''));
        if ($over) {
            hist($pdo, $rid, $uid, 'excess', 'Отклонение · ' . $sku . ($batch !== '' ? ' · партия ' . $batch : ''),
                (string)$plan, (string)$newFact . ' (излишек +' . round($qty - max(0, $rest), 3) . ')');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    // Товарный запас 8.1: паллета встаёт в ячейку приёмки (UPDATE остатка).
    // Журнал склада пишется внутри wms_sync_pallet_stock («приход»).
    sync_pallet_stock($pdo, $rid, $sku, (string)$pallet, $uid);
    if ($over) {
        // Столбец «Отклонение» в реестре поступлений виден сразу после
        // подтверждённого излишка (при закрытии документ пересчитает сумму).
        try {
            $add = round(max(0, $qty - max(0, $rest)), 3);
            $pdo->prepare(
                "UPDATE receipts SET excess_qty = COALESCE(excess_qty,0) + :ex, has_diff = 1 WHERE id = :id"
            )->execute([':ex' => $add, ':id' => $rid]);
        } catch (Throwable) {
        }
    }
    j(['ok' => true, 'progress' => refresh_head($pdo, $rid),
        'no_serial' => $noSerial, 'serials' => count($serials),
        'reject_reason' => $rejectReason, 'prod_date' => $prodDate,
        'excess' => $over, 'plan' => $plan, 'fact' => $newFact]);
}

// Принять весь приход: остатки всех позиций принимаются в полном объёме,
// без номеров паллет. Исключения — позиции со сканированием с/н и ЧЗ:
// их нельзя принимать «оптом», они возвращаются в список для ручной приёмки.
if ($action === 'accept_all' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $fio = trim((string)($body['user_fio'] ?? '')) !== '' ? trim((string)$body['user_fio']) : 'ТСД';
    if ($rid <= 0) {
        j(['error' => 'нужен receipt_id'], 422);
    }
    prihod_check_open($pdo, $rid);
    try {
        $items = $pdo->prepare(
            "SELECT sku, MAX(name) AS name FROM receipt_items
              WHERE receipt_id=:r GROUP BY sku ORDER BY MIN(line_no), MIN(id)"
        );
        $items->execute([':r' => $rid]);
        $skuRows = $items->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        j(['error' => $e->getMessage()], 500);
    }
    $accepted = [];
    $skipped = [];
    foreach ($skuRows as $row) {
        $sku = trim((string)($row['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $req = pos_requirements($pdo, $rid, $sku);
        if ((int)$req['in'] === 1) {
            $skipped[] = ['sku' => $sku, 'reason' => 'требуется сканирование серийных номеров'];
            continue;
        }
        if (pos_chz_required($pdo, $rid, $sku)) {
            $skipped[] = ['sku' => $sku, 'reason' => 'требуется сканирование Честного знака'];
            continue;
        }
        $needProdDate = (int)$req['prod_date'] === 1;
        $batches = sku_batches($pdo, $rid, $sku);
        $multi = count($batches) > 1;
        $scopes = $multi ? $batches : [scope_bounds($pdo, $rid, $sku, null)];
        foreach ($scopes as $b) {
            $batch = (string)($b['batch'] ?? '');
            $plan = (float)($b['plan'] ?? 0);
            $fact = (float)($b['fact'] ?? 0);
            $rest = round($plan - $fact, 3);
            if ($rest <= 0.0001) {
                continue;
            }
            if ($needProdDate) {
                try {
                    $q = $pdo->prepare(
                        "SELECT COUNT(*) FROM receipt_items WHERE receipt_id=:r AND sku=:s
                           AND COALESCE(supplier_batch,'')=:b AND prod_date IS NOT NULL AND prod_date<>''"
                    );
                    $q->execute([':r' => $rid, ':s' => $sku, ':b' => $batch]);
                    if ((int)$q->fetchColumn() === 0) {
                        $skipped[] = ['sku' => $sku, 'reason' => 'не указана дата производства'];
                        continue;
                    }
                } catch (Throwable) {
                }
            }
            $pdo->beginTransaction();
            try {
                op_insert($pdo, $rid, $sku, $batch, 'Принято', $uid, null, $rest);
                apply_qty_fact($pdo, $rid, $sku, $batch, $multi, $plan, round($fact + $rest, 3));
                set_deviation($pdo, $rid, $sku, $batch, $multi, '');
                hist($pdo, $rid, $uid, 'qty',
                    'Кол-во' . ($batch !== '' ? ' · партия ' . $batch : ''),
                    $sku, 'x' . $rest . ' · принять весь приход (без паллеты)');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $skipped[] = ['sku' => $sku, 'reason' => $e->getMessage()];
                continue;
            }
            // Товарный запас + журнал склада: строка без номера паллеты.
            sync_pallet_stock($pdo, $rid, $sku, '', $uid);
            $accepted[] = ['sku' => $sku, 'batch' => $batch, 'qty' => $rest];
        }
    }
    $remaining = 0.0;
    foreach (receipt_scopes($pdo, $rid) as $s) {
        if (empty($s['closed'])) {
            $remaining = round($remaining + max(0, (float)($s['remaining'] ?? 0)), 3);
        }
    }
    j(['ok' => true, 'accepted' => $accepted, 'skipped' => $skipped,
        'remaining' => $remaining, 'progress' => refresh_head($pdo, $rid)]);
}

// Проверка паллеты: существует ли такой SSCC в товарном запасе (product_stock).
if ($action === 'pallet_check' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $pallet = trim((string)($body['pallet'] ?? ''));
    if ($rid <= 0 || $pallet === '') {
        j(['error' => 'нужны receipt_id и pallet'], 422);
    }
    try {
        require_once __DIR__ . '/../shared/db.php';
        $npdo = wms_nomenclature_pdo();
        $chk = $npdo->prepare("SELECT COUNT(*) FROM product_stock WHERE TRIM(pallet) = :p");
        $chk->execute([':p' => $pallet]);
        $pc = (int)$chk->fetchColumn();
        j(['pallet_busy' => $pc > 0, 'pallet' => $pallet, 'count' => $pc]);
    } catch (Throwable $e) {
        j(['error' => $e->getMessage()], 500);
    }
}

// Сверка факт vs документ (ромб схемы): shortage|exact|excess (+ партии).
// Если передана партия (batch) — сверка идет ПО ПАРТИИ, а не по всему SKU.
// Иначе это и есть причина ложной «недостачи»: приняли одну партию из трех,
// а факт 4873 сравнивали с планом 14616.
if ($action === 'fact') {
    $rid = (int)($_GET['receipt_id'] ?? $body['receipt_id'] ?? 0);
    $sku = trim((string)($_GET['sku'] ?? $body['sku'] ?? ''));
    $qBatch = array_key_exists('batch', $_GET) || array_key_exists('supplier_batch', $_GET)
        ? trim((string)($_GET['batch'] ?? $_GET['supplier_batch'] ?? ''))
        : (array_key_exists('batch', $body) || array_key_exists('supplier_batch', $body) ? req_batch($body) : null);
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $plan = sku_plan($pdo, $rid, $sku);
    $fact = sku_fact($pdo, $rid, $sku);
    $dev = round($fact - $plan, 3);
    $batches = sku_batches($pdo, $rid, $sku);
    $out = ['sku' => $sku, 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
        'state' => $dev > 0 ? 'excess' : ($dev < 0 ? 'shortage' : 'exact'),
        'remaining' => round(max(0, $plan - $fact), 3),
        'batches' => $batches];
    if ($qBatch !== null) {
        $bp = 0.0;
        $bf = 0.0;
        $found = false;
        foreach ($batches as $bRow) {
            if ((string)$bRow['batch'] === (string)$qBatch) {
                $bp = (float)$bRow['plan'];
                $bf = (float)$bRow['fact'];
                $found = true;
                break;
            }
        }
        if (!$found && count($batches) === 1) {
            $bp = (float)$batches[0]['plan'];
            $bf = (float)$batches[0]['fact'];
        }
        $bdev = round($bf - $bp, 3);
        $out['batch'] = $qBatch;
        $out['batch_plan'] = $bp;
        $out['batch_fact'] = $bf;
        $out['batch_dev'] = $bdev;
        $out['batch_remaining'] = round(max(0, $bp - $bf), 3);
        $out['batch_state'] = $bdev > 0 ? 'excess' : ($bdev < 0 ? 'shortage' : 'exact');
    }
    j($out);
}

/** Строка позиции оформлена как «не заявлен»? */
// Фиксация факта по позиции/партии (шаг 10-11).
// mode: exact | shortage (фиксация недостачи) | excess (фиксация излишков).
// Частичная приёмка (принято 10 из 100) недостачей НЕ считается — позиция просто
// не закрыта, принимаем дальше (по SSCC), пока не примем всё по строке.
// Недостачу и излишки фиксирует оператор явно: цифра 2 — недостача, цифра 3 — излишки.
// Живой факт лежит в receipt_operations (шаги qty/sscc_accept/mark) —
// отдельного INSERT в остаток не нужно: единый источник — receipt_operations,
// оприходование в product_stock (8.1) — через close → StockSync.
if ($action === 'fix' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sku = trim((string)($body['sku'] ?? ''));
    $pallet = trim((string)($body['pallet'] ?? '')) ?: null;
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $mode = (string)($body['mode'] ?? 'exact');
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    if (!in_array($mode, ['exact', 'shortage', 'excess'], true)) {
        j(['error' => 'mode: exact|shortage|excess'], 422);
    }
    $batch = req_batch($body);
    // Границы позиции: по партии, если партий несколько (иначе по всему SKU).
    $sb = scope_bounds($pdo, $rid, $sku, $batch);
    if (isset($sb['need_batch'])) {
        j(['error' => 'Выбери партию поставщика', 'need_batch' => true, 'batches' => $sb['batches']], 422);
    }
    $plan = (float)$sb['plan'];
    $fact = (float)$sb['fact'];
    $batchNorm = (string)$sb['batch'];
    $batchMode = (bool)$sb['batch_mode'];
    $dev = round($fact - $plan, 3);
    $batches = $sb['batches'];

    // --- Ветка «излишки»: подтверждённое количество после пересчёта.
    // Указывать можно сколько угодно раз — акт обновляется, а не дублируется.
    if ($mode === 'excess') {
        $total = null;
        if (array_key_exists('total_qty', $body)) {
            $total = (float)$body['total_qty'];
        } elseif (array_key_exists('recount_qty', $body)) {
            $total = (float)$body['recount_qty'];
        }
        if ($total === null) {
            j(['error' => 'Излишек: пересчитай товар и вбей количество для подтверждения',
                'need_recount' => true, 'plan' => $plan, 'fact' => $fact], 422);
        }
        if ($total <= 0) {
            j(['error' => 'Количество после пересчета должно быть > 0'], 422);
        }
        // Излишек фиксируем только по строке, принятой по документу:
        // пока принято меньше заявленного — это не излишек, а недобор.
        if (round($total - $plan, 3) > 0 && round($fact - $plan, 3) < 0) {
            j(['error' => 'Сначала прими заявленное количество по строке: принято ' . $fact
                . ', по документу ' . $plan
                . ($batchNorm !== '' ? ' (партия ' . $batchNorm . ')' : ''),
                'need_accept' => true, 'plan' => $plan, 'fact' => $fact], 422);
        }
        $delta = round($total - $fact, 3);
        $pdo->beginTransaction();
        try {
            if ($delta != 0) {
                op_insert($pdo, $rid, $sku, $batchNorm, 'Принято', $uid, $pallet, $delta, 'излишек: подтверждено пересчётом');
            }
            $fact = $total;
            $dev = round($fact - $plan, 3);
            // Отметка решения оператора. Акт об излишке формируется при закрытии документа.
            set_deviation($pdo, $rid, $sku, $batchNorm, $batchMode, $dev > 0 ? 'excess' : '');
            apply_qty_fact($pdo, $rid, $sku, $batchNorm, $batchMode, $plan, $fact);
            hist($pdo, $rid, $uid, 'excess', 'Излишек ' . $sku . ($batchNorm !== '' ? ' · партия ' . $batchNorm : ''), (string)$plan, (string)$fact);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            j(['error' => $e->getMessage()], 500);
        }
        j(['ok' => true, 'mode' => 'excess', 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
            'state' => $dev > 0 ? 'excess' : ($dev < 0 ? 'shortage' : 'exact'),
            'batch' => $batchNorm, 'progress' => refresh_head($pdo, $rid),
            'batches' => sku_batches($pdo, $rid, $sku)]);
    }

    // --- Ветка «недостача»: явная фиксация оператором.
    if ($mode === 'shortage') {
        if ($dev >= 0) {
            j(['error' => 'Недостачи нет: принято ' . $fact . ' из ' . $plan
                . ($batchNorm !== '' ? ' (партия ' . $batchNorm . ')' : '')], 422);
        }
        $pdo->beginTransaction();
        try {
            // Отметка решения оператора. Акт о недостаче формируется при закрытии документа.
            set_deviation($pdo, $rid, $sku, $batchNorm, $batchMode, 'shortage');
            apply_qty_fact($pdo, $rid, $sku, $batchNorm, $batchMode, $plan, $fact);
            hist($pdo, $rid, $uid, 'shortage', 'Недостача ' . $sku . ($batchNorm !== '' ? ' · партия ' . $batchNorm : ''), (string)$plan, (string)$fact);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            j(['error' => $e->getMessage()], 500);
        }
        j(['ok' => true, 'mode' => 'shortage', 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
            'state' => 'shortage', 'batch' => $batchNorm, 'progress' => refresh_head($pdo, $rid),
            'batches' => sku_batches($pdo, $rid, $sku)]);
    }

    // --- Обычная фиксация (позиция принята в полном объёме).
    $pdo->beginTransaction();
    try {
        apply_qty_fact($pdo, $rid, $sku, $batchNorm, $batchMode, $plan, $fact);
        hist($pdo, $rid, $uid, 'fix', 'Факт ' . $sku . ($batchNorm !== '' ? ' · партия ' . $batchNorm : ''), (string)$plan, (string)$fact);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    $resp = ['ok' => true, 'mode' => 'exact', 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
        'state' => $dev > 0 ? 'excess' : ($dev < 0 ? 'shortage' : 'exact'),
        'progress' => refresh_head($pdo, $rid),
        'batches' => sku_batches($pdo, $rid, $sku)];
    // Расхождение не закрыто — подсказываем, в какую ветку уйти оператору.
    if ($dev < 0) {
        $resp['need_fix'] = 'shortage';
    } elseif ($dev > 0) {
        $resp['need_fix'] = 'excess';
    }
    if ($batchMode) {
        $resp['batch'] = $batchNorm;
        $resp['batch_plan'] = $plan;
        $resp['batch_fact'] = $fact;
        $resp['batch_dev'] = $dev;
        $resp['batch_state'] = $resp['state'];
        $tot = ['plan' => sku_plan($pdo, $rid, $sku), 'fact' => sku_fact($pdo, $rid, $sku)];
        $resp['total_plan'] = $tot['plan'];
        $resp['total_fact'] = $tot['fact'];
    }
    j($resp);
}

// Подтверждение позиции (шаг 11/38). В партионном режиме подтверждаем
// ТОЛЬКО строки текущей партии, иначе первая же партия «подтвердила» бы весь SKU.
if ($action === 'confirm' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sku = trim((string)($body['sku'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $batch = req_batch($body);
    if ($batch !== null) {
        $pdo->prepare("UPDATE receipt_items SET line_status='confirmed' WHERE receipt_id=:r AND sku=:s AND COALESCE(supplier_batch,'')=:b AND line_status IN ('ok','unclaimed')")
            ->execute([':r' => $rid, ':s' => $sku, ':b' => (string)$batch]);
        hist($pdo, $rid, $uid, 'confirm', 'Позиция · партия ' . $batch, $sku, 'подтверждена');
    } else {
        $pdo->prepare("UPDATE receipt_items SET line_status='confirmed' WHERE receipt_id=:r AND sku=:s AND line_status IN ('ok','unclaimed')")
            ->execute([':r' => $rid, ':s' => $sku]);
        hist($pdo, $rid, $uid, 'confirm', 'Позиция', $sku, 'подтверждена');
    }
    j(['ok' => true]);
}

// Закрытие тарного места (шаг 12): UPDATE pallet Закрыта + итоги
if ($action === 'pallet_close' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $sscc = trim((string)($body['sscc'] ?? ''));
    $weight = (float)($body['weight'] ?? 0);
    if ($rid <= 0 || $sscc === '') {
        j(['error' => 'нужны receipt_id и sscc'], 422);
    }
    // Только ПРИНЯТОЕ: брак и отменённые операции не входят в qty_total паллеты
    // (иначе tsd/info.php потом вечно показывает «расходится с фактом»).
    $t = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND pallet=:s AND accept_status='Принято'");
    $t->execute([':r' => $rid, ':s' => $sscc]);
    $total = (float)$t->fetchColumn();
    $pdo->prepare("UPDATE tsd_pallets SET status='Закрыта', qty_total=:q, weight=:w, closed_at=datetime('now') WHERE receipt_id=:r AND sscc=:s")
        ->execute([':q' => $total, ':w' => $weight, ':r' => $rid, ':s' => $sscc]);
    j(['ok' => true, 'qty_total' => $total]);
}

// Состав принятого паллета (для экрана отмены): что и по каким партиям принято.
if ($action === 'pallet_info') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $sscc = trim((string)($_GET['sscc'] ?? ''));
    if ($rid <= 0 || $sscc === '') {
        j(['error' => 'нужны receipt_id и sscc'], 422);
    }
    $st = $pdo->prepare(
        "SELECT sku, COALESCE(supplier_batch,'') AS batch, COALESCE(SUM(qty),0) AS qty, COUNT(*) AS ops
         FROM receipt_operations
         WHERE receipt_id=:r AND pallet=:s AND accept_status='Принято'
         GROUP BY sku, COALESCE(supplier_batch,'') ORDER BY sku, batch"
    );
    $st->execute([':r' => $rid, ':s' => $sscc]);
    $rows = $st->fetchAll();
    $batches = [];
    foreach ($rows as $r) {
        $batches[(string)$r['batch']] = true;
    }
    j(['sscc' => $sscc, 'rows' => $rows, 'batches' => array_keys($batches),
        'total' => array_sum(array_map(fn($r) => (float)$r['qty'], $rows))]);
}

// Отмена принятого паллета: снимаем приёмку (по паллете, по партии — если указана).
if ($action === 'pallet_cancel' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sscc = trim((string)($body['sscc'] ?? ''));
    $batch = req_batch($body);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sscc === '') {
        j(['error' => 'нужны receipt_id и sscc'], 422);
    }
    $hs = $pdo->prepare("SELECT status FROM receipts WHERE id=:id");
    $hs->execute([':id' => $rid]);
    $head = $hs->fetch();
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    if (!in_array((string)$head['status'], PRIHOD_START_FROM, true)) {
        j(['error' => 'Документ в статусе «' . $head['status'] . '» — приёмка закрыта, отмена невозможна'], 409);
    }
    // Что именно снимаем: по партии (если указана) или всё, что принято по паллете.
    $sql = "SELECT id, sku, COALESCE(supplier_batch,'') AS batch, qty FROM receipt_operations
            WHERE receipt_id=:r AND pallet=:s AND accept_status='Принято'";
    $p = [':r' => $rid, ':s' => $sscc];
    if ($batch !== null && $batch !== '') {
        $sql .= " AND COALESCE(supplier_batch,'')=:b";
        $p[':b'] = (string)$batch;
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $ops = $st->fetchAll();
    if (!$ops) {
        j(['error' => $batch !== null && $batch !== ''
            ? 'По паллете ' . $sscc . ' партия ' . $batch . ' не принята'
            : 'Паллета ' . $sscc . ' в приёмке не найдена'], 404);
    }
    $total = 0.0;
    $skus = [];
    $batches = [];
    foreach ($ops as $o) {
        $total += (float)$o['qty'];
        $skus[(string)$o['sku']] = true;
        $batches[(string)$o['batch']] = true;
    }
    $pdo->beginTransaction();
    try {
        $ids = array_map(fn($o) => (int)$o['id'], $ops);
        // Плейсхолдеры вместо склейки: $sscc приходит из тела запроса.
        $ph = [];
        foreach ($ids as $k => $id) {
            $ph[':id' . $k] = $id;
        }
        $in = implode(',', array_keys($ph));
        // Операции помечаем отменёнными (сохраняем след в журнале операций).
        // Конкатенация строки — переносимая: MySQL требует CONCAT(), SQLite/PG — оператор ||.
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $reasonExpr = $drv === 'mysql'
            ? "CONCAT(COALESCE(reason,''), ' · отмена паллеты ', :rsn)"
            : "(COALESCE(reason,'') || ' · отмена паллеты ' || :rsn)";
        $pdo->prepare("UPDATE receipt_operations SET accept_status='Отменено', reason=$reasonExpr WHERE id IN ($in)")
            ->execute($ph + [':rsn' => $sscc]);
        // Пересчитываем факт и снимаем отметки о решении оператора по затронутым позициям.
        foreach (array_keys($skus) as $sku) {
            foreach (array_keys($batches) as $b) {
                $sb = scope_bounds($pdo, $rid, (string)$sku, (string)$b);
                if (isset($sb['need_batch'])) {
                    continue;
                }
                $f = (float)$sb['fact'];
                $pl = (float)$sb['plan'];
                apply_qty_fact($pdo, $rid, (string)$sku, (string)$sb['batch'], (bool)$sb['batch_mode'], $pl, $f);
                set_deviation($pdo, $rid, (string)$sku, (string)$sb['batch'], (bool)$sb['batch_mode'], '');
            }
        }
        // Паллета: если принятого по ней не осталось — помечаем отменённой.
        $left = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND pallet=:s AND accept_status='Принято'");
        $left->execute([':r' => $rid, ':s' => $sscc]);
        $rest = (float)$left->fetchColumn();
        $pdo->prepare("UPDATE tsd_pallets SET status=:st, qty_total=:q WHERE receipt_id=:r AND sscc=:s")
            ->execute([':st' => ($rest > 0 ? 'Заполняется' : 'Отменена'), ':q' => $rest, ':r' => $rid, ':s' => $sscc]);
        hist($pdo, $rid, $uid, 'pallet_cancel', 'Отмена паллеты ' . $sscc
            . ($batch !== null && $batch !== '' ? ' · партия ' . $batch : ''),
            'принято ' . $total . ' шт', 'снято ' . $total . ' шт');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    // Товарный запас 8.1: остаток паллеты приводим к факту (UPDATE).
    foreach (array_keys($skus) as $sku) {
        sync_pallet_stock($pdo, $rid, (string)$sku, $sscc, $uid);
    }
    j(['ok' => true, 'sscc' => $sscc, 'cancelled_qty' => round($total, 3),
        'batch' => ($batch !== null ? $batch : ''), 'rest' => $rest,
        'progress' => refresh_head($pdo, $rid)]);
}

// Кросс-док: создать строку (gate_id, doc_id) + sscc kd (шаг 2)
if ($action === 'crossdock' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
                prihod_check_open($pdo, $rid);
    $gate = trim((string)($body['gate_id'] ?? $body['gate'] ?? ''));
    $sscc = trim((string)($body['sscc'] ?? '')) ?: null;
    if ($rid <= 0) {
        j(['error' => 'нужен receipt_id'], 422);
    }
    $pdo->prepare("INSERT INTO tsd_crossdock (receipt_id,gate_id,sscc,status) VALUES (:r,:g,:s,'создана')")
        ->execute([':r' => $rid, ':g' => $gate ?: null, ':s' => $sscc]);
    j(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

// Кросс-док: переместить в зону отгрузки (шаг 13)
if ($action === 'crossdock_move' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
                // Документ должен быть открыт для приёмки (закрытый — не принимаем).
    prihod_check_open($pdo, $rid);
    $sscc = trim((string)($body['sscc'] ?? ''));
    $cdId = (int)($body['crossdock_id'] ?? 0);
    if ($rid <= 0) {
        j(['error' => 'нужен receipt_id'], 422);
    }
    // Переносим ТОЛЬКО указанную паллету. Раньше запрос двигал в зону отгрузки
    // ВСЕ кросс-док-строки документа разом — одна отсканированная паллета
    // помечала отгруженными все остальные.
    $sql = "UPDATE tsd_crossdock SET status='в зоне отгрузки' WHERE receipt_id=:r AND status <> 'в зоне отгрузки'";
    $p = [':r' => $rid];
    if ($cdId > 0) {
        $sql .= ' AND id = :id';
        $p[':id'] = $cdId;
    } elseif ($sscc !== '') {
        $sql .= ' AND sscc = :s';
        $p[':s'] = $sscc;
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $moved = $st->rowCount();
    $left = (int)$pdo->query("SELECT COUNT(*) FROM tsd_crossdock WHERE receipt_id=" . $rid . " AND status <> 'в зоне отгрузки'")->fetchColumn();
    j(['ok' => true, 'moved' => $moved, 'left' => $left,
        'sscc' => $sscc !== '' ? $sscc : null]);
}

// Закрытие документа + формирование актов (шаг 14).
// Акты расхождений формируются ТОЛЬКО здесь — при закрытии документа.
// Статус: «Закрыто» (по всем строкам полученное = заявленному) либо
// «Закрыто с отклонением» (есть излишки или недостача).
// Закрыть можно, когда все строки приняты (fact = plan) либо приняты с излишками,
// подтверждёнными пересчётом. Незафиксированная недостача закрытию мешает:
// сначала оператор фиксирует недостачу (цифра 2 на форме «Приёмка»).
if ($action === 'close' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $h = $pdo->prepare("SELECT status, comment FROM receipts WHERE id=:id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    // Просто так закрыть нельзя: излишки без пересчета-подтверждения блокируют закрытие.
    // Недостача: закрывать позицию можно только после явной фиксации оператором.
    // Проверки и закрытие — единой функцией (так же, как в реестре поступлений).
    // --- Формируем акты расхождений и закрываем документ (только при закрытии) ---
    try {
        $closed = wms_close_receipt_guarded($pdo, $rid, $uid, 'ТСД');
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = str_starts_with($msg, 'Нельзя закрыть') ? 409 : 500;
        j(['error' => $msg], $code);
    }
    $status = $closed['status'];
    $excessQty = $closed['excess_qty'];
    $shortQty = $closed['shortage_qty'];
    $devText = $closed['deviation'];
    $actRows = $closed['act'];
    // Проброс факта в Номенклатуру (products.qty / stock / movements). Идемпотентно.
    $stock = null;
    try {
        require_once __DIR__ . '/../shared/StockSync.php';
        $stock = wms_push_receipt_to_nomenclature($pdo, wms_nomenclature_pdo(), $rid, $uid);
        hist($pdo, $rid, $uid, 'stock', 'Номенклатура', null, 'оприходовано SKU: ' . count($stock['items'] ?? []));
    } catch (Throwable $e) {
        $stock = ['synced' => false, 'error' => $e->getMessage()];
    }
    j(['ok' => true, 'status' => $status, 'status_label' => $closed['status_label'],
        'excess_qty' => $excessQty, 'shortage_qty' => $shortQty, 'deviation' => $devText,
        'act' => $actRows, 'acts' => $closed['acts'],
        'progress' => refresh_head($pdo, $rid), 'stock' => $stock]);
}

// Обмеры товара с ТСД (новый товар из CSV-прихода): сохранить габариты + снять флаг need_measure
if ($action === 'measure_save' && $method === 'POST') {
    $sku = trim((string)($body['sku'] ?? ''));
    $l = (int)($body['length_mm'] ?? 0);
    $w = (int)($body['width_mm'] ?? 0);
    $h = (int)($body['height_mm'] ?? 0);
    $wt = (int)($body['weight_g'] ?? 0);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($sku === '') {
        j(['error' => 'нужен sku'], 422);
    }
    try {
        require_once __DIR__ . '/../shared/CsvUpload.php';
        j(csv_upload_save_measure(wms_nomenclature_pdo(), $sku, $l, $w, $h, $wt, $uid, 'ТСД'));
    } catch (Throwable $e) {
        j(['error' => $e->getMessage()], 400);
    }
}

// Состояние документа для мастера (прогресс, паллеты, акты).
// fact теперь считается ПО ПАРТИИ строки (supplier_batch), а не по всему SKU:
// иначе 3 строки-партии одного SKU получали одинаковый общий факт и остаток
// считался неверно (0 вместо 2×4872).
if ($action === 'state') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $h = $pdo->prepare("SELECT r.*, sup.name AS supplier, w.name AS warehouse FROM receipts r LEFT JOIN contractors sup ON sup.id=r.supplier_id LEFT JOIN warehouses w ON w.id=r.warehouse_id WHERE r.id=:id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    $items = $pdo->prepare("SELECT i.* FROM receipt_items i WHERE i.receipt_id=:id ORDER BY i.line_no, i.id");
    $items->execute([':id' => $rid]);
    $itemRows = $items->fetchAll();
    foreach ($itemRows as &$ir) {
        $b = isset($ir['supplier_batch']) ? trim((string)$ir['supplier_batch']) : '';
        try {
            $ir['fact'] = sku_fact($pdo, $rid, (string)$ir['sku'], $b);
        } catch (Throwable) {
            $ir['fact'] = 0;
        }
    }
    unset($ir);
    $pal = $pdo->prepare("SELECT * FROM tsd_pallets WHERE receipt_id=:id ORDER BY id");
    $pal->execute([':id' => $rid]);
    $acts = $pdo->prepare("SELECT * FROM tsd_act_rasx WHERE receipt_id=:id ORDER BY id");
    $acts->execute([':id' => $rid]);
    $cd = $pdo->prepare("SELECT * FROM tsd_crossdock WHERE receipt_id=:id ORDER BY id");
    $cd->execute([':id' => $rid]);
    // Позиции по партиям: план/факт/остаток/состояние — мастеру и форме «Приёмка».
    $scopes = receipt_scopes($pdo, $rid);
    j(['head' => $head, 'items' => $itemRows, 'pallets' => $pal->fetchAll(), 'acts' => $acts->fetchAll(),
        'crossdock' => $cd->fetchAll(), 'scopes' => $scopes, 'progress' => refresh_head($pdo, $rid)]);
}

j(['error' => 'unknown action'], 404);
