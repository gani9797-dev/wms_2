<?php
declare(strict_types=1);
// ТСД «Задания» — единый tasks API поверх модуля TL Задания (31.08.2026).
// Источник: /tasks/wms_tasks.sqlite (те же таблицы, что и tasks/demo_sqlite.php):
//   tasks / task_items / task_history (+ warehouses, users).
// Типы: сбор (=отбор), размещение, перемещение, комплектация, пополнение.
// Кнопки ТСД: Размещение -> ?type=размещение, Перемещение -> ?type=перемещение,
//   Отбор (otbor.html) остается на отгрузках, Приемка/Отгрузка — на receipts/shipments.
//
// Авторизация: ?token=tsd-12345, заголовок X-Tsd-Token или сессия /login.
// Actions: tasks | task | take | release | confirm | problem | move_to_sort | pick(алиас confirm)

const TSD_TASKS_TOKEN = 'tsd-12345';
const TSD_TASKS_WAIT_SEC = 120; // «в ожидании» -> возврат в доступ через 2 мин

function tt_ok(): bool
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
    if ($t !== '' && hash_equals(TSD_TASKS_TOKEN, (string)$t)) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return isset($_SESSION['wms_auth_user']);
}

function tj(mixed $d, int $c = 200): void
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function tdb(): PDO
{
    $dbFile = __DIR__ . '/../tasks/wms_tasks.sqlite';
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Схема — как в tasks/demo_sqlite.php (без сида: сидирует демо-скрипт).
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
    // Закрепление заданий за сотрудниками ТСД + журнал проблем/сортировки.
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_task_locks (task_id INTEGER PRIMARY KEY, user_id INTEGER, user_fio TEXT, status TEXT NOT NULL DEFAULT 'work', updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_task_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER, cell TEXT, sku TEXT, user_id INTEGER, reason TEXT, created_at TEXT DEFAULT (datetime('now')))");
    return $pdo;
}

function t_free_waiting(PDO $pdo): void
{
    $pdo->exec("DELETE FROM tsd_task_locks WHERE status='waiting' AND (strftime('%s','now')-strftime('%s',updated_at))>" . TSD_TASKS_WAIT_SEC);
}

function tt_lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    $map = ['РАЗМЕЩЕНИЕ' => 'размещение', 'Размещение' => 'размещение',
        'ПЕРЕМЕЩЕНИЕ' => 'перемещение', 'Перемещение' => 'перемещение',
        'КОМПЛЕКТАЦИЯ' => 'комплектация', 'Комплектация' => 'комплектация',
        'ПОПОЛНЕНИЕ' => 'пополнение', 'Пополнение' => 'пополнение',
        'СБОР' => 'сбор', 'Сбор' => 'сбор', 'ОТБОР' => 'сбор', 'Отбор' => 'сбор'];
    return $map[$s] ?? strtolower($s);
}

/** Нормализация типа из кнопок ТСД: put/move/pick + русские названия. */
function t_norm_type(string $v): ?string
{
    $v = tt_lower(trim($v));
    $map = [
        'put' => 'размещение', 'размещение' => 'размещение',
        'move' => 'перемещение', 'перемещение' => 'перемещение',
        'pick' => 'сбор', 'отбор' => 'сбор', 'сбор' => 'сбор',
        'комплектация' => 'комплектация', 'пополнение' => 'пополнение',
    ];
    return $map[$v] ?? null;
}

/** Ячейка для сканирования по типу задания. */
function t_cell(array $head, array $item): string
{
    $type = tt_lower((string)($head['type'] ?? ''));
    if ($type === 'размещение') {
        return (string)($item['cell_to'] ?? $item['cell_from'] ?? '');
    }
    // сбор/перемещение/остальное: откуда брать
    return (string)($item['cell_from'] ?? $item['cell_to'] ?? '');
}

if (!tt_ok()) {
    tj(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
}

$pdo = tdb();
$action = $_GET['action'] ?? 'tasks';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Список заданий модуля для ТСД: ?action=tasks&type=размещение|перемещение|сбор&role=driver|storekeeper ---
// Роль фильтрует задания отбора по уровню ячейки: водитель => уровень >= 2, кладовщик => уровень = 1.
if ($action === 'tasks') {
    t_free_waiting($pdo);
    require_once __DIR__ . '/../shared/PickIssue.php';
    $type = isset($_GET['type']) ? t_norm_type((string)$_GET['type']) : null;
    $role = strtolower(trim((string)($_GET['role'] ?? '')));
    if (!in_array($role, ['driver', 'storekeeper', 'водитель', 'кладовщик'], true)) {
        $role = '';
    }
    if ($role === 'водитель') {
        $role = 'driver';
    } elseif ($role === 'кладовщик') {
        $role = 'storekeeper';
    }
    $where = "t.status IN ('new','work','paused')";
    $params = [];
    if ($type !== null) {
        $where .= " AND t.type = :tp";
        $params[':tp'] = $type;
    }
    $rows = $pdo->prepare(
        "SELECT t.id, t.doc_no, t.type, t.status, t.priority, t.due_date, t.doc_ref, t.order_ref,
                t.executor_id, t.executor_fio, t.plan_qty, t.fact_qty, t.progress, t.cells_count,
                t.warehouse_id, w.name AS warehouse,
                l.user_id AS lock_user, l.user_fio AS lock_fio, l.status AS lock_status
         FROM tasks t LEFT JOIN warehouses w ON w.id=t.warehouse_id
         LEFT JOIN tsd_task_locks l ON l.task_id=t.id
         WHERE $where ORDER BY
            CASE t.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 WHEN 'lowest' THEN 4 ELSE 5 END,
           t.due_date IS NULL, t.due_date ASC, t.id ASC LIMIT 100"
    );
    $rows->execute($params);
    $list = $rows->fetchAll();
    // Уровни ячеек по позициям (для фильтра по роли)
    $levelsByTask = [];
    if ($list) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $list));
        try {
            $q = $pdo->query("SELECT task_id, cell_from, cell_to FROM task_items WHERE task_id IN ($ids)");
            foreach ($q->fetchAll() as $it) {
                $tid = (int)$it['task_id'];
                foreach ([($it['cell_from'] ?? ''), ($it['cell_to'] ?? '')] as $c) {
                    $lvl = wms_pick_cell_level((string)$c);
                    if ($lvl !== null) {
                        $levelsByTask[$tid][] = $lvl;
                    }
                }
            }
        } catch (Throwable) {
        }
    }
    if ($role !== '') {
        $list = array_values(array_filter($list, function ($t) use ($levelsByTask, $role) {
            $lvls = $levelsByTask[(int)$t['id']] ?? [];
            if (!$lvls) {
                return true; // старые задания без уровня — видны всем
            }
            foreach ($lvls as $lvl) {
                if ($role === 'driver' && $lvl >= 2) {
                    return true;
                }
                if ($role === 'storekeeper' && $lvl === 1) {
                    return true;
                }
            }
            return false;
        }));
    }
    $prio = 0;
    foreach ($list as &$r) {
        $prio++;
        $r['prio'] = $prio; // 1 = наивысший
        $plan = (float)($r['plan_qty'] ?? 0);
        $fact = (float)($r['fact_qty'] ?? 0);
        $r['pct'] = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
        $lvls = $levelsByTask[(int)$r['id']] ?? [];
        if ($lvls) {
            $r['levels'] = array_values(array_unique($lvls));
            $r['min_level'] = min($lvls);
            $r['max_level'] = max($lvls);
        }
    }
    // Волна: пересечение SKU между заданиями одного типа.
    $need = [];
    if (count($list) >= 2) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $list));
        $q = $pdo->query("SELECT sku, COUNT(DISTINCT task_id) c, SUM(plan_qty-fact_qty) q FROM task_items WHERE task_id IN ($ids) GROUP BY sku HAVING c>1");
        foreach ($q->fetchAll() as $w) {
            $need[$w['sku']] = ['ships' => (int)$w['c'], 'qty' => (float)$w['q']];
        }
    }
    tj(['rows' => $list, 'type' => $type, 'role' => ($role !== '' ? $role : null),
        'role_hint' => $role === 'driver' ? 'Водитель: уровень ячейки >= 2' : ($role === 'storekeeper' ? 'Кладовщик: уровень ячейки = 1' : null),
        'wave' => ['possible' => !empty($need), 'cross_sku' => $need,
            'hint' => !empty($need) ? 'Есть пересечение SKU — можно волной' : null]]);
}

// --- Карточка задания: шапка + строки с need и ячейкой для скана ---
if ($action === 'task') {
    $id = (int)($_GET['id'] ?? 0);
    $h = $pdo->prepare('SELECT t.*, w.name AS warehouse FROM tasks t LEFT JOIN warehouses w ON w.id=t.warehouse_id WHERE t.id=:id');
    $h->execute([':id' => $id]);
    $head = $h->fetch();
    if (!$head) {
        tj(['error' => 'Задание не найдено'], 404);
    }
    $items = $pdo->prepare('SELECT * FROM task_items WHERE task_id=:id ORDER BY id');
    $items->execute([':id' => $id]);
    $rows = $items->fetchAll();
    foreach ($rows as &$it) {
        $it['cell'] = t_cell($head, $it);
        $it['need'] = max(0, (float)$it['plan_qty'] - (float)$it['fact_qty']);
        $it['photo'] = '📦';
    }
    $lock = $pdo->prepare('SELECT * FROM tsd_task_locks WHERE task_id=:id');
    $lock->execute([':id' => $id]);
    tj(['head' => $head, 'items' => $rows,
        'progress' => ['plan' => (float)$head['plan_qty'], 'fact' => (float)$head['fact_qty'], 'pct' => (int)$head['progress']],
        'lock' => $lock->fetch() ?: null]);
}

// --- Взять задание: статус «в работе», закрепление за сотрудником ---
if ($action === 'take' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($tid <= 0) {
        tj(['error' => 'нужен task_id'], 422);
    }
    t_free_waiting($pdo);
    if ($uid) {
        $mine = $pdo->prepare("SELECT * FROM tsd_task_locks WHERE user_id=:u AND status='work' AND task_id!=:t LIMIT 1");
        $mine->execute([':u' => $uid, ':t' => $tid]);
        if ($row = $mine->fetch()) {
            tj(['error' => 'У тебя уже есть задание в работе: №' . $row['task_id'] . '. Вернись на форму.', 'busy_id' => (int)$row['task_id']], 409);
        }
    }
    $busy = $pdo->prepare('SELECT * FROM tsd_task_locks WHERE task_id=:t');
    $busy->execute([':t' => $tid]);
    if ($bl = $busy->fetch()) {
        if (($bl['status'] ?? '') === 'work' && (int)($bl['user_id'] ?? 0) !== (int)($uid ?? -1)) {
            tj(['error' => 'Задание уже в работе у: ' . ($bl['user_fio'] ?: ('id ' . $bl['user_id']))], 409);
        }
    }
    $head = $pdo->prepare('SELECT status FROM tasks WHERE id=:t');
    $head->execute([':t' => $tid]);
    $st = (string)($head->fetchColumn() ?: '');
    if ($st === '') {
        tj(['error' => 'Задание не найдено'], 404);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO tsd_task_locks (task_id,user_id,user_fio,status,updated_at) VALUES (:t,:u,:f,\'work\',datetime(\'now\'))
            ON CONFLICT(task_id) DO UPDATE SET user_id=:u,user_fio=:f,status=\'work\',updated_at=datetime(\'now\')')
            ->execute([':t' => $tid, ':u' => $uid, ':f' => ($fio !== '' ? $fio : null)]);
        if (in_array($st, ['new', 'paused'], true)) {
            $pdo->prepare("UPDATE tasks SET status='work', executor_id=COALESCE(executor_id,:u), executor_fio=COALESCE(executor_fio,:f) WHERE id=:t")
                ->execute([':u' => $uid, ':f' => ($fio !== '' ? $fio : null), ':t' => $tid]);
        } elseif ($fio !== '') {
            $pdo->prepare("UPDATE tasks SET executor_fio=:f WHERE id=:t AND (executor_fio IS NULL OR executor_fio='')")
                ->execute([':f' => $fio, ':t' => $tid]);
        }
        $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'take','status',:o,'work')")
            ->execute([':t' => $tid, ':u' => $uid, ':o' => $st]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        tj(['error' => $e->getMessage()], 500);
    }
    tj(['ok' => true, 'status' => 'work']);
}

// --- Выход из задания: «в ожидании», таймер 2 мин ---
if ($action === 'release' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($tid <= 0) {
        tj(['error' => 'нужен task_id'], 422);
    }
    $pdo->prepare("UPDATE tsd_task_locks SET status='waiting', updated_at=datetime('now') WHERE task_id=:t")
        ->execute([':t' => $tid]);
    $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'release','status','work','waiting (2 мин)')")
        ->execute([':t' => $tid, ':u' => $uid]);
    tj(['ok' => true, 'status' => 'waiting', 'wait_sec' => TSD_TASKS_WAIT_SEC]);
}

// --- Подтверждение строки ТСД: qty <= остатка; допустимо меньшее (pick/confirm) ---
if (($action === 'confirm' || $action === 'pick') && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $sku = trim((string)($b['sku'] ?? ''));
    $qty = (float)($b['qty'] ?? 0);
    $cell = trim((string)($b['cell'] ?? ''));
    $pallet = trim((string)($b['pallet'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($tid <= 0 || $sku === '' || $qty <= 0) {
        tj(['error' => 'нужны task_id, sku, qty>0'], 422);
    }
    $it = $pdo->prepare('SELECT * FROM task_items WHERE task_id=:t AND (sku=:c OR code=:c) LIMIT 1');
    $it->execute([':t' => $tid, ':c' => $sku]);
    $row = $it->fetch();
    if (!$row) {
        $like = $pdo->prepare('SELECT * FROM task_items WHERE task_id=:t AND (sku LIKE :l OR code LIKE :l OR name LIKE :l) LIMIT 1');
        $like->execute([':t' => $tid, ':l' => '%' . $sku . '%']);
        $row = $like->fetch();
    }
    if (!$row) {
        tj(['error' => 'Позиция не найдена: ' . $sku], 404);
    }
    $need = (float)$row['plan_qty'] - (float)$row['fact_qty'];
    if ($qty - $need > 1e-9) {
        tj(['error' => 'Введено большее количество: к выполнению ' . max(0, $need), 'need' => max(0, $need)], 409);
    }
    require_once __DIR__ . '/../tasks/TaskService.php';
    $svc = new TaskService($pdo);
    try {
        $svc->pick((int)$row['id'], (float)$row['fact_qty'] + $qty, $uid);
        if ($pallet !== '' || $cell !== '') {
            $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'scan',:f,:o,:n)")
                ->execute([':t' => $tid, ':u' => $uid, ':f' => 'Ячейка ' . $cell,
                    ':o' => $row['sku'], ':n' => 'Отсканировано x' . $qty . ' · паллет ' . ($pallet !== '' ? $pallet : '—')]);
        }
    } catch (Throwable $e) {
        tj(['error' => $e->getMessage()], 500);
    }
    $pr = $pdo->query("SELECT plan_qty, fact_qty, progress FROM tasks WHERE id=" . $tid)->fetch();
    tj(['ok' => true, 'progress' => $pr, 'need_left' => max(0, $need - $qty)]);
}

// --- Проблема с товаром: Брак / Нет товара -> Log + история ---
if ($action === 'problem' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $sku = trim((string)($b['sku'] ?? ''));
    $cell = trim((string)($b['cell'] ?? ''));
    $reason = trim((string)($b['reason'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($tid <= 0 || $sku === '' || !in_array($reason, ['брак', 'нет_товара'], true)) {
        tj(['error' => 'нужны task_id, sku, reason=брак|нет_товара'], 422);
    }
    $block = $reason === 'брак' ? 'Брак' : 'Инвентаризация';
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO tsd_task_logs (task_id,cell,sku,user_id,reason) VALUES (:t,:c,:sku,:u,:r)')
            ->execute([':t' => $tid, ':c' => ($cell !== '' ? $cell : null), ':sku' => $sku, ':u' => $uid, ':r' => $block]);
        $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'cell_block',:f,:o,:n)")
            ->execute([':t' => $tid, ':u' => $uid, ':f' => 'Ячейка ' . $cell, ':o' => $sku, ':n' => 'Блокировка: ' . $block . ' + новое резервирование']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        tj(['error' => $e->getMessage()], 500);
    }
    $log = $pdo->query('SELECT id, task_id, cell, sku, user_id, reason, strftime(\'%d.%m.%Y %H:%M\',created_at) AS dt FROM tsd_task_logs ORDER BY id DESC LIMIT 1')->fetch();
    tj(['ok' => true, 'block' => $block, 'log' => $log]);
}

// --- Перенос паллеты в зону сортировки / ворота ---
if ($action === 'move_to_sort' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $pallet = trim((string)($b['pallet'] ?? ''));
    $gateCell = trim((string)($b['gate_cell'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $okMove = (int)($b['moved_ok'] ?? 1);
    if ($tid <= 0 || $pallet === '') {
        tj(['error' => 'нужны task_id и pallet'], 422);
    }
    if (!$okMove) {
        $pdo->prepare('INSERT INTO tsd_task_logs (task_id,cell,sku,user_id,reason) VALUES (:t,:c,:sku,:u,:r)')
            ->execute([':t' => $tid, ':c' => ($gateCell !== '' ? $gateCell : 'ЗОНА-СОРТИРОВКИ'), ':sku' => $pallet, ':u' => $uid, ':r' => 'Ошибка перемещения в зону сортировки']);
        $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'sort_error',:f,:o,:n)")
            ->execute([':t' => $tid, ':u' => $uid, ':f' => 'Паллет ' . $pallet, ':o' => $gateCell, ':n' => 'Не перенесена. Информация передана оператору + уведомление в WMS']);
        $log = $pdo->query('SELECT id, task_id, cell, sku, user_id, reason, strftime(\'%d.%m.%Y %H:%M\',created_at) AS dt FROM tsd_task_logs ORDER BY id DESC LIMIT 1')->fetch();
        tj(['ok' => false, 'log' => $log, 'notify_wms' => true]);
    }
    $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'move_to_sort',:o,:n)")
        ->execute([':t' => $tid, ':u' => $uid, ':o' => 'Паллет ' . $pallet, ':n' => 'Перенесена в зону сортировки (' . $gateCell . ')']);
    $left = (float)$pdo->query("SELECT COALESCE(SUM(plan_qty-fact_qty),0) FROM task_items WHERE task_id=" . $tid)->fetchColumn();
    if ($left <= 1e-9) {
        $pdo->prepare('DELETE FROM tsd_task_locks WHERE task_id=:t')->execute([':t' => $tid]);
    }
    tj(['ok' => true]);
}

tj(['error' => 'unknown action: ?action=tasks|task|take|release|confirm|problem|move_to_sort'], 404);
