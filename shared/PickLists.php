<?php
declare(strict_types=1);

// Списки заданий (ТСД «Отбор по зоне»).
//
// Алгоритм формирования:
//   1. Смотрим зону у ячеек: дополнительные зоны отбора ячейки (storage_cell_zones):
//      manual = «Ручной отбор», driver = «Отбор водителем». У ячейки может быть несколько зон.
//   2. По содержимому ячеек зоны (cell_content) получаем SKU и ячейку, где лежит товар.
//   3. Находим отгрузки в работе, у которых позиции (shipment_items) совпадают с SKU зоны.
//   4. Принцип формирования: в списке только одна отгрузка (нарядов у отгрузки может быть несколько).
//   5. Делим задания по типам, исходя из зоны ячеек:
//        ячейка с зоной manual -> список типа «ручной отбор», задание падает только роли Кладовщик;
//        ячейка с зоной driver -> список типа «отбор водителем», задание падает только роли Водитель.
//   6. Код списка: PK-000001, PK-000002, ... (буквы не меняются).
//
// Списки живут в БД заданий: task_lists (код, тип, отгрузка) + task_list_items (задания списка).

require_once __DIR__ . '/PickIssue.php';

/** Допустимые зоны отбора ячеек. */
function wms_pick_zone_types(): array
{
    return ['manual', 'driver'];
}

function wms_pick_zone_label(?string $z): string
{
    return match ($z) {
        'manual' => 'Ручной отбор',
        'driver' => 'Отбор водителем',
        default  => (string)$z,
    };
}

/** Роль ТСД, которой падает список этой зоны. */
function wms_pick_zone_role(string $z): string
{
    return $z === 'driver' ? 'driver' : 'storekeeper';
}

/** Зона отбора по роли ТСД (null — роль не определена). */
function wms_pick_zone_by_role(string $role): ?string
{
    $r = strtolower(trim($role));
    if ($r === 'driver' || $r === 'водитель') {
        return 'driver';
    }
    if ($r === 'storekeeper' || $r === 'кладовщик' || $r === 'admin' || $r === 'администратор') {
        return 'manual';
    }
    return null;
}

/**
 * Код списка: PK-000001, PK-000002, ... (буквы не меняются).
 */
function wms_pick_list_next_code(PDO $pdo): string
{
    try {
        $st = $pdo->prepare("SELECT code FROM task_lists WHERE code LIKE 'PK-%' ORDER BY code DESC LIMIT 1");
        $st->execute();
        $last = (string)($st->fetchColumn() ?: '');
        if ($last !== '' && preg_match('/(\d+)\s*$/', $last, $m)) {
            return 'PK-' . str_pad((string)(((int)$m[1]) + 1), 6, '0', STR_PAD_LEFT);
        }
    } catch (Throwable) {
    }
    return 'PK-000001';
}

/**
 * Ячейки с дополнительной зоной отбора.
 * @return array<string, array{id:int,code:string,zones:array<int,string>}> код ячейки => данные
 */
function wms_pick_zone_cells(PDO $storage, string $zoneType): array
{
    $out = [];
    try {
        $st = $storage->prepare(
            "SELECT sc.id, sc.code FROM storage_cell_zones cz
             JOIN storage_cells sc ON sc.id = cz.cell_id
             WHERE cz.zone = :z AND COALESCE(sc.is_archived,0) = 0
             ORDER BY sc.code"
        );
        $st->execute([':z' => $zoneType]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = trim((string)($r['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $out[$code] = ['id' => (int)$r['id'], 'code' => $code, 'zones' => []];
        }
    } catch (Throwable) {
        return [];
    }
    if (!$out) {
        return [];
    }
    // Все зоны этих ячеек (у ячейки может быть несколько зон).
    try {
        $ids = implode(',', array_map(fn($c) => (int)$c['id'], $out));
        $st = $storage->query("SELECT cell_id, zone FROM storage_cell_zones WHERE cell_id IN ($ids) ORDER BY id");
        $byId = [];
        foreach ($out as $code => $c) {
            $byId[(int)$c['id']] = $code;
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $code = $byId[(int)($r['cell_id'] ?? 0)] ?? null;
            if ($code === null) {
                continue;
            }
            $z = (string)($r['zone'] ?? '');
            if ($z !== '' && !in_array($z, $out[$code]['zones'], true)) {
                $out[$code]['zones'][] = $z;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * SKU в ячейках зоны: sku => [коды ячеек].
 * @param string[] $codes
 */
function wms_pick_zone_sku_cells(PDO $storage, array $codes): array
{
    $out = [];
    if (!$codes) {
        return $out;
    }
    try {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $storage->prepare(
            "SELECT cc.sku, sc.code FROM cell_content cc JOIN storage_cells sc ON sc.id = cc.cell_id
             WHERE sc.code IN ($in)"
        );
        $st->execute(array_values($codes));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $sku = trim((string)($r['sku'] ?? ''));
            $code = trim((string)($r['code'] ?? ''));
            if ($sku === '' || $code === '') {
                continue;
            }
            if (!isset($out[$sku])) {
                $out[$sku] = [];
            }
            if (!in_array($code, $out[$sku], true)) {
                $out[$sku][] = $code;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Задания отбора, привязанные ровно к одной отгрузке (построчный выпуск).
 * Волновые задания (на несколько отгрузок) в список не входят: в списке одна отгрузка.
 * @return array<int, array> id => задание
 */
function wms_pick_shipment_tasks(PDO $tasks, int $shipmentId, string $docNo): array
{
    $out = [];
    foreach (wms_find_pick_tasks($tasks, [$shipmentId], [$docNo]) as $t) {
        $sids = array_values(array_map('intval', (array)($t['shipment_ids'] ?? [])));
        if (count($sids) === 1 && (int)$sids[0] === $shipmentId) {
            $out[(int)$t['id']] = $t;
        }
    }
    return $out;
}

/**
 * Формирование списков заданий по зоне отбора.
 * @param PDO $storage БД ячеек
 * @param PDO $ship    БД отгрузок
 * @param PDO $tasks   БД заданий
 * @param string $zoneType manual|driver
 * @param int|null $userId
 * @return array{zone:string,lists:array,shipments:int,tasks:int,created:int,note:?string}
 */
function wms_pick_zone_build_lists(PDO $storage, PDO $ship, PDO $tasks, string $zoneType, ?int $userId = null): array
{
    $zoneType = strtolower(trim($zoneType));
    if (!in_array($zoneType, wms_pick_zone_types(), true)) {
        throw new RuntimeException('Недопустимая зона отбора: manual/driver');
    }
    wms_ensure_task_lists_schema($tasks);

    $res = ['zone' => $zoneType, 'lists' => [], 'shipments' => 0, 'tasks' => 0, 'created' => 0, 'note' => null];

    $cells = wms_pick_zone_cells($storage, $zoneType);
    if (!$cells) {
        $res['note'] = 'Нет ячеек с зоной отбора «' . wms_pick_zone_label($zoneType) . '»';
        return $res;
    }
    $skuCells = wms_pick_zone_sku_cells($storage, array_keys($cells));
    if (!$skuCells) {
        $res['note'] = 'В ячейках зоны нет товара';
        return $res;
    }

    // Отгрузки в работе.
    try {
        $ships = $ship->query(
            "SELECT id, doc_no, consignee, gate, status, priority, warehouse_id
             FROM shipments WHERE status IN ('created','assembling') ORDER BY id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $ships = [];
    }
    $created = 0;
    $taskCount = 0;
    $shipCount = 0;
    $skipped = [];

    foreach ($ships as $sh) {
        $sid = (int)$sh['id'];
        $docNo = (string)($sh['doc_no'] ?? ('#' . $sid));
        try {
            $items = $ship->prepare('SELECT sku, name, unit, code, qty FROM shipment_items WHERE shipment_id=:s ORDER BY id');
            $items->execute([':s' => $sid]);
            $sItems = $items->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $sItems = [];
        }
        if (!$sItems) {
            continue;
        }
        // Позиции отгрузки, товар которых лежит в ячейках зоны.
        $zoneSkus = [];
        foreach ($sItems as $it) {
            $sku = trim((string)($it['sku'] ?? ''));
            if ($sku !== '' && isset($skuCells[$sku])) {
                $zoneSkus[$sku] = $skuCells[$sku];
            }
        }
        if (!$zoneSkus) {
            continue;
        }
        $shipCount++;

        // Задания отбора по этой отгрузке (построчные — одна отгрузка на задание).
        $list = wms_pick_shipment_tasks($tasks, $sid, $docNo);
        if (!$list) {
            // Если по отгрузке уже есть волновые задания (на несколько отгрузок),
            // новые не выпускаем — иначе будет двойной отбор. Такая отгрузка в список не попадает.
            $any = wms_find_pick_tasks($tasks, [$sid], [$docNo]);
            if ($any) {
                $skipped[] = $docNo . ' (волновое задание)';
                continue;
            }
            // Заданий нет — выпускаем построчно, ячейку отбора берём из зоны.
            $resolver = function (string $sku) use ($zoneSkus): ?string {
                $cellsOfSku = $zoneSkus[$sku] ?? [];
                return $cellsOfSku ? (string)$cellsOfSku[0] : null;
            };
            try {
                wms_issue_pick_tasks($ship, [$tasks], [$sid], 'line', $userId, $resolver);
            } catch (Throwable) {
            }
            $list = wms_pick_shipment_tasks($tasks, $sid, $docNo);
        }
        if (!$list) {
            continue;
        }

        // Делим задания по типам отбора — исходя из зоны ячеек задания.
        $mine = [];
        foreach ($list as $t) {
            $tid = (int)$t['id'];
            $cell = '';
            try {
                $st = $tasks->prepare('SELECT cell_from FROM task_items WHERE task_id=:t ORDER BY id');
                $st->execute([':t' => $tid]);
                $cell = (string)($st->fetchColumn() ?: '');
            } catch (Throwable) {
            }
            $cell = trim($cell);
            $zones = $cells[$cell]['zones'] ?? [];
            if (!in_array($zoneType, $zones, true)) {
                continue; // ячейка не в этой зоне — задание в другой список
            }
            $mine[] = $tid;
        }
        if (!$mine) {
            continue;
        }
        $taskCount += count($mine);

        // Список отгрузки по этой зоне (одна отгрузка в списке).
        $listId = wms_pick_list_ensure($tasks, $sid, $docNo, $zoneType, $userId);
        if (!$listId) {
            continue;
        }
        wms_pick_list_sync_tasks($tasks, $listId, $mine);
        $res['lists'][] = wms_pick_list_row($tasks, $listId);
    }

    $res['shipments'] = $shipCount;
    $res['tasks'] = $taskCount;
    $res['created'] = $created;
    if ($skipped) {
        $res['skipped'] = $skipped;
        $res['note'] = 'Пропущены отгрузки с волновыми заданиями: ' . implode(', ', $skipped);
    }
    return $res;
}

/**
 * Найти или создать список отгрузки по зоне отбора.
 * @return int id списка (0 — не создался)
 */
function wms_pick_list_ensure(PDO $tasks, int $shipmentId, string $docNo, string $zoneType, ?int $userId = null): int
{
    try {
        $st = $tasks->prepare("SELECT id FROM task_lists WHERE shipment_id = :s AND type = :t LIMIT 1");
        $st->execute([':s' => $shipmentId, ':t' => $zoneType]);
        $id = $st->fetchColumn();
        if ($id !== false && $id !== null) {
            $tasks->prepare("UPDATE task_lists SET shipment_doc_no=:d, zone=:z, updated_at=datetime('now') WHERE id=:id")
                ->execute([':d' => $docNo, ':z' => $zoneType, ':id' => (int)$id]);
            return (int)$id;
        }
    } catch (Throwable) {
    }
    $code = wms_pick_list_next_code($tasks);
    $name = 'Отгрузка ' . $docNo . ' · ' . wms_pick_zone_label($zoneType);
    try {
        $tasks->prepare(
            "INSERT INTO task_lists (code, name, type, shipment_id, shipment_doc_no, zone, status, created_by, created_at, updated_at)
             VALUES (:c, :n, :t, :s, :d, :z, 'new', :u, datetime('now'), datetime('now'))"
        )->execute([
            ':c' => $code, ':n' => $name, ':t' => $zoneType, ':s' => $shipmentId,
            ':d' => $docNo, ':z' => $zoneType, ':u' => $userId,
        ]);
        return (int)$tasks->lastInsertId();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Синхронизировать задания списка: добавить новые, убрать лишние.
 * @param int[] $taskIds
 */
function wms_pick_list_sync_tasks(PDO $tasks, int $listId, array $taskIds): void
{
    $taskIds = array_values(array_unique(array_map('intval', $taskIds)));
    try {
        $st = $tasks->prepare('SELECT task_id FROM task_list_items WHERE list_id = :l');
        $st->execute([':l' => $listId]);
        $cur = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        foreach (array_diff($cur, $taskIds) as $old) {
            $tasks->prepare('DELETE FROM task_list_items WHERE list_id = :l AND task_id = :t')
                ->execute([':l' => $listId, ':t' => (int)$old]);
        }
        $seq = 0;
        foreach ($taskIds as $tid) {
            $seq++;
            $tasks->prepare(
                'INSERT INTO task_list_items (list_id, task_id, seq, created_at) VALUES (:l, :t, :s, datetime(\'now\'))'
            )->execute([':l' => $listId, ':t' => $tid, ':s' => $seq]);
        }
    } catch (Throwable) {
    }
}

/**
 * Агрегаты по спискам заданий — ОДНИМ запросом на набор списков.
 * Раньше для каждого списка выполнялось 4 подзапроса (N+1): на экране
 * ТСД со списками это десятки запросов на открытие.
 * @param int[] $listIds
 * @return array<int, array> — по id списка
 */
function wms_pick_list_rows(PDO $tasks, array $listIds): array
{
    $ids = array_values(array_unique(array_map('intval', $listIds)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids);
    $st = $tasks->prepare(
        "SELECT l.id, l.code, l.name, l.type, l.shipment_id, l.shipment_doc_no, l.zone, l.status, l.created_at,
                COUNT(li.id) AS tasks_count,
                COALESCE(SUM(CASE WHEN t.status IS NOT NULL AND t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END),0) AS tasks_open,
                COALESCE(SUM(t.plan_qty),0) AS plan_qty,
                COALESCE(SUM(t.fact_qty),0) AS fact_qty
         FROM task_lists l
         LEFT JOIN task_list_items li ON li.list_id = l.id
         LEFT JOIN tasks t ON t.id = li.task_id
         WHERE l.id IN ($in)
         GROUP BY l.id, l.code, l.name, l.type, l.shipment_id, l.shipment_doc_no, l.zone, l.status, l.created_at
         ORDER BY l.code"
    );
    $st->execute();
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['id']] = wms_pick_list_row_prepare($row);
    }
    return $out;
}

/** Приведение строки списка к типу и вычисление процента готовности. */
function wms_pick_list_row_prepare(array $row): array
{
    $row['id'] = (int)($row['id'] ?? 0);
    $row['tasks_count'] = (int)($row['tasks_count'] ?? 0);
    $row['tasks_open'] = (int)($row['tasks_open'] ?? 0);
    $row['plan_qty'] = (float)($row['plan_qty'] ?? 0);
    $row['fact_qty'] = (float)($row['fact_qty'] ?? 0);
    $row['pct'] = $row['plan_qty'] > 0
        ? (int)min(100, round($row['fact_qty'] / $row['plan_qty'] * 100))
        : ($row['fact_qty'] > 0 ? 100 : 0);
    $row['type_ru'] = wms_pick_zone_label((string)$row['type']);
    $row['role'] = wms_pick_zone_role((string)$row['type']);
    return $row;
}

/** Список с агрегатами по заданиям (один список). */
function wms_pick_list_row(PDO $tasks, int $listId): array
{
    $rows = wms_pick_list_rows($tasks, [$listId]);
    return $rows[$listId] ?? [];
}

/**
 * Списки заданий (по роли ТСД: кладовщик — ручной отбор, водитель — отбор водителем).
 * Списки по удалённым отгрузкам (связь с модулем «Отгрузки» нарушена) не показываем
 * и чистим: задание без отгрузки отбирать нельзя.
 * @param string $role пусто — без фильтра по роли
 * @param string $zone пусто — без фильтра по зоне
 * @return array<int, array>
 */
function wms_pick_lists_read(PDO $tasks, string $role = '', string $zone = ''): array
{
    wms_ensure_task_lists_schema($tasks);
    $zone = strtolower(trim($zone));
    if ($zone === '' && $role !== '') {
        $zone = (string)(wms_pick_zone_by_role($role) ?? '');
    }
    $out = [];
    try {
        if ($zone !== '') {
            $st = $tasks->prepare('SELECT id FROM task_lists WHERE type = :t ORDER BY code');
            $st->execute([':t' => $zone]);
        } else {
            $st = $tasks->query('SELECT id FROM task_lists ORDER BY code');
        }
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        $ids = [];
    }
    // Агрегаты по всем спискам — одним запросом (было 4 подзапроса на список).
    $rows = wms_pick_list_rows($tasks, $ids);
    // Связь с модулем «Отгрузки»: отгрузка должна существовать.
    $ship = null;
    try {
        require_once wms_pick_root() . '/shared/db.php';
        $ship = wms_shipments_pdo();
    } catch (Throwable) {
    }
    // Отгрузки для всех списков — одним запросом (было до двух на список).
    $shipById = [];
    $shipByDoc = [];
    if ($ship) {
        $sids = [];
        $docs = [];
        foreach ($rows as $r) {
            if ((int)($r['shipment_id'] ?? 0) > 0) {
                $sids[] = (int)$r['shipment_id'];
            }
            $dn = trim((string)($r['shipment_doc_no'] ?? ''));
            if ($dn !== '') {
                $docs[] = $dn;
            }
        }
        try {
            if ($sids) {
                $in = implode(',', array_unique($sids));
                $st = $ship->query("SELECT id, doc_no, consignee, gate, status FROM shipments WHERE id IN ($in)");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sh) {
                    $shipById[(int)$sh['id']] = $sh;
                }
            }
            if ($docs) {
                $in = "'" . implode("','", array_map(fn($d) => str_replace("'", "''", $d), array_unique($docs))) . "'";
                $st = $ship->query("SELECT id, doc_no, consignee, gate, status FROM shipments WHERE doc_no IN ($in)");
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sh) {
                    $shipByDoc[(string)$sh['doc_no']] = $sh;
                }
            }
        } catch (Throwable) {
        }
    }
    foreach ($ids as $id) {
        $row = $rows[$id] ?? null;
        if (!$row) {
            continue;
        }
        if ($ship) {
            $sh = null;
            $sid = (int)($row['shipment_id'] ?? 0);
            if ($sid > 0) {
                $sh = $shipById[$sid] ?? null;
            }
            if (!$sh) {
                $dn = trim((string)($row['shipment_doc_no'] ?? ''));
                if ($dn !== '') {
                    $sh = $shipByDoc[$dn] ?? null;
                }
            }
            if (!$sh) {
                // Отгрузка удалена из модуля «Отгрузки» — список и его задания больше неактуальны.
                wms_pick_list_drop($tasks, (int)$id);
                continue;
            }
            $row['shipment_id'] = $sh['id'];
            $row['shipment_doc_no'] = $sh['doc_no'];
            $row['consignee'] = $sh['consignee'];
            $row['gate'] = $sh['gate'];
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * Удалить список отбора вместе с его заданиями (отгрузка удалена из модуля «Отгрузки»).
 */
function wms_pick_list_drop(PDO $tasks, int $listId): void
{
    if ($listId <= 0) {
        return;
    }
    try {
        $tasks->prepare('DELETE FROM task_list_items WHERE list_id=:l')->execute([':l' => $listId]);
    } catch (Throwable) {
    }
    try {
        $tasks->prepare('DELETE FROM task_lists WHERE id=:l')->execute([':l' => $listId]);
    } catch (Throwable) {
    }
}

/**
 * Задания списка (для ТСД «Отбор по зоне»).
 * @return array<int, array>
 */
function wms_pick_list_tasks(PDO $tasks, int $listId): array
{
    $out = [];
    try {
        $st = $tasks->prepare(
            "SELECT t.id, t.doc_no, t.type, t.status, t.priority, t.doc_ref, t.plan_qty, t.fact_qty,
                    t.progress, t.cells_count, t.created_at,
                    lk.user_id AS lock_user, lk.user_fio AS lock_fio, lk.status AS lock_status,
                    li.seq AS seq
             FROM task_list_items li
             JOIN tasks t ON t.id = li.task_id
             LEFT JOIN tsd_task_locks lk ON lk.task_id = t.id
             WHERE li.list_id = :l ORDER BY li.seq, t.id"
        );
        $st->execute([':l' => $listId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['id'] = (int)$r['id'];
            $r['plan_qty'] = (float)$r['plan_qty'];
            $r['fact_qty'] = (float)$r['fact_qty'];
            $r['pct'] = $r['plan_qty'] > 0
                ? (int)min(100, round($r['fact_qty'] / $r['plan_qty'] * 100))
                : ($r['fact_qty'] > 0 ? 100 : 0);
            $out[] = $r;
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Наряды (заказы клиента) отгрузки списка — их может быть несколько.
 * @return array<int, array{doc_no:string, consignee:string}>
 */
function wms_pick_list_orders(PDO $ship, int $shipmentId): array
{
    $out = [];
    if ($shipmentId <= 0) {
        return $out;
    }
    try {
        $st = $ship->prepare(
            'SELECT co.doc_no, co.consignee FROM shipment_orders so
             JOIN client_orders co ON co.id = so.order_id
             WHERE so.shipment_id = :s ORDER BY co.doc_no'
        );
        $st->execute([':s' => $shipmentId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = ['doc_no' => (string)($r['doc_no'] ?? ''), 'consignee' => (string)($r['consignee'] ?? '')];
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Зоны отбора с агрегатами (для экрана «Отбор по зоне»).
 * @return array<int, array>
 */
function wms_pick_zones_summary(PDO $storage, PDO $tasks): array
{
    $out = [];
    foreach (wms_pick_zone_types() as $z) {
        $cells = wms_pick_zone_cells($storage, $z);
        $lists = 0;
        try {
            $st = $tasks->prepare('SELECT COUNT(*) FROM task_lists WHERE type = :t');
            $st->execute([':t' => $z]);
            $lists = (int)$st->fetchColumn();
        } catch (Throwable) {
        }
        $out[] = [
            'zone' => $z,
            'label' => wms_pick_zone_label($z),
            'role' => wms_pick_zone_role($z),
            'role_ru' => $z === 'driver' ? 'Водитель' : 'Кладовщик',
            'cells' => count($cells),
            'lists' => $lists,
        ];
    }
    return $out;
}
