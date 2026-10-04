<?php
declare(strict_types=1);

require_once __DIR__ . '/TaskStatus.php';
require_once __DIR__ . '/TaskFilter.php';

// Репозиторий реестра "Задания". Чистый PHP + PDO, зеркалит ShipmentRepository.
//  1. Поиск (№ задания, тип, сотрудник, товар) -> WHERE LIKE / JOIN items
//  2. Фильтры -> AND-условия
//  3. COUNT(*) для "Показано X–Y из Z"
//  4. Сортировка + LIMIT/OFFSET
//  5. active_filters для бейджа "Фильтры (N)".

final class TaskRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{rows: array, total: int, from: int, to: int, active_filters: int} */
    public function paginate(TaskFilter $f): array
    {
        [$where, $params] = $this->buildWhere($f);

        $cnt = $this->pdo->prepare("SELECT COUNT(DISTINCT t.id) FROM tasks t LEFT JOIN task_items i ON i.task_id = t.id $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $offset = ($f->page - 1) * $f->perPage;
        $sortMap = [
            'created_at' => 't.created_at', 'doc_no' => 't.doc_no',
            'progress' => 't.progress', 'priority' => 't.priority', 'id' => 't.id',
        ];
        $orderBy = ($sortMap[$f->sort] ?? 't.created_at') . ' ' . $f->dir;

        $sql = "SELECT t.id, t.doc_no, t.created_at, t.type, t.status, t.priority,
                       t.warehouse_id, w.name AS warehouse,
                       t.doc_ref, t.order_ref, t.executor_id, t.executor_fio,
                       t.plan_qty, t.fact_qty, t.progress, t.cells_count,
                       t.volume, t.weight, t.equipment, t.flags, t.due_date, t.done_at,
                       (SELECT COUNT(*) FROM task_items x WHERE x.task_id = t.id) AS lines_count
                FROM tasks t
                LEFT JOIN warehouses w ON w.id = t.warehouse_id
                LEFT JOIN task_items i ON i.task_id = t.id
                $where
                GROUP BY t.id
                ORDER BY $orderBy LIMIT :lim OFFSET :off";
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $f->perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $from = $total === 0 ? 0 : $offset + 1;
        $to = min($offset + $f->perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to,
                'active_filters' => $f->activeCount()];
    }

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT t.*, w.name AS warehouse_name
             FROM tasks t LEFT JOIN warehouses w ON w.id = t.warehouse_id
             WHERE t.id = :id"
        );
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $it = $this->pdo->prepare("SELECT * FROM task_items WHERE task_id = :id ORDER BY id");
        $it->execute([':id' => $id]);
        $row['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
        $row['status_label'] = TaskStatus::tryLabel((string)$row['status']);
        $row['items_count_v'] = count($row['items']);
        try {
            $h = $this->pdo->prepare("SELECT * FROM task_history WHERE task_id = :id ORDER BY created_at DESC, id DESC LIMIT 50");
            $h->execute([':id' => $id]);
            $row['history_count'] = count($h->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            $row['history_count'] = 0;
        }
        return $row;
    }

    public function items(int $taskId, int $page = 1, int $perPage = 50): array
    {
        $off = ($page - 1) * $perPage;
        $st = $this->pdo->prepare("SELECT * FROM task_items WHERE task_id = :id ORDER BY id LIMIT :lim OFFSET :off");
        $st->bindValue(':id', $taskId, PDO::PARAM_INT);
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', $off, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM task_items WHERE task_id = :id");
        $c->execute([':id' => $taskId]);
        $total = (int)$c->fetchColumn();
        return ['rows' => $rows, 'total' => $total,
                'from' => $total ? $off + 1 : 0, 'to' => min($off + $perPage, $total)];
    }

    public function history(int $taskId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM task_history WHERE task_id = :id ORDER BY created_at DESC, id DESC");
        $st->execute([':id' => $taskId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function documents(int $taskId): array
    {
        // Связанные документы = doc_ref/order_ref шапки + печатные формы (демо).
        $t = $this->findById($taskId);
        if (!$t) {
            return [];
        }
        $out = [];
        if (!empty($t['doc_ref'])) {
            $out[] = ['name' => 'Документ ' . $t['doc_ref'], 'format' => 'link', 'ref' => $t['doc_ref']];
        }
        if (!empty($t['order_ref'])) {
            $out[] = ['name' => 'Заказ ' . $t['order_ref'], 'format' => 'link', 'ref' => $t['order_ref']];
        }
        return $out;
    }

    /** @return array{0:string,1:array} */
    private function buildWhere(TaskFilter $f): array
    {
        $w = ['1=1'];
        $p = [];
        if ($f->search !== null) {
            $w[] = '(t.doc_no LIKE :q OR t.type LIKE :q OR t.executor_fio LIKE :q OR i.sku LIKE :q OR i.name LIKE :q OR t.doc_ref LIKE :q)';
            $p[':q'] = '%' . $f->search . '%';
        }
        if ($f->createdFrom) {
            $w[] = 't.created_at >= :cf';
            $p[':cf'] = $f->createdFrom . ' 00:00:00';
        }
        if ($f->createdTo) {
            $w[] = 't.created_at <= :ct';
            $p[':ct'] = $f->createdTo . ' 23:59:59';
        }
        if ($f->dueFrom) {
            $w[] = 't.due_date >= :df';
            $p[':df'] = $f->dueFrom;
        }
        if ($f->dueTo) {
            $w[] = 't.due_date <= :dt';
            $p[':dt'] = $f->dueTo;
        }
        if ($f->types) {
            $in = [];
            foreach (array_values($f->types) as $i => $s) {
                $k = ":tp$i";
                $in[] = $k;
                $p[$k] = (string)$s;
            }
            if ($in) {
                $w[] = 't.type IN (' . implode(',', $in) . ')';
            }
        }
        if ($f->statuses) {
            $in = [];
            foreach (array_values($f->statuses) as $i => $s) {
                if (!in_array($s, TaskStatus::values(), true)) {
                    continue;
                }
                $k = ":st$i";
                $in[] = $k;
                $p[$k] = $s;
            }
            if ($in) {
                $w[] = 't.status IN (' . implode(',', $in) . ')';
            }
        }
        if ($f->warehouseId) {
            $w[] = 't.warehouse_id = :wh';
            $p[':wh'] = $f->warehouseId;
        }
        if ($f->executorId) {
            $w[] = 't.executor_id = :ex';
            $p[':ex'] = $f->executorId;
        }
        if ($f->executor !== null && $f->executor !== '') {
            $w[] = 't.executor_fio LIKE :exf';
            $p[':exf'] = '%' . $f->executor . '%';
        }
        if ($f->priority !== null && $f->priority !== '' && $f->priority !== 'all') {
            $w[] = 't.priority = :pri';
            $p[':pri'] = $f->priority;
        }
        if ($f->docRef) {
            $w[] = 't.doc_ref LIKE :doc';
            $p[':doc'] = '%' . $f->docRef . '%';
        }
        if ($f->orderRef) {
            $w[] = 't.order_ref LIKE :ord';
            $p[':ord'] = '%' . $f->orderRef . '%';
        }
        if ($f->sku) {
            $w[] = '(i.sku LIKE :sku OR i.code LIKE :sku)';
            $p[':sku'] = '%' . $f->sku . '%';
        }
        foreach ([
            [$f->goodsFrom, 't.plan_qty', '>=', ':gf'], [$f->goodsTo, 't.plan_qty', '<=', ':gt'],
            [$f->cellsFrom, 't.cells_count', '>=', ':cf2'], [$f->cellsTo, 't.cells_count', '<=', ':ct2'],
            [$f->progressFrom, 't.progress', '>=', ':pf'], [$f->progressTo, 't.progress', '<=', ':pt'],
            [$f->volumeFrom, 't.volume', '>=', ':vf'], [$f->volumeTo, 't.volume', '<=', ':vt'],
            [$f->weightFrom, 't.weight', '>=', ':wf'], [$f->weightTo, 't.weight', '<=', ':wt'],
        ] as [$val, $col, $op, $ph]) {
            if ($val !== null) {
                $w[] = "$col $op $ph";
                $p[$ph] = $val;
            }
        }
        if ($f->flag !== null && $f->flag !== '' && $f->flag !== 'all') {
            $w[] = 't.flags LIKE :flg';
            $p[':flg'] = '%' . $f->flag . '%';
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }
}
