<?php
declare(strict_types=1);

// DTO фильтров реестра. Повторяет панель "Фильтры" из макета 1-в-1.
// Все поля nullable: null = фильтр не активен (для счетчика "Активные фильтры: N").

final class ShipmentFilter
{
    public ?string $search = null;          // Поиск по номеру, заказу, контрагенту, ТТН
    public ?string $docFrom = null;         // Период документа от (Y-m-d)
    public ?string $docTo = null;           // Период документа до
    /** @var string[]|null статусы (коды ShipmentStatus) */
    public ?array $statuses = null;         // "Выбрано 4"
    public ?int $contractorId = null;
    public ?int $warehouseId = null;        // "Все склады" = null
    public ?int $responsibleId = null;
    public ?int $orderId = null;            // Заказ клиента
    public ?string $contractNo = null;      // Договор
    public ?string $deliveryType = null;
    public ?string $deliveryMethod = null;
    public ?string $planShipFrom = null;
    public ?string $planShipTo = null;
    public ?string $factShipFrom = null;
    public ?string $factShipTo = null;
    public ?float $sumFrom = null;
    public ?float $sumTo = null;
    public ?string $hasDebt = null;         // all|yes|no
    public ?string $payment = null;         // all|paid|unpaid|partial
    public ?string $deliveryFrom = null;
    public ?string $deliveryTo = null;
    public ?int $linesFrom = null;          // Позиций от
    public ?int $linesTo = null;            // Позиций до
    // --- По макету (панель Фильтры, 2-я версия) ---
    public ?string $vehicle = null;         // ТС
    public ?string $gate = null;            // Ворота
    public ?string $loadingType = null;     // Тип загрузки: Внавал/Паллеты
    public ?string $priority = null;        // low/normal/high/all
    public ?int $loaderId = null;           // Загрузил
    public ?string $hasDiff = null;         // all/yes/no — Наличие расхождений
    public ?string $consignee = null;       // Грузополучатель (текст)
    public ?string $vehicleArrivalFrom = null;
    public ?string $vehicleArrivalTo = null;
    public ?string $vehicleDepartureFrom = null;
    public ?string $vehicleDepartureTo = null;
    public ?string $assemblyFrom = null;    // Дата сборки
    public ?string $assemblyTo = null;
    public ?string $packingFrom = null;     // Дата упаковки
    public ?string $packingTo = null;
    public ?string $checkFrom = null;       // Дата проверки
    public ?string $checkTo = null;
    public ?int $placesFrom = null;         // Грузовые места от
    public ?int $placesTo = null;
    public ?float $volumeFrom = null;       // Объем
    public ?float $volumeTo = null;
    public ?float $weightFrom = null;       // Вес
    public ?float $weightTo = null;
    public ?int $skuFrom = null;
    public ?int $skuTo = null;
    public ?int $qtyFrom = null;            // штук от/до
    public ?int $qtyTo = null;

    public int $page = 1;                   // Пагинация "Показано 1–6 из 120"
    public int $perPage = 20;               // "Строк на странице: 20"
    public string $sort = 'doc_date';       // doc_date|doc_no|total
    public string $dir = 'DESC';

    public static function fromArray(array $q): self
    {
        $f = new self();
        $get = fn(string $k) => isset($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        $f->search        = $get('search') ? trim((string)$get('search')) : null;
        $f->docFrom       = $get('doc_from');
        $f->docTo         = $get('doc_to');
        $f->statuses      = isset($q['status']) ? (array)$q['status'] : null;
        $f->contractorId  = $get('contractor_id') ? (int)$get('contractor_id') : null;
        $f->warehouseId   = $get('warehouse_id') ? (int)$get('warehouse_id') : null;
        $f->responsibleId = $get('responsible_id') ? (int)$get('responsible_id') : null;
        $f->orderId       = $get('order_id') ? (int)$get('order_id') : null;
        $f->contractNo    = $get('contract_no');
        $f->deliveryType  = $get('delivery_type');
        $f->deliveryMethod= $get('delivery_method');
        $f->planShipFrom  = $get('plan_ship_from');
        $f->planShipTo    = $get('plan_ship_to');
        $f->factShipFrom  = $get('fact_ship_from');
        $f->factShipTo    = $get('fact_ship_to');
        $f->sumFrom       = $get('sum_from') !== null ? (float)$get('sum_from') : null;
        $f->sumTo         = $get('sum_to') !== null ? (float)$get('sum_to') : null;
        $f->hasDebt       = $get('has_debt');
        $f->payment       = $get('payment');
        $f->deliveryFrom  = $get('delivery_from');
        $f->deliveryTo    = $get('delivery_to');
        $f->linesFrom     = $get('lines_from') !== null ? (int)$get('lines_from') : null;
        $f->linesTo       = $get('lines_to') !== null ? (int)$get('lines_to') : null;
        $f->vehicle       = $get('vehicle');
        $f->gate          = $get('gate');
        $f->loadingType   = $get('loading_type');
        $f->priority      = $get('priority');
        $f->loaderId      = $get('loader_id') ? (int)$get('loader_id') : null;
        $f->hasDiff       = $get('has_diff');
        $f->consignee     = $get('consignee');
        $f->vehicleArrivalFrom   = $get('vehicle_arrival_from');
        $f->vehicleArrivalTo     = $get('vehicle_arrival_to');
        $f->vehicleDepartureFrom = $get('vehicle_departure_from');
        $f->vehicleDepartureTo   = $get('vehicle_departure_to');
        $f->assemblyFrom  = $get('assembly_from');
        $f->assemblyTo    = $get('assembly_to');
        $f->packingFrom   = $get('packing_from');
        $f->packingTo     = $get('packing_to');
        $f->checkFrom     = $get('check_from');
        $f->checkTo       = $get('check_to');
        $f->placesFrom    = $get('places_from') !== null ? (int)$get('places_from') : null;
        $f->placesTo      = $get('places_to') !== null ? (int)$get('places_to') : null;
        $f->volumeFrom    = $get('volume_from') !== null ? (float)$get('volume_from') : null;
        $f->volumeTo      = $get('volume_to') !== null ? (float)$get('volume_to') : null;
        $f->weightFrom    = $get('weight_from') !== null ? (float)$get('weight_from') : null;
        $f->weightTo      = $get('weight_to') !== null ? (float)$get('weight_to') : null;
        $f->skuFrom       = $get('sku_from') !== null ? (int)$get('sku_from') : null;
        $f->skuTo         = $get('sku_to') !== null ? (int)$get('sku_to') : null;
        $f->qtyFrom       = $get('qty_from') !== null ? (int)$get('qty_from') : null;
        $f->qtyTo         = $get('qty_to') !== null ? (int)$get('qty_to') : null;
        $f->page          = max(1, (int)($q['page'] ?? 1));
        $f->perPage       = min(200, max(5, (int)($q['per_page'] ?? 20)));
        $allowedSort      = ['doc_date', 'doc_no', 'total_with_vat', 'id'];
        if (isset($q['sort']) && in_array($q['sort'], $allowedSort, true)) {
            $f->sort = $q['sort'];
        }
        if (isset($q['dir']) && strtoupper((string)$q['dir']) === 'ASC') {
            $f->dir = 'ASC';
        }
        return $f;
    }

    // Счетчик бейджа "Фильтры (3)" и строки "Активные фильтры: 3".
    public function activeCount(): int
    {
        $n = 0;
        foreach ([
            $this->search, $this->docFrom, $this->docTo, $this->statuses,
            $this->contractorId, $this->warehouseId, $this->responsibleId,
            $this->orderId, $this->contractNo, $this->deliveryType,
            $this->deliveryMethod, $this->planShipFrom, $this->planShipTo,
            $this->factShipFrom, $this->factShipTo, $this->sumFrom, $this->sumTo,
            $this->deliveryFrom, $this->deliveryTo, $this->linesFrom, $this->linesTo,
            $this->vehicle, $this->gate, $this->loadingType, $this->loaderId,
            $this->consignee, $this->vehicleArrivalFrom, $this->vehicleArrivalTo,
            $this->vehicleDepartureFrom, $this->vehicleDepartureTo,
            $this->assemblyFrom, $this->assemblyTo, $this->packingFrom, $this->packingTo,
            $this->checkFrom, $this->checkTo, $this->placesFrom, $this->placesTo,
            $this->volumeFrom, $this->volumeTo, $this->weightFrom, $this->weightTo,
            $this->skuFrom, $this->skuTo, $this->qtyFrom, $this->qtyTo,
        ] as $v) {
            if ($v !== null && $v !== [] && $v !== '') {
                $n++;
            }
        }
        if ($this->hasDebt !== null && $this->hasDebt !== 'all') {
            $n++;
        }
        if ($this->payment !== null && $this->payment !== 'all') {
            $n++;
        }
        if ($this->priority !== null && $this->priority !== '' && $this->priority !== 'all') {
            $n++;
        }
        if ($this->hasDiff !== null && $this->hasDiff !== 'all') {
            $n++;
        }
        return $n;
    }
}
