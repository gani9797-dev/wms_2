<?php
declare(strict_types=1);
// Модуль 9 «Контрагенты и грузополучатели» — API.
// Открыть: /09-kontragenty-i-gruzopoluchateli/demo_sqlite.php?action=list
// Список:   GET  ?action=list&search=&ctype=&is_active=&page=&per_page=&sort=&dir=
// Карточка: GET  ?action=show&id=1
// Зоны:     GET  ?action=zones
// ГП:       GET  ?action=consignees&counterparty_id=1
// ПКМ:      POST ?action=store|update|delete|settings_save&id=1  (JSON-тело)
// Ошибка:   {"error":"..."} + HTTP 400

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Counterparties.php';

// PostgreSQL (основной режим) — общая БД WMS; SQLite-демо — общая БД номенклатуры
// (в ней сквозные таблицы counterparties/consignees подключены как wms_common.*).
$pdo = wms_use_pgsql() ? wms_pg_pdo() : wms_nomenclature_pdo();
wms_ensure_cached($pdo, 'ext', 'wms_ensure_ext_schema');

$CP = wms_ext_table($pdo, 'counterparties');
$CSG = wms_ext_table($pdo, 'consignees');

/** Типы контрагентов: код => подпись. */
function wms09_ctypes(): array
{
    return ['supplier' => 'Поставщик', 'client' => 'Клиент', 'consignee' => 'Грузополучатель'];
}

/** Подпись типа контрагента. */
function wms09_ctype_label(string $ct): string
{
    $m = wms09_ctypes();
    return $m[$ct] ?? $ct;
}

/** Таблица товаров (в PostgreSQL — public.products, в SQLite — номенклатура). */
function wms09_products_table(PDO $pdo): ?string
{
    foreach (['products', wms_ext_table($pdo, 'products')] as $t) {
        if ($t && wms_has_column($pdo, $t, 'contractor_id')) {
            return $t;
        }
    }
    return null;
}

/** Нормализовать настройки контрагента (принцип резервирования, флаги с/н, списки). */
function wms09_row_settings(array $row): array
{
    $s = strtoupper(trim((string)($row['reserve_strategy'] ?? '')));
    if (!in_array($s, WmsCounterparties::STRATEGIES, true)) {
        $s = WmsCounterparties::DEFAULT_STRATEGY;
    }
    return [
        'reserve_strategy' => $s,
        'scan_serial_in' => (int)($row['scan_serial_in'] ?? 0) ? 1 : 0,
        'scan_serial_out' => (int)($row['scan_serial_out'] ?? 0) ? 1 : 0,
        'placement_zones' => WmsCounterparties::splitList($row['placement_zones'] ?? null),
        'placement_cell_codes' => WmsCounterparties::splitList($row['placement_cell_codes'] ?? null),
    ];
}

/** Статистика клиента: число артикулов, резерв, остаток. */
function wms09_stats(PDO $pdo, int $id): array
{
    $out = ['products_count' => 0, 'reserved_qty' => 0.0, 'qty' => 0.0];
    $t = wms09_products_table($pdo);
    if (!$t || $id <= 0) {
        return $out;
    }
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS c, COALESCE(SUM(reserved_qty),0) AS r, COALESCE(SUM(qty),0) AS q
             FROM $t WHERE contractor_id = :id"
        );
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['products_count'] = (int)($r['c'] ?? 0);
        $out['reserved_qty'] = (float)($r['r'] ?? 0);
        $out['qty'] = (float)($r['q'] ?? 0);
    } catch (Throwable) {
    }
    return $out;
}

/** Грузополучатели контрагента (или все, если $id = 0). */
function wms09_consignees(PDO $pdo, int $id = 0): array
{
    $t = wms_ext_table($pdo, 'consignees');
    $out = [];
    try {
        if ($id > 0) {
            $st = $pdo->prepare("SELECT id, counterparty_id, name, address, phone FROM $t WHERE counterparty_id = :id ORDER BY name");
            $st->execute([':id' => $id]);
        } else {
            $st = $pdo->query("SELECT id, counterparty_id, name, address, phone FROM $t ORDER BY name");
        }
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'counterparty_id' => (int)($r['counterparty_id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'address' => (string)($r['address'] ?? ''),
                'phone' => (string)($r['phone'] ?? ''),
            ];
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Идемпотентный сид демо-контрагентов (срабатывает только при пустой таблице).
 */
function wms09_seed(PDO $pdo): void
{
    $cp = wms_ext_table($pdo, 'counterparties');
    $csg = wms_ext_table($pdo, 'consignees');
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM $cp")->fetchColumn() > 0) {
            return;
        }
    } catch (Throwable) {
        return;
    }

    // [наименование, ИНН, тип, телефон, активен, принцип, с/н вход, с/н выход, зоны, ячейки]
    $rows = [
        ['ООО «Эталон»', '7707083893', 'client', '+7 495 120-45-67', 1, 'FIFO', 1, 1, 'storage,ship', 'A3-100-06-1,A3-100-06-2'],
        ['ООО «СпортОпт»', '500100732259', 'supplier', '+7 495 330-12-90', 1, 'FEFO', 0, 0, 'storage', ''],
        ['ИП Иванов', '771234567890', 'client', '+7 916 555-77-88', 1, 'LIFO', 0, 1, 'storage,ship', ''],
        ['ООО «Вектор»', '7802314567', 'consignee', '+7 495 777-00-11', 1, 'FIFO', 0, 0, '', ''],
    ];

    try {
        $ins = $pdo->prepare(
            wms_insert_ignore_prefix($pdo) . " $cp
                (name, inn, ctype, phone, is_active, reserve_strategy, scan_serial_in, scan_serial_out,
                 placement_zones, placement_cell_codes)
              VALUES (:name,:inn,:ctype,:phone,:act,:rs,:si,:so,:pz,:pc)" . wms_insert_ignore_suffix($pdo)
        );
        foreach ($rows as $r) {
            $ins->execute([
                ':name' => $r[0], ':inn' => $r[1], ':ctype' => $r[2], ':phone' => $r[3], ':act' => $r[4],
                ':rs' => $r[5], ':si' => $r[6], ':so' => $r[7],
                ':pz' => $r[8] !== '' ? $r[8] : null,
                ':pc' => $r[9] !== '' ? $r[9] : null,
            ]);
        }

        // Грузополучатели — по одному на контрагента.
        $cins = $pdo->prepare(
            wms_insert_ignore_prefix($pdo) . " $csg (counterparty_id, name, address, phone)
              VALUES (:cid,:name,:addr,:phone)" . wms_insert_ignore_suffix($pdo)
        );
        foreach ($rows as $k => $r) {
            $cid = wms09_counterparty_id($pdo, $r[0]);
            if (!$cid) {
                continue;
            }
            $cins->execute([
                ':cid' => $cid,
                ':name' => $r[0] . ' — грузополучатель',
                ':addr' => 'Москва, ул. Складочная, д. ' . (10 + $k),
                ':phone' => $r[3],
            ]);
        }

        // Артикулы привязываем к контрагенту-владельцу (в номенклатуре есть поле owner),
        // чтобы в реестре были видны резерв и количество артикулов клиента.
        $pt = wms09_products_table($pdo);
        if ($pt) {
            foreach ($rows as $r) {
                $cid = wms09_counterparty_id($pdo, $r[0]);
                if (!$cid) {
                    continue;
                }
                $st = $pdo->prepare("UPDATE $pt SET contractor_id = :cid WHERE owner = :own AND (contractor_id IS NULL OR contractor_id = 0)");
                $st->execute([':cid' => $cid, ':own' => $r[0]]);
                // Глобальные флаги клиента наследуются его артикулами.
                WmsCounterparties::applyFlagsToProducts($pdo, $cid);
            }
        }
    } catch (Throwable) {
    }
}

/** id контрагента по наименованию (для сида). */
function wms09_counterparty_id(PDO $pdo, string $name): int
{
    $cp = wms_ext_table($pdo, 'counterparties');
    try {
        return (int)$pdo->query("SELECT id FROM $cp WHERE name = " . $pdo->quote($name) . " LIMIT 1")->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

wms09_seed($pdo);

$action = (string)($_GET['action'] ?? 'list');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$raw = $isPost ? (string)file_get_contents('php://input') : '';
$body = json_decode($raw, true);
if (!is_array($body)) {
    // Не-JSON тело — принимаем form-кодирование.
    $body = [];
    if ($raw !== '') {
        parse_str($raw, $body);
    }
}
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        $search = trim((string)($_GET['search'] ?? ''));
        $ctype = trim((string)($_GET['ctype'] ?? ''));
        $isActive = trim((string)($_GET['is_active'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = wms_per_page($_GET['per_page'] ?? 20, 20, 200, 1);
        $sort = (string)($_GET['sort'] ?? 'name');
        $dir = strtoupper((string)($_GET['dir'] ?? 'ASC'));

        $where = ' WHERE 1=1';
        $params = [];
        $active = 0;
        if ($search !== '') {
            $where .= " AND (LOWER(c.name) LIKE LOWER(:s) OR LOWER(COALESCE(c.inn,'')) LIKE LOWER(:s) OR LOWER(COALESCE(c.phone,'')) LIKE LOWER(:s))";
            $params[':s'] = '%' . $search . '%';
            $active++;
        }
        if ($ctype !== '' && $ctype !== 'all') {
            $where .= ' AND c.ctype = :ctype';
            $params[':ctype'] = $ctype;
            $active++;
        }
        if ($isActive !== '' && $isActive !== 'all') {
            $where .= ' AND c.is_active = :act';
            $params[':act'] = ((int)$isActive) ? 1 : 0;
            $active++;
        }

        $prodTbl = wms09_products_table($pdo);
        $join = ' LEFT JOIN (SELECT contractor_id, SUM(reserved_qty) AS reserved_qty, COUNT(*) AS products_count'
            . ($prodTbl ? " FROM $prodTbl WHERE contractor_id IS NOT NULL GROUP BY contractor_id" : ' FROM (SELECT 1) x WHERE 1=0')
            . ') p ON p.contractor_id = c.id';

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM $CP c $where");
        foreach ($params as $k => $v) {
            $cnt->bindValue($k, $v);
        }
        $cnt->execute();
        $total = (int)$cnt->fetchColumn();

        $sortMap = [
            'name' => ['c.name', 't'], 'inn' => ['c.inn', 't'], 'ctype' => ['c.ctype', 't'],
            'phone' => ['c.phone', 't'], 'reserve' => ['c.reserve_strategy', 't'],
            'reserved' => ['COALESCE(p.reserved_qty,0)', 'n'], 'products' => ['COALESCE(p.products_count,0)', 'n'],
            'active' => ['c.is_active', 'n'], 'id' => ['c.id', 'n'],
        ];
        $orderBy = wms_order_by($pdo, $sort, $dir, $sortMap, 'c.name');

        $offset = ($page - 1) * $perPage;
        $st = $pdo->prepare("SELECT c.*, COALESCE(p.reserved_qty,0) AS reserved_qty, COALESCE(p.products_count,0) AS products_count
                              FROM $CP c $join $where ORDER BY $orderBy LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $s = wms09_row_settings($r);
            $rows[] = [
                'id' => (int)($r['id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'inn' => (string)($r['inn'] ?? ''),
                'ctype' => (string)($r['ctype'] ?? ''),
                'ctype_label' => wms09_ctype_label((string)($r['ctype'] ?? '')),
                'phone' => (string)($r['phone'] ?? ''),
                'is_active' => (int)($r['is_active'] ?? 0) ? 1 : 0,
                'reserve_strategy' => $s['reserve_strategy'],
                'scan_serial_in' => $s['scan_serial_in'],
                'scan_serial_out' => $s['scan_serial_out'],
                'placement_zones' => $s['placement_zones'],
                'placement_cell_codes' => $s['placement_cell_codes'],
                'reserved_qty' => (float)($r['reserved_qty'] ?? 0),
                'products_count' => (int)($r['products_count'] ?? 0),
            ];
        }

        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $perPage, $total);
        echo json_encode([
            'rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to, 'active_filters' => $active,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'show') {
        $id = (int)($_GET['id'] ?? 0);
        $row = WmsCounterparties::find($pdo, $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Контрагент не найден'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $s = wms09_row_settings($row);
        echo json_encode([
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'inn' => (string)($row['inn'] ?? ''),
            'ctype' => (string)($row['ctype'] ?? ''),
            'ctype_label' => wms09_ctype_label((string)($row['ctype'] ?? '')),
            'phone' => (string)($row['phone'] ?? ''),
            'is_active' => (int)($row['is_active'] ?? 0) ? 1 : 0,
            'settings' => $s,
            'stats' => wms09_stats($pdo, $id),
            'consignees' => wms09_consignees($pdo, $id),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'zones') {
        $rows = [];
        try {
            $st = $pdo->query("SELECT code, name FROM storage_zones WHERE COALESCE(is_active,1)=1 ORDER BY code");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $rows[] = ['code' => (string)($r['code'] ?? ''), 'name' => (string)($r['name'] ?? '')];
            }
        } catch (Throwable) {
        }
        echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'consignees') {
        $cid = (int)($_GET['counterparty_id'] ?? 0);
        echo json_encode(['rows' => wms09_consignees($pdo, $cid)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'store' && $isPost) {
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Наименование обязательно');
        }
        $ct = (string)($body['ctype'] ?? 'client');
        if (!array_key_exists($ct, wms09_ctypes())) {
            $ct = 'client';
        }
        $rs = strtoupper(trim((string)($body['reserve_strategy'] ?? WmsCounterparties::DEFAULT_STRATEGY)));
        if (!in_array($rs, WmsCounterparties::STRATEGIES, true)) {
            $rs = WmsCounterparties::DEFAULT_STRATEGY;
        }
        $st = $pdo->prepare(
            "INSERT INTO $CP (name, inn, ctype, phone, is_active, reserve_strategy, scan_serial_in, scan_serial_out,
                    placement_zones, placement_cell_codes)
             VALUES (:name,:inn,:ctype,:phone,:act,:rs,:si,:so,:pz,:pc)"
        );
        $st->execute([
            ':name' => $name,
            ':inn' => trim((string)($body['inn'] ?? '')) ?: null,
            ':ctype' => $ct,
            ':phone' => trim((string)($body['phone'] ?? '')) ?: null,
            ':act' => (int)($body['is_active'] ?? 1) ? 1 : 0,
            ':rs' => $rs,
            ':si' => 0,
            ':so' => 0,
            ':pz' => null,
            ':pc' => null,
        ]);
        $id = (int)$pdo->lastInsertId();
        if (isset($body['placement_zones']) || isset($body['placement_cell_codes'])
            || isset($body['scan_serial_in']) || isset($body['scan_serial_out'])) {
            WmsCounterparties::save($pdo, $id, $body);
        }
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'update' && $isPost) {
        $id = (int)($_GET['id'] ?? 0);
        $cur = WmsCounterparties::find($pdo, $id);
        if (!$cur) {
            throw new RuntimeException('Контрагент не найден');
        }
        $sets = [];
        $params = [':id' => $id];
        if (array_key_exists('name', $body)) {
            $name = trim((string)$body['name']);
            if ($name === '') {
                throw new RuntimeException('Наименование обязательно');
            }
            $sets[] = 'name = :name';
            $params[':name'] = $name;
        }
        if (array_key_exists('inn', $body)) {
            $sets[] = 'inn = :inn';
            $params[':inn'] = trim((string)$body['inn']) ?: null;
        }
        if (array_key_exists('ctype', $body)) {
            $ct = (string)$body['ctype'];
            if (!array_key_exists($ct, wms09_ctypes())) {
                $ct = 'client';
            }
            $sets[] = 'ctype = :ctype';
            $params[':ctype'] = $ct;
        }
        if (array_key_exists('phone', $body)) {
            $sets[] = 'phone = :phone';
            $params[':phone'] = trim((string)$body['phone']) ?: null;
        }
        if (array_key_exists('is_active', $body)) {
            $sets[] = 'is_active = :act';
            $params[':act'] = (int)$body['is_active'] ? 1 : 0;
        }
        if ($sets) {
            $st = $pdo->prepare("UPDATE $CP SET " . implode(', ', $sets) . " WHERE id = :id");
            $st->execute($params);
        }
        // Настройки — только если они переданы.
        if (isset($body['reserve_strategy']) || isset($body['scan_serial_in']) || isset($body['scan_serial_out'])
            || isset($body['placement_zones']) || isset($body['placement_cell_codes'])) {
            WmsCounterparties::save($pdo, $id, $body);
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'settings_save' && $isPost) {
        $id = (int)($_GET['id'] ?? 0);
        $s = WmsCounterparties::save($pdo, $id, $body);
        echo json_encode(['ok' => true, 'settings' => $s], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete' && $isPost) {
        $id = (int)($_GET['id'] ?? 0);
        $cur = WmsCounterparties::find($pdo, $id);
        if (!$cur) {
            throw new RuntimeException('Контрагент не найден');
        }
        $csg = wms09_consignees($pdo, $id);
        if ($csg) {
            throw new RuntimeException('У контрагента есть грузополучатели — удалите их перед удалением контрагента');
        }
        $stats = wms09_stats($pdo, $id);
        if ((int)$stats['products_count'] > 0) {
            throw new RuntimeException('У контрагента есть артикулы (' . (int)$stats['products_count'] . ') — удаление невозможно');
        }
        $pdo->prepare("DELETE FROM $CP WHERE id = :id")->execute([':id' => $id]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action, use ?action=list|show|zones|consignees|store|update|delete|settings_save&id=1'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
