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

/** @return PDO[] все существующие файлы БД заданий (07 + legacy tasks) */
function wms_tasks_pdos(): array
{
    $out = [];
    foreach ([wms_pick_root() . '/07-zadaniya/wms_tasks.sqlite', wms_pick_root() . '/tasks/wms_tasks.sqlite'] as $f) {
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
 */
function wms_pick_resolve_cell(PDO $storagePdo, string $sku, int $warehouseId, int $seedIdx): string
{
    try {
        $st = $storagePdo->prepare(
            "SELECT sc.code FROM cell_content cc JOIN storage_cells sc ON sc.id = cc.cell_id
             WHERE cc.sku = :sku AND sc.warehouse_id = :wh AND sc.is_archived = 0 AND sc.is_locked = 0
             ORDER BY cc.qty DESC LIMIT 1"
        );
        $st->execute([':sku' => $sku, ':wh' => $warehouseId]);
        $code = $st->fetchColumn();
        if ($code) {
            return (string)$code;
        }
    } catch (Throwable) {
    }
    try {
        $rows = $storagePdo->prepare(
            "SELECT code FROM storage_cells WHERE warehouse_id = :wh AND is_archived = 0 AND is_locked = 0 ORDER BY code"
        );
        $rows->execute([':wh' => $warehouseId]);
        $all = $rows->fetchAll(PDO::FETCH_COLUMN);
        if ($all) {
            return (string)$all[$seedIdx % count($all)];
        }
    } catch (Throwable) {
    }
    return 'СКЛАД-' . $warehouseId;
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
 * Выпуск заданий на отбор.
 * @param PDO $shipPdo  БД отгрузок (shipments)
 * @param PDO[] $taskPdos БД заданий (primary = [0])
 * @param int[] $shipmentIds
 * @param string $mode wave|line
 * @return array{tasks: array, mode: string, gate: ?string}
 */
function wms_issue_pick_tasks(PDO $shipPdo, array $taskPdos, array $shipmentIds, string $mode, ?int $userId): array
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
    $seed = 0;
    foreach ($groups as $g) {
        if ((float)$g['qty'] <= 0) {
            continue;
        }
        $wh = (int)$g['warehouse_id'] ?: 1;
        $cell = $storagePdo ? wms_pick_resolve_cell($storagePdo, $g['sku'], $wh, $seed++) : ('СКЛАД-' . $wh);
        $docRef = $mode === 'wave' ? implode(', ', $g['doc_nos']) : $g['doc_nos'][0];
        $modeRu = $mode === 'wave' ? 'волной' : 'построчно';
        $comment = 'Отбор ' . $modeRu . ' из отгрузок: ' . implode(', ', $g['doc_nos'])
            . ($g['gate'] ? (' · ворота ' . $g['gate']) : '');
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
                    'qty' => (float)$g['qty'], 'cell' => $cell,
                    'level' => wms_pick_cell_level($cell),
                    'shipments' => $g['doc_nos'], 'doc_ref' => $docRef,
                ];
            }
        }
    }

    // --- Пометить отгрузки: запущен отбор ---
    foreach ($heads as $h) {
        $sid = (int)$h['id'];
        try {
            // SQLite и MySQL: datetime('now') только SQLite, NOW() только MySQL.
            $drv = $shipPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $fn = $drv === 'sqlite' ? "datetime('now')" : 'NOW()';
            $shipPdo->prepare("UPDATE shipments SET launched_pick=:m, assembly_start=COALESCE(assembly_start,$fn) WHERE id=:id")
                ->execute([':m' => $mode === 'wave' ? 'Волна' : 'Строки', ':id' => $sid]);
        } catch (Throwable) {
        }
        try {
            $cur = (string)($h['status'] ?? '');
            if (in_array($cur, ['draft', 'created'], true)) {
                $shipPdo->prepare('UPDATE shipments SET status=\'assembling\' WHERE id=:id')->execute([':id' => $sid]);
            }
        } catch (Throwable) {
        }
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

    if (!$created) {
        throw new RuntimeException('Не создано заданий (пустые количества)');
    }
    return ['tasks' => $created, 'mode' => $mode, 'gate' => $gate, 'count' => count($created)];
}

/** Экранирование для LIKE. */
function wms_pick_like_esc(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
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
    $where = ["flags LIKE '%pick%'"];
    if ($hasSids) {
        $where[] = "(shipment_ids IS NOT NULL AND shipment_ids<>'')";
    }
    $rows = $tPdo->query("SELECT * FROM $t WHERE " . implode(' OR ', $where))->fetchAll(PDO::FETCH_ASSOC);
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
