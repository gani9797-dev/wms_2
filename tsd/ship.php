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
        $pdo->prepare("UPDATE shipments SET status='assembled', readiness=:p, assembly_end=COALESCE(assembly_end,datetime('now')) WHERE id=:id")
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

j(['error' => 'unknown action: ?action=tasks|task|scan|finish'], 404);
