<?php
declare(strict_types=1);

require_once __DIR__ . '/TaskStatus.php';

// Бизнес-логика "Заданий": нумерация TASK-YYYYMM-NNNNN, статусы, приоритет,
// назначение исполнителя, пауза/возврат, автопрогресс, печать/отправка (демо).

final class TaskService
{
    public function __construct(private PDO $pdo) {}

    public function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    private function now(): string
    {
        return $this->isSqlite() ? "datetime('now')" : 'NOW()';
    }

    public function nextDocNo(): string
    {
        $prefix = 'TASK-' . date('Ym') . '-';
        $st = $this->pdo->prepare("SELECT doc_no FROM tasks WHERE doc_no LIKE :p ORDER BY doc_no DESC LIMIT 1");
        $st->execute([':p' => $prefix . '%']);
        $last = (string)($st->fetchColumn() ?: '');
        $n = 1;
        if (preg_match('/(\d+)$/', $last, $m)) {
            $n = ((int)$m[1]) + 1;
        } else {
            $c = (int)$this->pdo->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
            $n = $c + 2456;
        }
        return $prefix . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
    }

    private function history(int $id, ?int $userId, string $action, ?string $field, ?string $old, ?string $new): void
    {
        $this->pdo->prepare(
            "INSERT INTO task_history (task_id,user_id,action,field,old_value,new_value) VALUES (?,?,?,?,?,?)"
        )->execute([$id, $userId, $action, $field, $old, $new]);
    }

    private function recalc(int $id): void
    {
        $st = $this->pdo->prepare("SELECT COALESCE(SUM(plan_qty),0) p, COALESCE(SUM(fact_qty),0) f, COUNT(*) c FROM task_items WHERE task_id=:id");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $plan = (float)($r['p'] ?? 0);
        $fact = (float)($r['f'] ?? 0);
        $pct = $plan > 0 ? (int)round($fact / $plan * 100) : 0;
        $pct = max(0, min(100, $pct));
        $this->pdo->prepare("UPDATE tasks SET plan_qty=:p, fact_qty=:f, progress=:pr WHERE id=:id")
            ->execute([':p' => $plan, ':f' => $fact, ':pr' => $pct, ':id' => $id]);
        if ($pct >= 100) {
            $this->pdo->prepare("UPDATE tasks SET status='done', done_at=" . $this->now() . " WHERE id=:id AND status IN ('new','work','paused')")
                ->execute([':id' => $id]);
        }
    }

    private function lower(string $s): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($s, 'UTF-8');
        }
        // fallback без mbstring: покрываем формы из макета вручную
        $map = ['СБОР' => 'сбор', 'Сбор' => 'сбор', 'РАЗМЕЩЕНИЕ' => 'размещение', 'Размещение' => 'размещение',
            'ПЕРЕМЕЩЕНИЕ' => 'перемещение', 'Перемещение' => 'перемещение', 'КОМПЛЕКТАЦИЯ' => 'комплектация',
            'Комплектация' => 'комплектация', 'ПОПОЛНЕНИЕ' => 'пополнение', 'Пополнение' => 'пополнение',
            'ОТБОР' => 'отбор', 'Отбор' => 'отбор', 'НИЗКИЙ' => 'низкий', 'Низкий' => 'низкий',
            'СРЕДНИЙ' => 'средний', 'Средний' => 'средний', 'ВЫСОКИЙ' => 'высокий', 'Высокий' => 'высокий',
            'САМЫЙ НИЗКИЙ' => 'самый низкий', 'Самый низкий' => 'самый низкий',
            'КРИТИЧЕСКИЙ' => 'критический', 'Критический' => 'критический'];
        return $map[$s] ?? strtolower($s);
    }

    /** Создание задания (модалка "+ Создать" / "Создать задание"). */
    public function create(array $b, ?int $userId): int
    {
        if (empty($b['type'])) {
            throw new RuntimeException('Укажите тип операции');
        }
        if (empty($b['warehouse_id'])) {
            throw new RuntimeException('Укажите склад');
        }
        $type = $this->lower(trim((string)$b['type']));
        $allowed = ['сбор', 'размещение', 'перемещение', 'комплектация', 'пополнение', 'отбор'];
        if (!in_array($type, $allowed, true)) {
            throw new RuntimeException('Неверный тип операции');
        }
        if ($type === 'отбор') {
            $type = 'сбор';
        }
        $priority = (string)($b['priority'] ?? 'normal');
        $map = ['самый низкий' => 'lowest', 'низкий' => 'low', 'средний' => 'normal', 'высокий' => 'high', 'критический' => 'critical',
            'lowest' => 'lowest', 'low' => 'low', 'normal' => 'normal', 'high' => 'high', 'critical' => 'critical'];
        $priority = $map[$this->lower($priority)] ?? 'normal';

        $this->pdo->beginTransaction();
        try {
            $no = $this->nextDocNo();
            $flags = $b['flags'] ?? null;
            if (is_array($flags)) {
                $flags = implode(',', $flags);
            }
            $st = $this->pdo->prepare(
                "INSERT INTO tasks (doc_no,type,status,priority,warehouse_id,doc_ref,order_ref,
                  executor_id,executor_fio,plan_qty,cells_count,volume,weight,equipment,flags,
                  need_check,comment,due_date,created_at)
                 VALUES (:no,:tp,'new',:pri,:wh,:doc,:ord,:exid,:exfio,0,:cells,:vol,:wei,:eq,:flg,:nc,:cmt,:due," . $this->now() . ")"
            );
            $st->execute([
                ':no' => $no, ':tp' => $type, ':pri' => $priority, ':wh' => (int)$b['warehouse_id'],
                ':doc' => $b['doc_ref'] ?? null, ':ord' => $b['order_ref'] ?? null,
                ':exid' => isset($b['executor_id']) && $b['executor_id'] !== '' ? (int)$b['executor_id'] : null,
                ':exfio' => $b['executor_fio'] ?? $b['executor'] ?? null,
                ':cells' => (int)($b['cells_count'] ?? 0), ':vol' => (float)($b['volume'] ?? 0),
                ':wei' => (float)($b['weight'] ?? 0), ':eq' => $b['equipment'] ?? null,
                ':flg' => $flags, ':nc' => !empty($b['need_check']) ? 1 : 0,
                ':cmt' => $b['comment'] ?? null, ':due' => $b['due_date'] ?? null,
            ]);
            $id = (int)$this->pdo->lastInsertId();
            // Позиции (шаг "Позиции задания"): best effort.
            foreach ((array)($b['items'] ?? []) as $it) {
                if (empty($it['sku']) && empty($it['name'])) {
                    continue;
                }
                $this->pdo->prepare(
                    "INSERT INTO task_items (task_id,code,sku,name,unit,cell_from,cell_to,pallet,plan_qty,fact_qty,state,executor_fio)
                     VALUES (:t,:code,:sku,:name,:unit,:cf,:ct,:pal,:pq,0,:st,:ex)"
                )->execute([
                    ':t' => $id, ':code' => $it['code'] ?? null, ':sku' => $it['sku'] ?? ($it['code'] ?? '—'),
                    ':name' => $it['name'] ?? $it['sku'] ?? 'Позиция', ':unit' => $it['unit'] ?? 'шт',
                    ':cf' => $it['cell_from'] ?? $it['from'] ?? null, ':ct' => $it['cell_to'] ?? $it['to'] ?? null,
                    ':pal' => $it['pallet'] ?? null, ':pq' => (float)($it['plan_qty'] ?? $it['qty'] ?? 0),
                    ':st' => $it['state'] ?? null, ':ex' => $it['executor_fio'] ?? null,
                ]);
            }
            $this->recalc($id);
            $this->history($id, $userId, 'create', null, null, $no);
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function changeStatus(int $id, TaskStatus $to, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT status FROM tasks WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Задание не найдено');
        }
        $from = TaskStatus::from($row['status']);
        if (!$from->canTransitTo($to)) {
            throw new RuntimeException("Нельзя перевести {$from->label()} → {$to->label()}");
        }
        $extra = '';
        if ($to === TaskStatus::Done) {
            $extra = ', done_at=' . $this->now() . ', progress=100';
        } elseif ($to === TaskStatus::Paused) {
            $extra = ', paused_at=' . $this->now();
        } elseif ($to === TaskStatus::New) {
            $extra = ', paused_at=NULL, done_at=NULL';
        }
        $this->pdo->prepare("UPDATE tasks SET status=:s $extra WHERE id=:id")->execute([':s' => $to->value, ':id' => $id]);
        $this->history($id, $userId, 'status', 'status', $from->value, $to->value);
    }

    // ПКМ: Приостановить
    public function pause(int $id, ?int $userId): void
    {
        $this->changeStatus($id, TaskStatus::Paused, $userId);
    }

    // ПКМ: Вернуть к началу
    public function restart(int $id, ?int $userId): void
    {
        $this->pdo->prepare("UPDATE task_items SET fact_qty=0 WHERE task_id=:id")->execute([':id' => $id]);
        $this->pdo->prepare("UPDATE tasks SET fact_qty=0, progress=0 WHERE id=:id")->execute([':id' => $id]);
        $this->changeStatus($id, TaskStatus::New, $userId);
        $this->history($id, $userId, 'restart', null, null, null);
    }

    // ПКМ: Сменить приоритет
    public function priority(int $id, string $priority, ?int $userId): void
    {
        $map = ['самый низкий' => 'lowest', 'низкий' => 'low', 'средний' => 'normal', 'высокий' => 'high', 'критический' => 'critical',
            'lowest' => 'lowest', 'low' => 'low', 'normal' => 'normal', 'high' => 'high', 'critical' => 'critical'];
        $p = $map[$this->lower(trim($priority))] ?? null;
        if (!$p) {
            throw new RuntimeException('Неверный приоритет');
        }
        $old = (string)$this->pdo->query("SELECT priority FROM tasks WHERE id=" . (int)$id)->fetchColumn();
        $this->pdo->prepare("UPDATE tasks SET priority=:p WHERE id=:id")->execute([':p' => $p, ':id' => $id]);
        $this->history($id, $userId, 'priority', 'priority', $old, $p);
    }

    // ПКМ: Назначить исполнителя
    public function assign(int $id, ?int $executorId, ?string $fio, ?int $userId): void
    {
        if (!$executorId && !$fio) {
            throw new RuntimeException('Укажите исполнителя');
        }
        if (!$fio && $executorId) {
            try {
                $fio = (string)$this->pdo->query("SELECT fio FROM users WHERE id=" . (int)$executorId)->fetchColumn();
            } catch (Throwable) {
            }
        }
        $old = (string)$this->pdo->query("SELECT executor_fio FROM tasks WHERE id=" . (int)$id)->fetchColumn();
        $this->pdo->prepare("UPDATE tasks SET executor_id=:e, executor_fio=:f WHERE id=:id")
            ->execute([':e' => $executorId, ':f' => $fio, ':id' => $id]);
        $this->history($id, $userId, 'assign', 'executor', $old, (string)$fio);
    }

    // ПКМ: Выполнить автоматически (демо: fact=plan, статус done)
    public function autoRun(int $id, ?int $userId): void
    {
        $this->pdo->prepare("UPDATE task_items SET fact_qty=plan_qty WHERE task_id=:id")->execute([':id' => $id]);
        $this->recalc($id);
        $this->history($id, $userId, 'auto', null, null, 'done');
    }

    // ТСД/отбор: отметить факт по строке (для будущих экранов ТСД).
    public function pick(int $itemId, float $qty, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT * FROM task_items WHERE id=:id");
        $st->execute([':id' => $itemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Строка не найдена');
        }
        $this->pdo->prepare("UPDATE task_items SET fact_qty=:q WHERE id=:id")->execute([':q' => $qty, ':id' => $itemId]);
        $this->recalc((int)$row['task_id']);
        $this->history((int)$row['task_id'], $userId, 'pick', 'fact_qty', (string)$row['fact_qty'], (string)$qty);
    }

    // Печать / Отправить (демо-данные для мокапа).
    public function printData(int $id, string $kind): array
    {
        $st = $this->pdo->prepare("SELECT * FROM tasks WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Задание не найдено');
        }
        $items = $this->pdo->prepare("SELECT * FROM task_items WHERE task_id=:id ORDER BY id");
        $items->execute([':id' => $id]);
        return ['kind' => $kind, 'head' => $head, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function exportCsv(TaskFilter $f, TaskRepository $repo): string
    {
        $page = $repo->paginate($f);
        $out = fopen('php://temp', 'r+');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['№ задания', 'Дата создания', 'Тип', 'Статус', 'Склад', 'Товары', 'Ячеек', 'Документ', 'Приоритет', 'Прогресс', 'Исполнитель'], ';');
        foreach ($page['rows'] as $r) {
            fputcsv($out, [$r['doc_no'], $r['created_at'], $r['type'], TaskStatus::tryLabel($r['status']),
                $r['warehouse'] ?? $r['warehouse_id'], ($r['fact_qty'] ?? '') . '/' . ($r['plan_qty'] ?? ''),
                $r['cells_count'], $r['doc_ref'], $r['priority'], $r['progress'], $r['executor_fio']], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
