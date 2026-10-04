<?php
declare(strict_types=1);

require_once __DIR__ . '/ReceiptStatus.php';
require_once __DIR__ . '/../shared/DocNo.php';

// Бизнес-логика поступлений: нумерация, пересчет сумм, ПКМ-действия.
// Зеркалит ShipmentService, но под конвейер приемки/размещения.

final class ReceiptService
{
    public function __construct(private PDO $pdo) {}

    // Следующий номер поступления: A-105101, A-105102, ... (см. shared/DocNo.php).
    public function nextDocNo(): string
    {
        return wms_next_receipt_no($this->pdo);
    }

    // Пересчет шапки из позиций: сумма без НДС, НДС, итого, долг, счетчики.
    public function recalc(int $receiptId): void
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) c, COUNT(DISTINCT sku) sku, COALESCE(SUM(qty),0) q,
                    COALESCE(SUM(sum_novat),0) s, COALESCE(SUM(vat_sum),0) v, COALESCE(SUM(sum_vat),0) sv
             FROM receipt_items WHERE receipt_id=:id"
        );
        $st->execute([':id' => $receiptId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);

        $h = $this->pdo->prepare("SELECT paid FROM receipts WHERE id=:id");
        $h->execute([':id' => $receiptId]);
        $paid = (float)($h->fetchColumn() ?: 0);

        $total = round((float)$r['s'], 2);
        $vat = round((float)$r['v'], 2);
        $withVat = round((float)$r['sv'], 2);
        if ($withVat <= 0 && $total > 0) {
            $withVat = $total + $vat;
        }
        $debt = round($withVat - $paid, 2);

        $u = $this->pdo->prepare(
            "UPDATE receipts SET total=:t, vat_sum=:v, total_with_vat=:sv,
              items_count=:c, sku_count=:sku, qty_total=:q, debt=:d WHERE id=:id"
        );
        $u->execute([
            ':t' => $total, ':v' => $vat, ':sv' => $withVat,
            ':c' => (int)$r['c'], ':sku' => (int)$r['sku'], ':q' => (float)$r['q'],
            ':d' => $debt, ':id' => $receiptId,
        ]);
    }

    private function history(int $id, ?int $userId, string $action, ?string $field, ?string $old, ?string $new, ?string $oldSt = null, ?string $newSt = null): void
    {
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO receipt_history (receipt_id,user_id,action,field,old_value,new_value,old_status,new_status) VALUES (?,?,?,?,?,?,?,?)"
            );
            $st->execute([$id, $userId, $action, $field, $old, $new, $oldSt, $newSt]);
        } catch (Throwable $e) {
        }
    }

    // ПКМ: Копировать — новый черновик с теми же позициями/операциями.
    public function copy(int $id, ?int $userId): int
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM receipts WHERE id=:id");
            $st->execute([':id' => $id]);
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (!$src) {
                throw new RuntimeException('Документ не найден');
            }
            $newNo = $this->nextDocNo();
            $ins = $this->pdo->prepare(
                "INSERT INTO receipts (doc_no,external_no,doc_date,plan_delivery_date,supplier_id,contractor_id,
                  contract_no,warehouse_id,status,receipt_type,accept_type,unload_type,
                  vehicle_plan,vehicle_fact,vehicle,gate,cargo_places_plan,priority,
                  vat_rate,paid,comment,creator_id,created_by)
                 VALUES (:no,:ext,datetime('now'),:plan,:sup,:ctr,:cn,:wh,'draft',:rt,:at,:ut,
                  :vp,:vf,:veh,:gate,:cp,:pri,:vat,0,:cmt,:cb,:cb)"
            );
            // MySQL: datetime('now') -> NOW(); поправим на ходу
            $ins = $this->fixNow($ins, "INSERT INTO receipts (doc_no,external_no,doc_date,plan_delivery_date,supplier_id,contractor_id,contract_no,warehouse_id,status,receipt_type,accept_type,unload_type,vehicle_plan,vehicle_fact,vehicle,gate,cargo_places_plan,priority,vat_rate,paid,comment,creator_id,created_by) VALUES (:no,:ext," . $this->now() . ",:plan,:sup,:ctr,:cn,:wh,'draft',:rt,:at,:ut,:vp,:vf,:veh,:gate,:cp,:pri,:vat,0,:cmt,:cb,:cb)");
            $ins->execute([
                ':no' => $newNo, ':ext' => $src['external_no'] ?? null, ':plan' => $src['plan_delivery_date'] ?? null,
                ':sup' => $src['supplier_id'], ':ctr' => $src['contractor_id'], ':cn' => $src['contract_no'] ?? null,
                ':wh' => $src['warehouse_id'], ':rt' => $src['receipt_type'] ?? 'supply',
                ':at' => $src['accept_type'] ?? null, ':ut' => $src['unload_type'] ?? null,
                ':vp' => $src['vehicle_plan'] ?? null, ':vf' => $src['vehicle_fact'] ?? null,
                ':veh' => $src['vehicle'] ?? null, ':gate' => $src['gate'] ?? null,
                ':cp' => $src['cargo_places_plan'] ?? 0, ':pri' => $src['priority'] ?? 'normal',
                ':vat' => $src['vat_rate'] ?? 20, ':cmt' => $src['comment'] ?? null, ':cb' => $userId,
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                "INSERT INTO receipt_items (receipt_id,line_no,code,sku,name,unit,qty,boxes,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat)
                 SELECT :nid,line_no,code,sku,name,unit,qty,boxes,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat
                 FROM receipt_items WHERE receipt_id=:oid"
            )->execute([':nid' => $newId, ':oid' => $id]);
            $this->recalc($newId);
            $this->history($newId, $userId, 'create', 'Документ №', null, $newNo, null, 'draft');
            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function changeStatus(int $id, ReceiptStatus $to, ?int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT status FROM receipts WHERE id=:id");
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Документ не найден');
            }
            $from = ReceiptStatus::from($row['status']);
            if (!$from->canTransitTo($to)) {
                throw new RuntimeException("Нельзя перевести {$from->label()} → {$to->label()}");
            }
            $posted = in_array($to, [ReceiptStatus::Closed, ReceiptStatus::ClosedShort], true) ? 1 : 0;
            $this->pdo->prepare("UPDATE receipts SET status=:s, is_posted=:p WHERE id=:id")
                ->execute([':s' => $to->value, ':p' => $posted, ':id' => $id]);
            $this->history($id, $userId, 'status', 'Статус', $from->label(), $to->label(), $from->value, $to->value);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function delete(int $id, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT status FROM receipts WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Документ не найден');
        }
        if (!in_array($row['status'], ['draft', 'cancelled'], true)) {
            throw new RuntimeException('Удалять можно только черновик или отмененное');
        }
        $this->pdo->prepare("DELETE FROM receipts WHERE id=:id")->execute([':id' => $id]);
    }

    public function archive(int $id, ?int $userId): void
    {
        $this->changeStatus($id, ReceiptStatus::Archived, $userId);
    }

    public function priority(int $id, string $priority, ?int $userId): void
    {
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            throw new RuntimeException('Неверный приоритет');
        }
        $this->pdo->prepare("UPDATE receipts SET priority=:p WHERE id=:id")->execute([':p' => $priority, ':id' => $id]);
        $this->history($id, $userId, 'update', 'Приоритет', null, $priority);
    }

    // ПКМ: Назначить приемщика / Принять автоматически (доприемка)
    public function assignReceiver(int $id, int $receiverId, ?int $userId): void
    {
        $this->pdo->prepare("UPDATE receipts SET responsible_id=:r WHERE id=:id")->execute([':r' => $receiverId, ':id' => $id]);
        $this->history($id, $userId, 'update', 'Приемщик', null, (string)$receiverId);
    }

    public function printData(int $id, string $kind): array
    {
        $st = $this->pdo->prepare("SELECT * FROM receipts WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        $items = $this->pdo->prepare("SELECT * FROM receipt_items WHERE receipt_id=:id ORDER BY line_no, id");
        $items->execute([':id' => $id]);
        return ['kind' => $kind, 'head' => $head, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function exportCsv(ReceiptFilter $f, object $repo): string
    {
        $page = $repo->paginate($f);
        $out = fopen('php://temp', 'r+');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['№ документа', '№ внешний', 'Дата прибытия', 'Дата приемки', 'Поставщик', 'Склад', 'ТС', 'Ворота', 'Тип разгрузки', 'Статус', 'Сумма', 'SKU', 'шт'], ';');
        foreach ($page['rows'] as $r) {
            fputcsv($out, [$r['doc_no'], $r['external_no'], $r['arrival_date'], $r['accept_date'],
                $r['supplier'], $r['warehouse'], $r['vehicle'], $r['gate'], $r['unload_type'],
                ReceiptStatus::tryLabel($r['status']), $r['total'], $r['sku_count'], $r['qty_total']], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    private function fixNow(PDOStatement $stmt, string $sql): PDOStatement
    {
        // Пересоздать statement с корректной функцией даты (SQLite vs MySQL).
        try {
            return $this->pdo->prepare($sql);
        } catch (Throwable $e) {
            return $stmt;
        }
    }
}
