<?php
declare(strict_types=1);
// Демо без MySQL: SQLite + те же классы реестра.
// Запуск: php -S localhost:8000 demo_sqlite.php  (из папки shipments)
// Открыть: http://localhost:8000/demo_sqlite.php?action=list
// Вкладки: ?action=orders|items|documents|history|flags&id=1
// Мастер: ?action=candidates (шаг1), POST ?action=form (шаг2)

require_once __DIR__ . '/ShipmentStatus.php';
require_once __DIR__ . '/ShipmentFilter.php';
require_once __DIR__ . '/ShipmentRepository.php';
require_once __DIR__ . '/ShipmentService.php';
require_once __DIR__ . '/../shared/CsvUpload.php';
require_once __DIR__ . '/../shared/ShipmentExcel.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Counterparties.php';

$dbFile = wms_shipments_db_file();
$pdo = wms_shipments_pdo();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

// --- Схема SQLite (упрощенная, для демо; прод — schema.sql на MySQL) ---
// Только SQLite: в PostgreSQL схема создаётся из db/schema-pg.sql, сиды — миграцией.
if ($isSqlite):
$pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS contractors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
// users — единый справочник сотрудников (shared/wms_users.sqlite), подключён в shared/db.php.
$pdo->exec("CREATE TABLE IF NOT EXISTS client_orders (id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, contractor_id INTEGER, consignee TEXT, address TEXT, phone TEXT, status TEXT DEFAULT 'created', warehouse_id INTEGER, total REAL DEFAULT 0, comment TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS client_order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, wms_code TEXT, barcode TEXT, scan_serial INTEGER NOT NULL DEFAULT 0, scan_chz INTEGER NOT NULL DEFAULT 0, owner_contractor_id INTEGER, owner_name TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт', qty REAL NOT NULL, supplier_batch TEXT, prod_date TEXT)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_coi_order ON client_order_items(order_id)");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipments (
  id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, doc_date TEXT NOT NULL,
  order_id INTEGER, contractor_id INTEGER NOT NULL, consignee TEXT, contract_no TEXT, warehouse_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'assembling', total REAL DEFAULT 0, vat_rate INTEGER DEFAULT 20,
  vat_sum REAL DEFAULT 0, total_with_vat REAL DEFAULT 0, paid REAL DEFAULT 0, debt REAL DEFAULT 0,
  items_count INTEGER DEFAULT 0, sku_count INTEGER DEFAULT 0, qty_total REAL DEFAULT 0,
  plan_shipment_date TEXT, fact_shipment_date TEXT,
  plan_delivery_date TEXT, fact_delivery_date TEXT,
  vehicle_arrival TEXT, vehicle_departure TEXT, loading_date TEXT,
  assembly_start TEXT, assembly_end TEXT, packing_start TEXT, packing_end TEXT, check_start TEXT, check_end TEXT,
  vehicle TEXT, gate TEXT, gates_extra TEXT, loading_type TEXT, delivery_type TEXT, delivery_method TEXT,
  priority TEXT DEFAULT 'normal', loader_id INTEGER,
  volume REAL DEFAULT 0, weight REAL DEFAULT 0, cargo_places INTEGER DEFAULT 0,
  has_diff INTEGER DEFAULT 0, readiness INTEGER DEFAULT 0,
  recommended_pick TEXT, launched_pick TEXT, need_check INTEGER DEFAULT 0, need_pack INTEGER DEFAULT 0,
  is_kgt INTEGER DEFAULT 0, is_danger INTEGER DEFAULT 0, serial_scan INTEGER DEFAULT 0, marking_scan INTEGER DEFAULT 0,
  is_crossdock INTEGER DEFAULT 0,
  ttn TEXT, responsible_id INTEGER, is_posted INTEGER DEFAULT 0, comment TEXT,
  created_by INTEGER, created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, order_id INTEGER,
  code TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
  qty REAL NOT NULL, price REAL NOT NULL, \"sum\" REAL NOT NULL,
  check_label TEXT, warehouse_id INTEGER, comment TEXT, supplier_batch TEXT)");
try {
    $has = false;
    foreach ($pdo->query("PRAGMA table_info(shipment_items)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['name'] ?? '') === 'prod_date') { $has = true; break; }
    }
    if (!$has) $pdo->exec("ALTER TABLE shipment_items ADD COLUMN prod_date TEXT");
} catch (Throwable) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, user_id INTEGER,
  user_fio TEXT, position TEXT, action TEXT NOT NULL, field TEXT,
  old_value TEXT, new_value TEXT, old_status TEXT, new_status TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, order_id INTEGER NOT NULL,
  status TEXT, qty_total REAL DEFAULT 0, collected INTEGER DEFAULT 0, checked INTEGER DEFAULT 0,
  packed INTEGER DEFAULT 0, shipped INTEGER DEFAULT 0,
  sum_novat REAL DEFAULT 0, vat REAL DEFAULT 0, sum_vat REAL DEFAULT 0,
  UNIQUE(shipment_id, order_id))");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, name TEXT NOT NULL,
  format TEXT DEFAULT 'pdf', pages INTEGER DEFAULT 1, file_path TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_flags (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, order_id INTEGER, item_id INTEGER,
  flag_type TEXT NOT NULL, serial_no TEXT, marking_code TEXT, aggregation TEXT, stock_left INTEGER,
  created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS tsd_picks (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, sku TEXT NOT NULL, qty REAL NOT NULL DEFAULT 1, user_id INTEGER, sscc TEXT, created_at TEXT DEFAULT (datetime('now')))");
// Реестр марок честного знака по отгрузке: expected -> accepted -> circulated.
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_marks (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, sku TEXT NOT NULL,
  marking_code TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'expected', accepted_at TEXT,
  created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_shipment_mark ON shipment_marks(shipment_id, marking_code)");
// Лог операций сортировки отгрузки (ТСД): только идентификаторы.
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_sort_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, pallet_id TEXT,
  pallet_created_at TEXT, user_id INTEGER, item_id INTEGER, serial_no TEXT, marking_code TEXT,
  qty REAL NOT NULL DEFAULT 1, operation TEXT NOT NULL DEFAULT 'sort', reason TEXT,
  created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ssl_shipment ON shipment_sort_log(shipment_id)");

// Миграция старой демо-БД: добавить колонки, если их нет
$ensure = function (string $table, string $col, string $ddl) use ($pdo) {
    $cols = [];
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[] = $r['name'];
    }
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $ddl");
    }
};
foreach ([
    ['shipments', 'consignee', 'consignee TEXT'],
    ['shipments', 'vehicle', 'vehicle TEXT'],
    ['shipments', 'gate', 'gate TEXT'],
    ['shipments', 'loading_type', 'loading_type TEXT'],
    ['shipments', 'priority', "priority TEXT DEFAULT 'normal'"],
    ['shipments', 'loader_id', 'loader_id INTEGER'],
    ['shipments', 'volume', 'volume REAL DEFAULT 0'],
    ['shipments', 'weight', 'weight REAL DEFAULT 0'],
    ['shipments', 'cargo_places', 'cargo_places INTEGER DEFAULT 0'],
    ['shipments', 'readiness', 'readiness INTEGER DEFAULT 0'],
    ['shipments', 'vehicle_arrival', 'vehicle_arrival TEXT'],
    ['shipments', 'vehicle_departure', 'vehicle_departure TEXT'],
    ['shipments', 'assembly_start', 'assembly_start TEXT'],
    ['shipments', 'packing_start', 'packing_start TEXT'],
    ['shipments', 'check_start', 'check_start TEXT'],
    ['shipments', 'sku_count', 'sku_count INTEGER DEFAULT 0'],
    ['shipments', 'qty_total', 'qty_total REAL DEFAULT 0'],
    ['shipments', 'is_crossdock', 'is_crossdock INTEGER DEFAULT 0'],
    ['shipment_items', 'code', 'code TEXT'],
    ['shipment_items', 'order_id', 'order_id INTEGER'],
    ['shipment_items', 'check_label', 'check_label TEXT'],
    ['shipment_items', 'unit', "unit TEXT DEFAULT 'шт'"],
    ['tsd_picks', 'sscc', 'sscc TEXT'],
] as [$t, $c, $ddl]) {
    try {
        $ensure($t, $c, $ddl);
    } catch (Throwable $e) {
    }
}

// --- Сид из макета, если пусто ---
if ((int)$pdo->query("SELECT COUNT(*) FROM shipments")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO warehouses (id,name) VALUES (1,'Основной склад')");
    $pdo->exec("INSERT INTO contractors (id,name) VALUES (1,'ООО \"Ромашка\"'),(2,'ИП Савинов М.Г.'),(3,'ООО «СпортОпт»'),(4,'ИП Петров А.С.')");
    // Сотрудники — в общей базе (shared/wms_users.sqlite), сиды там.
    // Заказы TML* из макета вкладки Заказы (9)
    $orders = [
        [1, 'TML317889621', 1, 'Почта России', 'г. Москва, ул. Примерная, 1', '+7 (900) 000-00-01', 'created', 1, 25400],
        [2, 'TML317889622', 1, 'Почта России', '', '', 'created', 1, 12500],
        [3, 'TML317889623', 2, 'Почта России', '', '', 'created', 1, 12500],
        [4, 'TML317889624', 2, 'Почта России', '', '', 'created', 1, 12500],
        [5, 'TML317889625', 1, 'Почта России', '', '', 'created', 1, 12500],
        [6, 'TML317889626', 1, 'Почта России', '', '', 'created', 1, 12500],
        [7, 'TML317889627', 1, 'Почта России', '', '', 'created', 1, 12500],
        [8, 'TML317889628', 2, 'Почта России', '', '', 'created', 1, 12500],
        [9, 'TML317889629', 2, 'Почта России', '', '', 'created', 1, 12500],
    ];
    $st = $pdo->prepare("INSERT INTO client_orders (id,doc_no,contractor_id,consignee,address,phone,status,warehouse_id,total,created_at) VALUES (?,?,?,?,?,?,?,?,?,datetime('now'))");
    foreach ($orders as $o) {
        $st->execute($o);
    }
    // Реестр из макета: S-205107, S-205106, S-205105... + S-205108
    $rows = [
        // doc_no, doc_date, order_id, contractor, consignee, contract, wh, status, total, total_with_vat, paid, debt, items, sku, qty, plan, fact, vehicle, gate, loading, priority, readiness
        ['S-205107', '2025-06-21 18:23', 1, 1, 'Почта России', 'Договор №813 от 01.01.2025', 1, 'packing', 125400, 125400, 125400, 0, 12, 4, 12, '2025-06-22', '2025-06-23', 'А221ВА77', '11', 'Внавал', 'normal', 100],
        ['S-205106', '2025-06-21 17:10', 2, 1, 'ПЭК', null, 1, 'assembling', 0, 0, 0, 0, 5, 1, 100, '2025-06-23', null, 'Е658УК77', '5', 'Внавал', 'normal', 30],
        ['S-205105', '2025-06-21 12:05', 3, 2, 'ТК КИТ', null, 1, 'assembling', 230000, 230000, 0, 230000, 18, 2, 18, '2025-06-23', null, null, null, 'Паллеты', 'high', 0],
        ['S-205104', '2025-06-21 10:00', 4, 2, 'Деловые Линии', null, 1, 'cancelled', 0, 0, 0, 0, 1, 1, 3, '2025-06-23', null, null, null, 'Паллеты', 'normal', 0],
        ['S-205103', '2025-06-21 09:00', 5, 1, 'ПЭК', null, 1, 'draft', 0, 0, 0, 0, 0, 0, 0, '2025-06-23', null, null, null, 'Паллеты', 'low', 0],
        ['S-205102', '2025-06-21 01:12', 6, 1, 'CDEK', null, 1, 'on_check', 65120, 65120, 0, 65120, 7, 1, 7, '2025-06-22', null, 'А775ЕЕ33', '7', 'Внавал', 'normal', 89],
        ['S-205101', '2025-06-21 19:34', 7, 1, 'Байкал Сервис', null, 1, 'created', 143800, 143800, 0, 143800, 14, 2, 14, '2025-06-22', null, 'Н911НО199', '5', 'Паллеты', 'normal', 0],
        ['S-205108', '2025-06-22 10:35', 1, 3, 'ООО «СпортОпт»', 'Договор поставки №15 от 01.01.2025', 1, 'delivering', 125400, 125400, 125400, 0, 12, 4, 12, '2025-06-23', null, 'А221ВА77', '11', 'Внавал', 'normal', 100],
    ];
    $st = $pdo->prepare("INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,consignee,contract_no,warehouse_id,status,total,total_with_vat,paid,debt,items_count,sku_count,qty_total,plan_shipment_date,fact_shipment_date,vehicle,gate,loading_type,priority,readiness,responsible_id,created_by,volume,weight,cargo_places,vehicle_arrival,assembly_start,packing_start,check_start) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,2.12,12,2,'2025-06-22 12:01','2025-06-22 11:22','2025-06-22 11:41','2025-06-22 14:59')");
    foreach ($rows as $r) {
        $st->execute($r);
    }
    // Связка первой отгрузки с 9 заказами
    $sid = (int)$pdo->query("SELECT id FROM shipments WHERE doc_no='S-205107'")->fetchColumn();
    $ins = $pdo->prepare("INSERT OR IGNORE INTO shipment_orders (shipment_id,order_id,collected,checked,packed,shipped,sum_novat,vat,sum_vat) VALUES (:s,:o,80,80,80,0,20320,5080,25400)");
    for ($i = 1; $i <= 9; $i++) {
        $ins->execute([':s' => $sid, ':o' => $i]);
    }
    // Товары (12) по макету вкладки Товары
    $goods = [
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889621-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889622-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889623-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889624-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889625-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889626-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889627-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889628-1'],
        ['T-101', 'MK-ASM-1315', 'Мяч баскетбольный А', 1, 6350, 'EXP317889629-1'],
        ['T-102', 'MK-ASM-1316', 'Мяч баскетбольный Б', 1, 6350, 'EXP317889621-2'],
        ['T-103', 'MK-ASM-1317', 'Мяч баскетбольный В', 1, 6350, 'EXP317889621-3'],
        ['T-104', 'MK-ASM-1318', 'Мяч баскетбольный Г', 1, 6350, 'EXP317889621-4'],
    ];
    $gi = $pdo->prepare("INSERT INTO shipment_items (shipment_id,code,sku,name,qty,price,\"sum\",check_label,unit) VALUES (:s,:code,:sku,:name,:qty,:price,:sum,:lbl,'шт')");
    foreach ($goods as [$code, $sku, $name, $qty, $price, $lbl]) {
        $gi->execute([':s' => $sid, ':code' => $code, ':sku' => $sku, ':name' => $name, ':qty' => $qty, ':price' => $price, ':sum' => $qty * $price, ':lbl' => $lbl]);
    }
    // Факт сортировки ТСД по SSCC (для упаковочных листов: один лист = одна SSCC)
    $pk = $pdo->prepare("INSERT INTO tsd_picks (shipment_id,sku,qty,user_id,sscc) VALUES (:s,:sku,:q,1,:sscc)");
    foreach ([['MK-ASM-1315', 9, '046402889105071'], ['MK-ASM-1316', 1, '046402889105088'], ['MK-ASM-1317', 1, '046402889105088'], ['MK-ASM-1318', 1, '046402889105088']] as [$psku, $pqty, $psscc]) {
        $pk->execute([':s' => $sid, ':sku' => $psku, ':q' => $pqty, ':sscc' => $psscc]);
    }
    // Документы (4)
    $docs = [['Экспедиторская расписка', 'pdf', 1], ['Путевой лист', 'pdf', 1], ['ТТН', 'xsls', 2], ['Доверенность', 'jpeg', 1]];
    $di = $pdo->prepare("INSERT INTO shipment_documents (shipment_id,name,format,pages) VALUES (:s,:n,:f,:p)");
    foreach ($docs as [$n, $f, $p]) {
        $di->execute([':s' => $sid, ':n' => $n, ':f' => $f, ':p' => $p]);
    }
    // Флаги (12): серийники + маркировка
    $fi = $pdo->prepare("INSERT INTO shipment_flags (shipment_id,order_id,flag_type,serial_no,marking_code,aggregation,stock_left) VALUES (:s,:o,'serial',:sn,:mk,:ag,:st)");
    $serials = ['A1B2C3D4E5F6', 'A1B2C3D4E5F7', 'A1B2C3D4E5F8', 'A1B2C3D4E5F9', 'A1B2C3D4E5F10', 'A1B2C3D4E5F11', 'A1B2C3D4E5F12', 'A1B2C3D4E5F13', 'A1B2C3D4E5F14'];
    foreach ($serials as $i => $sn) {
        $fi->execute([':s' => $sid, ':o' => ($i % 9) + 1, ':sn' => $sn, ':mk' => '0104640288927222215-Uy?Kp5C(_hHlhUqHGG', ':ag' => '04640288910507', ':st' => 100 + $i]);
    }
    // История
    $pdo->exec("INSERT INTO shipment_history (shipment_id,user_id,user_fio,position,action,field,old_value,new_value,old_status,new_status) VALUES ($sid,1,'Ивантеев Михаил','Менеджер','create','Статус','Черновик','Создан','draft','created')");
}
endif;

$repo = new ShipmentRepository($pdo);
$service = new ShipmentService($pdo);
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

if ($action === 'list') {
    echo json_encode($repo->paginate(ShipmentFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'show') {
    $row = $repo->findById((int)($_GET['id'] ?? 0));
    if (!$row) {
        http_response_code(404);
    }
    echo json_encode($row ?? ['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'orders') {
    echo json_encode($repo->orders((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'unlink_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Вкладка "Наряды", ПКМ: убрать заказы из отгрузки (со строками состава).
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $res = $service->unlinkOrders((int)($_GET['id'] ?? 0), (array)($b['order_ids'] ?? $b['ids'] ?? []), 1);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'pick_tasks') {
    // Вкладка "Задания": задания на отбор, созданные по отгрузке (волна/строки).
    try {
        echo json_encode($service->pickTasksForShipment((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'items') {
    echo json_encode($repo->items((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'documents') {
    echo json_encode($repo->documents((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'history') {
    echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'flags') {
    echo json_encode($repo->flags((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'marks') {
    // Реестр марок честного знака по отгрузке (загружается файлом на документ).
    try {
        $st = $pdo->prepare("SELECT id, sku, marking_code, prod_date, status, accepted_at FROM shipment_marks WHERE shipment_id=:id ORDER BY id");
        $st->execute([':id' => (int)($_GET['id'] ?? 0)]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'sort_log') {
    // Лог операций сортировки по отгрузке (ТСД).
    try {
        $st = $pdo->prepare(
            'SELECT l.id, l.pallet_id, l.pallet_created_at, l.user_id, u.fio AS user_fio, l.item_id,
                    i.sku AS item_sku, l.serial_no, l.marking_code, l.qty, l.operation, l.reason, l.created_at
             FROM shipment_sort_log l
             LEFT JOIN users u ON u.id = l.user_id
             LEFT JOIN shipment_items i ON i.id = l.item_id
             WHERE l.shipment_id=:id ORDER BY l.id DESC LIMIT 300'
        );
        $st->execute([':id' => (int)($_GET['id'] ?? 0)]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'candidates') {
    echo json_encode($repo->pickCandidates($_GET), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($b['contractor_id']) || empty($b['warehouse_id'])) {
        http_response_code(422);
        echo json_encode(['error' => 'contractor_id и warehouse_id обязательны'], JSON_UNESCAPED_UNICODE);
    } else {
        require_once __DIR__ . '/../shared/DocNo.php';
        $no = wms_next_shipment_no($pdo);
        $st = $pdo->prepare("INSERT INTO shipments (doc_no,doc_date,contractor_id,warehouse_id,status,total,total_with_vat,responsible_id,comment,created_by,plan_shipment_date,consignee,vehicle,gate,loading_type,priority) VALUES (:no,datetime('now'),:c,:w,'assembling',0,0,1,'',1,:p,:csg,:veh,:gate,:lt,:pri)");
        $st->execute([':no' => $no, ':c' => (int)$b['contractor_id'], ':w' => (int)$b['warehouse_id'], ':p' => $b['plan_shipment_date'] ?? null, ':csg' => $b['consignee'] ?? null, ':veh' => $b['vehicle'] ?? null, ':gate' => $b['gate'] ?? null, ':lt' => $b['loading_type'] ?? null, ':pri' => $b['priority'] ?? 'normal']);
        http_response_code(201);
        echo json_encode(['id' => (int)$pdo->lastInsertId(), 'doc_no' => $no], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // ПКМ «Копировать»: новый черновик с теми же позициями и нарядами.
    // Раньше кнопка в UI дёргала action=store с жёстко зашитыми
    // contractor_id=1 / warehouse_id=1 — получался пустой документ не у того
    // контрагента. Эндпоинт отсутствовал, а ShipmentService::copy() был мёртвым кодом.
    $sid = (int)($_GET['id'] ?? 0);
    if ($sid <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'нужен id отгрузки'], JSON_UNESCAPED_UNICODE);
    } else {
        try {
            $nid = $service->copy($sid, 1);
            http_response_code(201);
            echo json_encode(['id' => $nid], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
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
} elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $to = ShipmentStatus::from($b['to'] ?? '');
        $service->changeStatus((int)($_GET['id'] ?? 0), $to, 1);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'flag' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Флаги документа (галки в карточке): кроссдок, серийники, маркировка ЧЗ...
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $service->setFlag((int)($_GET['id'] ?? 0), (string)($b['field'] ?? ''), (int)($b['value'] ?? 0), 1);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'priority' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $service->priority((int)($_GET['id'] ?? 0), (string)($b['priority'] ?? 'normal'), 1);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'picking' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $service->startPicking((int)($_GET['id'] ?? 0), 1, (string)($b['mode'] ?? 'wave'));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'issue_pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        require_once __DIR__ . '/../shared/PickIssue.php';
        require_once __DIR__ . '/../shared/db.php';
        $taskPdos = wms_tasks_pdos();
        if (!$taskPdos) {
            // fallback: каноническая БД заданий (если wms_tasks_pdos пуст)
            $fallback = [wms_tasks_db_file()];
            foreach ($fallback as $f) {
                if (is_file($f)) {
                    $taskPdos[] = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                }
            }
        }
        $ids = (array)($b['shipment_ids'] ?? $b['ids'] ?? []);
        if (!$ids && isset($_GET['id'])) {
            $ids = [(int)$_GET['id']];
        }
        $res = wms_issue_pick_tasks($pdo, $taskPdos, $ids, (string)($b['mode'] ?? 'wave'), 1);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'reserve_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // ПКМ «Ручное резервирование»: строки отгрузки (требуется / в резерве / остаток).
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    try {
        require_once __DIR__ . '/../shared/PickIssue.php';
        require_once __DIR__ . '/../shared/db.php';
        $taskPdos = wms_tasks_pdos();
        if (!$taskPdos) {
            throw new RuntimeException('БД заданий недоступна');
        }
        $nom = wms_nomenclature_pdo();
        $storage = wms_storage_pdo();
        $lines = wms_pick_reserve_lines($pdo, $nom, $storage, $taskPdos[0], $sid);
        $docNo = (string)$pdo->query('SELECT doc_no FROM shipments WHERE id=' . $sid)->fetchColumn();
        echo json_encode([
            'ok' => true, 'shipment_id' => $sid, 'doc_no' => $docNo,
            'lines' => $lines,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'reserve_candidates' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    // ПКМ «Ручное резервирование»: товарный запас по позиции (ячейки/паллеты).
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    $sku = trim((string)($_GET['sku'] ?? ''));
    $batch = trim((string)($_GET['batch'] ?? ''));
    try {
        require_once __DIR__ . '/../shared/PickIssue.php';
        require_once __DIR__ . '/../shared/db.php';
        $taskPdos = wms_tasks_pdos();
        if (!$taskPdos) {
            throw new RuntimeException('БД заданий недоступна');
        }
        if ($sku === '') {
            throw new RuntimeException('Не указан артикул позиции');
        }
        $nom = wms_nomenclature_pdo();
        $storage = wms_storage_pdo();
        $res = wms_pick_reserve_candidates($pdo, $nom, $storage, $taskPdos[0], $sid, $sku, $batch !== '' ? $batch : null);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'reserve_manual' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // ПКМ «Ручное резервирование»: запись резерва по выбранным ячейкам/паллетам.
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? ($_GET['shipment_id'] ?? 0));
    try {
        require_once __DIR__ . '/../shared/PickIssue.php';
        require_once __DIR__ . '/../shared/db.php';
        $taskPdos = wms_tasks_pdos();
        if (!$taskPdos) {
            throw new RuntimeException('БД заданий недоступна');
        }
        $nom = wms_nomenclature_pdo();
        $storage = wms_storage_pdo();
        $res = wms_pick_reserve_manual($pdo, $nom, $storage, $taskPdos[0], $sid, (array)($b['items'] ?? []), 1);
        echo json_encode($res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'release_pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        require_once __DIR__ . '/../shared/PickIssue.php';
        require_once __DIR__ . '/../shared/db.php';
        $taskPdos = wms_tasks_pdos();
        if (!$taskPdos) {
            // fallback: каноническая БД заданий (если wms_tasks_pdos пуст)
            $fallback = [wms_tasks_db_file()];
            foreach ($fallback as $f) {
                if (is_file($f)) {
                    $taskPdos[] = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                }
            }
        }
        $ids = (array)($b['shipment_ids'] ?? $b['ids'] ?? []);
        if (!$ids && isset($_GET['id'])) {
            $ids = [(int)$_GET['id']];
        }
        $res = wms_release_pick_tasks($pdo, $taskPdos, $ids, 1);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'print') {
    $pId = (int)($_GET['id'] ?? 0);
    $pKind = (string)($_GET['kind'] ?? 'act');
    if (($_GET['format'] ?? '') === 'pdf') {
        require_once __DIR__ . '/../shared/Pdf.php';
        try {
            $pSscc = (string)($_GET['sscc'] ?? '');
            [$pData, $pForce] = wms_shipment_print($service->printData($pId, $pKind, $pSscc));
            $pBc = ($pKind === 'pick_list' || $pKind === 'p-sb') ? (string)($pData['head']['doc_no'] ?? '')
                : ((($pKind === 'pack_list' || $pKind === 'p-up') && $pSscc !== '') ? $pSscc : '');
            wms_pdf_output(wms_pdf_auto($pData, $pForce, $pBc !== '' ? $pBc : null), wms_pdf_filename($pData['title'] ?? $pKind, $pId));
        } catch (Throwable $e) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Ошибка PDF: ' . $e->getMessage();
        }
        exit;
    }
    try {
        echo json_encode($service->printData($pId, $pKind), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'sscc_list') {
    try {
        echo json_encode($service->ssccList((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'refs') {
    // Справочники для форм: контрагенты и склады.
    echo json_encode([
        'contractors' => $pdo->query("SELECT id, name FROM contractors ORDER BY name")->fetchAll(),
        'warehouses' => $pdo->query("SELECT id, name FROM warehouses ORDER BY id")->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'csv_shipment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Загрузка отгрузки (кнопка «Загрузить данные»): CSV или Excel-заявка (.xlsx).
    // Контрагент — из колонки «Контрагент» либо из шапки заявки (нового добавляем в справочник).
    try {
        $parsed = shipment_upload_parse_file($_FILES['file'] ?? []);
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            try {
                $nom = wms_nomenclature_pdo();
            } catch (Throwable) {
                $nom = null;
            }
            $hdr = $parsed['header'] ?? [];
            // Контрагент — из файла (шапка/колонка) либо явно переданный contractor_id.
            $contractorId = (int)($_POST['contractor_id'] ?? 0);
            $cc = ['id' => $contractorId, 'name' => '', 'created' => false];
            if ($contractorId <= 0) {
                $cc = csv_upload_resolve_contractor($pdo, (string)($hdr['contractor'] ?? ''));
                $contractorId = $cc['id'];
            }
            $r = csv_upload_import_shipment($pdo, $nom, $parsed['rows'],
                $contractorId, (int)($_POST['warehouse_id'] ?? 0), 1,
                (string)($_FILES['file']['name'] ?? 'upload.csv'), $hdr);
            // Грузополучатель из отгрузки автоматически попадает в справочник (тип «грузополучатель»).
            WmsCounterparties::ensure($pdo, (string)($hdr['consignee'] ?? ''), 'consignee');
            $r += ['contractor' => $cc['name'], 'contractor_new' => $cc['created'],
                'format' => ($parsed['format'] ?? '')];
            http_response_code(201);
            echo json_encode($r + ['row_errors' => $parsed['errors']], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'csv_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Загрузка расхода как наряда — тот же конвейер и форматы, что у робота in/out:
    // CSV/TXT (разделитель «;», UTF-8) или Excel-заявка .xlsx/.xlsm.
    // Один файл = один наряд; отгрузка по наряду формируется автоматически.
    require_once __DIR__ . '/../shared/ImportRobot.php';
    try {
        $res = wms_import_document('out', $_FILES['file'] ?? [], (int)($_POST['warehouse_id'] ?? 0), 1, [
            'check_hash' => true,
            'check_external' => true,
        ]);
        if (($res['doc_no'] ?? '') !== '') {
            wms_robot_log_add('web', (string)($res['file_name'] ?? ''), (string)($res['hash'] ?? ''),
                'success', (string)$res['doc_no'],
                'Заказ ' . $res['doc_no'] . ' загружен из веб-интерфейса: строк ' . (int)($res['items'] ?? 0) . '.');
        }
        http_response_code(201);
        echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch (WmsImportException $e) {
        http_response_code($e->httpCode);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Удаление через ПКМ (можно несколько). Правило сервиса: только черновик/отменена.
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = (array)($b['shipment_ids'] ?? $b['ids'] ?? []);
    if (!$ids && isset($_GET['id'])) {
        $ids = [(int)$_GET['id']];
    }
    $deleted = [];
    $errors = [];
    foreach (array_values(array_unique(array_map('intval', $ids))) as $sid) {
        if ($sid <= 0) {
            continue;
        }
        try {
            $doc = (string)$pdo->query('SELECT doc_no FROM shipments WHERE id=' . $sid)->fetchColumn();
            $service->delete($sid, 1);
            // В демо нет FK-каскадов — чистим связанные строки вручную.
            foreach (['shipment_items', 'shipment_orders', 'shipment_history', 'shipment_documents', 'shipment_flags', 'tsd_picks'] as $tbl) {
                try {
                    $pdo->prepare("DELETE FROM $tbl WHERE shipment_id=:id")->execute([':id' => $sid]);
                } catch (Throwable) {
                }
            }
            $deleted[] = $doc !== '' ? $doc : ('#' . $sid);
        } catch (Throwable $e) {
            $errors[] = '#' . $sid . ': ' . $e->getMessage();
        }
    }
    if (!$deleted) {
        http_response_code(400);
        echo json_encode(['error' => implode('; ', $errors) ?: 'Нечего удалять'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'disband' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // ПКМ "Расформировать отгрузку": код отгрузки удаляется, наряды остаются.
    try {
        $res = $service->disband((int)($_GET['id'] ?? 0), 1);
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'csv_marks' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Файл марок ЧЗ на отгрузку (реестр для сортировки): multipart file + shipment_id.
    try {
        $parsed = csv_upload_parse_marks($_FILES['file'] ?? []);
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            $r = csv_upload_import_shipment_marks($pdo, (int)($_POST['shipment_id'] ?? 0), $parsed['rows'], 1,
                (string)($_FILES['file']['name'] ?? 'marks.csv'));
            $r['row_errors'] = array_merge($parsed['errors'], $r['row_errors']);
            http_response_code(201);
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['error' => 'unknown action, use ?action=list|show|orders|items|documents|history|flags|candidates&id=1'], JSON_UNESCAPED_UNICODE);
}
