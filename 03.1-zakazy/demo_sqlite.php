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
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/ProductLookup.php';

$pdo = wms_shipments_pdo();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

// Дотянуть таблицы нарядов, если база старая (только SQLite; в PostgreSQL схема из db/schema-pg.sql).
if ($isSqlite) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, contractor_id INTEGER, consignee TEXT, address TEXT, phone TEXT, status TEXT DEFAULT 'created', warehouse_id INTEGER, total REAL DEFAULT 0, comment TEXT, created_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, wms_code TEXT, barcode TEXT, scan_serial INTEGER NOT NULL DEFAULT 0, scan_chz INTEGER NOT NULL DEFAULT 0, owner_contractor_id INTEGER, owner_name TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт', qty REAL NOT NULL, supplier_batch TEXT, prod_date TEXT)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coi_order ON client_order_items(order_id)");
}

$service = new ShipmentService($pdo);
$action = $_GET['action'] ?? 'list';

/** Сколько по строке заказа уже отобрано (факт ТСД). */
function order_item_processed(PDO $pdo, int $orderId, string $sku): float
{
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(SUM(p.qty),0)
               FROM shipment_orders so
               JOIN tsd_picks p ON p.shipment_id = so.shipment_id
              WHERE so.order_id = :id AND p.sku = :sku"
        );
        $st->execute([':id' => $orderId, ':sku' => $sku]);
        return round((float)$st->fetchColumn(), 3);
    } catch (Throwable) {
        return 0.0;
    }
}

if (in_array($action, ['create_order', 'delete', 'item_add', 'item_update', 'item_delete'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wms_auth_require_permission('doc_edit_order');
}
header('Content-Type: application/json; charset=utf-8');

if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = wms_per_page($_GET['per_page'] ?? 20, 20, 100, 1);
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
    // Агрегат номеров отгрузок: переносимый синтаксис (sqlite / pgsql / mysql).
    $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $shipAgg = $drv === 'pgsql'
        ? "(SELECT STRING_AGG(s.doc_no::text, ', ') FROM shipment_orders so JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=o.id)"
        : ($drv === 'sqlite'
            ? "(SELECT GROUP_CONCAT(s.doc_no, ', ') FROM shipment_orders so JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=o.id)"
            : "(SELECT GROUP_CONCAT(s.doc_no SEPARATOR ', ') FROM shipment_orders so JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=o.id)");
    // Сортировка по любому столбцу реестра (А→Я / Я→А).
    $sortMap = [
        'doc_no' => ['o.doc_no', 't'], 'created_at' => ['o.created_at', 'd'],
        'consignee' => ['o.consignee', 't'], 'warehouse' => ['w.name', 't'],
        'status' => ['o.status', 't'],
        'lines' => ['(SELECT COUNT(*) FROM client_order_items i WHERE i.order_id=o.id)', 'n'],
        'qty' => ['(SELECT COALESCE(SUM(i.qty),0) FROM client_order_items i WHERE i.order_id=o.id)', 'n'],
        'shipments' => [$shipAgg, 't'],
        'id' => ['o.id', 'n'],
    ];
    $orderBy = wms_order_by($pdo, (string)($_GET['sort'] ?? ''), (string)($_GET['dir'] ?? 'DESC'), $sortMap, 'o.id', 'n');
    $st = $pdo->prepare(
        "SELECT o.id, o.doc_no, o.consignee, c.name AS contractor, o.status,
                o.warehouse_id, w.name AS warehouse, o.total, o.comment, o.created_at,
                (SELECT COUNT(*) FROM client_order_items i WHERE i.order_id=o.id) AS lines,
                (SELECT COALESCE(SUM(qty),0) FROM client_order_items i WHERE i.order_id=o.id) AS qty,
                $shipAgg AS shipments
         FROM client_orders o
         LEFT JOIN contractors c ON c.id=o.contractor_id
         LEFT JOIN warehouses w ON w.id=o.warehouse_id
         $where ORDER BY $orderBy LIMIT :lim OFFSET :off"
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
    foreach ($row['items'] as &$line) {
        $line['processed_qty'] = order_item_processed($pdo, $id, (string)$line['sku']);
    }
    unset($line);
    $sh = $pdo->prepare(
        "SELECT s.id, s.doc_no, s.status, s.plan_shipment_date FROM shipment_orders so
         JOIN shipments s ON s.id=so.shipment_id WHERE so.order_id=:id ORDER BY s.id"
    );
    $sh->execute([':id' => $id]);
    $row['shipments'] = $sh->fetchAll();
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'product_lookup') {
    $q = trim((string)($_GET['q'] ?? $_GET['sku'] ?? $_GET['barcode'] ?? ''));
    echo json_encode(['product' => wms_product_lookup($q)], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'items') {
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM client_order_items WHERE order_id=:id ORDER BY id');
    $st->execute([':id' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$line) {
        $line['processed_qty'] = order_item_processed($pdo, $id, (string)$line['sku']);
    }
    unset($line);
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'item_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $oid = (int)($_GET['id'] ?? $b['order_id'] ?? 0);
    try {
        $chk = $pdo->prepare('SELECT id FROM client_orders WHERE id=:id');
        $chk->execute([':id' => $oid]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Заказ не найден');
        }
        $sku = trim((string)($b['sku'] ?? ''));
        $name = trim((string)($b['name'] ?? ''));
        $qty = (float)str_replace(',', '.', trim((string)($b['qty'] ?? 0)));
        $unit = trim((string)($b['unit'] ?? '')) ?: 'шт';
        if ($sku === '') {
            throw new RuntimeException('Укажите артикул');
        }
        if ($name === '') {
            throw new RuntimeException('Укажите наименование');
        }
        if ($qty <= 0) {
            throw new RuntimeException('Количество должно быть больше нуля');
        }
        $prodDate = trim((string)($b['prod_date'] ?? ''));
        $wmsCode = trim((string)($b['wms_code'] ?? '')) ?: null;
        $barcode = trim((string)($b['barcode'] ?? '')) ?: null;
        $scanSerial = !empty($b['scan_serial']) ? 1 : 0;
        $scanChz = !empty($b['scan_chz']) ? 1 : 0;
        $ownerId = (int)($b['owner_contractor_id'] ?? 0) ?: null;
        $ownerName = trim((string)($b['owner_name'] ?? '')) ?: null;
        $pdo->prepare(
            'INSERT INTO client_order_items (order_id, wms_code, barcode, scan_serial, scan_chz, owner_contractor_id, owner_name, sku, name, unit, qty, supplier_batch, prod_date)
             VALUES (:oid, :wms_code, :barcode, :scan_serial, :scan_chz, :owner_id, :owner_name, :sku, :name, :unit, :qty, :batch, :prod)'
        )->execute([
            ':oid' => $oid, ':wms_code' => $wmsCode, ':barcode' => $barcode,
            ':scan_serial' => $scanSerial, ':scan_chz' => $scanChz,
            ':owner_id' => $ownerId, ':owner_name' => $ownerName,
            ':sku' => $sku, ':name' => $name, ':unit' => $unit, ':qty' => $qty,
            ':batch' => trim((string)($b['supplier_batch'] ?? '')) ?: null,
            ':prod' => $prodDate !== '' ? $prodDate : null,
        ]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'item_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $oid = (int)($_GET['id'] ?? $b['order_id'] ?? 0);
    $iid = (int)($b['item_id'] ?? 0);
    try {
        $chk = $pdo->prepare('SELECT id FROM client_orders WHERE id=:id');
        $chk->execute([':id' => $oid]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Заказ не найден');
        }
        $it = $pdo->prepare('SELECT * FROM client_order_items WHERE id=:iid AND order_id=:oid');
        $it->execute([':iid' => $iid, ':oid' => $oid]);
        $item = $it->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Строка не найдена');
        }
        $processed = order_item_processed($pdo, $oid, (string)$item['sku']);
        $qty = (float)str_replace(',', '.', trim((string)($b['qty'] ?? 0)));
        if ($qty <= 0) {
            throw new RuntimeException('Количество должно быть больше нуля');
        }
        if ($qty + 0.0001 < $processed) {
            throw new RuntimeException('Нельзя указать меньше обработанного: ' . $processed);
        }
        $pdo->prepare('UPDATE client_order_items SET qty=:q WHERE id=:iid AND order_id=:oid')
            ->execute([':q' => $qty, ':iid' => $iid, ':oid' => $oid]);
        echo json_encode(['ok' => true, 'item' => ['id' => $iid, 'qty' => $qty, 'processed_qty' => $processed]], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'item_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $oid = (int)($_GET['id'] ?? $b['order_id'] ?? 0);
    $iid = (int)($b['item_id'] ?? 0);
    try {
        $chk = $pdo->prepare('SELECT id FROM client_orders WHERE id=:id');
        $chk->execute([':id' => $oid]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException('Заказ не найден');
        }
        $it = $pdo->prepare('SELECT * FROM client_order_items WHERE id=:iid AND order_id=:oid');
        $it->execute([':iid' => $iid, ':oid' => $oid]);
        $item = $it->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new RuntimeException('Строка не найдена');
        }
        $processed = order_item_processed($pdo, $oid, (string)$item['sku']);
        if ($processed > 0) {
            throw new RuntimeException('Нельзя удалить строку: уже обработано ' . $processed);
        }
        $st = $pdo->prepare('DELETE FROM client_order_items WHERE id=:iid AND order_id=:oid');
        $st->execute([':iid' => $iid, ':oid' => $oid]);
        if ($st->rowCount() === 0) {
            throw new RuntimeException('Строка не найдена');
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'refs') {
    echo json_encode([
        'warehouses' => $pdo->query('SELECT id, name FROM warehouses ORDER BY id')->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'create_order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $consignee = trim((string)($b['consignee'] ?? ''));
        if ($consignee === '') {
            throw new RuntimeException('Укажите грузополучателя');
        }
        $warehouseId = (int)($b['warehouse_id'] ?? 0) ?: 1;
        $contractorId = (int)($b['contractor_id'] ?? 0) ?: null;
        $docNo = trim((string)($b['doc_no'] ?? ''));
        $pdo->beginTransaction();
        try {
            if ($docNo === '') {
                for ($i = 0; $i < 20; $i++) {
                    $try = 'or_' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $q = $pdo->prepare('SELECT 1 FROM client_orders WHERE doc_no=:n LIMIT 1');
                    $q->execute([':n' => $try]);
                    if (!$q->fetchColumn()) {
                        $docNo = $try;
                        break;
                    }
                }
            }
            if ($docNo === '') {
                throw new RuntimeException('Не удалось сформировать номер наряда');
            }
            $pdo->prepare(
                "INSERT INTO client_orders (doc_no, contractor_id, consignee, address, phone, status, warehouse_id, total, comment)
                 VALUES (:n, :c, :csg, :addr, :ph, 'created', :wh, 0, :cmt)"
            )->execute([
                ':n' => $docNo, ':c' => $contractorId, ':csg' => $consignee,
                ':addr' => trim((string)($b['address'] ?? '')) ?: null,
                ':ph' => trim((string)($b['phone'] ?? '')) ?: null,
                ':wh' => $warehouseId,
                ':cmt' => trim((string)($b['comment'] ?? '')) ?: null,
            ]);
            $oid = (int)$pdo->lastInsertId();
            $sku = trim((string)($b['sku'] ?? ''));
            $name = trim((string)($b['name'] ?? ''));
            $qty = (float)str_replace(',', '.', trim((string)($b['qty'] ?? 0)));
            if ($sku !== '' || $name !== '' || $qty > 0) {
                if ($sku === '' || $name === '' || $qty <= 0) {
                    throw new RuntimeException('Для первой строки укажите артикул, наименование и количество');
                }
                $prodDate = trim((string)($b['prod_date'] ?? ''));
                $lookup = $sku !== '' ? wms_product_lookup($sku) : null;
                $wmsCode = trim((string)($b['wms_code'] ?? '')) ?: (string)($lookup['wms_code'] ?? '');
                $barcode = trim((string)($b['barcode'] ?? '')) ?: (string)($lookup['barcode'] ?? '');
                $scanSerial = !empty($b['scan_serial']) ? 1 : (int)($lookup['scan_serial_out'] ?? 0);
                $scanChz = !empty($b['scan_chz']) ? 1 : (int)($lookup['scan_chz'] ?? 0);
                $ownerId = (int)($b['owner_contractor_id'] ?? 0) ?: (int)($lookup['owner_contractor_id'] ?? 0);
                $ownerName = trim((string)($b['owner_name'] ?? '')) ?: (string)($lookup['owner_name'] ?? '');
                $pdo->prepare(
                    'INSERT INTO client_order_items (order_id, wms_code, barcode, scan_serial, scan_chz, owner_contractor_id, owner_name, sku, name, unit, qty, supplier_batch, prod_date)
                     VALUES (:oid, :wms_code, :barcode, :scan_serial, :scan_chz, :owner_id, :owner_name, :sku, :name, :unit, :qty, :batch, :prod)'
                )->execute([
                    ':oid' => $oid, ':wms_code' => $wmsCode ?: null, ':barcode' => $barcode ?: null,
                    ':scan_serial' => $scanSerial, ':scan_chz' => $scanChz,
                    ':owner_id' => $ownerId ?: null, ':owner_name' => $ownerName ?: null,
                    ':sku' => $sku, ':name' => $name,
                    ':unit' => trim((string)($b['unit'] ?? '')) ?: 'шт',
                    ':qty' => $qty,
                    ':batch' => trim((string)($b['supplier_batch'] ?? '')) ?: null,
                    ':prod' => $prodDate !== '' ? $prodDate : null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        echo json_encode(['ok' => true, 'id' => $oid, 'doc_no' => $docNo], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
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
