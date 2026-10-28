<?php
declare(strict_types=1);

require_once __DIR__ . '/ReceiptStatus.php';
require_once __DIR__ . '/ReceiptFilter.php';
require_once __DIR__ . '/../shared/pg.php';
require_once __DIR__ . '/../shared/Volume.php';

// Репозиторий реестра поступлений. Чистый PHP + PDO, переносится в Laravel Eloquent.
// Алгоритм списка по макету:
//  1. Поиск (№ документа/внешний/поставщик/ТТН) -> WHERE LIKE / JOIN
//  2. Фильтры -> AND-условия
//  3. COUNT(*) для "Показано X–Y из Z"
//  4. Сортировка + LIMIT/OFFSET для пагинации
//  5. Подсчет активных фильтров для бейджа "Фильтры (3)".

final class ReceiptRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{rows: array, total: int, from: int, to: int, active_filters: int} */
    public function paginate(ReceiptFilter $f): array
    {
        [$where, $params] = $this->buildWhere($f);

        $cnt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM receipts r
             LEFT JOIN contractors sup ON sup.id = r.supplier_id
             LEFT JOIN contractors ctr ON ctr.id = r.contractor_id
             $where"
        );
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f->page - 1) * $f->perPage;
        // Сортировка по любому столбцу реестра (А→Я / Я→А).
        $sortMap = [
            'doc_date' => ['r.doc_date', 'd'], 'doc_no' => ['r.doc_no', 't'],
            'total_with_vat' => ['r.total_with_vat', 'n'], 'id' => ['r.id', 'n'],
            'external_no' => ['r.external_no', 't'], 'arrival_date' => ['r.arrival_date', 'd'],
            'accept_date' => ['r.accept_date', 'd'], 'place_date' => ['r.place_date', 'd'],
            'supplier' => ['sup.name', 't'], 'vehicle' => ['r.vehicle', 't'],
            'gate' => ['r.gate', 't'], 'unload_type' => ['r.unload_type', 't'],
            'warehouse' => ['w.name', 't'], 'status' => ['r.status', 't'],
            // Сортируем по той же колонке, что показана в гриде («Сумма, ₽» = r.total).
            // Раньше здесь стояло r.total_with_vat — клик по заголовку сортировал
            // по ДРУГОМУ числу, чем то, которое видно в строке.
            'total' => ['r.total', 'n'], 'sku_count' => ['r.sku_count', 'n'],
            'qty_total' => ['r.qty_total', 'n'], 'accepted_qty' => ['r.accepted_qty', 'n'],
            'accepted_pct' => ['r.accepted_pct', 'n'], 'placed_pct' => ['r.placed_pct', 'n'],
            'deviation' => ['(r.excess_qty - r.shortage_qty)', 'n'],
            'flags' => ['(r.serial_scan + r.marking_scan)', 'n'],
        ];
        $orderBy = wms_order_by($this->pdo, $f->sort, $f->dir, $sortMap, 'r.doc_date', 'd');

        // Колонки реестра по макету: № документа, № внешний, Дата прибытия/приемки,
        // Поставщик, Склад, ТС, Ворота, Тип разгрузки, Статус приемки, Сумма, SKU, шт.,
        // Принято %, Размещено %, Флаги...
        $sql = "SELECT r.id, r.doc_no, r.external_no, r.doc_date,
                       r.plan_delivery_date, r.arrival_date, r.accept_date, r.place_date,
                       sup.name AS supplier, ctr.name AS contractor,
                       w.name AS warehouse, r.status,
                       r.receipt_type, r.accept_type, r.unload_type,
                       r.vehicle, r.vehicle_plan, r.vehicle_fact, r.gate,
                       r.total, r.total_with_vat, r.sku_count, r.qty_total, r.items_count,
                       r.accepted_qty, r.accepted_pct, r.placed_pct, r.priority, r.has_diff,
                       r.serial_scan, r.marking_scan, r.volume, r.cargo_places_plan,
                       r.excess_qty, r.shortage_qty, r.comment, r.deviation_qty,
                       u.fio AS created_by_name
                FROM receipts r
                LEFT JOIN contractors sup ON sup.id = r.supplier_id
                LEFT JOIN contractors ctr ON ctr.id = r.contractor_id
                LEFT JOIN warehouses w ON w.id = r.warehouse_id
                LEFT JOIN users u ON u.id = r.created_by
                $where ORDER BY $orderBy LIMIT :lim OFFSET :off";
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $this->attachVolumes($rows);

        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $f->perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to,
                'active_filters' => $f->activeCount()];
    }

    /**
     * Объём по документу (м³): план — по количеству в receipt_items,
     * факт — по фактически принятому количеству (receipt_operations «Принято»).
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
                "SELECT receipt_id, sku, COALESCE(SUM(qty),0) AS q
                 FROM receipt_items WHERE receipt_id IN ($in) GROUP BY receipt_id, sku"
            ) as $r) {
                $rid = (int)$r['receipt_id'];
                $sku = (string)$r['sku'];
                $plan[$rid][$sku] = (float)$r['q'];
                $skus[$sku] = true;
            }
            foreach ($this->pdo->query(
                "SELECT receipt_id, sku, COALESCE(SUM(qty),0) AS q
                 FROM receipt_operations WHERE receipt_id IN ($in) AND accept_status='Принято'
                 GROUP BY receipt_id, sku"
            ) as $r) {
                $rid = (int)$r['receipt_id'];
                $sku = (string)$r['sku'];
                $fact[$rid][$sku] = (float)$r['q'];
                $skus[$sku] = true;
            }
        } catch (Throwable) {
        }
        $unit = wms_volume_unit_map(array_keys($skus));
        foreach ($rows as &$row) {
            $rid = (int)($row['id'] ?? 0);
            $row['volume'] = wms_volume_sum($plan[$rid] ?? [], $unit);
            $row['volume_fact'] = wms_volume_sum($fact[$rid] ?? [], $unit);
        }
        unset($row);
    }

    public function findById(int $id): ?array
    {
        // Карточка, вкладка "Основное": основная инфо + стоимость + планирование.
        $st = $this->pdo->prepare(
            "SELECT r.*, sup.name AS supplier_name, ctr.name AS contractor_name,
                    w.name AS warehouse_name, u.fio AS creator_name, u2.fio AS unloader_name
             FROM receipts r
             LEFT JOIN contractors sup ON sup.id = r.supplier_id
             LEFT JOIN contractors ctr ON ctr.id = r.contractor_id
             LEFT JOIN warehouses w ON w.id = r.warehouse_id
             LEFT JOIN users u ON u.id = r.creator_id
             LEFT JOIN users u2 ON u2.id = r.unloader_id
             WHERE r.id = :id"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        try {
            $row['status_label'] = ReceiptStatus::from($row['status'])->label();
        } catch (Throwable $e) {
            $row['status_label'] = (string)$row['status'];
        }
        $row['is_paid'] = ((float)($row['debt'] ?? 0) <= 0);
        foreach (['receipt_items' => 'items_count_v', 'receipt_documents' => 'docs_count',
                  'receipt_flags' => 'flags_count', 'receipt_operations' => 'ops_count'] as $tbl => $key) {
            try {
                $c = $this->pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE receipt_id = :id");
                $c->execute([':id' => $id]);
                $row[$key] = (int)$c->fetchColumn();
            } catch (Throwable $e) {
                $row[$key] = 0;
            }
        }
        return $row;
    }

    // --- Вкладки карточки ---
    public function items(int $receiptId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id = :id ORDER BY line_no, id");
        $st->execute([':id' => $receiptId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function operations(int $receiptId): array
    {
        $st = $this->pdo->prepare(
            "SELECT o.*, u1.fio AS receiver_name, u2.fio AS storekeeper_name
             FROM receipt_operations o
             LEFT JOIN users u1 ON u1.id = o.receiver_id
             LEFT JOIN users u2 ON u2.id = o.storekeeper_id
             WHERE o.receipt_id = :id ORDER BY o.line_no, o.id"
        );
        $st->execute([':id' => $receiptId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function documents(int $receiptId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM receipt_documents WHERE receipt_id = :id ORDER BY id");
        $st->execute([':id' => $receiptId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(int $receiptId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM receipt_history WHERE receipt_id = :id ORDER BY created_at DESC, id DESC");
        $st->execute([':id' => $receiptId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function flags(int $receiptId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM receipt_flags WHERE receipt_id = :id ORDER BY id");
        $st->execute([':id' => $receiptId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Вкладка "Оприходования": дата изменения, № паллеты, артикул, наименование, кол-во со знаком.
    // Знак: accept_status='Принято' -> плюс, иначе (Брак/Отменено/...) -> минус.
    // Наименование тянем из receipt_items (первое совпадение по sku).
    // Дата изменения = accept_date операции приемки.
    public function stockin(int $receiptId): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT o.pallet, o.sku, o.qty, o.accept_status, o.accept_date AS changed_at,
                        o.supplier_batch,
                        (SELECT i.name FROM receipt_items i
                          WHERE i.receipt_id = o.receipt_id AND i.sku = o.sku LIMIT 1) AS name,
                        (SELECT p.status FROM tsd_pallets p
                          WHERE p.receipt_id = o.receipt_id AND p.sscc = o.pallet LIMIT 1) AS pallet_status
                 FROM receipt_operations o
                 WHERE o.receipt_id = :id ORDER BY o.id"
            );
        } catch (Throwable $e) {
            return [];
        }
        try {
            $st->execute([':id' => $receiptId]);
        } catch (Throwable $e) {
            return [];
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $qty = (float)($r['qty'] ?? 0);
            // Принята только если операция "Принято" и сама паллета не отменена.
            // Отменой считаем статус паллеты 'Отменена'/'Отменено'/'Cancelled' (если появится)
            // либо любой accept_status кроме 'Принято'.
            $pStatRaw = trim((string)($r['pallet_status'] ?? ''));
            $pStat = function_exists('mb_strtolower') ? mb_strtolower($pStatRaw, 'UTF-8') : strtolower($pStatRaw);
            $isPalletCancelled = in_array($pStat, ['отменена', 'отменено', 'отмена', 'cancelled', 'canceled'], true)
                || in_array($pStatRaw, ['Отменена', 'Отменено', 'ОТМЕНЕНА', 'ОТМЕНЕНО'], true);
            $accepted = ((string)($r['accept_status'] ?? '') === 'Принято') && !$isPalletCancelled;
            $out[] = [
                'changed_at' => (string)($r['changed_at'] ?? ''),
                'pallet' => ($r['pallet'] ?? '') !== '' ? $r['pallet'] : null,
                'sku' => (string)($r['sku'] ?? ''),
                'supplier_batch' => (string)($r['supplier_batch'] ?? ''),
                'name' => (string)($r['name'] ?? ''),
                'qty' => $accepted ? abs($qty) : -abs($qty),
                'accept_status' => (string)($r['accept_status'] ?? ''),
                'pallet_status' => (string)($r['pallet_status'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(ReceiptFilter $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f->search !== null) {
            $w[] = '(r.doc_no LIKE :q OR r.external_no LIKE :q OR sup.name LIKE :q OR ctr.name LIKE :q OR r.contract_no LIKE :q)';
            $p[':q'] = '%' . $f->search . '%';
        }
        $dates = [
            [$f->docFrom, 'r.doc_date', '>=', ':df', true], [$f->docTo, 'r.doc_date', '<=', ':dt', true],
            [$f->planFrom, 'r.plan_delivery_date', '>=', ':plf', false], [$f->planTo, 'r.plan_delivery_date', '<=', ':plt', false],
            [$f->acceptFrom, 'r.accept_date', '>=', ':acf', false], [$f->acceptTo, 'r.accept_date', '<=', ':act', false],
            [$f->placeFrom, 'r.place_date', '>=', ':pcf', false], [$f->placeTo, 'r.place_date', '<=', ':pct', false],
            [$f->arrivalFrom, 'r.vehicle_arrival_fact', '>=', ':arf', false], [$f->arrivalTo, 'r.vehicle_arrival_fact', '<=', ':art', false],
            [$f->unloadFrom, 'r.unload_date', '>=', ':unf', false], [$f->unloadTo, 'r.unload_date', '<=', ':unt', false],
        ];
        foreach ($dates as [$val, $col, $op, $ph, $isDt]) {
            if ($val !== null && $val !== '') {
                $w[] = "$col $op $ph";
                $p[$ph] = $isDt ? ($val . ($op === '>=' ? ' 00:00:00' : ' 23:59:59')) : $val;
            }
        }
        if ($f->statuses) {
            $in = [];
            foreach (array_values($f->statuses) as $i => $s) {
                if (!in_array($s, ReceiptStatus::values(), true)) {
                    continue;
                }
                $k = ":st$i";
                $in[] = $k;
                $p[$k] = $s;
            }
            if ($in) {
                $w[] = 'r.status IN (' . implode(',', $in) . ')';
            }
        }
        if ($f->supplierId) {
            $w[] = 'r.supplier_id = :sup';
            $p[':sup'] = $f->supplierId;
        }
        if ($f->contractorId) {
            $w[] = 'r.contractor_id = :ctr';
            $p[':ctr'] = $f->contractorId;
        }
        if ($f->warehouseId) {
            $w[] = 'r.warehouse_id = :wh';
            $p[':wh'] = $f->warehouseId;
        }
        if ($f->vehicle) {
            $w[] = '(r.vehicle LIKE :veh OR r.vehicle_plan LIKE :veh OR r.vehicle_fact LIKE :veh)';
            $p[':veh'] = '%' . $f->vehicle . '%';
        }
        if ($f->gate) {
            $w[] = 'r.gate = :gate';
            $p[':gate'] = $f->gate;
        }
        if ($f->unloadType) {
            $w[] = 'r.unload_type = :ult';
            $p[':ult'] = $f->unloadType;
        }
        if ($f->receiptType) {
            $w[] = 'r.receipt_type = :rt';
            $p[':rt'] = $f->receiptType;
        }
        if ($f->acceptType) {
            $w[] = 'r.accept_type = :at';
            $p[':at'] = $f->acceptType;
        }
        if ($f->priority !== null && $f->priority !== '' && $f->priority !== 'all') {
            $w[] = 'r.priority = :pri';
            $p[':pri'] = $f->priority;
        }
        if ($f->hasDiff === 'yes') {
            $w[] = 'r.has_diff = 1';
        } elseif ($f->hasDiff === 'no') {
            $w[] = 'r.has_diff = 0';
        }
        // Фильтр «Сумма» — по той же колонке, что показана в гриде (r.total).
        // Раньше фильтровалось по r.total_with_vat — другое число, чем видит оператор.
        if ($f->sumFrom !== null) {
            $w[] = 'r.total >= :sf';
            $p[':sf'] = $f->sumFrom;
        }
        if ($f->sumTo !== null) {
            $w[] = 'r.total <= :st2';
            $p[':st2'] = $f->sumTo;
        }
        if ($f->skuFrom !== null) {
            $w[] = 'r.sku_count >= :skf';
            $p[':skf'] = $f->skuFrom;
        }
        if ($f->skuTo !== null) {
            $w[] = 'r.sku_count <= :skt';
            $p[':skt'] = $f->skuTo;
        }
        if ($f->qtyFrom !== null) {
            $w[] = 'r.qty_total >= :qyf';
            $p[':qyf'] = $f->qtyFrom;
        }
        if ($f->qtyTo !== null) {
            $w[] = 'r.qty_total <= :qyt';
            $p[':qyt'] = $f->qtyTo;
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }
}
