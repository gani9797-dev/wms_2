<?php
declare(strict_types=1);
/**
 * Фиксация услуг при приёмке/отгрузке.
 *
 * Пишет billing_event (источник истины) и сразу пересчитывает начисления
 * клиента за текущий месяц. Услуги:
 *   приёмка: евро-паллеты, фин-паллеты, стикеровка, разгрузка валом (м³);
 *   отгрузка: евро-паллеты, фин-паллеты, стикеровка.
 */

require_once __DIR__ . '/Billing.php';

/** PDO биллинга (общая схема WMS). */
function wms_service_billing_pdo(): PDO
{
    return billing_pdo();
}

/** Найти billing_client по контрагенту документа (contractors/counterparties). */
function wms_service_client_resolve(PDO $pdo, int $contractorId = 0, string $contractorName = '', ?int $counterpartyId = null): ?array
{
    $tryCp = static function (int $cpId) use ($pdo): ?array {
        if ($cpId <= 0) {
            return null;
        }
        try {
            $st = $pdo->prepare('SELECT * FROM billing_client WHERE counterparty_id=:c LIMIT 1');
            $st->execute([':c' => $cpId]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            return null;
        }
    };
    $byName = static function (string $name) use ($pdo): ?array {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        try {
            $st = $pdo->prepare('SELECT * FROM billing_client WHERE LOWER(TRIM(name))=LOWER(TRIM(:n)) LIMIT 1');
            $st->execute([':n' => $name]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
            $st = $pdo->prepare("SELECT * FROM billing_client WHERE LOWER(name) LIKE LOWER(:n) ORDER BY id LIMIT 1");
            $st->execute([':n' => '%' . $name . '%']);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            return null;
        }
    };

    if ($counterpartyId !== null && $counterpartyId > 0) {
        if ($r = $tryCp((int)$counterpartyId)) {
            return $r;
        }
    }
    if ($contractorId > 0) {
        if ($r = $tryCp($contractorId)) {
            return $r;
        }
        // contractors.id -> имя -> counterparties.id -> billing_client
        try {
            $st = $pdo->prepare('SELECT name FROM contractors WHERE id=:i LIMIT 1');
            $st->execute([':i' => $contractorId]);
            $cname = trim((string)($st->fetchColumn() ?: ''));
            if ($cname !== '') {
                $contractorName = $contractorName !== '' ? $contractorName : $cname;
                $cp = $pdo->prepare('SELECT id FROM counterparties WHERE LOWER(TRIM(name))=LOWER(TRIM(:n)) LIMIT 1');
                $cp->execute([':n' => $cname]);
                $cpId = (int)($cp->fetchColumn() ?: 0);
                if ($cpId > 0 && ($r = $tryCp($cpId))) {
                    return $r;
                }
            }
        } catch (Throwable) {
        }
    }
    if ($contractorName !== '') {
        if ($r = $byName($contractorName)) {
            return $r;
        }
        try {
            $st = $pdo->prepare('SELECT id FROM counterparties WHERE LOWER(TRIM(name))=LOWER(TRIM(:n)) LIMIT 1');
            $st->execute([':n' => $contractorName]);
            $cpId = (int)($st->fetchColumn() ?: 0);
            if ($cpId > 0 && ($r = $tryCp($cpId))) {
                return $r;
            }
        } catch (Throwable) {
        }
    }
    return null;
}

/** События фиксации услуг по документу. */
function wms_service_fix_events(PDO $pdo, string $docNo): array
{
    $docNo = trim($docNo);
    if ($docNo === '') {
        return [];
    }
    try {
        $st = $pdo->prepare(
            "SELECT e.*, s.name AS service_name, s.code AS service_code
               FROM billing_event e LEFT JOIN billing_service s ON s.event_type_code=e.event_type_code
              WHERE e.doc_no=:d AND e.source='MANUAL'
              ORDER BY e.id"
        );
        $st->execute([':d' => $docNo]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

/**
 * Сохранить фиксацию услуг и пересчитать начисления.
 *
 * @param array{client_id?:int,contractor_id?:int,contractor_name?:string,counterparty_id?:int,
 *              stage:string,doc_type?:string,doc_no:string,event_date?:string,
 *              pallets_euro?:int,pallets_fin?:int,sticker_qty?:int,bulk_m3?:float,
 *              user_id?:int,user_fio?:string} $o
 * @return array{ok:bool,error?:string,client?:array,events?:array,charges?:array,total_net?:float,total_gross?:float,warning?:string}
 */
function wms_service_fixation_save(PDO $pdo, array $o): array
{
    $stage = (string)($o['stage'] ?? '');
    if (!in_array($stage, ['receive', 'ship'], true)) {
        return ['ok' => false, 'error' => 'stage должен быть receive|ship'];
    }
    $docNo = trim((string)($o['doc_no'] ?? ''));
    if ($docNo === '') {
        return ['ok' => false, 'error' => 'не указан номер документа'];
    }
    $clientId = (int)($o['client_id'] ?? 0);
    if ($clientId <= 0) {
        $client = wms_service_client_resolve(
            $pdo,
            (int)($o['contractor_id'] ?? 0),
            (string)($o['contractor_name'] ?? ''),
            isset($o['counterparty_id']) ? (int)$o['counterparty_id'] : null
        );
        if (!$client) {
            return ['ok' => false, 'error' => 'клиент биллинга не найден для контрагента документа'];
        }
        $clientId = (int)$client['id'];
    } else {
        $st = $pdo->prepare('SELECT * FROM billing_client WHERE id=:i LIMIT 1');
        $st->execute([':i' => $clientId]);
        $client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$client) {
            return ['ok' => false, 'error' => 'клиент биллинга не найден: ' . $clientId];
        }
    }

    $date = substr(trim((string)($o['event_date'] ?? '')), 0, 10);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }
    $euro = max(0, (int)($o['pallets_euro'] ?? 0));
    $fin = max(0, (int)($o['pallets_fin'] ?? 0));
    $sticker = max(0, (int)($o['sticker_qty'] ?? 0));
    $m3 = max(0.0, (float)($o['bulk_m3'] ?? 0));
    $docType = trim((string)($o['doc_type'] ?? ($stage === 'receive' ? 'Поступление' : 'Отгрузка')));

    $rows = [];
    if ($stage === 'receive') {
        if ($euro > 0) $rows[] = ['IN_PALLET_EURO', 'pallet', $euro, $euro, 0, 0];
        if ($fin > 0) $rows[] = ['IN_PALLET_FIN', 'pallet', $fin, $fin, 0, 0];
        if ($sticker > 0) $rows[] = ['LBL_STICKER', 'sticker', $sticker, 0, $sticker, 0];
        if ($m3 > 0) $rows[] = ['IN_UNLOAD_M3', 'm3', $m3, 0, 0, $m3];
    } else {
        if ($euro > 0) $rows[] = ['OUT_PALLET_EURO', 'pallet', $euro, $euro, 0, 0];
        if ($fin > 0) $rows[] = ['OUT_PALLET_FIN', 'pallet', $fin, $fin, 0, 0];
        if ($sticker > 0) $rows[] = ['LBL_STICKER', 'sticker', $sticker, 0, $sticker, 0];
    }
    if (!$rows) {
        return ['ok' => false, 'error' => 'не указано ни одной услуги (паллеты/стикеровка/м³)'];
    }

    $prefix = 'FIX-' . $stage . '-' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $docNo) . '-';
    // Повторная фиксация заменяет предыдущую по этому документу.
    try {
        $del = $pdo->prepare('DELETE FROM billing_event WHERE external_id LIKE :p');
        $del->execute([':p' => $prefix . '%']);
    } catch (Throwable) {
    }
    $ins = $pdo->prepare(
        "INSERT INTO billing_event
           (external_id,source,client_id,event_type_code,event_date,quantity,uom_code,
            pallet_count,sticker_qty,volume_m3,doc_type,doc_no,status,payload)
         VALUES (:ext,'MANUAL',:cl,:tp,:d,:q,:u,:pal,:st,:m3,:dt,:doc,'NEW',:pl)"
    );
    $payloadBase = json_encode([
        'stage' => $stage, 'euro' => $euro, 'fin' => $fin, 'sticker' => $sticker,
        'bulk_m3' => $m3, 'user_id' => (int)($o['user_id'] ?? 0), 'user_fio' => (string)($o['user_fio'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    $inserted = [];
    foreach ($rows as [$code, $uom, $qty, $pal, $st, $vm]) {
        try {
            $ins->execute([
                ':ext' => $prefix . $code, ':cl' => $clientId, ':tp' => $code, ':d' => $date,
                ':q' => $qty, ':u' => $uom, ':pal' => $pal, ':st' => $st, ':m3' => $vm,
                ':dt' => $docType, ':doc' => $docNo, ':pl' => $payloadBase,
            ]);
            $inserted[] = $code . '=' . $qty;
        } catch (Throwable $ex) {
            return ['ok' => false, 'error' => 'не удалось записать услугу ' . $code . ': ' . $ex->getMessage()];
        }
    }

    // Пересчёт за текущий календарный месяц по клиенту.
    $from = date('Y-m-01', strtotime($date));
    $to = date('Y-m-t', strtotime($date));
    $warning = null;
    try {
        if (function_exists('billing_recalc_client')) {
            billing_recalc_client($pdo, $clientId, $from, $to, (int)($o['user_id'] ?? 0));
        } else {
            billing_run($pdo, ['from' => $from, 'to' => $to, 'clients' => [$clientId], 'mode' => 'FULL']);
        }
    } catch (Throwable $ex) {
        $warning = 'услуги записаны, но пересчёт не выполнен: ' . $ex->getMessage();
    }

    $charges = [];
    $net = 0.0;
    $gross = 0.0;
    try {
        $q = $pdo->prepare(
            "SELECT c.*, s.name AS service_name, s.code AS service_code
               FROM billing_charge c LEFT JOIN billing_service s ON s.id=c.service_id
              WHERE c.client_id=:cl AND c.charge_date BETWEEN :f AND :t
                AND c.status='ACTIVE'
              ORDER BY c.charge_date, c.id"
        );
        // У charges нет doc_no; показываем начисления периода по услугам фиксации.
        $q->execute([':cl' => $clientId, ':f' => $from, ':t' => $to]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (in_array((string)$r['service_code'], ['IN_PALLET_EURO','IN_PALLET_FIN','OUT_PALLET_EURO','OUT_PALLET_FIN','IN_UNLOAD_M3','LBL_STICKER'], true)) {
                $charges[] = $r;
                $net += (float)$r['amount_net'];
                $gross += (float)$r['amount_gross'];
            }
        }
    } catch (Throwable) {
    }

    return [
        'ok' => true,
        'client' => $client,
        'events' => wms_service_fix_events($pdo, $docNo),
        'charges' => $charges,
        'total_net' => round($net, 2),
        'total_gross' => round($gross, 2),
        'period' => [$from, $to],
        'warning' => $warning,
    ];
}
