<?php
declare(strict_types=1);
// Модуль 3.1 "Заказы": реестр заказов/нарядов на отгрузку.
// Сюда попадают наряды or_XXXXXX из загрузки расхода (дашборд).
// Своей БД нет — работаем с БД отгрузок 03-otgruzki (там же client_orders).
// Открыть: /03.1-zakazy/demo_sqlite.php?action=list
// Карточка: ?action=show&id=1 (шапка + строки + связанные отгрузки)
// Формирование: POST ?action=form {order_ids:[], params:{warehouse_id,...}}

require_once __DIR__ . '/../03-otgruzki/ShipmentService.php';
require_once __DIR__ . '/../shared/db.php';

$pdo = new PDO('sqlite:' . __DIR__ . '/../03-otgruzki/wms_demo.sqlite', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Дотянуть таблицы нарядов, если база старая.
$pdo->exec("CREATE TABLE IF NOT EXISTS client_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, contractor_id INTEGER, consignee TEXT, address TEXT, phone TEXT, status TEXT DEFAULT 'created', warehouse_id INTEGER, total REAL DEFAULT 0, comment TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS client_order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт', qty REAL NOT NULL, prod_date TEXT)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_coi_order ON client_order_items(order_id)");

$service = new ShipmentService($pdo);
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $w = ['1=1'];
    $p = [];
    if (!empty($_GET['search'])) {
        $w[] = '(o.doc_no LIKE :q OR o.consignee LIKE :q OR c.name LIKE :q)';
        $p[':q'] = '%' . trim((string)$_GET['search']) . '%';
    }
    if (!empty($_GET['status'])) {
        $w[] = 'o.status = :st';
        $p[':st'] = (string)$_GET['status'];
    }
    if (!empty($_GET['warehouse_id'])) {
        $w[] = 'o.warehouse_id = :wh';
        $p[':wh'] = (int)$_GET['warehouse_id'];
    }
    $where = 'WHERE ' . implode(' AND ', $w);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM client_orders o LEFT JOIN contractors c ON c.id=o.contractor_id $where");
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $off = ($page - 1) * $perPage;
    $st = $pdo->prepare(
        "SELECT o.id, o.doc_no, o.consignee, c.name AS contractor, o.status,
                o.warehouse_id, w.name AS warehouse, o.total, o.comment, o.created_at,
                (SELECT COUNT(*) FROM client_order_items i WHERE i.order_id=o.id) AS lines,
                (SELECT COALESCE(SUM(qty),0) FROM client_order_items i WHERE i.order_id=o.id) AS qty,
                (SELECT GROUP_CONCAT(s.doc_no, ', ') FROM shipment_orders so
                  JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=o.id) AS shipments
         FROM client_orders o
         LEFT JOIN contractors c ON c.id=o.contractor_id
         LEFT JOIN warehouses w ON w.id=o.warehouse_id
         $where ORDER BY o.id DESC LIMIT :lim OFFSET :off"
    );
    foreach ($p as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $off, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    echo json_encode([
        'rows' => $rows, 'total' => $total,
        'from' => $total ? $off + 1 : 0, 'to' => min($off + $perPage, $total),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'show') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare(
        "SELECT o.*, c.name AS contractor, w.name AS warehouse,
                (SELECT COALESCE(SUM(qty),0) FROM client_order_items i WHERE i.order_id=o.id) AS qty
         FROM client_orders o
         LEFT JOIN contractors c ON c.id=o.contractor_id
         LEFT JOIN warehouses w ON w.id=o.warehouse_id
         WHERE o.id=:id"
    );
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Заказ не найден'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $it = $pdo->prepare('SELECT * FROM client_order_items WHERE order_id=:id ORDER BY id');
    $it->execute([':id' => $id]);
    $row['items'] = $it->fetchAll();
    $sh = $pdo->prepare(
        "SELECT s.id, s.doc_no, s.status, s.plan_shipment_date FROM shipment_orders so
         JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=:id ORDER BY s.id"
    );
    $sh->execute([':id' => $id]);
    $row['shipments'] = $sh->fetchAll();
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'items') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM client_order_items WHERE order_id=:id ORDER BY id');
    $st->execute([':id' => $id]);
    echo json_encode($st->fetchAll(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'refs') {
    echo json_encode([
        'warehouses' => $pdo->query('SELECT id, name FROM warehouses ORDER BY id')->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $nid = $service->formFromOrders((array)($b['order_ids'] ?? []), (array)($b['params'] ?? []), 1);
        http_response_code(201);
        echo json_encode(['id' => $nid], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Удаление заказов через ПКМ (можно несколько). Связанные с отгрузкой — запрет.
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = (array)($b['order_ids'] ?? $b['ids'] ?? []);
    if (!$ids && isset($_GET['id'])) {
        $ids = [(int)$_GET['id']];
    }
    $deleted = [];
    $errors = [];
    foreach (array_values(array_unique(array_map('intval', $ids))) as $oid) {
        if ($oid <= 0) {
            continue;
        }
        try {
            $doc = (string)$pdo->query('SELECT doc_no FROM client_orders WHERE id=' . $oid)->fetchColumn();
            if ($doc === '') {
                throw new RuntimeException('Заказ не найден');
            }
            $linked = [];
            try {
                $ls = $pdo->prepare(
                    "SELECT s.doc_no FROM shipment_orders so JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=:id"
                );
                $ls->execute([':id' => $oid]);
                $linked = $ls->fetchAll(PDO::FETCH_COLUMN);
            } catch (Throwable) {
            }
            if ($linked) {
                throw new RuntimeException($doc . ': уже включён в отгрузку (' . implode(', ', $linked) . ')');
            }
            try {
                $pdo->prepare('DELETE FROM client_order_items WHERE order_id=:id')->execute([':id' => $oid]);
            } catch (Throwable) {
            }
            $pdo->prepare('DELETE FROM client_orders WHERE id=:id')->execute([':id' => $oid]);
            $deleted[] = $doc;
        } catch (Throwable $e) {
            $errors[] = '#' . $oid . ': ' . $e->getMessage();
        }
    }
    if (!$deleted) {
        http_response_code(400);
        echo json_encode(['error' => implode('; ', $errors) ?: 'Нечего удалять'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'columns_get') {
    require_once __DIR__ . '/../shared/UserColumns.php';
    echo json_encode(['user' => wms_columns_user(), 'form' => (string)($_GET['form'] ?? ''), 'cols' => wms_columns_get($pdo, (string)($_GET['form'] ?? ''))], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'columns_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../shared/UserColumns.php';
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        wms_columns_save($pdo, (string)($b['form'] ?? ''), $b['cols'] ?? null);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} else {
    http_response_code(404);
    echo json_encode(['error' => 'unknown action, use ?action=list|show|items|refs&id=1'], JSON_UNESCAPED_UNICODE);
}
