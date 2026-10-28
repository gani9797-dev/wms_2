<?php
declare(strict_types=1);

require_once __DIR__ . '/CellStatus.php';
require_once __DIR__ . '/CellFilter.php';
require_once __DIR__ . '/CellPlacement.php';
require_once __DIR__ . '/../shared/pg.php';
require_once __DIR__ . '/../shared/CellZones.php';

// Репозиторий реестра "Ячейки хранения". PDO, MySQL + SQLite (демо).
// Заполненность считается на лету: qty / capacity.
// Вместимость 0 = безразмерная ячейка (без лимита): fill_pct = 0, признак is_unlimited = true.
// Статус «Робот. Настройка тар» (robot_tare): робот настраивает тару по СЕКЦИИ
// (warehouse_id + building + row_no). Если хотя бы в одной ячейке секции снята
// галка «Разрешено несколько типов паллет» или «Разрешено хранить разные товары» —
// вся секция пропускается роботом (robot_tare = 'skip').

final class CellRepository
{
    /** @var array<string,array{label:string,comment:string}>|null кэш секций с проблемными ячейками */
    private ?array $robotSections = null;
    private ?array $zoneNames = null;

    public function __construct(private PDO $pdo) {}

    /** Название зоны из справочника storage_zones (для кастомных кодов). */
    private function zoneName(?string $code): string
    {
        $code = trim((string)$code);
        if ($code === '') {
            return '';
        }
        if ($this->zoneNames === null) {
            $this->zoneNames = [];
            try {
                foreach ($this->pdo->query("SELECT code, name FROM storage_zones") as $z) {
                    $c = trim((string)($z['code'] ?? ''));
                    if ($c !== '') {
                        $this->zoneNames[strtolower($c)] = trim((string)($z['name'] ?? $c));
                    }
                }
            } catch (Throwable) {
            }
        }
        return $this->zoneNames[strtolower($code)] ?? CellStatus::zoneLabel($code);
    }

    public static function fillPct(float $qty, float $capacity): int
    {
        if ($capacity > 0) {
            return (int)min(100, round($qty / $capacity * 100));
        }
        // Безразмерная ячейка (вместимость 0): заполненность неприменима.
        return 0;
    }

    /** @return array{rows: array, total: int, from: int, to: int, active_filters: int} */
    public function paginate(CellFilter $f): array
    {
        [$where, $params] = $this->buildWhere($f);

        $cnt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM storage_cells c LEFT JOIN warehouses w ON w.id = c.warehouse_id $where"
        );
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f->page - 1) * $f->perPage;
        // Сортировка по любому столбцу реестра (А→Я / Я→А).
        $sortMap = [
            'code' => ['c.code', 't'], 'qty' => ['c.qty', 'n'], 'capacity' => ['c.capacity', 'n'],
            'id' => ['c.id', 'n'], 'zone' => ['c.zone', 't'], 'cell_type' => ['c.cell_type', 't'],
            'beam' => ['c.beam_width', 'n'], 'height' => ['c.cell_height_mm', 'n'],
            'pallet_types' => ['c.allowed_pallet_types', 't'], 'category' => ['c.category', 't'],
            'status' => ['c.status', 't'],
            'fill' => ['(CASE WHEN c.capacity > 0 THEN c.qty * 100.0 / c.capacity ELSE 0 END)', 'n'],
            'reserved' => ['c.reserved_qty', 'n'], 'stock' => ['c.qty', 'n'],
            'pallets' => ['c.pallet_count', 'n'], 'temp' => ['c.temp_from', 'n'],
            'lock' => ['c.is_locked', 'n'],
        ];
        $orderBy = wms_order_by($this->pdo, $f->sort, $f->dir, $sortMap, 'c.code');

        $sql = "SELECT c.*, w.name AS warehouse FROM storage_cells c
                LEFT JOIN warehouses w ON w.id = c.warehouse_id
                $where ORDER BY $orderBy LIMIT :lim OFFSET :off";
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $zones = $this->pickZonesByCell($ids);
        $extraZones = wms_cell_extra_zones_map($this->pdo, $ids);
        foreach ($rows as &$r) {
            $this->decorate($r);
            $this->decoratePickZones($r, $zones);
            $this->decorateZones($r, $extraZones);
        }
        unset($r);

        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $f->perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to,
                'active_filters' => $f->activeCount()];
    }

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT c.*, w.name AS warehouse FROM storage_cells c
             LEFT JOIN warehouses w ON w.id = c.warehouse_id WHERE c.id = :id"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $this->decorate($row);
        $zones = $this->pickZonesByCell([(int)$row['id']]);
        $this->decoratePickZones($row, $zones);
        $this->decorateZones($row, wms_cell_extra_zones_map($this->pdo, [(int)$row['id']]));
        return $row;
    }

    public function findByCode(string $code, ?int $warehouseId = null): ?array
    {
        $sql = "SELECT c.*, w.name AS warehouse FROM storage_cells c
                LEFT JOIN warehouses w ON w.id = c.warehouse_id WHERE c.code = :c";
        $p = [':c' => $code];
        if ($warehouseId) {
            $sql .= " AND c.warehouse_id = :w";
            $p[':w'] = $warehouseId;
        }
        $st = $this->pdo->prepare($sql . " LIMIT 1");
        $st->execute($p);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $this->decorate($row);
        $zones = $this->pickZonesByCell([(int)$row['id']]);
        $this->decoratePickZones($row, $zones);
        $this->decorateZones($row, wms_cell_extra_zones_map($this->pdo, [(int)$row['id']]));
        return $row;
    }

    /**
     * Зоны отбора ячеек (дополнительные): [cell_id => ['manual','driver']].
     * У ячейки может быть несколько зон отбора.
     * @param int[] $cellIds
     */
    public function pickZonesByCell(array $cellIds): array
    {
        $out = [];
        $cellIds = array_values(array_filter(array_map('intval', $cellIds)));
        if (!$cellIds) {
            return $out;
        }
        try {
            $in = implode(',', $cellIds);
            $rows = $this->pdo->query("SELECT cell_id, zone FROM storage_cell_zones WHERE cell_id IN ($in) ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $cid = (int)($r['cell_id'] ?? 0);
                $z = (string)($r['zone'] ?? '');
                if ($cid <= 0 || $z === '') {
                    continue;
                }
                if (!isset($out[$cid])) {
                    $out[$cid] = [];
                }
                if (!in_array($z, $out[$cid], true)) {
                    $out[$cid][] = $z;
                }
            }
        } catch (Throwable) {
        }
        return $out;
    }

    private function decoratePickZones(array &$r, array $zones): void
    {
        $list = $zones[(int)($r['id'] ?? 0)] ?? [];
        $r['pick_zones'] = $list;
        $r['pick_zones_label'] = $list
            ? implode(', ', array_map(fn($z) => CellStatus::pickZoneLabel($z), $list))
            : '—';
    }

    private function decorateZones(array &$r, array $extraMap): void
    {
        $primary = trim((string)($r['zone'] ?? ''));
        $extra = $extraMap[(int)($r['id'] ?? 0)] ?? [];
        $all = [];
        foreach (array_merge([$primary], $extra) as $z) {
            $z = trim((string)$z);
            if ($z !== '' && !in_array($z, $all, true)) {
                $all[] = $z;
            }
        }
        $r['zones'] = $all;
        $r['zones_label'] = $all
            ? implode(', ', array_map(fn($z) => $this->zoneName($z), $all))
            : '—';
    }

    // Вкладка "Содержимое": позиции в ячейке.
    public function content(int $cellId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM cell_content WHERE cell_id = :id ORDER BY sku, id");
        $st->execute([':id' => $cellId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(int $cellId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM cell_history WHERE cell_id = :id ORDER BY created_at DESC, id DESC LIMIT 100");
        $st->execute([':id' => $cellId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function movements(int $cellId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM cell_movements WHERE cell_id = :id ORDER BY doc_date DESC, id DESC LIMIT 100");
        $st->execute([':id' => $cellId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Кнопка "Задания (N)" / ПКМ "Показать задания": deep-link в модуль 07 с фильтром по ячейке.
    public function tasks(int $cellId): array
    {
        $c = $this->findById($cellId);
        if (!$c) {
            return [];
        }
        return [[
            'kind' => 'link',
            'name' => 'Задания по ячейке ' . $c['code'],
            'href' => '../07-zadaniya/app.html?cell=' . urlencode($c['code']),
        ]];
    }

    private function decorate(array &$r): void
    {
        $r['status_label'] = CellStatus::label((string)($r['status'] ?? 'free'));
        $r['zone_label'] = $this->zoneName($r['zone'] ?? null);
        $r['type_label'] = CellStatus::typeLabel($r['cell_type'] ?? null);
        $r['fill_pct'] = self::fillPct((float)($r['qty'] ?? 0), (float)($r['capacity'] ?? 0));
        $r['is_unlimited'] = ((float)($r['capacity'] ?? 0)) <= 0;
        $r['temp_label'] = $this->tempLabel($r);
        $r['lock_label'] = ((int)($r['is_locked'] ?? 0) === 1) ? 'Да' : 'Нет';
        $beam = isset($r['beam_width']) && $r['beam_width'] !== null && (float)$r['beam_width'] > 0
            ? (float)$r['beam_width'] : 3.2;
        $r['beam_width'] = $beam <= 3.4 ? 3.2 : 3.6;
        $r['beam_label'] = number_format($r['beam_width'], 1, ',', '') . ' м';
        $r['pallet_types_label'] = self::palletTypesLabel($r['allowed_pallet_types'] ?? null);
        $r['pallet_type_label'] = self::palletTypeLabel($r['pallet_type'] ?? null);
        $r['height_label'] = isset($r['cell_height_mm']) && $r['cell_height_mm'] !== null && (int)$r['cell_height_mm'] > 0
            ? ((int)$r['cell_height_mm'] . ' мм') : '—';
        $this->decorateRobotTare($r);
    }

    /**
     * Статус секции для робота (настройка тары): 'ok' | 'skip'.
     * Секция = warehouse_id + building + row_no (Ряд + Секция = вертикаль).
     * Ячейки зоны receiving (ворота) и архивные в расчёт секции не входят.
     */
    private function decorateRobotTare(array &$r): void
    {
        $r['robot_tare'] = 'ok';
        $r['robot_tare_comment'] = '';
        $key = CellPlacement::sectionKey($r);
        $bad = $this->problemSections()[$key] ?? null;
        if ($bad !== null) {
            $r['robot_tare'] = 'skip';
            $r['robot_tare_comment'] = (string)($bad['comment'] ?? '');
            return;
        }
        $label = self::sectionLabel($r);
        $r['robot_tare_comment'] = $label !== ''
            ? 'Секция ' . $label . ' настроена: робот может настроить тару'
            : 'Секция настроена: робот может настроить тару';
    }

    /** Название секции: корпус-ряд (например A-01). */
    public static function sectionLabel(array $r): string
    {
        $b = trim((string)($r['building'] ?? ''));
        $s = trim((string)($r['row_no'] ?? ''));
        if ($b !== '' && $s !== '') {
            return $b . '-' . $s;
        }
        return $b !== '' ? $b : $s;
    }

    /**
     * Секции с проблемными ячейками: робот по настройке тары их пропускает.
     * Проблемная ячейка: снят флаг «Разрешено несколько типов паллет»
     * или «Разрешено хранить разные товары в одной ячейке».
     * @return array<string,array{label:string,comment:string}> ключ секции => данные
     */
    public function problemSections(): array
    {
        if ($this->robotSections !== null) {
            return $this->robotSections;
        }
        $out = [];
        try {
            $rows = $this->pdo->query(
                "SELECT id, code, warehouse_id, building, row_no, allow_multi_pallet_types, allow_mixed_goods
                   FROM storage_cells
                  WHERE is_archived = 0 AND (zone IS NULL OR zone <> 'receiving')
                    AND (COALESCE(allow_multi_pallet_types, 1) = 0 OR COALESCE(allow_mixed_goods, 1) = 0)
                  ORDER BY code ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $key = CellPlacement::sectionKey($r);
                if (isset($out[$key])) {
                    continue; // комментарий — по первой проблемной ячейке секции
                }
                $label = self::sectionLabel($r);
                $flags = [];
                if ((int)($r['allow_mixed_goods'] ?? 1) === 0) {
                    $flags[] = 'Разные товары';
                }
                if ((int)($r['allow_multi_pallet_types'] ?? 1) === 0) {
                    $flags[] = 'Несколько типов паллет';
                }
                $flagTxt = count($flags) === 1
                    ? 'снят флаг «' . $flags[0] . '»'
                    : 'сняты флаги «' . implode('» и «', $flags) . '»';
                $out[$key] = [
                    'label' => $label,
                    'comment' => 'Секция ' . ($label !== '' ? $label : 'без номера') . ' пропускается: у ячейки '
                        . ($r['code'] ?? '') . ' ' . $flagTxt,
                ];
            }
        } catch (Throwable) {
        }
        return $this->robotSections = $out;
    }

    public static function palletTypesLabel(mixed $v): string
    {
        $raw = trim((string)($v ?? ''));
        if ($raw === '' || $raw === 'euro,fin' || $raw === 'fin,euro') {
            return 'Евро + Фин';
        }
        if ($raw === 'euro') {
            return 'Евро';
        }
        if ($raw === 'fin') {
            return 'Фин';
        }
        return $raw;
    }

    public static function palletTypeLabel(mixed $v): string
    {
        $s = trim((string)($v ?? ''));
        if ($s === '') {
            return '—';
        }
        return $s === 'fin' ? 'Фин' : ($s === 'euro' ? 'Евро' : $s);
    }

    private function tempLabel(array $r): string
    {
        $a = $r['temp_from'] ?? null;
        $b = $r['temp_to'] ?? null;
        if ($a === null && $b === null) {
            return '—';
        }
        $fmt = fn($v) => ((float)$v > 0 ? '+' : '') . rtrim(rtrim((string)(float)$v, '0'), '.');
        return $fmt($a) . '...' . $fmt($b) . ' °C';
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(CellFilter $f): array
    {
        $w = ['c.is_archived = 0'];
        $p = [];
        if ($f->search !== null) {
            $w[] = '(c.code LIKE :q OR c.category LIKE :q)';
            $p[':q'] = '%' . $f->search . '%';
        }
        if ($f->warehouseId) {
            $w[] = 'c.warehouse_id = :wh';
            $p[':wh'] = $f->warehouseId;
        }
        if ($f->zone) {
            $w[] = '(c.zone = :zone OR EXISTS (SELECT 1 FROM storage_cell_extra_zones ez WHERE ez.cell_id = c.id AND ez.zone = :zone))';
            $p[':zone'] = $f->zone;
        }
        // Доп. зона отбора (ручной отбор / отбор водителем) — у ячейки может быть несколько зон.
        if ($f->pickZone) {
            $w[] = 'EXISTS (SELECT 1 FROM storage_cell_zones cz WHERE cz.cell_id = c.id AND cz.zone = :pzone)';
            $p[':pzone'] = $f->pickZone;
        }
        if ($f->cellType) {
            $w[] = 'c.cell_type = :ct';
            $p[':ct'] = $f->cellType;
        }
        if ($f->statuses) {
            $in = [];
            foreach (array_values($f->statuses) as $i => $s) {
                if (!in_array($s, CellStatus::values(), true)) {
                    continue;
                }
                $k = ":st$i";
                $in[] = $k;
                $p[$k] = $s;
            }
            if ($in) {
                $w[] = 'c.status IN (' . implode(',', $in) . ')';
            }
        }
        if ($f->category) {
            $w[] = 'c.category = :cat';
            $p[':cat'] = $f->category;
        }
        // CAST(:x AS DOUBLE) нужен для SQLite: привязанный параметр приходит строкой
        // и сравнение числа со строкой дает неверный результат. В MySQL CAST безвреден.
        if ($f->tempFrom !== null) {
            $w[] = 'c.temp_from >= CAST(:tf AS DOUBLE)';
            $p[':tf'] = $f->tempFrom;
        }
        if ($f->tempTo !== null) {
            $w[] = 'c.temp_to <= CAST(:tt AS DOUBLE)';
            $p[':tt'] = $f->tempTo;
        }
        if ($f->allowed) {
            $w[] = 'c.allowed_goods = :al';
            $p[':al'] = $f->allowed;
        }
        if ($f->locked === 'yes') {
            $w[] = 'c.is_locked = 1';
        } elseif ($f->locked === 'no') {
            $w[] = 'c.is_locked = 0';
        }
        // Настройки ячейки: разные товары / несколько типов паллет.
        if ($f->mixedGoods === 'yes') {
            $w[] = 'COALESCE(c.allow_mixed_goods, 1) = 1';
        } elseif ($f->mixedGoods === 'no') {
            $w[] = 'COALESCE(c.allow_mixed_goods, 1) = 0';
        }
        if ($f->multiPallet === 'yes') {
            $w[] = 'COALESCE(c.allow_multi_pallet_types, 1) = 1';
        } elseif ($f->multiPallet === 'no') {
            $w[] = 'COALESCE(c.allow_multi_pallet_types, 1) = 0';
        }
        if ($f->hasGoods === 'yes') {
            $w[] = 'c.qty > 0';
        } elseif ($f->hasGoods === 'no') {
            $w[] = 'c.qty <= 0';
        }
        if ($f->palletFrom !== null) {
            $w[] = 'c.pallet_count >= CAST(:pf AS DOUBLE)';
            $p[':pf'] = $f->palletFrom;
        }
        if ($f->palletTo !== null) {
            $w[] = 'c.pallet_count <= CAST(:pt AS DOUBLE)';
            $p[':pt'] = $f->palletTo;
        }
        if ($f->qtyFrom !== null) {
            $w[] = 'c.qty >= CAST(:qf AS DOUBLE)';
            $p[':qf'] = $f->qtyFrom;
        }
        if ($f->qtyTo !== null) {
            $w[] = 'c.qty <= CAST(:qt AS DOUBLE)';
            $p[':qt'] = $f->qtyTo;
        }
        // Заполненность — производное поле: фильтруем выражением.
        // Безразмерные ячейки (capacity = 0) в фильтр заполненности не попадают.
        // CAST нужен для SQLite: привязанный параметр приходит строкой, а число < текст всегда.
        if ($f->fillFrom !== null) {
            $w[] = '(c.capacity > 0 AND c.qty / c.capacity * 100 >= CAST(:ff AS DOUBLE))';
            $p[':ff'] = $f->fillFrom;
        }
        if ($f->fillTo !== null) {
            $w[] = '(c.capacity > 0 AND c.qty / c.capacity * 100 <= CAST(:ft AS DOUBLE))';
            $p[':ft'] = $f->fillTo;
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }
}
