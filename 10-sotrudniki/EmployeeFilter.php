<?php
declare(strict_types=1);

// DTO фильтров реестра «Сотрудники». Повторяет панель «Фильтры» из макета 10.
// Все поля nullable: null = фильтр не активен (для счётчика «Фильтры (N)»).
final class EmployeeFilter
{
    public ?string $search = null;        // ID_user, ФИО, должность, код
    public ?string $createdFrom = null;   // Дата создания (от)
    public ?string $createdTo = null;     // Дата создания (по)
    public ?string $updatedFrom = null;   // Дата редактирования (от)
    public ?string $updatedTo = null;     // Дата редактирования (по)
    public ?string $position = null;      // Должность
    public ?string $department = null;    // Отдел
    public ?int $warehouseId = null;      // Склад (Все склады = null)
    public ?string $status = null;        // active/blocked/fired/all
    public ?string $shiftFrom = null;     // Был на смене (от)
    public ?string $shiftTo = null;       // Был на смене (по)
    public ?string $hiredFrom = null;     // Дата приема (от)
    public ?string $hiredTo = null;       // Дата приема (по)
    public ?int $experienceFrom = null;   // Стаж в компании, месяцев (от)
    public ?int $experienceTo = null;     // Стаж в компании, месяцев (по)
    public ?string $scheduleFrom = null;  // График работ (от)
    public ?string $scheduleTo = null;    // График работ (по)
    public ?int $onShift = null;          // На смене: 1/0/null
    public ?int $isBlocked = null;        // Заблокирован: 1/0/null
    public ?int $unplannedExit = null;    // Внеплановый выход: 1/0/null

    public int $page = 1;
    public int $perPage = 24;
    public string $sort = 'fio';
    public string $dir = 'ASC';

    public static function fromArray(array $q): self
    {
        $f = new self();
        $get = fn(string $k) => isset($q[$k]) && $q[$k] !== '' ? $q[$k] : null;
        $getInt = fn(string $k) => ($v = $get($k)) !== null ? (int)$v : null;
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
        $f->shiftFrom = $get('shift_from');    $f->shiftTo = $get('shift_to');
        $f->hiredFrom = $get('hired_from');    $f->hiredTo = $get('hired_to');
        $f->scheduleFrom = $get('schedule_from'); $f->scheduleTo = $get('schedule_to');
        $f->position = $get('position');       $f->department = $get('department');
        $wh = $get('warehouse_id') ?? $get('warehouse');
        $f->warehouseId = ($wh !== null && $wh !== 'all') ? (int)$wh : null;
        $st = $get('status');
        $f->status = ($st !== null && $st !== 'all') ? (string)$st : null;
        $f->experienceFrom = $getInt('experience_from');
        $f->experienceTo = $getInt('experience_to');
        $f->onShift = $getFlag('on_shift');
        $f->isBlocked = $getFlag('is_blocked');
        $f->unplannedExit = $getFlag('unplanned_exit');
        $f->page = max(1, (int)($q['page'] ?? 1));
        $f->perPage = wms_per_page($q['per_page'] ?? 24, 24, 200, 5);
        $allowed = ['id', 'fio', 'position', 'code', 'department', 'status',
            'hired_at', 'created_at', 'updated_at', 'shift'];
        if (isset($q['sort']) && in_array($q['sort'], $allowed, true)) $f->sort = $q['sort'];
        if (isset($q['dir']) && strtoupper((string)$q['dir']) === 'DESC') $f->dir = 'DESC';
        return $f;
    }

    /** Сколько фильтров активно — для бейджа «Фильтры (N)». */
    public function activeCount(): int
    {
        $n = 0;
        foreach ([
            $this->search, $this->createdFrom, $this->createdTo, $this->updatedFrom, $this->updatedTo,
            $this->position, $this->department, $this->warehouseId, $this->status,
            $this->shiftFrom, $this->shiftTo, $this->hiredFrom, $this->hiredTo,
            $this->experienceFrom, $this->experienceTo,
            $this->scheduleFrom, $this->scheduleTo,
            $this->onShift, $this->isBlocked, $this->unplannedExit,
        ] as $v) {
            if ($v !== null && $v !== '' && $v !== []) $n++;
        }
        return $n;
    }
}
