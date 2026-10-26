<?php
declare(strict_types=1);

// Общая логика приёмки поступлений: позиции-партии, отклонения и закрытие документа.
// Используется ТСД «Приход» (tsd/prihod.php) и модулем поступлений (02-postupleniya).
// ВАЖНО: акты расхождений (недостача/излишек) формируются ТОЛЬКО при закрытии документа —
// см. wms_close_receipt().

require_once __DIR__ . '/pg.php';

/** План позиции по документу (сумма qty строк). */
function sku_plan(PDO $pdo, int $rid, string $sku): float
{
    $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=:r AND sku=:s");
    $s->execute([':r' => $rid, ':s' => $sku]);
    return (float)$s->fetchColumn();
}

/** Факт приёмки позиции. $batch=null — по всем партиям, '' — без партии, иначе конкретная партия. */
function sku_fact(PDO $pdo, int $rid, string $sku, ?string $batch = null): float
{
    if ($batch === null) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND sku=:s AND accept_status='Принято'");
        $s->execute([':r' => $rid, ':s' => $sku]);
        return (float)$s->fetchColumn();
    }
    if ($batch === '') {
        $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND sku=:s AND accept_status='Принято' AND (supplier_batch IS NULL OR supplier_batch='')");
        $s->execute([':r' => $rid, ':s' => $sku]);
        return (float)$s->fetchColumn();
    }
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND sku=:s AND accept_status='Принято' AND supplier_batch=:b");
        $s->execute([':r' => $rid, ':s' => $sku, ':b' => $batch]);
        return (float)$s->fetchColumn();
    } catch (Throwable) {
        $s = $pdo->prepare("SELECT COALESCE(SUM(qty),0) FROM receipt_operations WHERE receipt_id=:r AND sku=:s AND accept_status='Принято'");
        $s->execute([':r' => $rid, ':s' => $sku]);
        return (float)$s->fetchColumn();
    }
}

/**
 * Партии поставщика по позиции: [{batch, plan, fact}].
 * batch='' — строки без партии (старые документы).
 */
function sku_batches(PDO $pdo, int $rid, string $sku): array
{
    try {
        $st = $pdo->prepare("SELECT supplier_batch, COALESCE(SUM(qty),0) AS plan FROM receipt_items WHERE receipt_id=:r AND sku=:s GROUP BY supplier_batch ORDER BY supplier_batch");
        $st->execute([':r' => $rid, ':s' => $sku]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [['batch' => '', 'plan' => sku_plan($pdo, $rid, $sku), 'fact' => sku_fact($pdo, $rid, $sku)]];
    }
    $out = [];
    foreach ($rows as $r) {
        $b = (string)($r['supplier_batch'] ?? '');
        $out[] = ['batch' => $b, 'plan' => (float)$r['plan'], 'fact' => sku_fact($pdo, $rid, $sku, $b)];
    }
    return $out;
}

/**
 * Границы позиции: план/факт в границах партии.
 * Позиция с несколькими партиями требует выбора партии ($batch = null -> need_batch).
 */
function scope_bounds(PDO $pdo, int $rid, string $sku, ?string $batch): array
{
    $batches = sku_batches($pdo, $rid, $sku);
    $multi = count($batches) > 1;
    if ($multi) {
        $known = array_map('strval', array_column($batches, 'batch'));
        if ($batch === null || !in_array((string)$batch, $known, true)) {
            return ['need_batch' => true, 'batches' => $batches];
        }
        $plan = 0.0;
        $fact = 0.0;
        foreach ($batches as $b) {
            if ((string)$b['batch'] === (string)$batch) {
                $plan = (float)$b['plan'];
                $fact = (float)$b['fact'];
                break;
            }
        }
        return ['plan' => $plan, 'fact' => $fact, 'batch' => (string)$batch, 'batch_mode' => true, 'batches' => $batches];
    }
    $bn = count($batches) === 1 ? (string)$batches[0]['batch'] : '';
    $fact = sku_fact($pdo, $rid, $sku, $bn);
    if ($fact <= 0) {
        // Старые операции без партии — считаем по SKU, иначе факт «пропадает».
        $all = sku_fact($pdo, $rid, $sku);
        if ($all > 0) {
            $fact = $all;
        }
    }
    return [
        'plan' => sku_plan($pdo, $rid, $sku),
        'fact' => $fact,
        'batch' => $bn,
        'batch_mode' => false,
        'batches' => $batches,
    ];
}

/** Есть ли колонка в таблице (sqlite/pg/mysql). */
function has_col(PDO $pdo, string $table, string $col): bool
{
    if (function_exists('wms_table_columns')) {
        return in_array($col, wms_table_columns($pdo, $table), true);
    }
    return true;
}

/**
 * Акт расхождений: одна строка на (позиция, партия, тип).
 * Повторная фиксация обновляет отклонение, а не плодит дубли.
 */
function act_upsert(PDO $pdo, int $rid, string $sku, string $batch, string $type, float $diff): void
{
    $withBatch = has_col($pdo, 'tsd_act_rasx', 'supplier_batch');
    $where = "receipt_id=:r AND sku=:s AND type=:t";
    $p = [':r' => $rid, ':s' => $sku, ':t' => $type];
    if ($withBatch) {
        $where .= " AND COALESCE(supplier_batch,'')=:b";
        $p[':b'] = $batch;
    }
    $st = $pdo->prepare("SELECT id FROM tsd_act_rasx WHERE $where ORDER BY id LIMIT 1");
    $st->execute($p);
    $id = $st->fetchColumn();
    if ($id !== false && $id !== null) {
        $pdo->prepare("UPDATE tsd_act_rasx SET qty_diff=:d WHERE id=:id")
            ->execute([':d' => $diff, ':id' => (int)$id]);
        return;
    }
    if ($withBatch) {
        $pdo->prepare("INSERT INTO tsd_act_rasx (receipt_id,sku,supplier_batch,type,qty_diff) VALUES (:r,:s,:b,:t,:d)")
            ->execute([':r' => $rid, ':s' => $sku, ':b' => $batch, ':t' => $type, ':d' => $diff]);
        return;
    }
    $pdo->prepare("INSERT INTO tsd_act_rasx (receipt_id,sku,type,qty_diff) VALUES (:r,:s,:t,:d)")
        ->execute([':r' => $rid, ':s' => $sku, ':t' => $type, ':d' => $diff]);
}

/**
 * Позиции документа по партиям поставщика: план / факт / отклонение / состояние.
 * Состояния: exact (принято всё), shortage (не хватает), excess (лишнее), unclaimed (не заявлен).
 * deviation — отметка решения оператора ('shortage'|'excess'); closed — расхождение зафиксировано.
 */
function receipt_scopes(PDO $pdo, int $rid): array
{
    $st = $pdo->prepare(
        "SELECT sku, COALESCE(supplier_batch,'') AS batch, SUM(qty) AS plan,
                MAX(CASE WHEN COALESCE(line_status,'')='unclaimed' THEN 1 ELSE 0 END) AS unclaimed,
                MAX(COALESCE(deviation,'')) AS deviation
         FROM receipt_items WHERE receipt_id=:r
         GROUP BY sku, COALESCE(supplier_batch,'') ORDER BY sku, batch"
    );
    $st->execute([':r' => $rid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // Акты: какие позиции/партии уже закрыты расхождениями.
    $acts = [];
    try {
        $withBatch = has_col($pdo, 'tsd_act_rasx', 'supplier_batch');
        $as = $pdo->prepare("SELECT sku, type, qty_diff" . ($withBatch ? ", COALESCE(supplier_batch,'') AS batch" : ", '' AS batch")
            . " FROM tsd_act_rasx WHERE receipt_id=:r");
        $as->execute([':r' => $rid]);
        foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $acts[(string)$a['sku'] . '|' . (string)$a['batch']][(string)$a['type']] = (float)$a['qty_diff'];
        }
    } catch (Throwable) {
    }
    $out = [];
    $batchCount = [];
    foreach ($rows as $r) {
        $batchCount[(string)$r['sku']] = ($batchCount[(string)$r['sku']] ?? 0) + 1;
    }
    foreach ($rows as $r) {
        $sku = (string)$r['sku'];
        $batch = (string)$r['batch'];
        $plan = (float)$r['plan'];
        $fact = sku_fact($pdo, $rid, $sku, $batch);
        if ($fact <= 0 && ($batchCount[$sku] ?? 0) === 1) {
            // Старые операции без партии — считаем по SKU, иначе факт «пропадает».
            $fact = sku_fact($pdo, $rid, $sku);
        }
        $dev = round($fact - $plan, 3);
        $unclaimed = (int)$r['unclaimed'] > 0;
        $mark = (string)($r['deviation'] ?? '');
        $act = $acts[$sku . '|' . $batch] ?? [];
        $state = $unclaimed ? 'unclaimed' : ($dev > 0 ? 'excess' : ($dev < 0 ? 'shortage' : 'exact'));
        $closed = true;
        if ($state === 'shortage') {
            // Закрыта, когда оператор зафиксировал недостачу (или акт уже есть).
            $closed = ($mark === 'shortage') || isset($act['недостача']);
        } elseif ($state === 'excess') {
            $closed = ($mark === 'excess') || isset($act['излишек']);
        }
        $out[] = [
            'sku' => $sku, 'batch' => $batch, 'plan' => $plan, 'fact' => $fact, 'dev' => $dev,
            'state' => $state, 'unclaimed' => $unclaimed, 'closed' => $closed,
            'deviation' => $mark,
            'remaining' => round(max(0, $plan - $fact), 3),
        ];
    }
    return $out;
}

/**
 * Отметка решения оператора по позиции (партии): 'shortage' | 'excess' | '' (сброс).
 * Сам акт расхождений формируется только при закрытии документа.
 */
function set_deviation(PDO $pdo, int $rid, string $sku, string $batchNorm, bool $batchMode, string $dev): void
{
    $sql = "UPDATE receipt_items SET deviation=:d WHERE receipt_id=:r AND sku=:s";
    $p = [':d' => ($dev === '' ? null : $dev), ':r' => $rid, ':s' => $sku];
    if ($batchMode) {
        $sql .= " AND COALESCE(supplier_batch,'')=:b";
        $p[':b'] = $batchNorm;
    }
    $pdo->prepare($sql)->execute($p);
}

/** Разложить факт по строкам позиции (пропорционально плану строк). */
function apply_qty_fact(PDO $pdo, int $rid, string $sku, string $batchNorm, bool $batchMode, float $plan, float $fact): void
{
    if ($batchMode) {
        $rows = $pdo->prepare("SELECT id, qty FROM receipt_items WHERE receipt_id=:r AND sku=:s AND COALESCE(supplier_batch,'')=:b");
        $rows->execute([':r' => $rid, ':s' => $sku, ':b' => $batchNorm]);
        $bRows = $rows->fetchAll();
        $bPlanSum = 0.0;
        foreach ($bRows as $br) {
            $bPlanSum += (float)$br['qty'];
        }
        if ($bPlanSum <= 0) {
            $bPlanSum = $plan;
        }
        wms_apply_qty_fact_rows($pdo, $bRows, $bPlanSum, $fact);
        return;
    }
    if ($plan > 0) {
        $rows = $pdo->prepare("SELECT id, qty FROM receipt_items WHERE receipt_id=:r AND sku=:s");
        $rows->execute([':r' => $rid, ':s' => $sku]);
        $rws = $rows->fetchAll();
        // Делим по СУММЕ планов строк, а не по переданному $plan: иначе доли
        // не сойдутся с фактом (план мог быть посчитан по-другому).
        $sum = 0.0;
        foreach ($rws as $rw) {
            $sum += (float)$rw['qty'];
        }
        if ($sum <= 0) {
            $sum = $plan;
        }
        wms_apply_qty_fact_rows($pdo, $rws, $sum, $fact);
        return;
    }
    $pdo->prepare("UPDATE receipt_items SET qty_fact=:f WHERE receipt_id=:r AND sku=:s")
        ->execute([':f' => $fact, ':r' => $rid, ':s' => $sku]);
}

/**
 * Разнести факт по строкам с точностью до копейки: доли округляются,
 * а остаток от округления добавляется к последней строке. Без этого
 * SUM(qty_fact) расходился с фактом (10/3 -> 3.333*3 = 9.999).
 */
function wms_apply_qty_fact_rows(PDO $pdo, array $rows, float $planSum, float $fact): void
{
    if (!$rows) {
        return;
    }
    $up = $pdo->prepare("UPDATE receipt_items SET qty_fact=:f WHERE id=:id");
    $shares = [];
    $acc = 0.0;
    foreach ($rows as $k => $rw) {
        $share = ($planSum > 0) ? round($fact * ((float)$rw['qty'] / $planSum), 3) : 0.0;
        $shares[$k] = $share;
        $acc = round($acc + $share, 3);
    }
    $diff = round($fact - $acc, 3);
    $last = array_key_last($shares);
    if (abs($diff) > 0.0001) {
        $shares[$last] = round($shares[$last] + $diff, 3);
    }
    foreach ($shares as $k => $share) {
        $up->execute([':f' => $share, ':id' => $rows[$k]['id']]);
    }
}

/** Позиции с излишком, не подтверждённым пересчётом. */
function unconfirmed_excess(PDO $pdo, int $rid): array
{
    $over = [];
    foreach (receipt_scopes($pdo, $rid) as $s) {
        if ($s['state'] === 'excess' && !$s['closed']) {
            $over[] = $s['sku'] . ($s['batch'] !== '' ? ' (партия ' . $s['batch'] . ')' : '');
        }
    }
    return $over;
}

/** Позиции с незафиксированной недостачей — закрытие документа с ними невозможно. */
function open_shortages(PDO $pdo, int $rid): array
{
    $out = [];
    foreach (receipt_scopes($pdo, $rid) as $s) {
        if ($s['state'] === 'shortage' && !$s['closed']) {
            $out[] = $s['sku'] . ($s['batch'] !== '' ? ' (партия ' . $s['batch'] . ')' : '');
        }
    }
    return $out;
}

/** Строка позиции оформлена как «не заявлен»? */
function line_is_unclaimed(PDO $pdo, int $rid, string $sku, string $batchNorm, bool $batchMode): bool
{
    $sql = "SELECT COUNT(*) FROM receipt_items WHERE receipt_id=:r AND sku=:s AND line_status='unclaimed'";
    $p = [':r' => $rid, ':s' => $sku];
    if ($batchMode) {
        $sql .= " AND COALESCE(supplier_batch,'')=:b";
        $p[':b'] = $batchNorm;
    }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Какие акты сформированы по документу.
 * Если расхождений нет — формируется «Акт приема-передачи» (акт приёмки).
 */
function acts_summary(PDO $pdo, int $rid, array $actRows): array
{
    $titles = [
        'приемка' => 'Акт приема-передачи',
        'недостача' => 'Акт о недостаче',
        'излишек' => 'Акт об излишке',
        'брак' => 'Акт о браке',
        'не_заявлен' => 'Акт о не заявленном товаре',
    ];
    $byType = [];
    foreach ($actRows as $a) {
        $t = (string)($a['type'] ?? '');
        if ($t === '') {
            continue;
        }
        if (!isset($byType[$t])) {
            $byType[$t] = ['type' => $t, 'title' => $titles[$t] ?? ('Акт: ' . $t), 'count' => 0, 'qty' => 0.0];
        }
        $byType[$t]['count']++;
        $byType[$t]['qty'] = round($byType[$t]['qty'] + (float)($a['qty_diff'] ?? 0), 3);
    }
    $out = [];
    if (!isset($byType['недостача']) && !isset($byType['излишек'])) {
        $plan = (float)$pdo->query("SELECT COALESCE(SUM(qty),0) FROM receipt_items WHERE receipt_id=$rid")->fetchColumn();
        $out[] = ['type' => 'приемка', 'title' => $titles['приемка'], 'count' => 1, 'qty' => $plan];
    }
    foreach ($byType as $t) {
        $out[] = $t;
    }
    return $out;
}

/**
 * ЗАКРЫТИЕ документа поступления с проверками.
 * Единая точка для всех точек закрытия (реестр поступлений и ТСД):
 *  - нельзя закрыть документ с неподтверждённым излишком;
 *  - нельзя закрыть документ с незафиксированной недостачей;
 *  - статус «Закрыто» / «Закрыто с отклонением» определяется ПО ФАКТУ расхождений,
 *    а не тем, что нажал оператор.
 * @throws RuntimeException с текстом причины (модуль отдаёт 400, ТСД — 409).
 */
function wms_close_receipt_guarded(PDO $pdo, int $rid, ?int $userId = null, string $userFio = 'Реестр поступлений'): array
{
    $bad = unconfirmed_excess($pdo, $rid);
    if ($bad) {
        throw new RuntimeException('Нельзя закрыть: подтверди излишек пересчетом по позициям: ' . implode(', ', $bad));
    }
    $short = open_shortages($pdo, $rid);
    if ($short) {
        throw new RuntimeException('Нельзя закрыть: зафиксируй недостачу по позициям: ' . implode(', ', $short));
    }
    // Статус не передаём намеренно: wms_close_receipt() сам выводит его из расхождений.
    return wms_close_receipt($pdo, $rid, $userId, $userFio);
}

/**
 * ЗАКРЫТИЕ документа поступления.
 * Формирует акты расхождений (только здесь!), ставит статус
 * «Закрыто» (все строки: полученное = заявленному) либо «Закрыто с отклонением»
 * (излишки/недостача), пишет комментарий и логирует действие.
 * @return array{status:string,excess_qty:float,shortage_qty:float,deviation:string,acts:array}
 */
function wms_close_receipt(PDO $pdo, int $rid, ?int $userId = null, string $userFio = 'ТСД', ?string $status = null): array
{
    $h = $pdo->prepare("SELECT status, comment FROM receipts WHERE id=:id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch();
    if (!$head) {
        throw new RuntimeException('Документ не найден');
    }
    $scopes = receipt_scopes($pdo, $rid);
    $excessQty = 0.0;
    $shortQty = 0.0;
    foreach ($scopes as $s) {
        if ($s['state'] === 'unclaimed') {
            // Не заявленный товар — отдельный акт (принимаем как есть, но отмечаем).
            if ((float)$s['fact'] > 0) {
                act_upsert($pdo, $rid, $s['sku'], $s['batch'], 'не_заявлен', (float)$s['fact']);
            }
            continue;
        }
        if ($s['state'] === 'shortage') {
            act_upsert($pdo, $rid, $s['sku'], $s['batch'], 'недостача', $s['dev']);
            $shortQty += abs((float)$s['dev']);
        } elseif ($s['state'] === 'excess') {
            act_upsert($pdo, $rid, $s['sku'], $s['batch'], 'излишек', $s['dev']);
            $excessQty += (float)$s['dev'];
        }
    }
    // Акт о браке — по факту операций «Брак» (тоже только при закрытии документа).
    try {
        $bq = $pdo->prepare(
            "SELECT sku, COALESCE(supplier_batch,'') AS batch, COALESCE(SUM(qty),0) AS q
             FROM receipt_operations WHERE receipt_id=:r AND accept_status='Брак'
             GROUP BY sku, COALESCE(supplier_batch,'')"
        );
        $bq->execute([':r' => $rid]);
        foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if ((float)$b['q'] > 0) {
                act_upsert($pdo, $rid, (string)$b['sku'], (string)$b['batch'], 'брак', (float)$b['q']);
            }
        }
    } catch (Throwable) {
    }
    $excessQty = round($excessQty, 3);
    $shortQty = round($shortQty, 3);
    $hasDev = ($excessQty > 0 || $shortQty > 0);
    $newStatus = $status ?: ($hasDev ? 'closed_short' : 'closed');
    // Комментарий документа: «излишек» / «недостача» / «излишек, недостача».
    $parts = [];
    if ($excessQty > 0) {
        $parts[] = 'излишек';
    }
    if ($shortQty > 0) {
        $parts[] = 'недостача';
    }
    $devText = implode(', ', $parts);
    $comment = trim((string)($head['comment'] ?? ''));
    if ($devText !== '' && strpos($comment, $devText) === false) {
        $comment = $comment === '' ? $devText : $comment . ' · ' . $devText;
    }
    $pdo->prepare("UPDATE receipts SET status=:st, accept_date=COALESCE(accept_date,datetime('now')), excess_qty=:ex, shortage_qty=:sh, comment=:cm WHERE id=:id")
        ->execute([':st' => $newStatus, ':ex' => $excessQty, ':sh' => $shortQty, ':cm' => ($comment === '' ? null : $comment), ':id' => $rid]);
    // Логируем в историю документа (вкладка «История» в модуле поступлений).
    try {
        $fio = $userFio;
        if ($userId) {
            $u = $pdo->prepare("SELECT fio FROM users WHERE id=:id");
            $u->execute([':id' => (int)$userId]);
            $v = $u->fetchColumn();
            if ($v !== false && $v !== null && $v !== '') {
                $fio = (string)$v;
            }
        }
        $pdo->prepare("INSERT INTO receipt_history (receipt_id,user_id,user_fio,action,field,old_value,new_value) VALUES (:r,:u,:f,'close','Статус',:o,:n)")
            ->execute([':r' => $rid, ':u' => $userId, ':f' => $fio, ':o' => (string)$head['status'], ':n' => $newStatus]);
        if ($devText !== '') {
            $pdo->prepare("INSERT INTO receipt_history (receipt_id,user_id,user_fio,action,field,old_value,new_value) VALUES (:r,:u,:f,'deviation','Отклонение','',:n)")
                ->execute([':r' => $rid, ':u' => $userId, ':f' => $fio,
                    ':n' => $devText . ' (излишек: ' . $excessQty . ', недостача: ' . $shortQty . ')']);
        }
    } catch (Throwable) {
    }
    $acts = $pdo->prepare("SELECT sku, supplier_batch, type, qty_diff, created_at FROM tsd_act_rasx WHERE receipt_id=:r ORDER BY id");
    $acts->execute([':r' => $rid]);
    $actRows = $acts->fetchAll();
    return [
        'status' => $newStatus,
        'status_label' => $hasDev ? 'Закрыто с отклонением' : 'Закрыто',
        'excess_qty' => $excessQty,
        'shortage_qty' => $shortQty,
        'deviation' => $devText,
        'act' => $actRows,
        'acts' => acts_summary($pdo, $rid, $actRows),
    ];
}
