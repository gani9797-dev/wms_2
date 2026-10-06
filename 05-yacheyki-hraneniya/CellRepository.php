<?php
declare(strict_types=1);

require_once __DIR__ . '/CellStatus.php';
require_once __DIR__ . '/CellFilter.php';

// Репозиторий реестра "Ячейки хранения". PDO, MySQL + SQLite (демо).
// Заполненность считается на лету: qty / capacity.
// Вместимость 0 = безразмерная ячейка (без лимита): fill_pct = 0, признак is_unlimited = true.

final class CellRepository
{
    public function __construct(private PDO $pdo) {}

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
        $sortMap = ['code' => 'c.code', 'qty' => 'c.qty', 'capacity' => 'c.capacity', 'id' => 'c.id'];
        $orderBy = ($sortMap[$f->sort] ?? 'c.code') . ' ' . $f->dir;

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
        foreach ($rows as &$r) {
            $this->decorate($r);
        }

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
        return $row;
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
        $r['zone_label'] = CellStatus::zoneLabel($r['zone'] ?? null);
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
            $w[] = 'c.zone = :zone';
            $p[':zone'] = $f->zone;
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
