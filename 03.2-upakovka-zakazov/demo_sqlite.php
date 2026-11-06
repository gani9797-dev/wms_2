<?php
declare(strict_types=1);
// Модуль 3.2 «Упаковка заказов» (упаковочный лист).
// Своей БД нет — работает с БД отгрузок 03-otgruzki:
//   shipments (шапка), shipment_items (план), tsd_picks (факт по SSCC),
//   shipment_sort_log (серийные номера на паллете),
//   shipment_pallets (статусы паллет: проверено / отгружено — ТСД «Отгрузить ТС»).
// Открыть: /03.2-upakovka-zakazov/demo_sqlite.php
//   ?action=shipments&from=2026-10-01&to=2026-10-31&q=S-205
//   ?action=pallets&shipment_id=1              — SSCC со статусами
//   ?action=pallet&shipment_id=1&sscc=…        — состав паллеты + с/н
//   ?action=print&shipment_id=1&sscc=…         — печатная форма A4 (PDF)

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Auth.php';
require_once __DIR__ . '/../shared/ShipmentPallets.php';
require_once __DIR__ . '/../shared/Pdf.php';

$pdo = wms_shipments_pdo();

/** Портативное условие «дата документа в диапазоне». */
function pack_date_expr(string $col): string
{
    return "substr(CAST($col AS TEXT), 1, 10)";
}

/** Состав паллеты (SSCC) по факту сортировки ТСД: строки + серийные номера. */
function pack_pallet_items(PDO $pdo, int $sid, string $sscc): array
{
    $items = [];
    // PG не разрешает подзапросы к негруппированным столбцам внешнего запроса —
    // агрегируем факт отдельно и подклеиваем справочник по sku через MIN(id).
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(i.code, '') AS code, t.sku,
                    COALESCE(i.name, t.sku) AS name, COALESCE(i.unit, 'шт') AS unit, t.qty
               FROM (SELECT sku, SUM(qty) AS qty, MIN(id) AS fid FROM tsd_picks
                      WHERE shipment_id = :s AND sscc = :c GROUP BY sku) t
               LEFT JOIN (SELECT sku, MIN(id) AS id FROM shipment_items
                           WHERE shipment_id = :s2 GROUP BY sku) mi ON mi.sku = t.sku
               LEFT JOIN shipment_items i ON i.id = mi.id
              ORDER BY t.fid"
        );
        $st->execute([':s' => $sid, ':s2' => $sid, ':c' => $sscc]);
        $items = $st->fetchAll();
    } catch (Throwable) {
        $items = [];
    }
    if (!$items) {
        // Запасной источник — лог сортировки (паллета могла быть собрана без tsd_picks.sscc).
        try {
            $st = $pdo->prepare(
                "SELECT COALESCE(i.code, '') AS code, COALESCE(i2.sku, '') AS sku,
                        COALESCE(i2.name, i2.sku, '') AS name, COALESCE(i2.unit, 'шт') AS unit, t.qty
                   FROM (SELECT item_id, SUM(qty) AS qty, MIN(id) AS fid FROM shipment_sort_log
                          WHERE shipment_id = :s AND pallet_id = :c GROUP BY item_id) t
                   LEFT JOIN shipment_items i2 ON i2.id = t.item_id
                   LEFT JOIN (SELECT sku, MIN(id) AS id FROM shipment_items
                               WHERE shipment_id = :s2 GROUP BY sku) mi ON mi.sku = i2.sku
                   LEFT JOIN shipment_items i ON i.id = mi.id
                  ORDER BY t.fid"
            );
            $st->execute([':s' => $sid, ':s2' => $sid, ':c' => $sscc]);
            $items = $st->fetchAll();
        } catch (Throwable) {
            $items = [];
        }
    }
    // Серийные номера паллеты — по артикулу.
    $serials = [];
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(i.sku, '') AS sku, l.serial_no
               FROM shipment_sort_log l
               LEFT JOIN shipment_items i ON i.id = l.item_id
              WHERE l.shipment_id = :s AND l.pallet_id = :c
                AND l.serial_no IS NOT NULL AND l.serial_no <> ''
              ORDER BY l.id"
        );
        $st->execute([':s' => $sid, ':c' => $sscc]);
        foreach ($st->fetchAll() as $r) {
            $serials[(string)$r['sku']][] = (string)$r['serial_no'];
        }
    } catch (Throwable) {
    }
    if (!$serials) {
        try {
            $st = $pdo->prepare(
                "SELECT sku, serial_no FROM shipment_flags
                  WHERE shipment_id = :s AND pallet = :c AND flag_type = 'serial'
                    AND serial_no IS NOT NULL AND serial_no <> '' ORDER BY id"
            );
            $st->execute([':s' => $sid, ':c' => $sscc]);
            foreach ($st->fetchAll() as $r) {
                $serials[(string)$r['sku']][] = (string)$r['serial_no'];
            }
        } catch (Throwable) {
        }
    }
    foreach ($items as &$it) {
        $it['qty'] = (float)($it['qty'] ?? 0);
        $it['serials'] = $serials[(string)$it['sku']] ?? [];
        $it['serials_text'] = implode(', ', $it['serials']);
    }
    unset($it);
    return $items;
}

$action = (string)($_GET['action'] ?? 'shipments');

// Печать упаковочного листа — PDF A4 (не JSON).
if ($action === 'print') {
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    $sscc = trim((string)($_GET['sscc'] ?? ''));
    if ($sid <= 0 || $sscc === '') {
        http_response_code(422);
        echo 'нужны shipment_id и sscc';
        exit;
    }
    $st = $pdo->prepare(
        'SELECT s.doc_no, s.doc_date, s.consignee, s.status, w.name AS warehouse
           FROM shipments s LEFT JOIN warehouses w ON w.id=s.warehouse_id WHERE s.id=:id'
    );
    $st->execute([':id' => $sid]);
    $head = $st->fetch() ?: [];
    if (!$head) {
        http_response_code(404);
        echo 'Отгрузка не найдена';
        exit;
    }
    $head['sscc'] = $sscc;
    $head['status_label'] = 'Упаковочный лист';
    $rows = pack_pallet_items($pdo, $sid, $sscc);
    $out = [];
    $n = 1;
    $qtyAll = 0.0;
    foreach ($rows as $r) {
        $qtyAll += (float)$r['qty'];
        $out[] = [
            'line_no' => $n++,
            'code' => (string)$r['code'],
            'sku' => (string)$r['sku'],
            'name' => (string)$r['name'],
            'unit' => (string)$r['unit'],
            'qty' => rtrim(rtrim(number_format((float)$r['qty'], 3, '.', ''), '0'), '.'),
            'serials' => (string)$r['serials_text'],
        ];
    }
    $head['qty_total'] = rtrim(rtrim(number_format($qtyAll, 3, '.', ''), '0'), '.');
    $head['sku_count'] = count($out);
    $data = ['kind' => 'pack_list', 'title' => 'Упаковочный лист', 'sscc' => $sscc, 'head' => $head, 'items' => $out];
    $force = ['line_no' => 'п/п', 'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование',
        'unit' => 'Ед.', 'qty' => 'Кол-во', 'serials' => 'Серийные номера'];
    require_once __DIR__ . '/../shared/Pdf.php';
    wms_pdf_output(wms_pdf_auto($data, $force, $sscc), 'pack_list_' . $sid . '_' . preg_replace('/\W+/', '', $sscc));
}

header('Content-Type: application/json; charset=utf-8');

if ($action === 'shipments') {
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $q = trim((string)($_GET['q'] ?? ''));
    $w = [];
    $p = [];
    if ($from !== '') {
        $w[] = pack_date_expr('s.doc_date') . ' >= :from';
        $p[':from'] = substr($from, 0, 10);
    }
    if ($to !== '') {
        $w[] = pack_date_expr('s.doc_date') . ' <= :to';
        $p[':to'] = substr($to, 0, 10);
    }
    if ($q !== '') {
        $w[] = '(s.doc_no LIKE :q OR s.consignee LIKE :q)';
        $p[':q'] = '%' . $q . '%';
    }
    $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
    $st = $pdo->prepare(
        "SELECT s.id, s.doc_no, s.doc_date, s.consignee, s.status, w.name AS warehouse
           FROM shipments s LEFT JOIN warehouses w ON w.id = s.warehouse_id
           $where ORDER BY s.doc_date DESC, s.id DESC LIMIT 200"
    );
    $st->execute($p);
    $rows = $st->fetchAll();
    $labels = [];
    try {
        require_once __DIR__ . '/../03-otgruzki/ShipmentStatus.php';
        $labels = array_column(array_map(fn($c) => [$c->value, $c->label()], ShipmentStatus::cases()), 1, 0);
    } catch (Throwable) {
    }
    foreach ($rows as &$r) {
        $pals = wms_ship_pallets_sync($pdo, (int)$r['id']);
        $total = count($pals);
        $shipped = 0;
        $checked = 0;
        foreach ($pals as $pal) {
            if (!empty($pal['shipped_at'])) {
                $shipped++;
            } elseif (!empty($pal['in_zone_at'])) {
                $checked++;
            }
        }
        $r['pallets_total'] = $total;
        $r['pallets_checked'] = $checked;
        $r['pallets_shipped'] = $shipped;
        $r['status_label'] = $labels[(string)$r['status']] ?? (string)$r['status'];
    }
    unset($r);
    echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'pallets') {
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    if ($sid <= 0) {
        echo json_encode(['error' => 'нужен shipment_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $st = $pdo->prepare('SELECT id, doc_no, doc_date, consignee, status FROM shipments WHERE id=:id');
    $st->execute([':id' => $sid]);
    $head = $st->fetch() ?: null;
    if (!$head) {
        echo json_encode(['error' => 'Отгрузка не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = [];
    foreach (wms_ship_pallets_sync($pdo, $sid) as $p) {
        $stt = wms_ship_pallet_status($p);
        $items = pack_pallet_items($pdo, $sid, (string)$p['sscc']);
        $qty = 0.0;
        $ser = 0;
        foreach ($items as $it) {
            $qty += (float)$it['qty'];
            $ser += count($it['serials']);
        }
        $rows[] = [
            'sscc' => (string)$p['sscc'], 'qty' => $qty, 'items' => count($items), 'serials' => $ser,
            'status' => $stt['code'], 'status_label' => $stt['label'],
            'sorted_at' => $p['sorted_at'], 'in_zone_at' => $p['in_zone_at'], 'shipped_at' => $p['shipped_at'],
        ];
    }
    echo json_encode(['head' => $head, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($action === 'pallet') {
    $sid = (int)($_GET['shipment_id'] ?? $_GET['id'] ?? 0);
    $sscc = trim((string)($_GET['sscc'] ?? ''));
    if ($sid <= 0 || $sscc === '') {
        echo json_encode(['error' => 'нужны shipment_id и sscc'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hit = null;
    foreach (wms_ship_pallets_sync($pdo, $sid) as $p) {
        if ((string)$p['sscc'] === $sscc) {
            $hit = $p;
            break;
        }
    }
    $st = $pdo->prepare('SELECT id, doc_no, doc_date, consignee, status FROM shipments WHERE id=:id');
    $st->execute([':id' => $sid]);
    $head = $st->fetch() ?: null;
    $stt = $hit ? wms_ship_pallet_status($hit) : ['code' => '', 'label' => ''];
    echo json_encode([
        'head' => $head, 'sscc' => $sscc,
        'status' => $stt['code'], 'status_label' => $stt['label'],
        'items' => pack_pallet_items($pdo, $sid, $sscc),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['error' => 'unknown action: shipments|pallets|pallet|print'], JSON_UNESCAPED_UNICODE);
