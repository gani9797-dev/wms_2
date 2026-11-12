<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
// ТСД-модуль отгрузок (сборка). Пишет в ЕДИНУЮ БД отгрузок
// (03-otgruzki/wms_demo.sqlite через shared/db.php — тот же файл, что модуль 03):
//   tsd_picks (факт сборки: shipment_id, sku, qty, user_id) — новая таблица факта,
//   shipments.readiness (0-100%), shipments.status (assembling -> assembled),
//   shipment_history (action=pick).
// То есть "собрал на ТСД -> сразу видно в Отгрузках".

const TSD_SHIP_STATUSES = ['created', 'assembling', 'assembled', 'packing'];

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

function j(mixed $d, int $c = 200): void
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function db(): PDO
{
    // Единая БД отгрузок (владелец — 03-otgruzki). См. shared/db.php.
    // Тот же файл читает модуль 03 (03-otgruzki/demo_sqlite.php), поэтому
    // сборки с ТСД сразу видны в веб-модуле «Отгрузки» (факт tsd_picks).
    require_once __DIR__ . '/../shared/db.php';
    if (function_exists('wms_use_pgsql') && wms_use_pgsql()) return wms_pg_pdo();
    $pdo = wms_shipments_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_picks (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, sku TEXT NOT NULL, qty REAL NOT NULL DEFAULT 1, user_id INTEGER, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_picks_ship ON tsd_picks(shipment_id)");
    return $pdo;
}

function progress(PDO $pdo, int $sid): array
{
    $plan = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM shipment_items WHERE shipment_id=' . $sid)->fetchColumn();
    $fact = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=' . $sid)->fetchColumn();
    $pct = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    return ['plan' => $plan, 'fact' => $fact, 'pct' => $pct];
}

if (!tsd_token_ok()) {
    j(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
}

$pdo = db();
$action = $_GET['action'] ?? 'tasks';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Задания на сборку
if ($action === 'tasks') {
    $in = "'" . implode("','", TSD_SHIP_STATUSES) . "'";
    $rows = $pdo->query(
        "SELECT s.id, s.doc_no, s.status, s.consignee, s.vehicle, s.gate, w.name AS warehouse,
                (SELECT COALESCE(SUM(qty),0) FROM shipment_items WHERE shipment_id=s.id) AS plan_qty,
                (SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=s.id) AS fact_qty
         FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id
         WHERE s.status IN ($in) ORDER BY s.plan_shipment_date IS NULL, s.plan_shipment_date ASC, s.id DESC LIMIT 100"
    )->fetchAll();
    foreach ($rows as &$r) {
        $plan = (float)($r['plan_qty'] ?? 0);
        $fact = (float)($r['fact_qty'] ?? 0);
        $r['pct'] = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    }
    j(['rows' => $rows]);
}

// Карточка: шапка + позиции план/факт
if ($action === 'task') {
    $id = (int)($_GET['id'] ?? 0);
    $h = $pdo->prepare('SELECT s.*, w.name AS warehouse FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.id=:id');
    $h->execute([':id' => $id]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Отгрузка не найдена'], 404);
    }
    $items = $pdo->prepare(
        'SELECT i.id, i.code, i.sku, i.name, i.qty AS plan_qty,
                COALESCE((SELECT SUM(p.qty) FROM tsd_picks p WHERE p.shipment_id=i.shipment_id AND p.sku=i.sku),0) AS fact_qty
         FROM shipment_items i WHERE i.shipment_id=:id ORDER BY i.id'
    );
    $items->execute([':id' => $id]);
    j(['head' => $head, 'items' => $items->fetchAll(), 'progress' => progress($pdo, $id)]);
}

// Скан сборки: code=sku/code, qty по умолч. 1
if ($action === 'scan' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? $_GET['id'] ?? 0);
    $code = trim((string)($b['code'] ?? $b['sku'] ?? ''));
    $qty = (float)($b['qty'] ?? 1);
    $userId = (int)($b['user_id'] ?? 0) ?: null;
    if ($sid <= 0 || $code === '') {
        j(['error' => 'нужны shipment_id и code'], 422);
    }
    if ($qty <= 0 || $qty > 100000) {
        j(['error' => 'неверное qty'], 422);
    }
    $h = $pdo->prepare('SELECT id, status FROM shipments WHERE id=:id');
    $h->execute([':id' => $sid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Отгрузка не найдена'], 404);
    }
    if (!in_array($head['status'], array_merge(TSD_SHIP_STATUSES, ['packing', 'on_check']), true)) {
        j(['error' => 'Документ в статусе ' . $head['status'] . ' — сборка запрещена'], 409);
    }
    $it = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id=:s AND (sku=:c OR code=:c) LIMIT 1');
    $it->execute([':s' => $sid, ':c' => $code]);
    $item = $it->fetch();
    if (!$item) {
        $like = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id=:s AND (sku LIKE :l OR code LIKE :l OR name LIKE :l) LIMIT 1');
        $like->execute([':s' => $sid, ':l' => '%' . $code . '%']);
        $item = $like->fetch();
    }
    $excess = !$item;
    $sku = $item['sku'] ?? $code;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO tsd_picks (shipment_id,sku,qty,user_id) VALUES (:s,:sku,:q,:u)')
            ->execute([':s' => $sid, ':sku' => $sku, ':q' => $qty, ':u' => $userId]);
        $pr = progress($pdo, $sid);
        $pdo->prepare('UPDATE shipments SET readiness=:p WHERE id=:id')->execute([':p' => $pr['pct'], ':id' => $sid]);
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (:s,:u,'pick',:o,:n)")
            ->execute([':s' => $sid, ':u' => $userId, ':o' => $code, ':n' => $sku . ' x' . $qty]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'sku' => $sku, 'excess' => $excess, 'progress' => progress($pdo, $sid),
        'warn' => $excess ? 'Сверх задания — проверь позицию' : null]);
}

// Завершить сборку: assembling/created -> assembled
if ($action === 'finish' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? $_GET['id'] ?? 0);
    $userId = (int)($b['user_id'] ?? 0) ?: null;
    $h = $pdo->prepare('SELECT status FROM shipments WHERE id=:id');
    $h->execute([':id' => $sid]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Отгрузка не найдена'], 404);
    }
    if (!in_array($head['status'], ['created', 'assembling'], true)) {
        j(['error' => 'Завершить можно только сборку (сейчас: ' . $head['status'] . ')'], 409);
    }
    $pr = progress($pdo, $sid);
    $pdo->beginTransaction();
    try {
        $nowSql = function_exists('wms_now_sql') ? wms_now_sql($pdo) : 'NOW()';
        $pdo->prepare("UPDATE shipments SET status='assembled', readiness=:p, assembly_end=COALESCE(assembly_end,$nowSql) WHERE id=:id")
            ->execute([':p' => $pr['pct'], ':id' => $sid]);
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (:s,:u,'status',:o,'assembled')")
            ->execute([':s' => $sid, ':u' => $userId, ':o' => $head['status']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        j(['error' => $e->getMessage()], 500);
    }
    j(['ok' => true, 'status' => 'assembled', 'progress' => progress($pdo, $sid)]);
}

// --- «Отгрузить ТС»: список отгрузок, готовых к отгрузке ---
if ($action === 'ship_list') {
    require_once __DIR__ . '/../shared/ShipmentPallets.php';
    $in = "'assembled','packing','packed','on_check','checked','ready'";
    $rows = $pdo->query(
        "SELECT s.id, s.doc_no, s.status, s.consignee, s.vehicle, s.gate, s.loading_type, s.plan_shipment_date, w.name AS warehouse
         FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id
         WHERE s.status IN ($in) ORDER BY s.id DESC LIMIT 50"
    )->fetchAll();
    foreach ($rows as &$r) {
        $pals = wms_ship_pallets_sync($pdo, (int)$r['id']);
        $total = count($pals);
        $shipped = 0;
        foreach ($pals as $p) {
            if (!empty($p['shipped_at'])) {
                $shipped++;
            }
        }
        $r['pallets_total'] = $total;
        $r['pallets_shipped'] = $shipped;
        $r['status_label'] = $r['status'] === 'shipped' ? 'Отгружено' : (string)$r['status'];
    }
    j(['rows' => $rows]);
}

// --- «Отгрузить ТС»: карточка отгрузки (SSCC + статусы) ---
if ($action === 'ship_task') {
    require_once __DIR__ . '/../shared/ShipmentPallets.php';
    $id = (int)($_GET['id'] ?? $_GET['shipment_id'] ?? 0);
    $q = trim((string)($_GET['q'] ?? ''));
    if ($id <= 0 && $q !== '') {
        $h = $pdo->prepare('SELECT id FROM shipments WHERE doc_no=:q LIMIT 1');
        $h->execute([':q' => $q]);
        $id = (int)($h->fetchColumn() ?: 0);
    }
    if ($id <= 0) {
        j(['error' => 'нужен id отгрузки'], 422);
    }
    $h = $pdo->prepare('SELECT s.*, w.name AS warehouse FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.id=:id');
    $h->execute([':id' => $id]);
    $head = $h->fetch();
    if (!$head) {
        j(['error' => 'Отгрузка не найдена'], 404);
    }
    $pallets = wms_ship_pallets_sync($pdo, $id);
    $out = [];
    $shipped = 0;
    foreach ($pallets as $p) {
        $st = wms_ship_pallet_status($p);
        if ($st['code'] === 'shipped') {
            $shipped++;
        }
        $out[] = [
            'id' => (int)$p['id'],
            'sscc' => (string)$p['sscc'],
            'qty' => (float)($p['qty'] ?? 0),
            'status' => $st['code'],
            'status_label' => $st['label'],
            'shipped_at' => $p['shipped_at'],
        ];
    }
    j(['head' => $head, 'pallets' => $out, 'total' => count($out), 'shipped' => $shipped,
        'all_shipped' => count($out) > 0 && $shipped === count($out)]);
}

// --- «Отгрузить ТС»: скан SSCC (цикл по паллетам) ---
if ($action === 'ship_pallet' && $method === 'POST') {
    require_once __DIR__ . '/../shared/ShipmentPallets.php';
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? $_GET['id'] ?? 0);
    $sscc = trim((string)($b['sscc'] ?? $b['code'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($sid <= 0 || $sscc === '') {
        j(['error' => 'нужны shipment_id и sscc'], 422);
    }
    $res = wms_ship_pallet_ship($pdo, $sid, $sscc, $uid, $fio);
    if (!$res['ok']) {
        j(['error' => $res['error'], 'code' => $res['code'] ?? 400], $res['code'] ?? 400);
    }
    j($res);
}

// --- «Отгрузить ТС»: отгрузка валом (списать всю отгрузку) ---
if ($action === 'ship_all' && $method === 'POST') {
    require_once __DIR__ . '/../shared/ShipmentPallets.php';
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? $_GET['id'] ?? 0);
    if ($sid <= 0 && trim((string)($b['doc_no'] ?? '')) !== '') {
        $h = $pdo->prepare('SELECT id FROM shipments WHERE doc_no=:q LIMIT 1');
        $h->execute([':q' => trim((string)$b['doc_no'])]);
        $sid = (int)($h->fetchColumn() ?: 0);
    }
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($sid <= 0) {
        j(['error' => 'нужен shipment_id или doc_no'], 422);
    }
    $res = wms_ship_bulk($pdo, $sid, $uid, $fio);
    if (!$res['ok']) {
        j(['error' => $res['error'], 'code' => $res['code'] ?? 400], $res['code'] ?? 400);
    }
    j($res);
}

// --- Фиксация услуг при отгрузке (стикеровка, паллеты евро/фин) ---
if ($action === 'service_fix_info' && $method === 'GET') {
    require_once __DIR__ . '/../shared/ServiceFixation.php';
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    if ($sid <= 0) {
        j(['error' => 'нужен shipment_id'], 422);
    }
    $h = $pdo->prepare('SELECT * FROM shipments WHERE id=:i');
    $h->execute([':i' => $sid]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        j(['error' => 'Отгрузка не найдена'], 404);
    }
    $bpdo = wms_service_billing_pdo();
    $client = wms_service_client_resolve($bpdo, (int)$head['contractor_id']);
    $euro = 0;
    $fin = 0;
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(ps.pallet_type),''),'euro') AS pt,
                    COUNT(DISTINCT sp.sscc) AS n
               FROM shipment_pallets sp
               LEFT JOIN product_stock ps ON TRIM(ps.pallet)=sp.sscc
              WHERE sp.shipment_id=:s GROUP BY pt"
        );
        $st->execute([':s' => $sid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string)$r['pt'] === 'fin') {
                $fin += (int)$r['n'];
            } else {
                $euro += (int)$r['n'];
            }
        }
    } catch (Throwable) {
    }
    j(['ok' => true, 'shipment' => $head, 'client' => $client,
        'pallets_euro' => $euro, 'pallets_fin' => $fin,
        'events' => wms_service_fix_events($bpdo, (string)$head['doc_no'])]);
}

if ($action === 'service_fix' && $method === 'POST') {
    require_once __DIR__ . '/../shared/ServiceFixation.php';
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? $_GET['id'] ?? 0);
    if ($sid <= 0 && trim((string)($b['doc_no'] ?? '')) !== '') {
        $h = $pdo->prepare('SELECT id FROM shipments WHERE doc_no=:q LIMIT 1');
        $h->execute([':q' => trim((string)$b['doc_no'])]);
        $sid = (int)($h->fetchColumn() ?: 0);
    }
    if ($sid <= 0) {
        j(['error' => 'нужен shipment_id'], 422);
    }
    $h = $pdo->prepare('SELECT * FROM shipments WHERE id=:i');
    $h->execute([':i' => $sid]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        j(['error' => 'Отгрузка не найдена'], 404);
    }
    $bpdo = wms_service_billing_pdo();
    $res = wms_service_fixation_save($bpdo, [
        'contractor_id' => (int)$head['contractor_id'],
        'stage' => 'ship',
        'doc_type' => 'Отгрузка',
        'doc_no' => (string)$head['doc_no'],
        'event_date' => (string)($b['event_date'] ?? date('Y-m-d')),
        'pallets_euro' => (int)($b['pallets_euro'] ?? 0),
        'pallets_fin' => (int)($b['pallets_fin'] ?? 0),
        'sticker_qty' => (int)($b['sticker_qty'] ?? 0),
        'user_id' => (int)($b['user_id'] ?? 0),
        'user_fio' => trim((string)($b['user_fio'] ?? '')),
    ]);
    if (empty($res['ok'])) {
        j(['error' => $res['error'] ?? 'Ошибка фиксации услуг'], 422);
    }
    j($res);
}

j(['error' => 'unknown action: ?action=tasks|task|scan|finish|ship_list|ship_task|ship_pallet|ship_all|service_fix|service_fix_info'], 404);
