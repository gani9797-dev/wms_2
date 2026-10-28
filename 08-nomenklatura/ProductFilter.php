<?php
declare(strict_types=1);

// DTO фильтров реестра "Номенклатура". Повторяет панель "Фильтры" из макета 8.
// Все поля nullable: null = фильтр не активен (для счетчика "Фильтры (N)").
final class ProductFilter
{
    public ?string $search = null;      // артикул, штрихкод, наименование
    public ?string $createdFrom = null;
    public ?string $createdTo = null;
    public ?string $updatedFrom = null; // Дата редактирования от
    public ?string $updatedTo = null;
    public ?int $warehouseId = null;    // Все склады = null
    public ?string $category = null;
    public ?string $brand = null;
    public ?string $owner = null;
    public ?string $unit = null;        // шт/кор/Все
    public ?float $lenFrom = null; public ?float $lenTo = null;
    public ?float $widFrom = null; public ?float $widTo = null;
    public ?float $heiFrom = null; public ?float $heiTo = null;
    public ?float $weightFrom = null; public ?float $weightTo = null;
    public ?float $volFrom = null; public ?float $volTo = null;
    public ?int $inBoxFrom = null; public ?int $inBoxTo = null;
    public ?int $onPalFrom = null; public ?int $onPalTo = null;
    public ?float $qtyFrom = null; public ?float $qtyTo = null;           // Остаток
    public ?float $resFrom = null; public ?float $resTo = null;           // Зарезервировано
    public ?int $shelfFrom = null; public ?int $shelfTo = null;           // Срок годности, дни
    public ?string $tempFrom = null; public ?string $tempTo = null;       // Температурный режим (списки, но храним как строки)
    public ?float $tempNumFrom = null; public ?float $tempNumTo = null;
    public ?string $markFrom = null; public ?string $markTo = null;       // Дата начала маркировки
    public ?string $chzGroup = null;
    public ?string $storage = null;     // Стратегия хранения
    public ?string $pick = null;        // Стратегия отбора
    public ?string $placement = null;   // Стратегия размещения
    public ?float $maxExpFrom = null; public ?float $maxExpTo = null;
    public ?string $status = null;      // active/inactive/archived/all
    public ?int $flagBatch = null;      // 1/0/null
    public ?int $flagExpiry = null;
    public ?int $flagSerial = null;
    public ?int $flagChz = null;
    public ?int $flagDanger = null;
    public ?int $flagPartial = null;
    public ?int $flagCheck = null;
    public ?int $flagPack = null;
    public ?int $flagReplenish = null;
    public ?int $flagScanIn = null;      // Сканирование с/н на входе
    public ?int $flagScanOut = null;     // Сканирование с/н на выходе
    public ?int $flagProdDate = null;    // Контроль даты производства
    public ?int $hasImage = null;

    public int $page = 1;
    public int $perPage = 24;
    public string $sort = 'sku';
    public string $dir = 'ASC';

    public static function fromArray(array $q): self
    {
        $f = new self();
        $get = fn(string $k) => isset($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        $getInt = fn(string $k) => ($v = $get($k)) !== null ? (int)$v : null;
        $getFloat = fn(string $k) => ($v = $get($k)) !== null ? (float)str_replace(',', '.', (string)$v) : null;
        $getFlag = function (string $k) use ($q) {
            if (!isset($q[$k]) || $q[$k] === '' || $q[$k] === 'all') return null;
            $v = $q[$k];
            if ($v === '1' || $v === 1 || $v === true || $v === 'yes' || $v === 'on') return 1;
            if ($v === '0' || $v === 0 || $v === 'no') return 0;
            return null;
        };
        $f->search = $get('search') ? trim((string)$get('search')) : null;
        $f->createdFrom = $get('created_from'); $f->createdTo = $get('created_to');
        $f->updatedFrom = $get('updated_from'); $f->updatedTo = $get('updated_to');
        $f->markFrom = $get('mark_from'); $f->markTo = $get('mark_to');
        $wh = $get('warehouse_id') ?? $get('warehouse');
        $f->warehouseId = ($wh !== null && $wh !== 'all') ? (int)$wh : null;
        $f->category = $get('category'); $f->brand = $get('brand');
        $f->owner = $get('owner'); $f->chzGroup = $get('chz_group');
        $f->storage = $get('storage'); $f->pick = $get('pick'); $f->placement = $get('placement');
        $u = $get('unit');
        $f->unit = ($u !== null && $u !== 'all' && $u !== 'Все') ? (string)$u : null;
        $st = $get('status');
        $f->status = ($st !== null && $st !== 'all') ? (string)$st : null;
        $f->tempFrom = $get('temp_from'); $f->tempTo = $get('temp_to');
        $f->tempNumFrom = $getFloat('temp_num_from'); $f->tempNumTo = $getFloat('temp_num_to');
        $f->lenFrom = $getFloat('len_from'); $f->lenTo = $getFloat('len_to');
        $f->widFrom = $getFloat('wid_from'); $f->widTo = $getFloat('wid_to');
        $f->heiFrom = $getFloat('hei_from'); $f->heiTo = $getFloat('hei_to');
        $f->weightFrom = $getFloat('weight_from'); $f->weightTo = $getFloat('weight_to');
        $f->volFrom = $getFloat('vol_from'); $f->volTo = $getFloat('vol_to');
        $f->inBoxFrom = $getInt('inbox_from'); $f->inBoxTo = $getInt('inbox_to');
        $f->onPalFrom = $getInt('onpal_from'); $f->onPalTo = $getInt('onpal_to');
        $f->qtyFrom = $getFloat('qty_from'); $f->qtyTo = $getFloat('qty_to');
        $f->resFrom = $getFloat('res_from'); $f->resTo = $getFloat('res_to');
        $f->shelfFrom = $getInt('shelf_from'); $f->shelfTo = $getInt('shelf_to');
        $f->maxExpFrom = $getFloat('maxexp_from'); $f->maxExpTo = $getFloat('maxexp_to');
        $f->flagBatch = $getFlag('is_batch'); $f->flagExpiry = $getFlag('is_expiry');
        $f->flagSerial = $getFlag('is_serial'); $f->flagChz = $getFlag('is_chz');
        $f->flagDanger = $getFlag('is_danger'); $f->flagPartial = $getFlag('allow_partial');
        $f->flagCheck = $getFlag('need_check'); $f->flagPack = $getFlag('need_pack');
        $f->flagReplenish = $getFlag('auto_replenish');
        $f->flagScanIn = $getFlag('scan_serial_in'); $f->flagScanOut = $getFlag('scan_serial_out');
        $f->flagProdDate = $getFlag('control_prod_date');
        $f->hasImage = $getFlag('has_image');
        $f->page = max(1, (int)($q['page'] ?? 1));
        $f->perPage = wms_per_page($q['per_page'] ?? 24, 24, 200, 5);
        $allowed = ['sku', 'code', 'name', 'qty', 'created_at', 'updated_at', 'id',
            'unit', 'category', 'reserved', 'status', 'barcode'];
        if (isset($q['sort']) && in_array($q['sort'], $allowed, true)) $f->sort = $q['sort'];
        if (isset($q['dir']) && strtoupper((string)$q['dir']) === 'DESC') $f->dir = 'DESC';
        return $f;
    }

    public function activeCount(): int
    {
        $n = 0;
        foreach ([
            $this->search, $this->createdFrom, $this->createdTo, $this->updatedFrom, $this->updatedTo,
            $this->warehouseId, $this->category, $this->brand, $this->owner, $this->unit,
            $this->lenFrom, $this->lenTo, $this->widFrom, $this->widTo, $this->heiFrom, $this->heiTo,
            $this->weightFrom, $this->weightTo, $this->volFrom, $this->volTo,
            $this->inBoxFrom, $this->inBoxTo, $this->onPalFrom, $this->onPalTo,
            $this->qtyFrom, $this->qtyTo, $this->resFrom, $this->resTo,
            $this->shelfFrom, $this->shelfTo, $this->tempFrom, $this->tempTo,
            $this->tempNumFrom, $this->tempNumTo, $this->markFrom, $this->markTo,
            $this->chzGroup, $this->storage, $this->pick, $this->placement, $this->maxExpFrom, $this->maxExpTo,
            $this->flagBatch, $this->flagExpiry, $this->flagSerial, $this->flagChz, $this->flagDanger,
            $this->flagPartial, $this->flagCheck, $this->flagPack, $this->flagReplenish,
            $this->flagScanIn, $this->flagScanOut, $this->flagProdDate, $this->hasImage,
        ] as $v) {
            if ($v !== null && $v !== '' && $v !== []) $n++;
        }
        if ($this->status !== null && $this->status !== '' && $this->status !== 'all') $n++;
        return $n;
    }
}
