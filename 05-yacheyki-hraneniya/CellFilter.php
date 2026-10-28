<?php
declare(strict_types=1);

// DTO фильтров реестра. Повторяет панель "Фильтры" из схемы 1-в-1.
// Все поля nullable: null = фильтр не активен (для счетчика "Фильтры (N)").

require_once __DIR__ . '/CellStatus.php';

final class CellFilter
{
    public ?string $search = null;      // Поиск по адресу, категории
    public ?int $warehouseId = null;    // Склад ("Все склады" = null)
    public ?string $zone = null;        // storage/shipment/receiving
    public ?string $cellType = null;    // pallet/box
    /** @var string[]|null */
    public ?array $statuses = null;     // Статус ("Выбрано 2")
    public ?string $category = null;    // Категория
    public ?float $tempFrom = null;     // Температурный режим от/до
    public ?float $tempTo = null;
    public ?float $fillFrom = null;     // Заполненность, %
    public ?float $fillTo = null;
    public ?string $allowed = null;     // Разрешено
    public ?string $locked = null;      // all/yes/no — Блокировка
    public ?string $hasGoods = null;    // all/yes/no — Есть товар
    public ?int $palletFrom = null;     // Паллет от/до
    public ?int $palletTo = null;
    public ?float $qtyFrom = null;      // Остаток, шт от/до
    public ?float $qtyTo = null;
    public ?string $pickZone = null;    // Доп. зона отбора: manual/driver (несколько зон у ячейки)
    public ?string $mixedGoods = null;  // all/yes/no — Разные товары в одной ячейке
    public ?string $multiPallet = null; // all/yes/no — Несколько типов паллет

    public int $page = 1;               // "Показано 1–14 из N"
    public int $perPage = 14;           // "Строк на странице: 14"
    public string $sort = 'code';
    public string $dir = 'ASC';

    public static function fromArray(array $q): self
    {
        $f = new self();
        $get = fn(string $k) => isset($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        $f->search      = $get('search') ? trim((string)$get('search')) : null;
        $f->warehouseId = $get('warehouse_id') ? (int)$get('warehouse_id') : null;
        $f->zone        = $get('zone');
        $f->cellType    = $get('cell_type');
        $f->statuses    = isset($q['status']) ? array_values((array)$q['status']) : null;
        $f->category    = $get('category');
        $f->tempFrom    = $get('temp_from') !== null ? (float)$get('temp_from') : null;
        $f->tempTo      = $get('temp_to') !== null ? (float)$get('temp_to') : null;
        $f->fillFrom    = $get('fill_from') !== null ? (float)$get('fill_from') : null;
        $f->fillTo      = $get('fill_to') !== null ? (float)$get('fill_to') : null;
        $f->allowed     = $get('allowed');
        $f->locked      = $get('locked');
        $f->hasGoods    = $get('has_goods');
        $f->palletFrom  = $get('pallet_from') !== null ? (int)$get('pallet_from') : null;
        $f->palletTo    = $get('pallet_to') !== null ? (int)$get('pallet_to') : null;
        $f->qtyFrom     = $get('qty_from') !== null ? (float)$get('qty_from') : null;
        $f->qtyTo       = $get('qty_to') !== null ? (float)$get('qty_to') : null;
        $f->pickZone    = $get('pick_zone') ?? $get('pick_zones');
        if ($f->pickZone !== null) {
            $f->pickZone = CellStatus::normalizePickZone($f->pickZone);
        }
        $f->mixedGoods  = $get('mixed_goods');
        $f->multiPallet = $get('multi_pallet_types');
        $f->page        = max(1, (int)($q['page'] ?? 1));
        $f->perPage     = wms_per_page($q['per_page'] ?? 14, 14, 200, 5);
        $allowed        = ['code', 'qty', 'capacity', 'id',
            'zone', 'cell_type', 'beam', 'height', 'pallet_types', 'category',
            'status', 'fill', 'reserved', 'stock', 'pallets', 'temp', 'lock'];
        if (isset($q['sort']) && in_array($q['sort'], $allowed, true)) {
            $f->sort = $q['sort'];
        }
        if (isset($q['dir']) && strtoupper((string)$q['dir']) === 'DESC') {
            $f->dir = 'DESC';
        }
        return $f;
    }

    public function activeCount(): int
    {
        $n = 0;
        foreach ([
            $this->search, $this->warehouseId, $this->zone, $this->cellType,
            $this->statuses, $this->category, $this->tempFrom, $this->tempTo,
            $this->fillFrom, $this->fillTo, $this->allowed,
            $this->palletFrom, $this->palletTo, $this->qtyFrom, $this->qtyTo,
            $this->pickZone,
        ] as $v) {
            if ($v !== null && $v !== [] && $v !== '') {
                $n++;
            }
        }
        if ($this->locked !== null && $this->locked !== '' && $this->locked !== 'all') {
            $n++;
        }
        if ($this->hasGoods !== null && $this->hasGoods !== '' && $this->hasGoods !== 'all') {
            $n++;
        }
        if ($this->mixedGoods !== null && $this->mixedGoods !== '' && $this->mixedGoods !== 'all') {
            $n++;
        }
        if ($this->multiPallet !== null && $this->multiPallet !== '' && $this->multiPallet !== 'all') {
            $n++;
        }
        return $n;
    }
}
