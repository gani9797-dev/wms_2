<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
// Буфер отбора: сборка на сборочной паллете по SSCC (товар с ворот -> сборочная паллета).
require_once __DIR__ . '/../shared/PickBuffer.php';
// ТСД «Сортировка отгрузки» — алгоритм из схемы «Сортировка» (сортировка.drawio).
//
// Ветка кроссдока (shipments.is_crossdock = 1):
//   скан отгрузки -> скан паллеты -> паллета принадлежит отгрузке? ->
//   паллета в зоне сортировки? -> перенос паллеты в зону отгрузки.
// Ветка обычной сортировки:
//   скан отгрузки -> скан штрихкода товара -> скан паллеты ->
//   марка (ЧЗ) циклом ИЛИ ввод количества штук/коробов ->
//   серийные номера циклом -> перенос товара на паллету в зону сортировки.
//
// Пишет в ЕДИНУЮ БД отгрузок (03-otgruzki/wms_demo.sqlite через shared/db.php):
//   tsd_picks (факт сортировки: shipment_id, sku, qty, sscc), shipments.readiness,
//   shipment_history (action=sort), shipment_flags (факт серийников/марок),
//   shipment_marks (реестр марок ЧЗ: статус марки), shipment_sort_log (лог операций).
// Лог сортировки (shipment_sort_log) хранит только идентификаторы:
//   id паллеты, дата создания SSCC, id сотрудника, id товара, серийник, честный знак.
//
// Авторизация: общая сессия (вход через /login) или токен ТСД:
//   /tsd/sort.php?action=shipment&code=S-205107&token=tsd-12345
//   или заголовок X-Tsd-Token: tsd-12345

const SORT_ZONE = 'shipment'; // зона отгрузки в справочнике ячеек
const SORT_ZONES = ['storage', 'sorting', 'sort']; // зоны сортировки (хранение)

function sot_ok(): bool
{
    if (wms_tsd_token_ok()) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return isset($_SESSION['wms_auth_user']);
}

function sj(mixed $d, int $c = 200): void
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function sot_db(): PDO
{
    // Единая БД отгрузок (владелец — 03-otgruzki). См. shared/db.php.
    require_once __DIR__ . '/../shared/db.php';
    return wms_shipments_pdo();
}

/** БД ячеек хранения (модуль 05) — зоны, паллеты. */
function sot_storage(): ?PDO
{
    try {
        require_once __DIR__ . '/../shared/db.php';
        return wms_storage_pdo();
    } catch (Throwable) {
        return null;
    }
}

/** БД заданий (модуль 07) — прогресс по заданиям отгрузки. */
function sot_tasks(): ?PDO
{
    try {
        require_once __DIR__ . '/../shared/db.php';
        return wms_tasks_pdo();
    } catch (Throwable) {
        return null;
    }
}

/** БД номенклатуры (модуль 08) — штрихкоды, признаки ЧЗ/серийников, фото. */
function sot_nom(): ?PDO
{
    try {
        require_once __DIR__ . '/../shared/db.php';
        return wms_nomenclature_pdo();
    } catch (Throwable) {
        return null;
    }
}

/** Текущее время в формате БД. */
function sot_now(PDO $pdo): string
{
    try {
        return (string)$pdo->query("SELECT " . (wms_is_sqlite($pdo) ? "datetime('now')" : 'NOW()'))->fetchColumn();
    } catch (Throwable) {
        return date('Y-m-d H:i:s');
    }
}

/**
 * Шапка отгрузки для ТСД: код, клиент, % собрана, задания выполнено/всего,
 * номера доков, признак кроссдока.
 */
function sot_head(PDO $pdo, int $sid): array
{
    $h = $pdo->prepare('SELECT s.*, w.name AS warehouse FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.id=:id');
    $h->execute([':id' => $sid]);
    $head = $h->fetch();
    if (!$head) {
        return [];
    }
    $plan = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM shipment_items WHERE shipment_id=' . $sid)->fetchColumn();
    $fact = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=' . $sid)->fetchColumn();
    $pct = $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0);
    $head['plan_qty'] = $plan;
    $head['fact_qty'] = $fact;
    $head['pct'] = $pct;
    $head['is_crossdock'] = (int)($head['is_crossdock'] ?? 0);
    // Задания по отгрузке: выполненные / всего + номера доков.
    $tasksDone = 0;
    $tasksTotal = 0;
    $docNos = [];
    $tp = sot_tasks();
    if ($tp) {
        try {
            $cols = wms_table_columns($tp, 'tasks');
            $sql = in_array('shipment_ids', $cols, true)
                ? 'SELECT doc_no, status, shipment_ids FROM tasks'
                : "SELECT doc_no, status, doc_ref FROM tasks";
            foreach ($tp->query($sql)->fetchAll() as $r) {
                $ids = [];
                if (isset($r['shipment_ids'])) {
                    $ids = array_filter(array_map('trim', explode(',', (string)$r['shipment_ids'])));
                } elseif (isset($r['doc_ref'])) {
                    // запасной путь: отгрузка указана в doc_ref (SSCC/номер доков)
                    $ids = array_filter(array_map('trim', explode(',', (string)$r['doc_ref'])));
                }
                $hit = false;
                foreach ($ids as $x) {
                    if ($x === (string)$sid || $x === (string)($head['doc_no'] ?? '')) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    continue;
                }
                $tasksTotal++;
                if (in_array((string)($r['status'] ?? ''), ['done', 'completed', 'closed'], true)) {
                    $tasksDone++;
                }
                $docNos[] = (string)($r['doc_no'] ?? '');
            }
        } catch (Throwable) {
        }
    }
    $head['tasks_done'] = $tasksDone;
    $head['tasks_total'] = $tasksTotal;
    $head['doc_nos'] = array_values(array_filter(array_unique($docNos)));
    return $head;
}

/** Позиция товара отгрузки по штрихкоду/артикулу. null — товар не найден. */
function sot_item_by_code(PDO $pdo, int $sid, string $code): ?array
{
    $st = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id=:s AND (sku=:c OR code=:c) LIMIT 1');
    $st->execute([':s' => $sid, ':c' => $code]);
    $item = $st->fetch();
    if ($item) {
        return $item;
    }
    // штрихкод из номенклатуры -> sku
    $nom = sot_nom();
    if ($nom) {
        try {
            $b = $nom->prepare('SELECT p.sku FROM product_barcodes b JOIN products p ON p.id=b.product_id WHERE b.barcode=:c LIMIT 1');
            $b->execute([':c' => $code]);
            $sku = $b->fetchColumn();
            if ($sku !== false && $sku !== null && $sku !== '') {
                $st = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id=:s AND sku=:sku LIMIT 1');
                $st->execute([':s' => $sid, ':sku' => $sku]);
                $item = $st->fetch();
                if ($item) {
                    return $item;
                }
            }
        } catch (Throwable) {
        }
    }
    $like = $pdo->prepare('SELECT * FROM shipment_items WHERE shipment_id=:s AND (sku LIKE :l OR code LIKE :l OR name LIKE :l) LIMIT 1');
    $like->execute([':s' => $sid, ':l' => '%' . $code . '%']);
    $item = $like->fetch();
    return $item ?: null;
}

/** Признаки товара из номенклатуры (ЧЗ, серийники) + фото. */
function sot_product_flags(string $sku): array
{
    $out = ['is_chz' => 0, 'is_serial' => 0, 'image' => null, 'name' => null,
        'serial_out' => 0, 'serial_out_src' => 'none', 'contractor_id' => null];
    $nom = sot_nom();
    if (!$nom) {
        return $out;
    }
    try {
        $st = $nom->prepare('SELECT is_chz, is_serial, image_path, name, scan_serial_out, scan_serial_out_override, contractor_id FROM products WHERE sku=:s LIMIT 1');
        $st->execute([':s' => $sku]);
        $r = $st->fetch();
        if ($r) {
            $out['is_chz'] = (int)($r['is_chz'] ?? 0);
            $out['is_serial'] = (int)($r['is_serial'] ?? 0);
            $out['image'] = $r['image_path'] ?? null;
            $out['name'] = $r['name'] ?? null;
            $cid = (isset($r['contractor_id']) && $r['contractor_id'] !== '' && $r['contractor_id'] !== null)
                ? (int)$r['contractor_id'] : null;
            $out['contractor_id'] = $cid;
            // Эффективный флаг сканирования с/н на выходе:
            // override артикула (NULL -> наследование глобального флага клиента).
            $ov = $r['scan_serial_out_override'] ?? null;
            if ($ov === null || $ov === '') {
                $out['serial_out'] = 0;
                $out['serial_out_src'] = 'client';
                if ($cid) {
                    try {
                        require_once __DIR__ . '/../shared/Counterparties.php';
                        $out['serial_out'] = (int)WmsCounterparties::serialFlags($nom, $cid)['out'];
                    } catch (Throwable) {
                    }
                }
            } else {
                $out['serial_out'] = (int)$ov ? 1 : 0;
                $out['serial_out_src'] = 'article';
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Серийные номера, ПРИНЯТЫЕ НА ВХОДЕ по артикулу (receipt_flags флаг_type='serial'),
 * за вычетом уже отгруженных (shipment_flags).
 *
 * Это база сверки на сортировке: отсканированный при отборе с/н должен совпадать
 * с одним из принятых на входе по той же позиции.
 * @return string[]
 */
function sot_accepted_serials(PDO $pdo, string $sku): array
{
    $sku = trim($sku);
    if ($sku === '') {
        return [];
    }
    $set = [];
    try {
        require_once __DIR__ . '/../shared/db.php';
        $rc = wms_receipts_pdo();
        $st = $rc->prepare(
            "SELECT serial_no FROM receipt_flags
              WHERE flag_type='serial' AND sku=:s AND serial_no IS NOT NULL ORDER BY id"
        );
        $st->execute([':s' => $sku]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $set[$v] = true;
            }
        }
    } catch (Throwable) {
    }
    // Уже отгруженные с/н повторно отгружать нельзя — исключаем их.
    try {
        $st = $pdo->prepare(
            "SELECT serial_no FROM shipment_flags
              WHERE flag_type='serial' AND serial_no IS NOT NULL"
        );
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                unset($set[$v]);
            }
        }
    } catch (Throwable) {
    }
    return array_keys($set);
}

/** Дата создания SSCC: из приёмки (tsd_pallets) или из ячеек хранения. */
function sot_pallet_created(string $sscc): ?string
{
    if ($sscc === '') {
        return null;
    }
    try {
        require_once __DIR__ . '/../shared/db.php';
        $rc = wms_receipts_pdo();
        $st = $rc->prepare('SELECT created_at FROM tsd_pallets WHERE sscc=:c ORDER BY id DESC LIMIT 1');
        $st->execute([':c' => $sscc]);
        $d = $st->fetchColumn();
        if ($d !== false && $d !== null && $d !== '') {
            return (string)$d;
        }
    } catch (Throwable) {
    }
    $sp = sot_storage();
    if ($sp) {
        try {
            $st = $sp->prepare('SELECT updated_at FROM cell_content WHERE pallet=:p ORDER BY id DESC LIMIT 1');
            $st->execute([':p' => $sscc]);
            $d = $st->fetchColumn();
            if ($d !== false && $d !== null && $d !== '') {
                return (string)$d;
            }
        } catch (Throwable) {
        }
    }
    return null;
}

/** Ячейка паллеты: код, зона. */
function sot_pallet_cell(string $sscc): ?array
{
    $sp = sot_storage();
    if (!$sp) {
        return null;
    }
    try {
        $st = $sp->prepare(
            'SELECT c.id, c.code, c.zone, c.warehouse_id FROM cell_content cc
             JOIN storage_cells c ON c.id=cc.cell_id WHERE cc.pallet=:p ORDER BY cc.id DESC LIMIT 1'
        );
        $st->execute([':p' => $sscc]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** Отгрузки и клиенты, связанные с паллетой. */
function sot_pallet_shipments(PDO $pdo, string $sscc): array
{
    $st = $pdo->prepare(
        'SELECT s.id, s.doc_no, s.consignee FROM tsd_picks p JOIN shipments s ON s.id=p.shipment_id
         WHERE p.sscc=:c GROUP BY s.id ORDER BY s.id'
    );
    $st->execute([':c' => $sscc]);
    return $st->fetchAll();
}

if (!sot_ok()) {
    sj(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
}

$pdo = sot_db();
$action = $_GET['action'] ?? 'shipment';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Поиск отгрузки по коду (кнопка «Сортировка» -> форма сканирования отгрузки) ---
if ($action === 'shipment' && $method === 'GET') {
    $code = trim((string)($_GET['code'] ?? $_GET['doc_no'] ?? ''));
    if ($code === '') {
        sj(['error' => 'Отсканируй код отгрузки'], 422);
    }
    $st = $pdo->prepare('SELECT id FROM shipments WHERE doc_no=:c LIMIT 1');
    $st->execute([':c' => $code]);
    $id = (int)$st->fetchColumn();
    if ($id <= 0) {
        $st = $pdo->prepare('SELECT id FROM shipments WHERE id=:c LIMIT 1');
        $st->execute([':c' => (int)$code]);
        $id = (int)$st->fetchColumn();
    }
    if ($id <= 0) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    $head = sot_head($pdo, $id);
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    sj(['head' => $head]);
}

// --- Шапка отгрузки (обновление прогресса) ---
if ($action === 'head' && $method === 'GET') {
    $head = sot_head($pdo, (int)($_GET['id'] ?? 0));
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    sj(['head' => $head]);
}

// --- Скан штрихкода товара: принадлежит ли товар отгрузке ---
if ($action === 'product' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? 0);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($sid <= 0 || $code === '') {
        sj(['error' => 'нужны id отгрузки и код товара'], 422);
    }
    $head = sot_head($pdo, $sid);
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    $item = sot_item_by_code($pdo, $sid, $code);
    if (!$item) {
        sj(['error' => 'Товар не принадлежит отгрузке: ' . $code], 404);
    }
    $plan = (float)$item['qty'];
    $st = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=:s AND sku=:sku');
    $st->execute([':s' => $sid, ':sku' => $item['sku']]);
    $fact = (float)$st->fetchColumn();
    $need = max(0.0, $plan - $fact);
    $flags = sot_product_flags((string)$item['sku']);
    // Признаки сканирования: по товару (номенклатура) или по документу.
    $marking = ((int)$flags['is_chz'] > 0) || ((int)($head['marking_scan'] ?? 0) > 0);
    $effOut = (int)($flags['serial_out'] ?? 0);
    // Серийники, принятые на входе по этой позиции — база сверки на сортировке.
    $accepted = sot_accepted_serials($pdo, (string)$item['sku']);
    // Цикл сканирования с/н нужен, если: товар серийный / документ требует /
    // по артикулу включён флаг сканирования на выходе / на входе уже сканировали с/н.
    $serial = ((int)$flags['is_serial'] > 0) || ((int)($head['serial_scan'] ?? 0) > 0)
        || $effOut === 1 || count($accepted) > 0;
    // Марки по товару в реестре документа: всего / отсканировано.
    $marksTotal = 0;
    $marksDone = 0;
    try {
        $m = $pdo->prepare('SELECT COUNT(*) FROM shipment_marks WHERE shipment_id=:s AND sku=:sku');
        $m->execute([':s' => $sid, ':sku' => $item['sku']]);
        $marksTotal = (int)$m->fetchColumn();
        $m = $pdo->prepare(
            'SELECT COUNT(*) FROM shipment_sort_log WHERE shipment_id=:s AND item_id=:i AND marking_code IS NOT NULL'
        );
        $m->execute([':s' => $sid, ':i' => (int)$item['id']]);
        $marksDone = (int)$m->fetchColumn();
    } catch (Throwable) {
    }
    sj(['item' => [
        'id' => (int)$item['id'],
        'sku' => (string)$item['sku'],
        'code' => (string)($item['code'] ?? ''),
        'name' => (string)$item['name'],
        'unit' => (string)($item['unit'] ?? 'шт'),
        'photo' => $flags['image'],
        'plan_qty' => $plan,
        'fact_qty' => $fact,
        'need_qty' => $need,
        'prod_date' => $item['prod_date'] ?? null,
        'marking_scan' => $marking,
        'serial_scan' => $serial,
        'serial_out' => $effOut,
        'accepted_serials' => count($accepted),
        'accepted_serials_list' => array_slice($accepted, 0, 500),
        'marks_total' => $marksTotal,
        'marks_done' => $marksDone,
    ], 'head' => $head]);
}

// --- Скан паллеты: чужая отгрузка / чужой клиент ---
if ($action === 'pallet' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? 0);
    $code = trim((string)($_GET['code'] ?? ''));
    if ($sid <= 0 || $code === '') {
        sj(['error' => 'нужны id отгрузки и SSCC паллеты'], 422);
    }
    $head = sot_head($pdo, $sid);
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    $ships = sot_pallet_shipments($pdo, $code);
    $own = 0;
    foreach ($ships as $s) {
        if ((int)$s['id'] === $sid) {
            $own++;
        }
    }
    if ($own === 0) {
        sj(['error' => 'Паллета не принадлежит отгрузке'], 409);
    }
    // На паллете другая отгрузка / другой клиент?
    // Клиент проверяем первым: если у чужой отгрузки другой грузополучатель —
    // сообщаем клиента (как в схеме), иначе — номер чужой отгрузки.
    $mine = trim((string)($head['consignee'] ?? ''));
    foreach ($ships as $s) {
        if ((int)$s['id'] === $sid) {
            continue;
        }
        $c = trim((string)($s['consignee'] ?? ''));
        if ($c !== '' && $mine !== '' && strcasecmp($c, $mine) !== 0) {
            sj(['error' => 'На паллете клиент: ' . $c, 'other_client' => $c], 409);
        }
    }
    foreach ($ships as $s) {
        if ((int)$s['id'] === $sid) {
            continue;
        }
        sj(['error' => 'На паллете другая отгрузка: ' . (string)$s['doc_no'], 'other_id' => (int)$s['id'], 'other_doc' => (string)$s['doc_no']], 409);
    }
    $cell = sot_pallet_cell($code);
    sj(['pallet' => [
        'sscc' => $code,
        'created_at' => sot_pallet_created($code),
        'cell' => $cell['code'] ?? null,
        'zone' => $cell['zone'] ?? null,
        'in_sort_zone' => $cell ? in_array((string)$cell['zone'], SORT_ZONES, true) : null,
    ], 'head' => $head]);
}

// --- Скан марки (честный знак): статус марки ---
if ($action === 'mark' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? 0);
    $code = trim((string)($_GET['code'] ?? ''));
    $sku = trim((string)($_GET['sku'] ?? ''));
    if ($sid <= 0 || $code === '') {
        sj(['error' => 'нужны id отгрузки и код марки'], 422);
    }
    $st = $pdo->prepare('SELECT * FROM shipment_marks WHERE shipment_id=:s AND marking_code=:m LIMIT 1');
    $st->execute([':s' => $sid, ':m' => $code]);
    $mark = $st->fetch();
    if (!$mark) {
        sj(['error' => 'Марка не найдена в реестре документа', 'reason' => 'Марка не принята'], 404);
    }
    if ($sku !== '' && (string)$mark['sku'] !== $sku) {
        sj(['error' => 'Марка относится к другому товару: ' . (string)$mark['sku'], 'reason' => 'Марка не принята'], 422);
    }
    $status = (string)$mark['status'];
    $accepted = $status === 'accepted' || $status === 'circulated';
    $circulated = $status === 'circulated';
    sj(['mark' => [
        'code' => $code,
        'sku' => (string)$mark['sku'],
        'status' => $status,
        'accepted' => $accepted,
        'circulated' => $circulated,
        'reason' => $accepted ? null : 'Марка не принята',
    ]]);
}

// --- Подбор марки в статусе «принята» (когда отсканированная не подошла) ---
// Правило из схемы: если у товара нет даты производства — подходит любая марка
// в статусе «принята»; иначе нужна марка с подходящей датой производства.
if ($action === 'mark_any' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? 0);
    $sku = trim((string)($_GET['sku'] ?? ''));
    $prodDate = trim((string)($_GET['prod_date'] ?? ''));
    $sql = "SELECT * FROM shipment_marks WHERE shipment_id=:s AND sku=:sku AND status IN ('accepted','circulated')";
    $params = [':s' => $sid, ':sku' => $sku];
    if ($prodDate !== '') {
        $sql .= " AND prod_date IS NOT NULL AND prod_date=:pd";
        $params[':pd'] = substr($prodDate, 0, 10);
    }
    $sql .= ' ORDER BY id LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $mark = $st->fetch();
    if (!$mark) {
        sj(['error' => $prodDate !== ''
            ? 'Нет марки с датой производства ' . substr($prodDate, 0, 10) . ' — требуется печать марки'
            : 'Нет марок в статусе «принята» по товару', 'reason' => 'Марка не принята'], 404);
    }
    sj(['mark' => [
        'code' => (string)$mark['marking_code'],
        'sku' => (string)$mark['sku'],
        'status' => (string)$mark['status'],
        'circulated' => (string)$mark['status'] === 'circulated',
        'by_prod_date' => $prodDate !== '',
    ]]);
}

// --- Скан серийного номера: проверка дубля + сверка с принятыми на входе ---
if ($action === 'serial' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? 0);
    $code = trim((string)($_GET['code'] ?? ''));
    $sku = trim((string)($_GET['sku'] ?? ''));
    if ($sid <= 0 || $code === '') {
        sj(['error' => 'нужны id отгрузки и серийный номер'], 422);
    }
    $st = $pdo->prepare('SELECT id FROM shipment_sort_log WHERE shipment_id=:s AND serial_no=:c LIMIT 1');
    $st->execute([':s' => $sid, ':c' => $code]);
    if ($st->fetch()) {
        sj(['error' => 'Серийник уже отсканирован: ' . $code], 409);
    }
    // Сверка по всему списку принятых на входе с/н этой позиции.
    $accepted = $sku !== '' ? sot_accepted_serials($pdo, $sku) : [];
    if ($accepted && !in_array($code, $accepted, true)) {
        sj(['error' => 'Серийный номер ' . $code . ' не найден среди принятых по позиции ' . $sku,
            'reason' => 'Серийный номер не принят на входе'], 404);
    }
    sj(['serial' => ['code' => $code, 'accepted' => count($accepted)]]);
}

// --- Подтверждение строки сортировки ---
if ($action === 'confirm' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $itemId = (int)($b['item_id'] ?? 0);
    $qty = (float)($b['qty'] ?? 0);
    $pallet = trim((string)($b['pallet_id'] ?? $b['pallet'] ?? ''));
    $serials = array_values(array_filter(array_map('trim', (array)($b['serials'] ?? []))));
    $markings = array_values(array_filter(array_map('trim', (array)($b['markings'] ?? []))));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($sid <= 0 || $itemId <= 0) {
        sj(['error' => 'нужны shipment_id и item_id'], 422);
    }
    $head = sot_head($pdo, $sid);
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    if (!in_array((string)($head['status'] ?? ''), ['created', 'assembling', 'assembled', 'packing', 'on_check'], true)) {
        sj(['error' => 'Документ в статусе ' . (string)$head['status'] . ' — сортировка запрещена'], 409);
    }
    $st = $pdo->prepare('SELECT * FROM shipment_items WHERE id=:i AND shipment_id=:s');
    $st->execute([':i' => $itemId, ':s' => $sid]);
    $item = $st->fetch();
    if (!$item) {
        sj(['error' => 'Товар не принадлежит отгрузке'], 404);
    }
    $sku = (string)$item['sku'];
    $plan = (float)$item['qty'];
    $st = $pdo->prepare('SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=:s AND sku=:sku');
    $st->execute([':s' => $sid, ':sku' => $sku]);
    $fact = (float)$st->fetchColumn();
    $need = max(0.0, $plan - $fact);
    if ($qty <= 0) {
        $qty = $need > 0 ? $need : 1.0;
    }
    if ($qty - $need > 1e-9) {
        sj(['error' => 'Указано большее количество: к отбору ' . rtrim(rtrim(number_format($need, 3, '.', ''), '0'), '.') . ' шт', 'need' => $need], 409);
    }
    // Серийники: дубль по отгрузке + сверка с принятыми на входе.
    // Сверка идёт по ВСЕМУ списку принятых с/н позиции: если с/н на входе
    // не сканировали — сверять нечего (признак accepted пустой).
    $acceptedSerials = sot_accepted_serials($pdo, $sku);
    foreach ($serials as $sn) {
        $d = $pdo->prepare('SELECT id FROM shipment_sort_log WHERE shipment_id=:s AND serial_no=:c LIMIT 1');
        $d->execute([':s' => $sid, ':c' => $sn]);
        if ($d->fetch()) {
            sj(['error' => 'Серийник уже отсканирован: ' . $sn], 409);
        }
        if ($acceptedSerials && !in_array($sn, $acceptedSerials, true)) {
            sj(['error' => 'Серийный номер ' . $sn . ' не найден среди принятых по позиции ' . $sku,
                'reason' => 'Серийный номер не принят на входе'], 404);
        }
    }
    // Количество с/н должно совпадать с количеством штук (циклическое сканирование).
    // Для дробного количества штук с/н не сканируются — проверку не делаем.
    if ($serials && abs($qty - round($qty)) < 1e-9) {
        $needSerials = (int)round($qty);
        if (count($serials) !== $needSerials) {
            sj(['error' => 'Отсканировано ' . count($serials) . ' из ' . $needSerials . ' серийных номеров',
                'scanned' => count($serials), 'need' => $needSerials], 422);
        }
    }
    // По позиции на входе сканировали с/н — отгрузить её без сверки нельзя.
    if ($acceptedSerials && !$serials) {
        sj(['error' => 'По позиции ' . $sku . ' на входе приняты серийные номера — отсканируй их при отборе',
            'need_serials' => true, 'accepted' => count($acceptedSerials)], 422);
    }
    // Марки: должны числиться в реестре документа и относиться к этому товару.
    // Статус 'expected' означает «загружена из файла, ещё не отсканирована»:
    // текущий скан и есть её приёмка — переводим в 'accepted'.
    $acceptMarks = [];
    foreach ($markings as $mk) {
        $m = $pdo->prepare('SELECT * FROM shipment_marks WHERE shipment_id=:s AND marking_code=:m LIMIT 1');
        $m->execute([':s' => $sid, ':m' => $mk]);
        $mark = $m->fetch();
        if (!$mark) {
            // Может числиться за другой отгрузкой — это ошибка данных, а не «нет марки».
            $o = $pdo->prepare('SELECT shipment_id FROM shipment_marks WHERE marking_code=:m LIMIT 1');
            $o->execute([':m' => $mk]);
            $other = $o->fetchColumn();
            if ($other !== false && $other !== null) {
                sj(['error' => 'Марка принадлежит другой отгрузке: ' . $mk,
                    'reason' => 'Марка не принята', 'other_shipment_id' => (int)$other], 409);
            }
            sj(['error' => 'Марка не найдена в реестре документа: ' . $mk], 404);
        }
        if ((string)$mark['sku'] !== $sku) {
            sj(['error' => 'Марка относится к другому товару: ' . (string)$mark['sku'],
                'reason' => 'Марка не принята'], 422);
        }
        $mstat = (string)$mark['status'];
        if (in_array($mstat, ['accepted', 'circulated'], true)) {
            // Повторный скан той же марки в этой отгрузке — двойной учёт единицы.
            $dup = $pdo->prepare('SELECT id FROM shipment_sort_log WHERE shipment_id=:s AND marking_code=:m LIMIT 1');
            $dup->execute([':s' => $sid, ':m' => $mk]);
            if ($dup->fetch()) {
                sj(['error' => 'Марка уже отсканирована: ' . $mk, 'reason' => 'Марка не принята'], 409);
            }
        }
        $acceptMarks[] = $mk;
    }
    $palletCreated = sot_pallet_created($pallet);
    $now = sot_now($pdo);
    if ($palletCreated === null || $palletCreated === '') {
        // Паллеты нет в справочниках — дата создания SSCC = дата операции сортировки.
        $palletCreated = $now;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO tsd_picks (shipment_id,sku,qty,user_id,sscc) VALUES (:s,:sku,:q,:u,:sscc)')
            ->execute([':s' => $sid, ':sku' => $sku, ':q' => $qty, ':u' => $uid, ':sscc' => ($pallet !== '' ? $pallet : null)]);
        // Лог сортировки: по одной строке на серийник и на марку.
        // Если нет ни серийников, ни марок — одна строка на позицию.
        $ins = $pdo->prepare(
            'INSERT INTO shipment_sort_log (shipment_id,pallet_id,pallet_created_at,user_id,item_id,serial_no,marking_code,qty,operation,reason,created_at)
             VALUES (:s,:pal,:palc,:u,:i,:sn,:mk,:q,:op,:rs,:dt)'
        );
        if (!$serials && !$markings) {
            $ins->execute([':s' => $sid, ':pal' => ($pallet !== '' ? $pallet : null), ':palc' => $palletCreated,
                ':u' => $uid, ':i' => $itemId, ':sn' => null, ':mk' => null, ':q' => $qty, ':op' => 'sort', ':rs' => null, ':dt' => $now]);
        }
        foreach ($serials as $sn) {
            $ins->execute([':s' => $sid, ':pal' => ($pallet !== '' ? $pallet : null), ':palc' => $palletCreated,
                ':u' => $uid, ':i' => $itemId, ':sn' => $sn, ':mk' => null, ':q' => 1, ':op' => 'serial', ':rs' => null, ':dt' => $now]);
        }
        foreach ($markings as $mk) {
            $ins->execute([':s' => $sid, ':pal' => ($pallet !== '' ? $pallet : null), ':palc' => $palletCreated,
                ':u' => $uid, ':i' => $itemId, ':sn' => null, ':mk' => $mk, ':q' => 1, ':op' => 'mark', ':rs' => null, ':dt' => $now]);
        }
        // Факт марок/серийников по документу (вкладка Флаги).
        // Колонка sku есть не в каждой старой БД — на этот случай запасной INSERT.
        $hasSkuCol = true;
        try {
            $hasSkuCol = in_array('sku', wms_table_columns($pdo, 'shipment_flags'), true);
        } catch (Throwable) {
            $hasSkuCol = false;
        }
        $fl = $pdo->prepare(
            $hasSkuCol
                ? "INSERT INTO shipment_flags (shipment_id,order_id,item_id,flag_type,serial_no,marking_code,pallet,sku,created_at)
                   VALUES (:s,:o,:i,:t,:sn,:mk,:pal,:sku,:dt)"
                : "INSERT INTO shipment_flags (shipment_id,order_id,item_id,flag_type,serial_no,marking_code,pallet,created_at)
                   VALUES (:s,:o,:i,:t,:sn,:mk,:pal,:dt)"
        );
        foreach ($serials as $sn) {
            $pr = [':s' => $sid, ':o' => $item['order_id'] ?? null, ':i' => $itemId, ':t' => 'serial', ':sn' => $sn, ':mk' => null,
                ':pal' => ($pallet !== '' ? $pallet : null), ':dt' => $now];
            if ($hasSkuCol) {
                $pr[':sku'] = $sku;
            }
            $fl->execute($pr);
        }
        foreach ($markings as $mk) {
            $pr = [':s' => $sid, ':o' => $item['order_id'] ?? null, ':i' => $itemId, ':t' => 'marking', ':sn' => null, ':mk' => $mk,
                ':pal' => ($pallet !== '' ? $pallet : null), ':dt' => $now];
            if ($hasSkuCol) {
                $pr[':sku'] = $sku;
            }
            $fl->execute($pr);
        }
        // Журнал серийных номеров: операция «Отгрузка» (код отгрузки + паллета).
        if ($serials) {
            try {
                require_once __DIR__ . '/../shared/Journal.php';
                $docNo = (string)($head['doc_no'] ?? '');
                foreach ($serials as $sn) {
                    WmsJournal::logSerial($pdo, [
                        'serial_no' => $sn,
                        'sku' => $sku,
                        'product_name' => (string)($item['name'] ?? ''),
                        'op_type' => 'Отгрузка',
                        'shipment_doc_no' => $docNo,
                        'pallet' => ($pallet !== '' ? $pallet : null),
                        'qty' => 1,
                        'user_id' => $uid,
                    ]);
                }
            } catch (Throwable) {
            }
        }
        // Марка принята: 'expected' -> 'accepted' с отметкой времени.
        // Раньше статус никто не менял, и проверка «марка принята» всегда отвергала.
        if ($acceptMarks) {
            $updMark = $pdo->prepare(
                "UPDATE shipment_marks SET status='accepted', accepted_at=:t WHERE shipment_id=:s AND marking_code=:m"
            );
            foreach ($acceptMarks as $mk) {
                $updMark->execute([':t' => $now, ':s' => $sid, ':m' => $mk]);
            }
        }
        // Прогресс отгрузки считаем НА ТЕКУЩЕМ подключении: внутри транзакции
        // нельзя открывать новые подключения (DDL-гаранты блокируются транзакцией).
        $planQ = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM shipment_items WHERE shipment_id=' . $sid)->fetchColumn();
        $factQ = (float)$pdo->query('SELECT COALESCE(SUM(qty),0) FROM tsd_picks WHERE shipment_id=' . $sid)->fetchColumn();
        $pct = $planQ > 0 ? (int)min(100, round($factQ / $planQ * 100)) : ($factQ > 0 ? 100 : 0);
        $pdo->prepare('UPDATE shipments SET readiness=:p WHERE id=:id')->execute([':p' => $pct, ':id' => $sid]);
        $pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value)
             VALUES (:s,:u,'sort',:f,:o,:n)"
        )->execute([':s' => $sid, ':u' => $uid, ':f' => $sku . ' · ' . $item['name'],
            ':o' => 'паллет ' . ($pallet !== '' ? $pallet : '—'),
            ':n' => 'Отсортировано ' . rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') . ' шт'
                . ($serials ? (' · с/н ' . count($serials)) : '') . ($markings ? (' · марок ' . count($markings)) : '')]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        sj(['error' => $e->getMessage()], 500);
    }
    sj(['ok' => true, 'progress' => sot_head($pdo, $sid)]);
}

// --- Кроссдок: перенос паллеты в зону отгрузки ---
if ($action === 'crossdock' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $sscc = trim((string)($b['pallet_id'] ?? $b['pallet'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($sid <= 0 || $sscc === '') {
        sj(['error' => 'нужны shipment_id и SSCC паллеты'], 422);
    }
    $head = sot_head($pdo, $sid);
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    $ships = sot_pallet_shipments($pdo, $sscc);
    $own = false;
    foreach ($ships as $s) {
        if ((int)$s['id'] === $sid) {
            $own = true;
        }
    }
    if (!$own) {
        sj(['error' => 'Паллета не принадлежит отгрузке'], 409);
    }
    $cell = sot_pallet_cell($sscc);
    if (!$cell) {
        sj(['error' => 'Паллета не найдена в ячейках хранения'], 404);
    }
    if (!in_array((string)$cell['zone'], SORT_ZONES, true)) {
        sj(['error' => 'Паллета не отобрана: зона ' . (string)$cell['zone']], 409);
    }
    $palletCreated = sot_pallet_created($sscc);
    $now = sot_now($pdo);
    if ($palletCreated === null || $palletCreated === '') {
        $palletCreated = $now;
    }
    // Перенос ячейки в зону отгрузки (модуль 05 — ячейки хранения).
    $sp = sot_storage();
    $moved = false;
    if ($sp) {
        try {
            $sp->beginTransaction();
            $sp->prepare("UPDATE storage_cells SET zone=:z, updated_at=:dt WHERE id=:id")
                ->execute([':z' => SORT_ZONE, ':dt' => $now, ':id' => (int)$cell['id']]);
            $sp->prepare(
                "INSERT INTO cell_history (cell_id,user_id,user_fio,action,field,field_label,old_value,new_value,created_at)
                 VALUES (:c,:u,:f,'move','zone','Зона',:o,:n,:dt)"
            )->execute([':c' => (int)$cell['id'], ':u' => $uid, ':f' => trim((string)($b['user_fio'] ?? '')),
                ':o' => (string)$cell['zone'], ':n' => SORT_ZONE, ':dt' => $now]);
            try {
                $sp->prepare(
                    "INSERT INTO cell_movements (cell_id,doc_date,doc_type,doc_no,sku,qty_change,balance,cell_from,cell_to)
                     VALUES (:c,:dt,'отгрузка',:dn,:sku,0,0,:cf,:ct)"
                )->execute([':c' => (int)$cell['id'], ':dt' => $now, ':dn' => (string)($head['doc_no'] ?? ''),
                    ':sku' => '', ':cf' => (string)$cell['code'], ':ct' => (string)$cell['code']]);
            } catch (Throwable) {
            }
            $sp->commit();
            $moved = true;
        } catch (Throwable $e) {
            try {
                $sp->rollBack();
            } catch (Throwable) {
            }
            sj(['error' => $e->getMessage()], 500);
        }
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO shipment_sort_log (shipment_id,pallet_id,pallet_created_at,user_id,item_id,qty,operation,reason,created_at)
             VALUES (:s,:pal,:palc,:u,NULL,1,:op,:rs,:dt)'
        )->execute([':s' => $sid, ':pal' => $sscc, ':palc' => $palletCreated, ':u' => $uid, ':op' => 'crossdock',
            ':rs' => 'Перенос в зону отгрузки из ' . (string)$cell['code'], ':dt' => $now]);
        $pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value)
             VALUES (:s,:u,'crossdock',:f,:o,:n)"
        )->execute([':s' => $sid, ':u' => $uid, ':f' => 'Паллет ' . $sscc,
            ':o' => 'Зона ' . (string)$cell['zone'], ':n' => 'Перенесена в зону отгрузки (' . (string)$cell['code'] . ')']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        sj(['error' => $e->getMessage()], 500);
    }
    sj(['ok' => true, 'moved' => $moved, 'cell' => (string)$cell['code'], 'zone' => SORT_ZONE]);
}

// --- Лог проблемной марки (из схемы: Марка, id отгрузки, причина, операция) ---
if ($action === 'mark_log' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $code = trim((string)($b['marking_code'] ?? ''));
    $reason = trim((string)($b['reason'] ?? 'Марка не принята'));
    $sku = trim((string)($b['sku'] ?? ''));
    $pallet = trim((string)($b['pallet_id'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    if ($sid <= 0 || $code === '') {
        sj(['error' => 'нужны shipment_id и код марки'], 422);
    }
    $itemId = null;
    if ($sku !== '') {
        $st = $pdo->prepare('SELECT id FROM shipment_items WHERE shipment_id=:s AND sku=:sku LIMIT 1');
        $st->execute([':s' => $sid, ':sku' => $sku]);
        $v = $st->fetchColumn();
        $itemId = $v !== false ? (int)$v : null;
    }
    $palletCreated = sot_pallet_created($pallet);
    $now = sot_now($pdo);
    if ($palletCreated === null || $palletCreated === '') {
        $palletCreated = $now;
    }
    $pdo->prepare(
        'INSERT INTO shipment_sort_log (shipment_id,pallet_id,pallet_created_at,user_id,item_id,serial_no,marking_code,qty,operation,reason,created_at)
         VALUES (:s,:pal,:palc,:u,:i,NULL,:mk,1,:op,:rs,:dt)'
    )->execute([':s' => $sid, ':pal' => ($pallet !== '' ? $pallet : null), ':palc' => $palletCreated,
        ':u' => $uid, ':i' => $itemId, ':mk' => $code, ':op' => 'mark', ':rs' => $reason, ':dt' => $now]);
    sj(['ok' => true]);
}

// --- Состояние сборки: что лежит на воротах и что уже собрано ---
if ($action === 'assembly_state' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? $_GET['shipment_id'] ?? 0);
    if ($sid <= 0) {
        sj(['error' => 'нужен shipment_id'], 422);
    }
    $state = wms_pick_buffer_state($pdo, $sid);
    $g = wms_pick_shipment_gates($pdo, $sid);
    $err = null;
    if ($state['picked'] > 0) {
        $err = 'Товар не перемещён на ворота: ' . $state['picked'] . ' позиций';
    } elseif ($state['at_gate'] <= 0) {
        $err = 'На воротах нет товара для сборки';
    }
    sj(['state' => $state, 'gates' => $g['gates'], 'doc_no' => $g['doc_no'],
        'can_assemble' => ($err === null), 'error' => $err]);
}

// --- Сборка на сборочной паллете: сканируем SSCC сборки ---
// POST ?action=assembly {shipment_id, sscc, user_id?, user_fio?}
if ($action === 'assembly' && $method === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid = (int)($b['shipment_id'] ?? 0);
    $sscc = trim((string)($b['sscc'] ?? $b['assembly_sscc'] ?? ''));
    $uid = (int)($b['user_id'] ?? 0) ?: null;
    $fio = trim((string)($b['user_fio'] ?? ''));
    if ($sid <= 0) {
        sj(['error' => 'нужен shipment_id'], 422);
    }
    $head = sot_head($pdo, $sid);
    if (!$head) {
        sj(['error' => 'Отгрузка не найдена'], 404);
    }
    $res = wms_pick_buffer_assemble($pdo, $sid, $sscc);
    if (!$res['ok']) {
        sj(['error' => $res['error'], 'state' => $res['state']], $res['code']);
    }
    // Журнал склада: сборка на сборочной паллете.
    try {
        require_once __DIR__ . '/../shared/Journal.php';
        WmsJournal::log(wms_journal_pdo(), [
            'op_type' => 'отбор',
            'doc_type' => 'Отгрузка',
            'doc_no' => (string)($head['doc_no'] ?? ''),
            'qty' => 0,
            'pallet' => $sscc,
            'sscc' => $sscc,
            'cell_from' => 'ВОРОТА-' . (string)($head['gate'] ?? ''),
            'cell_to' => 'СБОРОЧНАЯ-ПАЛЛЕТА',
            'zone' => 'Сборка',
            'user_id' => $uid,
            'user_fio' => $fio,
            'comment' => 'Сборка на сборочной паллете SSCC ' . $sscc,
        ]);
    } catch (Throwable) {
    }
    // История отгрузки.
    try {
        $pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value)
             VALUES (:s,:u,'assembly',:f,:o,:n)"
        )->execute([
            ':s' => $sid, ':u' => $uid, ':f' => 'Сборочная паллета',
            ':o' => 'на воротах', ':n' => 'Собрано на сборочной паллете SSCC ' . $sscc,
        ]);
    } catch (Throwable) {
    }
    sj(['ok' => true, 'assembly_sscc' => $sscc, 'updated' => $res['updated'], 'state' => $res['state']]);
}

// --- Лог сортировки по отгрузке ---
if ($action === 'log' && $method === 'GET') {
    $sid = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare(
        'SELECT l.*, u.fio AS user_fio, i.sku AS item_sku, i.name AS item_name
         FROM shipment_sort_log l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN shipment_items i ON i.id = l.item_id
         WHERE l.shipment_id=:s ORDER BY l.id DESC LIMIT 200'
    );
    $st->execute([':s' => $sid]);
    sj(['rows' => $st->fetchAll()]);
}

sj(['error' => 'unknown action: ?action=shipment|head|product|pallet|mark|mark_any|serial|confirm|crossdock|assembly|assembly_state|mark_log|log'], 404);
