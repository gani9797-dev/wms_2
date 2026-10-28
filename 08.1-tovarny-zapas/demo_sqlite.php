<?php
declare(strict_types=1);
// Модуль 8.1 "Товарный запас": реестр остатков по ячейкам и паллетам.
// Своей БД нет — читаем БД номенклатуры (product_stock + products).
// Открыть: /08.1-tovarny-zapas/demo_sqlite.php?action=list&search=1315
// Резерв по каждой строке тянем из заданий на отбор (tasks DB) одним запросом.
// Тара (pallet_type) хранится на строке запаса product_stock.pallet_type (euro/fin),
// при отсутствии — берётся из карточки товара products.pallet_type, по умолчанию euro.
// Смена тары: POST ?action=pallet_update {stock_id, pallet_type} с проверкой
// storage_cells.allowed_pallet_types — иначе ошибка «изменение паллеты невозможно...».
// Дата производства партии — product_stock.prod_date; срок хранения артикула —
// products.shelf_life_days; контроль даты производства — products.control_prod_date.
// Дата окончания срока = prod_date + shelf_life_days, осталось дней = дата - CURRENT_DATE.

require_once __DIR__ . '/../shared/db.php';

$pdo = wms_nomenclature_pdo();
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

// --- Справочник тары: габариты и вес самой паллеты ---
// EUR 1200×800, FIN 1200×1000 (см. 13-Настройки: EUR до 1500 кг, FIN до 1200 кг).
function stock_pallet_specs(): array
{
    return [
        'euro' => ['code' => 'euro', 'label' => 'Евро', 'length_mm' => 1200, 'width_mm' => 800, 'height_mm' => 144, 'tare_kg' => 25, 'max_weight_kg' => 1500],
        'fin'  => ['code' => 'fin',  'label' => 'Фин',  'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 138, 'tare_kg' => 25, 'max_weight_kg' => 1200],
    ];
}

function stock_norm_pallet_type(?string $v): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($v ?? '')), 'UTF-8') : strtolower(trim((string)($v ?? '')));
    if (in_array($s, ['евро', 'euro', 'eur', 'е', 'eur-паллет', 'eur pallet'], true)) {
        return 'euro';
    }
    if (in_array($s, ['фин', 'финская', 'финский', 'fin', 'fan', 'ф', 'fin-паллет'], true)) {
        return 'fin';
    }
    return $s !== '' ? $s : 'euro';
}

function stock_pallet_label(string $code): string
{
    $specs = stock_pallet_specs();
    return $specs[$code]['label'] ?? $code;
}

/** Миграция: колонки строки запаса. Портабельно (SQLite/MySQL/PostgreSQL). */
function stock_ensure_pallet_col(PDO $pdo): void
{
    // PRAGMA работает только в SQLite, поэтому на PostgreSQL/MySQL модуль
    // падал на запросах к несуществующим колонкам. wms_ensure_column проверяет
    // реальную схему нужным для драйвера способом.
    wms_ensure_column($pdo, 'product_stock', 'pallet_type', 'VARCHAR(10) NULL', 'pallet_type TEXT', 'VARCHAR(10) NULL');
    wms_ensure_column($pdo, 'product_stock', 'supplier_batch', 'VARCHAR(100) NULL', 'supplier_batch TEXT', 'VARCHAR(100) NULL');
    wms_ensure_column($pdo, 'product_stock', 'pallet_date', 'DATE NULL', 'pallet_date TEXT', 'DATE NULL');
    wms_ensure_column($pdo, 'product_stock', 'quality', "VARCHAR(20) NULL DEFAULT 'новый'", "quality TEXT DEFAULT 'новый'", "VARCHAR(20) NULL DEFAULT 'новый'");
    wms_ensure_column($pdo, 'product_stock', 'receipt_no', 'VARCHAR(50) NULL', 'receipt_no TEXT', 'VARCHAR(50) NULL');
    wms_ensure_column($pdo, 'product_stock', 'receipt_id', 'BIGINT NULL', 'receipt_id INTEGER', 'BIGINT NULL');
}

stock_ensure_pallet_col($pdo);

/**
 * Дотягиваем колонки, нужные для срока хранения:
 *   product_stock.prod_date      — дата производства партии (уже есть в shared/db.php),
 *   products.shelf_life_days      — срок хранения артикула в днях,
 *   products.control_prod_date    — флаг контроля даты производства.
 * Идемпотентно и безопасно на каждый запрос.
 */
function stock_ensure_shelf_cols(PDO $pdo): void
{
    if (!function_exists('wms_ensure_column')) {
        return;
    }
    wms_ensure_column($pdo, 'product_stock', 'prod_date', 'DATE NULL', 'prod_date TEXT', 'DATE NULL');
    wms_ensure_column($pdo, 'products', 'shelf_life_days', 'INTEGER NULL', 'shelf_life_days INTEGER', 'INTEGER NULL');
    wms_ensure_column($pdo, 'products', 'control_prod_date', 'TINYINT(1) NOT NULL DEFAULT 0', 'control_prod_date INTEGER DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
}

stock_ensure_shelf_cols($pdo);

/**
 * SQL-выражения срока хранения (портабельно: pgsql / mysql / sqlite).
 *   expire — дата окончания срока = prod_date + shelf_life_days (NULL, если нет даты/срока);
 *   days   — сколько дней осталось хранить = дата окончания срока - CURRENT_DATE
 *            (целое, может быть отрицательным — просрочено; NULL, если нечего считать).
 */
function stock_shelf_sql(PDO $pdo): array
{
    $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $cond = 's.prod_date IS NOT NULL AND p.shelf_life_days IS NOT NULL';
    if ($drv === 'mysql') {
        $exp = 'DATE_ADD(s.prod_date, INTERVAL p.shelf_life_days DAY)';
        $days = 'DATEDIFF(DATE_ADD(s.prod_date, INTERVAL p.shelf_life_days DAY), CURDATE())';
    } elseif ($drv === 'pgsql') {
        $exp = '(CAST(s.prod_date AS date) + p.shelf_life_days)';
        $days = '((CAST(s.prod_date AS date) + p.shelf_life_days) - CURRENT_DATE)';
    } else {
        $exp = "DATE(s.prod_date, '+' || p.shelf_life_days || ' days')";
        $days = "CAST(ROUND(julianday(DATE(s.prod_date, '+' || p.shelf_life_days || ' days')) - julianday('now','localtime')) AS INTEGER)";
    }
    return [
        'expire' => "CASE WHEN $cond THEN $exp END",
        'days'   => "CASE WHEN $cond THEN $days END",
    ];
}

/** Нормализация даты к строке YYYY-MM-DD (или null). */
function stock_date_norm($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $s = trim((string)$v);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return null;
}

/** Проверка: ячейка разрешает данный тип тары. */
function stock_cell_allows(?string $allowedRaw, string $palletType): bool
{
    $raw = trim((string)($allowedRaw ?? 'euro,fin'));
    if ($raw === '' || $raw === 'Все' || $raw === '*') {
        return true;
    }
    $parts = preg_split('/[,\s;\/]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $parts = array_map(fn($s) => stock_norm_pallet_type((string)$s), $parts);
    return in_array(stock_norm_pallet_type($palletType), $parts, true);
}

/** Карта ячеек склада {warehouse_id|cell => [allowed_pallet_types, pallet_type]} одним запросом. */
function stock_cells_map(array $rows): array
{
    $out = [];
    if (!$rows) {
        return $out;
    }
    try {
        $spdo = wms_storage_pdo();
        $keys = [];
        foreach ($rows as $r) {
            $k = ((int)($r['warehouse_id'] ?? 0)) . '|' . trim((string)($r['cell'] ?? ''));
            $keys[$k] = ['w' => (int)($r['warehouse_id'] ?? 0), 'c' => trim((string)($r['cell'] ?? ''))];
        }
        // Маленькими батчами по OR — ячеек на странице <= 100.
        $conds = [];
        $params = [];
        $i = 0;
        foreach (array_values($keys) as $kc) {
            if ($kc['c'] === '') {
                continue;
            }
            $conds[] = "(warehouse_id = :w$i AND code = :c$i)";
            $params[":w$i"] = $kc['w'];
            $params[":c$i"] = $kc['c'];
            $i++;
        }
        if (!$conds) {
            return $out;
        }
        $st = $spdo->prepare(
            'SELECT warehouse_id, code, allowed_pallet_types, pallet_type, cell_height_mm
             FROM storage_cells WHERE ' . implode(' OR ', $conds) . ' LIMIT 500'
        );
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $out[((int)$c['warehouse_id']) . '|' . trim((string)$c['code'])] = $c;
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Карта размещения паллет: {номер паллеты => [{warehouse_id, cell}, ...]}.
 * Правило склада: одна и та же паллета не может быть в разных ячейках
 * (несколько строк с одним номером в ОДНОЙ ячейке — норма: разные SKU на паллете).
 */
function stock_pallet_cells(PDO $pdo): array
{
    $out = [];
    try {
        $st = $pdo->query(
            "SELECT TRIM(pallet) AS pallet, warehouse_id, cell FROM product_stock
             WHERE pallet IS NOT NULL AND TRIM(pallet) <> ''
             GROUP BY TRIM(pallet), warehouse_id, cell"
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string)$r['pallet']][] = ['warehouse_id' => (int)$r['warehouse_id'], 'cell' => (string)$r['cell']];
        }
        try {
            $st->closeCursor();
        } catch (Throwable) {
        }
    } catch (Throwable) {
    }
    return $out;
}

/** Только нарушители правила: паллеты, сидящие в >1 различной ячейке. */
function stock_pallet_conflicts_list(array $palletCells): array
{
    $out = [];
    foreach ($palletCells as $pal => $places) {
        if (count($places) > 1) {
            $out[] = ['pallet' => $pal, 'cells' => array_values(array_unique(array_column($places, 'cell')))];
        }
    }
    return $out;
}

/** Обогащение строки запаса: тара + ВГХ + объём + вес + разбивка резерва + конфликт паллеты. */
function stock_enrich_row(array &$r, array $cellsMap, array $palletCells = []): void
{
    $specs = stock_pallet_specs();
    $pt = stock_norm_pallet_type((string)($r['pallet_type'] ?? $r['prod_pallet_type'] ?? 'euro'));
    if (!isset($specs[$pt])) {
        $pt = 'euro';
    }
    $spec = $specs[$pt];
    $r['pallet_type'] = $pt;
    $r['pallet_label'] = $spec['label'];
    $r['pallet_length_mm'] = $spec['length_mm'];
    $r['pallet_width_mm'] = $spec['width_mm'];
    $r['pallet_height_mm'] = (int)($r['prod_pallet_height_mm'] ?? 0) > 0
        ? (int)$r['prod_pallet_height_mm']
        : $spec['height_mm'];
    // Высота товара + паллеты.
    $goodsH = (int)($r['height_mm'] ?? 0);
    $r['total_height_mm'] = $goodsH > 0 ? $goodsH + (int)$spec['height_mm'] : (int)$spec['height_mm'];
    // Линейные габариты — в метрах.
    $r['pallet_length_m'] = round($spec['length_mm'] / 1000, 3);
    $r['pallet_width_m'] = round($spec['width_mm'] / 1000, 3);
    $r['total_height_m'] = round($r['total_height_mm'] / 1000, 3);
    // Объём паллеты с товаром, м3.
    $r['volume_m3'] = round($r['pallet_length_m'] * $r['pallet_width_m'] * $r['total_height_m'], 3);
    // Вес брутто, кг: товар (qty × вес шт) + тара (если есть номер паллеты).
    $qty = (float)($r['qty'] ?? 0);
    $unitG = (float)($r['weight_g'] ?? 0);
    $goodsKg = $unitG > 0 ? $qty * $unitG / 1000 : 0.0;
    $hasPallet = trim((string)($r['pallet'] ?? '')) !== '';
    $r['weight_kg'] = round($goodsKg + ($hasPallet ? (float)$spec['tare_kg'] : 0.0), 2);
    $r['goods_weight_kg'] = round($goodsKg, 2);
    // Ячейка: что разрешено / что стоит.
    $ck = ((int)($r['warehouse_id'] ?? 0)) . '|' . trim((string)($r['cell'] ?? ''));
    $cell = $cellsMap[$ck] ?? null;
    $r['cell_allowed'] = $cell['allowed_pallet_types'] ?? 'euro,fin';
    $r['cell_pallet_type'] = $cell['pallet_type'] ?? null;
    $r['cell_allows_current'] = stock_cell_allows($r['cell_allowed'], $pt);
    // Резерв: разбивка на «Отгрузка» (номера через запятую) и «Резерв» (сумма).
    $res = $r['reserve'] ?? [];
    $docs = [];
    $sum = 0.0;
    foreach ((array)$res as $x) {
        $dn = trim((string)($x['doc_no'] ?? ''));
        if ($dn !== '' && !in_array($dn, $docs, true)) {
            $docs[] = $dn;
        }
        $sum += (float)($x['qty'] ?? 0);
    }
    sort($docs);
    $r['reserve_docs'] = $docs;
    $r['reserve_docs_str'] = implode(', ', $docs);
    $r['reserve_qty'] = round($sum, 3);
    // Контроль уникальности паллеты: один номер — одна ячейка.
    $palNo = trim((string)($r['pallet'] ?? ''));
    $places = ($palNo !== '' && isset($palletCells[$palNo])) ? $palletCells[$palNo] : [];
    $r['pallet_cells'] = array_values(array_unique(array_column($places, 'cell')));
    $r['pallet_conflict'] = count($places) > 1;
    // Качество: 'новый' (по умолчанию) или 'Брак'.
    $r['quality'] = trim((string)($r['quality'] ?? 'новый'));
    if ($r['quality'] === '') {
        $r['quality'] = 'новый';
    }
    // --- Дата производства и срок хранения ---
    // prod_date — дата производства партии (product_stock.prod_date);
    // shelf_life_days — срок хранения артикула (products.shelf_life_days);
    // control_prod_date — флаг контроля даты производства (products.control_prod_date);
    // shelf_expire_date — prod_date + shelf_life_days;
    // shelf_days_left — сколько дней осталось хранить (может быть отрицательным).
    $r['prod_date'] = stock_date_norm($r['prod_date'] ?? null);
    $sl = $r['shelf_life_days'] ?? null;
    $r['shelf_life_days'] = ($sl === null || $sl === '') ? null : (int)$sl;
    $r['control_prod_date'] = (int)($r['control_prod_date'] ?? 0);
    $r['shelf_expire_date'] = stock_date_norm($r['shelf_expire_date'] ?? null);
    $dl = $r['shelf_days_left'] ?? null;
    $r['shelf_days_left'] = ($dl === null || $dl === '') ? null : (int)$dl;
    // Даты нет, а контроль на артикуле включён — UI подсветит незаполненную дату.
    $r['prod_date_required'] = ($r['control_prod_date'] === 1 && $r['prod_date'] === null) ? 1 : 0;
}

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
    $perPage = wms_per_page($_GET['per_page'] ?? 20, 20, 100, 1);
    $w = ['1=1'];
    $p = [];
    if (!empty($_GET['search'])) {
        $q = '%' . trim((string)$_GET['search']) . '%';
        $w[] = '(p.sku LIKE :q OR p.code LIKE :q OR p.name LIKE :q OR s.cell LIKE :q OR s.pallet LIKE :q OR s.supplier_batch LIKE :qb OR s.receipt_no LIKE :q)';
        $p[':q'] = $q;
        $p[':qb'] = $q;
    }
    if (!empty($_GET['warehouse_id'])) {
        $w[] = 's.warehouse_id = :wh';
        $p[':wh'] = (int)$_GET['warehouse_id'];
    }
    // Фильтр: список артикулов (через запятую) — например, позиции, которые
    // не удалось подобрать при резервировании (модуль «Отгрузки»).
    $fSkus = [];
    if (!empty($_GET['skus'])) {
        $parts = preg_split('/[,\s;]+/', trim((string)$_GET['skus']), -1, PREG_SPLIT_NO_EMPTY);
        foreach ((array)$parts as $s) {
            $s = trim((string)$s);
            if ($s !== '' && !in_array($s, $fSkus, true)) {
                $fSkus[] = $s;
            }
        }
    }
    if ($fSkus) {
        $ph = [];
        foreach ($fSkus as $i => $s) {
            $k = ':sk' . $i;
            $ph[] = $k;
            $p[$k] = $s;
        }
        $w[] = 'p.sku IN (' . implode(',', $ph) . ')';
    }
    // Фильтр: у артикула включён контроль даты производства.
    $fControl = trim((string)($_GET['control_prod_date'] ?? ''));
    if ($fControl !== '' && $fControl !== '0') {
        $w[] = 'COALESCE(p.control_prod_date, 0) = 1';
    }
    // Фильтр: наличие даты производства в партии (1 — указана, 0 — не указана).
    $fProdDate = trim((string)($_GET['prod_date'] ?? ($_GET['has_prod_date'] ?? '')));
    if ($fProdDate === '1') {
        $w[] = 's.prod_date IS NOT NULL';
    } elseif ($fProdDate === '0') {
        $w[] = 's.prod_date IS NULL';
    }
    $activeFilters = 0;
    if (!empty($_GET['search'])) {
        $activeFilters++;
    }
    if (!empty($_GET['warehouse_id'])) {
        $activeFilters++;
    }
    if ($fSkus) {
        $activeFilters++;
    }
    if ($fControl !== '' && $fControl !== '0') {
        $activeFilters++;
    }
    if ($fProdDate === '1' || $fProdDate === '0') {
        $activeFilters++;
    }
    $where = 'WHERE ' . implode(' AND ', $w);
    // Сортировка по любому столбцу реестра (А→Я / Я→А).
    // Габариты/объём/вес считаются так же, как в stock_enrich_row().
    $hExpr = "(CASE WHEN COALESCE(p.height_mm,0) > 0 THEN COALESCE(p.height_mm,0) ELSE 0 END)";
    $palH = "(CASE WHEN s.pallet_type = 'fin' THEN 138 ELSE 144 END)";
    $palW = "(CASE WHEN s.pallet_type = 'fin' THEN 1.0 ELSE 0.8 END)";
    $shelf = stock_shelf_sql($pdo);
    $sortMap = [
        'warehouse' => ['w.name', 't'], 'cell' => ['s.cell', 't'], 'pallet' => ['s.pallet', 't'],
        'batch' => ['s.supplier_batch', 't'], 'pallet_date' => ['s.pallet_date', 't'],
        'prod_date' => ['s.prod_date', 'd'], 'shelf_left' => [$shelf['expire'], 'd'],
        'pallet_type' => ['s.pallet_type', 't'], 'pallet_length' => ['s.pallet_type', 't'],
        'pallet_width' => [$palW, 'n'],
        'pallet_height' => ["($palH + $hExpr) / 1000.0", 'n'],
        'volume' => ["1.2 * $palW * (($palH + $hExpr) / 1000.0)", 'n'],
        'weight' => ["(COALESCE(p.weight_g,0) * COALESCE(s.qty,0) / 1000.0 + CASE WHEN COALESCE(s.pallet,'') <> '' THEN 25 ELSE 0 END)", 'n'],
        'product_code' => ['p.code', 't'], 'sku' => ['p.sku', 't'], 'qty' => ['s.qty', 'n'],
        'receipt' => ['s.receipt_no', 't'],
        'id' => ['s.id', 'n'],
    ];
    $orderBy = wms_order_by($pdo, (string)($_GET['sort'] ?? ''), (string)($_GET['dir'] ?? 'ASC'), $sortMap, 'p.sku, s.cell, s.id', 't');
    // Стабильный порядок при равных значениях (в т.ч. когда все даты пустые).
    if (strpos($orderBy, 's.id') === false) {
        $orderBy .= ', s.id';
    }
    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM product_stock s LEFT JOIN products p ON p.id = s.product_id $where"
    );
    $cnt->execute($p);
    $total = (int)$cnt->fetchColumn();
    $off = ($page - 1) * $perPage;
    // s.pallet_type может отсутствовать в старых файлах — миграция выше уже отработала.
    $st = $pdo->prepare(
        "SELECT s.id, s.cell, s.pallet, s.supplier_batch, s.pallet_date, s.pallet_type, s.qty, s.receipt_no, s.warehouse_id,
                w.name AS warehouse, p.sku, p.code AS product_code, p.name AS product_name, p.unit,
                p.length_mm, p.width_mm, p.height_mm, p.weight_g, p.volume_m3 AS prod_volume_m3,
                p.pallet_type AS prod_pallet_type, p.pallet_height_mm AS prod_pallet_height_mm,
                s.prod_date, p.shelf_life_days, p.control_prod_date,
                s.quality,
                {$shelf['expire']} AS shelf_expire_date, {$shelf['days']} AS shelf_days_left
         FROM product_stock s
         LEFT JOIN products p ON p.id = s.product_id
         LEFT JOIN warehouses w ON w.id = s.warehouse_id
         $where ORDER BY $orderBy LIMIT :lim OFFSET :off"
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
    $cellsMap = stock_cells_map($rows);
    // Контроль «одна паллета — одна ячейка» — по всей таблице, не только по странице.
    $palletCells = stock_pallet_cells($pdo);
    foreach ($rows as &$r) {
        stock_enrich_row($r, $cellsMap, $palletCells);
    }
    unset($r);
    echo json_encode([
        'rows' => $rows, 'total' => $total,
        'from' => $total ? $off + 1 : 0, 'to' => min($off + $perPage, $total),
        'active_filters' => $activeFilters,
        'pallet_specs' => stock_pallet_specs(),
        'conflicts' => stock_pallet_conflicts_list($palletCells),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
} elseif ($action === 'pallet_types') {
    echo json_encode(['rows' => array_values(stock_pallet_specs())], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'pallet_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = json_decode(file_get_contents('php://input'), true) ?? [];
    $stockId = (int)($b['stock_id'] ?? $b['id'] ?? 0);
    $wantRaw = (string)($b['pallet_type'] ?? '');
    $batch = trim((string)($b['supplier_batch'] ?? $b['batch'] ?? ''));
    $palletDate = trim((string)($b['pallet_date'] ?? ''));
    try {
        if ($stockId <= 0) {
            throw new RuntimeException('Не выбрана строка запаса');
        }
        $want = stock_norm_pallet_type($wantRaw);
        if (!in_array($want, ['euro', 'fin'], true)) {
            throw new RuntimeException('Тип тары: евро или фин');
        }
        $st = $pdo->prepare(
            'SELECT s.id, s.cell, s.warehouse_id, s.pallet, s.pallet_type, s.supplier_batch, s.pallet_date,
                    s.qty, s.quality, p.sku, p.name AS product_name
             FROM product_stock s LEFT JOIN products p ON p.id = s.product_id WHERE s.id = :id'
        );
        $st->execute([':id' => $stockId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Строка запаса не найдена');
        }
        try {
            $st->closeCursor();
        } catch (Throwable) {
        }
        // Правило «одна паллета — одна ячейка»: с конфликтной паллетой
        // никакие изменения запрещены, пока дубль не устранён.
        $palNo = trim((string)($row['pallet'] ?? ''));
        if ($palNo !== '') {
            $chk = $pdo->prepare('SELECT DISTINCT warehouse_id, cell FROM product_stock WHERE TRIM(pallet) = :p');
            $chk->execute([':p' => $palNo]);
            $places = $chk->fetchAll(PDO::FETCH_ASSOC);
            try {
                $chk->closeCursor();
            } catch (Throwable) {
            }
            if (count($places) > 1) {
                $names = array_values(array_unique(array_map(fn($x) => (string)$x['cell'], $places)));
                http_response_code(400);
                echo json_encode(['error' => 'Паллета «' . $palNo . '» находится в нескольких ячейках (' . implode(', ', $names) . '). Одна паллета не может быть в разных ячейках — сначала устраните дубль.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $cur = stock_norm_pallet_type((string)($row['pallet_type'] ?? ''));
        if ($cur === $want) {
            echo json_encode(['ok' => true, 'unchanged' => true, 'pallet_type' => $want], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Проверка ячейки: ячейка обязана принимать данный тип тары.
        // Важно: одно соединение к storage-БД на весь запрос (SQLite: второе
        // соединение упрётся в lock), курсоры закрываем до записи.
        $cellAllowed = 'euro,fin';
        $cellFound = false;
        $spdo = null;
        try {
            $spdo = wms_storage_pdo();
            try {
                $spdo->exec('PRAGMA busy_timeout = 5000');
            } catch (Throwable) {
            }
            $cst = $spdo->prepare('SELECT allowed_pallet_types, pallet_type FROM storage_cells WHERE code = :c AND warehouse_id = :w LIMIT 1');
            $cst->execute([':c' => trim((string)$row['cell']), ':w' => (int)$row['warehouse_id']]);
            if ($c = $cst->fetch(PDO::FETCH_ASSOC)) {
                $cellFound = true;
                $cellAllowed = (string)($c['allowed_pallet_types'] ?? 'euro,fin');
            }
            try {
                $cst->closeCursor();
            } catch (Throwable) {
            }
        } catch (Throwable) {
        }
        if (!stock_cell_allows($cellAllowed, $want)) {
            http_response_code(400);
            echo json_encode(['error' => 'Изменение паллеты невозможно, ячейка не предназначена для данного типа паллеты.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $pdo->prepare('UPDATE product_stock SET pallet_type = :pt, supplier_batch = :b, pallet_date = :d WHERE id = :id')
            ->execute([':pt' => $want, ':b' => $batch, ':d' => $palletDate !== '' ? $palletDate : null, ':id' => $stockId]);
        // Журнал склада: изменение паллеты/партии/даты паллеты — это изменение товара.
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            $changes = [];
            $oldPt = stock_norm_pallet_type((string)($row['pallet_type'] ?? ''));
            if ($oldPt !== $want) {
                $changes[] = 'тара ' . ($oldPt ?: '—') . ' → ' . $want;
            }
            $oldBatch = trim((string)($row['supplier_batch'] ?? ''));
            if ($oldBatch !== $batch) {
                $changes[] = 'партия «' . ($oldBatch !== '' ? $oldBatch : '—') . '» → «' . ($batch !== '' ? $batch : '—') . '»';
            }
            $oldDate = substr(trim((string)($row['pallet_date'] ?? '')), 0, 10);
            $newDate = substr($palletDate, 0, 10);
            if ($oldDate !== $newDate) {
                $changes[] = 'дата паллеты ' . ($oldDate !== '' ? $oldDate : '—') . ' → ' . ($newDate !== '' ? $newDate : '—');
            }
            WmsJournal::log(wms_journal_pdo(), [
                'op_type' => 'корректировка',
                'doc_type' => 'Товарный запас',
                'doc_no' => (string)($row['pallet'] ?? ''),
                'sku' => (string)($row['sku'] ?? ''),
                'product_name' => (string)($row['product_name'] ?? ''),
                'supplier_batch' => $batch,
                'qty' => (float)($row['qty'] ?? 0),
                'pallet' => (string)($row['pallet'] ?? ''),
                'sscc' => (string)($row['pallet'] ?? ''),
                'cell_from' => (string)($row['cell'] ?? ''),
                'cell_to' => (string)($row['cell'] ?? ''),
                'zone' => 'Хранение',
                'user_id' => 1,
                'user_fio' => 'Товарный запас',
                'comment' => 'Изменение паллеты: ' . implode('; ', $changes),
                'quality' => (string)($row['quality'] ?? 'новый'),
            ]);
        } catch (Throwable) {
        }
        // Фиксируем факт. тип стоящей паллеты на ячейке (тем же соединением).
        try {
            if (!$spdo) {
                $spdo = wms_storage_pdo();
            }
            $upd = $spdo->prepare('UPDATE storage_cells SET pallet_type = :pt WHERE code = :c AND warehouse_id = :w');
            $upd->execute([':pt' => $want, ':c' => trim((string)$row['cell']), ':w' => (int)$row['warehouse_id']]);
            try {
                $upd->closeCursor();
            } catch (Throwable) {
            }
            try {
                $q = $spdo->query(
                    "SELECT id FROM storage_cells WHERE code = " . $spdo->quote(trim((string)$row['cell'])) .
                    ' AND warehouse_id = ' . ((int)$row['warehouse_id']) . ' LIMIT 1'
                );
                $cellId = $q ? $q->fetchColumn() : false;
                if ($q) {
                    try {
                        $q->closeCursor();
                    } catch (Throwable) {
                    }
                }
                if ($cellId) {
                    $spdo->prepare(
                        "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                         VALUES (:c, 1, 'Товарный запас', 'update', 'pallet_type', 'Тип тары', :o, :n)"
                    )->execute([':c' => $cellId, ':o' => $cur ?: '—', ':n' => $want]);
                }
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            // Ячейка в модуле 05 — необязательна для смены тары в запасе:
            // stock уже обновлён, ошибку записи в ячейки не пробрасываем.
        }
        echo json_encode(['ok' => true, 'pallet_type' => $want, 'pallet_label' => stock_pallet_label($want), 'cell_found' => $cellFound], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'refs') {
    try {
        $wh = $pdo->query('SELECT id, name FROM warehouses ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $wh = [];
    }
    echo json_encode(['warehouses' => $wh, 'pallet_specs' => stock_pallet_specs()], JSON_UNESCAPED_UNICODE);
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
    echo json_encode(['error' => 'unknown action, use ?action=list|refs|pallet_types'], JSON_UNESCAPED_UNICODE);
}
