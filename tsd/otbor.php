<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
// Буфер отбора: виртуальная промежуточная ячейка пользователя (VIRT-<user_id>),
// перенос паллеты на ворота и сборка на сборочной паллете по SSCC.
require_once __DIR__ . '/../shared/PickBuffer.php';
// ТСД «Отбор» — алгоритм из схемы «Отбор. ТСД»:
//   роль (кладовщик/водитель) -> задания по приоритетам (1 наивысший) ->
//   шапка (код/клиент/ворота) + построчно (ячейка/артикул/наименование/фото/кол-во/паллет) ->
//   скан ячейки -> скан ШК (+«Проблема с товаром»: Брак/Нет товара -> блокировка ячейки +
//   новое резервирование + Log: id ячейки, id товара, id сотрудника, id причины, дата) ->
//   ввод кол-ва (допустимо меньшее, большее запрещено) -> скан паллеты
//   (чужая отгрузка? зона сортировки? объем? паллета в ячейке пользователя?) ->
//   ворота: скан ячейки отгрузки -> контроль переноса в зону сортировки (+Log ошибки).
// Ветка волны: пересечение SKU -> суммарная потребность -> маршрут (ячейка 1 раз) ->
//   шапка Ворота1/2/3+итого -> раскладка по клиентам -> распределение по воротам (SSCC).
// Пишет в ЕДИНУЮ БД отгрузок (03-otgruzki/wms_demo.sqlite через shared/db.php):
// tsd_picks (факт), shipments.readiness, shipment_history (pick/otbor_take/cell_block/move_to_sort).
// shipment_history (pick/otbor_take/cell_block/move_to_sort). Совместимо с /shipments и ship.php.

const OTBOR_STATUSES = ['created', 'assembling'];
const OTBOR_WAIT_SEC = 120; // таймер 2 мин: «в ожидании» -> возврат в доступ

function otk(): bool
{
    // Токен ТСД — из окружения (shared/tsd_auth.php): ?token=, заголовок X-Tsd-Token, Bearer.
    if (wms_tsd_token_ok()) return true;
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    return isset($_SESSION['wms_auth_user']);
}
function oj(mixed $d, int $c = 200): void
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}
function odb(): PDO
{
    // Единая БД отгрузок (владелец — 03-otgruzki). См. shared/db.php.
    require_once __DIR__ . '/../shared/db.php';
    if (function_exists('wms_use_pgsql') && wms_use_pgsql()) return wms_pg_pdo();
    $pdo = wms_shipments_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_picks (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, sku TEXT NOT NULL, qty REAL NOT NULL DEFAULT 1, user_id INTEGER, sscc TEXT, created_at TEXT DEFAULT (datetime('now')))");
    // Миграция: SSCC сортировки (номер паллеты, указанный при отборе через ТСД).
    try {
        $has = false;
        foreach ($pdo->query("PRAGMA table_info(tsd_picks)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (($c['name'] ?? '') === 'sscc') {
                $has = true;
                break;
            }
        }
        if (!$has) {
            $pdo->exec("ALTER TABLE tsd_picks ADD COLUMN sscc TEXT");
        }
    } catch (Throwable) {
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_otbor_locks (shipment_id INTEGER PRIMARY KEY, user_id INTEGER, user_fio TEXT, status TEXT NOT NULL DEFAULT 'work', updated_at TEXT DEFAULT (datetime('now')))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tsd_otbor_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER, cell TEXT, sku TEXT, user_id INTEGER, reason TEXT, created_at TEXT DEFAULT (datetime('now')))");
    return $pdo;
}
function oprogress(PDO $pdo, int $sid): array
{
    $plan = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM shipment_items WHERE shipment_id=' . $sid)->fetchColumn();
    $fact = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=' . $sid)->fetchColumn();
    $pct = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    return ['plan' => $plan, 'fact' => $fact, 'pct' => $pct];
}
require_once __DIR__ . '/../shared/PickIssue.php';
// Ячейка отбора: реальная ячейка из справочника (cell_content/storage_cells),
// иначе — ворота/склад. Уровень ячейки нужен для фильтра по роли:
// водитель => уровень >= 2, кладовщик => уровень = 1.
function ocell(array $head, array $item): string
{
    static $storage = null;
    static $init = false;
    if (!$init) {
        $init = true;
        try {
            require_once __DIR__ . '/../shared/db.php';
            $storage = wms_storage_pdo();
        } catch (Throwable) {
            $storage = null;
        }
    }
    $sku = (string)($item['sku'] ?? '');
    $wh = (int)($head['warehouse_id'] ?? 1);
    // 1) Если отбор уже выпущен как задание — берём ячейку из task_items (там реальный уровень).
    if ($sku !== '') {
        if (function_exists('wms_use_pgsql') && wms_use_pgsql()) {
            try {
                $tp = wms_pg_pdo();
                $st = $tp->prepare("SELECT cell_from FROM task_items WHERE sku=:s ORDER BY id DESC LIMIT 1");
                $st->execute([':s' => $sku]);
                $c = $st->fetchColumn();
                if ($c) return (string)$c;
            } catch (Throwable) {}
        } else {
            try {
                $tp = wms_tasks_pdo();
                $st = $tp->prepare("SELECT cell_from FROM task_items WHERE sku=:s ORDER BY id DESC LIMIT 1");
                $st->execute([':s' => $sku]);
                $c = $st->fetchColumn();
                if ($c) {
                    return (string)$c;
                }
            } catch (Throwable) {
            }
        }
    }
    // 2) Остаток в ячейке.
    if ($storage && $sku !== '') {
        try {
            $st = $storage->prepare(
                "SELECT sc.code FROM cell_content cc JOIN storage_cells sc ON sc.id=cc.cell_id
                 WHERE cc.sku=:s AND sc.warehouse_id=:w AND sc.is_archived=0 AND sc.is_locked=0 ORDER BY cc.qty DESC LIMIT 1"
            );
            $st->execute([':s' => $sku, ':w' => $wh]);
            $c = $st->fetchColumn();
            if ($c) {
                return (string)$c;
            }
        } catch (Throwable) {
        }
    }
    if (!empty($head['gate'])) {
        return 'ВОРОТА-' . $head['gate'];
    }
    return 'СКЛАД-' . ($head['warehouse_id'] ?? '?');
}
function ofree_waiting(PDO $pdo): void
{
    // Возраст блокировки в секундах: SQLite — strftime, PostgreSQL — EXTRACT(EPOCH).
    $age = wms_is_sqlite($pdo)
        ? "(strftime('%s','now')-strftime('%s',updated_at))"
        : "(EXTRACT(EPOCH FROM NOW())-EXTRACT(EPOCH FROM updated_at::timestamp))";
    $pdo->exec("DELETE FROM tsd_otbor_locks WHERE status='waiting' AND " . $age . ">" . OTBOR_WAIT_SEC);
}

/** Дата/время строки лога в формате ДД.ММ.ГГГГ ЧЧ:ММ (SQLite/PostgreSQL). */
function o_dt_sql(PDO $pdo): string
{
    return wms_is_sqlite($pdo)
        ? "strftime('%d.%m.%Y %H:%M',created_at)"
        : "TO_CHAR(created_at,'DD.MM.YYYY HH24:MI')";
}

if (!otk()) oj(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
$pdo = odb();
$action = $_GET['action'] ?? 'tasks';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Задания по приоритетам (1 = наивысший): high -> normal -> low, затем план. дата, id ---
if ($action === 'tasks') {
    ofree_waiting($pdo);
    $role = trim((string)($_GET['role'] ?? 'storekeeper')); // storekeeper|driver
    $in = "'" . implode("','", OTBOR_STATUSES) . "'";
    $rows = $pdo->query(
        "SELECT s.id, s.doc_no, s.status, s.consignee, s.vehicle, s.gate, s.priority, s.plan_shipment_date,
                s.warehouse_id, w.name AS warehouse, s.loading_type,
                (SELECT COALESCE(SUM(qty),0) FROM shipment_items WHERE shipment_id=s.id) AS plan_qty,
                (SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=s.id) AS fact_qty,
                l.user_id AS lock_user, l.user_fio AS lock_fio, l.status AS lock_status
         FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id
         LEFT JOIN tsd_otbor_locks l ON l.shipment_id=s.id
         WHERE s.status IN ($in) ORDER BY
            CASE s.priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 WHEN 'lowest' THEN 4 ELSE 5 END,
           s.plan_shipment_date IS NULL, s.plan_shipment_date ASC, s.id ASC LIMIT 100"
    )->fetchAll();
    // Фильтр по роли через уровень ячейки: водитель => >=2, кладовщик => =1.
    // Уровень считаем по реальным ячейкам позиций (ocell), без уровня — показываем всем.
    $role = ($role === 'driver') ? 'driver' : 'storekeeper';
    if ($rows) {
        $sids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $sheads = [];
        foreach ($rows as $r) {
            $sheads[(int)$r['id']] = $r;
        }
        try {
            $q = $pdo->query("SELECT shipment_id, sku FROM shipment_items WHERE shipment_id IN ($sids)");
            $lvls = [];
            foreach ($q->fetchAll() as $it) {
                $sid = (int)$it['shipment_id'];
                $lvl = wms_pick_cell_level(ocell($sheads[$sid], $it));
                if ($lvl !== null) {
                    $lvls[$sid][] = $lvl;
                }
            }
            $rows = array_values(array_filter($rows, function ($r) use ($lvls, $role) {
                $ll = $lvls[(int)$r['id']] ?? [];
                if (!$ll) {
                    return true;
                }
                foreach ($ll as $lvl) {
                    if ($role === 'driver' && $lvl >= 2) {
                        return true;
                    }
                    if ($role === 'storekeeper' && $lvl === 1) {
                        return true;
                    }
                }
                return false;
            }));
            foreach ($rows as &$r) {
                $ll = $lvls[(int)$r['id']] ?? [];
                if ($ll) {
                    $r['levels'] = array_values(array_unique($ll));
                    $r['min_level'] = min($ll);
                    $r['max_level'] = max($ll);
                }
            }
            unset($r);
        } catch (Throwable) {
        }
    }
    $prio = 0;
    foreach ($rows as &$r) {
        $prio++;
        $r['prio'] = $prio; // 1 = наивысший
        $plan = (float)($r['plan_qty'] ?? 0);
        $fact = (float)($r['fact_qty'] ?? 0);
        $r['pct'] = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
        // Тип отбора по роли: кладовщик = ручной, водитель = водительский
        $r['pick_type'] = ($role === 'driver') ? 'driver' : 'manual';
        $r['pick_type_ru'] = ($role === 'driver') ? 'Отбор водителем' : 'Ручной отбор';
    }
    // Волна: пересечение SKU между активными заданиями?
    $need = [];
    if (count($rows) >= 2) {
        $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
        $q = $pdo->query("SELECT sku, COUNT(DISTINCT shipment_id) c, SUM(qty) q FROM shipment_items WHERE shipment_id IN ($ids) GROUP BY sku HAVING COUNT(DISTINCT shipment_id)>1");
        foreach ($q->fetchAll() as $w) $need[$w['sku']] = ['ships' => (int)$w['c'], 'qty' => (float)$w['q']];
    }
    oj(['rows' => $rows, 'role' => $role,
        'role_hint' => $role === 'driver' ? 'Водитель: уровень ячейки >= 2' : 'Кладовщик: уровень ячейки = 1',
        'wave' => ['possible' => !empty($need), 'cross_sku' => $need,
        'hint' => !empty($need) ? 'Есть пересечение SKU — можно волной (3 отгрузки/3 ворота/3 клиента)' : null]]);
}

// --- Карточка задания: шапка + построчные строки отбора ---
if ($action === 'task') {
    $id = (int)($_GET['id'] ?? 0);
    $h = $pdo->prepare('SELECT s.*, w.name AS warehouse FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.id=:id');
    $h->execute([':id' => $id]);
    $head = $h->fetch();
    if (!$head) oj(['error' => 'Отгрузка не найдена'], 404);
    $items = $pdo->prepare(
        'SELECT i.id, i.code, i.sku, i.name, i.unit, i.qty AS plan_qty,
                COALESCE((SELECT SUM(p.qty) FROM tsd_picks p WHERE p.shipment_id=i.shipment_id AND p.sku=i.sku),0) AS fact_qty
         FROM shipment_items i WHERE i.shipment_id=:id ORDER BY i.id'
    );
    $items->execute([':id' => $id]);
    $rows = $items->fetchAll();
    // Штрихкоды товара из номенклатуры («Штрихкоды (через запятую)») — сверка скана на ТСД.
    require_once __DIR__ . '/../shared/db.php';
    $bcs = wms_nomenclature_barcodes(array_column($rows, 'sku'));
    foreach ($rows as &$it) {
        $it['cell'] = ocell($head, $it); // ячейка отбора
        $it['need'] = max(0, (float)$it['plan_qty'] - (float)$it['fact_qty']); // к отбору
        $it['barcodes'] = $bcs[(string)($it['sku'] ?? '')] ?? [];
        $it['photo'] = '📦';
    }
    unset($it);
    $lock = $pdo->prepare('SELECT * FROM tsd_otbor_locks WHERE shipment_id=:id');
    $lock->execute([':id' => $id]);
    oj(['head' => $head, 'items' => $rows, 'progress' => oprogress($pdo, $id), 'lock' => $lock->fetch() ?: null,
        'buffer' => wms_pick_buffer_state($pdo, $id),
        'gates' => wms_pick_shipment_gates($pdo, $id)['gates']]);
}

// --- Взять задание: статус «в работе», закрепление за сотрудником ---
if ($action === 'take' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($sid <= 0) oj(['error' => 'нужен shipment_id'], 422);
    ofree_waiting($pdo);
    // Возврат на форму при вылете: у сотрудника уже есть задание в работе?
    if ($uid) {
        $mine = $pdo->prepare("SELECT * FROM tsd_otbor_locks WHERE user_id=:u AND status='work' AND shipment_id!=:s LIMIT 1");
        $mine->execute([':u' => $uid, ':s' => $sid]);
        if ($row = $mine->fetch()) oj(['error' => 'У тебя уже есть задание в работе: №' . $row['shipment_id'] . '. Вернись на форму.', 'busy_id' => (int)$row['shipment_id']], 409);
    }
    // Следующий отбор запрещён, пока товар не перемещён на ворота и не собран
    // на сборочной паллете (в буфере остались открытые позиции).
    $openBuf = wms_pick_buffer_open($pdo, $sid, $uid);
    if ($openBuf && !wms_pick_session_shipment($pdo, $sid, $uid)) {
        oj(['error' => wms_pick_buffer_block_text($openBuf),
            'open_positions' => count($openBuf),
            'buffer' => wms_pick_buffer_state($pdo, $sid),
            'need_gate' => true, 'need_assembly' => true], 409);
    }
    $busy = $pdo->prepare('SELECT * FROM tsd_otbor_locks WHERE shipment_id=:s');
    $busy->execute([':s' => $sid]);
    if ($bl = $busy->fetch()) {
        if (($bl['status'] ?? '') === 'work' && (int)($bl['user_id'] ?? 0) !== (int)($uid ?? -1)) {
            oj(['error' => 'Задание уже в работе у: ' . ($bl['user_fio'] ?: ('id ' . $bl['user_id']))], 409);
        }
    }
    $pdo->prepare('INSERT INTO tsd_otbor_locks (shipment_id,user_id,user_fio,status,updated_at) VALUES (:s,:u,:f,\'work\',datetime(\'now\'))
        ON CONFLICT(shipment_id) DO UPDATE SET user_id=:u,user_fio=:f,status=\'work\',updated_at=datetime(\'now\')')
        ->execute([':s' => $sid, ':u' => $uid, ':f' => ($fio !== '' ? $fio : null)]);
    $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'otbor_take','в ожидании/доступ','в работе')")
        ->execute([':s' => $sid, ':u' => $uid]);
    oj(['ok' => true, 'status' => 'work']);
}

// --- Выход из задания: статус «в ожидании», таймер 2 мин ---
if ($action === 'release' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($sid <= 0) oj(['error' => 'нужен shipment_id'], 422);
    $pdo->prepare("UPDATE tsd_otbor_locks SET status='waiting', updated_at=datetime('now') WHERE shipment_id=:s")
        ->execute([':s' => $sid]);
    $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'otbor_wait','в работе','в ожидании (2 мин)')")
        ->execute([':s' => $sid, ':u' => $uid]);
    oj(['ok' => true, 'status' => 'waiting', 'wait_sec' => OTBOR_WAIT_SEC]);
}

// --- Проблема с товаром: Брак / Нет товара -> блокировка ячейки + новое резервирование (stub) + Log ---
if ($action === 'problem' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $sku = trim((string)($b['sku'] ?? ''));
    $cell = trim((string)($b['cell'] ?? ''));
    $reason = trim((string)($b['reason'] ?? '')); // брак | нет_товара
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($sid <= 0 || $sku === '' || !in_array($reason, ['брак', 'нет_товара'], true)) oj(['error' => 'нужны shipment_id, sku, reason=брак|нет_товара'], 422);
    $block = $reason === 'брак' ? 'Брак' : 'Инвентаризация';
    // Ячейка реально блокируется, оператору подбирается другая.
    // Раньше только писалась запись в журнал и в историю, а ответ утверждал,
    // что ячейка заблокирована и новое резервирование создано.
    $reserved = ['blocked' => false, 'cell' => '', 'reason' => $block];
try {
        require_once __DIR__ . '/../shared/db.php';
        require_once __DIR__ . '/../shared/PickIssue.php';
        $wh = (int)$pdo->query('SELECT warehouse_id FROM shipments WHERE id=' . (int)$sid)->fetchColumn();
        $reserved = wms_block_cell_and_reserve(wms_storage_pdo(), $cell, $sku, (int)$wh ?: 1, $block);
    } catch (Throwable) {
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO tsd_otbor_logs (shipment_id,cell,sku,user_id,reason) VALUES (:s,:c,:sku,:u,:r)')
            ->execute([':s' => $sid, ':c' => ($cell !== '' ? $cell : null), ':sku' => $sku, ':u' => $uid, ':r' => $block]);
        $note = 'Блокировка: ' . $block
            . ($reserved['blocked'] ? ' (ячейка заблокирована)' : ' (ячейку заблокировать не удалось)')
            . ($reserved['cell'] !== '' ? ' · новая ячейка: ' . $reserved['cell'] : ' · новая ячейка не подобрана');
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value) VALUES (:s,:u,'cell_block',:f,:o,:n)")
            ->execute([':s' => $sid, ':u' => $uid, ':f' => 'Ячейка ' . $cell, ':o' => $sku, ':n' => $note]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        oj(['error' => $e->getMessage()], 500);
    }
    $log = $pdo->query('SELECT id, shipment_id, cell, sku, user_id, reason, ' . o_dt_sql($pdo) . ' AS dt FROM tsd_otbor_logs ORDER BY id DESC LIMIT 1')->fetch();
    oj(['ok' => true, 'block' => $block, 'log' => $log,
        'cell_blocked' => (bool)$reserved['blocked'],
        'new_cell' => $reserved['cell'],
        'hint' => $reserved['blocked']
            ? 'Ячейка заблокирована (' . $block . ').'
              . ($reserved['cell'] !== '' ? ' Продолжай с ячейки ' . $reserved['cell'] . '.' : ' Подбери другую ячейку вручную.')
            : 'Ячейку заблокировать не удалось — обратись к мастеру склада.']);
}

// --- Подтверждение строки: qty <= остатка к отбору; допустимо меньшее ---
if ($action === 'confirm' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $sku = trim((string)($b['sku'] ?? ''));
    $qty = (float)($b['qty'] ?? 0);
    $cell = trim((string)($b['cell'] ?? ''));
    $pallet = trim((string)($b['pallet'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($sid <= 0 || $sku === '' || $qty <= 0) oj(['error' => 'нужны shipment_id, sku, qty>0'], 422);
    // Следующий отбор запрещён, пока предыдущий товар не перемещён на ворота
    // и не собран на сборочной паллете (открытые позиции буфера).
    // Волна (wave=1) — один сеанс отбора на несколько отгрузок: проверка не применяется.
    $isWave = (int)($b['wave'] ?? 0) === 1;
    if (!$isWave) {
        $openBuf = wms_pick_buffer_open($pdo, $sid, $uid);
        if ($openBuf && !wms_pick_session_shipment($pdo, $sid, $uid)) {
            oj(['error' => wms_pick_buffer_block_text($openBuf),
                'open_positions' => count($openBuf),
                'buffer' => wms_pick_buffer_state($pdo, $sid),
                'need_gate' => true, 'need_assembly' => true], 409);
        }
    }
    $it = $pdo->prepare('SELECT qty FROM shipment_items WHERE shipment_id=:s AND sku=:sku LIMIT 1');
    $it->execute([':s' => $sid, ':sku' => $sku]);
    $plan = (float)($it->fetchColumn() ?: 0);
    $fact = (float)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=$sid AND sku=" . $pdo->quote($sku))->fetchColumn();
    $need = $plan - $fact;
    if ($qty - $need > 1e-9) oj(['error' => 'Введено большее количество: к отбору ' . $need, 'need' => $need], 409);
    // Паллета: контроль на уровне кода (чужая отгрузка / зона сортировки).
    if ($pallet !== '') {
        try {
            require_once __DIR__ . '/../shared/db.php';
            $pc = wms_pick_pallet_check($pdo, wms_storage_pdo(), wms_nomenclature_pdo(), $sid, $pallet);
            if (!empty($pc['checks']['other_shipment'])) {
                oj(['error' => 'Паллета ' . $pallet . ' принадлежит другой отгрузке', 'pallet' => $pallet], 409);
            }
            if (!empty($pc['checks']['in_sort'])) {
                oj(['error' => 'Паллета ' . $pallet . ' находится в зоне сортировки', 'pallet' => $pallet], 409);
            }
        } catch (Throwable) {
            // Проверка паллеты не должна блокировать отбор.
        }
    }
    $pdo->beginTransaction();
    try {
        try {
            $pdo->prepare('INSERT INTO tsd_picks (shipment_id,sku,qty,user_id,sscc) VALUES (:s,:sku,:q,:u,:sscc)')
                ->execute([':s' => $sid, ':sku' => $sku, ':q' => $qty, ':u' => $uid, ':sscc' => ($pallet !== '' ? $pallet : null)]);
        } catch (Throwable) {
            // Нет колонки sscc (старая БД) — пишем без неё.
            $pdo->prepare('INSERT INTO tsd_picks (shipment_id,sku,qty,user_id) VALUES (:s,:sku,:q,:u)')
                ->execute([':s' => $sid, ':sku' => $sku, ':q' => $qty, ':u' => $uid]);
        }
        $pr = oprogress($pdo, $sid);
        $pdo->prepare('UPDATE shipments SET readiness=:p WHERE id=:id')->execute([':p' => $pr['pct'], ':id' => $sid]);
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'pick',:o,:n)")
            ->execute([':s' => $sid, ':u' => $uid, ':o' => "Ячейка $cell · $sku", ':n' => "Отобрано x$qty · паллет " . ($pallet !== '' ? $pallet : '—')]);
        // Товар перемещён в виртуальную промежуточную ячейку пользователя (VIRT-<user_id>).
        $pname = '';
        try {
            $nm = $pdo->prepare('SELECT name FROM shipment_items WHERE shipment_id=:s AND sku=:sku LIMIT 1');
            $nm->execute([':s' => $sid, ':sku' => $sku]);
            $pname = (string)($nm->fetchColumn() ?: '');
        } catch (Throwable) {
        }
        $bufId = wms_pick_buffer_add($pdo, $sid, null, $sku, $pname, $qty, $uid, $fio);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        oj(['error' => $e->getMessage()], 500);
    }
    oj(['ok' => true, 'progress' => oprogress($pdo, $sid), 'need_left' => max(0, $need - $qty),
        'buffer_cell' => wms_pick_buffer_cell($uid), 'buffer_id' => $bufId,
        'buffer' => wms_pick_buffer_state($pdo, $sid)]);
}

// --- Проверка паллеты при отборе: решает система, оператору не задают вопросы ---
// POST ?action=pallet_check {shipment_id, pallet?, volume?} -> вердикт по паллете.
if ($action === 'pallet_check' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? $b['task_id'] ?? 0);
    $pallet = trim((string)($b['pallet'] ?? ''));
    $volume = isset($b['volume']) && $b['volume'] !== '' ? (float)$b['volume'] : null;
    if ($sid <= 0) {
        oj(['error' => 'нужен shipment_id'], 422);
    }
    require_once __DIR__ . '/../shared/db.php';
    $h = $pdo->prepare('SELECT id, doc_no, consignee, gate, status FROM shipments WHERE id=:id');
    $h->execute([':id' => $sid]);
    $sh = $h->fetch(PDO::FETCH_ASSOC);
    if (!$sh) {
        oj(['error' => 'Отгрузка не найдена'], 404);
    }
    $res = wms_pick_pallet_check($pdo, wms_storage_pdo(), wms_nomenclature_pdo(), (int)$sh['id'], $pallet, $volume);
    $res['shipment'] = [
        'id' => (int)$sh['id'], 'doc_no' => (string)($sh['doc_no'] ?? ''),
        'consignee' => (string)($sh['consignee'] ?? ''), 'gate' => $sh['gate'] ?? null,
        'status' => (string)($sh['status'] ?? ''),
    ];
    oj($res, $res['state'] === 'error' ? 409 : 200);
}

// --- Отправка паллеты на ворота сортировки: ворота отгрузки — источник истины ---
// GET ?action=gate_info&shipment_id=N -> ворота отгрузки + состояние буфера.
if ($action === 'gate_info' && $method === 'GET') {
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    if ($sid <= 0) {
        oj(['error' => 'нужен shipment_id'], 422);
    }
    $g = wms_pick_shipment_gates($pdo, $sid);
    $g['buffer'] = wms_pick_buffer_state($pdo, $sid);
    if (!$g['has_gates']) {
        $g['hint'] = 'В отгрузке ворота не указаны — введи ворота вручную';
    }
    oj($g);
}

// --- Перенос паллеты в зону сортировки (контроль из схемы) ---
if ($action === 'move_to_sort' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $pallet = trim((string)($b['pallet'] ?? ''));
    $gateCell = trim((string)($b['gate_cell'] ?? ''));
    $gate = trim((string)($b['gate'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    $okMove = (int)($b['moved_ok'] ?? 1); // 1 = перенесена; 0 = системная ошибка
    if ($sid <= 0 || $pallet === '') oj(['error' => 'нужны shipment_id и pallet'], 422);
    // Ворота отгрузки — источник истины: в отгрузке должны быть указаны ровно эти ворота.
    $gates = wms_pick_shipment_gates($pdo, $sid);
    if ($gate === '' && $gates['has_gates']) {
        $gate = (string)$gates['gates'][0];
    }
    if ($gates['has_gates'] && !wms_pick_gate_allowed($gates['gates'], $gate)) {
        oj(['error' => 'Ворота ' . $gate . ' не указаны в отгрузке ' . $gates['doc_no'],
            'gate' => $gate, 'gates' => $gates['gates'], 'doc_no' => $gates['doc_no']], 409);
    }
    if ($gate === '') {
        oj(['error' => 'Укажи ворота сортировки', 'gates' => $gates['gates']], 422);
    }
    if (!$okMove) {
        $pdo->prepare('INSERT INTO tsd_otbor_logs (shipment_id,cell,sku,user_id,reason) VALUES (:s,:c,:sku,:u,:r)')
            ->execute([':s' => $sid, ':c' => ($gateCell !== '' ? $gateCell : 'ЗОНА-СОРТИРОВКИ'), ':sku' => $pallet, ':u' => $uid, ':r' => 'Ошибка перемещения в зону сортировки']);
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value) VALUES (:s,:u,'sort_error',:f,:o,:n)")
            ->execute([':s' => $sid, ':u' => $uid, ':f' => 'Паллет ' . $pallet, ':o' => $gateCell, ':n' => 'Не перенесена. Информация передана оператору + уведомление в WMS']);
        $log = $pdo->query('SELECT id, shipment_id, cell, sku, user_id, reason, ' . o_dt_sql($pdo) . ' AS dt FROM tsd_otbor_logs ORDER BY id DESC LIMIT 1')->fetch();
        oj(['ok' => false, 'log' => $log, 'notify_wms' => true]);
    }
    $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'move_to_sort',:o,:n)")
        ->execute([':s' => $sid, ':u' => $uid, ':o' => 'Паллет ' . $pallet, ':n' => 'Перенесена на ворота сортировки ' . $gate . ' (' . $gateCell . ')']);
    // Буфер отбора: товар перенесён на ворота (picked -> at_gate), сборка по SSCC впереди.
    $moved = wms_pick_buffer_to_gate($pdo, $sid, $gate, $uid);
    // Журнал склада: перемещение из промежуточной ячейки пользователя на ворота.
    try {
        require_once __DIR__ . '/../shared/Journal.php';
        WmsJournal::log(wms_journal_pdo(), [
            'op_type' => 'перемещение',
            'doc_type' => 'Отгрузка',
            'doc_no' => $gates['doc_no'],
            'sku' => '',
            'qty' => 0,
            'pallet' => $pallet,
            'sscc' => $pallet,
            'cell_from' => wms_pick_buffer_cell($uid),
            'cell_to' => 'ВОРОТА-' . $gate,
            'zone' => 'Сортировка',
            'user_id' => $uid,
            'user_fio' => $fio,
            'comment' => 'Паллета отправлена на ворота сортировки ' . $gate
                . ' (ворота отгрузки — источник истины)',
        ]);
    } catch (Throwable) {
    }
    // Задание полностью в сортировке -> освобождаем закрепление
    $pdo->prepare('DELETE FROM tsd_otbor_locks WHERE shipment_id=:s')->execute([':s' => $sid]);
    oj(['ok' => true, 'gate' => $gate, 'gates' => $gates['gates'],
        'buffer_moved' => $moved, 'buffer' => wms_pick_buffer_state($pdo, $sid)]);
}

oj(['error' => 'unknown action: ?action=tasks|task|take|release|problem|confirm|pallet_check|gate_info|move_to_sort'], 404);
