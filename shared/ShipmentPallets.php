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
    $src = 'SELECT sscc, SUM(qty) AS qty, MIN(at) AS at FROM (' . implode(' UNION ALL ', $parts) . ') t GROUP BY sscc';
    try {
        $st = $pdo->prepare($src);
        $st->execute([':s' => $sid]);
        $found = $st->fetchAll();
    } catch (Throwable) {
        $found = [];
    }
    $has = $pdo->prepare('SELECT id FROM shipment_pallets WHERE shipment_id=:s AND sscc=:c LIMIT 1');
    $ins = $pdo->prepare(
        "INSERT INTO shipment_pallets (shipment_id,sscc,qty,sorted_at,in_zone_at,created_at)
         VALUES (:s,:c,:q,:at,datetime('now'),datetime('now'))"
    );
    foreach ($found as $row) {
        $sscc = trim((string)$row['sscc']);
        if ($sscc === '') {
            continue;
        }
        try {
            $has->execute([':s' => $sid, ':c' => $sscc]);
            if ($has->fetch()) {
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
            $pdo->prepare("UPDATE shipment_pallets SET shipped_at=datetime('now'), user_id=:u WHERE id=:id")
                ->execute([':u' => $uid, ':id' => (int)$hit['id']]);
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
                        fact_shipment_date=COALESCE(fact_shipment_date, :d)
                  WHERE id=:id"
            )->execute([':d' => date('Y-m-d'), ':id' => $sid]);
            $pdo->prepare(
                "INSERT INTO shipment_history (shipment_id,user_id,user_fio,action,old_status,new_status)
                 VALUES (:s,:u,:f,'status',:o,'shipped')"
            )->execute([':s' => $sid, ':u' => $uid, ':f' => $fio, ':o' => ($old === false ? null : (string)$old)]);
            require_once __DIR__ . '/IntegrationApi.php';
            wms_webhook_shipment_status($pdo, $sid, 'shipped', ['sscc' => (string)$hit['sscc'], 'user_fio' => $fio]);
        } catch (Throwable) {
        }
    }
    return ['ok' => true, 'already' => $already, 'sscc' => (string)$hit['sscc'],
        'remaining' => $remaining, 'all_shipped' => $all, 'status' => $status];
}
