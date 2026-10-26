<?php
declare(strict_types=1);

// Общая логика "Выпуск заданий на отбор" из Отгрузок.
// Волной: одна позиция (SKU) = одно объединённое задание по всем выбранным отгрузкам.
//   Условие: у всех отгрузок одинаковые ворота (иначе 400).
// Построчно (line): каждая строка shipment_items = отдельное задание.
//   Один артикул в двух отгрузках => два задания.
// Задания создаются в БД Заданий (tasks/task_items, тип "сбор") и видны:
//   - в модуле Задания (07-zadaniya / tasks)
//   - в ТСД Операция Отбор (tsd/tasks.php?type=сбор + tsd/otbor.html)
// Фильтр по роли в ТСД: водитель => уровень ячейки >= 2, кладовщик => уровень = 1.
// Уровень берётся из кода ячейки storage_cells.code формата Здание-Ряд-Уровень-Место
//   напр. A3-100-06-1 => уровень 6. Хранится в task_items.cell_from.

require_once __DIR__ . '/pg.php';

function wms_pick_root(): string
{
    if (function_exists('wms_root')) {
        return wms_root();
    }
    return dirname(__DIR__);
}

function wms_pick_cell_level(?string $cell): ?int
{
    if ($cell === null || $cell === '') {
        return null;
    }
    $c = trim($cell);
    // Формат A3-100-06-1 / C1-120-01-3 / М33-106-3-10
    $parts = preg_split('/[\-.\/]/u', $c);
    if ($parts && count($parts) >= 4) {
        // уровень — предпоследний сегмент (06, 01, 3 ...)
        $lvl = $parts[count($parts) - 2];
        if (is_numeric($lvl)) {
            return (int)$lvl;
        }
    }
    if (count($parts ?? []) === 3) {
        // М33-106-3: последний сегмент уровень
        $lvl = end($parts);
        if (is_numeric($lvl)) {
            return (int)$lvl;
        }
    }
    // ВОРОТА-11 / СКЛАД-1 / Приемка_9 — уровня нет
    return null;
}

function wms_pick_role_allows(?string $cell, string $role): bool
{
    $lvl = wms_pick_cell_level($cell);
    if ($lvl === null) {
        // Без уровня (ворота/приёмка/старые данные) — показываем обеим ролям,
        // чтобы не потерять старые задания.
        return true;
    }
    if ($role === 'driver') {
        return $lvl >= 2;
    }
    // storekeeper и прочие — уровень 1
    return $lvl === 1;
}

/** @return PDO[] каноническая БД заданий (tasks/wms_tasks.sqlite через shared/db.php) */
function wms_tasks_pdos(): array
{
    // Режим PostgreSQL: единая БД wms вместо sqlite-файлов.
    if (function_exists('wms_use_pgsql') && wms_use_pgsql() && function_exists('wms_tasks_pdo')) {
        try {
            return [wms_tasks_pdo()];
        } catch (Throwable) {
        }
    }
    $out = [];
    // Канон — 07-zadaniya/wms_tasks.sqlite (см. shared/db.php::wms_tasks_db_file()).
    foreach ([wms_tasks_db_file()] as $f) {
        try {
            if (!is_file($f)) {
                continue;
            }
            $pdo = new PDO('sqlite:' . $f, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $out[] = $pdo;
        } catch (Throwable) {
        }
    }
    return $out;
}

/** Определение имён таблиц заданий: demo (tasks/...) или prod (wms_tasks/...). */
function wms_task_tables(PDO $pdo): array
{
    foreach ([['tasks', 'task_items', 'task_history'], ['wms_tasks', 'wms_task_items', 'wms_task_history']] as $cand) {
        try {
            $pdo->query("SELECT 1 FROM {$cand[0]} LIMIT 1");
            return ['t' => $cand[0], 'i' => $cand[1], 'h' => $cand[2]];
        } catch (Throwable) {
        }
    }
    return ['t' => 'tasks', 'i' => 'task_items', 'h' => 'task_history'];
}

function wms_task_has_col(PDO $pdo, string $table, string $col): bool
{
    try {
        if (function_exists('wms_table_columns')) {
            return in_array($col, wms_table_columns($pdo, $table), true);
        }
        $drv = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($drv === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (($r['name'] ?? '') === $col) {
                    return true;
                }
            }
            return false;
        }
        $q = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :c");
        $q->execute([':c' => $col]);
        return (bool)$q->fetch();
    } catch (Throwable) {
        return false;
    }
}

function wms_ensure_task_pick_cols(PDO $pdo, ?string $table = null): void
{
    if ($table === null) {
        $table = wms_task_tables($pdo)['t'];
    }
    foreach (['pick_mode' => 'pick_mode TEXT', 'gate' => 'gate TEXT', 'shipment_ids' => 'shipment_ids TEXT'] as $col => $ddl) {
        if (!wms_task_has_col($pdo, $table, $col)) {
            try {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $ddl");
            } catch (Throwable) {
            }
        }
    }
}

/**
 * Резолв ячейки отбора для SKU: сначала реальный остаток (cell_content),
 * иначе — любая ячейка склада (round-robin, чтобы разложить уровни 1 и 2+).
 * $exclude — код ячейки, который нельзя предлагать (только что заблокированная).
 */
function wms_pick_resolve_cell(PDO $storagePdo, string $sku, int $warehouseId, int $seedIdx, string $exclude = ''): string
{
    $exclude = trim($exclude);
    try {
        $st = $storagePdo->prepare(
            "SELECT sc.code FROM cell_content cc JOIN storage_cells sc ON sc.id = cc.cell_id
             WHERE cc.sku = :sku AND sc.warehouse_id = :wh AND sc.is_archived = 0 AND sc.is_locked = 0
               AND sc.code <> :ex
             ORDER BY cc.qty DESC LIMIT 1"
        );
        $st->execute([':sku' => $sku, ':wh' => $warehouseId, ':ex' => $exclude]);
        $code = $st->fetchColumn();
        if ($code) {
            return (string)$code;
        }
    } catch (Throwable) {
    }
    try {
        $rows = $storagePdo->prepare(
            "SELECT code FROM storage_cells WHERE warehouse_id = :wh AND is_archived = 0 AND is_locked = 0 AND code <> :ex ORDER BY code"
        );
        $rows->execute([':wh' => $warehouseId, ':ex' => $exclude]);
        $all = $rows->fetchAll(PDO::FETCH_COLUMN);
        if ($all) {
            return (string)$all[$seedIdx % count($all)];
        }
    } catch (Throwable) {
    }
    return 'СКЛАД-' . $warehouseId;
}

// ============================================================
// РЕЗЕРВИРОВАНИЕ: принцип подбора партий (FIFO/FEFO/LIFO) по настройке
// клиента, пропуск заблокированных/отбракованных партий, ручное резервирование.
// ============================================================

/** Допустимые принципы резервирования (совпадают с WmsCounterparties::STRATEGIES). */
function wms_pick_strategies(): array
{
    return ['FIFO', 'FEFO', 'LIFO'];
}

/**
 * Строка в нижнем регистре с учётом кириллицы.
 * mbstring в сборке может отсутствовать — приводим А-Я/Ё вручную (strtr + карта).
 */
function wms_pick_lower(string $s): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        for ($i = 0; $i < 32; $i++) {
            $map[chr(0xD0) . chr(0x90 + $i)] = chr(0xD0) . chr(0xB0 + $i); // А-Я → а-я
        }
        $map[chr(0xD0) . chr(0x81)] = chr(0xD1) . chr(0x91);              // Ё → ё
    }
    return strtr(strtolower($s), $map);
}

/**
 * Принцип резервирования клиента отгрузки (FIFO по умолчанию).
 * Настройка — counterparties.reserve_strategy (см. shared/Counterparties.php).
 */
function wms_pick_reserve_strategy(PDO $pdo, ?int $contractorId): string
{
    $s = 'FIFO';
    try {
        require_once wms_pick_root() . '/shared/Counterparties.php';
        $s = strtoupper(trim((string)WmsCounterparties::reserveStrategy($pdo, $contractorId)));
    } catch (Throwable) {
        $s = 'FIFO';
    }
    return in_array($s, wms_pick_strategies(), true) ? $s : 'FIFO';
}

/**
 * Принцип резервирования для группы отгрузок (одна позиция = одно задание).
 * Берётся у клиента первой отгрузки группы; в волне с разными клиентами
 * действует принцип первой отгрузки (фиксируется в комментарии задания).
 * @return array{strategy:string,contractor_id:?int,doc_no:string}
 */
function wms_pick_group_strategy(PDO $pdo, array $heads, array $shipmentIds): array
{
    foreach ($shipmentIds as $sid) {
        $h = $heads[(int)$sid] ?? null;
        if (!is_array($h)) {
            continue;
        }
        $cid = (isset($h['contractor_id']) && $h['contractor_id'] !== '' && $h['contractor_id'] !== null)
            ? (int)$h['contractor_id'] : null;
        return [
            'strategy' => wms_pick_reserve_strategy($pdo, $cid),
            'contractor_id' => $cid,
            'doc_no' => (string)($h['doc_no'] ?? ''),
        ];
    }
    return ['strategy' => 'FIFO', 'contractor_id' => null, 'doc_no' => ''];
}

/**
 * Индекс заблокированных партий товара (отбраковка и блокировки приёмки).
 *
 * Источники блокировки:
 *   - receipt_operations.reject_reason — причина отбраковки строки приёмки;
 *   - receipt_operations.accept_status = «Брак» — строка принята как брак;
 *   - receipt_flags.status <> 'годный' либо receipt_flags.reason — блокировка.
 *
 * Блокировка действует на партию (паллету), если паллета указана, иначе —
 * на всю приёмку (receipt_id) по этому артикулу.
 *
 * @return array{
 *   rejected_pallets: array<string,array<int,array{rid:int,text:string}>>,
 *   rejected_receipts: array<int,array<int,array{rid:int,text:string}>>,
 *   flag_pallets: array<string,array<int,array{rid:int,text:string}>>,
 *   flag_receipts: array<int,array<int,array{rid:int,text:string}>>,
 *   reasons: string[]
 * }
 */
function wms_pick_blocked_index(PDO $pdo, string $sku, ?int $warehouseId = null): array
{
    $idx = [
        'rejected_pallets' => [], 'rejected_receipts' => [],
        'flag_pallets' => [], 'flag_receipts' => [],
        'reasons' => [],
    ];
    $sku = trim($sku);
    if ($sku === '') {
        return $idx;
    }
    // --- Строки приёмки: отбраковка / брак ---
    try {
        $st = $pdo->prepare(
            "SELECT receipt_id, pallet, reject_reason, reason, accept_status
             FROM receipt_operations WHERE sku = :sku"
        );
        $st->execute([':sku' => $sku]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reject = trim((string)($r['reject_reason'] ?? ''));
            $status = trim((string)($r['accept_status'] ?? ''));
            $brak = ($status !== '' && strpos(wms_pick_lower($status), 'брак') !== false);
            if ($reject === '' && !$brak) {
                continue;
            }
            $why = $reject !== '' ? $reject : (trim((string)($r['reason'] ?? '')) ?: $status);
            $text = 'Отбраковка при приёмке' . ($why !== '' ? ': ' . $why : '');
            $pal = trim((string)($r['pallet'] ?? ''));
            $rid = (int)($r['receipt_id'] ?? 0);
            if ($pal !== '') {
                $idx['rejected_pallets'][$pal][] = ['rid' => $rid, 'text' => $text];
            } elseif ($rid > 0) {
                $idx['rejected_receipts'][$rid][] = ['rid' => $rid, 'text' => $text];
            }
            $idx['reasons'][] = $text;
        }
    } catch (Throwable) {
    }
    // --- Флаги приёмки: негоден / причина ---
    try {
        $st = $pdo->prepare(
            "SELECT receipt_id, pallet, status, reason FROM receipt_flags WHERE sku = :sku"
        );
        $st->execute([':sku' => $sku]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $status = trim((string)($r['status'] ?? ''));
            $why = trim((string)($r['reason'] ?? ''));
            // «годный» без причины — не блокирует; иначе статус <> 'годный' или причина.
            if (wms_pick_lower($status) === 'годный' && $why === '') {
                continue;
            }
            $text = 'Блокировка при приёмке'
                . ($status !== '' && wms_pick_lower($status) !== 'годный' ? ' (' . $status . ')' : '')
                . ($why !== '' ? ': ' . $why : '');
            $pal = trim((string)($r['pallet'] ?? ''));
            $rid = (int)($r['receipt_id'] ?? 0);
            if ($pal !== '') {
                $idx['flag_pallets'][$pal][] = ['rid' => $rid, 'text' => $text];
            } elseif ($rid > 0) {
                $idx['flag_receipts'][$rid][] = ['rid' => $rid, 'text' => $text];
            }
            $idx['reasons'][] = $text;
        }
    } catch (Throwable) {
    }
    // --- Ограничение по складу: в подбор идут только партии этого склада ---
    if ($warehouseId !== null && $warehouseId > 0) {
        $allowed = null;
        try {
            $st = $pdo->prepare("SELECT id FROM receipts WHERE warehouse_id = :w");
            $st->execute([':w' => $warehouseId]);
            $allowed = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            $allowed = null;
        }
        if ($allowed !== null) {
            $keep = function (array $entries) use ($allowed): array {
                $out = [];
                foreach ($entries as $e) {
                    if ($e['rid'] > 0 && !in_array((int)$e['rid'], $allowed, true)) {
                        continue;
                    }
                    $out[] = $e;
                }
                return $out;
            };
            foreach (['rejected_pallets', 'flag_pallets'] as $k) {
                foreach ($idx[$k] as $pal => $entries) {
                    $idx[$k][$pal] = $keep($entries);
                }
            }
            foreach (['rejected_receipts', 'flag_receipts'] as $k) {
                foreach ($idx[$k] as $rid => $entries) {
                    $idx[$k][$rid] = $keep($entries);
                }
            }
            $reasons = [];
            foreach (['rejected_pallets', 'flag_pallets'] as $k) {
                foreach ($idx[$k] as $entries) {
                    foreach ($entries as $e) {
                        $reasons[] = $e['text'];
                    }
                }
            }
            foreach (['rejected_receipts', 'flag_receipts'] as $k) {
                foreach ($idx[$k] as $entries) {
                    foreach ($entries as $e) {
                        $reasons[] = $e['text'];
                    }
                }
            }
            $idx['reasons'] = array_values(array_unique($reasons));
        }
    }
    return $idx;
}

/**
 * Причины, по которым товар нельзя резервировать (отбраковка/блокировки приёмки).
 * Общий механизм пропуска партий: используется и при подборе под отбор,
 * и при ручном резервировании.
 *
 * @param PDO $pdo           БД поступлений (receipt_operations, receipt_flags)
 * @param string $sku        артикул
 * @param int|null $warehouseId склад (ограничивает приёмки; если таблица недоступна — игнорируется)
 * @param int|null $receiptId   приёмка — точная партия
 * @param string|null $pallet    паллета — точная партия
 * @return string[] причины (пустой список = товар не заблокирован)
 */
function wms_pick_blocked_reasons(PDO $pdo, string $sku, ?int $warehouseId = null, ?int $receiptId = null, ?string $pallet = null): array
{
    $idx = wms_pick_blocked_index($pdo, $sku, $warehouseId);
    $out = [];
    $pal = trim((string)($pallet ?? ''));
    if ($pal !== '') {
        foreach (['rejected_pallets', 'flag_pallets'] as $k) {
            foreach ($idx[$k][$pal] ?? [] as $e) {
                $out[] = $e['text'];
            }
        }
    }
    if ($receiptId !== null && $receiptId > 0) {
        foreach (['rejected_receipts', 'flag_receipts'] as $k) {
            foreach ($idx[$k][$receiptId] ?? [] as $e) {
                $out[] = $e['text'];
            }
        }
    }
    if ($pal === '' && ($receiptId === null || $receiptId <= 0)) {
        $out = $idx['reasons'];
    }
    return array_values(array_unique($out));
}

/** Причины блокировки конкретной партии (по индексу wms_pick_blocked_index). */
function wms_pick_batch_blocked(array $idx, ?int $receiptId, ?string $pallet): array
{
    $out = [];
    $pal = trim((string)($pallet ?? ''));
    if ($pal !== '') {
        foreach (['rejected_pallets', 'flag_pallets'] as $k) {
            foreach ($idx[$k][$pal] ?? [] as $e) {
                $out[] = $e['text'];
            }
        }
    }
    if ($receiptId !== null && $receiptId > 0) {
        foreach (['rejected_receipts', 'flag_receipts'] as $k) {
            foreach ($idx[$k][$receiptId] ?? [] as $e) {
                $out[] = $e['text'];
            }
        }
    }
    return array_values(array_unique($out));
}

/** Даты приёмки партий (receipt_id => дата приёмки/документа). */
function wms_pick_receipt_dates(?PDO $rcpPdo, array $receiptIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $receiptIds), fn($v) => $v > 0)));
    $out = [];
    if (!$ids || !$rcpPdo) {
        return $out;
    }
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $rcpPdo->prepare("SELECT id, doc_date, accept_date FROM receipts WHERE id IN ($in)");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = trim((string)($r['accept_date'] ?? ''));
            if ($d === '') {
                $d = trim((string)($r['doc_date'] ?? ''));
            }
            $out[(int)$r['id']] = $d !== '' ? $d : null;
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Партии товара (product_stock) по складу с пометкой блокировок.
 * Сортировка — по принципу резервирования:
 *   FIFO — по дате приёмки (раньше принятая партия first), без даты — по id партии;
 *   FEFO — по дате производства (prod_date, запасная — pallet_date);
 *   LIFO — по дате приёмки в обратном порядке.
 *
 * @return array[] ['id','cell','pallet','qty','prod_date','pallet_date','receipt_id',
 *                   'accept_date','blocked'=>string[],'locked'=>bool,'lock_reason'=>string,'usable'=>bool]
 */
function wms_pick_stock_candidates(
    PDO $nomPdo,
    ?PDO $storagePdo,
    ?PDO $rcpPdo,
    string $sku,
    int $warehouseId,
    string $strategy = 'FIFO'
): array {
    $sku = trim($sku);
    if ($sku === '') {
        return [];
    }
    $rows = [];
    try {
        $sql = "SELECT s.id, s.cell, s.pallet, s.pallet_type, s.supplier_batch, s.pallet_date, s.prod_date,
                       s.qty, s.warehouse_id, s.receipt_id, s.receipt_no,
                       p.name AS product_name, p.unit
                FROM product_stock s JOIN products p ON p.id = s.product_id
                WHERE p.sku = :sku AND COALESCE(s.qty,0) > 0";
        $params = [':sku' => $sku];
        if ($warehouseId > 0) {
            $sql .= ' AND s.warehouse_id = :wh';
            $params[':wh'] = $warehouseId;
        }
        $sql .= ' ORDER BY s.id';
        $st = $nomPdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
    if (!$rows) {
        return [];
    }
    // --- Заблокированные ячейки склада + приоритет зон резервирования ---
    $cells = [];
    $reserveZones = [];
    if ($storagePdo) {
        try {
            $st = $storagePdo->prepare(
                "SELECT code, is_locked, lock_reason, is_archived, zone FROM storage_cells WHERE warehouse_id = :w"
            );
            $st->execute([':w' => $warehouseId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $cells[strtoupper(trim((string)($c['code'] ?? '')))] = $c;
            }
        } catch (Throwable) {
        }
        try {
            require_once __DIR__ . '/StorageZones.php';
            foreach (wms_storage_zone_rows($storagePdo, true) as $z) {
                if ((int)($z['affects_reservation'] ?? 0) === 1) {
                    $reserveZones[strtolower((string)$z['code'])] = true;
                }
            }
        } catch (Throwable) {
        }
    }
    // --- Причины блокировки партий (отбраковка/флаги приёмки) ---
    $idx = [];
    try {
        $src = $rcpPdo ?: $nomPdo;
        $idx = wms_pick_blocked_index($src, $sku, $warehouseId > 0 ? $warehouseId : null);
    } catch (Throwable) {
        $idx = [];
    }
    // --- Даты приёмки партий (FIFO/LIFO) ---
    $receiptIds = [];
    foreach ($rows as $r) {
        $rid = (int)($r['receipt_id'] ?? 0);
        if ($rid > 0) {
            $receiptIds[] = $rid;
        }
    }
    $dates = wms_pick_receipt_dates($rcpPdo, $receiptIds);

    $out = [];
    foreach ($rows as $r) {
        $cell = trim((string)($r['cell'] ?? ''));
        $pal = trim((string)($r['pallet'] ?? ''));
        $rid = (int)($r['receipt_id'] ?? 0);
        $blocked = wms_pick_batch_blocked($idx, $rid > 0 ? $rid : null, $pal !== '' ? $pal : null);
        $c = $cells[strtoupper($cell)] ?? null;
        $locked = false;
        $lockReason = '';
        if ($c) {
            $locked = ((int)($c['is_locked'] ?? 0) === 1);
            $lockReason = (string)($c['lock_reason'] ?? '');
        }
        $out[] = [
            'id' => (int)($r['id'] ?? 0),
            'cell' => $cell,
            'pallet' => $pal,
            'pallet_type' => (string)($r['pallet_type'] ?? ''),
            'supplier_batch' => (string)($r['supplier_batch'] ?? ''),
            'qty' => (float)($r['qty'] ?? 0),
            'prod_date' => trim((string)($r['prod_date'] ?? '')),
            'pallet_date' => trim((string)($r['pallet_date'] ?? '')),
            'receipt_id' => $rid,
            'receipt_no' => (string)($r['receipt_no'] ?? ''),
            'accept_date' => $rid > 0 ? ($dates[$rid] ?? null) : null,
            'product_name' => (string)($r['product_name'] ?? ''),
            'unit' => (string)($r['unit'] ?? ''),
            'blocked' => $blocked,
            'locked' => $locked,
            'lock_reason' => $lockReason,
            'zone_reserve' => isset($reserveZones[strtolower(trim((string)($c['zone'] ?? '')))]) ? 0 : 1,
            'usable' => (!$locked && !$blocked),
        ];
    }

    // --- Сортировка по принципу резервирования ---
    $strategy = strtoupper(trim($strategy));
    if (!in_array($strategy, wms_pick_strategies(), true)) {
        $strategy = 'FIFO';
    }
    usort($out, function (array $a, array $b) use ($strategy): int {
        // Приоритет зон, влияющих на резервирование (0 — приоритетная).
        $az = (int)($a['zone_reserve'] ?? 1);
        $bz = (int)($b['zone_reserve'] ?? 1);
        if ($az !== $bz) {
            return $az <=> $bz;
        }
        if ($strategy === 'FEFO') {
            $ka = $a['prod_date'] !== '' ? $a['prod_date'] : $a['pallet_date'];
            $kb = $b['prod_date'] !== '' ? $b['prod_date'] : $b['pallet_date'];
            if ($ka === '' && $kb === '') {
                return $a['id'] <=> $b['id'];
            }
            if ($ka === '') {
                return 1;   // без даты — в конец
            }
            if ($kb === '') {
                return -1;
            }
            $c = strcmp($ka, $kb);
            return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
        }
        $ka = (string)($a['accept_date'] ?? '');
        $kb = (string)($b['accept_date'] ?? '');
        if ($strategy === 'LIFO') {
            if ($ka === '' && $kb === '') {
                return $b['id'] <=> $a['id'];
            }
            if ($ka === '') {
                return 1;   // без даты приёмки — в конец
            }
            if ($kb === '') {
                return -1;
            }
            $c = strcmp($kb, $ka);
            return $c !== 0 ? $c : ($b['id'] <=> $a['id']);
        }
        // FIFO
        if ($ka === '' && $kb === '') {
            return $a['id'] <=> $b['id'];
        }
        if ($ka === '') {
            return 1;
        }
        if ($kb === '') {
            return -1;
        }
        $c = strcmp($ka, $kb);
        return $c !== 0 ? $c : ($a['id'] <=> $b['id']);
    });

    return $out;
}

/**
 * Подбор партии/ячейки под отбор с учётом принципа резервирования клиента.
 * Пропускаются заблокированные ячейки и отбракованные/заблокированные партии.
 *
 * @return array{ok:bool,cell:string,pallet:?string,stock_id:?int,available:float,
 *               need:float,reason:string,strategy:string,blocked:string[],candidates:int}
 */
function wms_pick_match_stock(
    PDO $nomPdo,
    ?PDO $storagePdo,
    ?PDO $rcpPdo,
    string $sku,
    int $warehouseId,
    float $need,
    string $strategy = 'FIFO'
): array {
    $res = [
        'ok' => false, 'cell' => '', 'pallet' => null, 'stock_id' => null,
        'available' => 0.0, 'need' => $need, 'reason' => '',
        'strategy' => $strategy, 'blocked' => [], 'candidates' => 0,
    ];
    $cands = wms_pick_stock_candidates($nomPdo, $storagePdo, $rcpPdo, $sku, $warehouseId, $strategy);
    $res['candidates'] = count($cands);
    if (!$cands) {
        $res['reason'] = 'Товар не найден на складе: нет остатков';
        return $res;
    }
    $usable = [];
    $blocked = [];
    $locked = [];
    foreach ($cands as $c) {
        if ($c['usable']) {
            $usable[] = $c;
            continue;
        }
        foreach ($c['blocked'] as $t) {
            $blocked[] = $t;
        }
        if ($c['locked']) {
            $locked[] = 'ячейка ' . $c['cell'] . ' заблокирована' . ($c['lock_reason'] !== '' ? ' (' . $c['lock_reason'] . ')' : '');
        }
    }
    $available = 0.0;
    foreach ($usable as $c) {
        $available += (float)$c['qty'];
    }
    $res['available'] = round($available, 3);
    $res['blocked'] = array_values(array_unique(array_merge($blocked, $locked)));
    if (!$usable) {
        $why = array_slice($res['blocked'], 0, 3);
        $res['reason'] = 'Товар заблокирован: ' . ($why ? implode('; ', $why) : 'партии недоступны для отбора');
        return $res;
    }
    if ($need > 0 && $available + 1e-9 < $need) {
        $res['reason'] = 'Недостаточно остатка: доступно ' . round($available, 3) . ', требуется ' . round($need, 3);
        return $res;
    }
    $pick = $usable[0];
    $res['ok'] = true;
    $res['cell'] = (string)$pick['cell'];
    $res['pallet'] = $pick['pallet'] !== '' ? $pick['pallet'] : null;
    $res['stock_id'] = (int)$pick['id'];
    return $res;
}

/**
 * «Проблема с товаром» (Брак / Нет товара): ячейка реально блокируется
 * (is_locked + причина), оператору подбирается другая ячейка для товара.
 * Раньше писалась только запись в журнал и в историю, а ответ утверждал,
 * что ячейка заблокирована и новое резервирование создано.
 * @return array{blocked:bool, cell:string, reason:string}
 */
function wms_block_cell_and_reserve(PDO $storagePdo, string $cell, string $sku, int $warehouseId, string $reason): array
{
    $out = ['blocked' => false, 'cell' => '', 'reason' => $reason];
    $cell = trim($cell);
    if ($cell !== '') {
        try {
            $now = wms_is_sqlite($storagePdo) ? "datetime('now')" : 'NOW()';
            $st = $storagePdo->prepare(
                "UPDATE storage_cells SET is_locked = 1, lock_reason = :r, updated_at = $now
                 WHERE code = :c AND warehouse_id = :w AND COALESCE(is_archived,0) = 0"
            );
            $st->execute([':r' => $reason, ':c' => $cell, ':w' => $warehouseId]);
            $out['blocked'] = $st->rowCount() > 0;
        } catch (Throwable) {
        }
    }
    // Подбор замены: другая ячейка с этим товаром (заблокированная исключена).
    try {
        $new = wms_pick_resolve_cell($storagePdo, $sku, $warehouseId, 0, $cell);
        $out['cell'] = ($new !== '' && $new !== $cell) ? $new : '';
    } catch (Throwable) {
    }
    return $out;
}

function wms_pick_next_doc_no(PDO $pdo): string
{
    $tbl = wms_task_tables($pdo)['t'];
    $prefix = 'TASK-' . date('Ym') . '-';
    try {
        $st = $pdo->prepare("SELECT doc_no FROM $tbl WHERE doc_no LIKE :p ORDER BY doc_no DESC LIMIT 1");
        $st->execute([':p' => $prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        if (preg_match('/(\d+)$/', $last, $m)) {
            return $prefix . str_pad((string)(((int)$m[1]) + 1), 5, '0', STR_PAD_LEFT);
        }
    } catch (Throwable) {
    }
    try {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM $tbl")->fetchColumn();
        return $prefix . str_pad((string)($c + 2456), 5, '0', STR_PAD_LEFT);
    } catch (Throwable) {
        return $prefix . '02456';
    }
}

/**
 * Создать задание на отбор с позициями (одно задание = одна пачка строк).
 * Общий помощник: используется и выпуском заданий, и ручным резервированием.
 *
 * @param PDO $tPdo БД заданий
 * @param array $d doc_no, type, status, priority, warehouse_id, doc_ref, shipment_ids[],
 *                 gate, pick_mode, plan_qty, cells_count, flags, comment, due_date,
 *                 user_id, items[] = [code, sku, name, unit, cell_from, pallet, plan_qty]
 * @return array{id:int,doc_no:string}
 */
function wms_pick_insert_task(PDO $tPdo, array $d): array
{
    $tbl = wms_task_tables($tPdo);
    wms_ensure_task_pick_cols($tPdo, $tbl['t']);
    $isSqlite = $tPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $nowFn = $isSqlite ? "datetime('now')" : 'NOW()';
    $hasPm = wms_task_has_col($tPdo, $tbl['t'], 'pick_mode');
    $hasGate = wms_task_has_col($tPdo, $tbl['t'], 'gate');
    $hasSids = wms_task_has_col($tPdo, $tbl['t'], 'shipment_ids');
    $cols = ['doc_no', 'type', 'status', 'priority', 'warehouse_id', 'doc_ref',
        'executor_id', 'executor_fio', 'plan_qty', 'cells_count', 'volume', 'weight',
        'equipment', 'flags', 'need_check', 'comment', 'due_date'];
    $vals = [':no', ':ty', ':st', ':pri', ':wh', ':doc',
        'NULL', 'NULL', ':pq', ':cc', '0', '0',
        'NULL', ':flg', '0', ':cmt', ':due'];
    $params = [
        ':no' => (string)($d['doc_no'] ?? ''),
        ':ty' => (string)($d['type'] ?? 'сбор'),
        ':st' => (string)($d['status'] ?? 'new'),
        ':pri' => (string)($d['priority'] ?? 'normal'),
        ':wh' => (int)($d['warehouse_id'] ?? 1),
        ':doc' => (string)($d['doc_ref'] ?? ''),
        ':pq' => (float)($d['plan_qty'] ?? 0),
        ':cc' => (int)($d['cells_count'] ?? 1),
        ':flg' => (string)($d['flags'] ?? 'pick'),
        ':cmt' => (string)($d['comment'] ?? ''),
        ':due' => $d['due_date'] ?? null,
    ];
    if ($hasPm) {
        $cols[] = 'pick_mode';
        $vals[] = ':pm';
        $params[':pm'] = (string)($d['pick_mode'] ?? '');
    }
    if ($hasGate) {
        $cols[] = 'gate';
        $vals[] = ':gate';
        $params[':gate'] = $d['gate'] ?? null;
    }
    if ($hasSids) {
        $cols[] = 'shipment_ids';
        $vals[] = ':sids';
        $params[':sids'] = implode(',', array_map('intval', (array)($d['shipment_ids'] ?? [])));
    }
    $cols[] = 'created_at';
    $vals[] = $nowFn;
    $t = $tbl['t'];
    $st = $tPdo->prepare("INSERT INTO $t (" . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
    $st->execute($params);
    $tid = (int)$tPdo->lastInsertId();
    $ti = $tbl['i'];
    foreach ((array)($d['items'] ?? []) as $it) {
        $tPdo->prepare(
            "INSERT INTO $ti (task_id,code,sku,name,unit,cell_from,cell_to,pallet,plan_qty,fact_qty,state,executor_fio)
             VALUES (:t,:code,:sku,:name,:unit,:cf,NULL,:pal,:pq,0,NULL,NULL)"
        )->execute([
            ':t' => $tid,
            ':code' => ($it['code'] ?? '') !== '' ? $it['code'] : null,
            ':sku' => (string)($it['sku'] ?? ''),
            ':name' => (string)($it['name'] ?? ($it['sku'] ?? '')),
            ':unit' => (string)($it['unit'] ?? 'шт'),
            ':cf' => (string)($it['cell_from'] ?? ''),
            ':pal' => ($it['pallet'] ?? '') !== '' ? (string)$it['pallet'] : null,
            ':pq' => (float)($it['plan_qty'] ?? 0),
        ]);
    }
    try {
        $th = $tbl['h'];
        $tPdo->prepare(
            "INSERT INTO $th (task_id,user_id,action,field,old_value,new_value) VALUES (?,?,?,?,?,?)"
        )->execute([
            $tid, $d['user_id'] ?? null, 'create', null, null,
            (string)($d['doc_no'] ?? '') . ' (' . (string)($d['comment'] ?? '') . ')',
        ]);
    } catch (Throwable) {
    }
    return ['id' => $tid, 'doc_no' => (string)($d['doc_no'] ?? '')];
}

/**
 * Текущий резерв по открытым заданиям на отбор (остаток plan_qty − fact_qty).
 * Используется для расчёта доступного остатка при ручном резервировании.
 *
 * @return array[] ['sku','cell','pallet','qty','task_id','doc_no','shipment_ids'=>int[],'doc_nos'=>string[]]
 */
function wms_pick_reserve_rows(PDO $tPdo, array $skus): array
{
    $skus = array_values(array_unique(array_filter(array_map(
        fn($s) => trim((string)$s),
        $skus
    ), fn($s) => $s !== '')));
    if (!$skus) {
        return [];
    }
    $tbl = wms_task_tables($tPdo);
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($skus), '?'));
        $st = $tPdo->prepare(
            "SELECT i.sku, i.cell_from, i.pallet, i.plan_qty, i.fact_qty,
                    t.id AS task_id, t.doc_no, t.shipment_ids, t.doc_ref
             FROM {$tbl['i']} i JOIN {$tbl['t']} t ON t.id = i.task_id
             WHERE i.sku IN ($in) AND t.flags LIKE '%pick%' AND t.status IN ('new','work','paused')"
        );
        $st->execute($skus);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $need = (float)($r['plan_qty'] ?? 0) - (float)($r['fact_qty'] ?? 0);
            if ($need <= 0) {
                continue;
            }
            $sids = [];
            foreach (explode(',', (string)($r['shipment_ids'] ?? '')) as $v) {
                $v = trim($v);
                if ($v !== '' && is_numeric($v)) {
                    $sids[] = (int)$v;
                }
            }
            $docs = array_values(array_filter(array_map('trim', explode(',', (string)($r['doc_ref'] ?? ''))), fn($v) => $v !== ''));
            $out[] = [
                'sku' => (string)($r['sku'] ?? ''),
                'cell' => trim((string)($r['cell_from'] ?? '')),
                'pallet' => trim((string)($r['pallet'] ?? '')),
                'qty' => $need,
                'task_id' => (int)($r['task_id'] ?? 0),
                'doc_no' => (string)($r['doc_no'] ?? ''),
                'shipment_ids' => $sids,
                'doc_nos' => $docs,
            ];
        }
    } catch (Throwable) {
    }
    return $out;
}

/** Резерв по строке партии (SKU + ячейка + паллета). */
function wms_pick_reserved_for_row(array $rows, string $sku, string $cell, string $pallet, ?int $shipmentId = null, string $docNo = ''): float
{
    $sum = 0.0;
    foreach ($rows as $r) {
        if (strcasecmp((string)$r['sku'], $sku) !== 0) {
            continue;
        }
        if (trim((string)$r['cell']) !== trim($cell)) {
            continue;
        }
        $rp = trim((string)($r['pallet'] ?? ''));
        if ($rp !== '' && $pallet !== '' && strcasecmp($rp, $pallet) !== 0) {
            continue;
        }
        if ($shipmentId !== null && $shipmentId > 0) {
            $mine = in_array((int)$shipmentId, $r['shipment_ids'], true)
                || ($docNo !== '' && in_array($docNo, $r['doc_nos'], true));
            if (!$mine) {
                continue;
            }
        }
        $sum += (float)$r['qty'];
    }
    return $sum;
}

/** Резерв по артикулу (весь или по конкретной отгрузке). */
function wms_pick_reserved_for_sku(array $rows, string $sku, ?int $shipmentId = null, string $docNo = ''): float
{
    $sum = 0.0;
    foreach ($rows as $r) {
        if (strcasecmp((string)$r['sku'], $sku) !== 0) {
            continue;
        }
        if ($shipmentId !== null && $shipmentId > 0) {
            $mine = in_array((int)$shipmentId, $r['shipment_ids'], true)
                || ($docNo !== '' && in_array($docNo, $r['doc_nos'], true));
            if (!$mine) {
                continue;
            }
        }
        $sum += (float)$r['qty'];
    }
    return $sum;
}

/**
 * Строки отгрузки для ручного резервирования: потребность, резерв, остаток.
 *
 * @return array[] ['sku','name','unit','need','order_qty','limit','reserved',
 *                  'reserved_shipment','stock','max_new']
 */
function wms_pick_reserve_lines(PDO $shipPdo, PDO $nomPdo, ?PDO $storagePdo, PDO $taskPdo, int $shipmentId): array
{
    $shipmentId = (int)$shipmentId;
    if ($shipmentId <= 0) {
        throw new RuntimeException('Отгрузка не найдена');
    }
    $head = null;
    try {
        $st = $shipPdo->prepare('SELECT id, doc_no, warehouse_id, contractor_id, status FROM shipments WHERE id = :id');
        $st->execute([':id' => $shipmentId]);
        $head = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
        $head = null;
    }
    if (!$head) {
        throw new RuntimeException('Отгрузка не найдена');
    }
    $wh = (int)($head['warehouse_id'] ?? 1) ?: 1;
    $docNo = (string)($head['doc_no'] ?? '');
    $items = [];
    try {
        $st = $shipPdo->prepare('SELECT sku, name, unit, qty, order_id FROM shipment_items WHERE shipment_id = :s ORDER BY id');
        $st->execute([':s' => $shipmentId]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $items = [];
    }
    $lines = [];
    $orderIds = [];
    foreach ($items as $it) {
        $sku = trim((string)($it['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        if (!isset($lines[$sku])) {
            $lines[$sku] = [
                'sku' => $sku,
                'name' => (string)($it['name'] ?? $sku),
                'unit' => (string)($it['unit'] ?? 'шт'),
                'need' => 0.0,
                'order_qty' => 0.0,
                'limit' => 0.0,
                'reserved' => 0.0,
                'reserved_shipment' => 0.0,
                'stock' => 0.0,
                'max_new' => 0.0,
            ];
        }
        $lines[$sku]['need'] += (float)($it['qty'] ?? 0);
        $oid = (int)($it['order_id'] ?? 0);
        if ($oid > 0) {
            $orderIds[$oid] = true;
        }
    }
    // Расход по заказам (client_order_items) — ограничивает ручной резерв.
    if ($orderIds) {
        try {
            $oids = array_keys($orderIds);
            $in = implode(',', array_fill(0, count($oids), '?'));
            $st = $shipPdo->prepare("SELECT sku, SUM(qty) AS q FROM client_order_items WHERE order_id IN ($in) GROUP BY sku");
            $st->execute($oids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sku = trim((string)($r['sku'] ?? ''));
                if ($sku !== '' && isset($lines[$sku])) {
                    $lines[$sku]['order_qty'] += (float)($r['q'] ?? 0);
                }
            }
        } catch (Throwable) {
        }
    }
    $skus = array_keys($lines);
    $reserveRows = $taskPdo ? wms_pick_reserve_rows($taskPdo, $skus) : [];
    // Остаток на складе (без заблокированных ячеек и брака).
    $stockMap = [];
    if ($skus) {
        $blockedIdx = [];
        $rcpPdo = null;
        try {
            $rcpPdo = function_exists('wms_receipts_pdo') ? wms_receipts_pdo() : null;
        } catch (Throwable) {
            $rcpPdo = null;
        }
        foreach ($skus as $sku) {
            try {
                $blockedIdx[$sku] = wms_pick_blocked_index($rcpPdo ?: $nomPdo, $sku, $wh);
            } catch (Throwable) {
                $blockedIdx[$sku] = [];
            }
        }
        try {
            // Только именованные параметры: pdo_pgsql не допускает смешивания
            // позиционных (?) и именованных (:x) плейсхолдеров.
            $ph = [];
            $qparams = [':wh' => $wh];
            foreach ($skus as $i => $s) {
                $k = ':sk' . $i;
                $ph[] = $k;
                $qparams[$k] = $s;
            }
            $st = $nomPdo->prepare(
                "SELECT p.sku AS sku, s.id, s.cell, s.pallet, s.qty, s.receipt_id
                 FROM product_stock s JOIN products p ON p.id = s.product_id
                 WHERE p.sku IN (" . implode(',', $ph) . ") AND s.warehouse_id = :wh AND COALESCE(s.qty,0) > 0"
            );
            $st->execute($qparams);
            $cells = [];
            if ($storagePdo) {
                try {
                    $stc = $storagePdo->prepare('SELECT code, is_locked FROM storage_cells WHERE warehouse_id = :w');
                    $stc->execute([':w' => $wh]);
                    foreach ($stc->fetchAll(PDO::FETCH_ASSOC) as $c) {
                        $cells[strtoupper(trim((string)($c['code'] ?? '')))] = (int)($c['is_locked'] ?? 0) === 1;
                    }
                } catch (Throwable) {
                }
            }
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sku = trim((string)($r['sku'] ?? ''));
                if (!isset($lines[$sku])) {
                    continue;
                }
                $cell = trim((string)($r['cell'] ?? ''));
                $pal = trim((string)($r['pallet'] ?? ''));
                $rid = (int)($r['receipt_id'] ?? 0);
                $blocked = wms_pick_batch_blocked($blockedIdx[$sku] ?? [], $rid > 0 ? $rid : null, $pal !== '' ? $pal : null);
                $locked = $cells[strtoupper($cell)] ?? false;
                if ($blocked || $locked) {
                    continue;
                }
                $stockMap[$sku] = ($stockMap[$sku] ?? 0.0) + (float)($r['qty'] ?? 0);
            }
        } catch (Throwable) {
        }
    }
    foreach ($lines as $sku => &$l) {
        $l['stock'] = round((float)($stockMap[$sku] ?? 0.0), 3);
        $l['reserved'] = round(wms_pick_reserved_for_sku($reserveRows, $sku), 3);
        $l['reserved_shipment'] = round(wms_pick_reserved_for_sku($reserveRows, $sku, $shipmentId, $docNo), 3);
        $limit = (float)$l['need'];
        if ((float)$l['order_qty'] > 0) {
            $limit = min($limit, (float)$l['order_qty']);
        }
        $l['limit'] = round($limit, 3);
        $l['max_new'] = round(max(0.0, $limit - (float)$l['reserved_shipment']), 3);
    }
    unset($l);
    return array_values($lines);
}

/**
 * Кандидаты ручного резервирования по позиции отгрузки.
 *
 * @return array{shipment_id:int,doc_no:string,warehouse_id:int,sku:string,need:float,
 *               limit:float,reserved:float,reserved_shipment:float,max_new:float,
 *               rows:array[],blocked:string[]}
 */
function wms_pick_reserve_candidates(PDO $shipPdo, PDO $nomPdo, ?PDO $storagePdo, PDO $taskPdo, int $shipmentId, string $sku): array
{
    $shipmentId = (int)$shipmentId;
    $sku = trim($sku);
    $lines = wms_pick_reserve_lines($shipPdo, $nomPdo, $storagePdo, $taskPdo, $shipmentId);
    $line = null;
    foreach ($lines as $l) {
        if (strcasecmp((string)$l['sku'], $sku) === 0) {
            $line = $l;
            break;
        }
    }
    if (!$line) {
        throw new RuntimeException('Позиция «' . $sku . '» не найдена в отгрузке');
    }
    $head = null;
    try {
        $st = $shipPdo->prepare('SELECT id, doc_no, warehouse_id FROM shipments WHERE id = :id');
        $st->execute([':id' => $shipmentId]);
        $head = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
    }
    $wh = (int)($head['warehouse_id'] ?? 1) ?: 1;
    $docNo = (string)($head['doc_no'] ?? '');
    $reserveRows = $taskPdo ? wms_pick_reserve_rows($taskPdo, [$sku]) : [];

    $rcpPdo = null;
    try {
        $rcpPdo = function_exists('wms_receipts_pdo') ? wms_receipts_pdo() : null;
    } catch (Throwable) {
        $rcpPdo = null;
    }
    $cands = wms_pick_stock_candidates($nomPdo, $storagePdo, $rcpPdo, $sku, $wh, 'FIFO');
    $rows = [];
    foreach ($cands as $c) {
        $reservedRow = wms_pick_reserved_for_row($reserveRows, $sku, (string)$c['cell'], (string)$c['pallet']);
        $reservedThis = wms_pick_reserved_for_row($reserveRows, $sku, (string)$c['cell'], (string)$c['pallet'], $shipmentId, $docNo);
        $available = round(max(0.0, (float)$c['qty'] - $reservedRow), 3);
        $maxFor = round($available + $reservedThis, 3);
        $rows[] = [
            'stock_id' => (int)$c['id'],
            'cell' => (string)$c['cell'],
            'pallet' => (string)$c['pallet'],
            'pallet_type' => (string)$c['pallet_type'],
            'qty' => round((float)$c['qty'], 3),
            'available' => $available,
            'reserved' => round($reservedRow, 3),
            'reserved_shipment' => round($reservedThis, 3),
            'max_for_shipment' => $maxFor,
            'blocked' => $c['blocked'],
            'locked' => (bool)$c['locked'],
            'lock_reason' => (string)$c['lock_reason'],
            'usable' => (bool)$c['usable'],
            'default_qty' => round(min($maxFor, (float)$line['max_new']), 3),
        ];
    }
    $blocked = [];
    foreach ($rows as $r) {
        foreach ($r['blocked'] as $t) {
            $blocked[] = $t;
        }
        if ($r['locked']) {
            $blocked[] = 'ячейка ' . $r['cell'] . ' заблокирована';
        }
    }
    return [
        'shipment_id' => $shipmentId,
        'doc_no' => $docNo,
        'warehouse_id' => $wh,
        'sku' => $sku,
        'name' => (string)$line['name'],
        'unit' => (string)$line['unit'],
        'need' => (float)$line['need'],
        'limit' => (float)$line['limit'],
        'reserved' => (float)$line['reserved'],
        'reserved_shipment' => (float)$line['reserved_shipment'],
        'max_new' => (float)$line['max_new'],
        'rows' => $rows,
        'blocked' => array_values(array_unique($blocked)),
    ];
}

/**
 * Ручное резервирование: проверки лимитов и запись резерва.
 *
 * Ограничения:
 *   - не больше, чем требуется по строке отгрузки (shipment_items.qty)
 *     и по расходу заказа (client_order_items.qty);
 *   - не больше, чем есть в конкретной ячейке/паллете (product_stock.qty);
 *   - заблокированные ячейки и отбракованные партии — не резервируются.
 *
 * @param array $items [{sku, stock_id, qty}, ...]
 * @return array{ok:bool,reserved:float,items:array[],doc_no:string,task_no:?string}
 */
function wms_pick_reserve_manual(PDO $shipPdo, PDO $nomPdo, ?PDO $storagePdo, PDO $taskPdo, int $shipmentId, array $items, ?int $userId = null): array
{
    $shipmentId = (int)$shipmentId;
    if ($shipmentId <= 0) {
        throw new RuntimeException('Отгрузка не найдена');
    }
    if (!$taskPdo) {
        throw new RuntimeException('БД заданий недоступна');
    }
    $items = array_values(array_filter((array)$items, fn($x) => is_array($x)));
    if (!$items) {
        throw new RuntimeException('Не выбрано ни одной позиции для резервирования');
    }
    $lines = wms_pick_reserve_lines($shipPdo, $nomPdo, $storagePdo, $taskPdo, $shipmentId);
    $bySku = [];
    foreach ($lines as $l) {
        $bySku[strtoupper((string)$l['sku'])] = $l;
    }
    $head = null;
    try {
        $st = $shipPdo->prepare('SELECT id, doc_no, warehouse_id, status FROM shipments WHERE id = :id');
        $st->execute([':id' => $shipmentId]);
        $head = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
    }
    if (!$head) {
        throw new RuntimeException('Отгрузка не найдена');
    }
    $wh = (int)($head['warehouse_id'] ?? 1) ?: 1;
    $docNo = (string)($head['doc_no'] ?? '');

    // --- Группировка по артикулу и проверка лимитов ---
    $sumBySku = [];
    foreach ($items as $it) {
        $sku = trim((string)($it['sku'] ?? ''));
        $qty = (float)($it['qty'] ?? 0);
        if ($sku === '') {
            throw new RuntimeException('Не указан артикул позиции');
        }
        if (!isset($bySku[strtoupper($sku)])) {
            throw new RuntimeException('Позиция «' . $sku . '» не найдена в отгрузке');
        }
        if ($qty <= 0) {
            throw new RuntimeException('Количество по позиции «' . $sku . '» должно быть больше нуля');
        }
        $k = strtoupper($sku);
        $sumBySku[$k] = ($sumBySku[$k] ?? 0.0) + $qty;
    }
    foreach ($sumBySku as $k => $sum) {
        $l = $bySku[$k];
        $limit = (float)$l['limit'];
        $total = (float)$l['reserved_shipment'] + $sum;
        if ($total > $limit + 1e-9) {
            throw new RuntimeException(sprintf(
                'Превышено требуемое количество по позиции: нужно %s, выбрано %s',
                rtrim(rtrim(number_format($limit, 3, '.', ''), '0'), '.') ?: '0',
                rtrim(rtrim(number_format($total, 3, '.', ''), '0'), '.') ?: '0'
            ));
        }
    }

    // --- Проверка строк запаса ---
    $rcpPdo = null;
    try {
        $rcpPdo = function_exists('wms_receipts_pdo') ? wms_receipts_pdo() : null;
    } catch (Throwable) {
        $rcpPdo = null;
    }
    $reserveRows = wms_pick_reserve_rows($taskPdo, array_column($lines, 'sku'));
    $prepared = [];
    $totalQty = 0.0;
    foreach ($items as $it) {
        $sku = trim((string)($it['sku'] ?? ''));
        $stockId = (int)($it['stock_id'] ?? 0);
        $qty = (float)($it['qty'] ?? 0);
        if ($stockId <= 0) {
            throw new RuntimeException('Не указана строка товарного запаса по позиции «' . $sku . '»');
        }
        $row = null;
        try {
            $st = $nomPdo->prepare(
                'SELECT s.id, s.product_id, s.warehouse_id, s.cell, s.pallet, s.qty, s.receipt_id, p.sku, p.name, p.unit
                 FROM product_stock s JOIN products p ON p.id = s.product_id WHERE s.id = :id'
            );
            $st->execute([':id' => $stockId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable) {
            $row = null;
        }
        if (!$row) {
            throw new RuntimeException('Строка товарного запаса не найдена (id=' . $stockId . ')');
        }
        if (strcasecmp(trim((string)$row['sku']), $sku) !== 0) {
            throw new RuntimeException('Строка запаса не соответствует позиции «' . $sku . '»');
        }
        if ((int)($row['warehouse_id'] ?? 0) !== $wh) {
            throw new RuntimeException('Строка запаса относится к другому складу');
        }
        $cell = trim((string)($row['cell'] ?? ''));
        $pal = trim((string)($row['pallet'] ?? ''));
        $rid = (int)($row['receipt_id'] ?? 0);
        // Ячейка заблокирована — резервировать нельзя.
        if ($storagePdo) {
            try {
                $st = $storagePdo->prepare('SELECT is_locked, lock_reason FROM storage_cells WHERE code = :c AND warehouse_id = :w LIMIT 1');
                $st->execute([':c' => $cell, ':w' => $wh]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if ($c && (int)($c['is_locked'] ?? 0) === 1) {
                    throw new RuntimeException('Ячейка ' . $cell . ' заблокирована' . (trim((string)($c['lock_reason'] ?? '')) !== '' ? ' (' . $c['lock_reason'] . ')' : ''));
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable) {
            }
        }
        // Отбракованная/заблокированная партия — резервировать нельзя.
        $blocked = wms_pick_blocked_reasons($rcpPdo ?: $nomPdo, $sku, $wh, $rid > 0 ? $rid : null, $pal !== '' ? $pal : null);
        if ($blocked) {
            throw new RuntimeException('Партия по позиции «' . $sku . '» заблокирована: ' . implode('; ', $blocked));
        }
        // Остаток в конкретной ячейке/паллете.
        $reservedRow = wms_pick_reserved_for_row($reserveRows, $sku, $cell, $pal);
        $reservedThis = wms_pick_reserved_for_row($reserveRows, $sku, $cell, $pal, $shipmentId, $docNo);
        $available = max(0.0, (float)($row['qty'] ?? 0) - $reservedRow);
        $maxFor = $available + $reservedThis;
        if ($qty - $maxFor > 1e-9) {
            throw new RuntimeException(sprintf(
                'Превышен остаток в ячейке %s: доступно %s, выбрано %s',
                $cell !== '' ? $cell : '—',
                rtrim(rtrim(number_format($maxFor, 3, '.', ''), '0'), '.') ?: '0',
                rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.') ?: '0'
            ));
        }
        $prepared[] = [
            'sku' => $sku,
            'name' => (string)($row['name'] ?? $sku),
            'unit' => (string)($row['unit'] ?? 'шт'),
            'stock_id' => $stockId,
            'cell' => $cell,
            'pallet' => $pal,
            'qty' => $qty,
        ];
        $totalQty += $qty;
    }

    // --- Запись резерва: задание на отбор + счётчики резерва ---
    $docTaskNo = wms_pick_next_doc_no($taskPdo);
    $taskItems = [];
    $cells = [];
    foreach ($prepared as $p) {
        $taskItems[] = [
            'sku' => $p['sku'], 'name' => $p['name'], 'unit' => $p['unit'],
            'cell_from' => $p['cell'], 'pallet' => $p['pallet'], 'plan_qty' => $p['qty'],
        ];
        $cells[$p['cell']] = true;
    }
    $task = null;
    try {
        $task = wms_pick_insert_task($taskPdo, [
            'doc_no' => $docTaskNo,
            'type' => 'сбор',
            'status' => 'new',
            'priority' => 'normal',
            'warehouse_id' => $wh,
            'doc_ref' => $docNo,
            'shipment_ids' => [$shipmentId],
            'pick_mode' => 'manual',
            'plan_qty' => $totalQty,
            'cells_count' => max(1, count($cells)),
            'flags' => 'pick,manual',
            'comment' => 'Ручное резервирование по отгрузке ' . $docNo,
            'user_id' => $userId,
            'items' => $taskItems,
        ]);
    } catch (Throwable $e) {
        throw new RuntimeException('Не удалось создать задание на резерв: ' . $e->getMessage());
    }
    // Счётчики резерва: ячейка и номенклатура (тот же механизм, что и в wms_block_cell_and_reserve).
    foreach ($prepared as $p) {
        if ($storagePdo && $p['cell'] !== '') {
            try {
                $storagePdo->prepare(
                    'UPDATE storage_cells SET reserved_qty = COALESCE(reserved_qty,0) + :q WHERE code = :c AND warehouse_id = :w'
                )->execute([':q' => $p['qty'], ':c' => $p['cell'], ':w' => $wh]);
            } catch (Throwable) {
            }
        }
        try {
            $nomPdo->prepare(
                'UPDATE products SET reserved_qty = COALESCE(reserved_qty,0) + :q WHERE sku = :s'
            )->execute([':q' => $p['qty'], ':s' => $p['sku']]);
        } catch (Throwable) {
        }
    }
    // Журнал склада.
    try {
        require_once __DIR__ . '/Journal.php';
        foreach ($prepared as $p) {
            WmsJournal::log(wms_journal_pdo(), [
                'op_type' => 'отбор',
                'doc_type' => 'Резерв',
                'doc_no' => $docNo,
                'sku' => $p['sku'],
                'product_name' => $p['name'],
                'qty' => (float)$p['qty'],
                'pallet' => $p['pallet'] !== '' ? $p['pallet'] : null,
                'cell_from' => $p['cell'],
                'zone' => 'Отбор',
                'user_id' => $userId,
                'comment' => 'Ручное резервирование (задание ' . $docTaskNo . ')',
            ]);
        }
    } catch (Throwable) {
    }
    // История отгрузки.
    try {
        $shipPdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'reserve',:o,:n)"
        )->execute([
            ':s' => $shipmentId, ':u' => $userId,
            ':o' => 'резерв', ':n' => 'ручное резервирование: ' . count($prepared) . ' поз. на ' . round($totalQty, 3) . ' шт',
        ]);
    } catch (Throwable) {
    }
    return [
        'ok' => true,
        'reserved' => round($totalQty, 3),
        'items' => $prepared,
        'doc_no' => $docNo,
        'task_no' => $task['doc_no'] ?? $docTaskNo,
        'task_id' => (int)($task['id'] ?? 0),
    ];
}

/**
 * Выпуск заданий на отбор.
 * @param PDO $shipPdo  БД отгрузок (shipments)
 * @param PDO[] $taskPdos БД заданий (primary = [0])
 * @param int[] $shipmentIds
 * @param string $mode wave|line
 * @param callable|null $cellResolver fn(string $sku, int $warehouseId, int $seed): ?string —
 *        приоритетная ячейка отбора (например, ячейка выбранной зоны).
 * @return array{tasks: array, mode: string, gate: ?string, count: int,
 *               not_reserved: array[]}
 *         not_reserved — позиции, которые не удалось подобрать (нет остатка /
 *         ячейка заблокирована / партия отбракована): [{sku,name,need,reason}].
 */
function wms_issue_pick_tasks(PDO $shipPdo, array $taskPdos, array $shipmentIds, string $mode, ?int $userId, ?callable $cellResolver = null): array
{
    $shipmentIds = array_values(array_unique(array_map('intval', $shipmentIds)));
    $shipmentIds = array_values(array_filter($shipmentIds, fn($v) => $v > 0));
    if (!$shipmentIds) {
        throw new RuntimeException('Выберите отгрузки');
    }
    $mode = ($mode === 'line' || $mode === 'lines' || $mode === 'построчно') ? 'line' : 'wave';
    if (!$taskPdos) {
        throw new RuntimeException('БД заданий недоступна');
    }
    $primary = $taskPdos[0];

    // --- Шапки отгрузок ---
    $in = implode(',', $shipmentIds);
    $heads = $shipPdo->query("SELECT * FROM shipments WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    if (count($heads) !== count($shipmentIds)) {
        throw new RuntimeException('Не все отгрузки найдены');
    }
    $byId = [];
    foreach ($heads as $h) {
        $byId[(int)$h['id']] = $h;
    }
    // --- Проверка ворот для волны ---
    $gates = [];
    foreach ($heads as $h) {
        $gates[] = trim((string)($h['gate'] ?? ''));
    }
    $gate = null;
    if ($mode === 'wave') {
        $lower = function_exists('mb_strtolower') ? fn($g) => mb_strtolower($g, 'UTF-8') : fn($g) => strtolower($g);
        $uniq = array_unique(array_map($lower, $gates));
        $uniq = array_values(array_filter($uniq, fn($g) => $g !== ''));
        if (count($gates) !== count(array_filter($gates, fn($g) => $g !== ''))) {
            throw new RuntimeException('Для волнового отбора у всех отгрузок должны быть указаны одинаковые ворота');
        }
        if (count($uniq) !== 1) {
            throw new RuntimeException('Для волнового отбора ворота должны совпадать: ' . implode(', ', array_unique($gates)));
        }
        $gate = $gates[0];
    }

    // --- Позиции ---
    $items = $shipPdo->query("SELECT * FROM shipment_items WHERE shipment_id IN ($in) ORDER BY shipment_id, id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        throw new RuntimeException('В отгрузках нет позиций');
    }

    require_once wms_pick_root() . '/shared/db.php';
    try {
        $storagePdo = wms_storage_pdo();
    } catch (Throwable) {
        $storagePdo = null;
    }
    // Товарный запас (партии) и приёмка (блокировки/отбраковка) — для подбора.
    try {
        $nomPdo = wms_nomenclature_pdo();
    } catch (Throwable) {
        $nomPdo = null;
    }
    try {
        $rcpPdo = wms_receipts_pdo();
    } catch (Throwable) {
        $rcpPdo = null;
    }

    // Группировка
    $groups = []; // key => [sku, name, code, unit, qty, shipment_ids[], doc_nos[], warehouse_id, price]
    if ($mode === 'wave') {
        foreach ($items as $it) {
            $sku = (string)($it['sku'] ?? '');
            if ($sku === '') {
                continue;
            }
            if (!isset($groups[$sku])) {
                $sid = (int)$it['shipment_id'];
                $groups[$sku] = [
                    'sku' => $sku, 'name' => (string)($it['name'] ?? $sku),
                    'code' => (string)($it['code'] ?? ''), 'unit' => (string)($it['unit'] ?? 'шт'),
                    'qty' => 0.0, 'shipment_ids' => [], 'doc_nos' => [],
                    'warehouse_id' => (int)($byId[$sid]['warehouse_id'] ?? 1),
                    'gate' => $gate,
                ];
            }
            $groups[$sku]['qty'] += (float)($it['qty'] ?? 0);
            $sid = (int)$it['shipment_id'];
            if (!in_array($sid, $groups[$sku]['shipment_ids'], true)) {
                $groups[$sku]['shipment_ids'][] = $sid;
                $groups[$sku]['doc_nos'][] = (string)($byId[$sid]['doc_no'] ?? ('#' . $sid));
            }
        }
    } else {
        foreach ($items as $idx => $it) {
            $sid = (int)$it['shipment_id'];
            $groups['L' . $idx] = [
                'sku' => (string)($it['sku'] ?? ''), 'name' => (string)($it['name'] ?? ''),
                'code' => (string)($it['code'] ?? ''), 'unit' => (string)($it['unit'] ?? 'шт'),
                'qty' => (float)($it['qty'] ?? 0), 'shipment_ids' => [$sid],
                'doc_nos' => [(string)($byId[$sid]['doc_no'] ?? ('#' . $sid))],
                'warehouse_id' => (int)($byId[$sid]['warehouse_id'] ?? 1),
                'gate' => trim((string)($byId[$sid]['gate'] ?? '')) ?: null,
            ];
        }
    }
    if (!$groups) {
        throw new RuntimeException('Нет позиций для заданий');
    }

    // Приоритет отгрузок: high > normal > low
    $prioRank = ['lowest' => 0, 'low' => 1, 'normal' => 2, 'high' => 3, 'critical' => 4];
    $maxPrio = 'normal';
    $maxR = -1;
    $due = null;
    foreach ($heads as $h) {
        $p = (string)($h['priority'] ?? 'normal');
        $r = $prioRank[$p] ?? 2;
        if ($r > $maxR) {
            $maxR = $r;
            $maxPrio = $p;
        }
        $d = $h['plan_shipment_date'] ?? null;
        if ($d && ($due === null || $d < $due)) {
            $due = $d;
        }
    }

    $created = [];
    $notReserved = [];
    $seed = 0;
    // Транзакции на всех БД заданий и на БД отгрузок: отбор либо запускается
    // целиком (задания созданы + отгрузки помечены), либо не запускается вовсе.
    // Раньше отгрузки помечались «запущен отбор» ДО проверки «созданы ли задания»,
    // и документ оставался в резерве с НУЛЕМ заданий.
    $tx = [];
    try {
        foreach ($taskPdos as $tPdo) {
            if (!$tPdo->inTransaction()) {
                $tPdo->beginTransaction();
                $tx[] = $tPdo;
            }
        }
        foreach ($groups as $g) {
            if ((float)$g['qty'] <= 0) {
                continue;
            }
            $wh = (int)$g['warehouse_id'] ?: 1;
            $cell = null;
            if ($cellResolver !== null) {
                try {
                    $cell = $cellResolver((string)$g['sku'], $wh, $seed);
                } catch (Throwable) {
                    $cell = null;
                }
            }
            // --- Подбор партии под отбор по принципу резервирования клиента ---
            // FIFO / FEFO / LIFO — настройка counterparties.reserve_strategy.
            // Заблокированные ячейки и отбракованные партии в подбор не идут.
            $strategy = 'FIFO';
            $match = null;
            if ($nomPdo) {
                $gs = wms_pick_group_strategy($shipPdo, $byId, $g['shipment_ids']);
                $strategy = $gs['strategy'];
                $match = wms_pick_match_stock($nomPdo, $storagePdo, $rcpPdo, (string)$g['sku'], $wh, (float)$g['qty'], $strategy);
                if (!$match['ok']) {
                    // Товар не удалось подобрать — позиция уходит в отчёт not_reserved.
                    $notReserved[] = [
                        'sku' => (string)$g['sku'],
                        'name' => (string)($g['name'] ?? $g['sku']),
                        'unit' => (string)($g['unit'] ?? 'шт'),
                        'need' => round((float)$g['qty'], 3),
                        'available' => (float)$match['available'],
                        'reason' => (string)$match['reason'],
                        'strategy' => $strategy,
                        'shipments' => $g['doc_nos'],
                    ];
                    continue;
                }
            }
            if ($cell === null || $cell === '') {
                if ($match && (string)$match['cell'] !== '') {
                    $cell = (string)$match['cell'];
                } else {
                    $cell = $storagePdo ? wms_pick_resolve_cell($storagePdo, $g['sku'], $wh, $seed) : ('СКЛАД-' . $wh);
                }
            }
            $seed++;
            $docRef = $mode === 'wave' ? implode(', ', $g['doc_nos']) : $g['doc_nos'][0];
            $modeRu = $mode === 'wave' ? 'волной' : 'построчно';
            $comment = 'Отбор ' . $modeRu . ' из отгрузок: ' . implode(', ', $g['doc_nos'])
                . ($g['gate'] ? (' · ворота ' . $g['gate']) : '')
                . ' · принцип ' . $strategy;
            $docNo = wms_pick_next_doc_no($primary);

            // Вставка во все БД заданий с одинаковым doc_no
            foreach ($taskPdos as $pi => $tPdo) {
                $tbl = wms_task_tables($tPdo);
                wms_ensure_task_pick_cols($tPdo, $tbl['t']);
                $isSqlite = $tPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
                $nowFn = $isSqlite ? "datetime('now')" : 'NOW()';
                // doc_no может уже существовать во вторичной БД — тогда генерируем суффикс
                $tryNo = $docNo;
                if ($pi > 0) {
                    try {
                        $ex = $tPdo->prepare("SELECT id FROM {$tbl['t']} WHERE doc_no=:n");
                        $ex->execute([':n' => $tryNo]);
                        if ($ex->fetch()) {
                            $tryNo = $docNo . '-' . ($pi + 1);
                        }
                    } catch (Throwable) {
                    }
                }
                $hasPm = wms_task_has_col($tPdo, $tbl['t'], 'pick_mode');
                $hasGate = wms_task_has_col($tPdo, $tbl['t'], 'gate');
                $hasSids = wms_task_has_col($tPdo, $tbl['t'], 'shipment_ids');
                $cols = ['doc_no', 'type', 'status', 'priority', 'warehouse_id', 'doc_ref',
                    'executor_id', 'executor_fio', 'plan_qty', 'cells_count', 'volume', 'weight',
                    'equipment', 'flags', 'need_check', 'comment', 'due_date'];
                $vals = [':no', "'сбор'", "'new'", ':pri', ':wh', ':doc',
                    'NULL', 'NULL', ':pq', '1', '0', '0',
                    'NULL', ':flg', '0', ':cmt', ':due'];
                $params = [
                    ':no' => $tryNo, ':pri' => $maxPrio, ':wh' => $wh, ':doc' => $docRef,
                    ':pq' => (float)$g['qty'], ':flg' => 'pick,' . $mode . ($gate ? ',gate' . $gate : ''),
                    ':cmt' => $comment, ':due' => $due,
                ];
                if ($hasPm) {
                    $cols[] = 'pick_mode';
                    $vals[] = ':pm';
                    $params[':pm'] = $mode;
                }
                if ($hasGate) {
                    $cols[] = 'gate';
                    $vals[] = ':gate';
                    $params[':gate'] = $g['gate'];
                }
                if ($hasSids) {
                    $cols[] = 'shipment_ids';
                    $vals[] = ':sids';
                    $params[':sids'] = implode(',', $g['shipment_ids']);
                }
                // wms_tasks (prod) требует consignee nullable — остальные колонки имеют дефолты
                $cols[] = 'created_at';
                $vals[] = $nowFn;
                $t = $tbl['t'];
                $st = $tPdo->prepare("INSERT INTO $t (" . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');
                $st->execute($params);
                $tid = (int)$tPdo->lastInsertId();
                $ti = $tbl['i'];
                $tPdo->prepare(
                    "INSERT INTO $ti (task_id,code,sku,name,unit,cell_from,cell_to,pallet,plan_qty,fact_qty,state,executor_fio)
                     VALUES (:t,:code,:sku,:name,:unit,:cf,NULL,NULL,:pq,0,NULL,NULL)"
                )->execute([
                    ':t' => $tid, ':code' => $g['code'] ?: null, ':sku' => $g['sku'],
                    ':name' => $g['name'] ?: $g['sku'], ':unit' => $g['unit'] ?: 'шт',
                    ':cf' => $cell, ':pq' => (float)$g['qty'],
                ]);
                try {
                    $th = $tbl['h'];
                    $tPdo->prepare(
                        "INSERT INTO $th (task_id,user_id,action,field,old_value,new_value) VALUES (?,?,?,?,?,?)"
                    )->execute([$tid, $userId, 'create', null, null, $tryNo . ' (' . $modeRu . ')']);
                } catch (Throwable) {
                }
                if ($pi === 0) {
                    $created[] = [
                        'id' => $tid, 'doc_no' => $tryNo, 'sku' => $g['sku'],
                        'name' => (string)($g['name'] ?? ''),
                        'qty' => (float)$g['qty'], 'cell' => $cell,
                        'pallet' => $match && $match['pallet'] ? $match['pallet'] : null,
                        'stock_id' => $match ? $match['stock_id'] : null,
                        'strategy' => $strategy,
                        'level' => wms_pick_cell_level($cell),
                        'shipments' => $g['doc_nos'], 'doc_ref' => $docRef,
                    ];
                }
            }
        }

        // Ни одного задания не создалось:
        //   - позиции есть, но товар не удалось подобрать → отчёт not_reserved
        //     (отгрузки НЕ помечаем как запущенные, резерв не создаём);
        //   - позиций/количеств нет → ошибка.
        if (!$created && !$notReserved) {
            throw new RuntimeException('Не создано заданий (пустые количества)');
        }

        // --- Пометить отгрузки: запущен отбор ---
        if ($created) {
            if (!$shipPdo->inTransaction()) {
                $shipPdo->beginTransaction();
                $tx[] = $shipPdo;
            }
            foreach ($heads as $h) {
                $sid = (int)$h['id'];
                // Критичные UPDATE — без проглатывания ошибок: иначе отгрузка
                // осталась бы помеченной «запущен отбор» без единого задания.
                $drv = $shipPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $fn = $drv === 'sqlite' ? "datetime('now')" : 'NOW()';
                $shipPdo->prepare("UPDATE shipments SET launched_pick=:m, assembly_start=COALESCE(assembly_start,$fn) WHERE id=:id")
                    ->execute([':m' => $mode === 'wave' ? 'Волна' : 'Строки', ':id' => $sid]);
                $cur = (string)($h['status'] ?? '');
                if (in_array($cur, ['draft', 'created'], true)) {
                    $shipPdo->prepare("UPDATE shipments SET status='assembling' WHERE id=:id")->execute([':id' => $sid]);
                }
                // Журнал — мягко: расхождение колонок не должно откатывать отбор.
                try {
                    $shipPdo->prepare(
                        "INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (:s,:u,'picking',:o,:n)"
                    )->execute([':s' => $sid, ':u' => $userId, ':o' => (string)($h['status'] ?? ''), ':n' => ($mode === 'wave' ? 'Волна' : 'Строки')]);
                } catch (Throwable) {
                    try {
                        $shipPdo->prepare(
                            "INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'picking',:o,:n)"
                        )->execute([':s' => $sid, ':u' => $userId, ':o' => (string)($h['status'] ?? ''), ':n' => ($mode === 'wave' ? 'Волна' : 'Строки')]);
                    } catch (Throwable) {
                    }
                }
            }
        }
        foreach ($tx as $pdo) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        foreach ($tx as $pdo) {
            try {
                $pdo->rollBack();
            } catch (Throwable) {
            }
        }
        throw $e;
    }
    // Журнал склада (модуль 14): отбор — выдача заданий на отбор.
    // Пишем после создания заданий (точка, где уже пишется shipment_history 'picking').
    try {
        require_once __DIR__ . '/Journal.php';
        foreach ($created as $t) {
            WmsJournal::log(wms_journal_pdo(), [
                'op_type' => 'отбор',
                'doc_type' => 'Задание',
                'doc_no' => (string)($t['doc_no'] ?? ''),
                'sku' => (string)($t['sku'] ?? ''),
                'product_name' => (string)($t['name'] ?? ''),
                'qty' => (float)($t['qty'] ?? 0),
                'cell_from' => (string)($t['cell'] ?? ''),
                'zone' => 'Отбор',
                'user_id' => $userId,
                'comment' => 'Отбор ' . ($mode === 'wave' ? 'волной' : 'построчно')
                    . ((string)($t['doc_ref'] ?? '') !== '' ? ' из отгрузок: ' . $t['doc_ref'] : ''),
            ]);
        }
    } catch (Throwable) {
    }
    return [
        'tasks' => $created,
        'mode' => $mode,
        'gate' => $gate,
        'count' => count($created),
        // Позиции, которые не удалось подобрать (нет остатка / блокировка / брак).
        'not_reserved' => $notReserved,
        'failed' => $notReserved,
        'note' => $notReserved
            ? 'Не удалось подобрать позиций: ' . count($notReserved)
            : '',
    ];
}

/** Экранирование для LIKE. */
function wms_pick_like_esc(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * Найти отгрузку, к которой привязано задание на отбор (связь с модулем «Отгрузки»).
 * Порядок: id из shipment_ids -> номер документа из doc_ref.
 * null — отгрузки больше нет (документ удалён из модуля «Отгрузки»).
 * @return array{id:int,doc_no:string,consignee:string,gate:?string,status:string}|null
 */
function wms_pick_task_shipment(PDO $ship, array $task): ?array
{
    $ids = [];
    foreach (explode(',', (string)($task['shipment_ids'] ?? '')) as $v) {
        $v = trim($v);
        if ($v !== '' && is_numeric($v)) {
            $ids[] = (int)$v;
        }
    }
    $docs = array_values(array_filter(array_map('trim', explode(',', (string)($task['doc_ref'] ?? ''))), fn($v) => $v !== ''));
    return wms_pick_shipment_by_link($ship, $ids, $docs);
}

/**
 * Отгрузка по id и/или номеру документа.
 * @param int[] $ids
 * @param string[] $docNos
 * @return array{id:int,doc_no:string,consignee:string,gate:?string,status:string}|null
 */
function wms_pick_shipment_by_link(PDO $ship, array $ids, array $docNos): ?array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    $docNos = array_values(array_unique(array_filter(array_map('trim', $docNos), fn($v) => $v !== '')));
    if (!$ids && !$docNos) {
        return null;
    }
    $row = null;
    try {
        if ($ids) {
            $in = implode(',', $ids);
            $st = $ship->query("SELECT id, doc_no, consignee, gate, status FROM shipments WHERE id IN ($in) ORDER BY id");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $row = $r;
                break;
            }
        }
        if (!$row && $docNos) {
            foreach ($docNos as $dn) {
                $st = $ship->prepare('SELECT id, doc_no, consignee, gate, status FROM shipments WHERE doc_no = :d LIMIT 1');
                $st->execute([':d' => $dn]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $row = $r;
                    break;
                }
            }
        }
    } catch (Throwable) {
        return null;
    }
    if (!$row) {
        return null;
    }
    return [
        'id' => (int)($row['id'] ?? 0),
        'doc_no' => (string)($row['doc_no'] ?? ''),
        'consignee' => (string)($row['consignee'] ?? ''),
        'gate' => ($row['gate'] ?? null) !== null ? (string)$row['gate'] : null,
        'status' => (string)($row['status'] ?? ''),
    ];
}

/**
 * Проверка паллеты при отборе — на уровне кода (без вопросов оператору).
 * Проверяется: паллета есть на складе, не в зоне сортировки, не занята другой
 * отгрузкой, объём в пределах лимита.
 *
 * @param PDO $ship    БД отгрузок
 * @param PDO $storage БД ячеек хранения
 * @param PDO $nom     БД номенклатуры (запас)
 * @param int $shipmentId отгрузка, для которой отбираем
 * @param string $pallet SSCC паллеты (пусто — паллеты в ячейке оператора нет)
 * @param float|null $volume объём паллеты, м3 (если оператор измерил)
 * @param float $volumeLimit лимит объёма паллеты, м3
 * @return array{ok:bool,state:string,pallet:string,cell:string,checks:array,error:?string,hint:string}
 */
function wms_pick_pallet_check(
    PDO $ship,
    PDO $storage,
    PDO $nom,
    int $shipmentId,
    string $pallet = '',
    ?float $volume = null,
    float $volumeLimit = 2.0
): array {
    $pallet = trim($pallet);
    $res = [
        'ok' => false, 'state' => 'scan', 'pallet' => $pallet, 'cell' => '',
        'checks' => ['exists' => false, 'in_sort' => false, 'other_shipment' => false, 'volume' => true],
        'error' => null, 'hint' => '',
    ];
    if ($pallet === '') {
        $res['state'] = 'scan';
        $res['hint'] = 'Паллеты в ячейке оператора нет — отсканируй паллету';
        return $res;
    }
    // 1) Паллета есть на складе? Где лежит?
    $cells = [];
    $qty = 0.0;
    $skus = [];
    try {
        $st = $nom->prepare(
            'SELECT s.cell, s.qty, p.sku FROM product_stock s
             JOIN products p ON p.id = s.product_id
             WHERE s.pallet = :pal AND s.qty > 0 ORDER BY s.id'
        );
        $st->execute([':pal' => $pallet]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cell = trim((string)($r['cell'] ?? ''));
            if ($cell !== '' && !in_array($cell, $cells, true)) {
                $cells[] = $cell;
            }
            $qty += (float)($r['qty'] ?? 0);
            $sku = trim((string)($r['sku'] ?? ''));
            if ($sku !== '' && !in_array($sku, $skus, true)) {
                $skus[] = $sku;
            }
        }
    } catch (Throwable) {
    }
    if (!$cells) {
        // Паллеты нет в запасе — может, она ещё в приёмке (product_stock без паллеты не найти).
        try {
            $st = $storage->prepare(
                'SELECT DISTINCT sc.code FROM cell_content cc JOIN storage_cells sc ON sc.id = cc.cell_id
                 WHERE cc.pallet = :pal'
            );
            $st->execute([':pal' => $pallet]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cell = trim((string)($r['code'] ?? ''));
                if ($cell !== '' && !in_array($cell, $cells, true)) {
                    $cells[] = $cell;
                }
            }
        } catch (Throwable) {
        }
    }
    $res['cell'] = implode(', ', $cells);
    $res['checks']['exists'] = (bool)$cells;
    $res['qty'] = $qty;
    $res['skus'] = $skus;
    if (!$cells) {
        // Паллеты нет в запасе — это новая (пустая) паллета оператора: отбирать в неё можно.
        $res['hint'] = 'Паллета ' . $pallet . ' новая (нет на складе) — отбирать можно';
    }
    // 2) Паллета в зоне сортировки? (туда кладут уже отобранное)
    $inSort = false;
    if (preg_match('/^(SORT|СОРТ)/iu', $pallet)) {
        $inSort = true;
    }
    foreach ($cells as $cell) {
        if (stripos($cell, 'ЗОНА-СОРТИРОВКИ') !== false || stripos($cell, 'СОРТИРОВК') !== false) {
            $inSort = true;
        }
        try {
            $st = $storage->prepare('SELECT zone FROM storage_cells WHERE code = :c LIMIT 1');
            $st->execute([':c' => $cell]);
            $zone = strtolower((string)($st->fetchColumn() ?: ''));
            if (in_array($zone, ['sorting', 'shipping', 'sort'], true)) {
                $inSort = true;
            }
        } catch (Throwable) {
        }
    }
    $res['checks']['in_sort'] = $inSort;
    if ($inSort) {
        $res['state'] = 'error';
        $res['error'] = 'Паллета ' . $pallet . ' находится в зоне сортировки';
        $res['hint'] = 'Отбери в другую паллету — эта уже в зоне сортировки';
        return $res;
    }
    // 3) Паллета занята другой отгрузкой? (факт отбора другой отгрузки)
    $other = [];
    try {
        $st = $ship->prepare('SELECT DISTINCT shipment_id FROM tsd_picks WHERE sscc = :pal');
        $st->execute([':pal' => $pallet]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            if ((int)$sid !== $shipmentId) {
                $other[] = (int)$sid;
            }
        }
    } catch (Throwable) {
    }
    // Паллета в заданиях на отбор другой отгрузки.
    try {
        $st = $ship->prepare(
            'SELECT DISTINCT t.shipment_ids FROM task_items i JOIN tasks t ON t.id = i.task_id
             WHERE i.pallet = :pal AND t.flags LIKE \'%pick%\''
        );
        $st->execute([':pal' => $pallet]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sids) {
            foreach (explode(',', (string)$sids) as $v) {
                $v = trim($v);
                if ($v !== '' && is_numeric($v) && (int)$v !== $shipmentId) {
                    $other[] = (int)$v;
                }
            }
        }
    } catch (Throwable) {
    }
    $res['checks']['other_shipment'] = (bool)$other;
    if ($other) {
        $res['state'] = 'error';
        $res['error'] = 'Паллета ' . $pallet . ' принадлежит другой отгрузке';
        $res['hint'] = 'Отбери в другую паллету';
        return $res;
    }
    // 4) Объём паллеты в пределах лимита? (измерение оператора приоритетнее расчёта по номенклатуре)
    $calcVol = 0.0;
    try {
        $st = $nom->prepare(
            'SELECT COALESCE(SUM(s.qty * COALESCE(p.volume_m3,0)),0) FROM product_stock s
             JOIN products p ON p.id = s.product_id WHERE s.pallet = :pal AND s.qty > 0'
        );
        $st->execute([':pal' => $pallet]);
        $calcVol = (float)($st->fetchColumn() ?: 0);
    } catch (Throwable) {
    }
    $measured = (float)($volume ?? 0);
    $vol = $measured > 0 ? $measured : $calcVol;
    $res['checks']['volume'] = $vol <= $volumeLimit + 1e-9;
    $res['volume'] = round($vol, 3);
    $res['volume_calc'] = round($calcVol, 3);
    if (!$res['checks']['volume']) {
        $res['state'] = 'error';
        $res['error'] = 'Паллета ' . $pallet . ' превышает допустимый объем (' . round($vol, 2) . ' м3)';
        $res['hint'] = 'Раздели паллету';
        return $res;
    }
    // Готово: паллету можно использовать.
    $res['ok'] = true;
    $res['state'] = 'cell';
    $res['hint'] = 'Паллета ' . $pallet . ' годится: можно отбирать в неё';
    return $res;
}

/**
 * Найти задания на отбор, созданные по отгрузкам (выпуск волной/построчно).
 * Матчинг объединённый (ИЛИ), т.к. числовые id отгрузок могут расходиться
 * между зеркалами БД, а doc_no — стабильный бизнес-ключ:
 *  - shipment_ids содержит один из id, ЛИБО
 *  - doc_ref содержит один из номеров документов (точное совпадение токена) + флаг pick.
 * @return array<int, array> задачи вида [id, doc_no, status, shipment_ids[], doc_nos[]]
 */
function wms_find_pick_tasks(PDO $tPdo, array $shipmentIds, array $docNos): array
{
    $tbl = wms_task_tables($tPdo);
    $t = $tbl['t'];
    $shipmentIds = array_values(array_unique(array_map('intval', array_filter(array_map('intval', $shipmentIds), fn($v) => $v > 0))));
    $docNos = array_values(array_unique(array_filter(array_map('trim', $docNos), fn($v) => $v !== '')));
    if (!$shipmentIds && !$docNos) {
        return [];
    }
    $hasSids = wms_task_has_col($tPdo, $t, 'shipment_ids');
    // Отбор отгрузок переносим В SQL: раньше тянулась ВСЯ таблица заданий
    // (flags LIKE '%pick%' — полный скан, индекс не применим), а фильтр шёл в PHP.
    // Теперь БД отдаёт только релевантные строки.
    $where = ["flags LIKE '%pick%'"];
    $params = [];
    $match = [];
    $i = 0;
    foreach ($shipmentIds as $sid) {
        if (!$hasSids) {
            break;
        }
        // shipment_ids — CSV: "12,15,20" (в разных записях с пробелом: "12, 15").
        // Сравниваем БЕЗ пробелов, иначе токен не найдётся.
        $k = 's' . $i++;
        $match[] = "(REPLACE(shipment_ids,' ','') LIKE :{$k}a OR REPLACE(shipment_ids,' ','') LIKE :{$k}b"
            . " OR REPLACE(shipment_ids,' ','') LIKE :{$k}c OR REPLACE(shipment_ids,' ','') = :{$k}d)";
        $params[":{$k}a"] = '%,' . $sid . ',%';
        $params[":{$k}b"] = $sid . ',%';
        $params[":{$k}c"] = '%,' . $sid;
        $params[":{$k}d"] = (string)$sid;
    }
    $j = 0;
    foreach ($docNos as $dn) {
        $k = 'd' . $j++;
        // doc_ref тоже может содержать пробелы после запятой ("S-1, S-2").
        $match[] = "(REPLACE(doc_ref,' ','') LIKE :{$k}a OR REPLACE(doc_ref,' ','') LIKE :{$k}b"
            . " OR REPLACE(doc_ref,' ','') LIKE :{$k}c OR REPLACE(doc_ref,' ','') = :{$k}d)";
        $params[":{$k}a"] = '%,' . $dn . ',%';
        $params[":{$k}b"] = $dn . ',%';
        $params[":{$k}c"] = '%,' . $dn;
        $params[":{$k}d"] = $dn;
    }
    if ($match) {
        $where[] = '(' . implode(' OR ', $match) . ')';
    }
    if ($hasSids) {
        $where[] = "(shipment_ids IS NOT NULL AND shipment_ids<>'')";
    }
    $st = $tPdo->prepare("SELECT * FROM $t WHERE " . implode(' AND ', $where));
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v);
    }
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $found = [];
    foreach ($rows as $r) {
        $sids = [];
        if ($hasSids) {
            foreach (explode(',', (string)($r['shipment_ids'] ?? '')) as $v) {
                $v = trim($v);
                if ($v !== '' && is_numeric($v)) {
                    $sids[] = (int)$v;
                }
            }
        }
        $tokens = array_values(array_filter(array_map('trim', explode(',', (string)($r['doc_ref'] ?? ''))), fn($v) => $v !== ''));
        $hitId = (bool)array_intersect($sids, $shipmentIds);
        $hitDoc = (bool)array_intersect($tokens, $docNos);
        // По doc_ref матчим только задания отбора (флаг pick), чтобы не цеплять ручные задания со ссылкой на документ.
        if ($hitId || ($hitDoc && str_contains((string)($r['flags'] ?? ''), 'pick'))) {
            $found[(int)$r['id']] = [
                'id' => (int)$r['id'], 'doc_no' => (string)($r['doc_no'] ?? ''),
                'status' => (string)($r['status'] ?? ''),
                'pick_mode' => (string)($r['pick_mode'] ?? ''),
                'shipment_ids' => $sids,
                'doc_nos' => $tokens,
            ];
        }
    }
    return array_values($found);
}

/**
 * Детализация заданий на отбор по отгрузкам для вкладки "Задания" карточки.
 * @return array[] [doc_no, status, pick_mode, sku, qty, cell, plan_qty, fact_qty, progress, shipments[]]
 */
function wms_pick_tasks_detail(PDO $tPdo, array $shipmentIds, array $docNos): array
{
    $tbl = wms_task_tables($tPdo);
    $t = $tbl['t'];
    $ti = $tbl['i'];
    $out = [];
    foreach (wms_find_pick_tasks($tPdo, $shipmentIds, $docNos) as $bt) {
        $tid = (int)$bt['id'];
        try {
            $items = $tPdo->prepare("SELECT sku, name, unit, cell_from, plan_qty, fact_qty FROM $ti WHERE task_id=:t ORDER BY id");
            $items->execute([':t' => $tid]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rows = [];
        }
        $plan = 0.0;
        $fact = 0.0;
        foreach ($rows as $it) {
            $plan += (float)($it['plan_qty'] ?? 0);
            $fact += (float)($it['fact_qty'] ?? 0);
        }
        $first = $rows[0] ?? [];
        $out[] = [
            'id' => $tid, 'doc_no' => $bt['doc_no'], 'status' => $bt['status'],
            'pick_mode' => $bt['pick_mode'] !== '' ? $bt['pick_mode'] : null,
            'sku' => (string)($first['sku'] ?? ''), 'name' => (string)($first['name'] ?? ''),
            'qty' => $plan, 'cell' => (string)($first['cell_from'] ?? ''),
            'lines' => count($rows),
            'plan_qty' => $plan, 'fact_qty' => $fact,
            'progress' => $plan > 0 ? (int)min(100, round($fact / $plan * 100)) : ($fact > 0 ? 100 : 0),
            'shipments' => $bt['doc_nos'],
        ];
    }
    usort($out, fn($a, $b) => strcmp((string)$a['doc_no'], (string)$b['doc_no']));
    return $out;
}

/**
 * ПКМ "Снять резерв": удаляет ВСЕ задания на отбор, созданные по документам.
 * Волновое задание, общее с другими (невыбранными) отгрузками, тоже удаляется —
 * оно вернётся в отчёте как shared. launched_pick очищается у всех отгрузок,
 * на которые ссылались удалённые задания, если по ним не осталось других заданий.
 * @param PDO $shipPdo  БД отгрузок (shipments)
 * @param PDO[] $taskPdos БД заданий (primary = [0])
 * @param int[] $shipmentIds
 * @return array{deleted: array, count: int, shipments: array}
 */
function wms_release_pick_tasks(PDO $shipPdo, array $taskPdos, array $shipmentIds, ?int $userId): array
{
    $shipmentIds = array_values(array_unique(array_map('intval', $shipmentIds)));
    $shipmentIds = array_values(array_filter($shipmentIds, fn($v) => $v > 0));
    if (!$shipmentIds) {
        throw new RuntimeException('Выберите отгрузки');
    }
    if (!$taskPdos) {
        throw new RuntimeException('БД заданий недоступна');
    }
    $in = implode(',', $shipmentIds);
    $heads = $shipPdo->query("SELECT * FROM shipments WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
    if (count($heads) !== count($shipmentIds)) {
        throw new RuntimeException('Не все отгрузки найдены');
    }
    $docNoById = [];
    foreach ($heads as $h) {
        $docNoById[(int)$h['id']] = (string)($h['doc_no'] ?? ('#' . $h['id']));
    }
    // Счётчики резерва (ячейки/номенклатура) — для отката ручных резервов.
    require_once wms_pick_root() . '/shared/db.php';
    try {
        $storagePdo = wms_storage_pdo();
    } catch (Throwable) {
        $storagePdo = null;
    }
    try {
        $nomPdo = wms_nomenclature_pdo();
    } catch (Throwable) {
        $nomPdo = null;
    }

    // Все задания, связанные с выбранными отгрузками (по primary).
    $primary = $taskPdos[0];
    $linked = wms_find_pick_tasks($primary, $shipmentIds, array_values($docNoById));
    if (!$linked) {
        // Заданий нет, но флаг отбора мог остаться (напр. старый "Запустить сборку"
        // ставит launched_pick без создания заданий) — снимаем несогласованный флаг,
        // чтобы не было фантомного "резерва".
        $clearedDocs = [];
        foreach ($heads as $h) {
            if (trim((string)($h['launched_pick'] ?? '')) !== '') {
                try {
                    $shipPdo->prepare('UPDATE shipments SET launched_pick=NULL WHERE id=:id')->execute([':id' => (int)$h['id']]);
                    $clearedDocs[] = (string)($h['doc_no'] ?? ('#' . $h['id']));
                } catch (Throwable) {
                }
                try {
                    $shipPdo->prepare(
                        "INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'unpicking',:o,:n)"
                    )->execute([':s' => (int)$h['id'], ':u' => $userId, ':o' => (string)($h['launched_pick'] ?? ''), ':n' => 'флаг отбора снят, заданий не было']);
                } catch (Throwable) {
                }
            }
        }
        if ($clearedDocs) {
            return ['deleted' => [], 'count' => 0,
                'shipments' => array_map(fn($h) => ['id' => (int)$h['id'], 'doc_no' => (string)($h['doc_no'] ?? ''), 'reserve_cleared' => true], $heads),
                'note' => 'Заданий на отбор не найдено; несогласованный флаг отбора снят: ' . implode(', ', $clearedDocs)];
        }
        throw new RuntimeException('По документам нет заданий на отбор (резерв уже снят)');
    }

    // Отгрузки, которых коснётся удаление (включая соседей по волне).
    $touchedIds = [];
    foreach ($linked as $lt) {
        foreach ((array)$lt['shipment_ids'] as $sid) {
            $touchedIds[(int)$sid] = true;
        }
    }
    foreach ($shipmentIds as $sid) {
        $touchedIds[$sid] = true;
    }
    // doc_no соседей (для отчёта shared).
    $allHeads = [];
    try {
        $tin = implode(',', array_map('intval', array_keys($touchedIds)));
        foreach ($shipPdo->query("SELECT id, doc_no FROM shipments WHERE id IN ($tin)")->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $allHeads[(int)$h['id']] = (string)$h['doc_no'];
        }
    } catch (Throwable) {
    }

    $deleted = [];
    foreach ($taskPdos as $tPdo) {
        $tbl = wms_task_tables($tPdo);
        $t = $tbl['t'];
        $ti = $tbl['i'];
        $th = $tbl['h'];
        $batch = wms_find_pick_tasks($tPdo, $shipmentIds, array_values($docNoById));
        foreach ($batch as $bt) {
            $tid = (int)$bt['id'];
            // Ручной резерв: снимаем счётчики резерва (ячейка/номенклатура).
            try {
                $fl = $tPdo->prepare("SELECT flags, warehouse_id FROM $t WHERE id=:t");
                $fl->execute([':t' => $tid]);
                $row = $fl->fetch(PDO::FETCH_ASSOC);
                if ($row && str_contains((string)($row['flags'] ?? ''), 'manual')) {
                    $twh = (int)($row['warehouse_id'] ?? 0);
                    $it = $tPdo->prepare("SELECT sku, cell_from, plan_qty FROM $ti WHERE task_id=:t");
                    $it->execute([':t' => $tid]);
                    foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $ri) {
                        $qty = (float)($ri['plan_qty'] ?? 0);
                        if ($qty <= 0) {
                            continue;
                        }
                        $cell = trim((string)($ri['cell_from'] ?? ''));
                        if ($storagePdo && $cell !== '') {
                            try {
                                $storagePdo->prepare(
                                    'UPDATE storage_cells SET reserved_qty = CASE WHEN COALESCE(reserved_qty,0) >= :q THEN reserved_qty - :q ELSE 0 END
                                     WHERE code = :c AND warehouse_id = :w'
                                )->execute([':q' => $qty, ':c' => $cell, ':w' => $twh]);
                            } catch (Throwable) {
                            }
                        }
                        try {
                            $nomPdo->prepare(
                                'UPDATE products SET reserved_qty = CASE WHEN COALESCE(reserved_qty,0) >= :q THEN reserved_qty - :q ELSE 0 END
                                 WHERE sku = :s'
                            )->execute([':q' => $qty, ':s' => (string)($ri['sku'] ?? '')]);
                        } catch (Throwable) {
                        }
                    }
                }
            } catch (Throwable) {
            }
            try {
                $tPdo->prepare("DELETE FROM $ti WHERE task_id=:t")->execute([':t' => $tid]);
            } catch (Throwable) {
            }
            try {
                $tPdo->prepare("DELETE FROM $th WHERE task_id=:t")->execute([':t' => $tid]);
            } catch (Throwable) {
            }
            foreach (['tsd_task_locks', 'tsd_task_logs'] as $aux) {
                try {
                    $tPdo->exec("DELETE FROM $aux WHERE task_id=$tid");
                } catch (Throwable) {
                }
            }
            try {
                $tPdo->prepare("DELETE FROM $t WHERE id=:t")->execute([':t' => $tid]);
            } catch (Throwable) {
                continue;
            }
            if ($tPdo === $primary) {
                $extra = array_values(array_diff((array)$bt['shipment_ids'], $shipmentIds));
                $deleted[] = [
                    'id' => $tid, 'doc_no' => $bt['doc_no'], 'status' => $bt['status'],
                    'pick_mode' => $bt['pick_mode'],
                    'shared_with' => array_values(array_filter(array_map(fn($s) => $allHeads[(int)$s] ?? null, $extra))),
                ];
            }
        }
    }

    // Чистим launched_pick у затронутых отгрузок, если заданий по ним не осталось.
    $shipReport = [];
    foreach (array_keys($touchedIds) as $sid) {
        $remain = 0;
        foreach ($taskPdos as $tPdo) {
            $remain += count(wms_find_pick_tasks($tPdo, [$sid], [$allHeads[$sid] ?? ($docNoById[$sid] ?? '')]));
        }
        $cleared = false;
        if ($remain === 0) {
            try {
                $shipPdo->prepare('UPDATE shipments SET launched_pick=NULL WHERE id=:id')->execute([':id' => $sid]);
                $cleared = true;
            } catch (Throwable) {
            }
        }
        try {
            $shipPdo->prepare(
                "INSERT INTO shipment_history (shipment_id,user_id,action,old_value,new_value) VALUES (:s,:u,'unpicking',:o,:n)"
            )->execute([':s' => $sid, ':u' => $userId, ':o' => 'резерв', ':n' => 'резерв снят, удалено заданий: ' . count($deleted)]);
        } catch (Throwable) {
            try {
                $shipPdo->prepare(
                    "INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (:s,:u,'unpicking',NULL,NULL)"
                )->execute([':s' => $sid, ':u' => $userId]);
            } catch (Throwable) {
            }
        }
        $shipReport[] = ['id' => $sid, 'doc_no' => $allHeads[$sid] ?? ($docNoById[$sid] ?? ('#' . $sid)), 'reserve_cleared' => $cleared];
    }

    if (!$deleted) {
        throw new RuntimeException('Не удалено ни одного задания');
    }
    return ['deleted' => $deleted, 'count' => count($deleted), 'shipments' => $shipReport];
}
