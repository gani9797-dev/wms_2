<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
require_once __DIR__ . '/../shared/PickIssue.php';
// Буфер отбора: виртуальная промежуточная ячейка пользователя + ворота + сборка по SSCC.
require_once __DIR__ . '/../shared/PickBuffer.php';
// ТСД «Задания» — единый tasks API поверх модуля TL Задания (31.08.2026).
// Источник: /tasks/wms_tasks.sqlite (те же таблицы, что и tasks/demo_sqlite.php):
//   tasks / task_items / task_history (+ warehouses, users).
// Типы: сбор (=отбор), размещение, перемещение, комплектация, пополнение.
// Кнопки ТСД: Размещение -> ?type=размещение, Перемещение -> ?type=перемещение,
//   Отбор (otbor.html) остается на отгрузках, Приемка/Отгрузка — на receipts/shipments.
//
// Авторизация: ?token=tsd-12345, заголовок X-Tsd-Token или сессия /login.
// Actions: tasks | task | take | release | confirm | problem | move_to_sort | pick(алиас confirm)

const TSD_TASKS_WAIT_SEC = 120; // «в ожидании» -> возврат в доступ через 2 мин

function tt_ok(): bool
{
    if (wms_tsd_token_ok()) {
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
    // Возраст блокировки в секундах: SQLite — strftime, PostgreSQL — EXTRACT(EPOCH).
    $age = wms_is_sqlite($pdo)
        ? "(strftime('%s','now')-strftime('%s',updated_at))"
        : "(EXTRACT(EPOCH FROM NOW())-EXTRACT(EPOCH FROM updated_at::timestamp))";
    $pdo->exec("DELETE FROM tsd_task_locks WHERE status='waiting' AND " . $age . ">" . TSD_TASKS_WAIT_SEC);
    // Страховка: блокировки от удалённых заданий. Иначе сотрудник навсегда
    // остаётся «занятым»: «У тебя уже есть задание в работе: №<удалённое>».
    try {
        $pdo->exec("DELETE FROM tsd_task_locks WHERE task_id NOT IN (SELECT id FROM tasks)");
    } catch (Throwable) {
    }
}

/** Дата/время строки лога в формате ДД.ММ.ГГГГ ЧЧ:ММ (SQLite/PostgreSQL). */
function t_dt_sql(PDO $pdo): string
{
    return wms_is_sqlite($pdo)
        ? "strftime('%d.%m.%Y %H:%M',created_at)"
        : "TO_CHAR(created_at,'DD.MM.YYYY HH24:MI')";
}

/**
 * Отгрузка задания на отбор (связь с модулем «Отгрузки»).
 * null — задание не на отбор или отгрузка удалена.
 */
function t_pick_shipment(PDO $pdo, int $taskId): ?array
{
    require_once __DIR__ . '/../shared/db.php';
    try {
        $th = $pdo->prepare('SELECT * FROM tasks WHERE id=:t');
        $th->execute([':t' => $taskId]);
        $trow = $th->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$trow || !str_contains((string)($trow['flags'] ?? ''), 'pick')) {
            return null;
        }
        return wms_pick_task_shipment(wms_shipments_pdo(), $trow);
    } catch (Throwable) {
        return null;
    }
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
        // Портативная проверка колонки: PRAGMA на PostgreSQL недопустим.
        if (!wms_has_column($ndb, 'product_stock', 'pallet_type')) {
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
            // Источник: строка приёмки этого же прихода и паллеты
            // (ячейка приёмки = DOK-01…DOK-10, см. приёмку ТСД), иначе любая строка приёмки.
            $src = null;
            if ($receiptId) {
                $sq = $ndb->prepare(
                    "SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w
                     AND receipt_id=:r AND COALESCE(pallet,'')=:pal AND qty > 0 ORDER BY id LIMIT 1"
                );
                try {
                    $sp = [':p' => $pid, ':w' => $warehouseId, ':r' => $receiptId, ':pal' => $sscc];
                    $sq->execute($sp);
                    $src = $sq->fetch() ?: null;
                } catch (Throwable) {
                    $src = null;
                }
            }
            if (!$src) {
                // Любая строка ячейки приёмки (зона receiving) с этим товаром.
                try {
                    $sq = $ndb->prepare(
                        "SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w
                         AND cell IN (SELECT code FROM storage_cells WHERE zone='receiving') AND qty > 0 ORDER BY id LIMIT 1"
                    );
                    $sq->execute([':p' => $pid, ':w' => $warehouseId]);
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
            // Сколько реально переносим: не больше, чем лежит в источнике.
            // Раньше здесь обрезали источник до нуля, но в целевую ячейку писали
            // ПОЛНОЕ количество — запас создавался из ниоткуда.
            $movedQty = 0.0;
            $shortBy = 0.0;
            if ($src) {
                $srcQty = (float)$src['qty'];
                $movedQty = min($qty, $srcQty);
                $shortBy = round($qty - $movedQty, 3);
                $ndb->prepare('UPDATE product_stock SET qty = qty - :q WHERE id=:id')
                    ->execute([':q' => $movedQty, ':id' => $src['id']]);
                if ($srcQty - $movedQty <= 0.0001) {
                    // Паллета полностью ушла из ячейки — строка-заглушка больше не нужна
                    // (иначе 8.1 считает её конфликтом паллеты по двум ячейкам).
                    try {
                        $ndb->prepare('DELETE FROM product_stock WHERE id=:id AND qty <= 0.0001')
                            ->execute([':id' => $src['id']]);
                    } catch (Throwable) {
                    }
                }
            } else {
                // Строки приёмки нет — переносить нечего. Создавать запас из ничего
                // нельзя: это завышает остаток. Помечаем позицию пропущенной.
                $skipped[] = $sku . ': нет остатка в зоне приёмки — нечего размещать';
                continue;
            }
            if ($movedQty <= 0) {
                $skipped[] = $sku . ': остаток в приёмке нулевой';
                continue;
            }
            $ins = $ndb->prepare(
                'INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,pallet_type,qty' .
                ($receiptId ? ',receipt_id' : '') . ') VALUES (:p,:w,:c,:pal,:pt,:q' .
                ($receiptId ? ',:r' : '') . ')'
            );
            $ip = [':p' => $pid, ':w' => $warehouseId, ':c' => $cell,
                ':pal' => ($sscc !== '' ? $sscc : null), ':pt' => $ptype, ':q' => $movedQty];
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
                    ':pal' => ($sscc !== '' ? $sscc : null), ':pt' => $ptype, ':q' => $movedQty]);
            }
            try {
                $ndb->prepare(
                    "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                     VALUES (:p,datetime('now'),'place',:d,:c,:q,:b)"
                )->execute([':p' => $pid, ':d' => 'Размещение ' . ($docNo ?? $sscc),
                    ':c' => $cell, ':q' => $movedQty, ':b' => (float)($prod['qty'] ?? 0)]);
            } catch (Throwable) {
            }
            $moved[] = ['sku' => $sku, 'qty' => $movedQty]
                + ($shortBy > 0 ? ['note' => 'в приёмке не хватило ' . $shortBy . ' шт'] : []);
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
                t.warehouse_id, t.flags, w.name AS warehouse,
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
    unset($r);
    // Связь с модулем «Отгрузки»: задание на отбор должно быть привязано к
    // существующей отгрузке. Осиротевшие (отгрузка удалена) в отбор не выдаём.
    $ship = null;
    try {
        require_once __DIR__ . '/../shared/db.php';
        $ship = wms_shipments_pdo();
    } catch (Throwable) {
    }
    $broken = [];
    if ($ship && $list) {
        foreach ($list as &$r) {
            // Связь с отгрузкой — только у заданий на отбор (флаг pick).
            if (!str_contains((string)($r['flags'] ?? ''), 'pick')) {
                continue;
            }
            $sh = wms_pick_task_shipment($ship, $r);
            if (!$sh) {
                $broken[] = ['doc_no' => (string)($r['doc_no'] ?? ''), 'shipment_ref' => (string)($r['doc_ref'] ?? '')];
                $r['_broken'] = true;
                continue;
            }
            $r['_broken'] = false;
            $r['shipment_id'] = $sh['id'];
            $r['shipment_doc_no'] = $sh['doc_no'];
            $r['consignee'] = $sh['consignee'];
            $r['gate'] = $sh['gate'];
        }
        unset($r);
        if ($broken) {
            $list = array_values(array_filter($list, fn($r) => empty($r['_broken'])));
            foreach ($list as &$r) {
                unset($r['_broken']);
            }
            unset($r);
            $prio = 0;
            foreach ($list as &$r) {
                $r['prio'] = ++$prio;
            }
            unset($r);
        }
    }
    // Волна: пересечение SKU между заданиями одного типа.
    $need = [];
    if (count($list) >= 2) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $list));
        $q = $pdo->query("SELECT sku, COUNT(DISTINCT task_id) c, SUM(plan_qty-fact_qty) q FROM task_items WHERE task_id IN ($ids) GROUP BY sku HAVING COUNT(DISTINCT task_id)>1");
        foreach ($q->fetchAll() as $w) {
            $need[$w['sku']] = ['ships' => (int)$w['c'], 'qty' => (float)$w['q']];
        }
    }
    tj(['rows' => $list, 'type' => $type, 'role' => ($role !== '' ? $role : null),
        'role_hint' => $role === 'driver' ? 'Водитель: уровень ячейки >= 2' : ($role === 'storekeeper' ? 'Кладовщик: уровень ячейки = 1' : null),
        'broken' => $broken,
        'broken_hint' => $broken
            ? ('Задания без отгрузки (удалена из модуля «Отгрузки»): ' . implode(', ', array_map(fn($b) => $b['doc_no'] . ' → ' . ($b['shipment_ref'] ?: '—'), $broken)))
            : null,
        'wave' => ['possible' => !empty($need), 'cross_sku' => $need,
            'hint' => !empty($need) ? 'Есть пересечение SKU — можно волной' : null]]);
}

// --- Отбор по зоне: зоны отбора ячеек (ручной отбор / отбор водителем) ---
if ($action === 'zones') {
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../shared/PickLists.php';
    $role = strtolower(trim((string)($_GET['role'] ?? '')));
    if (!in_array($role, ['driver', 'storekeeper', 'водитель', 'кладовщик'], true)) {
        $role = '';
    }
    $role = wms_pick_zone_by_role($role) === 'driver' ? 'driver' : ($role !== '' ? 'storekeeper' : '');
    $storage = wms_storage_pdo();
    $zones = wms_pick_zones_summary($storage, $pdo);
    foreach ($zones as &$z) {
        $z['mine'] = ($role !== '' && $z['role'] === $role);
    }
    unset($z);
    tj(['rows' => $zones, 'role' => ($role !== '' ? $role : null),
        'role_hint' => $role === 'driver' ? 'Отбор водителем — зоны ячеек с отбором водителем'
            : ($role === 'storekeeper' ? 'Ручной отбор — зоны ячеек с ручным отбором' : null)]);
}

// --- Отбор по зоне: списки заданий (PK-000001 ...) по зоне ---
if ($action === 'zone_lists') {
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../shared/PickLists.php';
    $zone = strtolower(trim((string)($_GET['zone'] ?? '')));
    if (!in_array($zone, wms_pick_zone_types(), true)) {
        tj(['error' => 'Нужна зона отбора: manual (ручной отбор) | driver (отбор водителем)'], 422);
    }
    $role = strtolower(trim((string)($_GET['role'] ?? '')));
    if (!in_array($role, ['driver', 'storekeeper', 'водитель', 'кладовщик'], true)) {
        $role = '';
    }
    $role = wms_pick_zone_by_role($role) === 'driver' ? 'driver' : ($role !== '' ? 'storekeeper' : '');
    // Список типа «ручной отбор» падает только роли Кладовщик,
    // список типа «отбор водителем» — только роли Водитель.
    if ($role !== '' && wms_pick_zone_role($zone) !== $role) {
        tj(['rows' => [], 'zone' => $zone, 'role' => $role,
            'error' => 'Зона «' . wms_pick_zone_label($zone) . '» — только для роли '
                . ($zone === 'driver' ? 'Водитель' : 'Кладовщик')], 403);
    }
    $uid = (int)($_GET['user_id'] ?? 0) ?: null;
    $storage = wms_storage_pdo();
    $ship = wms_shipments_pdo();
    $summary = wms_pick_zone_build_lists($storage, $ship, $pdo, $zone, $uid);
    $lists = wms_pick_lists_read($pdo, $role, $zone);
    // Наряды (заказы клиента) отгрузки списка — их может быть несколько.
    foreach ($lists as &$l) {
        $l['orders'] = wms_pick_list_orders($ship, (int)($l['shipment_id'] ?? 0));
    }
    unset($l);
    tj(['rows' => $lists, 'zone' => $zone, 'role' => ($role !== '' ? $role : null),
        'zone_label' => wms_pick_zone_label($zone),
        'summary' => $summary]);
}

// --- Отбор по зоне: задания списка ---
if ($action === 'list_tasks') {
    require_once __DIR__ . '/../shared/PickLists.php';
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        tj(['error' => 'нужен id списка'], 422);
    }
    $list = wms_pick_list_row($pdo, $id);
    if (!$list) {
        tj(['error' => 'Список заданий не найден'], 404);
    }
    $role = strtolower(trim((string)($_GET['role'] ?? '')));
    if (!in_array($role, ['driver', 'storekeeper', 'водитель', 'кладовщик'], true)) {
        $role = '';
    }
    $role = wms_pick_zone_by_role($role) === 'driver' ? 'driver' : ($role !== '' ? 'storekeeper' : '');
    if ($role !== '' && wms_pick_zone_role((string)$list['type']) !== $role) {
        tj(['error' => 'Список «' . $list['code'] . '» — только для роли '
            . ((string)$list['type'] === 'driver' ? 'Водитель' : 'Кладовщик')], 403);
    }
    tj(['rows' => wms_pick_list_tasks($pdo, $id), 'list' => $list]);
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
    // Штрихкоды товара из номенклатуры («Штрихкоды (через запятую)») — сверка скана на ТСД.
    require_once __DIR__ . '/../shared/db.php';
    $bcs = wms_nomenclature_barcodes(array_column($rows, 'sku'));
    foreach ($rows as &$it) {
        $it['cell'] = t_cell($head, $it);
        $it['need'] = max(0, (float)$it['plan_qty'] - (float)$it['fact_qty']);
        $it['barcodes'] = $bcs[(string)($it['sku'] ?? '')] ?? [];
        $it['photo'] = '📦';
    }
    unset($it);
    $lock = $pdo->prepare('SELECT * FROM tsd_task_locks WHERE task_id=:id');
    $lock->execute([':id' => $id]);
    // Связь с модулем «Отгрузки»: без существующей отгрузки отбор невозможен.
    require_once __DIR__ . '/../shared/db.php';
    $shipment = null;
    $isPickTask = str_contains((string)($head['flags'] ?? ''), 'pick');
    if ($isPickTask) {
        try {
            $ship = wms_shipments_pdo();
            $shipment = wms_pick_task_shipment($ship, $head);
        } catch (Throwable) {
        }
        if (!$shipment) {
            $ref = trim((string)($head['doc_ref'] ?? ''));
            tj(['error' => 'Отгрузка ' . ($ref !== '' ? $ref : 'без номера') . ' удалена из модуля «Отгрузки» — отбор по заданию невозможен',
                'broken' => true, 'shipment_ref' => $ref], 409);
        }
    }
    // Списки заданий (PK-000001 ...), в которые входит задание.
    $lists = [];
    try {
        $ls = $pdo->prepare(
            'SELECT l.id, l.code, l.name, l.type, l.shipment_id, l.shipment_doc_no
             FROM task_list_items li JOIN task_lists l ON l.id = li.list_id
             WHERE li.task_id = :t ORDER BY l.code'
        );
        $ls->execute([':t' => $id]);
        $lists = $ls->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
    }
    tj(['head' => $head, 'items' => $rows, 'lists' => $lists, 'shipment' => $shipment,
        'progress' => ['plan' => (float)$head['plan_qty'], 'fact' => (float)$head['fact_qty'], 'pct' => (int)$head['progress']],
        'buffer' => $shipment ? wms_pick_buffer_state(wms_shipments_pdo(), (int)$shipment['id']) : null,
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
    // Связь с модулем «Отгрузки»: задание без отгрузки в отбор не берём.
    require_once __DIR__ . '/../shared/db.php';
    try {
        $ship = wms_shipments_pdo();
        $th = $pdo->prepare('SELECT * FROM tasks WHERE id=:t');
        $th->execute([':t' => $tid]);
        $trow = $th->fetch(PDO::FETCH_ASSOC) ?: [];
        if (str_contains((string)($trow['flags'] ?? ''), 'pick')) {
            $sh = wms_pick_task_shipment($ship, $trow);
            if (!$sh) {
                $ref = trim((string)($trow['doc_ref'] ?? ''));
                tj(['error' => 'Отгрузка ' . ($ref !== '' ? $ref : 'без номера') . ' удалена из модуля «Отгрузки» — отбор по заданию невозможен',
                    'broken' => true], 409);
            }
        }
    } catch (Throwable) {
        // БД отгрузок недоступна — оператора не блокируем.
    }
    // Следующий отбор запрещён, пока товар не перемещён на ворота и не собран
    // на сборочной паллете (в буфере остались открытые позиции).
    $shPick = t_pick_shipment($pdo, $tid);
    if ($shPick) {
        try {
            $shipBuf = wms_shipments_pdo();
            $openBuf = wms_pick_buffer_open($shipBuf, (int)$shPick['id'], $uid);
            if ($openBuf && !wms_pick_session_task($pdo, $tid, $uid)) {
                tj(['error' => wms_pick_buffer_block_text($openBuf),
                    'open_positions' => count($openBuf),
                    'buffer' => wms_pick_buffer_state($shipBuf, (int)$shPick['id']),
                    'need_gate' => true, 'need_assembly' => true], 409);
            }
        } catch (Throwable) {
        }
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
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($tid <= 0 || $sku === '' || $qty <= 0) {
        tj(['error' => 'нужны task_id, sku, qty>0'], 422);
    }
    // Следующий отбор запрещён, пока предыдущий товар не перемещён на ворота
    // и не собран на сборочной паллете (открытые позиции буфера).
    $shPick = t_pick_shipment($pdo, $tid);
    if ($shPick) {
        try {
            $shipBuf = wms_shipments_pdo();
            $openBuf = wms_pick_buffer_open($shipBuf, (int)$shPick['id'], $uid);
            if ($openBuf && !wms_pick_session_task($pdo, $tid, $uid)) {
                tj(['error' => wms_pick_buffer_block_text($openBuf),
                    'open_positions' => count($openBuf),
                    'buffer' => wms_pick_buffer_state($shipBuf, (int)$shPick['id']),
                    'need_gate' => true, 'need_assembly' => true], 409);
            }
        } catch (Throwable) {
        }
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
    // Паллета: контроль на уровне кода (чужая отгрузка / зона сортировки).
    if ($pallet !== '') {
        try {
            $ship = wms_shipments_pdo();
            $th2 = $pdo->prepare('SELECT * FROM tasks WHERE id=:t');
            $th2->execute([':t' => $tid]);
            $trow2 = $th2->fetch(PDO::FETCH_ASSOC) ?: [];
            $sh2 = wms_pick_task_shipment($ship, $trow2);
            if ($sh2) {
                $pc = wms_pick_pallet_check($ship, wms_storage_pdo(), wms_nomenclature_pdo(), (int)$sh2['id'], $pallet);
                if (!empty($pc['checks']['other_shipment'])) {
                    tj(['error' => 'Паллета ' . $pallet . ' принадлежит другой отгрузке', 'pallet' => $pallet], 409);
                }
                if (!empty($pc['checks']['in_sort'])) {
                    tj(['error' => 'Паллета ' . $pallet . ' находится в зоне сортировки', 'pallet' => $pallet], 409);
                }
            }
        } catch (Throwable) {
            // Проверка паллеты не должна блокировать отбор.
        }
    }
    require_once __DIR__ . '/../07-zadaniya/TaskService.php';
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
    // Товар перемещён в виртуальную промежуточную ячейку пользователя (VIRT-<user_id>).
    $bufCell = wms_pick_buffer_cell($uid);
    if ($shPick) {
        try {
            $shipBuf = wms_shipments_pdo();
            wms_pick_buffer_add($shipBuf, (int)$shPick['id'], $tid, (string)($row['sku'] ?? $sku),
                (string)($row['name'] ?? ''), $qty, $uid, $fio);
        } catch (Throwable) {
        }
    }
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
    $bufState = null;
    if ($shPick) {
        try {
            $bufState = wms_pick_buffer_state(wms_shipments_pdo(), (int)$shPick['id']);
        } catch (Throwable) {
        }
    }
    tj(['ok' => true, 'progress' => $pr, 'need_left' => max(0, $need - $qty), 'stock' => $stock,
        'buffer_cell' => $bufCell, 'buffer' => $bufState]);
}

// --- Проверка паллеты при отборе: решает система, оператору не задают вопросы ---
// POST ?action=pallet_check {task_id, pallet?, volume?} -> вердикт по паллете:
//   state=cell  — паллета годится (можно отбирать в неё);
//   state=scan  — паллеты в ячейке оператора нет, нужен скан;
//   state=error — отказ с причиной (не найдена / зона сортировки / чужая отгрузка / объём).
if ($action === 'pallet_check' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $pallet = trim((string)($b['pallet'] ?? ''));
    $volume = isset($b['volume']) && $b['volume'] !== '' ? (float)$b['volume'] : null;
    if ($tid <= 0) {
        tj(['error' => 'нужен task_id'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../shared/PickIssue.php';
    // Отгрузка задания — по связи с модулем «Отгрузки».
    $th = $pdo->prepare('SELECT * FROM tasks WHERE id=:t');
    $th->execute([':t' => $tid]);
    $trow = $th->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$trow) {
        tj(['error' => 'Задание не найдено'], 404);
    }
    $ship = wms_shipments_pdo();
    $sh = wms_pick_task_shipment($ship, $trow);
    if (!$sh) {
        $ref = trim((string)($trow['doc_ref'] ?? ''));
        tj(['error' => 'Отгрузка ' . ($ref !== '' ? $ref : 'без номера') . ' удалена из модуля «Отгрузки» — отбор по заданию невозможен',
            'broken' => true], 409);
    }
    $res = wms_pick_pallet_check($ship, wms_storage_pdo(), wms_nomenclature_pdo(), (int)$sh['id'], $pallet, $volume);
    $res['shipment'] = $sh;
    tj($res, $res['state'] === 'error' ? 409 : 200);
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
       // Ячейка реально блокируется, оператору подбирается другая.
       // Раньше только писалась запись в журнал, а ответ утверждал,
       // что ячейка заблокирована и резервирование пересоздано.
       $reserved = ['blocked' => false, 'cell' => '', 'reason' => $block];
       try {
           require_once __DIR__ . '/../shared/db.php';
           require_once __DIR__ . '/../shared/PickIssue.php';
           $wh = (int)$pdo->query('SELECT warehouse_id FROM tasks WHERE id=' . (int)$tid)->fetchColumn();
           $reserved = wms_block_cell_and_reserve(wms_storage_pdo(), $cell, $sku, (int)$wh ?: 1, $block);
       } catch (Throwable) {
       }
       $pdo->beginTransaction();
       try {
           $pdo->prepare('INSERT INTO tsd_task_logs (task_id,cell,sku,user_id,reason) VALUES (:t,:c,:sku,:u,:r)')
               ->execute([':t' => $tid, ':c' => ($cell !== '' ? $cell : null), ':sku' => $sku, ':u' => $uid, ':r' => $block]);
           $note = 'Блокировка: ' . $block
               . ($reserved['blocked'] ? ' (ячейка заблокирована)' : ' (ячейку заблокировать не удалось)')
               . ($reserved['cell'] !== '' ? ' · новая ячейка: ' . $reserved['cell'] : ' · новая ячейка не подобрана');
           $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'cell_block',:f,:o,:n)")
               ->execute([':t' => $tid, ':u' => $uid, ':f' => 'Ячейка ' . $cell, ':o' => $sku, ':n' => $note]);
           $pdo->commit();
       } catch (Throwable $e) {
           $pdo->rollBack();
           tj(['error' => $e->getMessage()], 500);
       }
       $log = $pdo->query('SELECT id, task_id, cell, sku, user_id, reason, ' . t_dt_sql($pdo) . ' AS dt FROM tsd_task_logs ORDER BY id DESC LIMIT 1')->fetch();
       tj(['ok' => true, 'block' => $block, 'log' => $log,
           'cell_blocked' => (bool)$reserved['blocked'],
           'new_cell' => $reserved['cell'],
           'hint' => $reserved['blocked']
               ? 'Ячейка заблокирована (' . $block . ').'
                 . ($reserved['cell'] !== '' ? ' Продолжай с ячейки ' . $reserved['cell'] . '.' : ' Подбери другую ячейку вручную.')
               : 'Ячейку заблокировать не удалось — обратись к мастеру склада.']);
   }

// --- Отправка паллеты на ворота сортировки: ворота отгрузки — источник истины ---
// GET ?action=gate_info&task_id=N -> ворота отгрузки задания + состояние буфера.
if ($action === 'gate_info' && $method === 'GET') {
    $tid = (int)($_GET['task_id'] ?? $_GET['id'] ?? 0);
    if ($tid <= 0) {
        tj(['error' => 'нужен task_id'], 422);
    }
    $sh = t_pick_shipment($pdo, $tid);
    if (!$sh) {
        tj(['error' => 'Отгрузка задания не найдена'], 404);
    }
    $ship = wms_shipments_pdo();
    $g = wms_pick_shipment_gates($ship, (int)$sh['id']);
    $g['buffer'] = wms_pick_buffer_state($ship, (int)$sh['id']);
    $g['shipment_id'] = (int)$sh['id'];
    if (!$g['has_gates']) {
        $g['hint'] = 'В отгрузке ворота не указаны — введи ворота вручную';
    }
    tj($g);
}

// --- Перенос паллеты в зону сортировки / ворота ---
if ($action === 'move_to_sort' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? $b['shipment_id'] ?? 0);
    $pallet = trim((string)($b['pallet'] ?? ''));
    $gateCell = trim((string)($b['gate_cell'] ?? ''));
    $gate = trim((string)($b['gate'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    $okMove = (int)($b['moved_ok'] ?? 1);
    if ($tid <= 0 || $pallet === '') {
        tj(['error' => 'нужны task_id и pallet'], 422);
    }
    $shPick = t_pick_shipment($pdo, $tid);
    $shipBuf = null;
    $gates = ['gates' => [], 'has_gates' => false, 'doc_no' => ''];
    if ($shPick) {
        try {
            $shipBuf = wms_shipments_pdo();
            $gates = wms_pick_shipment_gates($shipBuf, (int)$shPick['id']);
            if ($gate === '' && $gates['has_gates']) {
                $gate = (string)$gates['gates'][0];
            }
            if ($gates['has_gates'] && !wms_pick_gate_allowed($gates['gates'], $gate)) {
                tj(['error' => 'Ворота ' . $gate . ' не указаны в отгрузке ' . $gates['doc_no'],
                    'gate' => $gate, 'gates' => $gates['gates'], 'doc_no' => $gates['doc_no']], 409);
            }
            if ($gate === '') {
                tj(['error' => 'Укажи ворота сортировки', 'gates' => $gates['gates']], 422);
            }
        } catch (Throwable) {
        }
    }
    if (!$okMove) {
        $pdo->prepare('INSERT INTO tsd_task_logs (task_id,cell,sku,user_id,reason) VALUES (:t,:c,:sku,:u,:r)')
            ->execute([':t' => $tid, ':c' => ($gateCell !== '' ? $gateCell : 'ЗОНА-СОРТИРОВКИ'), ':sku' => $pallet, ':u' => $uid, ':r' => 'Ошибка перемещения в зону сортировки']);
        $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'sort_error',:f,:o,:n)")
            ->execute([':t' => $tid, ':u' => $uid, ':f' => 'Паллет ' . $pallet, ':o' => $gateCell, ':n' => 'Не перенесена. Информация передана оператору + уведомление в WMS']);
    $log = $pdo->query('SELECT id, task_id, cell, sku, user_id, reason, ' . t_dt_sql($pdo) . ' AS dt FROM tsd_task_logs ORDER BY id DESC LIMIT 1')->fetch();
        tj(['ok' => false, 'log' => $log, 'notify_wms' => true]);
    }
    $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (:t,:u,'move_to_sort','Ворота',:o,:n)")
        ->execute([':t' => $tid, ':u' => $uid, ':o' => 'Паллет ' . $pallet, ':n' => 'Перенесена на ворота сортировки ' . $gate . ' (' . $gateCell . ')']);
    // Буфер отбора: товар перенесён на ворота (picked -> at_gate).
    $moved = 0;
    $bufState = null;
    if ($shipBuf && $shPick) {
        try {
            $moved = wms_pick_buffer_to_gate($shipBuf, (int)$shPick['id'], $gate, $uid);
            $bufState = wms_pick_buffer_state($shipBuf, (int)$shPick['id']);
            require_once __DIR__ . '/../shared/Journal.php';
            WmsJournal::log(wms_journal_pdo(), [
                'op_type' => 'перемещение',
                'doc_type' => 'Отгрузка',
                'doc_no' => $gates['doc_no'],
                'qty' => 0,
                'pallet' => $pallet,
                'sscc' => $pallet,
                'cell_from' => wms_pick_buffer_cell($uid),
                'cell_to' => 'ВОРОТА-' . $gate,
                'zone' => 'Сортировка',
                'user_id' => $uid,
                'user_fio' => $fio,
                'comment' => 'Паллета отправлена на ворота сортировки ' . $gate,
            ]);
        } catch (Throwable) {
        }
    }
    $left = (float)$pdo->query("SELECT COALESCE(SUM(plan_qty-fact_qty),0) FROM task_items WHERE task_id=" . $tid)->fetchColumn();
    if ($left <= 1e-9) {
        $pdo->prepare('DELETE FROM tsd_task_locks WHERE task_id=:t')->execute([':t' => $tid]);
    }
    tj(['ok' => true, 'gate' => $gate, 'gates' => $gates['gates'],
        'buffer_moved' => $moved, 'buffer' => $bufState]);
}

// --- Размещение по SSCC (роль Водитель -> Размещение, онлайн):
// POST ?action=place_scan {sscc, sku?, warehouse_id?, user_id?, user_fio?}
// Тип тары и высота берутся из Номенклатуры по SKU состава SSCC.
// Создается задание типа "размещение" (PL-...), ячейка подбирается сразу
// по высоте + типу упаковки, приоритет — добивка неполных секций,
// а для клиента — только его зоны и как можно ближе к его же товару.

/**
 * Контрагент поступления по SSCC (запасной признак клиента для размещения):
 * tsd_pallets.sscc -> receipts.contractor_id.
 */
function tt_receipt_contractor(PDO $pdo, string $sscc): ?int
{
    $sscc = trim($sscc);
    if ($sscc === '') {
        return null;
    }
    try {
        $st = $pdo->prepare(
            "SELECT r.contractor_id FROM tsd_pallets p JOIN receipts r ON r.id = p.receipt_id
              WHERE p.sscc = :s AND r.contractor_id IS NOT NULL LIMIT 1"
        );
        $st->execute([':s' => $sscc]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (int)$v;
    } catch (Throwable) {
        return null;
    }
}

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
    //    Качество берём из приемки: есть операция «Брак» по паллете/SKU — «Брак».
    $skus = [];
    $qualityBySku = [];
    try {
        $rdb = wms_receipts_pdo();
        $op = $rdb->prepare(
            "SELECT sku, COALESCE(SUM(qty),0) q,
                    SUM(CASE WHEN accept_status = 'Брак' THEN 1 ELSE 0 END) bad
             FROM receipt_operations WHERE pallet = :s GROUP BY sku"
        );
        $op->execute([':s' => $sscc]);
        foreach ($op->fetchAll() as $row) {
            $rowSku = trim((string)($row['sku'] ?? ''));
            if ($rowSku !== '') {
                $skus[$rowSku] = (float)($row['q'] ?? 0);
                if ((int)($row['bad'] ?? 0) > 0) {
                    $qualityBySku[$rowSku] = 'Брак';
                }
            }
        }
        if (!$skus) {
            $tp = $rdb->prepare("SELECT receipt_id FROM tsd_pallets WHERE sscc = :s LIMIT 1");
            $tp->execute([':s' => $sscc]);
            $rid = $tp->fetchColumn();
            if ($rid) {
                $st = $rdb->prepare(
                    "SELECT sku, COALESCE(SUM(qty),0) q,
                            SUM(CASE WHEN accept_status = 'Брак' THEN 1 ELSE 0 END) bad
                     FROM receipt_operations WHERE receipt_id = :r GROUP BY sku"
                );
                $st->execute([':r' => $rid]);
                foreach ($st->fetchAll() as $row) {
                    $rowSku = trim((string)($row['sku'] ?? ''));
                    if ($rowSku !== '') {
                        $skus[$rowSku] = (float)($row['q'] ?? 0);
                        if ((int)($row['bad'] ?? 0) > 0) {
                            $qualityBySku[$rowSku] = 'Брак';
                        }
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
        $qualityBySku[$skuReq] = 'новый';
    }
    if (!$skus) {
        tj(['error' => 'SSCC не найден в приемке: ' . $sscc . '. Прими паллету в ТСД-Приходе', 'sscc' => $sscc], 404);
    }
    $palletQuality = in_array('Брак', $qualityBySku, true) ? 'Брак' : 'новый';

    // 2) Номенклатура: имена + fallback типа тары/высоты (максимум по составу).
    // Явно указанный водителем тип тары — приоритетнее справочника.
    $nomenType = 'euro';
    $nomenHeight = null;
    $names = [];
    $palletWeightKg = null;
    $clientIds = [];
    try {
        $ndb = wms_nomenclature_pdo();
        // Портативная проверка колонок: PRAGMA table_info() на PostgreSQL —
        // синтаксическая ошибка, из-за неё весь place_scan падал с 500.
        $hasPt = wms_has_column($ndb, 'products', 'pallet_type');
        $hasPh = wms_has_column($ndb, 'products', 'pallet_height_mm');
        $hasWg = wms_has_column($ndb, 'products', 'weight_g');
        $hasCi = wms_has_column($ndb, 'products', 'contractor_id');
        $weightAcc = 0.0;
        $weightKnown = false;
        foreach (array_keys($skus) as $sku) {
            $sel = "SELECT sku, name" . ($hasPt ? ", pallet_type" : "") . ($hasPh ? ", pallet_height_mm" : "")
                . ($hasWg ? ", weight_g" : "") . ($hasCi ? ", contractor_id" : "") . " FROM products WHERE sku = :s LIMIT 1";
            $ps = $ndb->prepare($sel);
            $ps->execute([':s' => $sku]);
            if ($pr = $ps->fetch()) {
                $names[$sku] = (string)($pr['name'] ?? $sku);
                // Артикул принадлежит контрагенту — по нему определяем клиента.
                if ($hasCi && isset($pr['contractor_id']) && $pr['contractor_id'] !== null
                    && $pr['contractor_id'] !== '') {
                    $clientIds[(int)$pr['contractor_id']] = true;
                }
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
                // Вес паллеты — для проверки предельного веса ячейки.
                if ($hasWg && $pr['weight_g'] !== null && (float)$pr['weight_g'] > 0) {
                    $weightAcc += (float)$skus[$sku] * (float)$pr['weight_g'] / 1000.0;
                    $weightKnown = true;
                }
            } else {
                $names[$sku] = $sku;
            }
        }
    } catch (Throwable $e) {
        tj(['error' => 'БД номенклатуры недоступна: ' . $e->getMessage()], 500);
    }
    $palletWeightKg = isset($weightKnown) && $weightKnown ? round((float)$weightAcc, 3) : null;
    $ptype = $ptypeReq !== '' ? CellPlacement::normalizePalletType($ptypeReq) : $nomenType;
    $pheight = $heightReq !== null && $heightReq > 0 ? $heightReq : $nomenHeight;
    // Клиент (контрагент) для подбора ячейки: основной признак — артикул
    // принадлежит контрагенту (products.contractor_id). Если по составу палиты
    // клиент не определён — берём контрагента поступления.
    $contractorId = null;
    if ($clientIds) {
        $contractorId = (int)array_key_first($clientIds);
    }
    if (!$contractorId && isset($rdb)) {
        $contractorId = tt_receipt_contractor($rdb, $sscc);
    }
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
            $dlines[] = ['sku' => $x['sku'], 'name' => $x['name'] ?? $x['sku'], 'qty' => (float)$x['plan_qty'],
                'quality' => $qualityBySku[(string)$x['sku']] ?? 'новый'];
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
            'status' => $found['status'], 'sscc' => $sscc, 'quality' => $palletQuality,
            'cell' => ['code' => $dcell], 'qty' => $dqty, 'lines' => $dlines,
            'pallet_type' => $dptype, 'pallet_type_label' => $dptype === 'fin' ? 'Фин' : 'Евро',
            'hint' => 'Задание уже создано ранее — предложена та же ячейка. Ячейку можно сменить.']);
    }

    // 3-4) Подбор ячейки + создание задания + резерв — одним блоком:
    // любая ошибка возвращается JSON (а не белым экраном/HTML, иначе ТСД покажет "Нет сети").
    try {
        $sdb = wms_storage_pdo();
        $sug = CellPlacement::suggest($sdb, $ptype, $pheight, $wid, array_keys($plans), $contractorId, $palletQuality);
        if (!$sug) {
            $zonesTxt = '';
            if ($contractorId) {
                $cz = CellPlacement::clientContext($sdb, $contractorId)['zones'];
                $zonesTxt = $cz ? (' Зоны размещения клиента: ' . implode(', ', $cz)) : '';
            }
            tj(['error' => 'Нет подходящей ячейки: высота ' . ($pheight ?? '—') . ' мм / тара ' .
                ($ptype === 'fin' ? 'фин' : 'евро') . '.' . $zonesTxt .
                ' Проверь высоту ячеек и режим секции (евро блокирует секцию 3,2)',
                'sscc' => $sscc, 'pallet_type' => $ptype, 'height_mm' => $pheight,
                'contractor_id' => $contractorId], 404);
        }
        $cell = $sug['cell'];

        require_once __DIR__ . '/../07-zadaniya/TaskService.php';
        $svc = new TaskService($pdo);
        $items = [];
        $lines = [];
        foreach ($plans as $sku => $pq) {
            $items[] = ['sku' => $sku, 'name' => $names[$sku] ?? $sku, 'unit' => 'шт',
                'cell_to' => $cell['code'], 'pallet' => $sscc, 'plan_qty' => $pq];
            $lines[] = ['sku' => $sku, 'name' => $names[$sku] ?? $sku, 'qty' => $pq,
                'quality' => $qualityBySku[$sku] ?? 'новый'];
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
            $occ = CellPlacement::occupy($sdb, (int)$cell['id'], $ptype, $sscc, ($uid ?: 0), $fio,
                (float)$qtyTotal, $palletWeightKg, array_keys($plans), $palletQuality, false);
            $cellNow = $occ['cell'];
        } catch (Throwable $e) {
            tj(['error' => 'Задание создано, но ячейка уже занята: ' . $e->getMessage() . '. Подбери заново.',
                'task_id' => $tid, 'cell' => $cell['code']], 409);
        }
        $docNo = (string)$pdo->query("SELECT doc_no FROM tasks WHERE id = " . (int)$tid)->fetchColumn();
        @file_put_contents(__DIR__ . '/place_scan.log',
            date('Y-m-d H:i:s') . ' OUT new task=' . $tid . ' cell=' . $cellNow['code'] . PHP_EOL, FILE_APPEND);
        // Подсказка оператору: ПОЧЕМУ выбрана именно эта ячейка.
        $tech = ($occ['temp_blocked'] ?? null)
            ? 'Фин №3 на балке 3,6: ячейка ' . $occ['temp_blocked']['code'] . ' заблокирована (Фин секция)'
            : (($occ['section_tuned'] ?? false) ? 'Секция 3,2 настроена под евро' : 'Добивка секции: ячейка зарезервирована');
        $reasons = (array)($sug['placement']['reasons'] ?? []);
        $hint = $reasons ? (implode('; ', $reasons) . '. ' . $tech) : $tech;
        tj(['ok' => true, 'task_id' => $tid, 'doc_no' => $docNo, 'sscc' => $sscc, 'quality' => $palletQuality,
            'pallet_type' => $ptype, 'pallet_type_label' => $ptype === 'fin' ? 'Фин' : 'Евро',
            'height_mm' => $pheight, 'skus' => $names, 'qty' => $qtyTotal, 'lines' => $lines, 'existing' => false,
            'cell' => ['id' => (int)$cellNow['id'], 'code' => $cellNow['code'],
                'beam' => CellPlacement::normalizeBeam($cellNow['beam_width'] ?? 3.2),
                'height_mm' => $cellNow['cell_height_mm'],
                'status' => $cellNow['status'],
                'zone' => (string)($cellNow['zone'] ?? '')],
            'section' => $sug['section'],
            'placement' => $sug['placement'],
            'contractor_id' => $contractorId,
            'contractor_name' => (string)($sug['placement']['contractor_name'] ?? ''),
            'temp_blocked' => $occ['temp_blocked']['code'] ?? null,
            'section_tuned' => $occ['section_tuned'],
            'hint' => $hint]);
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
        // Качество паллеты — по операциям приёмки (есть «Брак» — вся паллета брак).
        $palletQuality = 'новый';
        try {
            $pqSscc = trim((string)($items[0]['pallet'] ?? ''));
            if ($pqSscc !== '') {
                $rdb = wms_receipts_pdo();
                $qq = $rdb->prepare("SELECT COUNT(*) FROM receipt_operations WHERE pallet = :p AND accept_status = 'Брак'");
                $qq->execute([':p' => $pqSscc]);
                if ((int)$qq->fetchColumn() > 0) {
                    $palletQuality = 'Брак';
                }
            }
        } catch (Throwable) {
        }
        // Тип/высота/клиент — из номенклатуры по составу задания.
        $ptype = 'euro';
        $pheight = null;
        $clientIds = [];
        try {
            $ndb = wms_nomenclature_pdo();
            // Портативная проверка колонок (PRAGMA на PostgreSQL недопустим).
            $hasPt = wms_has_column($ndb, 'products', 'pallet_type');
            $hasPh = wms_has_column($ndb, 'products', 'pallet_height_mm');
            $hasCi = wms_has_column($ndb, 'products', 'contractor_id');
            foreach ($items as $x) {
                if (!$hasPt && !$hasPh && !$hasCi) {
                    break;
                }
                $sel = "SELECT pallet_type, pallet_height_mm" . ($hasCi ? ", contractor_id" : "")
                    . " FROM products WHERE sku=:s LIMIT 1";
                $ps = $ndb->prepare($sel);
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
                    if ($hasCi && isset($pr['contractor_id']) && $pr['contractor_id'] !== null
                        && $pr['contractor_id'] !== '') {
                        $clientIds[(int)$pr['contractor_id']] = true;
                    }
                }
            }
        } catch (Throwable) {
        }
        $contractorId = $clientIds ? (int)array_key_first($clientIds) : null;

        $sdb = wms_storage_pdo();
        $wid = (int)($head['warehouse_id'] ?? 0) ?: null;
        // Новая ячейка должна быть в зоне размещения клиента (если зоны заданы).
        if ($contractorId) {
            $cctx = CellPlacement::clientContext($sdb, $contractorId);
            if ($cctx['zones']) {
                $allowed = [];
                foreach ($cctx['zones'] as $z) {
                    foreach (CellPlacement::zoneVariants($z) as $v) {
                        $allowed[$v] = true;
                    }
                }
                $nz = $sdb->prepare("SELECT code, zone FROM storage_cells WHERE code=:c"
                    . ($wid ? " AND warehouse_id=:w" : "") . " LIMIT 1");
                $nzp = [':c' => $newCode];
                if ($wid) {
                    $nzp[':w'] = $wid;
                }
                $nz->execute($nzp);
                $nrow = $nz->fetch(PDO::FETCH_ASSOC);
                $isOwn = false;
                foreach ($cctx['cells'] as $cc) {
                    if (strcasecmp(trim($cc), trim((string)($nrow['code'] ?? ''))) === 0) {
                        $isOwn = true;
                        break;
                    }
                }
                $zOk = false;
                foreach (CellPlacement::zoneVariants((string)($nrow['zone'] ?? '')) as $v) {
                    if (isset($allowed[$v])) {
                        $zOk = true;
                        break;
                    }
                }
                if (!$isOwn && !$zOk) {
                    tj(['error' => 'Ячейка ' . $newCode . ' не в зоне размещения клиента ('
                        . implode(', ', $cctx['zones']) . '). Выбери ячейку в зоне клиента.',
                        'zone' => $nrow['zone'] ?? null], 409);
                }
            }
        }
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
            $ret = CellPlacement::retarget($sdb, $oldId, $newCode, $ptype, $pheight, $wid, ($uid ?: 0), $fio,
                array_column($items, 'sku'), $palletQuality, false);
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
            $row['content_skus'] = $snap['content'][(int)($row['id'] ?? 0)] ?? [];
            $chk = CellPlacement::checkCell($row, $ptype, $pheight, $snap['bySection'], $snap['byLevel'],
                null, array_column($items, 'sku'), null, $palletQuality);
            if (!$chk['ok']) {
                tj(['error' => 'Ячейка ' . $newCode . ': ' . $chk['error']], 409);
            }
            $occ = CellPlacement::occupy($sdb, (int)$row['id'], $ptype, null, ($uid ?: 0), $fio . ' (смена ячейки)',
                (float)($head['plan_qty'] ?? 0), null, array_column($items, 'sku'), $palletQuality, false);
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
/**
 * Вес паллеты по составу (кг): сумма weight_g * qty / 1000.
 * null — если вес товара неизвестен (тогда проверку лимита пропускаем).
 * @param array $moves [{sku, qty}]
 */
function tt_pallet_weight(array $moves): ?float
{
    if (!$moves) {
        return null;
    }
    try {
        $ndb = wms_nomenclature_pdo();
        if (!wms_has_column($ndb, 'products', 'weight_g')) {
            return null;
        }
        $acc = 0.0;
        $known = false;
        $st = $ndb->prepare("SELECT COALESCE(weight_g,0) AS w FROM products WHERE sku = :s LIMIT 1");
        foreach ($moves as $m) {
            $st->execute([':s' => (string)($m['sku'] ?? '')]);
            $w = $st->fetchColumn();
            if ($w === false || $w === null || (float)$w <= 0) {
                continue;
            }
            $acc += (float)($m['qty'] ?? 0) * (float)$w / 1000.0;
            $known = true;
        }
        return $known ? round($acc, 3) : null;
    } catch (Throwable) {
        return null;
    }
}

if ($action === 'place_confirm' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $tid = (int)($b['task_id'] ?? 0);
    $cell = trim((string)($b['cell'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? '')) !== '' ? trim((string)$b['user_fio']) : 'ТСД';
    if ($tid <= 0 || $cell === '') {
        tj(['error' => 'нужны task_id и cell (отсканируй штрихкод ячейки)'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../07-zadaniya/TaskService.php';
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
                    // Повторная проверка ограничений ячейки: между сканированием
                    // и подтверждением её состояние могло измениться (заняли
                    // другие, изменили лимиты). Раньше проверок здесь не было —
                    // товар можно было положить в переполненную ячейку.
                    $cap = (float)($crow['capacity'] ?? 0);
                    if ($cap > 0 && (float)($crow['qty'] ?? 0) + $sum - $cap > 1e-9) {
                        tj(['error' => 'В ячейке ' . $expected . ' не хватает места: свободно '
                            . round($cap - (float)($crow['qty'] ?? 0), 3) . ' шт из ' . $cap
                            . ', а разместить нужно ' . $sum . ' шт'], 409);
                    }
                    $cellType = trim((string)($crow['pallet_type'] ?? ''));
                    if ($cellType !== '' && !CellPlacement::cellAllowsType($crow, $cellType)) {
                        tj(['error' => 'Ячейка ' . $expected . ' не принимает тару «'
                            . ($cellType === 'fin' ? 'фин' : 'евро') . '»'], 409);
                    }
                    $maxW = $crow['max_weight_kg'];
                    $palletKg = tt_pallet_weight($moves);
                    if ($maxW !== null && $maxW !== '' && (float)$maxW > 0 && $palletKg !== null && $palletKg > 0
                        && $palletKg - (float)$maxW > 1e-9) {
                        tj(['error' => 'Превышен макс. вес ячейки ' . $expected . ': паллета '
                            . $palletKg . ' кг > лимита ' . (float)$maxW . ' кг'], 409);
                    }
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
        $sscc = trim((string)($items[0]['pallet'] ?? ''));
        // Качество и ячейка-источник до переноса запаса — для журнала склада (модуль 14).
        $qualityBySku = [];
        $srcCellBySku = [];
        $nameBySku = [];
        foreach ($items as $x) {
            $nameBySku[(string)$x['sku']] = (string)($x['name'] ?? $x['sku']);
        }
        if ($sscc !== '') {
            try {
                $ndbJ = wms_nomenclature_pdo();
                $hasQ = wms_has_column($ndbJ, 'product_stock', 'quality');
                $qj = $ndbJ->prepare(
                    'SELECT p.sku, s.cell' . ($hasQ ? ', s.quality' : '')
                    . ' FROM product_stock s JOIN products p ON p.id = s.product_id'
                    . ' WHERE s.pallet = :p AND s.qty > 0 ORDER BY s.id'
                );
                $qj->execute([':p' => $sscc]);
                foreach ($qj->fetchAll() as $sr) {
                    $skuJ = trim((string)($sr['sku'] ?? ''));
                    if ($skuJ === '') {
                        continue;
                    }
                    if (!isset($srcCellBySku[$skuJ]) || $srcCellBySku[$skuJ] === '') {
                        $srcCellBySku[$skuJ] = trim((string)($sr['cell'] ?? ''));
                    }
                    if (($sr['quality'] ?? '') === 'Брак') {
                        $qualityBySku[$skuJ] = 'Брак';
                    }
                }
            } catch (Throwable) {
            }
        }
        // Товарный запас (08.1): переносим строки из ЗОНЫ-ПРИЕМКИ в ячейку.
        $stock = ['moved' => [], 'skipped' => []];
        if ($sum > 0 && $expected !== '') {
            try {
                $stock = tt_move_stock_to_cell($sscc, $expected, $moves,
                    (int)($head['warehouse_id'] ?? 0), (string)($head['doc_no'] ?? ''));
            } catch (Throwable $e) {
                $stock = ['moved' => [], 'skipped' => [], 'error' => $e->getMessage()];
            }
        }
        // Журнал склада: фактическое размещение паллеты (а не только резерв ячейки).
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            $jd = wms_journal_pdo();
            $movedMap = [];
            foreach (($stock['moved'] ?? []) as $mv) {
                $skuM = (string)($mv['sku'] ?? '');
                $movedMap[$skuM] = round(($movedMap[$skuM] ?? 0) + (float)($mv['qty'] ?? 0), 3);
            }
            foreach ($moves as $mv) {
                $skuM = trim((string)($mv['sku'] ?? ''));
                if ($skuM === '') {
                    continue;
                }
                $qtyM = isset($movedMap[$skuM]) ? (float)$movedMap[$skuM] : (float)($mv['qty'] ?? 0);
                if ($qtyM <= 0) {
                    continue;
                }
                WmsJournal::log($jd, [
                    'op_type' => 'размещение',
                    'doc_type' => 'Задание',
                    'doc_no' => (string)($head['doc_no'] ?? ''),
                    'sku' => $skuM,
                    'product_name' => $nameBySku[$skuM] ?? $skuM,
                    'qty' => $qtyM,
                    'pallet' => $sscc,
                    'sscc' => $sscc,
                    'cell_from' => $srcCellBySku[$skuM] ?? '',
                    'cell_to' => $expected,
                    'zone' => 'Хранение',
                    'user_id' => $uid,
                    'user_fio' => $fio,
                    'comment' => 'Размещение паллеты ' . $sscc . ' по заданию ' . (string)($head['doc_no'] ?? ''),
                    'quality' => $qualityBySku[$skuM] ?? 'новый',
                ]);
            }
        } catch (Throwable) {
        }
        $pdo->prepare('DELETE FROM tsd_task_locks WHERE task_id=:t')->execute([':t' => $tid]);
        $pr = $pdo->query("SELECT plan_qty, fact_qty, progress, status FROM tasks WHERE id=" . $tid)->fetch();
        tj(['ok' => true, 'task_id' => $tid, 'cell' => $expected, 'progress' => $pr, 'stock' => $stock,
            'hint' => 'Паллета размещена в ' . $expected]);
    } catch (Throwable $e) {
        tj(['error' => $e->getMessage()], 400);
    }
}

// --- Перемещение ЦЕЛОГО паллета по SSCC (форма ТСД «Перемещение → целый паллет»):
// Шаг 1: POST ?action=move_scan {sscc} -> состав паллеты, откуда, габариты.
// Шаг 2: POST ?action=move_check {sscc, cell} -> проверка настроек ячейки
//   (блокировка, место по паллетам/шт/весу/высоте, тара, темп. режим,
//    категория, опасный груз, правила секции/яруса). На любом несоответствии —
//   409 с текстом ошибки для ТСД.
// Шаг 3: POST ?action=move_confirm {sscc, cell, user_id?, user_fio?} ->
//   повторная проверка + фактическое перемещение (stock, ячейки, задание RE-...).
function tt_whole_pallet_info(string $sscc): array
{
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellPlacement.php';
    $sscc = trim($sscc);
    if ($sscc === '') {
        throw new RuntimeException('нужен sscc (отсканируй SSCC паллеты)');
    }
    $ndb = wms_nomenclature_pdo();
    // Строки запаса этой паллеты (только с остатком).
    $hasPt = false;
    $hasQl = false;
    try {
        $hasPt = wms_has_column($ndb, 'product_stock', 'pallet_type');
        $hasQl = wms_has_column($ndb, 'product_stock', 'quality');
    } catch (Throwable) {
    }
    $rows = [];
    try {
        $q = $ndb->prepare(
            'SELECT s.id, s.product_id, s.warehouse_id, s.cell, s.pallet, s.qty'
            . ($hasPt ? ', s.pallet_type' : '')
            . ($hasQl ? ', s.quality' : '')
            . ' FROM product_stock s WHERE s.pallet = :s AND s.qty > 0 ORDER BY s.id'
        );
        $q->execute([':s' => $sscc]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        throw new RuntimeException('БД номенклатуры недоступна: ' . $e->getMessage());
    }
    if (!$rows) {
        // Подсказка: может, паллета ещё в приёмке / в задании на размещение.
        $hint = '';
        try {
            $rdb = wms_receipts_pdo();
            $rq = $rdb->prepare('SELECT COUNT(*) FROM receipt_operations WHERE pallet = :s');
            $rq->execute([':s' => $sscc]);
            if ((int)$rq->fetchColumn() > 0) {
                $hint = ' Паллета есть в приёмке, но ещё не на стоке — сначала размести её (ТСД → Размещение).';
            }
        } catch (Throwable) {
        }
        if ($hint === '') {
            try {
                $tq = $GLOBALS['pdo'] ?? null;
                if ($tq instanceof PDO) {
                    $sq = $tq->prepare("SELECT doc_no FROM tasks t JOIN task_items i ON i.task_id=t.id
                        WHERE i.pallet=:s AND t.status IN ('new','work','paused') ORDER BY t.id DESC LIMIT 1");
                    $sq->execute([':s' => $sscc]);
                    if ($dn = $sq->fetchColumn()) {
                        $hint = ' Паллета в открытом задании ' . $dn . ' — заверши его или отсканируй после размещения.';
                    }
                }
            } catch (Throwable) {
            }
        }
        throw new RuntimeException('SSCC не найден на стоке: ' . $sscc . '.' . $hint);
    }
    $cells = array_values(array_unique(array_map(fn($r) => trim((string)($r['cell'] ?? '')), $rows)));
    if (count($cells) > 1) {
        throw new RuntimeException('Паллета ' . $sscc . ' разбита по ячейкам (' . implode(', ', $cells)
            . ') — целым паллетом переместить нельзя, используй частичное перемещение');
    }
    $cellFrom = $cells[0] ?? '';
    if ($cellFrom === '') {
        throw new RuntimeException('У паллеты ' . $sscc . ' нет ячейки-отправителя');
    }
    $whs = array_values(array_unique(array_map(fn($r) => (int)($r['warehouse_id'] ?? 0), $rows)));
    $wid = $whs[0] ?? 0;
    // Карточки товаров состава.
    $pcols = [];
    try {
        // Портативная проверка колонок (PRAGMA на PostgreSQL недопустим).
        $pcols = wms_table_columns($ndb, 'products');
    } catch (Throwable) {
    }
    $lines = [];
    $qtyTotal = 0.0;
    $weightKg = 0.0;
    $weightKnown = false;
    $volM3 = 0.0;
    $volKnown = false;
    $heightMm = null;
    $ptype = 'euro';
    $hasDanger = false;
    $hasQualityBad = false;
    $tFrom = null;
    $tTo = null;
    $cats = [];
    foreach ($rows as $r) {
        $sel = 'SELECT sku, name, category, temp_from, temp_to, is_danger, weight_g, volume_m3, height_mm'
            . (in_array('pallet_type', $pcols, true) ? ', pallet_type' : '')
            . (in_array('pallet_height_mm', $pcols, true) ? ', pallet_height_mm' : '')
            . ' FROM products WHERE id = :id LIMIT 1';
        $ps = $ndb->prepare($sel);
        $ps->execute([':id' => (int)$r['product_id']]);
        $pr = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
        $sku = (string)($pr['sku'] ?? ('id:' . $r['product_id']));
        $q = round((float)($r['qty'] ?? 0), 3);
        $qtyTotal = round($qtyTotal + $q, 3);
        $lineQuality = ((string)($r['quality'] ?? '') === 'Брак') ? 'Брак' : 'новый';
        if ($lineQuality === 'Брак') {
            $hasQualityBad = true;
        }
        $lines[] = ['sku' => $sku, 'name' => (string)($pr['name'] ?? $sku), 'qty' => $q,
            'category' => (string)($pr['category'] ?? ''), 'cell' => $cellFrom,
            'quality' => $lineQuality];
        if (trim((string)($pr['category'] ?? '')) !== '') {
            $cats[] = trim((string)$pr['category']);
        }
        if (!empty($pr['is_danger'])) {
            $hasDanger = true;
        }
        if (isset($pr['weight_g']) && $pr['weight_g'] !== null && (float)$pr['weight_g'] > 0) {
            $weightKg = round($weightKg + $q * (float)$pr['weight_g'] / 1000.0, 3);
            $weightKnown = true;
        }
        if (isset($pr['volume_m3']) && $pr['volume_m3'] !== null && (float)$pr['volume_m3'] > 0) {
            $volM3 = round($volM3 + $q * (float)$pr['volume_m3'], 6);
            $volKnown = true;
        }
        if (isset($pr['pallet_height_mm']) && $pr['pallet_height_mm'] !== null && (int)$pr['pallet_height_mm'] > 0) {
            $h = (int)$pr['pallet_height_mm'];
            $heightMm = $heightMm === null ? $h : max($heightMm, $h);
        }
        if (isset($pr['pallet_type']) && trim((string)$pr['pallet_type']) !== '') {
            $t = CellPlacement::normalizePalletType((string)$pr['pallet_type']);
            if ($t === 'fin') {
                $ptype = 'fin';
            } elseif ($ptype !== 'fin' && $t !== '') {
                $ptype = $t;
            }
        }
        if ($hasPt && trim((string)($r['pallet_type'] ?? '')) !== '') {
            $t = CellPlacement::normalizePalletType((string)$r['pallet_type']);
            if ($t === 'fin') {
                $ptype = 'fin';
            }
        }
        if (isset($pr['temp_from']) && $pr['temp_from'] !== null && $pr['temp_from'] !== '') {
            $tf = (float)$pr['temp_from'];
            $tFrom = $tFrom === null ? $tf : max($tFrom, $tf);
        }
        if (isset($pr['temp_to']) && $pr['temp_to'] !== null && $pr['temp_to'] !== '') {
            $tt = (float)$pr['temp_to'];
            $tTo = $tTo === null ? $tt : min($tTo, $tt);
        }
    }
    if ($qtyTotal <= 0) {
        throw new RuntimeException('По SSCC ' . $sscc . ' нет остатка к перемещению');
    }
    return [
        'sscc' => $sscc, 'cell_from' => $cellFrom, 'warehouse_id' => $wid,
        'qty' => $qtyTotal, 'weight_kg' => ($weightKnown ? $weightKg : null),
        'volume_m3' => ($volKnown ? $volM3 : null), 'height_mm' => $heightMm,
        'pallet_type' => $ptype,
        'pallet_type_label' => $ptype === 'fin' ? 'Фин' : 'Евро',
        'quality' => $hasQualityBad ? 'Брак' : 'новый',
        'has_danger' => $hasDanger, 'temp_from' => $tFrom, 'temp_to' => $tTo,
        'categories' => array_values(array_unique($cats)), 'lines' => $lines,
    ];
}

if ($action === 'move_scan' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sscc = trim((string)($b['sscc'] ?? $b['pallet'] ?? ''));
    try {
        $p = tt_whole_pallet_info($sscc);
        tj(['ok' => true, 'pallet' => $p,
            'hint' => 'Паллета ' . $p['sscc'] . ' в ячейке ' . $p['cell_from']
                . ' · ' . $p['qty'] . ' шт · тара ' . $p['pallet_type_label']
                . '. Отсканируй ячейку назначения.']);
    } catch (Throwable $e) {
        tj(['error' => $e->getMessage(), 'sscc' => $sscc], 404);
    }
}

// Проверка ячейки назначения под целый паллет (все запреты из настроек ячейки).
if ($action === 'move_check' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sscc = trim((string)($b['sscc'] ?? $b['pallet'] ?? ''));
    $cellCode = trim((string)($b['cell'] ?? $b['cell_to'] ?? ''));
    if ($sscc === '' || $cellCode === '') {
        tj(['error' => 'нужны sscc и cell (отсканируй SSCC паллеты и ячейку назначения)'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellPlacement.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellMoveCheck.php';
    try {
        $p = tt_whole_pallet_info($sscc);
        $sdb = wms_storage_pdo();
        $wid = (int)($p['warehouse_id'] ?? 0);
        $cs = $sdb->prepare('SELECT * FROM storage_cells WHERE code = :c'
            . ($wid ? ' AND warehouse_id = :w' : '') . ' LIMIT 1');
        $cp = [':c' => $cellCode];
        if ($wid) {
            $cp[':w'] = $wid;
        }
        $cs->execute($cp);
        $cell = $cs->fetch(PDO::FETCH_ASSOC);
        if (!$cell && $wid) {
            // Ячейка может быть на другом складе — ищем без привязки, чтобы дать точную ошибку.
            $cs2 = $sdb->prepare('SELECT * FROM storage_cells WHERE code = :c LIMIT 1');
            $cs2->execute([':c' => $cellCode]);
            $cell = $cs2->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$cell) {
            tj(['error' => 'Ячейка не найдена: ' . $cellCode, 'sscc' => $sscc, 'cell' => $cellCode], 404);
        }
        $snap = CellPlacement::snapshot($sdb, (int)($cell['warehouse_id'] ?? $wid) ?: null);
        $chk = CellMoveCheck::check($cell, $p, $snap, $sdb);
        if (!$chk['ok']) {
            tj(['error' => $chk['error'], 'code' => ($chk['code'] ?? 'deny'),
                'sscc' => $sscc, 'cell' => $cell['code'], 'pallet' => $p], 409);
        }
        tj(['ok' => true, 'sscc' => $sscc, 'cell_from' => $p['cell_from'], 'cell_to' => $cell['code'],
            'pallet' => $p,
            'cell' => ['code' => $cell['code'], 'status' => $cell['status'],
                'qty' => (float)($cell['qty'] ?? 0), 'capacity' => (float)($cell['capacity'] ?? 0),
                'pallet_count' => (int)($cell['pallet_count'] ?? 0),
                'max_pallets' => $cell['max_pallets'], 'max_weight_kg' => $cell['max_weight_kg'],
                'cell_height_mm' => $cell['cell_height_mm']],
            'hint' => 'Ячейка ' . $cell['code'] . ' подходит: можно подтверждать перемещение.']);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $code = str_starts_with($msg, 'SSCC') || str_contains($msg, 'разбита') ? 404 : 400;
        tj(['error' => $msg, 'sscc' => $sscc, 'cell' => $cellCode], $code);
    }
}

// Подтверждение перемещения целого паллета (повторная проверка + проведение).
if ($action === 'move_confirm' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sscc = trim((string)($b['sscc'] ?? $b['pallet'] ?? ''));
    $cellCode = trim((string)($b['cell'] ?? $b['cell_to'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? '')) !== '' ? trim((string)$b['user_fio']) : 'ТСД';
    if ($sscc === '' || $cellCode === '') {
        tj(['error' => 'нужны sscc и cell (отсканируй SSCC паллеты и ячейку назначения)'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellPlacement.php';
    require_once __DIR__ . '/../05-yacheyki-hraneniya/CellMoveCheck.php';
    require_once __DIR__ . '/../07-zadaniya/TaskService.php';
    try {
        $p = tt_whole_pallet_info($sscc);
        $sdb = wms_storage_pdo();
        $wid = (int)($p['warehouse_id'] ?? 0);
        $cs = $sdb->prepare('SELECT * FROM storage_cells WHERE code = :c'
            . ($wid ? ' AND warehouse_id = :w' : '') . ' LIMIT 1');
        $cp = [':c' => $cellCode];
        if ($wid) {
            $cp[':w'] = $wid;
        }
        $cs->execute($cp);
        $cell = $cs->fetch(PDO::FETCH_ASSOC);
        if (!$cell) {
            tj(['error' => 'Ячейка не найдена: ' . $cellCode, 'sscc' => $sscc], 404);
        }
        // Идемпотентность: паллета уже в целевой ячейке — не дублируем.
        if (CellPlacement::sameCellCode((string)($p['cell_from'] ?? ''), (string)$cell['code'])) {
            tj(['ok' => true, 'already' => true, 'sscc' => $sscc, 'cell' => $cell['code'],
                'hint' => 'Паллета уже находится в ячейке ' . $cell['code']]);
        }
        $snap = CellPlacement::snapshot($sdb, (int)($cell['warehouse_id'] ?? $wid) ?: null);
        $chk = CellMoveCheck::check($cell, $p, $snap, $sdb);
        if (!$chk['ok']) {
            tj(['error' => $chk['error'], 'code' => ($chk['code'] ?? 'deny'),
                'sscc' => $sscc, 'cell' => $cell['code']], 409);
        }
        $cellFrom = (string)$p['cell_from'];
        $cellTo = (string)$cell['code'];
        $qtyMove = round((float)$p['qty'], 3);
        $ptype = CellPlacement::normalizePalletType((string)$p['pallet_type']);
        $dstWid = (int)($cell['warehouse_id'] ?? $wid);
        // 1) Задание на перемещение (RE-...) — след для реестра и истории.
        $svc = new TaskService($pdo);
        $items = [];
        foreach ($p['lines'] as $ln) {
            $items[] = ['sku' => $ln['sku'], 'name' => $ln['name'], 'unit' => 'шт',
                'cell_from' => $cellFrom, 'cell_to' => $cellTo,
                'pallet' => $sscc, 'plan_qty' => $ln['qty']];
        }
        try {
            $tid = $svc->create([
                'type' => 'перемещение',
                'warehouse_id' => $dstWid ?: $wid ?: 1,
                'doc_ref' => 'SSCC ' . $sscc,
                'executor_id' => $uid,
                'executor_fio' => $fio,
                'comment' => 'ТСД перемещение целого паллета: ' . $sscc . ' ' . $cellFrom . ' → ' . $cellTo,
                'items' => $items,
            ], $uid);
        } catch (Throwable $e) {
            tj(['error' => 'Не удалось создать задание: ' . $e->getMessage()], 500);
        }
        $docNo = (string)$pdo->query('SELECT doc_no FROM tasks WHERE id = ' . (int)$tid)->fetchColumn();
        // 2) Товарный запас: переносим все строки паллеты в новую ячейку.
        $ndb = wms_nomenclature_pdo();
        try {
            $up = $ndb->prepare('UPDATE product_stock SET cell = :to WHERE pallet = :s AND cell = :from AND qty > 0');
            $up->execute([':to' => $cellTo, ':s' => $sscc, ':from' => $cellFrom]);
            if ($up->rowCount() === 0) {
                throw new RuntimeException('Остаток паллеты изменился — обнови форму и отсканируй SSCC заново');
            }
            foreach ($p['lines'] as $ln) {
                try {
                    $pid = (int)$ndb->query("SELECT id FROM products WHERE sku = "
                        . $ndb->quote((string)$ln['sku']) . ' LIMIT 1')->fetchColumn();
                    if ($pid > 0) {
                        $bal = (float)$ndb->query('SELECT qty FROM products WHERE id = ' . $pid)->fetchColumn();
                        $ndb->prepare(
                            "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                             VALUES (:p,datetime('now'),'move',:d,:c,0,:b)"
                        )->execute([':p' => $pid, ':d' => 'Перемещение ' . $docNo, ':c' => $cellFrom . ' → ' . $cellTo, ':b' => $bal]);
                    }
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            // Откатываем задание, чтобы не плодить пустые RE-....
            try {
                (new TaskService($pdo))->delete($tid, $uid);
            } catch (Throwable) {
            }
            tj(['error' => 'Не удалось переместить запас: ' . $e->getMessage()], 500);
        }
        // 3) Ячейки хранения: освобождаем старую, занимаем новую.
        try {
            // Источник: ищем по коду (тот же склад, иначе любой).
            $src = $sdb->prepare('SELECT * FROM storage_cells WHERE code = :c'
                . ($wid ? ' AND warehouse_id = :w' : '') . ' LIMIT 1');
            $sp = [':c' => $cellFrom];
            if ($wid) {
                $sp[':w'] = $wid;
            }
            $src->execute($sp);
            $srcRow = $src->fetch(PDO::FETCH_ASSOC);
            if (!$srcRow) {
                $src2 = $sdb->prepare('SELECT * FROM storage_cells WHERE code = :c LIMIT 1');
                $src2->execute([':c' => $cellFrom]);
                $srcRow = $src2->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if ($srcRow) {
                $newQty = max(0, round((float)($srcRow['qty'] ?? 0) - $qtyMove, 3));
                $newPal = max(0, (int)($srcRow['pallet_count'] ?? 0) - 1);
                $newStatus = ($newQty <= 0 && $newPal <= 0) ? 'free' : 'busy';
                $sdb->prepare('UPDATE storage_cells SET qty = :q, pallet_count = :pc, status = :st'
                    . ($newStatus === 'free' ? ', pallet_type = NULL' : '')
                    . ' WHERE id = :id')
                    ->execute([':q' => $newQty, ':pc' => $newPal, ':st' => $newStatus, ':id' => $srcRow['id']]);
                try {
                    $sdb->prepare(
                        "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                         VALUES (:c, :u, :f, 'move', 'Перемещение', :d, :o, :n)"
                    )->execute([':c' => $srcRow['id'], ':u' => $uid, ':f' => $fio,
                        ':d' => 'Перемещение ' . $docNo, ':o' => $cellFrom . ' · ' . $sscc, ':n' => $cellTo]);
                    $sdb->prepare(
                        "INSERT INTO cell_movements (cell_id, doc_date, doc_type, doc_no, sku, qty_change, balance, cell_from, cell_to)
                         VALUES (:c, datetime('now'), 'move', :d, :s, :q, :b, :f, :t)"
                    )->execute([':c' => $srcRow['id'], ':d' => 'Перемещение ' . $docNo, ':s' => $sscc,
                        ':q' => -$qtyMove, ':b' => $newQty, ':f' => $cellFrom, ':t' => $cellTo]);
                } catch (Throwable) {
                }
                try {
                    CellPlacement::syncTempBlocks($sdb, (int)$srcRow['warehouse_id'],
                        $srcRow['building'] ?? null, $srcRow['row_no'] ?? null, $srcRow['level_no'] ?? null);
                } catch (Throwable) {
                }
            }
            // Назначение: свободная проверенная ячейка -> busy.
            $sdb->prepare(
                'UPDATE storage_cells SET qty = COALESCE(qty,0) + :q, pallet_count = COALESCE(pallet_count,0) + 1,
                        pallet_type = :pt, status = \'busy\' WHERE id = :id'
            )->execute([':q' => $qtyMove, ':pt' => $ptype, ':id' => $cell['id']]);
            try {
                $sdb->prepare(
                    "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                     VALUES (:c, :u, :f, 'move', 'Перемещение', :d, :o, :n)"
                )->execute([':c' => $cell['id'], ':u' => $uid, ':f' => $fio,
                    ':d' => 'Перемещение ' . $docNo, ':o' => $cellFrom . ' · ' . $sscc, ':n' => $cellTo . ' · +' . $qtyMove]);
                $sdb->prepare(
                    "INSERT INTO cell_movements (cell_id, doc_date, doc_type, doc_no, sku, qty_change, balance, cell_from, cell_to)
                     VALUES (:c, datetime('now'), 'move', :d, :s, :q, :b, :f, :t)"
                )->execute([':c' => $cell['id'], ':d' => 'Перемещение ' . $docNo, ':s' => $sscc,
                    ':q' => $qtyMove, ':b' => round((float)($cell['qty'] ?? 0) + $qtyMove, 3),
                    ':f' => $cellFrom, ':t' => $cellTo]);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            tj(['error' => 'Запас перенесён, но ячейки не обновлены: ' . $e->getMessage()
                . ' (задание ' . $docNo . ')', 'task_id' => $tid, 'doc_no' => $docNo], 500);
        }
        // Журнал склада: перемещение целого паллета (смена ячейки, SSCC, качества).
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            $jd = wms_journal_pdo();
            foreach (($p['lines'] ?? []) as $ln) {
                $skuJ = trim((string)($ln['sku'] ?? ''));
                $qtyJ = (float)($ln['qty'] ?? 0);
                if ($skuJ === '' || $qtyJ <= 0) {
                    continue;
                }
                WmsJournal::log($jd, [
                    'op_type' => 'перемещение',
                    'doc_type' => 'Задание',
                    'doc_no' => $docNo,
                    'sku' => $skuJ,
                    'product_name' => (string)($ln['name'] ?? $skuJ),
                    'qty' => $qtyJ,
                    'pallet' => $sscc,
                    'sscc' => $sscc,
                    'cell_from' => $cellFrom,
                    'cell_to' => $cellTo,
                    'zone' => 'Хранение',
                    'user_id' => $uid,
                    'user_fio' => $fio,
                    'comment' => 'Перемещение целого паллета ' . $sscc . ' по заданию ' . $docNo,
                    'quality' => (string)($ln['quality'] ?? 'новый'),
                ]);
            }
        } catch (Throwable) {
        }
        // 4) Закрываем задание фактом (целый паллет = всё сразу).
        try {
            $iq = $pdo->prepare('SELECT id, plan_qty FROM task_items WHERE task_id = :t ORDER BY id');
            $iq->execute([':t' => $tid]);
            foreach ($iq->fetchAll(PDO::FETCH_ASSOC) as $it) {
                $svc->pick((int)$it['id'], (float)$it['plan_qty'], $uid);
            }
            $pdo->prepare("INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value)
                VALUES (:t,:u,'move',:f,:o,:n)")
                ->execute([':t' => $tid, ':u' => $uid, ':f' => 'Паллет ' . $sscc,
                    ':o' => $cellFrom, ':n' => $cellTo . ' · ' . $docNo]);
        } catch (Throwable) {
        }
        $pr = $pdo->query('SELECT plan_qty, fact_qty, progress, status FROM tasks WHERE id = ' . (int)$tid)->fetch();
        tj(['ok' => true, 'task_id' => $tid, 'doc_no' => $docNo, 'sscc' => $sscc,
            'cell_from' => $cellFrom, 'cell_to' => $cellTo, 'progress' => $pr,
            'hint' => 'Паллета ' . $sscc . ' перемещена: ' . $cellFrom . ' → ' . $cellTo . ' (' . $docNo . ')']);
    } catch (Throwable $e) {
        // Если это уже готовый tj-ответ — он бы вышел через exit; сюда попадаем только с исключениями.
        tj(['error' => $e->getMessage(), 'sscc' => $sscc, 'cell' => $cellCode], 400);
    }
}

tj(['error' => 'unknown action: ?action=tasks|task|take|release|confirm|problem|move_to_sort|place_scan|place_retarget|place_confirm|move_scan|move_check|move_confirm'], 404);
