<?php
declare(strict_types=1);

// Модуль 0 «Дашборд» — отчёты Приход / Расход.
// Источники факта:
//   приход  — receipt_operations (accept_status='Принято'), дата обработки = accept_date;
//   расход  — tsd_picks (факт отбора/отгрузки с паллетой SSCC), дата = fact_shipment_date (или дата отбора).
// Объём считаем по номенклатуре: products.volume_m3 (если 0 — по габаритам L×W×H).
// Сводный: штук / объём / паллет в разрезе клиента. Детализация: по паллетам.

require_once __DIR__ . '/../shared/db.php';

function dash_json(mixed $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Объём единицы по каждому SKU: volume_m3 или по габаритам (м³). */
function dash_volumes(): array
{
    $map = [];
    try {
        $nom = wms_nomenclature_pdo();
        foreach ($nom->query("SELECT sku, volume_m3, length_mm, width_mm, height_mm FROM products")->fetchAll() as $p) {
            $v = (float)($p['volume_m3'] ?? 0);
            if ($v <= 0) {
                $v = ((float)($p['length_mm'] ?? 0) * (float)($p['width_mm'] ?? 0) * (float)($p['height_mm'] ?? 0)) / 1000000000;
            }
            $map[(string)$p['sku']] = max(0.0, $v);
        }
    } catch (Throwable) {
    }
    return $map;
}

/** Фильтр периода по дате (строка YYYY-MM-DD). */
function dash_period(): array
{
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    $ok = fn(string $d): bool => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
    return [$ok($from) ? $from : '', $ok($to) ? $to : ''];
}

/** Направление: in | out | all. */
function dash_direction(): string
{
    $d = (string)($_GET['direction'] ?? 'all');
    return in_array($d, ['in', 'out', 'all'], true) ? $d : 'all';
}

/** Факт прихода: строки по (день, документ, клиент, паллета, SKU). */
function dash_in_rows(string $from, string $to): array
{
    $pdo = wms_receipts_pdo();
    $sql = "SELECT substr(CAST(o.accept_date AS TEXT), 1, 10) AS day, r.doc_no AS doc,
                   COALESCE(NULLIF(c.name, ''), '') AS client,
                   COALESCE(o.pallet, '') AS pallet, o.sku AS sku, SUM(o.qty) AS qty
            FROM receipt_operations o
            JOIN receipts r ON r.id = o.receipt_id
            LEFT JOIN contractors c ON c.id = r.supplier_id
            WHERE o.accept_status = 'Принято' AND o.accept_date IS NOT NULL";
    $p = [];
    if ($from !== '') {
        $sql .= " AND substr(CAST(o.accept_date AS TEXT), 1, 10) >= :from";
        $p[':from'] = $from;
    }
    if ($to !== '') {
        $sql .= " AND substr(CAST(o.accept_date AS TEXT), 1, 10) <= :to";
        $p[':to'] = $to;
    }
    $sql .= " GROUP BY 1,2,3,4,5 ORDER BY 1 DESC, 2, 4, 5";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'type' => 'in', 'day' => (string)($r['day'] ?? ''), 'doc' => (string)($r['doc'] ?? ''),
            'client' => (string)($r['client'] ?? ''), 'pallet' => (string)($r['pallet'] ?? ''),
            'sku' => (string)($r['sku'] ?? ''), 'qty' => (float)($r['qty'] ?? 0),
        ];
    }
    return $out;
}

/** Факт расхода: строки по (день, документ, клиент, паллета SSCC, SKU). */
function dash_out_rows(string $from, string $to): array
{
    $pdo = wms_shipments_pdo();
    $sql = "SELECT substr(CAST(COALESCE(s.fact_shipment_date, p.created_at) AS TEXT), 1, 10) AS day, s.doc_no AS doc,
                   COALESCE(NULLIF(c.name, ''), NULLIF(s.consignee, ''), '') AS client,
                   COALESCE(p.sscc, '') AS pallet, p.sku AS sku, SUM(p.qty) AS qty
            FROM tsd_picks p
            JOIN shipments s ON s.id = p.shipment_id
            LEFT JOIN contractors c ON c.id = s.contractor_id
            WHERE COALESCE(s.fact_shipment_date, p.created_at) IS NOT NULL";
    $p = [];
    if ($from !== '') {
        $sql .= " AND substr(CAST(COALESCE(s.fact_shipment_date, p.created_at) AS TEXT), 1, 10) >= :from";
        $p[':from'] = $from;
    }
    if ($to !== '') {
        $sql .= " AND substr(CAST(COALESCE(s.fact_shipment_date, p.created_at) AS TEXT), 1, 10) <= :to";
        $p[':to'] = $to;
    }
    $sql .= " GROUP BY 1,2,3,4,5 ORDER BY 1 DESC, 2, 4, 5";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'type' => 'out', 'day' => (string)($r['day'] ?? ''), 'doc' => (string)($r['doc'] ?? ''),
            'client' => (string)($r['client'] ?? ''), 'pallet' => (string)($r['pallet'] ?? ''),
            'sku' => (string)($r['sku'] ?? ''), 'qty' => (float)($r['qty'] ?? 0),
        ];
    }
    return $out;
}

/** Данные с учётом объёма по номенклатуре. */
function dash_data(string $from, string $to): array
{
    $vol = dash_volumes();
    $rows = array_merge(dash_in_rows($from, $to), dash_out_rows($from, $to));
    foreach ($rows as &$r) {
        $r['volume'] = round(($vol[$r['sku']] ?? 0.0) * (float)$r['qty'], 6);
    }
    unset($r);
    return $rows;
}

/** Сводный: по клиентам — приход/расход (штук, объём, паллет). */
function dash_summary(array $rows): array
{
    $by = [];
    $pal = [];
    foreach ($rows as $r) {
        $c = $r['client'] !== '' ? $r['client'] : '—';
        if (!isset($by[$c])) {
            $by[$c] = ['client' => $c, 'in_qty' => 0.0, 'in_volume' => 0.0, 'in_pallets' => 0,
                'out_qty' => 0.0, 'out_volume' => 0.0, 'out_pallets' => 0];
        }
        $dir = $r['type'] === 'in' ? 'in' : 'out';
        $by[$c][$dir . '_qty'] += (float)$r['qty'];
        $by[$c][$dir . '_volume'] += (float)$r['volume'];
        if ((string)$r['pallet'] !== '') {
            $pal[$c . '|' . $dir][(string)$r['pallet']] = true;
        }
    }
    $out = [];
    foreach ($by as $c => $v) {
        $v['in_pallets'] = count($pal[$c . '|in'] ?? []);
        $v['out_pallets'] = count($pal[$c . '|out'] ?? []);
        $v['in_qty'] = round($v['in_qty'], 3);
        $v['out_qty'] = round($v['out_qty'], 3);
        $v['in_volume'] = round($v['in_volume'], 6);
        $v['out_volume'] = round($v['out_volume'], 6);
        $out[] = $v;
    }
    usort($out, fn($a, $b) => strcmp((string)$a['client'], (string)$b['client']));
    return $out;
}

/** Детализация: по паллетам (тип, дата, документ, клиент, паллета) — штук, объём, артикулы. */
function dash_detail(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $key = $r['type'] . '|' . $r['day'] . '|' . $r['doc'] . '|' . $r['client'] . '|' . $r['pallet'];
        if (!isset($by[$key])) {
            $by[$key] = ['type' => $r['type'], 'day' => $r['day'], 'doc' => $r['doc'],
                'client' => $r['client'] !== '' ? $r['client'] : '—', 'pallet' => $r['pallet'],
                'qty' => 0.0, 'volume' => 0.0, 'skus' => []];
        }
        $by[$key]['qty'] += (float)$r['qty'];
        $by[$key]['volume'] += (float)$r['volume'];
        $sku = (string)$r['sku'];
        if ($sku !== '' && !in_array($sku, $by[$key]['skus'], true)) {
            $by[$key]['skus'][] = $sku;
        }
    }
    $out = array_values($by);
    foreach ($out as &$d) {
        $d['qty'] = round($d['qty'], 3);
        $d['volume'] = round($d['volume'], 6);
        $d['skus'] = implode(', ', $d['skus']);
    }
    unset($d);
    usort($out, function ($a, $b) {
        $c = strcmp((string)$b['day'], (string)$a['day']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp($a['type'], $b['type']);
        if ($c !== 0) {
            return $c;
        }
        $c = strcmp((string)$a['doc'], (string)$b['doc']);
        return $c !== 0 ? $c : strcmp((string)$a['pallet'], (string)$b['pallet']);
    });
    return $out;
}

/** Итоги. */
function dash_totals(array $rows): array
{
    $t = ['in_qty' => 0.0, 'in_volume' => 0.0, 'out_qty' => 0.0, 'out_volume' => 0.0, 'in_pallets' => 0, 'out_pallets' => 0];
    $pal = ['in' => [], 'out' => []];
    foreach ($rows as $r) {
        $dir = $r['type'] === 'in' ? 'in' : 'out';
        $t[$dir . '_qty'] += (float)$r['qty'];
        $t[$dir . '_volume'] += (float)$r['volume'];
        if ((string)$r['pallet'] !== '') {
            $pal[$dir][(string)$r['pallet']] = true;
        }
    }
    $t['in_pallets'] = count($pal['in']);
    $t['out_pallets'] = count($pal['out']);
    foreach (['in_qty', 'out_qty'] as $k) {
        $t[$k] = round($t[$k], 3);
    }
    foreach (['in_volume', 'out_volume'] as $k) {
        $t[$k] = round($t[$k], 6);
    }
    return $t;
}

$action = (string)($_GET['action'] ?? 'summary');
[$from, $to] = dash_period();
$dir = dash_direction();

$rows = dash_data($from, $to);
if ($dir !== 'all') {
    $rows = array_values(array_filter($rows, fn($r) => $r['type'] === $dir));
}

if ($action === 'summary') {
    dash_json(['ok' => true, 'direction' => $dir, 'from' => $from, 'to' => $to,
        'rows' => dash_summary($rows), 'totals' => dash_totals($rows)]);
}

if ($action === 'detail') {
    dash_json(['ok' => true, 'direction' => $dir, 'from' => $from, 'to' => $to,
        'rows' => dash_detail($rows), 'totals' => dash_totals($rows)]);
}

if ($action === 'export') {
    require_once __DIR__ . '/../shared/XlsxWrite.php';
    // Направление сводного листа и отдельно детализации (фильтр «Приход/Расход/Все»).
    $ddir = (string)($_GET['detail_direction'] ?? $dir);
    if (!in_array($ddir, ['in', 'out', 'all'], true)) {
        $ddir = $dir;
    }
    $rows = dash_data($from, $to);
    $sumRowsRaw = $dir === 'all' ? $rows : array_values(array_filter($rows, fn($r) => $r['type'] === $dir));
    $detRowsRaw = $ddir === 'all' ? $rows : array_values(array_filter($rows, fn($r) => $r['type'] === $ddir));
    $summary = dash_summary($sumRowsRaw);
    $detail = dash_detail($detRowsRaw);
    $totals = dash_totals($sumRowsRaw);

    // Лист «Сводный»: колонки зависят от направления.
    $head = ['Клиент'];
    if ($dir !== 'out') {
        $head = array_merge($head, ['Принято, шт', 'Объём прихода, м³', 'Паллет приход']);
    }
    if ($dir !== 'in') {
        $head = array_merge($head, ['Отгружено, шт', 'Объём расхода, м³', 'Паллет расход']);
    }
    $sumRows = [$head];
    foreach ($summary as $s) {
        $line = [$s['client']];
        if ($dir !== 'out') {
            $line[] = $s['in_qty'];
            $line[] = $s['in_volume'];
            $line[] = $s['in_pallets'];
        }
        if ($dir !== 'in') {
            $line[] = $s['out_qty'];
            $line[] = $s['out_volume'];
            $line[] = $s['out_pallets'];
        }
        $sumRows[] = $line;
    }
    $tl = ['Итого'];
    if ($dir !== 'out') {
        $tl[] = $totals['in_qty'];
        $tl[] = $totals['in_volume'];
        $tl[] = $totals['in_pallets'];
    }
    if ($dir !== 'in') {
        $tl[] = $totals['out_qty'];
        $tl[] = $totals['out_volume'];
        $tl[] = $totals['out_pallets'];
    }
    $sumRows[] = $tl;

    $detRows = [['Тип', 'Дата обработки', 'Документ', 'Клиент', 'Паллета', 'Кол-во, шт', 'Объём, м³', 'Артикулы']];
    foreach ($detail as $d) {
        $detRows[] = [
            $d['type'] === 'in' ? 'Приход' : 'Расход',
            $d['day'], $d['doc'], $d['client'],
            $d['pallet'] !== '' ? $d['pallet'] : '—',
            $d['qty'], $d['volume'], $d['skus'],
        ];
    }

    $bin = wms_xlsx_write([
        ['name' => 'Сводный', 'rows' => $sumRows],
        ['name' => 'Детализация', 'rows' => $detRows],
    ]);
    $fname = 'otchet_prihod_rashod_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($bin));
    header('Cache-Control: no-store');
    echo $bin;
    exit;
}

dash_json(['error' => 'unknown action: summary|detail|export'], 404);
