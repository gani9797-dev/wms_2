<?php
declare(strict_types=1);

require_once __DIR__ . '/ProductStatus.php';
require_once __DIR__ . '/ProductFilter.php';
require_once __DIR__ . '/../shared/pg.php';
require_once __DIR__ . '/../shared/Counterparties.php';

// Репозиторий реестра "Номенклатура". PDO, работает и с MySQL, и с SQLite (демо).
//  1. Поиск (артикул, штрихкод, наименование) -> WHERE LIKE + JOIN barcodes
//  2. Фильтры панели -> AND-условия
//  3. COUNT(*) для "Показано X–Y из Z"
//  4. Сортировка + LIMIT/OFFSET
//  5. active_filters для бейджа "Фильтры (N)".
final class ProductRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{rows: array, total: int, from: int, to: int, active_filters: int} */
    public function paginate(ProductFilter $f): array
    {
        [$where, $params] = $this->buildWhere($f);
        $cnt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT p.id) FROM products p LEFT JOIN product_barcodes b ON b.product_id = p.id $where"
        );
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f->page - 1) * $f->perPage;
        // Сортировка по любому столбцу реестра (А→Я / Я→А).
        $sortMap = [
            'sku' => ['p.sku', 't'], 'code' => ['p.code', 't'], 'name' => ['p.name', 't'],
            'qty' => ['p.qty', 'n'], 'created_at' => ['p.created_at', 'd'],
            'updated_at' => ['p.updated_at', 'd'], 'id' => ['p.id', 'n'],
            'unit' => ['p.unit', 't'], 'category' => ['p.category', 't'],
            'reserved' => ['p.reserved_qty', 'n'],
            'status' => ['(CASE WHEN p.is_archived = 1 THEN 2 WHEN p.is_active = 1 THEN 0 ELSE 1 END)', 'n'],
            'barcode' => ['MIN(b.barcode)', 't'],
        ];
        $orderBy = wms_order_by($this->pdo, $f->sort, $f->dir, $sortMap, 'p.sku');
        $sql = "SELECT p.*,
                  (SELECT COUNT(*) FROM product_stock s WHERE s.product_id = p.id) AS cells_count
                FROM products p
                LEFT JOIN product_barcodes b ON b.product_id = p.id
                $where
                GROUP BY p.id
                ORDER BY $orderBy LIMIT :lim OFFSET :off";
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $bcSt = $this->pdo->prepare("SELECT barcode FROM product_barcodes WHERE product_id = :id ORDER BY id");
        foreach ($rows as &$r) {
            $r['status'] = ProductStatus::fromActive((int)($r['is_active'] ?? 1), (int)($r['is_archived'] ?? 0));
            $r['status_label'] = ProductStatus::label($r['status']);
            try {
                $bcSt->execute([':id' => $r['id']]);
                $bl = $bcSt->fetchAll(PDO::FETCH_COLUMN);
                $r['barcode_list'] = $bl;
                $r['barcodes'] = implode(', ', $bl);
            } catch (Throwable) {
                $r['barcode_list'] = [];
                $r['barcodes'] = '';
            }
        }
        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $f->perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to,
                'active_filters' => $f->activeCount()];
    }

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT p.* FROM products p WHERE p.id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['status'] = ProductStatus::fromActive((int)($row['is_active'] ?? 1), (int)($row['is_archived'] ?? 0));
        $row['status_label'] = ProductStatus::label($row['status']);
        $b = $this->pdo->prepare("SELECT barcode FROM product_barcodes WHERE product_id = :id ORDER BY id");
        $b->execute([':id' => $id]);
        $row['barcode_list'] = $b->fetchAll(PDO::FETCH_COLUMN);
        $row['barcodes'] = implode(', ', $row['barcode_list']);
        // Правила сканирования с/н: эффективное значение + признак наследования от клиента.
        $row['scan_serial_in'] = (int)($row['scan_serial_in'] ?? 0);
        $row['scan_serial_out'] = (int)($row['scan_serial_out'] ?? 0);
        $row['control_prod_date'] = (int)($row['control_prod_date'] ?? 0);
        $cid = (isset($row['contractor_id']) && $row['contractor_id'] !== '' && $row['contractor_id'] !== null)
            ? (int)$row['contractor_id'] : null;
        $row['contractor_id'] = $cid;
        $cp = $cid ? WmsCounterparties::find($this->pdo, $cid) : null;
        $row['contractor_name'] = $cp ? (string)($cp['name'] ?? '') : null;
        $flags = WmsCounterparties::serialFlags($this->pdo, $cid);
        $row['client_scan_serial_in'] = $flags['in'];
        $row['client_scan_serial_out'] = $flags['out'];
        // override IS NULL → значение унаследовано от клиента (точечного решения нет).
        $row['scan_serial_in_inherited'] = ($row['scan_serial_in_override'] === null || $row['scan_serial_in_override'] === '') ? 1 : 0;
        $row['scan_serial_out_inherited'] = ($row['scan_serial_out_override'] === null || $row['scan_serial_out_override'] === '') ? 1 : 0;
        return $row;
    }

    public function findBySku(string $sku): ?array
    {
        $st = $this->pdo->prepare("SELECT p.* FROM products p WHERE p.sku = :s LIMIT 1");
        $st->execute([':s' => $sku]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function stock(int $productId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT s.*, w.name AS warehouse FROM product_stock s LEFT JOIN warehouses w ON w.id = s.warehouse_id WHERE s.product_id = :id ORDER BY s.cell"
            );
            $st->execute([':id' => $productId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return []; }
    }

    /** Весь товарный запас по коду прихода (doc_no): строки с карточками товаров. */
    public function stockByReceipt(string $docNo): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return [];
        }
        try {
            $st = $this->pdo->prepare(
                "SELECT s.*, w.name AS warehouse, p.sku, p.name AS product_name, p.unit
                 FROM product_stock s
                 LEFT JOIN warehouses w ON w.id = s.warehouse_id
                 LEFT JOIN products p ON p.id = s.product_id
                 WHERE s.receipt_no = :d ORDER BY p.sku, s.cell, s.id"
            );
            $st->execute([':d' => $docNo]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) { return []; }
    }

    public function history(int $productId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM product_history WHERE product_id = :id ORDER BY created_at DESC, id DESC LIMIT 100");
        $st->execute([':id' => $productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function movements(int $productId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM product_movements WHERE product_id = :id ORDER BY doc_date DESC, id DESC LIMIT 100");
        $st->execute([':id' => $productId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function documents(int $productId): array
    {
        // По схеме (розовый коммент): Документы → Поступления / Отгрузки / Перемещения / Инвентаризации / Возвраты.
        // Возвращаем и найденные движения, и deep-link'и в смежные модули с фильтром по артикулу,
        // чтобы пользователя не бросало в "огромный список" без фильтра.
        $p = $this->findById($productId);
        if (!$p) return [];
        $out = [];
        try {
            $st = $this->pdo->prepare("SELECT DISTINCT doc_type, doc_no FROM product_movements WHERE product_id = :id ORDER BY doc_date DESC LIMIT 20");
            $st->execute([':id' => $productId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $out[] = ['kind' => $m['doc_type'], 'name' => $m['doc_no']];
            }
        } catch (Throwable) {}
        $sku = $p['sku'];
        $out[] = ['kind' => 'receipt', 'name' => 'Поступления', 'href' => '../02-postupleniya/app.html?sku=' . urlencode($sku)];
        $out[] = ['kind' => 'shipment', 'name' => 'Отгрузки', 'href' => '../03-otgruzki/app.html?sku=' . urlencode($sku)];
        $out[] = ['kind' => 'move', 'name' => 'Перемещения', 'href' => '../04-vnutriskladskie-operatsii/app.html?sku=' . urlencode($sku)];
        $out[] = ['kind' => 'inventory', 'name' => 'Инвентаризации', 'href' => '../06-inventarizatsiya/app.html?sku=' . urlencode($sku)];
        $out[] = ['kind' => 'return', 'name' => 'Возвраты', 'href' => '../02-postupleniya/app.html?sku=' . urlencode($sku) . '&type=return'];
        return $out;
    }

    /** Задания по номенклатуре (пункт ПКМ "Показать задания" / "Задания по документу"). */
    public function tasks(int $productId): array
    {
        $p = $this->findById($productId);
        if (!$p) return [];
        $sku = (string)$p['sku'];
        try {
            // Задания по артикулу лежат в task_items: у таблицы tasks колонки sku нет.
            // Прежний запрос (WHERE sku = ... на tasks) падал всегда, и функция
            // молча отдавала только ссылку на модуль заданий.
            $st = $this->pdo->prepare(
                "SELECT t.id, t.doc_no, t.type, t.status, t.plan_qty, t.fact_qty, t.warehouse_id,
                        ti.sku, ti.cell_from, ti.cell_to, ti.state
                 FROM task_items ti JOIN tasks t ON t.id = ti.task_id
                 WHERE ti.sku = :s ORDER BY t.id DESC LIMIT 50"
            );
            $st->execute([':s' => $sku]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['name'] = trim((string)($r['doc_no'] ?? '') . ' · ' . (string)($r['sku'] ?? $sku));
            }
            unset($r);
            if ($rows) return $rows;
        } catch (Throwable) {
        }
        // Fallback: deep-link в модуль заданий, если таблицы заданий нет в этой БД.
        return [['href' => '../07-zadaniya/app.html?sku=' . urlencode($sku), 'name' => 'Задания по ' . $sku, 'kind' => 'link']];
    }

    public function defaults(): array
    {
        try {
            $rows = $this->pdo->query("SELECT field_name, field_value FROM product_defaults")->fetchAll(PDO::FETCH_KEY_PAIR);
            return $rows ?: [];
        } catch (Throwable) { return []; }
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(ProductFilter $f): array
    {
        $w = ['1=1']; $p = [];
        if ($f->search !== null) {
            $w[] = '(p.sku LIKE :q OR p.code LIKE :q OR p.name LIKE :q OR b.barcode LIKE :q)';
            $p[':q'] = '%' . $f->search . '%';
        }
        if ($f->createdFrom) { $w[] = 'p.created_at >= :cf'; $p[':cf'] = $f->createdFrom . ' 00:00:00'; }
        if ($f->createdTo) { $w[] = 'p.created_at <= :ct'; $p[':ct'] = $f->createdTo . ' 23:59:59'; }
        if ($f->updatedFrom) { $w[] = 'p.updated_at >= :uf'; $p[':uf'] = $f->updatedFrom . ' 00:00:00'; }
        if ($f->updatedTo) { $w[] = 'p.updated_at <= :ut'; $p[':ut'] = $f->updatedTo . ' 23:59:59'; }
        if ($f->warehouseId) { $w[] = 'p.warehouse_id = :wh'; $p[':wh'] = $f->warehouseId; }
        if ($f->category) { $w[] = 'p.category = :cat'; $p[':cat'] = $f->category; }
        if ($f->brand) { $w[] = 'p.brand LIKE :br'; $p[':br'] = '%' . $f->brand . '%'; }
        if ($f->owner) { $w[] = 'p.owner LIKE :ow'; $p[':ow'] = '%' . $f->owner . '%'; }
        if ($f->unit) { $w[] = 'p.unit = :un'; $p[':un'] = $f->unit; }
        if ($f->storage) { $w[] = 'p.storage_strategy = :ss'; $p[':ss'] = $f->storage; }
        if ($f->pick) { $w[] = 'p.pick_strategy = :ps'; $p[':ps'] = $f->pick; }
        if ($f->placement && $this->hasPlacement()) { $w[] = 'p.placement_strategy = :pls'; $p[':pls'] = $f->placement; }
        if ($f->chzGroup) { $w[] = 'p.chz_group LIKE :chz'; $p[':chz'] = '%' . $f->chzGroup . '%'; }
        if ($f->status === 'active') { $w[] = 'p.is_active = 1 AND p.is_archived = 0'; }
        elseif ($f->status === 'inactive') { $w[] = 'p.is_active = 0 AND p.is_archived = 0'; }
        elseif ($f->status === 'archived') { $w[] = 'p.is_archived = 1'; }
        foreach ([
            [$f->lenFrom, 'p.length_mm', '>=', ':lf'], [$f->lenTo, 'p.length_mm', '<=', ':lt'],
            [$f->widFrom, 'p.width_mm', '>=', ':wf'], [$f->widTo, 'p.width_mm', '<=', ':wt2'],
            [$f->heiFrom, 'p.height_mm', '>=', ':hf'], [$f->heiTo, 'p.height_mm', '<=', ':ht'],
            [$f->weightFrom, 'p.weight_g', '>=', ':wg'], [$f->weightTo, 'p.weight_g', '<=', ':wg2'],
            [$f->volFrom, 'p.volume_m3', '>=', ':vf'], [$f->volTo, 'p.volume_m3', '<=', ':vt'],
            [$f->inBoxFrom, 'p.qty_in_box', '>=', ':ibf'], [$f->inBoxTo, 'p.qty_in_box', '<=', ':ibt'],
            [$f->onPalFrom, 'p.boxes_per_pallet', '>=', ':opf'], [$f->onPalTo, 'p.boxes_per_pallet', '<=', ':opt'],
            [$f->qtyFrom, 'p.qty', '>=', ':qf'], [$f->qtyTo, 'p.qty', '<=', ':qt'],
            [$f->resFrom, 'p.reserved_qty', '>=', ':rf'], [$f->resTo, 'p.reserved_qty', '<=', ':rt'],
            [$f->shelfFrom, 'p.shelf_life_days', '>=', ':sf'], [$f->shelfTo, 'p.shelf_life_days', '<=', ':st2'],
            [$f->maxExpFrom, 'p.max_expiry_pct', '>=', ':mef'], [$f->maxExpTo, 'p.max_expiry_pct', '<=', ':met'],
            [$f->tempNumFrom, 'p.temp_from', '>=', ':tnf'], [$f->tempNumTo, 'p.temp_to', '<=', ':tnt'],
        ] as [$val, $col, $op, $ph]) {
            if ($val !== null) { $w[] = "$col $op $ph"; $p[$ph] = $val; }
        }
        if ($f->markFrom) { $w[] = 'p.marking_start >= :mf'; $p[':mf'] = $f->markFrom; }
        if ($f->markTo) { $w[] = 'p.marking_start <= :mt'; $p[':mt'] = $f->markTo; }
        foreach ([
            [$f->flagBatch, 'p.is_batch'], [$f->flagExpiry, 'p.is_expiry'],
            [$f->flagSerial, 'p.is_serial'], [$f->flagChz, 'p.is_chz'],
            [$f->flagDanger, 'p.is_danger'], [$f->flagPartial, 'p.allow_partial_pick'],
            [$f->flagCheck, 'p.need_check'], [$f->flagPack, 'p.need_pack'],
            [$f->flagReplenish, 'p.auto_replenish'],
            [$f->flagScanIn, 'p.scan_serial_in'], [$f->flagScanOut, 'p.scan_serial_out'],
            [$f->flagProdDate, 'p.control_prod_date'],
        ] as [$val, $col]) {
            if ($val !== null) { $w[] = "$col = " . (int)$val; }
        }
        if ($f->hasImage !== null) {
            $w[] = $f->hasImage ? 'p.image_path IS NOT NULL' : 'p.image_path IS NULL';
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    private ?bool $placementCache = null;

    private function hasPlacement(): bool
    {
        if ($this->placementCache !== null) return $this->placementCache;
        try {
            $drv = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $cols = $this->pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($cols as $c) {
                    if (($c['name'] ?? '') === 'placement_strategy') {
                        return $this->placementCache = true;
                    }
                }
                return $this->placementCache = false;
            }
            if ($drv === 'pgsql') {
                $st = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.columns WHERE table_name='products' AND column_name='placement_strategy'"
                );
                $st->execute();
                return $this->placementCache = ((int)$st->fetchColumn() > 0);
            }
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'placement_strategy'"
            );
            $st->execute();
            return $this->placementCache = ((int)$st->fetchColumn() > 0);
        } catch (Throwable) {
            return $this->placementCache = false;
        }
    }
}
