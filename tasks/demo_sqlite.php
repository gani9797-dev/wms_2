<?php
declare(strict_types=1);
// Демо "Заданий" без MySQL: SQLite + те же классы реестра.
// Открыть: /tasks/demo_sqlite.php?action=list
// Вкладки: ?action=show|items|history|documents&id=1
// ПКМ: POST ?action=status|pause|restart|priority|assign|auto&id=1

require_once __DIR__ . '/TaskStatus.php';
require_once __DIR__ . '/TaskFilter.php';
require_once __DIR__ . '/TaskRepository.php';
require_once __DIR__ . '/TaskService.php';
require_once __DIR__ . '/../shared/db.php';

// Единая БД заданий (тот же файл, что у ТСД и модуля 07).
$pdo = wms_tasks_pdo();

// --- Схема SQLite (упрощенная; прод — tasks/schema.sql на MySQL) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS warehouses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, fio TEXT NOT NULL, role TEXT DEFAULT 'storekeeper')");
$pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT, doc_no TEXT NOT NULL UNIQUE, type TEXT NOT NULL DEFAULT 'сбор',
  status TEXT NOT NULL DEFAULT 'new', priority TEXT NOT NULL DEFAULT 'normal',
  warehouse_id INTEGER NOT NULL, doc_ref TEXT, order_ref TEXT,
  executor_id INTEGER, executor_fio TEXT,
  plan_qty REAL DEFAULT 0, fact_qty REAL DEFAULT 0, progress INTEGER DEFAULT 0,
  cells_count INTEGER DEFAULT 0, volume REAL DEFAULT 0, weight REAL DEFAULT 0,
  equipment TEXT, flags TEXT, need_check INTEGER DEFAULT 0, comment TEXT,
  due_date TEXT, done_at TEXT, paused_at TEXT,
  created_at TEXT DEFAULT (datetime('now')), updated_at TEXT DEFAULT (datetime('now')))");
$pdo->exec("CREATE TABLE IF NOT EXISTS task_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
  code TEXT, sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
  cell_from TEXT, cell_to TEXT, pallet TEXT,
  plan_qty REAL DEFAULT 0, fact_qty REAL DEFAULT 0,
  state TEXT, executor_fio TEXT)");
$pdo->exec("CREATE TABLE IF NOT EXISTS task_history (
  id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL, user_id INTEGER,
  action TEXT NOT NULL, field TEXT, old_value TEXT, new_value TEXT,
  created_at TEXT DEFAULT (datetime('now')))");

// --- Сид из макета, если пусто ---
if ((int)$pdo->query("SELECT COUNT(*) FROM tasks")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO warehouses (id,name) VALUES (1,'Основной склад'),(2,'Холодильный склад')");
    $pdo->exec("INSERT INTO users (id,fio,role) VALUES
      (1,'Иванов И.И.','Администратор'),(11,'11 Ибрагимов Е.С.','Кладовщик'),
      (233,'233 Петрова М.А.','Кладовщик'),(258,'258 Смирнов А.С.','Кладовщик'),
      (819,'819 Шветцов А.В.','Кладовщик'),(1220,'1220 Михайлова О.С.','Кладовщик'),
      (1287,'1287 Ш Феничев Олег','Кладовщик'),(1465,'1465 Ионова М.И.','Кладовщик'),
      (3315,'3315 Иванов П.М.','Кладовщик')");

    // doc_no, created, type, status, priority, wh, doc_ref, order_ref, exec_id, exec_fio, plan, fact, prog, cells, vol, weight, due
    $rows = [
        ['TASK-202506-02456', '2025-06-22 12:01', 'сбор',        'done',      'low',    1, 'S-205107', null, 1465, '1465 Ионова М.И.',    15, 15, 100, 8,  2.10, 12.0, '2025-06-22'],
        ['TASK-202506-02450', '2025-06-21 18:18', 'размещение',  'work',      'normal', 1, '10002458П', null, 258,  '258 Смирнов А.С.',     30, 20, 70,  3,  1.20, 8.0,  '2025-06-23'],
        ['TASK-202506-02417', '2025-06-21 12:05', 'перемещение', 'new',       'normal', 2, null,        null, 3315, '3315 Иванов П.М.',     5,  0,  0,   1,  0.40, 2.0,  '2025-06-23'],
        ['TASK-202506-02308', '2025-06-21 18:18', 'сбор',        'cancelled', 'high',   1, 'S-205106', null, 1220, '1220 Михайлова О.С.', 12, 0,  0,   12, 1.00, 6.0,  '2025-06-23'],
        ['TASK-202506-02300', '2025-06-21 12:05', 'сбор',        'paused',    'high',   1, 'S-205105', null, 819,  '819 Шветцов А.В.',    40, 14, 39,  9,  3.10, 20.0, '2025-06-23'],
        ['TASK-202506-02250', '2025-06-21 12:05', 'перемещение', 'work',      'normal', 2, null,        null, 233,  '233 Петрова М.А.',    30, 3,  3,   7,  2.40, 15.0, '2025-06-24'],
        ['TASK-202506-02210', '2025-06-21 17:10', 'перемещение', 'new',       'low',    1, null,        null, 11,   '11 Ибрагимов Е.С.',   90, 0,  0,   4,  5.00, 40.0, '2025-06-24'],
    ];
    $st = $pdo->prepare("INSERT INTO tasks (doc_no,created_at,type,status,priority,warehouse_id,doc_ref,order_ref,executor_id,executor_fio,plan_qty,fact_qty,progress,cells_count,volume,weight,due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($rows as $r) {
        $st->execute($r);
    }

    // Детализация по первому заданию (строка из макета)
    $tid = (int)$pdo->query("SELECT id FROM tasks WHERE doc_no='TASK-202506-02456'")->fetchColumn();
    $pdo->prepare("INSERT INTO task_items (task_id,code,sku,name,unit,cell_from,cell_to,pallet,plan_qty,fact_qty,state,executor_fio) VALUES (:t,:code,:sku,:name,'шт',:cf,:ct,:pal,:pq,:fq,:st,:ex)")->execute([
        ':t' => $tid, ':code' => 'T-101', ':sku' => 'MK-ASM-1315', ':name' => 'Мяч баскетбольный А',
        ':cf' => 'Приемка_9', ':ct' => 'М33-106-3-10', ':pal' => 'MEZ223895',
        ':pq' => 10, ':fq' => 10, ':st' => 'Размещен', ':ex' => '1287 Ш Феничев Олег',
    ]);
    // Парочка строк для второго задания
    $tid2 = (int)$pdo->query("SELECT id FROM tasks WHERE doc_no='TASK-202506-02450'")->fetchColumn();
    $ins = $pdo->prepare("INSERT INTO task_items (task_id,code,sku,name,cell_from,cell_to,pallet,plan_qty,fact_qty,state,executor_fio) VALUES (:t,:code,:sku,:name,:cf,:ct,:pal,:pq,:fq,:st,:ex)");
    $ins->execute([':t' => $tid2, ':code' => 'T-102', ':sku' => 'MK-ASM-1316', ':name' => 'Мяч баскетбольный Б', ':cf' => 'Приемка_9', ':ct' => 'М33-106-3-11', ':pal' => 'MEZ223896', ':pq' => 20, ':fq' => 12, ':st' => 'В размещении', ':ex' => '258 Смирнов А.С.']);
    $ins->execute([':t' => $tid2, ':code' => 'T-103', ':sku' => 'MK-ASM-1317', ':name' => 'Мяч баскетбольный В', ':cf' => 'Приемка_9', ':ct' => 'М33-106-3-12', ':pal' => 'MEZ223897', ':pq' => 10, ':fq' => 8, ':st' => 'В размещении', ':ex' => '258 Смирнов А.С.']);

    $pdo->exec("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES ($tid,1,'create',NULL,NULL,'TASK-202506-02456')");
}

$repo = new TaskRepository($pdo);
$service = new TaskService($pdo);
$userId = 1;
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        echo json_encode($repo->paginate(TaskFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'show') {
        $row = $repo->findById((int)($_GET['id'] ?? 0));
        if (!$row) {
            http_response_code(404);
        }
        echo json_encode($row ?? ['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'items') {
        echo json_encode($repo->items((int)($_GET['id'] ?? 0), (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'history') {
        echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'documents') {
        echo json_encode($repo->documents((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $service->create($b, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $service->changeStatus((int)($_GET['id'] ?? 0), TaskStatus::from($b['to'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'pause' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->pause((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'restart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->restart((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'priority' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $service->priority((int)($_GET['id'] ?? 0), (string)($b['priority'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $service->assign((int)($_GET['id'] ?? 0), isset($b['executor_id']) && $b['executor_id'] !== '' ? (int)$b['executor_id'] : null, (string)($b['executor_fio'] ?? $b['executor'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->autoRun((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->delete((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete_many' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = $b['ids'] ?? $b;
        $n = $service->deleteMany((array)$ids, $userId);
        echo json_encode(['ok' => true, 'deleted' => $n], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        // ТСД-скан: {item_id, qty} либо {task_id, sku/code, qty}
        $qty = (float)($b['qty'] ?? 1);
        if (!empty($b['item_id'])) {
            $service->pick((int)$b['item_id'], $qty, $userId);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } else {
            $taskId = (int)($b['task_id'] ?? $_GET['id'] ?? 0);
            $code = trim((string)($b['code'] ?? $b['sku'] ?? ''));
            if ($taskId <= 0 || $code === '') {
                http_response_code(422);
                echo json_encode(['error' => 'нужны item_id либо task_id+code'], JSON_UNESCAPED_UNICODE);
            } else {
                $st = $pdo->prepare("SELECT * FROM task_items WHERE task_id=:t AND (sku=:c OR code=:c) LIMIT 1");
                $st->execute([':t' => $taskId, ':c' => $code]);
                $it = $st->fetch();
                if (!$it) {
                    $like = $pdo->prepare("SELECT * FROM task_items WHERE task_id=:t AND (sku LIKE :l OR code LIKE :l OR name LIKE :l) LIMIT 1");
                    $like->execute([':t' => $taskId, ':l' => '%' . $code . '%']);
                    $it = $like->fetch();
                }
                if (!$it) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Позиция не найдена: ' . $code], JSON_UNESCAPED_UNICODE);
                } else {
                    $newQty = (float)$it['fact_qty'] + $qty;
                    $plan = (float)$it['plan_qty'];
                    if ($plan > 0 && $newQty - $plan > 1e-9) {
                        http_response_code(409);
                        echo json_encode(['error' => 'Больше плана: к выполнению ' . max(0, $plan - (float)$it['fact_qty']), 'need' => max(0, $plan - (float)$it['fact_qty'])], JSON_UNESCAPED_UNICODE);
                    } else {
                        $service->pick((int)$it['id'], $newQty, $userId);
                        echo json_encode(['ok' => true, 'sku' => $it['sku'], 'fact_qty' => $newQty], JSON_UNESCAPED_UNICODE);
                    }
                }
            }
        }
    } elseif ($action === 'print') {
        echo json_encode($service->printData((int)($_GET['id'] ?? 0), (string)($_GET['kind'] ?? 'list')), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
        echo json_encode(['error' => 'unknown action, use ?action=list|show|items|history|documents&id=1'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
