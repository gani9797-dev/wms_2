<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
// ТСД-модуль приемки (поступления). Отдельная легкая точка для сканеров.
// Связка с проектом: пишет В ТУ ЖЕ БД, что и /02-postupleniya/demo_sqlite.php
// (единый файл 02-postupleniya/wms_receipts.sqlite через shared/db.php):
//   receipt_operations (факт приемки), receipt_flags (серийники/ЧЗ),
//   receipts.accepted_qty/accepted_pct/deviation_qty/has_diff/accept_date/status,
//   receipt_history (action=accept).
// То есть "принял на ТСД -> сразу видно в Поступлениях".
//
// Авторизация: либо общая сессия (зашел через /login с ПК), либо токен ТСД:
//   /tsd/api.php?action=tasks&token=tsd-12345
//   или заголовок X-Tsd-Token: tsd-12345
// Смена токена: заменить константу ниже (на проде — вынести в env/БД + PIN на пользователя).

const TSD_WORK_STATUSES = ['arrived', 'unloading', 'on_stock', 'ready_accept', 'accepting', 'ready_place', 'placing'];

function tsd_token_ok(): bool
{
    if (wms_tsd_token_ok()) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return isset($_SESSION['wms_auth_user']);
}

function tsd_json(mixed $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function tsd_db(): PDO
{
    // Единая БД поступлений (владелец — 02-postupleniya). См. shared/db.php.
    require_once __DIR__ . '/../shared/db.php';
    return wms_receipts_pdo();
}

function tsd_progress(PDO $pdo, int $rid): array
{
    $plan = (float)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=" . $rid)->fetchColumn();
    // Только ПРИНЯТОЕ: «Брак» и «Отменено» — не факт приёмки.
    // Раньше суммировались все операции, и прогресс в tsd/api.php расходился
    // с прогрессом в tsd/prihod.php (refresh_head фильтрует по Принято).
    $fact = (float)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=" . $rid
        . " AND accept_status='Принято'")->fetchColumn();
    $pct = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    return ['plan' => $plan, 'fact' => $fact, 'pct' => $pct, 'dev' => round($fact - $plan, 3)];
}

if (!tsd_token_ok()) {
    tsd_json(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
}

$pdo = tsd_db();
$action = $_GET['action'] ?? 'tasks';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Список заданий на приемку для ТСД ---
if ($action === 'tasks') {
    $in = "'" . implode("','", TSD_WORK_STATUSES) . "'";
    $st = $pdo->query(
        "SELECT r.id, r.doc_no, r.external_no, r.status, r.vehicle, r.gate, r.warehouse_id,
                w.name AS warehouse, sup.name AS supplier,
                (SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=r.id) AS plan_qty,
                (SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=r.id
                   AND accept_status='Принято') AS fact_qty
         FROM receipts r
         LEFT JOIN warehouses w ON w.id=r.warehouse_id
         LEFT JOIN contractors sup ON sup.id=r.supplier_id
         WHERE r.status IN ($in)
         ORDER BY r.plan_delivery_date IS NULL, r.plan_delivery_date ASC, r.id DESC
         LIMIT 100"
    );
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $plan = (float)($r['plan_qty'] ?? 0);
        $fact = (float)($r['fact_qty'] ?? 0);
        $r['pct'] = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    }
    tsd_json(['rows' => $rows, 'token_hint' => 'передай ?token= на ТСД']);
}

// --- Карточка задания: шапка + план/факт по каждой позиции ---
if ($action === 'task') {
    $id = (int)($_GET['id'] ?? 0);
    $h = $pdo->prepare("SELECT r.*, sup.name AS supplier, w.name AS warehouse FROM receipts r LEFT JOIN contractors sup ON sup.id=r.supplier_id LEFT JOIN warehouses w ON w.id=r.warehouse_id WHERE r.id=:id");
    $h->execute([':id' => $id]);
    $head = $h->fetch();
    if (!$head) {
        tsd_json(['error' => 'Поступление не найдено'], 404);
    }
    $items = $pdo->prepare(
        "SELECT i.id, i.line_no, i.code, i.sku, i.name, i.qty AS plan_qty,
                COALESCE((SELECT SUM(o.qty) FROM receipt_operations o WHERE o.receipt_id=i.receipt_id
                   AND o.sku=i.sku AND o.accept_status='Принято'),0) AS fact_qty
         FROM receipt_items i WHERE i.receipt_id=:id ORDER BY i.line_no, i.id"
    );
    $items->execute([':id' => $id]);
    $pr = tsd_progress($pdo, $id);
    tsd_json(['head' => $head, 'items' => $items->fetchAll(), 'progress' => $pr]);
}

// --- Вход по PIN с ТСД: POST ?action=login {pin} -> {id, fio} ---
if ($action === 'login') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim((string)($b['pin'] ?? $_GET['pin'] ?? ''));
    if ($pin === '') {
        tsd_json(['error' => 'Введи PIN (4 цифры)'], 422);
    }
    $st = $pdo->prepare("SELECT id, fio, role FROM users WHERE pin=:p LIMIT 1");
    $st->execute([':p' => $pin]);
    $u = $st->fetch();
    if (!$u) {
        tsd_json(['error' => 'Неверный PIN'], 401);
    }
    tsd_json(['ok' => true, 'id' => (int)$u['id'], 'fio' => $u['fio'], 'role' => $u['role']]);
}

// --- Приемщики для выбора на ТСД (без PIN в выдаче) ---
if ($action === 'receivers') {
    $rows = $pdo->query("SELECT id, fio FROM users ORDER BY id LIMIT 50")->fetchAll();
    tsd_json(['rows' => $rows]);
}

// --- Скан: code = штрихкод/sku/код; qty по умолчанию 1 ---
if ($action === 'scan' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $rid = (int)($b['receipt_id'] ?? $_GET['id'] ?? 0);
    $code = trim((string)($b['code'] ?? $b['sku'] ?? $b['barcode'] ?? ''));
    $qty = (float)($b['qty'] ?? 1);
    $pallet = trim((string)($b['pallet'] ?? ''));
    $serial = trim((string)($b['serial'] ?? ''));
    $marking = trim((string)($b['marking'] ?? ''));
    $receiverId = (int)($b['receiver_id'] ?? 0) ?: null;

    if ($rid <= 0 || $code === '') {
        tsd_json(['error' => 'нужны receipt_id и code (штрихкод/артикул)'], 422);
    }
    if ($qty <= 0 || $qty > 100000) {
        tsd_json(['error' => 'неверное qty'], 422);
    }
    $rh = $pdo->prepare("SELECT id, status, doc_no FROM receipts WHERE id=:id");
    $rh->execute([':id' => $rid]);
    $head = $rh->fetch();
    if (!$head) {
        tsd_json(['error' => 'Поступление не найдено'], 404);
    }
    if (!in_array($head['status'], array_merge(TSD_WORK_STATUSES, ['expected']), true)) {
        tsd_json(['error' => 'Документ в статусе ' . $head['status'] . ' — приемка запрещена'], 409);
    }

    // Поиск позиции плана: сначала точное по sku/code, потом LIKE.
    // При нескольких партиях одного артикула строка выбирается по партии
    // (иначе line_no операции указывал бы на чужую партию).
    $it = $pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id=:r AND (sku=:c OR code=:c) LIMIT 1");
    $it->execute([':r' => $rid, ':c' => $code]);
    $item = $it->fetch();
    if (!$item) {
        $like = $pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id=:r AND (sku LIKE :l OR code LIKE :l OR name LIKE :l) LIMIT 1");
        $like->execute([':r' => $rid, ':l' => '%' . $code . '%']);
        $item = $like->fetch();
    }
    $excess = false;
    if (!$item) {
        // Сверх плана: фиксируем как расхождение, но не теряем скан.
        $excess = true;
        $sku = $code;
        $itemId = null;
    } else {
        $sku = $item['sku'];
        $itemId = (int)$item['id'];
    }

    // Дубли серийников/марок отбиваем сразу (повторный скан).
    if ($serial !== '') {
        $d = $pdo->prepare("SELECT id FROM receipt_flags WHERE receipt_id=:r AND serial_no=:s LIMIT 1");
        $d->execute([':r' => $rid, ':s' => $serial]);
        if ($d->fetch()) {
            tsd_json(['error' => 'Серийник уже отсканирован: ' . $serial], 409);
        }
    }
    if ($marking !== '') {
        $d = $pdo->prepare("SELECT id FROM receipt_flags WHERE receipt_id=:r AND marking_code=:m LIMIT 1");
        $d->execute([':r' => $rid, ':m' => $marking]);
        if ($d->fetch()) {
            tsd_json(['error' => 'Марка уже отсканирована'], 409);
        }
    }
    // Сверка с системой: если на документ загружен файл марок — марка обязана быть
    // в реестре и относиться к этому артикулу. Без файла марок — старое поведение.
    $regCnt = 0;
    if ($marking !== '') {
        try {
            $rc = $pdo->prepare("SELECT COUNT(*) FROM receipt_marks WHERE receipt_id=:r");
            $rc->execute([':r' => $rid]);
            $regCnt = (int)$rc->fetchColumn();
        } catch (Throwable) {
            $regCnt = 0;
        }
        if ($regCnt > 0) {
            $rg = $pdo->prepare("SELECT sku FROM receipt_marks WHERE receipt_id=:r AND marking_code=:m LIMIT 1");
            $rg->execute([':r' => $rid, ':m' => $marking]);
            $regSku = $rg->fetchColumn();
            if ($regSku === false || $regSku === null) {
                tsd_json(['error' => 'Марки нет в системе: код не найден в файле марок документа'], 404);
            }
            if ($regSku !== $sku) {
                tsd_json(['error' => 'Марка не подходит: относится к товару ' . $regSku], 422);
            }
        }
    }

    // Партия поставщика: операция должна попасть в границы своей партии,
    // иначе факт по партиям (sku_fact с фильтром по партии) не увидит её
    // и приёмка в ТСД-Приходе разойдётся с этим сканом.
    $batch = null;
    if (array_key_exists('batch', $b) || array_key_exists('supplier_batch', $b)) {
        $bv = $b['batch'] ?? $b['supplier_batch'] ?? null;
        $batch = ($bv === null) ? null : trim((string)$bv);
    }
    $knownBatches = [];
    try {
        require_once __DIR__ . '/../shared/ReceiptClose.php';
        $knownBatches = array_column(sku_batches($pdo, $rid, $sku), 'batch');
    } catch (Throwable) {
    }
    if (count($knownBatches) > 1 && ($batch === null || !in_array($batch, $knownBatches, true))) {
        tsd_json(['error' => 'Выбери партию поставщика', 'need_batch' => true, 'batches' => $knownBatches], 422);
    }
    if ($batch === null) {
        $batch = count($knownBatches) === 1 ? (string)$knownBatches[0] : '';
    }
    // Строка позиции уточняется по партии: у одного артикула могут быть
    // несколько строк с разными партиями.
    if ($itemId && count($knownBatches) > 1) {
        try {
            $bi = $pdo->prepare(
                "SELECT id, line_no FROM receipt_items WHERE receipt_id=:r AND sku=:s
                 AND COALESCE(supplier_batch,'') = :b ORDER BY id LIMIT 1"
            );
            $bi->execute([':r' => $rid, ':s' => $sku, ':b' => (string)$batch]);
            if ($biRow = $bi->fetch(PDO::FETCH_ASSOC)) {
                $itemId = (int)$biRow['id'];
                $item['line_no'] = (int)($biRow['line_no'] ?? 0);
            }
        } catch (Throwable) {
        }
    }

    $pdo->beginTransaction();
    try {
        if ($regCnt > 0 && $marking !== '') {
            $pdo->prepare("UPDATE receipt_marks SET status='accepted', accepted_at=datetime('now') WHERE receipt_id=:r AND marking_code=:m")
                ->execute([':r' => $rid, ':m' => $marking]);
        }
        try {
            $pdo->prepare(
                "INSERT INTO receipt_operations (receipt_id,line_no,sku,supplier_batch,accept_status,accept_date,receiver_id,pallet,sku_qty,qty)
                 VALUES (:r,:ln,:sku,:b,'Принято',datetime('now'),:rec,:pal,1,:q)"
            )->execute([':r' => $rid, ':ln' => ($itemId ? (int)($item['line_no'] ?? 0) : 0),
                ':sku' => $sku, ':b' => (string)$batch, ':rec' => $receiverId,
                ':pal' => ($pallet !== '' ? $pallet : null), ':q' => $qty]);
        } catch (Throwable) {
            // Старая БД без колонки supplier_batch.
            $pdo->prepare(
                "INSERT INTO receipt_operations (receipt_id,line_no,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty)
                 VALUES (:r,:ln,:sku,'Принято',datetime('now'),:rec,:pal,1,:q)"
            )->execute([':r' => $rid, ':ln' => ($itemId ? (int)($item['line_no'] ?? 0) : 0),
                ':sku' => $sku, ':rec' => $receiverId,
                ':pal' => ($pallet !== '' ? $pallet : null), ':q' => $qty]);
        }

        if ($serial !== '' || $marking !== '') {
            $pdo->prepare(
                "INSERT INTO receipt_flags (receipt_id,item_id,flag_type,serial_no,marking_code,sku,pallet,qty,flag_date)
                 VALUES (:r,:i,:t,:s,:m,:sku,:pal,1,datetime('now'))"
            )->execute([
                ':r' => $rid, ':i' => $itemId,
                ':t' => $marking !== '' ? 'marking' : 'serial',
                ':s' => $serial !== '' ? $serial : null,
                ':m' => $marking !== '' ? $marking : null,
                ':sku' => $sku,
                ':pal' => ($pallet !== '' ? $pallet : null),
            ]);
        }

        $pr = tsd_progress($pdo, $rid);
        // Первый скан переводит Готова к приемке -> Приемка автоматически.
        $newStatus = $head['status'];
        if ($head['status'] === 'ready_accept') {
            $newStatus = 'accepting';
        } elseif ($head['status'] === 'expected') {
            $newStatus = 'accepting';
        }
        $hasDiff = ($excess || $pr['dev'] != 0) ? 1 : 0;
        $pdo->prepare(
            "UPDATE receipts SET accepted_qty=:f, accepted_pct=:p, deviation_qty=:d, has_diff=:hd,
                    accept_date=COALESCE(accept_date,datetime('now')),
                    arrival_date=COALESCE(arrival_date,datetime('now')), status=:s WHERE id=:id"
        )->execute([':f' => $pr['fact'], ':p' => $pr['pct'], ':d' => $pr['dev'], ':hd' => $hasDiff, ':s' => $newStatus, ':id' => $rid]);
        $pdo->prepare(
            "INSERT INTO receipt_history (receipt_id,user_id,action,field,old_value,new_value,old_status,new_status)
             VALUES (:r,:u,'accept','Скан ТСД',:old,:new,:os,:ns)"
        )->execute([':r' => $rid, ':u' => $receiverId, ':old' => $code, ':new' => $sku . ' x' . $qty, ':os' => $head['status'], ':ns' => $newStatus]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        tsd_json(['error' => $e->getMessage()], 500);
    }
    // Журнал серийных номеров (модуль 14): приёмка с/н.
    // Пишется один раз — в момент скана; паллета та, на которую с/н приняли.
    if ($serial !== '') {
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            WmsJournal::logSerial(wms_journal_pdo(), [
                'serial_no' => $serial,
                'sku' => $sku,
                'product_name' => (string)($item['name'] ?? ''),
                'op_type' => 'Приход',
                'receipt_doc_no' => (string)($head['doc_no'] ?? ''),
                'pallet' => $pallet,
                'qty' => 1,
                'user_id' => $receiverId,
            ]);
        } catch (Throwable) {
        }
    }
    $pr = tsd_progress($pdo, $rid);
    tsd_json(['ok' => true, 'sku' => $sku, 'excess' => $excess, 'progress' => $pr, 'warn' => $excess ? 'Сверх плана — будет расхождение' : null]);
}

// --- Завершить приемку: accepting -> closed / closed_short ---
// Акты расхождений формируются ТОЛЬКО при закрытии документа (shared/ReceiptClose.php).
if ($action === 'finish' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $rid = (int)($b['receipt_id'] ?? $_GET['id'] ?? 0);
    $receiverId = (int)($b['receiver_id'] ?? 0) ?: null;
    $rh = $pdo->prepare("SELECT status FROM receipts WHERE id=:id");
    $rh->execute([':id' => $rid]);
    $head = $rh->fetch();
    if (!$head) {
        tsd_json(['error' => 'Поступление не найдено'], 404);
    }
    if (!in_array($head['status'], ['accepting', 'ready_accept', 'expected'], true)) {
        tsd_json(['error' => 'Завершить можно только из приемки (сейчас: ' . $head['status'] . ')'], 409);
    }
    $pr = tsd_progress($pdo, $rid);
    // Проброс факта в Номенклатуру (products.qty / stock / movements). Идемпотентно.
    $stock = null;
    try {
        require_once __DIR__ . '/../shared/StockSync.php';
        $stock = wms_push_receipt_to_nomenclature($pdo, wms_nomenclature_pdo(), $rid, $receiverId);
    } catch (Throwable $e) {
        $stock = ['synced' => false, 'error' => $e->getMessage()];
    }
    // Закрытие документа: акты + статус «Закрыто»/«Закрыто с отклонением» + комментарий.
    $closed = null;
    try {
        require_once __DIR__ . '/../shared/ReceiptClose.php';
        $closed = wms_close_receipt($pdo, $rid, $receiverId, 'ТСД');
    } catch (Throwable $e) {
        tsd_json(['error' => $e->getMessage()], 500);
    }
    $pdo->prepare(
        "UPDATE receipts SET accepted_qty=:f, accepted_pct=:p, deviation_qty=:d, has_diff=:hd WHERE id=:id"
    )->execute([':f' => $pr['fact'], ':p' => $pr['pct'], ':d' => $pr['dev'], ':hd' => $pr['dev'] != 0 ? 1 : 0, ':id' => $rid]);
    tsd_json(['ok' => true, 'progress' => tsd_progress($pdo, $rid), 'status' => $closed['status'],
        'status_label' => $closed['status_label'], 'deviation' => $closed['deviation'],
        'acts' => $closed['acts'], 'stock' => $stock]);
}

tsd_json(['error' => 'unknown action: ?action=tasks|task|receivers|scan|finish'], 404);
