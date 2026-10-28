<?php
declare(strict_types=1);
// Демо поступлений без MySQL: SQLite + те же классы реестра.
// Запуск: php -S localhost:8001 demo_sqlite.php  (из папки receipts)
// Открыть: http://localhost:8001/demo_sqlite.php?action=list
// Вкладки: ?action=operations|items|documents|history|flags&id=1

require_once __DIR__ . '/ReceiptStatus.php';
require_once __DIR__ . '/ReceiptFilter.php';
require_once __DIR__ . '/ReceiptRepository.php';
require_once __DIR__ . '/ReceiptService.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/CsvUpload.php';
require_once __DIR__ . '/../shared/Counterparties.php';
require_once __DIR__ . '/../shared/ProductLookup.php';

$dbFile = wms_receipts_db_file();
$pdo = wms_receipts_pdo();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

// --- Схема SQLite (упрощенная, для демо; прод — schema.sql на MySQL) ---
// Только SQLite: в PostgreSQL схема создаётся из db/schema-pg.sql, сиды — миграцией.
if ($isSqlite):
$pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS contractors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
// users — единый справочник сотрудников (shared/wms_users.sqlite), подключён в shared/db.php.
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
  code TEXT, barcode TEXT, scan_serial INTEGER NOT NULL DEFAULT 0, scan_chz INTEGER NOT NULL DEFAULT 0,
  owner_contractor_id INTEGER, owner_name TEXT,
  sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
  qty REAL NOT NULL, boxes INTEGER DEFAULT 0, price REAL DEFAULT 0, vat_rate INTEGER DEFAULT 20,
  vat_sum_unit REAL DEFAULT 0, price_wvat REAL DEFAULT 0, sum_novat REAL DEFAULT 0,
  vat_sum REAL DEFAULT 0, sum_vat REAL DEFAULT 0, quality TEXT, prod_group TEXT, prod_date TEXT)");
// Миграция для файлов, созданных до CSV-загрузки: качество, группа, дата производства и партия в позициях/операциях.
foreach (['quality' => 'quality TEXT', 'prod_group' => 'prod_group TEXT', 'prod_date' => 'prod_date TEXT', 'supplier_batch' => 'supplier_batch TEXT'] as $col => $ddl) {
    try {
        $has = false;
        foreach ($pdo->query("PRAGMA table_info(receipt_items)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $col) { $has = true; break; }
        }
        if (!$has) $pdo->exec("ALTER TABLE receipt_items ADD COLUMN $ddl");
    } catch (Throwable) {}
}
$pdo->exec("CREATE TABLE IF NOT EXISTS receipt_operations (
  id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, line_no INTEGER DEFAULT 0,
  sku TEXT, supplier_batch TEXT, accept_status TEXT, accept_date TEXT, receiver_id INTEGER, pallet TEXT,
  sku_qty INTEGER DEFAULT 0, qty REAL DEFAULT 0, place_status TEXT, place_date TEXT,
  storekeeper_id INTEGER, cell TEXT)");
try {
    $has = false;
    foreach ($pdo->query("PRAGMA table_info(receipt_operations)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['name'] ?? '') === 'supplier_batch') { $has = true; break; }
    }
    if (!$has) $pdo->exec("ALTER TABLE receipt_operations ADD COLUMN supplier_batch TEXT");
} catch (Throwable) {}
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
$pdo->exec("CREATE TABLE IF NOT EXISTS receipt_marks (
  id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sku TEXT NOT NULL,
  marking_code TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'expected',
  accepted_at TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_receipt_mark ON receipt_marks(receipt_id, marking_code)");

// --- Сид из макета, если пусто ---
if ((int)$pdo->query("SELECT COUNT(*) FROM receipts")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO warehouses (id,name) VALUES (1,'Основной склад')");
    $pdo->exec("INSERT INTO contractors (id,name) VALUES (1,'ООО «СпортОпт»'),(2,'ООО «Мяч-Сервис»'),(3,'ООО «Восток»'),(4,'ИП Смирнов В.В.'),(5,'ИП Смирнов М.А.'),(6,'ООО «ТоргСнаб»'),(7,'ООО «ФиТрейд»'),(8,'ИП Козлов К.К.')");
    // Сотрудники — в общей базе (shared/wms_users.sqlite), сиды там.

    // Реестр из макета (7 строк): doc_no, external, doc_date, plan, arrival, accept, sup, ctr, contract, wh, status, rtype, atype, utype, veh, gate, places_plan/fact, total, vat, total_wvat, paid, items, sku, qty, acc%, place%
    $rows = [
        ['A-105107','317889621','2025-06-22 12:01','2025-06-22 10:50','2025-06-22 12:01','2025-06-22 19:28',1,1,'Договор поставки №15 от 01.01.2025',1,'closed_short','supply','recount','Внавал','А221ВА77','11',4,4,125400,20,125400,125400,12,4,12,99,100],
        ['A-105106','АСН223697','2025-06-21 18:18','2025-06-22 10:50','2025-06-21 18:18',null,2,5,'Договор поставки №15 от 01.01.2025',1,'accepting','supply','recount','Внавал','Е658УК77','5',4,3,460000,20,552000,552000,2,2,500,80,60],
        ['A-105105','ОП452-1123','2025-06-21 12:05','2025-06-23 10:00','2025-06-21 12:05',null,3,3,null,1,'unloading','supply','recount','Паллеты',null,null,18,0,230000,20,230000,0,18,2,18,0,0],
        ['A-105104','1061988','2025-06-21 10:00','2025-06-23 10:00',null,null,4,4,null,1,'cancelled','supply','no_recount','Паллеты',null,null,3,0,0,20,0,0,1,1,3,0,0],
        ['A-105103','33-5425846','2025-06-21 09:00','2025-06-23 10:00',null,null,6,6,null,1,'draft','supply','recount','Паллеты',null,null,0,0,0,20,0,0,0,0,0,0,0],
        ['A-105102','786545658','2025-06-21 12:05','2025-06-22 10:00','2025-06-21 12:05','2025-06-21 01:12',7,7,null,1,'placing','supply','recount','Внавал','А775ЕЕ33','7',7,7,65120,20,65120,0,7,1,7,100,99],
        ['A-105101','КК-551458','2025-06-21 17:10','2025-06-22 10:00','2025-06-21 17:10',null,8,8,null,1,'ready_accept','supply','recount','Паллеты','Н911НО199','5',14,0,143800,20,143800,0,14,7,14,0,0],
    ];
    $st = $pdo->prepare("INSERT INTO receipts (doc_no,external_no,doc_date,plan_delivery_date,arrival_date,accept_date,supplier_id,contractor_id,contract_no,warehouse_id,status,receipt_type,accept_type,unload_type,vehicle,gate,cargo_places_plan,cargo_places_fact,total,vat_rate,total_with_vat,paid,items_count,sku_count,qty_total,accepted_pct,placed_pct,vat_sum,debt,created_by,creator_id,serial_scan,marking_scan,volume,vehicle_arrival_fact) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,1,1,1,2.12,?)");
    foreach ($rows as $r) {
        [$no,$ext,$doc,$plan,$arr,$acc,$sup,$ctr,$cn,$wh,$status,$rt,$at,$ut,$veh,$gate,$cpp,$cpf,$total,$vat,$withVat,$paid,$items,$sku,$qty,$ap,$pp] = $r;
        $vatSum = round($total * $vat / 100, 2);
        if ($no === 'A-105106') {
            $vatSum = 92000; // из карточки: 460 000 + 92 000 = 552 000
        }
        $debt = round($withVat - $paid, 2);
        $st->execute([$no,$ext,$doc,$plan,$arr,$acc,$sup,$ctr,$cn,$wh,$status,$rt,$at,$ut,$veh,$gate,$cpp,$cpf,$total,$vat,$withVat,$paid,$items,$sku,$qty,$ap,$pp,$vatSum,$debt,$arr]);
    }

    // --- Товары детально для A-105106 (карточка: 2 позиции, 500 шт) ---
    $rid = (int)$pdo->query("SELECT id FROM receipts WHERE doc_no='A-105106'")->fetchColumn();
    $gi = $pdo->prepare("INSERT INTO receipt_items (receipt_id,line_no,code,sku,name,unit,qty,boxes,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat) VALUES (:r,:ln,:code,:sku,:name,'шт',:qty,0,:price,20,:vu,:pw,:sn,:vs,:sv)");
    $gi->execute([':r'=>$rid,':ln'=>1,':code'=>'T-101',':sku'=>'MK-ASM-1315',':name'=>'Мяч баскетбольный А',':qty'=>150,':price'=>1200,':vu'=>240,':pw'=>1440,':sn'=>180000,':vs'=>36000,':sv'=>216000]);
    $gi->execute([':r'=>$rid,':ln'=>2,':code'=>'T-102',':sku'=>'MK-ASM-1316',':name'=>'Мяч баскетбольный Б',':qty'=>350,':price'=>800,':vu'=>160,':pw'=>960,':sn'=>280000,':vs'=>56000,':sv'=>336000]);

    // Товары-заглушки для остальных (чтобы SKU/шт в реестре сходились)
    $gi2 = $pdo->prepare("INSERT INTO receipt_items (receipt_id,line_no,code,sku,name,unit,qty,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat) VALUES (:r,1,'T-101','MK-ASM-1315','Мяч баскетбольный А','шт',:qty,1200,20,240,1440,:qty*1200,:qty*240,:qty*1440)");
    foreach ([['A-105107',12],['A-105105',18],['A-105102',7],['A-105101',14]] as [$no,$qty]) {
        $id = (int)$pdo->query("SELECT id FROM receipts WHERE doc_no=" . $pdo->quote($no))->fetchColumn();
        $gi2->execute([':r'=>$id,':qty'=>$qty]);
    }

    // --- Текущие операции по поступлению (4 строки из макета) ---
    $opId = (int)$pdo->query("SELECT id FROM receipts WHERE doc_no='A-105107'")->fetchColumn();
    $ops = [
        [1,'MK-ASM-1315','Принято','2025-06-21 20:07',3,'MEZ223895',1,1,'Размещено','2025-06-21 20:30',4,'М33-106-3-10'],
        [2,'MK-ASM-1315','Принято','2025-06-21 20:09',3,'MEZ223896',1,1,'Размещено','2025-06-21 20:37',4,'М38-012-1-15'],
        [3,'MK-ASM-1315','Принято','2025-06-21 20:10',3,'MEZ223897',1,1,'Размещено','2025-06-21 20:42',4,'М12-122-4-14'],
        [4,'MK-ASM-1315','Принято','2025-06-21 20:11',3,'MEZ223898',1,1,'В размещении',null, null,null],
    ];
    $oi = $pdo->prepare("INSERT INTO receipt_operations (receipt_id,line_no,sku,accept_status,accept_date,receiver_id,pallet,sku_qty,qty,place_status,place_date,storekeeper_id,cell) VALUES (:r,:ln,:sku,:as,:ad,:rec,:pal,:sq,:q,:ps,:pd,:st,:cell)");
    foreach ($ops as $o) {
        $oi->execute([':r'=>$opId,':ln'=>$o[0],':sku'=>$o[1],':as'=>$o[2],':ad'=>$o[3],':rec'=>$o[4],':pal'=>$o[5],':sq'=>$o[6],':q'=>$o[7],':ps'=>$o[8],':pd'=>$o[9],':st'=>$o[10],':cell'=>$o[11]]);
    }

    // --- Вложенные документы (4) ---
    $docs = [['Экспедиторская расписка','pdf',1],['Путевой лист','pdf',1],['ТТН','xsls',2],['Доверенность','jpeg',1]];
    $di = $pdo->prepare("INSERT INTO receipt_documents (receipt_id,name,format,pages) VALUES (:r,:n,:f,:p)");
    foreach ($docs as [$n,$f,$p]) {
        $di->execute([':r'=>$opId,':n'=>$n,':f'=>$f,':p'=>$p]);
    }
    $di->execute([':r'=>$rid,':n'=>'Экспедиторская расписка',':f'=>'pdf',':p'=>1]);
    $di->execute([':r'=>$rid,':n'=>'ТТН',':f'=>'xsls',':p'=>2]);

    // --- Флаги: серийники + маркировка ЧЗ (9 КИ из макета) ---
    $fi = $pdo->prepare("INSERT INTO receipt_flags (receipt_id,flag_type,serial_no,marking_code,sku,receiver,flag_date) VALUES (:r,:t,:sn,:mk,:sku,:rec,:d)");
    $marks = [
        '0104640288927222215-Uy?Kp5C(_hHlhUqHGG','0100400001218228215mHllktHH=ZjoipXc:GD',
        '0100400001218228215"l5NUl&CEjr?RmtX,cf','0104640288951852215D6BNfL!Jb_UK(,E/+V',
        '0104640288952118215NMT,cZmuR,BP_oVS&HG','0104640288952484215fWOMo!MZ(XfmFLLFYrB',
        '0104640288952484215jhfsi"Jp06YOnW;N,lV','0104640288952484215nnZGI/mRcvWHiPjWs&o',
        '0104640288952484215YplvPZWz"<mqc/zfHsy',
    ];
    foreach ($marks as $i => $mk) {
        $fi->execute([':r'=>$rid,':t'=>'marking',':sn'=>null,':mk'=>$mk,':sku'=>'MK-ASM-1315',':rec'=>'10977 П Иванов Иван',':d'=>'2025-06-21 20:0' . ($i % 10)]);
    }

    // --- История (как в макете вкладки История) ---
    $hi = $pdo->prepare("INSERT INTO receipt_history (receipt_id,user_id,user_fio,position,action,field,old_value,new_value,old_status,new_status,created_at) VALUES (:r,:u,:fio,:pos,:a,:fld,:old,:new,:os,:ns,:d)");
    $hist = [
        [$rid,5,'1788 - Смирнов Николай','Менеджер','create','Документ №',null,'АСН223697',null,null,'2025-06-20 10:03'],
        [$rid,5,'1788 - Смирнов Николай','Менеджер','update','Склад',null,'Основной склад',null,null,'2025-06-20 10:03'],
        [$rid,5,'1788 - Смирнов Николай','Менеджер','update','Контрагент',null,'ИП Смирнов М.А.',null,null,'2025-06-20 10:03'],
        [$rid,5,'1788 - Смирнов Николай','Менеджер','update','Поставщик',null,'ООО «Мяч-Сервис»',null,null,'2025-06-20 10:03'],
        [$rid,5,'1788 - Смирнов Николай','Менеджер','update','Тип поступления',null,'Поставка',null,null,'2025-06-20 10:03'],
        [$rid,5,'1788 - Смирнов Николай','Менеджер','update','Дата поставки, план',null,'2025-06-22 10:50',null,null,'2025-06-20 10:03'],
        [$opId,1,'Ивантеев Михаил','Менеджер','status','Статус','Черновик','Приемка','draft','accepting','2025-06-21 19:00'],
    ];
    foreach ($hist as $h) {
        $hi->execute([':r'=>$h[0],':u'=>$h[1],':fio'=>$h[2],':pos'=>$h[3],':a'=>$h[4],':fld'=>$h[5],':old'=>$h[6],':new'=>$h[7],':os'=>$h[8],':ns'=>$h[9],':d'=>$h[10]]);
    }
}
endif;

$repo = new ReceiptRepository($pdo);
$service = new ReceiptService($pdo);
$action = $_GET['action'] ?? 'list';
$userId = function_exists('wms_auth_actor_id') ? wms_auth_actor_id() : 1;
if (in_array($action, ['store', 'status', 'update', 'priority', 'csv_receipt', 'csv_marks', 'item_add', 'item_update', 'item_delete'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST') {
    wms_auth_require_permission('doc_edit_receipt');
}
header('Content-Type: application/json; charset=utf-8');

if ($action === 'list') {
    echo json_encode($repo->paginate(ReceiptFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'show') {
    $row = $repo->findById((int)($_GET['id'] ?? 0));
    if (!$row) {
        http_response_code(404);
    }
    echo json_encode($row ?? ['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'operations') {
    echo json_encode($repo->operations((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'product_lookup') {
    $q = trim((string)($_GET['q'] ?? $_GET['sku'] ?? $_GET['barcode'] ?? ''));
    echo json_encode(['product' => wms_product_lookup($q)], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'items') {
    $rid = (int)($_GET['id'] ?? 0);
    $items = $repo->items($rid);
    foreach ($items as &$item) {
        $item['processed_qty'] = $service->processedQty($rid, $item);
    }
    unset($item);
    echo json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'item_add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $item = $service->addItem((int)($_GET['id'] ?? $b['receipt_id'] ?? 0), $b, $userId);
        echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'item_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $item = $service->updateItem((int)($_GET['id'] ?? $b['receipt_id'] ?? 0), (int)($b['item_id'] ?? 0), $b, $userId);
        echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'item_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $service->deleteItem((int)($_GET['id'] ?? $b['receipt_id'] ?? 0), (int)($b['item_id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'documents') {
    echo json_encode($repo->documents((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'history') {
    echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'flags') {
    echo json_encode($repo->flags((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'stockin') {
    echo json_encode($repo->stockin((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($b['supplier_id']) || empty($b['warehouse_id']) || empty($b['cargo_places_plan'])) {
        http_response_code(422);
        echo json_encode(['error' => 'supplier_id, warehouse_id и cargo_places_plan обязательны'], JSON_UNESCAPED_UNICODE);
    } else {
        require_once __DIR__ . '/../shared/DocNo.php';
        $no = wms_next_receipt_no($pdo);
        $now = function_exists('wms_now_sql') ? wms_now_sql($pdo) : "datetime('now')";
        $st = $pdo->prepare("INSERT INTO receipts (doc_no,doc_date,supplier_id,contractor_id,warehouse_id,status,receipt_type,accept_type,cargo_places_plan,created_by,creator_id,external_no,plan_delivery_date,unload_type) VALUES (:no,$now,:s,:s,:w,'draft',:rt,:at,:cp,:cb,:cr,:ext,:plan,:ut)");
        $rt = in_array((string)($b['receipt_type'] ?? ''), ['supply', 'return'], true) ? (string)$b['receipt_type'] : 'supply';
        $at = in_array((string)($b['accept_type'] ?? ''), ['recount', 'no_recount'], true) ? (string)$b['accept_type'] : null;
        $st->execute([':no'=>$no,':s'=>(int)$b['supplier_id'],':w'=>(int)$b['warehouse_id'],':rt'=>$rt,':at'=>$at,':cp'=>(int)$b['cargo_places_plan'],':cb'=>$userId,':cr'=>$userId,':ext'=>$b['external_no'] ?? null,':plan'=>$b['plan_delivery_date'] ?? null,':ut'=>$b['unload_type'] ?? null]);
        http_response_code(201);
        echo json_encode(['id' => (int)$pdo->lastInsertId(), 'doc_no' => $no], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $to = ReceiptStatus::from($b['to'] ?? '');
        // Закрытие проходит через гарды и формирует акты расхождений
        // (ReceiptService::changeStatus -> wms_close_receipt_guarded).
        $closed = $service->changeStatus((int)($_GET['id'] ?? 0), $to, $userId);
        // Закрытие из реестра тоже приходует факт в Номенклатуру (идемпотентно, дубль с ТСД исключен guard-таблицей).
        $stock = null;
        if (in_array($to, [ReceiptStatus::Closed, ReceiptStatus::ClosedShort], true)) {
            try {
                require_once __DIR__ . '/../shared/StockSync.php';
                $stock = wms_push_receipt_to_nomenclature($pdo, wms_nomenclature_pdo(), (int)($_GET['id'] ?? 0), $userId, 'Реестр поступлений');
            } catch (Throwable $e) {
                $stock = ['synced' => false, 'error' => $e->getMessage()];
            }
        }
        echo json_encode(['ok' => true, 'stock' => $stock,
            'acts' => $closed['acts'] ?? [], 'excess_qty' => $closed['excess_qty'] ?? 0,
            'shortage_qty' => $closed['shortage_qty'] ?? 0, 'deviation' => $closed['deviation'] ?? ''],
            JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Кнопка "Изменить" в карточке: дата поставки (план) + статус.
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    try {
        $status = $service->updateHead((int)($_GET['id'] ?? 0), $b, $userId);
        // Закрытие из карточки тоже приходует факт в Номенклатуру (как в action=status).
        $stock = null;
        if (in_array($status, ['closed', 'closed_short'], true)) {
            try {
                require_once __DIR__ . '/../shared/StockSync.php';
                $stock = wms_push_receipt_to_nomenclature($pdo, wms_nomenclature_pdo(), (int)($_GET['id'] ?? 0), $userId, 'Реестр поступлений');
            } catch (Throwable $e) {
                $stock = ['synced' => false, 'error' => $e->getMessage()];
            }
        }
        echo json_encode(['ok' => true, 'status' => $status, 'stock' => $stock], JSON_UNESCAPED_UNICODE);
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
} elseif ($action === 'print') {
    $pId = (int)($_GET['id'] ?? 0);
    $pKind = (string)($_GET['kind'] ?? 'act');
    $pType = (string)($_GET['type'] ?? '');
    if (($_GET['format'] ?? '') === 'pdf') {
        require_once __DIR__ . '/../shared/Pdf.php';
        try {
            $data = $service->printData($pId, $pKind, $pType);
            $force = (!in_array($pKind, ['act_rasx', 'receiver_doc'], true) && isset($data['items']))
                ? ['line_no' => 'п/п', 'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование', 'unit' => 'Ед.', 'qty' => 'Кол-во']
                : null;
            $bcText = ($pKind === 'receiver_doc') ? (string)($data['head']['doc_no'] ?? '') : '';
            wms_pdf_output(wms_pdf_auto($data, $force, $bcText !== '' ? $bcText : null, true), wms_pdf_filename($data['title'] ?? $pKind, $pId));
        } catch (Throwable $e) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Ошибка PDF: ' . $e->getMessage();
        }
        exit;
    }
    try {
        echo json_encode($service->printData($pId, $pKind, $pType), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'act_types') {
    try {
        echo json_encode($service->actTypes((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'refs') {
    echo json_encode([
        'suppliers' => $pdo->query("SELECT id, name FROM contractors ORDER BY name")->fetchAll(),
        'warehouses' => $pdo->query("SELECT id, name FROM warehouses ORDER BY id")->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'csv_receipt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Загрузка прихода — тот же конвейер и форматы, что у робота in/out:
    // CSV/TXT (разделитель «;», UTF-8) или Excel-заявка .xlsx/.xlsm.
    require_once __DIR__ . '/../shared/ImportRobot.php';
    try {
        $res = wms_import_document('in', $_FILES['file'] ?? [], (int)($_POST['warehouse_id'] ?? 0), $userId, [
            'check_hash' => true,
            'check_external' => true,
        ]);
        if (($res['doc_no'] ?? '') !== '') {
            wms_robot_log_add('web', (string)($res['file_name'] ?? ''), (string)($res['hash'] ?? ''),
                'success', (string)$res['doc_no'],
                'Приход ' . $res['doc_no'] . ' загружен из веб-интерфейса: строк ' . (int)($res['items'] ?? 0) . '.');
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
} elseif ($action === 'mark_docs') {
    // Документы для загрузки марок: в работе (не закрытые/отмененные).
    $in = "'expected','arrived','unloading','on_stock','ready_accept','accepting','placing','draft'";
    echo json_encode($pdo->query(
        "SELECT r.id, r.doc_no, r.status,
                (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = r.id) AS items,
                (SELECT COUNT(*) FROM receipt_marks WHERE receipt_id = r.id) AS marks
         FROM receipts r WHERE r.status IN ($in) ORDER BY r.id DESC LIMIT 100"
    )->fetchAll(), JSON_UNESCAPED_UNICODE);
} elseif ($action === 'csv_marks' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Файл марок ЧЗ на документ (кнопка «Загрузить данные»): multipart file + receipt_id.
    try {
        $parsed = csv_upload_parse_marks($_FILES['file'] ?? []);
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            $r = csv_upload_import_marks($pdo, (int)($_POST['receipt_id'] ?? 0), $parsed['rows'], $userId,
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
    echo json_encode(['error' => 'unknown action, use ?action=list|show|operations|items|documents|history|flags|stockin&id=1'], JSON_UNESCAPED_UNICODE);
}
