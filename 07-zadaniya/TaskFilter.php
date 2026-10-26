<?php
declare(strict_types=1);

// DTO фильтров реестра "Задания". Повторяет панель "Фильтры" из макета.
// Все поля nullable: null = фильтр не активен (для счетчика "Фильтры (N)").

final class TaskFilter
{
    public ?string $search = null;      // № задания, тип, сотрудник, товар...
    public ?string $createdFrom = null; // Дата создания от (Y-m-d)
    public ?string $createdTo = null;
    public ?string $dueFrom = null;     // Дата выполнения от
    public ?string $dueTo = null;
    /** @var string[]|null типы: сбор/размещение/перемещение/комплектация/пополнение */
    public ?array $types = null;
    /** @var string[]|null статусы: new/work/paused/done/cancelled */
    public ?array $statuses = null;
    public ?int $warehouseId = null;    // Все склады = null
    public ?int $executorId = null;
    public ?string $executor = null;    // текст: ФИО / табельный
    public ?string $priority = null;    // lowest/low/normal/high/critical/all
    public ?string $docRef = null;      // Документ (№ отгрузки/поступления)
    public ?string $orderRef = null;    // Заказ
    public ?string $sku = null;         // Код товара / артикул
    public ?int $goodsFrom = null;      // Товаров от
    public ?int $goodsTo = null;
    public ?int $linesFrom = null;      // Позиций от
    public ?int $linesTo = null;
    public ?int $cellsFrom = null;      // Ячеек от
    public ?int $cellsTo = null;
    public ?int $progressFrom = null;   // Прогресс % от
    public ?int $progressTo = null;
    public ?float $volumeFrom = null;
    public ?float $volumeTo = null;
    public ?float $weightFrom = null;
    public ?float $weightTo = null;
    public ?string $flag = null;        // Флаги: danger/check/confirm...
    public ?string $role = null;        // Роль исполнителя
    public ?string $listCode = null;    // Список заданий: PK-000001 ...

    public int $page = 1;
    public int $perPage = 20;
    public string $sort = 'created_at';
    public string $dir = 'DESC';

    public static function fromArray(array $q): self
    {
        $f = new self();
        $get = fn(string $k) => isset($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        $f->search      = $get('search') ? trim((string)$get('search')) : null;
        $f->createdFrom = $get('created_from');
        $f->createdTo   = $get('created_to');
        $f->dueFrom     = $get('due_from');
        $f->dueTo       = $get('due_to');
        if (isset($q['type'])) {
            $f->types = array_values((array)$q['type']);
        } elseif (isset($q['types'])) {
            $f->types = array_values((array)$q['types']);
        }
        if (isset($q['status'])) {
            $f->statuses = array_values((array)$q['status']);
        } elseif (isset($q['statuses'])) {
            $f->statuses = array_values((array)$q['statuses']);
        }
        $f->warehouseId = $get('warehouse_id') ? (int)$get('warehouse_id') : null;
        $f->executorId  = $get('executor_id') ? (int)$get('executor_id') : null;
        $f->executor    = $get('executor');
        $f->priority    = $get('priority');
        $f->docRef      = $get('doc_ref') ?? $get('doc');
        $f->orderRef    = $get('order_ref') ?? $get('order');
        $f->sku         = $get('sku') ?? $get('code');
        $f->goodsFrom   = $get('goods_from') !== null ? (int)$get('goods_from') : null;
        $f->goodsTo     = $get('goods_to') !== null ? (int)$get('goods_to') : null;
        $f->linesFrom   = $get('lines_from') !== null ? (int)$get('lines_from') : null;
        $f->linesTo     = $get('lines_to') !== null ? (int)$get('lines_to') : null;
        $f->cellsFrom   = $get('cells_from') !== null ? (int)$get('cells_from') : null;
        $f->cellsTo     = $get('cells_to') !== null ? (int)$get('cells_to') : null;
        $f->progressFrom = $get('progress_from') !== null ? (int)$get('progress_from') : null;
        $f->progressTo   = $get('progress_to') !== null ? (int)$get('progress_to') : null;
        $f->volumeFrom  = $get('volume_from') !== null ? (float)$get('volume_from') : null;
        $f->volumeTo    = $get('volume_to') !== null ? (float)$get('volume_to') : null;
        $f->weightFrom  = $get('weight_from') !== null ? (float)$get('weight_from') : null;
        $f->weightTo    = $get('weight_to') !== null ? (float)$get('weight_to') : null;
        $f->flag        = $get('flag') ?? $get('flags');
        $f->role        = $get('role');
        $f->listCode    = $get('list_code') ?? $get('list') ?? $get('pick_list');
        if ($f->listCode !== null) {
            $f->listCode = trim((string)$f->listCode);
            if ($f->listCode === '') {
                $f->listCode = null;
            }
        }
        $f->page        = max(1, (int)($q['page'] ?? 1));
        $f->perPage     = min(200, max(5, (int)($q['per_page'] ?? 20)));
        $allowedSort    = ['created_at', 'doc_no', 'progress', 'priority', 'id',
            'type', 'status', 'warehouse', 'goods', 'cell', 'pallet', 'sku',
            'name', 'cells', 'doc_ref', 'executor', 'flags', 'list'];
        if (isset($q['sort']) && in_array($q['sort'], $allowedSort, true)) {
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
            $this->search, $this->createdFrom, $this->createdTo, $this->dueFrom, $this->dueTo,
            $this->types, $this->statuses, $this->warehouseId, $this->executorId, $this->executor,
            $this->docRef, $this->orderRef, $this->sku,
            $this->goodsFrom, $this->goodsTo, $this->linesFrom, $this->linesTo,
            $this->cellsFrom, $this->cellsTo, $this->progressFrom, $this->progressTo,
            $this->volumeFrom, $this->volumeTo, $this->weightFrom, $this->weightTo,
            $this->flag, $this->role, $this->listCode,
        ] as $v) {
            if ($v !== null && $v !== [] && $v !== '') {
                $n++;
            }
        }
        if ($this->priority !== null && $this->priority !== '' && $this->priority !== 'all') {
            $n++;
        }
        return $n;
    }
}
