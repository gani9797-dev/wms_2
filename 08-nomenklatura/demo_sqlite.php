<?php
declare(strict_types=1);
// Демо "Номенклатуры" без MySQL: SQLite + те же классы реестра.
// Открыть: /08-nomenklatura/demo_sqlite.php?action=list
// Вкладки: ?action=show|stock|history|movements|documents&id=1
// ПКМ: POST ?action=store|update|copy|block|unblock|archive|mass&id=1
// Печать ШК/этикетки: ?action=print&id=1&kind=barcode|label

require_once __DIR__ . '/ProductStatus.php';
require_once __DIR__ . '/ProductFilter.php';
require_once __DIR__ . '/ProductRepository.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/Notifications.php';

require_once __DIR__ . '/../shared/db.php';

$dbFile = wms_nomenclature_db_file();
$pdo = wms_nomenclature_pdo();
$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

// Только SQLite: в PostgreSQL схема создаётся из db/schema-pg.sql, сиды — миграцией.
if ($isSqlite):
$pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
// users — единый справочник сотрудников (shared/wms_users.sqlite), подключён в shared/db.php.
$pdo->exec("CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, sku TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL, unit TEXT DEFAULT 'шт', category TEXT DEFAULT '', brand TEXT, owner TEXT,
  warehouse_id INTEGER, description TEXT, image_path TEXT,
  length_mm INTEGER, width_mm INTEGER, height_mm INTEGER, weight_g INTEGER, volume_m3 REAL,
  qty_in_box INTEGER, boxes_per_pallet INTEGER,
  storage_strategy TEXT DEFAULT 'FEFO', pick_strategy TEXT DEFAULT 'FEFO',
  placement_strategy TEXT DEFAULT 'FEFO',
  temp_from REAL, temp_to REAL, shelf_life_days INTEGER, marking_start TEXT, chz_group TEXT,
  max_expiry_pct REAL,
  is_batch INTEGER DEFAULT 0, is_expiry INTEGER DEFAULT 0, is_serial INTEGER DEFAULT 0,
  is_chz INTEGER DEFAULT 0, is_danger INTEGER DEFAULT 0,
  allow_partial_pick INTEGER DEFAULT 0, need_check INTEGER DEFAULT 0,
  need_pack INTEGER DEFAULT 0, auto_replenish INTEGER DEFAULT 0, need_measure INTEGER DEFAULT 0, quality TEXT,
  qty REAL DEFAULT 0, reserved_qty REAL DEFAULT 0,
  is_active INTEGER DEFAULT 1, is_archived INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS product_barcodes (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, barcode TEXT NOT NULL UNIQUE)");
$pdo->exec("CREATE TABLE IF NOT EXISTS product_stock (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, cell TEXT NOT NULL, pallet TEXT, qty REAL DEFAULT 0, receipt_id INTEGER, receipt_no TEXT)");
foreach (['receipt_id' => 'receipt_id INTEGER', 'receipt_no' => 'receipt_no TEXT'] as $col => $ddl) {
    try {
        $has = false;
        foreach ($pdo->query("PRAGMA table_info(product_stock)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $col) { $has = true; break; }
        }
        if (!$has) $pdo->exec("ALTER TABLE product_stock ADD COLUMN $ddl");
    } catch (Throwable) {}
}
$pdo->exec("CREATE TABLE IF NOT EXISTS product_history (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, user_id INTEGER, user_fio TEXT, field TEXT, field_label TEXT, old_value TEXT, new_value TEXT, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS product_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, doc_date TEXT NOT NULL, doc_type TEXT NOT NULL, doc_no TEXT NOT NULL, cell TEXT, qty_change REAL NOT NULL, balance REAL NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS product_defaults (id INTEGER PRIMARY KEY AUTOINCREMENT, field_name TEXT NOT NULL UNIQUE, field_value TEXT, updated_by INTEGER, updated_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, kind TEXT DEFAULT 'nomenclature', title TEXT NOT NULL, body TEXT, sku TEXT, is_important INTEGER DEFAULT 0, is_read INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS notification_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL DEFAULT 1, event_kind TEXT NOT NULL, ch_web INTEGER DEFAULT 0, ch_email INTEGER DEFAULT 0, ch_tg INTEGER DEFAULT 0, cond_label TEXT, is_on INTEGER DEFAULT 1, UNIQUE(user_id, event_kind))");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_p_sku ON products(sku)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_p_code ON products(code)");
// Миграция для файлов, созданных до появления placement_strategy.
try {
    $has = false;
    foreach ($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['name'] ?? '') === 'placement_strategy') { $has = true; break; }
    }
    if (!$has) $pdo->exec("ALTER TABLE products ADD COLUMN placement_strategy TEXT DEFAULT 'FEFO'");
} catch (Throwable) {}
// Миграция для файлов, созданных до CSV-загрузки: флаг «требуются обмеры».
try {
    $has = false;
    foreach ($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['name'] ?? '') === 'need_measure') { $has = true; break; }
    }
    if (!$has) $pdo->exec("ALTER TABLE products ADD COLUMN need_measure INTEGER DEFAULT 0");
} catch (Throwable) {}
// Атрибут «Качество» (проставляется приемкой).
try {
    $has = false;
    foreach ($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (($c['name'] ?? '') === 'quality') { $has = true; break; }
    }
    if (!$has) $pdo->exec("ALTER TABLE products ADD COLUMN quality TEXT");
} catch (Throwable) {}
// Тип тары и высота паллеты (для подбора ячейки при размещении по SSCC:
// euro/фин + высота, мм — ячейка обязана принять по высоте и по типу).
foreach (['pallet_type' => "pallet_type TEXT DEFAULT 'euro'", 'pallet_height_mm' => 'pallet_height_mm INTEGER'] as $col => $ddl) {
    try {
        $has = false;
        foreach ($pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === $col) { $has = true; break; }
        }
        if (!$has) $pdo->exec("ALTER TABLE products ADD COLUMN $ddl");
    } catch (Throwable) {}
}
// Разовая чистка сид-мусора: старый сид ставил всем «Легкая промышленность».
// Сбрасываем только нетронутым сид-строкам (Мячи/Wilson/Эталон, габариты 1000³) —
// заведенное вручную не трогаем. Идемпотентно.
try {
    $pdo->exec("UPDATE products SET chz_group = NULL WHERE chz_group = 'Легкая промышленность'
      AND category = 'Мячи' AND brand = 'Wilson' AND owner LIKE '%Эталон%'
      AND length_mm = 1000 AND width_mm = 1000 AND height_mm = 1000 AND weight_g = 12000");
} catch (Throwable) {}

// --- Сид из макета 8, если пусто ---
if ((int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO warehouses (id,name) VALUES (1,'Основной склад'),(2,'Холодильный склад')");
    // Сотрудники — в общей базе (shared/wms_users.sqlite), сиды там.
    $pdo->exec("INSERT INTO product_defaults (field_name,field_value) VALUES ('unit','шт'),('category','Мячи'),('warehouse_id','1'),('storage_strategy','FEFO'),('pick_strategy','FEFO'),('placement_strategy','FEFO')");

    // [sku, name, unit, qty, reserved, active, code] — строки из макета.
    // Внутренний код WMS: T-101, T-102, ... (генератор — shared/ProductCode.php).
    $letters = ['А','Б','В','Г','Д','Е','Ж','З','И','Й','К','Л','М','Н','О','П','Р','С','Т','У','Ф','Х','Ц','Ч'];
    $rows = [];
    $rows[] = ['MK-ASM-1315', 'Мяч баскетбольный А', 'шт', 1250, 150, 1, 'T-101'];
    $rows[] = ['MK-ASM-1316', 'Мяч баскетбольный Б', 'кор', 600, 600, 1, 'T-102'];
    for ($i = 2; $i < count($letters); $i++) {
        $n = 1315 + $i;
        $units = ['шт', 'кор'];
        $unit = ($letters[$i] === 'А' || $letters[$i] === 'Й' || $letters[$i] === 'П' || $letters[$i] === 'Т') ? 'шт' : 'кор';
        $qty = ($letters[$i] === 'А') ? 1250 : (($letters[$i] === 'Т') ? 1200 : (($letters[$i] === 'Ж' || $letters[$i] === 'Х') ? 0 : 600));
        $res = ($i === 0) ? 150 : (($i === 1) ? 600 : 0);
        $act = ($letters[$i] === 'Ж' || $letters[$i] === 'Х') ? 0 : 1;
        // код: T-101 + i
        $rows[] = ["MK-ASM-$n", "Мяч баскетбольный {$letters[$i]}", $unit, $qty, $res, $act, 'T-' . (101 + $i)];
    }
    $ins = $pdo->prepare("INSERT INTO products (code,sku,name,unit,category,brand,owner,warehouse_id,description,length_mm,width_mm,height_mm,weight_g,volume_m3,qty_in_box,boxes_per_pallet,storage_strategy,pick_strategy,temp_from,temp_to,shelf_life_days,marking_start,chz_group,max_expiry_pct,is_batch,is_expiry,is_serial,is_chz,is_danger,allow_partial_pick,need_check,need_pack,auto_replenish,qty,reserved_qty,is_active,created_at) VALUES (:code,:sku,:name,:unit,'Мячи','Wilson','ООО «Эталон»',1,:descr,1000,1000,1000,12000,1.0,10,20,'FEFO','FEFO',5,25,365,'2025-01-01',NULL,80,0,1,0,0,:danger,0,0,1,0,:qty,:res,:act,'2025-06-01 10:00:00')");
    $bc = $pdo->prepare("INSERT INTO product_barcodes (product_id, barcode) VALUES (:p,:b)");
    foreach ($rows as $k => $r) {
        [$sku, $name, $unit, $qty, $res, $act, $code] = $r;
        $danger = ($k === 0) ? 1 : 0;
        $ins->execute([':code' => $code, ':sku' => $sku, ':name' => $name, ':unit' => $unit,
            ':descr' => 'Мяч баскетбольный Wilson NCAA Replica. Для игры в зале и на улице',
            ':danger' => $danger, ':qty' => $qty, ':res' => $res, ':act' => $act]);
        $pid = (int)$pdo->lastInsertId();
        // ШК из макета: для первой позиции два ШК, для остальных по одному.
        // Штрихкоды (EAN) — числовые, от внутреннего кода T... не зависят.
        if ($k === 0) {
            $bc->execute([':p' => $pid, ':b' => '2178899952100']);
            $bc->execute([':p' => $pid, ':b' => '4673192631679']);
        } else {
            $bc->execute([':p' => $pid, ':b' => '21788999' . (521 + $k) . '00']);
        }
    }
    // Товарный запас для первой карточки (нижняя панель)
    $pid1 = (int)$pdo->query("SELECT id FROM products WHERE sku='MK-ASM-1315'")->fetchColumn();
    $pdo->prepare("INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,qty) VALUES ($pid1,1,'A-01-01','MEZ223895',1000),($pid1,1,'B-02-03',NULL,250)")->execute();
    // История изменений (вкладка 3)
    $pdo->exec("INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value,created_at) VALUES
      ($pid1,1788,'1788 - Смирнов Николай','weight_g','Вес','10 500','12 000','2025-06-06 10:33:00'),
      ($pid1,512,'512 - Иванов Михаил','shelf_life_days','Срок годности','120','365','2025-06-07 09:15:00'),
      ($pid1,1788,'1788 - Смирнов Николай','temp','Температур. режим','+10...+20','+5...+25','2025-06-12 14:20:00')");
    // Движение (вкладка 3)
    $pdo->exec("INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance) VALUES
      ($pid1,'2025-05-22 11:30:00','receipt','Поступление №П-1287','A-01-01',1000,1000),
      ($pid1,'2025-05-24 15:45:00','move','Перемещение №ПР-452','B-02-03',-500,500),
      ($pid1,'2025-05-26 09:20:00','shipment','Отгрузка №О-778','B-02-03',-200,500)");
}

// --- Сид уведомлений по схеме 8 v31 (панель колокола), если пусто ---
if ((int)$pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO notifications (kind,title,body,sku,is_important,is_read,created_at) VALUES
      ('shipment','Отгрузка S-205107 доставлена','Заказ 317889621 - Почта России',NULL,0,0,'2025-08-14 10:25:00'),
      ('receipt','Поступление A-105106 принято с отклонениями','Расхождение: -12шт. T-101 MK-ASM-1315 Мяч баскетбольный А','MK-ASM-1315',0,0,'2025-08-14 09:42:00'),
      ('task','Задание 641105514 просрочено','Тип: Отбор, Исполнитель: 78229 - Феничев Олег',NULL,0,0,'2025-08-14 09:17:00'),
      ('nomenclature','Изменена карточка товара T-101 MK-ASM-1315 Мяч баскетбольный А','Новый вес: 1250 г, внес изменения: 78229 - Феничев Олег','MK-ASM-1315',0,1,'2025-08-14 09:02:00')");
}
if ((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE created_at LIKE '2025-08-13%'")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO notifications (kind,title,body,sku,is_important,is_read,created_at) VALUES
      ('shipment','Отгрузка S-205101 доставлена','Заказ 317889600 - Почта России',NULL,0,1,'2025-08-13 22:20:00'),
      ('receipt','Поступление A-105106 принято','Без расхождений: T-102 MK-ASM-1316','MK-ASM-1316',0,1,'2025-08-13 19:18:00'),
      ('task','Задание 641105500 выполнено','Тип: Отбор, Исполнитель: 78229 - Феничев Олег',NULL,0,1,'2025-08-13 19:03:00'),
      ('nomenclature','Изменена карточка товара T-102 MK-ASM-1316 Мяч баскетбольный Б','Новый остаток: 600, внес изменения: 512 - Иванов Михаил','MK-ASM-1316',0,1,'2025-08-13 18:40:00')");
}
if ((int)$pdo->query("SELECT COUNT(*) FROM notification_subscriptions")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO notification_subscriptions (user_id,event_kind,ch_web,ch_email,ch_tg,cond_label,is_on) VALUES
      (1,'receipt',0,0,0,'Все статусы',1),
      (1,'shipment',0,1,0,'Расхождение, Отменено',1),
      (1,'task',1,0,1,'Просрочено',1),
      (1,'nomenclature',0,1,0,'Любое изменение',0)");
}
endif;

$repo = new ProductRepository($pdo);
$service = new ProductService($pdo);
$userId = 1;
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        echo json_encode($repo->paginate(ProductFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'show') {
        $row = $repo->findById((int)($_GET['id'] ?? 0));
        if (!$row) http_response_code(404);
        echo json_encode($row ?? ['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'stock') {
        echo json_encode($repo->stock((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'stock_by_receipt') {
        echo json_encode($repo->stockByReceipt((string)($_GET['doc_no'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'sku_reserve') {
        // В каких отгрузках артикул в резерве (задания на отбор):
        // [{doc_no, pallet, tasks[], qty}]. Группировка по паре (отгрузка, паллета).
        // Связка: task_items.sku -> tasks.doc_ref (номера отгрузок через запятую).
        $sku = trim((string)($_GET['sku'] ?? ''));
        $out = [];
        if ($sku !== '') {
            require_once __DIR__ . '/../shared/PickIssue.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Counterparties.php';
            try {
                $tpdos = wms_tasks_pdos();
                $tpdo = $tpdos[0] ?? null;
                if ($tpdo) {
                    $tbl = wms_task_tables($tpdo);
                    $st = $tpdo->prepare(
                        "SELECT t.doc_no AS task_no, t.doc_ref, t.status,
                                i.cell_from, i.pallet, i.plan_qty, i.fact_qty
                         FROM {$tbl['i']} i JOIN {$tbl['t']} t ON t.id = i.task_id
                         WHERE i.sku = :s AND t.flags LIKE '%pick%' AND t.status IN ('new','work','paused')"
                    );
                    $st->execute([':s' => $sku]);
                    $byShip = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $need = max(0, (float)$r['plan_qty'] - (float)$r['fact_qty']);
                        if ($need <= 0) {
                            continue;
                        }
                        $pal = trim((string)($r['pallet'] ?? ''));
                        foreach (explode(',', (string)($r['doc_ref'] ?? '')) as $dn) {
                            $dn = trim($dn);
                            if ($dn === '') {
                                continue;
                            }
                            $key = $dn . "\x00" . $pal;
                            if (!isset($byShip[$key])) {
                                $byShip[$key] = ['doc_no' => $dn, 'pallet' => ($pal !== '' ? $pal : null), 'tasks' => [], 'qty' => 0.0];
                            }
                            if (!in_array($r['task_no'], $byShip[$key]['tasks'], true)) {
                                $byShip[$key]['tasks'][] = $r['task_no'];
                            }
                            $byShip[$key]['qty'] += $need;
                        }
                    }
                    ksort($byShip);
                    $out = array_values($byShip);
                }
            } catch (Throwable) {
            }
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'history') {
        echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'movements') {
        echo json_encode($repo->movements((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'documents') {
        echo json_encode($repo->documents((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'tasks') {
        echo json_encode($repo->tasks((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'defaults') {
        echo json_encode($repo->defaults(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'counterparties') {
        // Список клиентов (контрагентов) для выпадающего списка в карточке товара.
        $list = [];
        foreach (WmsCounterparties::listAll($pdo, true) as $cp) {
            $list[] = [
                'id' => (int)($cp['id'] ?? 0),
                'name' => (string)($cp['name'] ?? ''),
                'reserve_strategy' => (string)($cp['reserve_strategy'] ?? WmsCounterparties::DEFAULT_STRATEGY),
                'scan_serial_in' => (int)($cp['scan_serial_in'] ?? 0) ? 1 : 0,
                'scan_serial_out' => (int)($cp['scan_serial_out'] ?? 0) ? 1 : 0,
            ];
        }
        echo json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'defaults_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        // Роль берём из запроса (демо): role=admin. В проде — из сессии/JWT.
        $role = (string)($b['role'] ?? $_GET['role'] ?? 'admin');
        unset($b['role']);
        echo json_encode(['saved' => $service->saveDefaults($b, $userId, $role)], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $service->create($b, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $service->update((int)($_GET['id'] ?? 0), $b, $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->copy((int)($_GET['id'] ?? 0), $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->block((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'unblock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->unblock((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->archive((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->delete((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'mass' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $n = $service->massUpdate((array)($b['ids'] ?? []), (array)($b['patch'] ?? []), $userId);
        echo json_encode(['updated' => $n], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'print') {
        $pId = (int)($_GET['id'] ?? 0);
        $pKind = (string)($_GET['kind'] ?? 'barcode');
        $pBc = isset($_GET['bc']) ? (string)$_GET['bc'] : null;
        $pFmt = (string)($_GET['format'] ?? '');
        $pW = isset($_GET['w']) ? (float)$_GET['w'] : 58.0;
        $pH = isset($_GET['h']) ? (float)$_GET['h'] : 40.0;
        $pN = isset($_GET['copies']) ? max(1, min(999, (int)$_GET['copies'])) : 1;
        $pDpi = isset($_GET['dpi']) ? (int)$_GET['dpi'] : 203;
        if ($pFmt === 'zpl') {
            require_once __DIR__ . '/../shared/Zpl.php';
            try {
                $pd = $service->printData($pId, $pKind, $pBc);
                $zpl = wms_zpl_product_barcode((string)($pd['sku'] ?? ''), (string)($pd['name'] ?? ''), (string)($pd['barcode'] ?? ''), $pW, $pH, $pN, $pDpi);
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . wms_zpl_filename((string)($pd['sku'] ?? 'barcode'), $pId) . '"');
                }
                echo $zpl;
            } catch (Throwable $e) {
                http_response_code(400);
                if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка ZPL: ' . $e->getMessage();
            }
            exit;
        }
        if ($pFmt === 'label') {
            require_once __DIR__ . '/../shared/Zpl.php';
            try {
                $pd = $service->printData($pId, $pKind, $pBc);
                if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
                echo wms_label_html((string)($pd['sku'] ?? ''), (string)($pd['name'] ?? ''), (string)($pd['barcode'] ?? ''), $pW, $pH, $pN, isset($_GET['autoprint']));
            } catch (Throwable $e) {
                http_response_code(400);
                if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка этикетки: ' . $e->getMessage();
            }
            exit;
        }
        if ($pFmt === 'pdf') {
            require_once __DIR__ . '/../shared/Pdf.php';
            try {
                $pd = $service->printData($pId, $pKind, $pBc);
                if ($pKind === 'barcode') {
                    if (($pd['barcode'] ?? '') === '') throw new RuntimeException('У товара нет штрихкода');
                    wms_pdf_output(
                        wms_pdf_product_barcode((string)($pd['sku'] ?? ''), (string)($pd['name'] ?? ''), (string)$pd['barcode']),
                        wms_pdf_filename('barcode_' . ($pd['sku'] ?? $pKind), $pId)
                    );
                } else {
                    wms_pdf_output(wms_pdf_auto($pd), wms_pdf_filename($pKind, $pId));
                }
            } catch (Throwable $e) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка PDF: ' . $e->getMessage();
            }
            exit;
        }
        echo json_encode($service->printData($pId, $pKind, $pBc), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'notif_list') {
        echo json_encode(Notifications::list($pdo, (string)($_GET['tab'] ?? 'all'), isset($_GET['search']) ? (string)$_GET['search'] : null, (string)($_GET['kind'] ?? $_GET['type'] ?? ''), (int)($_GET['limit'] ?? 50)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'notif_counts') {
        echo json_encode(Notifications::counts($pdo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'notif_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        Notifications::markRead($pdo, (int)($b['id'] ?? $_GET['id'] ?? 0));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_read_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(['updated' => Notifications::markAllRead($pdo)], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_subs') {
        echo json_encode(Notifications::subscriptions($pdo, $userId), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'notif_sub_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        echo json_encode(Notifications::saveSubscription($pdo, $b, $userId), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_sub_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        Notifications::deleteSubscription($pdo, (string)($b['event_kind'] ?? $_GET['event_kind'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
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
    } elseif ($action === 'export') {
        $f = ProductFilter::fromArray($_GET);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nomenclature.csv"');
        echo $service->exportCsv($f, $repo);
        exit;
    } elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Массовое создание: JSON {rows:[{sku,code,name,...}]} либо CSV-файл (multipart).
        $rows = [];
        if (!empty($_FILES['file']['tmp_name'])) {
            $txt = file_get_contents($_FILES['file']['tmp_name']);
            $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
            $lines = preg_split('/\r?\n/', trim($txt));
            $head = str_getcsv(array_shift($lines) ?: '', ';');
            foreach ($lines as $ln) {
                if (!trim($ln)) continue;
                $row = str_getcsv($ln, ';');
                $assoc = [];
                foreach ($head as $i => $h) $assoc[trim($h)] = $row[$i] ?? '';
                // маппинг русских заголовков экспорта
                $rows[] = [
                    'code' => $assoc['Код'] ?? '', 'sku' => $assoc['Артикул'] ?? '',
                    'name' => $assoc['Наименование'] ?? '', 'unit' => $assoc['Ед.изм'] ?? 'шт',
                    'category' => $assoc['Категория'] ?? 'Мячи', 'brand' => $assoc['Бренд'] ?? '',
                    'owner' => $assoc['Владелец'] ?? '', 'barcode' => $assoc['Штрихкоды'] ?? '',
                ];
            }
        } else {
            $b = json_decode(file_get_contents('php://input'), true) ?? [];
            $rows = $b['rows'] ?? [];
        }
        echo json_encode($service->importRows($rows, $userId), JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action, use ?action=list|show|stock|history|movements|documents&id=1'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
