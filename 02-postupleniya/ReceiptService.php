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
            $fio = null;
            $position = null;
            if (function_exists('wms_auth_user')) {
                $u = wms_auth_user();
                if ($u) {
                    $fio = (string)($u['fio'] ?? '');
                    $position = (string)($u['position'] ?? '');
                }
            }
            $st = $this->pdo->prepare(
                "INSERT INTO receipt_history (receipt_id,user_id,user_fio,position,action,field,old_value,new_value,old_status,new_status) VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([$id, $userId, $fio, $position, $action, $field, $old, $new, $oldSt, $newSt]);
        } catch (Throwable $e) {
        }
    }

    private function assertItemsEditable(array $row): void
    {
        $status = (string)($row['status'] ?? '');
        if ((int)($row['is_posted'] ?? 0) === 1
            || in_array($status, ['closed', 'closed_short', 'cancelled', 'archived'], true)) {
            throw new RuntimeException('Строки нельзя менять в статусе «' . $status . '»');
        }
    }

    private function number(mixed $v): float
    {
        return (float)str_replace(',', '.', trim((string)$v));
    }

    /** Сколько по строке уже принято/обработано. */
    public function processedQty(int $receiptId, array $item): float
    {
        $fact = max(0.0, (float)($item['qty_fact'] ?? 0));
        $lineNo = (int)($item['line_no'] ?? 0);
        $sku = (string)($item['sku'] ?? '');
        $batch = (string)($item['supplier_batch'] ?? '');
        $ops = 0.0;
        try {
            if ($lineNo > 0) {
                $st = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(qty),0) FROM receipt_operations
                      WHERE receipt_id=:r AND line_no=:ln AND accept_status IN ('Принято','Брак')"
                );
                $st->execute([':r' => $receiptId, ':ln' => $lineNo]);
                $ops = (float)$st->fetchColumn();
            } elseif ($sku !== '') {
                // Старые операции ТСД без line_no: используем только если строка с таким SKU/партией одна.
                $cnt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM receipt_items
                      WHERE receipt_id=:r AND sku=:s AND COALESCE(supplier_batch,'')=:b"
                );
                $cnt->execute([':r' => $receiptId, ':s' => $sku, ':b' => $batch]);
                if ((int)$cnt->fetchColumn() === 1) {
                    $st = $this->pdo->prepare(
                        "SELECT COALESCE(SUM(qty),0) FROM receipt_operations
                          WHERE receipt_id=:r AND sku=:s AND COALESCE(supplier_batch,'')=:b
                            AND COALESCE(line_no,0)=0 AND accept_status IN ('Принято','Брак')"
                    );
                    $st->execute([':r' => $receiptId, ':s' => $sku, ':b' => $batch]);
                    $ops = (float)$st->fetchColumn();
                }
            }
        } catch (Throwable) {
        }
        return round(max($fact, $ops), 3);
    }

    /** Изменить количество строки (не ниже уже обработанного). */
    public function updateItem(int $receiptId, int $itemId, array $data, ?int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM receipts WHERE id=:id");
            $st->execute([':id' => $receiptId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Документ не найден');
            }
            $this->assertItemsEditable($row);
            $it = $this->pdo->prepare("SELECT * FROM receipt_items WHERE id=:id AND receipt_id=:rid");
            $it->execute([':id' => $itemId, ':rid' => $receiptId]);
            $item = $it->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new RuntimeException('Строка не найдена');
            }
            $processed = $this->processedQty($receiptId, $item);
            $oldQty = (float)($item['qty'] ?? 0);
            $qty = $this->number($data['qty'] ?? $oldQty);
            if ($qty <= 0) {
                throw new RuntimeException('Количество должно быть больше нуля');
            }
            if ($qty + 0.0001 < $processed) {
                throw new RuntimeException('Нельзя указать меньше обработанного: ' . $processed);
            }
            $price = (float)($item['price'] ?? 0);
            $vat = (int)($item['vat_rate'] ?? 20);
            $vatUnit = round($price * $vat / 100, 2);
            $priceWvat = round($price + $vatUnit, 2);
            $sumNoVat = round($qty * $price, 2);
            $sumVat = round($qty * $vatUnit, 2);
            $sumVatAll = round($qty * $priceWvat, 2);
            $boxes = array_key_exists('boxes', $data) ? max(0, (int)$data['boxes']) : (int)($item['boxes'] ?? 0);
            $this->pdo->prepare(
                "UPDATE receipt_items SET qty=:qty, boxes=:boxes, vat_sum_unit=:vu, price_wvat=:pw,
                  sum_novat=:sn, vat_sum=:vs, sum_vat=:sv WHERE id=:id AND receipt_id=:rid"
            )->execute([
                ':qty' => $qty, ':boxes' => $boxes, ':vu' => $vatUnit, ':pw' => $priceWvat,
                ':sn' => $sumNoVat, ':vs' => $sumVat, ':sv' => $sumVatAll,
                ':id' => $itemId, ':rid' => $receiptId,
            ]);
            if (abs($qty - $oldQty) > 0.0001) {
                $this->history($receiptId, $userId, 'update', 'Количество', (string)$oldQty, (string)$qty);
            }
            $this->recalc($receiptId);
            $this->pdo->commit();
            $item['qty'] = $qty;
            $item['boxes'] = $boxes;
            $item['processed_qty'] = $processed;
            return $item;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Добавить строку в приходный документ. */
    public function addItem(int $receiptId, array $data, ?int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM receipts WHERE id=:id");
            $st->execute([':id' => $receiptId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Документ не найден');
            }
            $this->assertItemsEditable($row);

            $sku = trim((string)($data['sku'] ?? ''));
            $wmsCode = trim((string)($data['wms_code'] ?? $data['code'] ?? '')) ?: null;
            $barcode = trim((string)($data['barcode'] ?? '')) ?: null;
            $scanSerial = !empty($data['scan_serial']) ? 1 : 0;
            $scanChz = !empty($data['scan_chz']) ? 1 : 0;
            $ownerId = (int)($data['owner_contractor_id'] ?? 0) ?: null;
            $ownerName = trim((string)($data['owner_name'] ?? '')) ?: null;
            $name = trim((string)($data['name'] ?? ''));
            $unit = trim((string)($data['unit'] ?? '')) ?: 'шт';
            $qty = $this->number($data['qty'] ?? 0);
            $price = $this->number($data['price'] ?? 0);
            $vat = (int)($data['vat_rate'] ?? 20);
            $boxes = max(0, (int)($data['boxes'] ?? 0));
            if ($sku === '') {
                throw new RuntimeException('Укажите артикул');
            }
            if ($name === '') {
                throw new RuntimeException('Укажите наименование');
            }
            if ($qty <= 0) {
                throw new RuntimeException('Количество должно быть больше нуля');
            }
            if ($price < 0) {
                throw new RuntimeException('Цена не может быть отрицательной');
            }
            if ($vat < 0 || $vat > 100) {
                $vat = 20;
            }
            $vatUnit = round($price * $vat / 100, 2);
            $priceWvat = round($price + $vatUnit, 2);
            $sumNoVat = round($qty * $price, 2);
            $sumVat = round($qty * $vatUnit, 2);
            $sumVatAll = round($qty * $priceWvat, 2);

            $ln = $this->pdo->prepare("SELECT COALESCE(MAX(line_no),0)+1 FROM receipt_items WHERE receipt_id=:id");
            $ln->execute([':id' => $receiptId]);
            $lineNo = (int)$ln->fetchColumn();
            $prodDate = trim((string)($data['prod_date'] ?? ''));
            $prodDate = $prodDate !== '' ? $prodDate : null;

            $this->pdo->prepare(
                "INSERT INTO receipt_items
                  (receipt_id,line_no,code,barcode,scan_serial,scan_chz,owner_contractor_id,owner_name,sku,name,unit,qty,boxes,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat,supplier_batch,prod_date)
                 VALUES
                  (:rid,:ln,:code,:barcode,:scan_serial,:scan_chz,:owner_id,:owner_name,:sku,:name,:unit,:qty,:boxes,:price,:vat,:vu,:pw,:sn,:vs,:sv,:batch,:prod)"
            )->execute([
                ':rid' => $receiptId, ':ln' => $lineNo,
                ':code' => $wmsCode, ':barcode' => $barcode, ':scan_serial' => $scanSerial, ':scan_chz' => $scanChz,
                ':owner_id' => $ownerId, ':owner_name' => $ownerName,
                ':sku' => $sku, ':name' => $name, ':unit' => $unit,
                ':qty' => $qty, ':boxes' => $boxes, ':price' => $price, ':vat' => $vat,
                ':vu' => $vatUnit, ':pw' => $priceWvat, ':sn' => $sumNoVat,
                ':vs' => $sumVat, ':sv' => $sumVatAll,
                ':batch' => trim((string)($data['supplier_batch'] ?? '')) ?: null,
                ':prod' => $prodDate,
            ]);
            $itemId = (int)$this->pdo->lastInsertId();
            $this->recalc($receiptId);
            $this->history($receiptId, $userId, 'update', 'Товары', null, $sku . ' × ' . $qty);
            $this->pdo->commit();
            return [
                'id' => $itemId, 'line_no' => $lineNo, 'wms_code' => $wmsCode, 'barcode' => $barcode,
                'scan_serial' => $scanSerial, 'scan_chz' => $scanChz,
                'owner_contractor_id' => $ownerId, 'owner_name' => $ownerName,
                'sku' => $sku, 'name' => $name,
                'unit' => $unit, 'qty' => $qty, 'price' => $price, 'vat_rate' => $vat,
                'supplier_batch' => $data['supplier_batch'] ?? null,
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Удалить строку приходного документа. */
    public function deleteItem(int $receiptId, int $itemId, ?int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM receipts WHERE id=:id");
            $st->execute([':id' => $receiptId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Документ не найден');
            }
            $this->assertItemsEditable($row);
            $it = $this->pdo->prepare("SELECT * FROM receipt_items WHERE id=:id AND receipt_id=:rid");
            $it->execute([':id' => $itemId, ':rid' => $receiptId]);
            $item = $it->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                throw new RuntimeException('Строка не найдена');
            }
            $processed = $this->processedQty($receiptId, $item);
            if ($processed > 0) {
                throw new RuntimeException('Нельзя удалить строку: уже обработано ' . $processed);
            }
            $this->pdo->prepare("DELETE FROM receipt_items WHERE id=:id AND receipt_id=:rid")
                ->execute([':id' => $itemId, ':rid' => $receiptId]);
            $this->recalc($receiptId);
            $this->history($receiptId, $userId, 'update', 'Товары', (string)$item['sku'] . ' × ' . (string)$item['qty'], null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
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
            try {
                $this->pdo->exec("ALTER TABLE receipt_items ADD COLUMN supplier_batch VARCHAR(100) NULL");
            } catch (Throwable) {
            }
            $this->pdo->prepare(
                "INSERT INTO receipt_items (receipt_id,line_no,code,sku,name,unit,qty,boxes,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat,supplier_batch)
                 SELECT :nid,line_no,code,sku,name,unit,qty,boxes,price,vat_rate,vat_sum_unit,price_wvat,sum_novat,vat_sum,sum_vat,supplier_batch
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

    /**
     * Смена статуса по конвейеру переходов.
     * Для закрытия (Закрыто / Закрыто с отклонением) идёт через
     * wms_close_receipt_guarded(): гарды + акты расхождений + статус по факту.
     * @return array|null результат закрытия (акты, излишки, недостача) — если закрывали
     */
    public function changeStatus(int $id, ReceiptStatus $to, ?int $userId): ?array
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
            if ($posted) {
                require_once __DIR__ . '/../shared/ReceiptClose.php';
                if ($to === ReceiptStatus::ClosedShort) {
                    // Кнопка «Закрыть с недостачей»: закрываем принудительно,
                    // даже если недостача ещё не была зафиксирована отдельным актом.
                    $closed = wms_close_receipt($this->pdo, $id, $userId, 'Реестр поступлений', 'closed_short');
                } else {
                    // Обычное закрытие — через гарды: неподтверждённый излишек
                    // и незафиксированная недостача не дают закрыть документ.
                    $closed = wms_close_receipt_guarded($this->pdo, $id, $userId, 'Реестр поступлений');
                }
                $this->pdo->prepare("UPDATE receipts SET is_posted=1 WHERE id=:id")->execute([':id' => $id]);
                $this->pdo->commit();
                return $closed;
            }
            $this->pdo->prepare("UPDATE receipts SET status=:s, is_posted=:p WHERE id=:id")
                ->execute([':s' => $to->value, ':p' => $posted, ':id' => $id]);
            $this->history($id, $userId, 'status', 'Статус', $from->label(), $to->label(), $from->value, $to->value);
            $this->pdo->commit();
            return null;
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

    // Кнопка "Изменить" в карточке: дата поставки (план) + статус через конвейер переходов.
    // ПКМ "Указать атрибуты": дата прибытия (факт), статус, ТС, ворота.
    // Сначала статус (при запрещенном переходе ничего не пишется), затем остальные поля.
    // Возвращает новый статус.
    public function updateHead(int $id, array $b, ?int $userId): string
    {
        $st = $this->pdo->prepare("SELECT * FROM receipts WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Документ не найден');
        }
        if (array_key_exists('status', $b) && $b['status'] !== null && $b['status'] !== ''
            && (string)$b['status'] !== (string)($row['status'] ?? '')) {
            try {
                $to = ReceiptStatus::from((string)$b['status']);
            } catch (Throwable) {
                throw new RuntimeException('Недопустимый статус');
            }
            $closed = $this->changeStatus($id, $to, $userId);
            // При закрытии статус выводится из факта расхождений
            // («Закрыто» / «Закрыто с отклонением»), а не из того, что выбрал оператор.
            $row['status'] = is_array($closed) && isset($closed['status'])
                ? (string)$closed['status'] : $to->value;
        }
        if (array_key_exists('plan_delivery_date', $b)) {
            $nv = $this->normPlanDate($b['plan_delivery_date']);
            $ov = $row['plan_delivery_date'] ?? null;
            if ((string)$nv !== (string)$ov) {
                $this->pdo->prepare("UPDATE receipts SET plan_delivery_date=:d WHERE id=:id")
                    ->execute([':d' => $nv, ':id' => $id]);
                $this->history($id, $userId, 'update', 'Дата поставки, план',
                    $ov === null ? null : (string)$ov, $nv === null ? null : (string)$nv);
            }
        }
        if (array_key_exists('arrival_date', $b)) {
            $nv = $this->normDateTime($b['arrival_date'], 'Неверная дата прибытия (нужен формат ГГГГ-ММ-ДД)');
            $ov = $row['arrival_date'] ?? null;
            if ((string)$nv !== (string)$ov) {
                $this->pdo->prepare("UPDATE receipts SET arrival_date=:d WHERE id=:id")
                    ->execute([':d' => $nv, ':id' => $id]);
                $this->history($id, $userId, 'update', 'Дата прибытия',
                    $ov === null ? null : (string)$ov, $nv === null ? null : (string)$nv);
            }
        }
        if (array_key_exists('vehicle', $b)) {
            $nv = trim((string)($b['vehicle'] ?? ''));
            if (function_exists('mb_strlen') ? mb_strlen($nv, 'UTF-8') > 30 : strlen($nv) > 30) {
                throw new RuntimeException('Номер ТС слишком длинный (макс. 30 символов)');
            }
            $nv = $nv === '' ? null : $nv;
            $ov = $row['vehicle_fact'] ?? null;
            if ((string)$nv !== (string)$ov) {
                $this->pdo->prepare("UPDATE receipts SET vehicle_fact=:v, vehicle=:v WHERE id=:id")
                    ->execute([':v' => $nv, ':id' => $id]);
                $this->history($id, $userId, 'update', 'ТС',
                    $ov === null ? null : (string)$ov, $nv === null ? null : (string)$nv);
            }
        }
        if (array_key_exists('gate', $b)) {
            $nv = trim((string)($b['gate'] ?? ''));
            if (function_exists('mb_strlen') ? mb_strlen($nv, 'UTF-8') > 20 : strlen($nv) > 20) {
                throw new RuntimeException('Номер ворот слишком длинный (макс. 20 символов)');
            }
            $nv = $nv === '' ? null : $nv;
            $ov = $row['gate'] ?? null;
            if ((string)$nv !== (string)$ov) {
                $this->pdo->prepare("UPDATE receipts SET gate=:g WHERE id=:id")
                    ->execute([':g' => $nv, ':id' => $id]);
                $this->history($id, $userId, 'update', 'Ворота',
                    $ov === null ? null : (string)$ov, $nv === null ? null : (string)$nv);
            }
        }
        return (string)($row['status'] ?? '');
    }

    private function normDateTime(mixed $v, string $err): ?string
    {
        if ($v === null || trim((string)$v) === '') {
            return null;
        }
        $s = trim((string)$v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new RuntimeException($err);
            }
            return $s . ' 00:00:00';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])
                || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59) {
                throw new RuntimeException($err);
            }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], (int)($m[6] ?? 0));
        }
        throw new RuntimeException($err);
    }

    private function normPlanDate(mixed $v): ?string
    {
        if ($v === null || trim((string)$v) === '') {
            return null;
        }
        $s = trim((string)$v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                throw new RuntimeException('Неверная дата поставки (нужен формат ГГГГ-ММ-ДД)');
            }
            return $s . ' 00:00:00';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) {
            if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])
                || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59) {
                throw new RuntimeException('Неверная дата поставки');
            }
            return sprintf('%04d-%02d-%02d %02d:%02d:%02d',
                (int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4], (int)$m[5], (int)($m[6] ?? 0));
        }
        throw new RuntimeException('Неверный формат даты поставки (нужен ГГГГ-ММ-ДД)');
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

    public function printData(int $id, string $kind, string $type = ''): array
    {
        if ($kind === 'act_rasx') {
            return $this->actDoc($id, $type === '' ? 'all' : $type);
        }
        if ($kind === 'receiver_doc') {
            return $this->receiverDoc($id);
        }
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

    public const ACT_TYPES = [
        'излишек' => 'Акт об излишке',
        'недостача' => 'Акт о недостаче',
        'брак' => 'Акт о браке',
        'не_заявлен' => 'Акт о не заявленном товаре',
    ];

    /** Какие акты расхождений есть по документу: [{type, title, count, qty}]. */
    public function actTypes(int $id): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT type, COUNT(*) c, COALESCE(SUM(qty_diff), 0) q FROM tsd_act_rasx WHERE receipt_id = :id GROUP BY type ORDER BY type"
            );
            $st->execute([':id' => $id]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['type' => $r['type'], 'title' => self::ACT_TYPES[$r['type']] ?? ('Акт: ' . $r['type']),
                    'count' => (int)$r['c'], 'qty' => (float)$r['q']];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Акт расхождений: один тип или 'all' (сводный — всё в одном документе).
     * Строки: штрихкод, артикул, наименование, количество, тип, дата.
     */
    public function actDoc(int $id, string $type): array
    {
        $st = $this->pdo->prepare(
            "SELECT r.*, sup.name AS supplier_name, w.name AS warehouse_name FROM receipts r
             LEFT JOIN contractors sup ON sup.id = r.supplier_id
             LEFT JOIN warehouses w ON w.id = r.warehouse_id WHERE r.id = :id"
        );
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        $where = '';
        $p = [':id' => $id];
        if ($type !== '' && $type !== 'all') {
            if (!isset(self::ACT_TYPES[$type])) {
                throw new RuntimeException('Неизвестный тип акта');
            }
            $where = ' AND a.type = :t';
            $p[':t'] = $type;
        }
        try {
            $rows = $this->pdo->prepare(
                "SELECT a.sku, a.type, a.qty_diff, a.created_at,
                        COALESCE(a.supplier_batch,'') AS supplier_batch,
                        (SELECT COALESCE(NULLIF(i.barcode,''), i.code) FROM receipt_items i WHERE i.receipt_id = a.receipt_id AND i.sku = a.sku LIMIT 1) AS barcode,
                        (SELECT i.name FROM receipt_items i WHERE i.receipt_id = a.receipt_id AND i.sku = a.sku LIMIT 1) AS name
                 FROM tsd_act_rasx a WHERE a.receipt_id = :id$where ORDER BY a.id"
            );
            $rows->execute($p);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Старая схема без партии в акте.
            try {
                $rows = $this->pdo->prepare(
                    "SELECT a.sku, a.type, a.qty_diff, a.created_at, '' AS supplier_batch,
                            (SELECT COALESCE(NULLIF(i.barcode,''), i.code) FROM receipt_items i WHERE i.receipt_id = a.receipt_id AND i.sku = a.sku LIMIT 1) AS barcode,
                            (SELECT i.name FROM receipt_items i WHERE i.receipt_id = a.receipt_id AND i.sku = a.sku LIMIT 1) AS name
                     FROM tsd_act_rasx a WHERE a.receipt_id = :id$where ORDER BY a.id"
                );
                $rows->execute($p);
                $items = $rows->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                $items = [];
            }
        }
        $title = ($type === '' || $type === 'all') ? 'Сводный акт о расхождениях' : self::ACT_TYPES[$type];
        return ['kind' => 'act_rasx', 'type' => $type === '' ? 'all' : $type, 'title' => $title,
            'head' => $head, 'rows' => $items];
    }

    /**
     * Документ приемщика: штрихкод товара, поставщик, артикул, количество (план/факт).
     */
    public function receiverDoc(int $id): array
    {
        $st = $this->pdo->prepare(
            "SELECT r.*, sup.name AS supplier_name, w.name AS warehouse_name FROM receipts r
             LEFT JOIN contractors sup ON sup.id = r.supplier_id
             LEFT JOIN warehouses w ON w.id = r.warehouse_id WHERE r.id = :id"
        );
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        try {
            // Факт — по паре (SKU, партия) и только ПРИНЯТОЕ.
            // Раньше корреляция была лишь по SKU: при повторе артикула в двух
            // строках (разные партии) обе строки показывали общий факт,
            // и сумма в «Документе приемщика» была завышена в N раз.
            $rows = $this->pdo->prepare(
                "SELECT i.line_no, COALESCE(NULLIF(i.barcode,''), i.code) AS barcode, i.sku, i.name, i.qty AS plan_qty, i.supplier_batch,
                        COALESCE((SELECT SUM(o.qty) FROM receipt_operations o
                                  WHERE o.receipt_id = i.receipt_id AND o.sku = i.sku
                                    AND o.accept_status = 'Принято'
                                    AND COALESCE(o.supplier_batch,'') = COALESCE(i.supplier_batch,'')), 0) AS fact_qty
                 FROM receipt_items i WHERE i.receipt_id = :id ORDER BY i.line_no, i.id"
            );
        } catch (Throwable) {
            // Старая схема без supplier_batch — считаем по SKU.
            $rows = $this->pdo->prepare(
                "SELECT i.line_no, COALESCE(NULLIF(i.barcode,''), i.code) AS barcode, i.sku, i.name, i.qty AS plan_qty,
                        COALESCE((SELECT SUM(o.qty) FROM receipt_operations o
                                  WHERE o.receipt_id = i.receipt_id AND o.sku = i.sku
                                    AND o.accept_status = 'Принято'), 0) AS fact_qty
                 FROM receipt_items i WHERE i.receipt_id = :id ORDER BY i.line_no, i.id"
            );
        }
        $rows->execute([':id' => $id]);
        return ['kind' => 'receiver_doc', 'title' => 'Документ приемщика',
            'head' => $head, 'rows' => $rows->fetchAll(PDO::FETCH_ASSOC)];
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
