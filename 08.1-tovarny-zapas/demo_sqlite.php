<?php
declare(strict_types=1);
// Модуль 8.1 "Товарный запас": реестр остатков по ячейкам и паллетам.
// Своей БД нет — читаем БД номенклатуры (product_stock + products).
// Открыть: /08.1-tovarny-zapas/demo_sqlite.php?action=list&search=1315
// Резерв по каждой строке тянем из заданий на отбор (tasks DB) одним запросом.

require_once __DIR__ . '/../shared/db.php';

$pdo = wms_nomenclature_pdo();
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

/** Резерв {sku: [{doc_no, pallet, tasks[], qty}]} одним запросом по списку артикулов. */
function stock_reserve_map(PDO $tpdo, array $skus): array
{
    $out = [];
    $skus = array_values(array_unique(array_filter(array_map('trim', $skus), fn($v) => $v !== '')));
    if (!$skus) {
        return $out;
    }
    require_once __DIR__ . '/../shared/PickIssue.php';
    try {
        $tbl = wms_task_tables($tpdo);
        $in = implode(',', array_fill(0, count($skus), '?'));
        $st = $tpdo->prepare(
            "SELECT i.sku, t.doc_no AS task_no, t.doc_ref, i.pallet, i.plan_qty, i.fact_qty
             FROM {$tbl['i']} i JOIN {$tbl['t']} t ON t.id = i.task_id
             WHERE i.sku IN ($in) AND t.flags LIKE '%pick%' AND t.status IN ('new','work','paused')"
        );
        $st->execute($skus);
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
                $sku = (string)$r['sku'];
                $key = $dn . "\x00" . $pal;
                if (!isset($out[$sku][$key])) {
                    $out[$sku][$key] = ['doc_no' => $dn, 'pallet' => ($pal !== '' ? $pal : null), 'tasks' => [], 'qty' => 0.0];
                }
                if (!in_array($r['task_no'], $out[$sku][$key]['tasks'], true)) {
                    $out[$sku][$key]['tasks'][] = $r['task_no'];
                }
                $out[$sku][$key]['qty'] += $need;
            }
        }
        foreach ($out as $sku => $byKey) {
            ksort($byKey);
            $out[$sku] = array_values($byKey);
        }
    } catch (Throwable) {
    }
    return $out;
}

if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
    $w = ['1=1'];
    $p = [];
    if (!empty($_GET['search'])) {
        $w[] = '(p.sku LIKE :q OR p.name LIKE :q OR s.cell LIKE :q OR s.pallet LIKE :q OR s.receipt_no LIKE :q)';
        $p[':q'] = '%' . trim((string)$_GET['search']) . '%';
    }
    if (!empty($_GET['warehouse_id'])) {
        $w[] = 's.warehouse_id = :wh';
        $p[':wh'] = (int)$_GET['warehouse_id'];
    }
    $where = 'WHERE ' . implode(' AND ', $w);
    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM product_stock s LEFT JOIN products p ON p.id = s.product_id $where"
    );
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $off = ($page - 1) * $perPage;
    $st = $pdo->prepare(
        "SELECT s.id, s.cell, s.pallet, s.qty, s.receipt_no, s.warehouse_id,
                w.name AS warehouse, p.sku, p.name AS product_name, p.unit
         FROM product_stock s
         LEFT JOIN products p ON p.id = s.product_id
         LEFT JOIN warehouses w ON w.id = s.warehouse_id
         $where ORDER BY p.sku, s.cell, s.id LIMIT :lim OFFSET :off"
    );
    foreach ($p as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $off, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // Резерв одним запросом по артикулам страницы.
    try {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $tpdos = wms_tasks_pdos();
        if ($tpdos) {
            $resMap = stock_reserve_map($tpdos[0], array_column($rows, 'sku'));
            foreach ($rows as &$r) {
                $r['reserve'] = $resMap[(string)($r['sku'] ?? '')] ?? [];
            }
            unset($r);
        }
    } catch (Throwable) {
    }
    echo json_encode([
        'rows' => $rows, 'total' => $total,
        'from' => $total ? $off + 1 : 0, 'to' => min($off + $perPage, $total),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'refs') {
    try {
        $wh = $pdo->query('SELECT id, name FROM warehouses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $wh = [];
    }
    echo json_encode(['warehouses' => $wh], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['error' => 'unknown action, use ?action=list|refs'], JSON_UNESCAPED_UNICODE);
}
