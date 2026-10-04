<?php
declare(strict_types=1);
// ТСД «Приход» — мастер приёмки по схеме «Приход ТСД» (drawio).
// Маппинг схемы на таблицы (ЕДИНАЯ БД поступлений 02-postupleniya/wms_receipts.sqlite, см. shared/db.php):
//   doc_priemka            -> receipts (статус «В работе» = accepting, «Закрыт» = ready_place)
//   ячейка ворот           -> receipts.gate
//   line (позиция)         -> receipt_items (+qty_fact, +line_status: ok|unclaimed|confirmed)
//   line_qty               -> receipt_operations (accept_status: Принято|Брак, +reason)
//   mark_log               -> receipt_flags (+status: годный|брак|нечитаема, +reason)
//   pallet                 -> tsd_pallets (sscc, status Заполняется|Закрыта, qty_total, weight)
//   line_crossdock         -> tsd_crossdock (gate_id, sscc, status)
//   act_rasx               -> tsd_act_rasx (тип: недостача|излишек|не_заявлен|брак, qty_diff)
//   ostatok                -> tsd_stock (sku, pallet, qty)
// Порядок этапов на ТСД: 1 ворота (скан) → 2 документы с этими воротами / ШК документа
// (ворота проставляются в receipts.gate) → 3 скан ШК товара → 4 кросс-док? → 5 сканы
// единиц (марки ЧЗ либо поштучные ШК) → 6 обмеры нового товара → 7 количество → 8 тарное
// место → 9 качество. Шаги API: docs[?gate=], doc_by_code, start, ean, unclaimed,
// pallet_open, sscc_accept, mark, qty, fact, confirm, pallet_close, crossdock,
// crossdock_move, close, measure_save.

const TSD_TOKEN = 'tsd-12345';
const PRIHOD_START_FROM = ['expected', 'arrived', 'unloading', 'on_stock', 'ready_accept', 'accepting'];

function tok_ok(): bool
{
    $t = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($t === '') {
        $h = $_SERVER['HTTP_X_TSD_TOKEN'] ?? '';
        if ($h !== '') {
            $t = $h;
        } else {
            $a = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (str_starts_with($a, 'Bearer ')) {
                $t = substr($a, 7);
            }
        }
    }
    if ($t !== '' && hash_equals(TSD_TOKEN, (string)$t)) {
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
    require_once __DIR__ . '/../shared/db.php';
    return wms_receipts_pdo();
}

function hist(PDO $pdo, int $rid, $uid, string $action, ?string $field, ?string $old, ?string $new): void
{
    $pdo->prepare("INSERT INTO receipt_history (receipt_id,user_id,action,field,old_value,new_value) VALUES (:r,:u,:a,:f,:o,:n)")
        ->execute([':r' => $rid, ':u' => $uid, ':a' => $action, ':f' => $field, ':o' => $old, ':n' => $new]);
}

function sku_plan(PDO $pdo, int $rid, string $sku): float
{
    $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=:r AND sku=:s");
    $s->execute([':r' => $rid, ':s' => $sku]);
    return (float)$s->fetchColumn();
}

function sku_fact(PDO $pdo, int $rid, string $sku): float
{
    $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND sku=:s AND accept_status='Принято'");
    $s->execute([':r' => $rid, ':s' => $sku]);
    return (float)$s->fetchColumn();
}

/**
 * Позиции с излишком (fact > plan), не подтвержденным пересчетом
 * (нет акта излишка). Не заявленные строки не считаем излишком.
 * @return string[] sku
 */
function unconfirmed_excess(PDO $pdo, int $rid): array
{
    try {
        $st = $pdo->prepare(
            "SELECT i.sku,
                    COALESCE((SELECT SUM(o.qty) FROM receipt_operations o WHERE o.receipt_id = i.receipt_id AND o.sku = i.sku AND o.accept_status = 'Принято'), 0) AS fact,
                    COALESCE(SUM(i.qty), 0) AS plan
             FROM receipt_items i
             WHERE i.receipt_id = :r AND COALESCE(i.line_status, 'ok') != 'unclaimed'
             GROUP BY i.sku"
        );
        $st->execute([':r' => $rid]);
        $over = [];
        foreach ($st->fetchAll() as $row) {
            if (round((float)$row['fact'] - (float)$row['plan'], 3) > 0) {
                $over[] = $row['sku'];
            }
        }
        if (!$over) {
            return [];
        }
        $in = implode(',', array_fill(0, count($over), '?'));
        $a = $pdo->prepare("SELECT DISTINCT sku FROM tsd_act_rasx WHERE receipt_id = ? AND type = 'излишек' AND sku IN ($in)");
        $a->execute([$rid, ...$over]);
        $confirmed = $a->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($over, $confirmed));
    } catch (Throwable) {
        return [];
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
             WHERE r.status IN ($in) AND r.gate = :g ORDER BY r.plan_delivery_date IS NULL, r.plan_delivery_date ASC, r.id DESC LIMIT 100"
        );
        $st->execute([':g' => $gate]);
        $rows = $st->fetchAll();
    } else {
        $rows = $pdo->query(
            "SELECT r.id, r.doc_no, r.external_no, r.status, r.gate, w.name AS warehouse, sup.name AS supplier,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято') AS fact_qty
             FROM receipts r LEFT JOIN warehouses w ON w.id=r.warehouse_id LEFT JOIN contractors sup ON sup.id=r.supplier_id
             WHERE r.status IN ($in) ORDER BY r.plan_delivery_date IS NULL, r.plan_delivery_date ASC, r.id DESC LIMIT 100"
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
         WHERE r.status IN ($in) AND (r.doc_no = :c OR r.external_no = :c) LIMIT 1"
    );
    $s->execute([':c' => $code]);
    $doc = $s->fetch();
    if (!$doc) {
        $l = $pdo->prepare(
            "SELECT r.id, r.doc_no, r.external_no, r.status, r.gate, w.name AS warehouse, sup.name AS supplier,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                    (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id AND accept_status='Принято') AS fact_qty
             FROM receipts r LEFT JOIN warehouses w ON w.id=r.warehouse_id LEFT JOIN contractors sup ON sup.id=r.supplier_id
             WHERE r.status IN ($in) AND (r.doc_no LIKE :q OR r.external_no LIKE :q) LIMIT 1"
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

// Начало приёмки: ячейка ворот + статус «В работе» (шаг 1 схемы)
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
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE receipts SET gate=COALESCE(NULLIF(:g,''),gate), status='accepting', accept_date=COALESCE(accept_date,datetime('now')) WHERE id=:id")
            ->execute([':g' => $gate, ':id' => $rid]);
        hist($pdo, $rid, $uid, 'status', 'Статус', $head['status'], 'accepting');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'status' => 'accepting']);
}

// Сканирование штрихкода товара (EAN-13) -> карточка или «не числится»
if ($action === 'ean') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $ean = trim((string)($_GET['ean'] ?? $body['ean'] ?? ''));
    if ($rid <= 0 || $ean === '') {
        j(['error' => 'нужны receipt_id и ean'], 422);
    }
    $s = $pdo->prepare("SELECT id, line_no, code, sku, name, unit, qty, qty_fact, line_status FROM receipt_items WHERE receipt_id=:r AND (code=:e OR sku=:e) LIMIT 1");
    $s->execute([':r' => $rid, ':e' => $ean]);
    $item = $s->fetch();
    if (!$item) {
        $l = $pdo->prepare("SELECT id, line_no, code, sku, name, unit, qty, qty_fact, line_status FROM receipt_items WHERE receipt_id=:r AND (code LIKE :q OR sku LIKE :q) LIMIT 1");
        $l->execute([':r' => $rid, ':q' => '%' . $ean . '%']);
        $item = $l->fetch();
    }
    if (!$item) {
        j(['found' => false]);
    }
    $item['fact'] = sku_fact($pdo, $rid, $item['sku']);
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
    j(['found' => true, 'item' => $item, 'measure' => $measure, 'chz_required' => $chzRequired]);
}

// «Не заявлен»: оформить строку (шаг 3) + статус строки
if ($action === 'unclaimed' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
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

// Приём всей вложенности по SSCC без вскрытия (шаг 5): items=[{sku,qty}]
if ($action === 'sscc_accept' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sscc = trim((string)($body['sscc'] ?? ''));
    $items = (array)($body['items'] ?? []);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sscc === '' || !$items) {
        j(['error' => 'нужны receipt_id, sscc и items [{sku,qty}]'], 422);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT OR IGNORE INTO tsd_pallets (receipt_id,sscc,status) VALUES (:r,:s,'Заполняется')")->execute([':r' => $rid, ':s' => $sscc]);
        $ins = $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty) VALUES (:r,:sku,'Принято',datetime('now'),:u,:p,1,:q)");
        foreach ($items as $it) {
            $q = (float)($it['qty'] ?? 0);
            if ($q <= 0 || empty($it['sku'])) {
                continue;
            }
            $ins->execute([':r' => $rid, ':sku' => (string)$it['sku'], ':u' => $uid, ':p' => $sscc, ':q' => $q]);
        }
        hist($pdo, $rid, $uid, 'sscc', 'SSCC приём', null, $sscc);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'progress' => refresh_head($pdo, $rid)]);
}

// Марка DataMatrix (шаги 6-9): quality=good|unreadable|defect + reason
if ($action === 'mark' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sku = trim((string)($body['sku'] ?? ''));
    $mark = trim((string)($body['mark'] ?? ''));
    $quality = (string)($body['quality'] ?? 'good');
    $reason = trim((string)($body['reason'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sku === '' || $mark === '') {
        j(['error' => 'нужны receipt_id, sku и mark'], 422);
    }
    if (!in_array($quality, ['good', 'unreadable', 'defect'], true)) {
        j(['error' => 'quality: good|unreadable|defect'], 422);
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
            $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,qty,reason) VALUES (:r,:s,'Принято',datetime('now'),:u,1,'нечитаемая марка (отложена)')")
                ->execute([':r' => $rid, ':s' => $sku, ':u' => $uid]);
        } else {
            // Брак маркированного (шаг 7)
            if ($reason === '') {
                throw new RuntimeException('Укажи причину брака');
            }
            $pdo->prepare("INSERT INTO receipt_flags (receipt_id,item_id,flag_type,marking_code,sku,status,reason,flag_date) VALUES (:r,:i,'marking',:m,:s,'брак',:o,datetime('now'))")
                ->execute([':r' => $rid, ':i' => $itemId, ':m' => $mark, ':s' => $sku, ':o' => $reason]);
            $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,qty,reason) VALUES (:r,:s,'Брак',datetime('now'),:u,1,:o)")
                ->execute([':r' => $rid, ':s' => $sku, ':u' => $uid, ':o' => $reason]);
            $pdo->prepare("INSERT INTO tsd_act_rasx (receipt_id,sku,type,qty_diff) VALUES (:r,:s,'брак',1)")->execute([':r' => $rid, ':s' => $sku]);
        }
        hist($pdo, $rid, $uid, 'mark', 'Марка ' . $quality, $mark, $sku);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], $e instanceof RuntimeException ? 422 : 500);
    }
    j(['ok' => true, 'progress' => refresh_head($pdo, $rid)]);
}

// Ручной ввод количества немаркированного (шаг 8) + качество товара
if ($action === 'qty' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sku = trim((string)($body['sku'] ?? ''));
    $qty = (float)($body['qty'] ?? 0);
    $quality = (string)($body['quality'] ?? 'good');
    $reason = trim((string)($body['reason'] ?? ''));
    $pallet = trim((string)($body['pallet'] ?? '')) ?: null;
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sku === '' || $qty <= 0) {
        j(['error' => 'нужны receipt_id, sku и qty>0'], 422);
    }
    if ($quality === 'defect') {
        // Шаг 6: брак немаркированного (бой / мятая упаковка)
        if ($reason === '') {
            j(['error' => 'Укажи причину брака: бой / мятая упаковка'], 422);
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty,reason) VALUES (:r,:s,'Брак',datetime('now'),:u,:p,1,:q,:o)")
                ->execute([':r' => $rid, ':s' => $sku, ':u' => $uid, ':p' => $pallet, ':q' => $qty, ':o' => $reason]);
            $pdo->prepare("INSERT INTO tsd_act_rasx (receipt_id,sku,type,qty_diff) VALUES (:r,:s,'брак',:q)")->execute([':r' => $rid, ':s' => $sku, ':q' => $qty]);
            hist($pdo, $rid, $uid, 'defect', 'Брак', $sku, $reason . ' x' . $qty);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            j(['error' => $e->getMessage()], 500);
        }
        j(['ok' => true, 'progress' => refresh_head($pdo, $rid)]);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty) VALUES (:r,:s,'Принято',datetime('now'),:u,:p,1,:q)")
            ->execute([':r' => $rid, ':s' => $sku, ':u' => $uid, ':p' => $pallet, ':q' => $qty]);
        hist($pdo, $rid, $uid, 'qty', 'Кол-во', $sku, 'x' . $qty);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'progress' => refresh_head($pdo, $rid)]);
}

// Сверка факт vs документ (ромб схемы): shortage|exact|excess
if ($action === 'fact') {
    $rid = (int)($_GET['receipt_id'] ?? $body['receipt_id'] ?? 0);
    $sku = trim((string)($_GET['sku'] ?? $body['sku'] ?? ''));
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $plan = sku_plan($pdo, $rid, $sku);
    $fact = sku_fact($pdo, $rid, $sku);
    $dev = round($fact - $plan, 3);
    j(['sku' => $sku, 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
        'state' => $dev > 0 ? 'excess' : ($dev < 0 ? 'shortage' : 'exact')]);
}

// Фиксация факта (шаг 10): UPDATE line qty_fact + INSERT ostatok; акт при расхождении (шаг 11)
if ($action === 'fix' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sku = trim((string)($body['sku'] ?? ''));
    $pallet = trim((string)($body['pallet'] ?? '')) ?: null;
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $plan = sku_plan($pdo, $rid, $sku);
    $fact = sku_fact($pdo, $rid, $sku);
    $dev = round($fact - $plan, 3);
    // Излишек без пересчета не фиксируем: требуем вбить количество после пересчета.
    $recount = array_key_exists('recount_qty', $body) ? (float)$body['recount_qty'] : null;
    if ($dev > 0 && $recount === null) {
        j(['error' => 'Излишек: пересчитай товар и вбей количество для подтверждения',
            'need_recount' => true, 'plan' => $plan, 'fact' => $fact], 422);
    }
    $pdo->beginTransaction();
    try {
        if ($recount !== null) {
            if ($recount <= 0) {
                throw new RuntimeException('Количество после пересчета должно быть > 0');
            }
            $corr = round($recount - $fact, 3);
            if ($corr != 0) {
                $pdo->prepare("INSERT INTO receipt_operations (receipt_id,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty,reason) VALUES (:r,:s,'Принято',datetime('now'),:u,:p,1,:q,'пересчет: подтверждение количества')")
                    ->execute([':r' => $rid, ':s' => $sku, ':u' => $uid, ':p' => $pallet, ':q' => $corr]);
            }
            $fact = $recount;
            $dev = round($fact - $plan, 3);
        }
        if ($plan > 0) {
            $rows = $pdo->prepare("SELECT id, qty FROM receipt_items WHERE receipt_id=:r AND sku=:s");
            $rows->execute([':r' => $rid, ':s' => $sku]);
            $up = $pdo->prepare("UPDATE receipt_items SET qty_fact=:f WHERE id=:id");
            foreach ($rows->fetchAll() as $rw) {
                $up->execute([':f' => round($fact * ((float)$rw['qty'] / $plan), 3), ':id' => $rw['id']]);
            }
        } else {
            $pdo->prepare("UPDATE receipt_items SET qty_fact=:f WHERE receipt_id=:r AND sku=:s")->execute([':f' => $fact, ':r' => $rid, ':s' => $sku]);
        }
        $pdo->prepare("INSERT INTO tsd_stock (receipt_id,sku,pallet,qty) VALUES (:r,:s,:p,:q)")
            ->execute([':r' => $rid, ':s' => $sku, ':p' => $pallet, ':q' => $fact]);
        $type = null;
        if ($dev < 0) {
            $type = 'недостача';
        } elseif ($dev > 0) {
            $isUn = $pdo->prepare("SELECT COUNT(*) FROM receipt_items WHERE receipt_id=:r AND sku=:s AND line_status='unclaimed'");
            $isUn->execute([':r' => $rid, ':s' => $sku]);
            $type = ((int)$isUn->fetchColumn() > 0) ? 'не_заявлен' : 'излишек';
        }
        if ($type !== null) {
            $pdo->prepare("INSERT INTO tsd_act_rasx (receipt_id,sku,type,qty_diff) VALUES (:r,:s,:t,:d)")
                ->execute([':r' => $rid, ':s' => $sku, ':t' => $type, ':d' => $dev]);
        }
        hist($pdo, $rid, $uid, 'fix', 'Факт ' . $sku, (string)$plan, (string)$fact);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
        'state' => $dev > 0 ? 'excess' : ($dev < 0 ? 'shortage' : 'exact'),
        'progress' => refresh_head($pdo, $rid)]);
}

// Подтверждение позиции (шаг 11/38)
if ($action === 'confirm' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sku = trim((string)($body['sku'] ?? ''));
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    if ($rid <= 0 || $sku === '') {
        j(['error' => 'нужны receipt_id и sku'], 422);
    }
    $pdo->prepare("UPDATE receipt_items SET line_status='confirmed' WHERE receipt_id=:r AND sku=:s AND line_status IN ('ok','unclaimed')")
        ->execute([':r' => $rid, ':s' => $sku]);
    hist($pdo, $rid, $uid, 'confirm', 'Позиция', $sku, 'подтверждена');
    j(['ok' => true]);
}

// Закрытие тарного места (шаг 12): UPDATE pallet Закрыта + итоги
if ($action === 'pallet_close' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $sscc = trim((string)($body['sscc'] ?? ''));
    $weight = (float)($body['weight'] ?? 0);
    if ($rid <= 0 || $sscc === '') {
        j(['error' => 'нужны receipt_id и sscc'], 422);
    }
    $t = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND pallet=:s");
    $t->execute([':r' => $rid, ':s' => $sscc]);
    $total = (float)$t->fetchColumn();
    $pdo->prepare("UPDATE tsd_pallets SET status='Закрыта', qty_total=:q, weight=:w, closed_at=datetime('now') WHERE receipt_id=:r AND sscc=:s")
        ->execute([':q' => $total, ':w' => $weight, ':r' => $rid, ':s' => $sscc]);
    j(['ok' => true, 'qty_total' => $total]);
}

// Кросс-док: создать строку (gate_id, doc_id) + sscc kd (шаг 2)
if ($action === 'crossdock' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
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
    if ($rid <= 0) {
        j(['error' => 'нужен receipt_id'], 422);
    }
    $pdo->prepare("UPDATE tsd_crossdock SET status='в зоне отгрузки' WHERE receipt_id=:r AND status != 'в зоне отгрузки'")->execute([':r' => $rid]);
    j(['ok' => true]);
}

// Закрытие документа + акт (шаг 14): статус «Закрыт» (=ready_place), ts_end, печать акта
if ($action === 'close' && $method === 'POST') {
    $rid = (int)($body['receipt_id'] ?? 0);
    $uid = (int)($body['user_id'] ?? 0) ?: null;
    $h = $pdo->prepare("SELECT status FROM receipts WHERE id=:id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    $acts = $pdo->prepare("SELECT sku, type, qty_diff, created_at FROM tsd_act_rasx WHERE receipt_id=:r ORDER BY id");
    $acts->execute([':r' => $rid]);
    $actRows = $acts->fetchAll();
    // Просто так закрыть нельзя: излишки без пересчета-подтверждения блокируют закрытие.
    $bad = unconfirmed_excess($pdo, $rid);
    if ($bad) {
        j(['error' => 'Нельзя закрыть: подтверди излишек пересчетом по позициям: ' . implode(', ', $bad)], 409);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE receipts SET status='ready_place', accept_date=COALESCE(accept_date,datetime('now')) WHERE id=:id")->execute([':id' => $rid]);
        hist($pdo, $rid, $uid, 'status', 'Статус', $head['status'], 'ready_place');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    // Проброс факта в Номенклатуру (products.qty / stock / movements). Идемпотентно.
    $stock = null;
    try {
        require_once __DIR__ . '/../shared/StockSync.php';
        $stock = wms_push_receipt_to_nomenclature($pdo, wms_nomenclature_pdo(), $rid, $uid);
        hist($pdo, $rid, $uid, 'stock', 'Номенклатура', null, 'оприходовано SKU: ' . count($stock['items'] ?? []));
    } catch (Throwable $e) {
        $stock = ['synced' => false, 'error' => $e->getMessage()];
    }
    j(['ok' => true, 'status' => 'ready_place', 'act' => $actRows, 'progress' => refresh_head($pdo, $rid), 'stock' => $stock]);
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

// Состояние документа для мастера (прогресс, паллеты, акты)
if ($action === 'state') {
    $rid = (int)($_GET['receipt_id'] ?? 0);
    $h = $pdo->prepare("SELECT r.*, sup.name AS supplier, w.name AS warehouse FROM receipts r LEFT JOIN contractors sup ON sup.id=r.supplier_id LEFT JOIN warehouses w ON w.id=r.warehouse_id WHERE r.id=:id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Документ не найден'], 404);
    }
    $items = $pdo->prepare("SELECT i.*, COALESCE((SELECT SUM(o.qty) FROM receipt_operations o WHERE o.receipt_id=i.receipt_id AND o.sku=i.sku AND o.accept_status='Принято'),0) AS fact FROM receipt_items i WHERE i.receipt_id=:id ORDER BY i.line_no, i.id");
    $items->execute([':id' => $rid]);
    $pal = $pdo->prepare("SELECT * FROM tsd_pallets WHERE receipt_id=:id ORDER BY id");
    $pal->execute([':id' => $rid]);
    $acts = $pdo->prepare("SELECT * FROM tsd_act_rasx WHERE receipt_id=:id ORDER BY id");
    $acts->execute([':id' => $rid]);
    $cd = $pdo->prepare("SELECT * FROM tsd_crossdock WHERE receipt_id=:id ORDER BY id");
    $cd->execute([':id' => $rid]);
    j(['head' => $head, 'items' => $items->fetchAll(), 'pallets' => $pal->fetchAll(), 'acts' => $acts->fetchAll(), 'crossdock' => $cd->fetchAll(), 'progress' => refresh_head($pdo, $rid)]);
}

j(['error' => 'unknown action'], 404);
