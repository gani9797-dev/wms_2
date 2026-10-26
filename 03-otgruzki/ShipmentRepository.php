<?php
declare(strict_types=1);

require_once __DIR__ . '/ShipmentStatus.php';
require_once __DIR__ . '/ShipmentFilter.php';
require_once __DIR__ . '/../shared/pg.php';
require_once __DIR__ . '/../shared/Volume.php';

// Репозиторий реестра отгрузок. Чистый PHP + PDO, легко переносится в Laravel Eloquent.
// Алгоритм списка по макету:
//  1. Поиск (номер/заказ/контрагент/ТТН) -> WHERE LIKE / JOIN
//  2. Фильтры -> AND-условия
//  3. Подсчет COUNT(*) для "Показано X–Y из Z"
//  4. Сортировка + LIMIT/OFFSET для пагинации
//  5. Подсчет активных фильтров для бейджа.

final class ShipmentRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{rows: array, total: int, from: int, to: int} */
    public function paginate(ShipmentFilter $f): array
    {
        [$where, $params] = $this->buildWhere($f);

        $cnt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM shipments s
             LEFT JOIN contractors c ON c.id = s.contractor_id
             LEFT JOIN client_orders o ON o.id = s.order_id
             $where"
        );
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f->page - 1) * $f->perPage;
        // Сортировка по любому столбцу реестра (А→Я / Я→А).
        $sortMap = [
            'doc_date' => ['s.doc_date', 'd'], 'doc_no' => ['s.doc_no', 't'],
            'total_with_vat' => ['s.total_with_vat', 'n'], 'id' => ['s.id', 'n'],
            'vehicle_arrival' => ['s.vehicle_arrival', 'd'],
            'ship_date' => ['COALESCE(s.fact_shipment_date, s.plan_shipment_date)', 'd'],
            'consignee' => ['s.consignee', 't'], 'vehicle' => ['s.vehicle', 't'],
            'gate' => ['s.gate', 't'], 'loading_type' => ['s.loading_type', 't'],
            'warehouse' => ['w.name', 't'], 'status' => ['s.status', 't'],
            'total' => ['s.total_with_vat', 'n'], 'sku_count' => ['s.sku_count', 'n'],
            'qty_total' => ['s.qty_total', 'n'], 'readiness' => ['s.readiness', 'n'],
            'flags' => ['(s.serial_scan + s.marking_scan + s.need_check + s.need_pack)', 'n'],
        ];
        $orderBy = wms_order_by($this->pdo, $f->sort, $f->dir, $sortMap, 's.doc_date', 'd');

        // Колонки реестра по макету: №, Дата создания, Дата прибытия/отгрузки,
        // ТС, Ворота, Тип загрузки, Склад, Статус, Сумма, SKU, шт, Готовность, Флаги, Приоритет...
        // Заказ/Контрагент оставлены для совместимости; заказы N:1 via shipment_orders.
        $sql = "SELECT s.id, s.doc_no, s.doc_date, o.doc_no AS order_no,
                       c.name AS contractor, s.consignee, w.name AS warehouse, s.status,
                       s.total_with_vat AS total, s.items_count, s.sku_count, s.qty_total,
                       s.plan_shipment_date, s.fact_shipment_date,
                       s.vehicle_arrival, s.vehicle_departure, s.loading_date,
                       s.vehicle, s.gate, s.loading_type, s.priority,
                       s.volume, s.weight, s.cargo_places, s.readiness, s.has_diff,
                       s.need_check, s.need_pack, s.is_kgt, s.is_danger,
                       s.serial_scan, s.marking_scan, s.is_crossdock,
                       s.assembly_start, s.packing_start, s.check_start,
                       u.fio AS created_by, l.fio AS loaded_by,
                       s.paid, s.debt
                FROM shipments s
                LEFT JOIN contractors c ON c.id = s.contractor_id
                LEFT JOIN warehouses w ON w.id = s.warehouse_id
                LEFT JOIN client_orders o ON o.id = s.order_id
                LEFT JOIN users u ON u.id = s.responsible_id
                LEFT JOIN users l ON l.id = s.loader_id
                $where ORDER BY $orderBy LIMIT :lim OFFSET :off";
        try {
            $st = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
            $st->bindValue(':off', $offset, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Fallback для старой БД без новых колонок
            $sql2 = "SELECT s.id, s.doc_no, s.doc_date, o.doc_no AS order_no,
                           c.name AS contractor, w.name AS warehouse, s.status,
                           s.total_with_vat AS total, s.items_count,
                           s.plan_shipment_date, u.fio AS created_by,
                           s.paid, s.debt
                    FROM shipments s
                    LEFT JOIN contractors c ON c.id = s.contractor_id
                    LEFT JOIN warehouses w ON w.id = s.warehouse_id
                    LEFT JOIN client_orders o ON o.id = s.order_id
                    LEFT JOIN users u ON u.id = s.responsible_id
                    $where ORDER BY $orderBy LIMIT :lim OFFSET :off";
            $st = $this->pdo->prepare($sql2);
            foreach ($params as $k => $v) {
                try {
                    $st->bindValue($k, $v);
                } catch (Throwable $ex) {
                }
            }
            $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
            $st->bindValue(':off', $offset, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $this->attachVolumes($rows);

        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $f->perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to,
                'active_filters' => $f->activeCount()];
    }

    /**
     * Объём по отгрузке (м³): план — по shipment_items.qty,
     * факт — по фактически отобранному количеству (tsd_picks), если оно есть.
     * Единичный объём = Д × Ш × В товара (без паллеты).
     */
    private function attachVolumes(array &$rows): void
    {
        if (!$rows) {
            return;
        }
        $ids = array_values(array_filter(array_map(static fn($r): int => (int)($r['id'] ?? 0), $rows)));
        if (!$ids) {
            return;
        }
        $in = implode(',', array_map('intval', $ids));
        $plan = [];
        $fact = [];
        $skus = [];
        try {
            foreach ($this->pdo->query(
                "SELECT shipment_id, sku, COALESCE(SUM(qty),0) AS q
                 FROM shipment_items WHERE shipment_id IN ($in) GROUP BY shipment_id, sku"
            ) as $r) {
                $sid = (int)$r['shipment_id'];
                $sku = (string)$r['sku'];
                $plan[$sid][$sku] = (float)$r['q'];
                $skus[$sku] = true;
            }
            // Факт отбора из ТСД; если по конкретной отгрузке отбора не было —
            // берём количества самой отгрузки.
            try {
                foreach ($this->pdo->query(
                    "SELECT shipment_id, sku, COALESCE(SUM(qty),0) AS q
                     FROM tsd_picks WHERE shipment_id IN ($in) GROUP BY shipment_id, sku"
                ) as $r) {
                    $sid = (int)$r['shipment_id'];
                    $sku = (string)$r['sku'];
                    $fact[$sid][$sku] = (float)$r['q'];
                    $skus[$sku] = true;
                }
            } catch (Throwable) {
            }
            foreach ($plan as $sid => $bySku) {
                if (!isset($fact[$sid])) {
                    $fact[$sid] = $bySku;
                }
            }
        } catch (Throwable) {
        }
        $unit = wms_volume_unit_map(array_keys($skus));
        foreach ($rows as &$row) {
            $sid = (int)($row['id'] ?? 0);
            $row['volume'] = wms_volume_sum($plan[$sid] ?? [], $unit);
            $row['volume_fact'] = wms_volume_sum($fact[$sid] ?? [], $unit);
        }
        unset($row);
    }

    public function findById(int $id): ?array
    {
        // Карточка, вкладка "Основное": основная инфо + финансы + даты + флаги.
        $st = $this->pdo->prepare(
            "SELECT s.*, c.name AS contractor_name, w.name AS warehouse_name,
                    o.doc_no AS order_no, u.fio AS responsible, l.fio AS loader_name
             FROM shipments s
             LEFT JOIN contractors c ON c.id = s.contractor_id
             LEFT JOIN warehouses w ON w.id = s.warehouse_id
             LEFT JOIN client_orders o ON o.id = s.order_id
             LEFT JOIN users u ON u.id = s.responsible_id
             LEFT JOIN users l ON l.id = s.loader_id
             WHERE s.id = :id"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $it = $this->pdo->prepare("SELECT * FROM shipment_items WHERE shipment_id = :id ORDER BY id");
        try {
            $it->execute([':id' => $id]);
            $row['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row['items'] = [];
        }
        try {
            $row['status_label'] = ShipmentStatus::from($row['status'])->label();
        } catch (Throwable $e) {
            $row['status_label'] = (string)$row['status'];
        }
        $row['is_paid'] = ((float)$row['debt'] <= 0);
        // Счетчики вкладок: Заказы (N) / Товары (N) / Документы (N) / Флаги (N)
        foreach (['shipment_orders' => 'orders_count', 'shipment_items' => 'items_count_v',
                  'shipment_documents' => 'docs_count', 'shipment_flags' => 'flags_count'] as $tbl => $key) {
            try {
                $c = $this->pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE shipment_id = :id");
                $c->execute([':id' => $id]);
                $row[$key] = (int)$c->fetchColumn();
            } catch (Throwable $e) {
                $row[$key] = ($key === 'items_count_v') ? count($row['items']) : 0;
            }
        }
        return $row;
    }

    // --- Вкладки карточки (догружаются отдельно, чтобы Основное открывалось быстро) ---
    public function orders(int $shipmentId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT so.*, o.doc_no AS order_no, o.consignee, o.address, o.phone,
                        o.status AS order_status, o.created_at AS order_created,
                        w.name AS warehouse_name
                 FROM shipment_orders so
                 LEFT JOIN client_orders o ON o.id = so.order_id
                 LEFT JOIN warehouses w ON w.id = o.warehouse_id
                 WHERE so.shipment_id = :id ORDER BY so.id"
            );
            $st->execute([':id' => $shipmentId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function items(int $shipmentId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipment_items WHERE shipment_id = :id ORDER BY id");
        $st->execute([':id' => $shipmentId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function documents(int $shipmentId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM shipment_documents WHERE shipment_id = :id ORDER BY id");
            $st->execute([':id' => $shipmentId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function history(int $shipmentId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM shipment_history WHERE shipment_id = :id ORDER BY created_at DESC, id DESC");
            $st->execute([':id' => $shipmentId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function flags(int $shipmentId): array
    {
        try {
            $st = $this->pdo->prepare("SELECT * FROM shipment_flags WHERE shipment_id = :id ORDER BY id");
            $st->execute([':id' => $shipmentId]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Кандидаты для шага 1 мастера "Формирование отгрузки: Выбор заказов" */
    public function pickCandidates(array $q): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($q['search'])) {
            $w[] = '(o.doc_no LIKE :q OR c.name LIKE :q OR o.consignee LIKE :q)';
            $p[':q'] = '%' . $q['search'] . '%';
        }
        if (!empty($q['contractor_id'])) {
            $w[] = 'o.contractor_id = :ctr';
            $p[':ctr'] = (int)$q['contractor_id'];
        }
        if (!empty($q['status'])) {
            $w[] = 'o.status = :st';
            $p[':st'] = (string)$q['status'];
        }
        $sql = "SELECT o.id, o.doc_no, o.consignee, c.name AS contractor,
                       o.status, o.total, o.created_at
                FROM client_orders o
                LEFT JOIN contractors c ON c.id = o.contractor_id
                WHERE " . implode(' AND ', $w) . " ORDER BY o.id DESC LIMIT 200";
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(ShipmentFilter $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f->search !== null) {
            $w[] = '(s.doc_no LIKE :q OR o.doc_no LIKE :q OR c.name LIKE :q OR s.ttn LIKE :q)';
            $p[':q'] = '%' . $f->search . '%';
        }
        if ($f->docFrom) {
            $w[] = 's.doc_date >= :df';
            $p[':df'] = $f->docFrom . ' 00:00:00';
        }
        if ($f->docTo) {
            $w[] = 's.doc_date <= :dt';
            $p[':dt'] = $f->docTo . ' 23:59:59';
        }
        if ($f->statuses) {
            $in = [];
            foreach (array_values($f->statuses) as $i => $s) {
                if (!in_array($s, ShipmentStatus::values(), true)) {
                    continue;
                }
                $k = ":st$i";
                $in[] = $k;
                $p[$k] = $s;
            }
            if ($in) {
                $w[] = 's.status IN (' . implode(',', $in) . ')';
            }
        }
        if ($f->contractorId) {
            $w[] = 's.contractor_id = :ctr';
            $p[':ctr'] = $f->contractorId;
        }
        if ($f->warehouseId) {
            $w[] = 's.warehouse_id = :wh';
            $p[':wh'] = $f->warehouseId;
        }
        if ($f->responsibleId) {
            $w[] = 's.responsible_id = :resp';
            $p[':resp'] = $f->responsibleId;
        }
        if ($f->orderId) {
            $w[] = 's.order_id = :ord';
            $p[':ord'] = $f->orderId;
        }
        if ($f->contractNo) {
            $w[] = 's.contract_no LIKE :cn';
            $p[':cn'] = '%' . $f->contractNo . '%';
        }
        if ($f->deliveryType) {
            $w[] = 's.delivery_type = :dlt';
            $p[':dlt'] = $f->deliveryType;
        }
        if ($f->deliveryMethod) {
            $w[] = 's.delivery_method = :dlm';
            $p[':dlm'] = $f->deliveryMethod;
        }
        if ($f->planShipFrom) {
            $w[] = 's.plan_shipment_date >= :psf';
            $p[':psf'] = $f->planShipFrom;
        }
        if ($f->planShipTo) {
            $w[] = 's.plan_shipment_date <= :pst';
            $p[':pst'] = $f->planShipTo;
        }
        if ($f->factShipFrom) {
            $w[] = 's.fact_shipment_date >= :fsf';
            $p[':fsf'] = $f->factShipFrom;
        }
        if ($f->factShipTo) {
            $w[] = 's.fact_shipment_date <= :fst';
            $p[':fst'] = $f->factShipTo;
        }
        if ($f->sumFrom !== null) {
            $w[] = 's.total_with_vat >= :sf';
            $p[':sf'] = $f->sumFrom;
        }
        if ($f->sumTo !== null) {
            $w[] = 's.total_with_vat <= :st2';
            $p[':st2'] = $f->sumTo;
        }
        if ($f->hasDebt === 'yes') {
            $w[] = 's.debt > 0';
        } elseif ($f->hasDebt === 'no') {
            $w[] = 's.debt <= 0';
        }
        if ($f->payment === 'paid') {
            $w[] = 's.debt <= 0 AND s.total_with_vat > 0';
        } elseif ($f->payment === 'unpaid') {
            $w[] = 's.paid <= 0';
        } elseif ($f->payment === 'partial') {
            $w[] = 's.paid > 0 AND s.debt > 0';
        }
        if ($f->deliveryFrom) {
            $w[] = 's.fact_delivery_date >= :dlf';
            $p[':dlf'] = $f->deliveryFrom;
        }
        if ($f->deliveryTo) {
            $w[] = 's.fact_delivery_date <= :dlt2';
            $p[':dlt2'] = $f->deliveryTo;
        }
        if ($f->linesFrom !== null) {
            $w[] = 's.items_count >= :lf';
            $p[':lf'] = $f->linesFrom;
        }
        if ($f->linesTo !== null) {
            $w[] = 's.items_count <= :lt';
            $p[':lt'] = $f->linesTo;
        }
        // --- Новые фильтры по макету: оборачиваем в try-совместимость со старой БД ---
        // (если колонки нет в старой SQLite — условие просто пропускается на уровне SQL-ошибки?
        //  Нет: проверяем наличие колонок один раз и только тогда добавляем условие.)
        static $cols = null;
        if ($cols === null) {
            try {
                $drv = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                if ($drv === 'pgsql') {
                    $st = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name='shipments'");
                    $st->execute();
                    $cols = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
                } else {
                    $info = $this->pdo->query("PRAGMA table_info(shipments)")->fetchAll(PDO::FETCH_ASSOC);
                    if ($info) {
                        $cols = array_column($info, 'name');
                    } else {
                        // MySQL: INFORMATION_SCHEMA
                        $cols = [];
                        $q = $this->pdo->query("SHOW COLUMNS FROM shipments");
                        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                            $cols[] = $r['Field'] ?? $r['field'] ?? '';
                        }
                    }
                }
            } catch (Throwable $e) {
                $cols = [];
            }
        }
        $has = fn(string $c) => $cols === [] || in_array($c, $cols, true);
        if ($f->vehicle !== null && $f->vehicle !== '' && $has('vehicle')) {
            $w[] = 's.vehicle LIKE :veh';
            $p[':veh'] = '%' . $f->vehicle . '%';
        }
        if ($f->gate !== null && $f->gate !== '' && $has('gate')) {
            $w[] = 's.gate = :gate';
            $p[':gate'] = $f->gate;
        }
        if ($f->loadingType !== null && $f->loadingType !== '' && $has('loading_type')) {
            $w[] = 's.loading_type = :ltp';
            $p[':ltp'] = $f->loadingType;
        }
        if ($f->priority !== null && $f->priority !== '' && $f->priority !== 'all' && $has('priority')) {
            $w[] = 's.priority = :pri';
            $p[':pri'] = $f->priority;
        }
        if ($f->loaderId && $has('loader_id')) {
            $w[] = 's.loader_id = :ldr';
            $p[':ldr'] = $f->loaderId;
        }
        if ($f->hasDiff === 'yes' && $has('has_diff')) {
            $w[] = 's.has_diff = 1';
        } elseif ($f->hasDiff === 'no' && $has('has_diff')) {
            $w[] = 's.has_diff = 0';
        }
        if ($f->consignee !== null && $f->consignee !== '' && $has('consignee')) {
            $w[] = 's.consignee LIKE :csg';
            $p[':csg'] = '%' . $f->consignee . '%';
        }
        foreach ([
            [$f->vehicleArrivalFrom, 'vehicle_arrival', '>=', ':vaf'],
            [$f->vehicleArrivalTo, 'vehicle_arrival', '<=', ':vat'],
            [$f->vehicleDepartureFrom, 'vehicle_departure', '>=', ':vdf'],
            [$f->vehicleDepartureTo, 'vehicle_departure', '<=', ':vdt'],
            [$f->assemblyFrom, 'assembly_start', '>=', ':asf'],
            [$f->assemblyTo, 'assembly_start', '<=', ':ast'],
            [$f->packingFrom, 'packing_start', '>=', ':pkf'],
            [$f->packingTo, 'packing_start', '<=', ':pkt'],
            [$f->checkFrom, 'check_start', '>=', ':chf'],
            [$f->checkTo, 'check_start', '<=', ':cht'],
        ] as [$val, $col, $op, $ph]) {
            if ($val !== null && $val !== '' && $has($col)) {
                $w[] = "s.$col $op $ph";
                $p[$ph] = $val;
            }
        }
        if ($f->placesFrom !== null && $has('cargo_places')) {
            $w[] = 's.cargo_places >= :plf';
            $p[':plf'] = $f->placesFrom;
        }
        if ($f->placesTo !== null && $has('cargo_places')) {
            $w[] = 's.cargo_places <= :plt';
            $p[':plt'] = $f->placesTo;
        }
        if ($f->volumeFrom !== null && $has('volume')) {
            $w[] = 's.volume >= :vof';
            $p[':vof'] = $f->volumeFrom;
        }
        if ($f->volumeTo !== null && $has('volume')) {
            $w[] = 's.volume <= :vot';
            $p[':vot'] = $f->volumeTo;
        }
        if ($f->weightFrom !== null && $has('weight')) {
            $w[] = 's.weight >= :wef';
            $p[':wef'] = $f->weightFrom;
        }
        if ($f->weightTo !== null && $has('weight')) {
            $w[] = 's.weight <= :wet';
            $p[':wet'] = $f->weightTo;
        }
        if ($f->skuFrom !== null && $has('sku_count')) {
            $w[] = 's.sku_count >= :skf';
            $p[':skf'] = $f->skuFrom;
        }
        if ($f->skuTo !== null && $has('sku_count')) {
            $w[] = 's.sku_count <= :skt';
            $p[':skt'] = $f->skuTo;
        }
        if ($f->qtyFrom !== null && $has('qty_total')) {
            $w[] = 's.qty_total >= :qyf';
            $p[':qyf'] = $f->qtyFrom;
        }
        if ($f->qtyTo !== null && $has('qty_total')) {
            $w[] = 's.qty_total <= :qyt';
            $p[':qyt'] = $f->qtyTo;
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }
}
