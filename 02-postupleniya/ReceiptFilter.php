<?php
declare(strict_types=1);

// DTO фильтров реестра. Повторяет панель "Фильтры" из макета 1-в-1.
// Все поля nullable: null = фильтр не активен (для счетчика "Фильтры (3)").

final class ReceiptFilter
{
    public ?string $search = null;      // Поиск по № документа, накладной, поставщику, ТТН
    public ?string $docFrom = null;     // Дата создания документа от
    public ?string $docTo = null;
    public ?string $planFrom = null;    // Дата поставки (планируемая) от/до
    public ?string $planTo = null;
    public ?string $acceptFrom = null;  // Дата приемки (фактическая)
    public ?string $acceptTo = null;
    public ?string $placeFrom = null;   // Дата размещения
    public ?string $placeTo = null;
    public ?string $arrivalFrom = null; // Дата прибытия ТС
    public ?string $arrivalTo = null;
    public ?string $unloadFrom = null;  // Дата разгрузки ТС
    public ?string $unloadTo = null;
    /** @var string[]|null */
    public ?array $statuses = null;     // Статус приемки ("Выбрано 4")
    public ?int $supplierId = null;     // Поставщик
    public ?int $contractorId = null;
    public ?int $warehouseId = null;    // Склад ("Все склады" = null)
    public ?string $vehicle = null;     // ТС
    public ?string $gate = null;        // Ворота
    public ?string $unloadType = null;  // Тип разгрузки: Внавал/Паллеты
    public ?string $receiptType = null; // supply/return
    public ?string $acceptType = null;  // recount/no_recount
    public ?string $priority = null;    // low/normal/high/all
    public ?string $hasDiff = null;     // all/yes/no — Наличие расхождений
    public ?float $sumFrom = null;
    public ?float $sumTo = null;
    public ?int $skuFrom = null;
    public ?int $skuTo = null;
    public ?int $qtyFrom = null;        // штук
    public ?int $qtyTo = null;

    public int $page = 1;               // "Показано 1–7 из 7"
    public int $perPage = 20;           // "Строк на странице: 14"
    public string $sort = 'doc_date';
    public string $dir = 'DESC';

    public static function fromArray(array $q): self
    {
        $f = new self();
        $get = fn(string $k) => isset($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        $f->search       = $get('search') ? trim((string)$get('search')) : null;
        $f->docFrom      = $get('doc_from');
        $f->docTo        = $get('doc_to');
        $f->planFrom     = $get('plan_from');
        $f->planTo       = $get('plan_to');
        $f->acceptFrom   = $get('accept_from');
        $f->acceptTo     = $get('accept_to');
        $f->placeFrom    = $get('place_from');
        $f->placeTo      = $get('place_to');
        $f->arrivalFrom  = $get('arrival_from');
        $f->arrivalTo    = $get('arrival_to');
        $f->unloadFrom   = $get('unload_from');
        $f->unloadTo     = $get('unload_to');
        $f->statuses     = isset($q['status']) ? (array)$q['status'] : null;
        $f->supplierId   = $get('supplier_id') ? (int)$get('supplier_id') : null;
        $f->contractorId = $get('contractor_id') ? (int)$get('contractor_id') : null;
        $f->warehouseId  = $get('warehouse_id') ? (int)$get('warehouse_id') : null;
        $f->vehicle      = $get('vehicle');
        $f->gate         = $get('gate');
        $f->unloadType   = $get('unloading_type') ?? $get('unload_type');
        $f->receiptType  = $get('receipt_type');
        $f->acceptType   = $get('accept_type');
        $f->priority     = $get('priority');
        $f->hasDiff      = $get('has_diff');
        $f->sumFrom      = $get('sum_from') !== null ? (float)$get('sum_from') : null;
        $f->sumTo        = $get('sum_to') !== null ? (float)$get('sum_to') : null;
        $f->skuFrom      = $get('sku_from') !== null ? (int)$get('sku_from') : null;
        $f->skuTo        = $get('sku_to') !== null ? (int)$get('sku_to') : null;
        $f->qtyFrom      = $get('qty_from') !== null ? (int)$get('qty_from') : null;
        $f->qtyTo        = $get('qty_to') !== null ? (int)$get('qty_to') : null;
        $f->page         = max(1, (int)($q['page'] ?? 1));
        $f->perPage      = min(200, max(5, (int)($q['per_page'] ?? 20)));
        $allowed         = ['doc_date', 'doc_no', 'total_with_vat', 'id',
            'external_no', 'arrival_date', 'accept_date', 'place_date', 'supplier',
            'vehicle', 'gate', 'unload_type', 'warehouse', 'status', 'total',
            'sku_count', 'qty_total', 'accepted_qty', 'accepted_pct', 'placed_pct',
            'deviation', 'flags'];
        if (isset($q['sort']) && in_array($q['sort'], $allowed, true)) {
            $f->sort = $q['sort'];
        }
        if (isset($q['dir']) && strtoupper((string)$q['dir']) === 'ASC') {
            $f->dir = 'ASC';
        }
        return $f;
    }

    public function activeCount(): int
    {
        $n = 0;
        foreach ([
            $this->search, $this->docFrom, $this->docTo, $this->planFrom, $this->planTo,
            $this->acceptFrom, $this->acceptTo, $this->placeFrom, $this->placeTo,
            $this->arrivalFrom, $this->arrivalTo, $this->unloadFrom, $this->unloadTo,
            $this->statuses, $this->supplierId, $this->contractorId, $this->warehouseId,
            $this->vehicle, $this->gate, $this->unloadType, $this->receiptType, $this->acceptType,
            $this->sumFrom, $this->sumTo, $this->skuFrom, $this->skuTo, $this->qtyFrom, $this->qtyTo,
        ] as $v) {
            if ($v !== null && $v !== [] && $v !== '') {
                $n++;
            }
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
