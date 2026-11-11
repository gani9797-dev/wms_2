<?php
declare(strict_types=1);
/**
 * Паллеты (SSCC) отгрузки: статусы «проверено / отгружено» (модуль 3.2,
 * функция ТСД «Отгрузить ТС»).
 *
 * Источник SSCC — факт сортировки ТСД: shipment_sort_log.pallet_id и
 * tsd_picks.sscc. Синхронизация переносит их в shipment_pallets:
 *   sorted_at  — паллета отсортирована,
 *   in_zone_at — весь товар паллеты в зоне отгрузки (статус «проверено»),
 *   shipped_at — отгружено (статус «отгружено», скан SSCC на ТСД).
 */

require_once __DIR__ . '/db.php';

/** Привести SSCC к цифровому виду без ведущих нулей (для сравнения сканов). */
function wms_ship_sscc_norm(string $s): string
{
    $d = preg_replace('/\D+/', '', $s);
    return $d === null ? '' : ltrim($d, '0');
}

/**
 * Список паллет отгрузки с синхронизацией из факта сортировки.
 * @return array<int,array<string,mixed>>
 */
function wms_ship_pallets_sync(PDO $pdo, int $sid): array
{
    if ($sid <= 0) {
        return [];
    }
    try {
        wms_ensure_shipments_schema($pdo);
    } catch (Throwable) {
    }
    if (!wms_table_columns($pdo, 'shipment_pallets')) {
        return [];
    }
    // SSCC из факта: лог сортировки + факт сборки (tsd_picks.sscc, если есть).
    $parts = [
        "SELECT pallet_id AS sscc, qty, created_at AS at FROM shipment_sort_log
          WHERE shipment_id=:s AND pallet_id IS NOT NULL AND pallet_id <> ''",
    ];
    try {
        $cols = wms_table_columns($pdo, 'tsd_picks');
        if (in_array('sscc', $cols, true)) {
            $parts[] = "SELECT sscc, qty, created_at AS at FROM tsd_picks
                         WHERE shipment_id=:s AND sscc IS NOT NULL AND sscc <> ''";
        }
    } catch (Throwable) {
    }
    // MAX, а не SUM: tsd_picks и shipment_sort_log — два отражения одного и
    // того же факта, их нельзя складывать (иначе паллета «удваивается»).
    $src = 'SELECT sscc, MAX(qty) AS qty, MIN(at) AS at FROM (' . implode(' UNION ALL ', $parts) . ') t GROUP BY sscc';
    try {
        $st = $pdo->prepare($src);
        $st->execute([':s' => $sid]);
        $found = $st->fetchAll();
    } catch (Throwable) {
        $found = [];
    }
    $has = $pdo->prepare('SELECT id, shipped_at FROM shipment_pallets WHERE shipment_id=:s AND sscc=:c LIMIT 1');
    $nowSql = function_exists('wms_now_sql') ? wms_now_sql($pdo) : 'NOW()';
    $ins = $pdo->prepare(
        "INSERT INTO shipment_pallets (shipment_id,sscc,qty,sorted_at,in_zone_at,created_at)
         VALUES (:s,:c,:q,:at,$nowSql,$nowSql)"
    );
    foreach ($found as $row) {
        $sscc = trim((string)$row['sscc']);
        if ($sscc === '') {
            continue;
        }
        try {
            $has->execute([':s' => $sid, ':c' => $sscc]);
            $exists = $has->fetch(PDO::FETCH_ASSOC);
            if ($exists) {
                // Количество могло быть посчитано дважды старой версией — выравниваем.
                if (empty($exists['shipped_at'])) {
                    $pdo->prepare('UPDATE shipment_pallets SET qty=:q WHERE id=:id')
                        ->execute([':q' => (float)($row['qty'] ?? 0), ':id' => (int)$exists['id']]);
                }
                continue;
            }
            $ins->execute([':s' => $sid, ':c' => $sscc, ':q' => (float)($row['qty'] ?? 0),
                ':at' => ($row['at'] ?? null) ?: null]);
        } catch (Throwable) {
        }
    }
    try {
        $st = $pdo->prepare('SELECT * FROM shipment_pallets WHERE shipment_id=:s ORDER BY sscc');
        $st->execute([':s' => $sid]);
        return $st->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** Статус паллеты для упаковочного листа: проверено / отгружено / ожидает. */
function wms_ship_pallet_status(array $row): array
{
    if (!empty($row['shipped_at'])) {
        return ['code' => 'shipped', 'label' => 'Отгружено'];
    }
    if (!empty($row['in_zone_at'])) {
        return ['code' => 'checked', 'label' => 'Проверено'];
    }
    return ['code' => 'pending', 'label' => 'Ожидает'];
}

/**
 * Отгрузить одну паллету (скан SSCC). Когда отгружены все паллеты —
 * документ переходит в статус «Отгружено» (shipped) с фактом даты отгрузки.
 *
 * @return array{ok:bool,error?:string,code?:int,already?:bool,sscc?:string,remaining?:int,all_shipped?:bool,status?:string,pallets?:array}
 */
function wms_ship_pallet_ship(PDO $pdo, int $sid, string $sscc, ?int $uid = null, string $fio = ''): array
{
    $sscc = trim($sscc);
    if ($sid <= 0 || $sscc === '') {
        return ['ok' => false, 'error' => 'нужны shipment_id и sscc', 'code' => 422];
    }
    $rows = wms_ship_pallets_sync($pdo, $sid);
    if (!$rows) {
        return ['ok' => false, 'error' => 'В отгрузке нет паллет (SSCC) — сначала отсортируйте товар', 'code' => 409];
    }
    $hit = null;
    $norm = wms_ship_sscc_norm($sscc);
    foreach ($rows as $r) {
        if ((string)$r['sscc'] === $sscc) {
            $hit = $r;
            break;
        }
        if ($hit === null && $norm !== '' && wms_ship_sscc_norm((string)$r['sscc']) === $norm) {
            $hit = $r;
        }
    }
    if (!$hit) {
        return ['ok' => false, 'error' => 'SSCC ' . $sscc . ' не относится к этой отгрузке', 'code' => 404];
    }
    $already = !empty($hit['shipped_at']);
    if (!$already) {
        try {
            $nowSql = function_exists('wms_now_sql') ? wms_now_sql($pdo) : 'NOW()';
            $pdo->prepare("UPDATE shipment_pallets SET shipped_at=$nowSql, user_id=:u WHERE id=:id")
                ->execute([':u' => $uid, ':id' => (int)$hit['id']]);
            // Списываем именно товар, лежащий на отсканированной SSCC.
            wms_ship_writeoff($pdo, $sid, (string)$hit['sscc'], $uid, $fio, false);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => 500];
        }
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM shipment_pallets WHERE shipment_id=:s AND shipped_at IS NULL');
    $st->execute([':s' => $sid]);
    $remaining = (int)$st->fetchColumn();
    $all = $remaining === 0;
    $status = null;
    if ($all) {
        try {
            $h = $pdo->prepare('SELECT status FROM shipments WHERE id=:id');
            $h->execute([':id' => $sid]);
            $old = $h->fetchColumn();
            $status = 'shipped';
            $pdo->prepare(
                "UPDATE shipments SET status='shipped', is_posted=1,
                        loading_type=COALESCE(loading_type,'Паллеты'),
                        fact_shipment_date=COALESCE(fact_shipment_date, :d)
                  WHERE id=:id"
            )->execute([':d' => date('Y-m-d'), ':id' => $sid]);
            $pdo->prepare(
                "INSERT INTO shipment_history (shipment_id,user_id,user_fio,action,old_status,new_status)
                 VALUES (:s,:u,:f,'status',:o,'shipped')"
            )->execute([':s' => $sid, ':u' => $uid, ':f' => $fio, ':o' => ($old === false ? null : (string)$old)]);
            require_once __DIR__ . '/IntegrationApi.php';
            wms_webhook_shipment_status($pdo, $sid, 'shipped', ['sscc' => (string)$hit['sscc'], 'user_fio' => $fio]);
            wms_ship_export_excel($pdo, $sid);
        } catch (Throwable) {
        }
    }
    return ['ok' => true, 'already' => $already, 'sscc' => (string)$hit['sscc'],
        'remaining' => $remaining, 'all_shipped' => $all, 'status' => $status];
}

/** Паллеты, фактически отобранные/отсортированные по отгрузке. */
function wms_ship_pick_pallets(PDO $pdo, int $sid): array
{
    if ($sid <= 0) {
        return [];
    }
    $out = [];
    try {
        $st = $pdo->prepare("SELECT DISTINCT sscc FROM tsd_picks WHERE shipment_id=:s AND sscc IS NOT NULL AND sscc<>''");
        $st->execute([':s' => $sid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $out[$v] = true;
            }
        }
    } catch (Throwable) {
    }
    try {
        $st = $pdo->prepare("SELECT DISTINCT pallet_id FROM shipment_sort_log WHERE shipment_id=:s AND pallet_id IS NOT NULL AND pallet_id<>''");
        $st->execute([':s' => $sid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $v = trim((string)$v);
            if ($v !== '') {
                $out[$v] = true;
            }
        }
    } catch (Throwable) {
    }
    return array_keys($out);
}

/**
 * Списание остатков при отгрузке.
 *  - $sscc задан: списываем именно товар на этой паллете;
 *  - $sscc = null: отгрузка «валом» — все паллеты отгрузки + добор по SKU
 *    до планового количества, если отобрано меньше.
 * Возвращает список списанных строк.
 */
function wms_ship_writeoff(PDO $pdo, int $sid, ?string $sscc, ?int $uid, string $fio, bool $bulk): array
{
    require_once __DIR__ . '/db.php';
    $ndb = wms_nomenclature_pdo();
    // Списание — в транзакции: строки остатков блокируются FOR UPDATE,
    // два одновременных списания одной паллеты невозможны.
    $ownTx = !$ndb->inTransaction();
    if ($ownTx) {
        try {
            $ndb->beginTransaction();
        } catch (Throwable) {
            $ownTx = false;
        }
    }
    $lockSql = (function_exists('wms_is_pgsql') && wms_is_pgsql($ndb)) ? ' FOR UPDATE' : '';
    $h = $pdo->prepare('SELECT id, doc_no, contractor_id, consignee, warehouse_id, status FROM shipments WHERE id=:i');
    $h->execute([':i' => $sid]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        if ($ownTx && $ndb->inTransaction()) {
            $ndb->rollBack();
        }
        return [];
    }
    $docNo = (string)$head['doc_no'];
    $wid = (int)($head['warehouse_id'] ?? 1) ?: 1;
    $want = []; // sku => qty (null = без ограничения, паллетный режим)
    $pallets = [];
    if ($bulk) {
        $items = $pdo->prepare('SELECT sku, SUM(qty) q FROM shipment_items WHERE shipment_id=:s GROUP BY sku');
        $items->execute([':s' => $sid]);
        foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $want[(string)$r['sku']] = (float)$r['q'];
        }
        $pallets = wms_ship_pick_pallets($pdo, $sid);
    } elseif ($sscc !== null && trim($sscc) !== '') {
        $pallets = [trim($sscc)];
    }
    if (!$pallets && !$bulk) {
        if ($ownTx && $ndb->inTransaction()) {
            $ndb->rollBack();
        }
        return [];
    }
    $rows = [];
    if ($pallets) {
        $ph = implode(',', array_fill(0, count($pallets), '?'));
        try {
            $st = $ndb->prepare(
                "SELECT s.id, s.product_id, s.cell, s.pallet, s.qty, s.pallet_type, p.sku, p.name
                   FROM product_stock s JOIN products p ON p.id=s.product_id
                  WHERE TRIM(s.pallet) IN ($ph) AND s.qty > 0 ORDER BY s.id" . $lockSql
            );
            $st->execute($pallets);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rows = [];
        }
    }
    // «Валом»: если на паллетах не хватило — добираем по SKU из остатков склада.
    if ($bulk) {
        $have = [];
        foreach ($rows as $r) {
            $have[(string)$r['sku']] = round(($have[(string)$r['sku']] ?? 0) + (float)$r['qty'], 3);
        }
        foreach ($want as $sku => $q) {
            $need = round($q - ($have[$sku] ?? 0), 3);
            if ($need <= 0.0001) {
                continue;
            }
            try {
                $st = $ndb->prepare(
                    "SELECT s.id, s.product_id, s.cell, s.pallet, s.qty, s.pallet_type, p.sku, p.name
                       FROM product_stock s JOIN products p ON p.id=s.product_id
                      WHERE p.sku=:sku AND s.qty > 0 AND s.warehouse_id=:w
                      ORDER BY (s.cell LIKE 'DOK-%'), s.prod_date NULLS LAST, s.id LIMIT 50" . $lockSql
                );
                $st->execute([':sku' => $sku, ':w' => $wid]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $take = min($need, (float)$r['qty']);
                    if ($take <= 0) {
                        continue;
                    }
                    $r['_take'] = $take;
                    $rows[] = $r;
                    $need = round($need - $take, 3);
                    if ($need <= 0.0001) {
                        break;
                    }
                }
            } catch (Throwable) {
            }
        }
    }
    $done = [];
    $cells = [];
    // Остаток к списанию по каждому SKU (для отгрузки валом).
    $remainingWant = $want;
    $journalPdo = null;
    try {
        require_once __DIR__ . '/Journal.php';
        $journalPdo = wms_journal_pdo();
    } catch (Throwable) {
    }
    $now = date('Y-m-d H:i:s');
    foreach ($rows as $r) {
        $stockId = (int)$r['id'];
        $pid = (int)$r['product_id'];
        $stockQty = (float)$r['qty'];
        $take = isset($r['_take']) ? (float)$r['_take'] : $stockQty;
        if ($bulk) {
            $sku = (string)$r['sku'];
            // ограничение плановым количеством по SKU (суммарно по всем паллетам)
            if (isset($remainingWant[$sku])) {
                $take = min($take, (float)$remainingWant[$sku]);
            }
        }
        $take = min($take, $stockQty);
        if ($take <= 0.0001) {
            continue;
        }
        if ($bulk && isset($remainingWant[(string)$r['sku']])) {
            $remainingWant[(string)$r['sku']] = round($remainingWant[(string)$r['sku']] - $take, 3);
        }
        try {
            $ndb->prepare('UPDATE product_stock SET qty=:q WHERE id=:id')
                ->execute([':q' => round($stockQty - $take, 3), ':id' => $stockId]);
            $pq = $ndb->prepare('SELECT COALESCE(qty,0) FROM products WHERE id=:p');
            $pq->execute([':p' => $pid]);
            $pbal = (float)$pq->fetchColumn();
            $pbalNew = round($pbal - $take, 3);
            $ndb->prepare('UPDATE products SET qty=:q, updated_at=NOW() WHERE id=:p')
                ->execute([':q' => $pbalNew, ':p' => $pid]);
            $mv = $ndb->prepare(
                "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                 VALUES (:p,NOW(),'Отгрузка',:d,:c,:q,:b)"
            );
            $mv->execute([':p' => $pid, ':d' => $docNo, ':c' => (string)($r['cell'] ?? ''),
                ':q' => -$take, ':b' => $pbalNew]);
        } catch (Throwable) {
        }
        $cell = trim((string)($r['cell'] ?? ''));
        if ($cell !== '') {
            $cells[$cell] = true;
        }
        $done[] = ['sku' => (string)$r['sku'], 'name' => (string)($r['name'] ?? ''),
            'qty' => $take, 'pallet' => (string)($r['pallet'] ?? ''), 'cell' => $cell];
        if ($journalPdo) {
            try {
                WmsJournal::log($journalPdo, [
                    'op_type' => 'отгрузка',
                    'doc_type' => 'Отгрузка',
                    'doc_no' => $docNo,
                    'sku' => (string)$r['sku'],
                    'product_name' => (string)($r['name'] ?? ''),
                    'qty' => $take,
                    'pallet' => (string)($r['pallet'] ?? ''),
                    'sscc' => (string)($r['pallet'] ?? ''),
                    'cell_from' => $cell,
                    'zone' => 'Отгрузка',
                    'user_id' => $uid,
                    'user_fio' => $fio,
                    'comment' => $bulk ? 'Отгрузка валом' : 'Отгрузка паллеты',
                ]);
            } catch (Throwable) {
            }
        }
    }
    // Обновить занятость ячеек и очистить нулевые остатки.
    foreach (array_keys($cells) as $code) {
        try {
            $sum = $ndb->prepare("SELECT COALESCE(SUM(qty),0) q, SUM(CASE WHEN qty>0 THEN 1 ELSE 0 END) p
                                    FROM product_stock WHERE cell=:c");
            $sum->execute([':c' => $code]);
            $row = $sum->fetch(PDO::FETCH_ASSOC) ?: ['q' => 0, 'p' => 0];
            $q = (float)($row['q'] ?? 0);
            $pc = (int)($row['p'] ?? 0);
            $sdb = wms_storage_pdo();
            $sdb->prepare("UPDATE storage_cells SET qty=:q, pallet_count=:p, reserved_qty=0,
                              status=CASE WHEN :q2 > 0 THEN 'busy' ELSE 'free' END,
                              pallet_type=CASE WHEN :q3 > 0 THEN pallet_type ELSE NULL END
                            WHERE code=:c")
                ->execute([':q' => $q, ':p' => $pc, ':q2' => $q, ':q3' => $q, ':c' => $code]);
            if ($q <= 0) {
                $sdb->prepare('DELETE FROM cell_content WHERE cell_id IN (SELECT id FROM storage_cells WHERE code=:c)')
                    ->execute([':c' => $code]);
            }
        } catch (Throwable) {
        }
    }
    if ($ownTx && $ndb->inTransaction()) {
        $ndb->commit();
    }
    return $done;
}

/** Сформировать Excel-файл факта отгрузки в папку «факт отгрузка». */
function wms_ship_export_excel(PDO $pdo, int $sid): ?string
{
    if ($sid <= 0) {
        return null;
    }
    require_once __DIR__ . '/XlsxWrite.php';
    $h = $pdo->prepare('SELECT * FROM shipments WHERE id=:i');
    $h->execute([':i' => $sid]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        return null;
    }
    $items = $pdo->prepare('SELECT sku, name, qty, supplier_batch, prod_date FROM shipment_items WHERE shipment_id=:s ORDER BY id');
    $items->execute([':s' => $sid]);
    $rows = [
        ['Факт отгрузки', (string)$head['doc_no']],
        ['Дата отгрузки', (string)($head['fact_shipment_date'] ?? date('Y-m-d'))],
        ['Грузополучатель', (string)($head['consignee'] ?? '')],
        ['Способ загрузки', (string)($head['loading_type'] ?? '')],
        [],
        ['Артикул', 'Наименование', 'Количество', 'Партия', 'Дата производства'],
    ];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [(string)$r['sku'], (string)$r['name'], (float)$r['qty'],
            (string)($r['supplier_batch'] ?? ''), (string)($r['prod_date'] ?? '')];
    }
    $pallets = [];
    try {
        $pallets = wms_ship_pallets_sync($pdo, $sid);
    } catch (Throwable) {
    }
    $prow = [['SSCC', 'Количество', 'Отгружено']];
    foreach ($pallets as $p) {
        $prow[] = [(string)$p['sscc'], (float)($p['qty'] ?? 0), !empty($p['shipped_at']) ? 'да' : 'нет'];
    }
    $dir = __DIR__ . '/../факт отгрузка';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $safe = preg_replace('/[^A-Za-z0-9А-Яа-я._-]+/u', '_', (string)$head['doc_no']) ?: ('S' . $sid);
    $file = $dir . '/Отгрузка_' . $safe . '_' . date('Ymd_His') . '.xlsx';
    try {
        $bin = wms_xlsx_write([
            ['name' => 'Товары', 'rows' => $rows],
            ['name' => 'Паллеты', 'rows' => $prow],
        ]);
        if (@file_put_contents($file, $bin) === false) {
            return null;
        }
        return $file;
    } catch (Throwable) {
        return null;
    }
}

/** Отгрузка «валом»: списать всю отгрузку и закрыть документ. */
function wms_ship_bulk(PDO $pdo, int $sid, ?int $uid, string $fio): array
{
    $h = $pdo->prepare('SELECT id, doc_no, status, gate, loading_type FROM shipments WHERE id=:i');
    $h->execute([':i' => $sid]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        return ['ok' => false, 'error' => 'Отгрузка не найдена', 'code' => 404];
    }
    if (in_array((string)$head['status'], ['shipped', 'cancelled', 'archived'], true)) {
        return ['ok' => false, 'error' => 'Отгрузка уже закрыта (статус ' . $head['status'] . ')', 'code' => 409];
    }
    $writeoff = wms_ship_writeoff($pdo, $sid, null, $uid, $fio, true);
    $nowSql = function_exists('wms_now_sql') ? wms_now_sql($pdo) : 'NOW()';
    // Все паллеты считаются отгруженными.
    try {
        $pdo->prepare("UPDATE shipment_pallets SET shipped_at=COALESCE(shipped_at,$nowSql), user_id=COALESCE(user_id,:u) WHERE shipment_id=:s")
            ->execute([':u' => $uid, ':s' => $sid]);
    } catch (Throwable) {
    }
    $old = (string)$head['status'];
    $pdo->prepare("UPDATE shipments SET status='shipped', is_posted=1, loading_type=COALESCE(loading_type,'Внавал'),
                      fact_shipment_date=COALESCE(fact_shipment_date, :d) WHERE id=:i")
        ->execute([':d' => date('Y-m-d'), ':i' => $sid]);
    try {
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,user_fio,action,old_status,new_status,comment)
                       VALUES (:s,:u,:f,'status',:o,'shipped',:c)")
            ->execute([':s' => $sid, ':u' => $uid, ':f' => $fio, ':o' => $old,
                ':c' => 'Отгрузка валом: списано ' . count($writeoff) . ' строк']);
    } catch (Throwable) {
        try {
            $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status)
                           VALUES (:s,:u,'status',:o,'shipped')")
                ->execute([':s' => $sid, ':u' => $uid, ':o' => $old]);
        } catch (Throwable) {
        }
    }
    try {
        require_once __DIR__ . '/IntegrationApi.php';
        wms_webhook_shipment_status($pdo, $sid, 'shipped', ['mode' => 'bulk', 'user_fio' => $fio]);
    } catch (Throwable) {
    }
    $file = wms_ship_export_excel($pdo, $sid);
    return ['ok' => true, 'mode' => 'bulk', 'status' => 'shipped', 'written_off' => $writeoff,
        'excel' => $file, 'pallets' => count(wms_ship_pallets_sync($pdo, $sid))];
}
