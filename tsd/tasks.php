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
    // Единая БД заданий (владелец файла — tasks/wms_tasks.sqlite). См. shared/db.php.
    // Тот же файл читает модуль 07 (07-zadaniya/demo_sqlite.php), поэтому
    // задания с ТСД сразу видны в веб-модуле «Задания».
    require_once __DIR__ . '/../shared/db.php';
    return wms_tasks_pdo();
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

/**
 * Перенос строк товарного запаса (модуль 08.1, product_stock в БД номенклатуры)
 * из ЗОНЫ-ПРИЕМКИ в ячейку размещения.
 * $moves = [['sku' => ..., 'qty' => ...], ...], $cell — код ячейки-приемника.
 * Идемпотентно: если запас уже лежит в целевой ячейке на этой паллете — не дублирует.
 * @return array{moved: array, skipped: string[]}
 */
function tt_move_stock_to_cell(string $sscc, string $cell, array $moves, int $warehouseId, ?string $docNo = null): array
{
    require_once __DIR__ . '/../shared/db.php';
    $moved = [];
    $skipped = [];
    $ndb = wms_nomenclature_pdo();
    // Колонка тары на строке запаса (08.1 тоже ее тянет, но здесь — чтобы не упасть).
    try {
        $has = false;
        foreach ($ndb->query('PRAGMA table_info(product_stock)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === 'pallet_type') {
                $has = true;
                break;
            }
        }
        if (!$has) {
            $ndb->exec('ALTER TABLE product_stock ADD COLUMN pallet_type TEXT');
        }
    } catch (Throwable) {
    }
    // Привязка SSCC к приходу — для точного поиска строки приемки.
    $receiptId = null;
    try {
        $rdb = wms_receipts_pdo();
        $rq = $rdb->prepare('SELECT receipt_id FROM tsd_pallets WHERE sscc=:s LIMIT 1');
        $rq->execute([':s' => $sscc]);
        $receiptId = $rq->fetchColumn() ?: null;
        if (!$receiptId && $sscc !== '') {
            $rq = $rdb->prepare('SELECT receipt_id FROM receipt_operations WHERE pallet=:s LIMIT 1');
            $rq->execute([':s' => $sscc]);
            $receiptId = $rq->fetchColumn() ?: null;
        }
    } catch (Throwable) {
    }
    // Тип тары — с ячейки резерва, иначе из карточки, иначе euro.
    $ptype = 'euro';
    try {
        $sdb = wms_storage_pdo();
        $cs = $sdb->prepare('SELECT pallet_type FROM storage_cells WHERE code=:c'
            . ($warehouseId ? ' AND warehouse_id=:w' : '') . ' LIMIT 1');
        $cp = [':c' => $cell];
        if ($warehouseId) {
            $cp[':w'] = $warehouseId;
        }
        $cs->execute($cp);
        if ($r0 = $cs->fetch()) {
            if (trim((string)($r0['pallet_type'] ?? '')) !== '') {
                $t = strtolower(trim((string)$r0['pallet_type']));
                $ptype = in_array($t, ['фин', 'финская', 'fin'], true) ? 'fin' : 'euro';
            }
        }
    } catch (Throwable) {
    }
    $ndb->beginTransaction();
    try {
        foreach ($moves as $m) {
            $sku = trim((string)($m['sku'] ?? ''));
            $qty = round((float)($m['qty'] ?? 0), 3);
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $ps = $ndb->prepare('SELECT id, qty FROM products WHERE sku=:s LIMIT 1');
            $ps->execute([':s' => $sku]);
            $prod = $ps->fetch();
            if (!$prod) {
                $skipped[] = $sku . ': нет карточки в номенклатуре';
                continue;
            }
            $pid = (int)$prod['id'];
            // Уже размещено этой паллетой? — не дублируем (повторный confirm).
            $tq = $ndb->prepare(
                'SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w AND cell=:c AND '
                . ($sscc !== '' ? 'pallet=:pal' : "COALESCE(pallet,'')=''") . ' LIMIT 1'
            );
            $tp = [':p' => $pid, ':w' => $warehouseId, ':c' => $cell];
            if ($sscc !== '') {
                $tp[':pal'] = $sscc;
            }
            $tq->execute($tp);
            if ($trow = $tq->fetch()) {
                $moved[] = ['sku' => $sku, 'qty' => 0, 'note' => 'уже в ячейке'];
                continue;
            }
            // Источник: строка приемки (тот же приход, ЗОНА-ПРИЕМКИ), иначе любая приемка.
            $src = null;
            if ($receiptId) {
                $sq = $ndb->prepare(
                    "SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w
                     AND cell='ЗОНА-ПРИЕМКИ' AND receipt_id=:r AND qty > 0 ORDER BY id LIMIT 1"
                );
                try {
                    $sq->execute([':p' => $pid, ':w' => $warehouseId, ':r' => $receiptId]);
                    $src = $sq->fetch() ?: null;
                } catch (Throwable) {
                    $src = null;
                }
            }
            if (!$src) {
                $sq = $ndb->prepare(
                    "SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w
                     AND cell='ЗОНА-ПРИЕМКИ' AND qty > 0 ORDER BY id LIMIT 1"
                );
                $sq->execute([':p' => $pid, ':w' => $warehouseId]);
                $src = $sq->fetch() ?: null;
            }
            $short = false;
            if ($src) {
                if ((float)$src['qty'] < $qty) {
                    $short = true;
                }
                $ndb->prepare('UPDATE product_stock SET qty=CASE WHEN qty-:q < 0 THEN 0 ELSE qty-:q END WHERE id=:id')
                    ->execute([':q' => $qty, ':id' => $src['id']]);
            } else {
                $short = true; // строки приемки нет (приход без проброса?) — создаем строку ячейки как факт
            }
            $ins = $ndb->prepare(
                'INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,pallet_type,qty' .
                ($receiptId ? ',receipt_id' : '') . ') VALUES (:p,:w,:c,:pal,:pt,:q' .
                ($receiptId ? ',:r' : '') . ')'
            );
            $ip = [':p' => $pid, ':w' => $warehouseId, ':c' => $cell,
                ':pal' => ($sscc !== '' ? $sscc : null), ':pt' => $ptype, ':q' => $qty];
            if ($receiptId) {
                $ip[':r'] = $receiptId;
            }
            try {
                $ins->execute($ip);
            } catch (Throwable) {
                // Старая схема без receipt_id.
                $ndb->prepare(
                    'INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,pallet_type,qty)
                     VALUES (:p,:w,:c,:pal,:pt,:q)'
                )->execute([':p' => $pid, ':w' => $warehouseId, ':c' => $cell,
                    ':pal' => ($sscc !== '' ? $sscc : null), ':pt' => $ptype, ':q' => $qty]);
            }
            try {
                $ndb->prepare(
                    "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                     VALUES (:p,datetime('now'),'place',:d,:c,:q,:b)"
                )->execute([':p' => $pid, ':d' => 'Размещение ' . ($docNo ?? $sscc),
                    ':c' => $cell, ':q' => $qty, ':b' => (float)($prod['qty'] ?? 0)]);
            } catch (Throwable) {
            }
            $moved[] = ['sku' => $sku, 'qty' => $qty] + ($short ? ['note' => 'в приемке не хватило/нет строки'] : []);
        }
        $ndb->commit();
    } catch (Throwable $e) {
        try {
            $ndb->rollBack();
        } catch (Throwable) {
        }
        throw $e;
    }
    return ['moved' => $moved, 'skipped' => $skipped];
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
    // Размещение: отсканированная ячейка обязана совпадать с предложенной.
    $tt = $pdo->prepare('SELECT type FROM tasks WHERE id=:t');
    $tt->execute([':t' => $tid]);
    $taskType = trim((string)($tt->fetchColumn() ?? ''));
    if ($taskType === 'размещение' && $cell !== '' && trim((string)($row['cell_to'] ?? '')) !== '') {
        $same = function_exists('mb_strtoupper')
            ? mb_strtoupper($cell, 'UTF-8') === mb_strtoupper(trim((string)$row['cell_to']), 'UTF-8')
            : strtoupper($cell) === strtoupper(trim((string)$row['cell_to']));
        if (!$same) {
            tj(['error' => 'ячейка отсканирована неверно (ожидалась ' . $row['cell_to'] . ')',
                'expected' => $row['cell_to'], 'scanned' => $cell], 409);
        }
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
    // Размещение через карточку: подтвержденное кол-во тоже едет в товарный запас (08.1).
    $stock = null;
    if ($taskType === 'размещение' && $cell !== '') {
        try {
            $wh = (int)$pdo->query('SELECT warehouse_id FROM tasks WHERE id=' . $tid)->fetchColumn();
            $stock = tt_move_stock_to_cell($pallet, $cell,
                [['sku' => $row['sku'], 'qty' => $qty]], $wh,
                (string)$pdo->query('SELECT doc_no FROM tasks WHERE id=' . $tid)->fetchColumn());
        } catch (Throwable $e) {
            $stock = ['moved' => [], 'skipped' => [], 'error' => $e->getMessage()];
        }
    }
    $pr = $pdo->query("SELECT plan_qty, fact_qty, progress FROM tasks WHERE id=" . $tid)->fetch();
    tj(['ok' => true, 'progress' => $pr, 'need_left' => max(0, $need - $qty), 'stock' => $stock]);
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

// --- Размещение по SSCC (роль Водитель -> Размещение, онлайн):
// POST ?action=place_scan {sscc, sku?, warehouse_id?, user_id?, user_fio?}
// Тип тары и высота берутся из Номенклатуры по SKU состава SSCC.
// Создается задание типа "размещение" (PL-...), ячейка подбирается сразу
// по высоте + типу упаковки, приоритет — добивка непустых секций до полной.
if ($action === 'place_scan' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    @file_put_contents(__DIR__ . '/place_scan.log',
        date('Y-m-d H:i:s') . ' IN sscc=' . trim((string)($b['sscc'] ?? $b['pallet'] ?? '')) . PHP_EOL, FILE_APPEND);
    $sscc = trim((string)($b['sscc'] ?? $b['pallet'] ?? ''));
    $skuReq = trim((string)($b['sku'] ?? ''));
    // Шаг 1 ТСД — только скан SSCC. Кол-во, тип тары и высота подтягиваются
    // из приемки и номенклатуры (поля qty/pallet_type/height_mm — лишь опциональный оверрайд).
    $qtyReq = (float)($b['qty'] ?? 0);
    $ptypeReq = trim((string)($b['pallet_type'] ?? ''));
    $heightReq = isset($b['height_mm']) && $b['height_mm'] !== '' ? (int)$b['height_mm'] : null;
    $wid = isset($b['warehouse_id']) && $b['warehouse_id'] !== '' ? (int)$b['warehouse_id'] : null;
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? '')) !== '' ? trim((string)$b['user_fio']) : 'ТСД';
    if ($sscc === '') {
        tj(['error' => 'нужен sscc (сканируй SSCC паллеты)'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellPlacement.php';
    if ($ptypeReq !== '') {
        $t = CellPlacement::normalizePalletType($ptypeReq);
        if (!in_array($t, ['euro', 'fin'], true)) {
            tj(['error' => 'тип тары: евро или фин (pallet_type=euro|fin)'], 422);
        }
    }

    // 1) Состав SSCC: операции приемки с pallet=sscc (скан без вскрытия пишет туда же).
    $skus = [];
    try {
        $rdb = wms_receipts_pdo();
        $op = $rdb->prepare("SELECT sku, COALESCE(SUM(qty),0) q FROM receipt_operations WHERE pallet = :s GROUP BY sku");
        $op->execute([':s' => $sscc]);
        foreach ($op->fetchAll() as $row) {
            if (trim((string)($row['sku'] ?? '')) !== '') {
                $skus[(string)$row['sku']] = (float)($row['q'] ?? 0);
            }
        }
        if (!$skus) {
            $tp = $rdb->prepare("SELECT receipt_id FROM tsd_pallets WHERE sscc = :s LIMIT 1");
            $tp->execute([':s' => $sscc]);
            $rid = $tp->fetchColumn();
            if ($rid) {
                $st = $rdb->prepare("SELECT sku, COALESCE(SUM(qty),0) q FROM receipt_operations WHERE receipt_id = :r GROUP BY sku");
                $st->execute([':r' => $rid]);
                foreach ($st->fetchAll() as $row) {
                    if (trim((string)($row['sku'] ?? '')) !== '') {
                        $skus[(string)$row['sku']] = (float)($row['q'] ?? 0);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        tj(['error' => 'БД поступлений недоступна: ' . $e->getMessage()], 500);
    }
    if ($skuReq !== '' && !$skus) {
        // Ручной sku без данных приемки: кол-во только явным qty, иначе нечего размещать.
        if ($qtyReq <= 0) {
            tj(['error' => 'По SSCC нет данных о количестве — прими паллету в ТСД-Приходе', 'sscc' => $sscc], 404);
        }
        $skus = [$skuReq => $qtyReq];
    }
    if (!$skus) {
        tj(['error' => 'SSCC не найден в приемке: ' . $sscc . '. Прими паллету в ТСД-Приходе', 'sscc' => $sscc], 404);
    }

    // 2) Номенклатура: имена + fallback типа тары/высоты (максимум по составу).
    // Явно указанный водителем тип тары — приоритетнее справочника.
    $nomenType = 'euro';
    $nomenHeight = null;
    $names = [];
    try {
        $ndb = wms_nomenclature_pdo();
        $cols = [];
        foreach ($ndb->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[] = $c['name'];
        }
        $hasPt = in_array('pallet_type', $cols, true);
        $hasPh = in_array('pallet_height_mm', $cols, true);
        foreach (array_keys($skus) as $sku) {
            $sel = "SELECT sku, name" . ($hasPt ? ", pallet_type" : "") . ($hasPh ? ", pallet_height_mm" : "") . " FROM products WHERE sku = :s LIMIT 1";
            $ps = $ndb->prepare($sel);
            $ps->execute([':s' => $sku]);
            if ($pr = $ps->fetch()) {
                $names[$sku] = (string)($pr['name'] ?? $sku);
                if ($hasPt && trim((string)($pr['pallet_type'] ?? '')) !== '') {
                    $t = CellPlacement::normalizePalletType((string)$pr['pallet_type']);
                    // Смешанный состав: фин строже — ведем как фин.
                    if ($t === 'fin') {
                        $nomenType = 'fin';
                    } elseif ($nomenType !== 'fin') {
                        $nomenType = $t;
                    }
                }
                if ($hasPh && $pr['pallet_height_mm'] !== null && (int)$pr['pallet_height_mm'] > 0) {
                    $nomenHeight = $nomenHeight === null ? (int)$pr['pallet_height_mm'] : max($nomenHeight, (int)$pr['pallet_height_mm']);
                }
            } else {
                $names[$sku] = $sku;
            }
        }
    } catch (Throwable $e) {
        tj(['error' => 'БД номенклатуры недоступна: ' . $e->getMessage()], 500);
    }
    $ptype = $ptypeReq !== '' ? CellPlacement::normalizePalletType($ptypeReq) : $nomenType;
    $pheight = $heightReq !== null && $heightReq > 0 ? $heightReq : $nomenHeight;
    // Кол-во штук — из приемки по SSCC (водитель ничего не вводит).
    $plans = [];
    $qtyTotal = 0.0;
    foreach ($skus as $sku => $q) {
        $pq = round((float)$q, 3);
        if ($pq <= 0) {
            continue;
        }
        $plans[$sku] = $pq;
        $qtyTotal = round($qtyTotal + $pq, 3);
    }
    if ($qtyTotal <= 0) {
        tj(['error' => 'По SSCC нет количества к размещению', 'sscc' => $sscc], 404);
    }

    // Идемпотентность: одна паллета — одно задание. Повторный скан возвращает
    // ранее предложенную ячейку с полной шапкой, новое задание НЕ создается.
    $dup = $pdo->prepare(
        "SELECT t.id, t.doc_no, t.status, t.warehouse_id FROM tasks t
         JOIN task_items i ON i.task_id = t.id
         WHERE t.type = 'размещение' AND t.status IN ('new','work','paused') AND i.pallet = :s
         ORDER BY t.id DESC LIMIT 1"
    );
    $dup->execute([':s' => $sscc]);
    if ($found = $dup->fetch()) {
        $di = $pdo->prepare('SELECT * FROM task_items WHERE task_id=:t ORDER BY id');
        $di->execute([':t' => (int)$found['id']]);
        $ditems = $di->fetchAll();
        $dcell = trim((string)($ditems[0]['cell_to'] ?? ''));
        $dlines = [];
        $dqty = 0.0;
        foreach ($ditems as $x) {
            $dlines[] = ['sku' => $x['sku'], 'name' => $x['name'] ?? $x['sku'], 'qty' => (float)$x['plan_qty']];
            $dqty = round($dqty + (float)$x['plan_qty'], 3);
        }
        $dptype = $ptype;
        try {
            $sdb0 = wms_storage_pdo();
            $cq = $sdb0->prepare("SELECT pallet_type FROM storage_cells WHERE code=:c"
                . ((int)($found['warehouse_id'] ?? 0) ? " AND warehouse_id=:w" : "") . " LIMIT 1");
            $cp = [':c' => $dcell];
            if ((int)($found['warehouse_id'] ?? 0)) {
                $cp[':w'] = (int)$found['warehouse_id'];
            }
            $cq->execute($cp);
            if ($r0 = $cq->fetch()) {
                if (trim((string)($r0['pallet_type'] ?? '')) !== '') {
                    $dptype = CellPlacement::normalizePalletType((string)$r0['pallet_type']);
                }
            }
        } catch (Throwable) {
        }
        @file_put_contents(__DIR__ . '/place_scan.log',
            date('Y-m-d H:i:s') . ' OUT dup task=' . $found['id'] . ' cell=' . $dcell . PHP_EOL, FILE_APPEND);
        tj(['ok' => true, 'existing' => true, 'task_id' => (int)$found['id'], 'doc_no' => $found['doc_no'],
            'status' => $found['status'], 'sscc' => $sscc,
            'cell' => ['code' => $dcell], 'qty' => $dqty, 'lines' => $dlines,
            'pallet_type' => $dptype, 'pallet_type_label' => $dptype === 'fin' ? 'Фин' : 'Евро',
            'hint' => 'Задание уже создано ранее — предложена та же ячейка. Ячейку можно сменить.']);
    }

    // 3-4) Подбор ячейки + создание задания + резерв — одним блоком:
    // любая ошибка возвращается JSON (а не белым экраном/HTML, иначе ТСД покажет "Нет сети").
    try {
        $sdb = wms_storage_pdo();
        $sug = CellPlacement::suggest($sdb, $ptype, $pheight, $wid);
        if (!$sug) {
            tj(['error' => 'Нет подходящей ячейки: высота ' . ($pheight ?? '—') . ' мм / тара ' .
                ($ptype === 'fin' ? 'фин' : 'евро') . '. Проверь высоту ячеек и режим секции (евро блокирует секцию 3.2)',
                'sscc' => $sscc, 'pallet_type' => $ptype, 'height_mm' => $pheight], 404);
        }
        $cell = $sug['cell'];

        require_once __DIR__ . '/../tasks/TaskService.php';
        $svc = new TaskService($pdo);
        $items = [];
        $lines = [];
        foreach ($plans as $sku => $pq) {
            $items[] = ['sku' => $sku, 'name' => $names[$sku] ?? $sku, 'unit' => 'шт',
                'cell_to' => $cell['code'], 'pallet' => $sscc, 'plan_qty' => $pq];
            $lines[] = ['sku' => $sku, 'name' => $names[$sku] ?? $sku, 'qty' => $pq];
        }
        try {
            $tid = $svc->create([
                'type' => 'размещение',
                'warehouse_id' => $wid ?: (int)($cell['warehouse_id'] ?? 1),
                'doc_ref' => 'SSCC ' . $sscc,
                'executor_id' => $uid,
                'executor_fio' => ($fio !== '' ? $fio : null),
                'comment' => 'ТСД размещение: ' . $sscc . ' → ' . $cell['code'],
                'items' => $items,
            ], $uid);
        } catch (Throwable $e) {
            tj(['error' => 'Не удалось создать задание: ' . $e->getMessage()], 500);
        }
        try {
            $occ = CellPlacement::occupy($sdb, (int)$cell['id'], $ptype, $sscc, ($uid ?: 0), $fio);
            $cellNow = $occ['cell'];
        } catch (Throwable $e) {
            tj(['error' => 'Задание создано, но ячейка уже занята: ' . $e->getMessage() . '. Подбери заново.',
                'task_id' => $tid, 'cell' => $cell['code']], 409);
        }
        $docNo = (string)$pdo->query("SELECT doc_no FROM tasks WHERE id = " . (int)$tid)->fetchColumn();
        @file_put_contents(__DIR__ . '/place_scan.log',
            date('Y-m-d H:i:s') . ' OUT new task=' . $tid . ' cell=' . $cellNow['code'] . PHP_EOL, FILE_APPEND);
        tj(['ok' => true, 'task_id' => $tid, 'doc_no' => $docNo, 'sscc' => $sscc,
            'pallet_type' => $ptype, 'pallet_type_label' => $ptype === 'fin' ? 'Фин' : 'Евро',
            'height_mm' => $pheight, 'skus' => $names, 'qty' => $qtyTotal, 'lines' => $lines, 'existing' => false,
            'cell' => ['id' => (int)$cellNow['id'], 'code' => $cellNow['code'],
                'beam' => CellPlacement::normalizeBeam($cellNow['beam_width'] ?? 3.2),
                'height_mm' => $cellNow['cell_height_mm'],
                'status' => $cellNow['status']],
            'section' => $sug['section'],
            'temp_blocked' => $occ['temp_blocked']['code'] ?? null,
            'section_tuned' => $occ['section_tuned'],
            'hint' => ($occ['temp_blocked'] ?? null)
                ? 'Фин №3 на балке 3.6: ячейка ' . $occ['temp_blocked']['code'] . ' заблокирована (временно занята)'
                : (($occ['section_tuned'] ?? false) ? 'Секция 3.2 настроена под евро' : 'Добивка секции: ячейка зарезервирована')]);
    } catch (Throwable $e) {
        try {
            @file_put_contents(__DIR__ . '/place_scan.log',
                date('Y-m-d H:i:s') . ' sscc=' . $sscc . ' ERR ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL, FILE_APPEND);
        } catch (Throwable) {
        }
        tj(['error' => 'Ошибка размещения: ' . $e->getMessage(), 'sscc' => $sscc], 500);
    }
}

// --- Смена ячейки размещения: POST ?action=place_retarget {task_id, cell} ---
// Ячейку менять допустимо: резерв переносится, задание обновляется.
if ($action === 'place_retarget' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? 0);
    $newCode = trim((string)($b['cell'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? '')) !== '' ? trim((string)$b['user_fio']) : 'ТСД';
    if ($tid <= 0 || $newCode === '') {
        tj(['error' => 'нужны task_id и cell'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellPlacement.php';
    try {
        $h = $pdo->prepare("SELECT * FROM tasks WHERE id=:t");
        $h->execute([':t' => $tid]);
        $head = $h->fetch();
        if (!$head) {
            tj(['error' => 'Задание не найдено'], 404);
        }
        if (($head['type'] ?? '') !== 'размещение' || !in_array($head['status'] ?? '', ['new', 'work', 'paused'], true)) {
            tj(['error' => 'Сменить ячейку можно только в открытом задании на размещение'], 409);
        }
        $it = $pdo->prepare('SELECT * FROM task_items WHERE task_id=:t ORDER BY id');
        $it->execute([':t' => $tid]);
        $items = $it->fetchAll();
        if (!$items) {
            tj(['error' => 'В задании нет позиций'], 404);
        }
        $oldCode = trim((string)($items[0]['cell_to'] ?? ''));
        // Тип/высота — из номенклатуры по составу задания.
        $ptype = 'euro';
        $pheight = null;
        try {
            $ndb = wms_nomenclature_pdo();
            $cols = [];
            foreach ($ndb->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cols[] = $c['name'];
            }
            foreach ($items as $x) {
                if (!in_array('pallet_type', $cols, true) && !in_array('pallet_height_mm', $cols, true)) {
                    break;
                }
                $ps = $ndb->prepare("SELECT pallet_type, pallet_height_mm FROM products WHERE sku=:s LIMIT 1");
                $ps->execute([':s' => $x['sku']]);
                if ($pr = $ps->fetch()) {
                    if (trim((string)($pr['pallet_type'] ?? '')) !== '') {
                        $t = CellPlacement::normalizePalletType((string)$pr['pallet_type']);
                        if ($t === 'fin') {
                            $ptype = 'fin';
                        } elseif ($ptype !== 'fin') {
                            $ptype = $t;
                        }
                    }
                    if ($pr['pallet_height_mm'] !== null && (int)$pr['pallet_height_mm'] > 0) {
                        $pheight = $pheight === null ? (int)$pr['pallet_height_mm'] : max($pheight, (int)$pr['pallet_height_mm']);
                    }
                }
            }
        } catch (Throwable) {
        }
        $sdb = wms_storage_pdo();
        $wid = (int)($head['warehouse_id'] ?? 0) ?: null;
        $oldId = 0;
        if ($oldCode !== '') {
            $q = $sdb->prepare("SELECT id FROM storage_cells WHERE code=:c" . ($wid ? " AND warehouse_id=:w" : "") . " LIMIT 1");
            $qp = [':c' => $oldCode];
            if ($wid) {
                $qp[':w'] = $wid;
            }
            $q->execute($qp);
            $oldId = (int)($q->fetchColumn() ?: 0);
        }
        if ($oldId > 0) {
            $ret = CellPlacement::retarget($sdb, $oldId, $newCode, $ptype, $pheight, $wid, ($uid ?: 0), $fio);
        } else {
            // Старый резерв не найден — просто занимаем новую.
            $nt = $sdb->prepare("SELECT * FROM storage_cells WHERE code=:c" . ($wid ? " AND warehouse_id=:w" : "") . " LIMIT 1");
            $np = [':c' => $newCode];
            if ($wid) {
                $np[':w'] = $wid;
            }
            $nt->execute($np);
            if (!($row = $nt->fetch())) {
                tj(['error' => 'Ячейка не найдена: ' . $newCode], 404);
            }
            $snap = CellPlacement::snapshot($sdb, $wid);
            $chk = CellPlacement::checkCell($row, $ptype, $pheight, $snap['bySection'], $snap['byLevel']);
            if (!$chk['ok']) {
                tj(['error' => 'Ячейка ' . $newCode . ': ' . $chk['error']], 409);
            }
            $occ = CellPlacement::occupy($sdb, (int)$row['id'], $ptype, null, ($uid ?: 0), $fio . ' (смена ячейки)');
            $ret = ['cell' => $occ['cell'], 'old_cell' => null,
                'temp_blocked' => $occ['temp_blocked'], 'section_tuned' => $occ['section_tuned']];
        }
        $pdo->prepare("UPDATE task_items SET cell_to=:c WHERE task_id=:t")->execute([':c' => $ret['cell']['code'], ':t' => $tid]);
        $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'retarget','cell_to',:o,:n)")
            ->execute([':t' => $tid, ':u' => $uid, ':o' => $oldCode, ':n' => $ret['cell']['code']]);
        tj(['ok' => true, 'task_id' => $tid, 'old_cell' => $oldCode, 'cell' => $ret['cell']['code'],
            'temp_blocked' => $ret['temp_blocked']['code'] ?? null,
            'hint' => 'Ячейка сменена: ' . $oldCode . ' → ' . $ret['cell']['code']]);
    } catch (Throwable $e) {
        try {
            @file_put_contents(__DIR__ . '/place_scan.log',
                date('Y-m-d H:i:s') . ' retarget task=' . $tid . ' ERR ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        } catch (Throwable) {
        }
        tj(['error' => $e->getMessage()], 400);
    }
}

// --- Подтверждение размещения сканом ячейки: POST ?action=place_confirm {task_id, cell} ---
// Отсканированная ячейка обязана совпасть с предложенной, иначе "ячейка отсканирована неверно".
if ($action === 'place_confirm' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? 0);
    $cell = trim((string)($b['cell'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($tid <= 0 || $cell === '') {
        tj(['error' => 'нужны task_id и cell (отсканируй штрихкод ячейки)'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../tasks/TaskService.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellPlacement.php';
    try {
        $h = $pdo->prepare("SELECT * FROM tasks WHERE id=:t");
        $h->execute([':t' => $tid]);
        $head = $h->fetch();
        if (!$head) {
            tj(['error' => 'Задание не найдено'], 404);
        }
        $it = $pdo->prepare('SELECT * FROM task_items WHERE task_id=:t ORDER BY id');
        $it->execute([':t' => $tid]);
        $items = $it->fetchAll();
        if (!$items) {
            tj(['error' => 'В задании нет позиций'], 404);
        }
        $expected = trim((string)($items[0]['cell_to'] ?? ''));
        foreach ($items as $x) {
            if ((float)$x['plan_qty'] - (float)$x['fact_qty'] <= 1e-9) {
                continue;
            }
            if ($expected === '' ) {
                $expected = trim((string)($x['cell_to'] ?? ''));
            }
            if (!CellPlacement::sameCellCode($cell, (string)($x['cell_to'] ?? ''))) {
                tj(['error' => 'ячейка отсканирована неверно (ожидалась ' . $x['cell_to'] . ')',
                    'expected' => $x['cell_to'], 'scanned' => $cell], 409);
            }
        }
        // Все сошлось — фиксируем факт и переводим ячейку в занятую.
        $svc = new TaskService($pdo);
        $sum = 0.0;
        $moves = [];
        foreach ($items as $x) {
            $need = (float)$x['plan_qty'] - (float)$x['fact_qty'];
            if ($need > 1e-9) {
                $svc->pick((int)$x['id'], (float)$x['plan_qty'], $uid);
                $sum = round($sum + $need, 3);
                $moves[] = ['sku' => $x['sku'], 'qty' => $need];
            }
        }
        if ($expected !== '') {
            try {
                $sdb = wms_storage_pdo();
                $wid = (int)($head['warehouse_id'] ?? 0);
                $cs = $sdb->prepare("SELECT * FROM storage_cells WHERE code=:c" . ($wid ? " AND warehouse_id=:w" : "") . " LIMIT 1");
                $cp = [':c' => $expected];
                if ($wid) {
                    $cp[':w'] = $wid;
                }
                $cs->execute($cp);
                if ($crow = $cs->fetch()) {
                    $sdb->prepare(
                        "UPDATE storage_cells SET qty = COALESCE(qty,0) + :q,
                                reserved_qty = CASE WHEN COALESCE(reserved_qty,0) > 0 THEN reserved_qty - 1 ELSE 0 END,
                                status = 'busy' WHERE id = :id"
                    )->execute([':q' => $sum, ':id' => $crow['id']]);
                    try {
                        $sdb->prepare(
                            "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                             VALUES (:c, :u, 'ТСД', 'confirm', 'Размещение', 'reserved', :n)"
                        )->execute([':c' => $crow['id'], ':u' => $uid, ':n' => 'busy · +' . $sum]);
                    } catch (Throwable) {
                    }
                }
            } catch (Throwable) {
            }
        }
        // Товарный запас (08.1): переносим строки из ЗОНЫ-ПРИЕМКИ в ячейку.
        $stock = ['moved' => [], 'skipped' => []];
        if ($sum > 0 && $expected !== '') {
            try {
                $sscc = trim((string)($items[0]['pallet'] ?? ''));
                $stock = tt_move_stock_to_cell($sscc, $expected, $moves,
                    (int)($head['warehouse_id'] ?? 0), (string)($head['doc_no'] ?? ''));
            } catch (Throwable $e) {
                $stock = ['moved' => [], 'skipped' => [], 'error' => $e->getMessage()];
            }
        }
        $pdo->prepare('DELETE FROM tsd_task_locks WHERE task_id=:t')->execute([':t' => $tid]);
        $pr = $pdo->query("SELECT plan_qty, fact_qty, progress, status FROM tasks WHERE id=" . $tid)->fetch();
        tj(['ok' => true, 'task_id' => $tid, 'cell' => $expected, 'progress' => $pr, 'stock' => $stock,
            'hint' => 'Паллета размещена в ' . $expected]);
    } catch (Throwable $e) {
        tj(['error' => $e->getMessage()], 400);
    }
}

tj(['error' => 'unknown action: ?action=tasks|task|take|release|confirm|problem|move_to_sort|place_scan|place_retarget|place_confirm'], 404);
