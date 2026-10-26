<?php
declare(strict_types=1);

require_once __DIR__ . '/ShipmentStatus.php';
require_once __DIR__ . '/../shared/DocNo.php';

// Бизнес-логика документа: нумерация, пересчет сумм, ПКМ-действия.
// Вынесена из контроллера, чтобы расти: сюда подключаются очереди,
// резервирование остатков, печать УПД/ТТН, отправка Email/EDI.

final class ShipmentService
{
    public function __construct(private PDO $pdo) {}

    // Следующий номер отгрузки: S-205101, S-205102, ... (см. shared/DocNo.php).
    public function nextDocNo(): string
    {
        return wms_next_shipment_no($this->pdo);
    }

    /**
     * Создать черновик отгрузки из набора полей.
     * Общая точка для «+ Создать» и для массового импорта из Excel:
     * раньше импорт звал store(), который сам печатал JSON, — цикл выдавал
     * N склеенных JSON-документов, а счётчик created рос даже при ошибке строки.
     * @return array{id:int,doc_no:string}
     */
    public function createShipment(array $body, ?int $userId): array
    {
        if (empty($body['contractor_id']) || empty($body['warehouse_id'])) {
            throw new RuntimeException('contractor_id и warehouse_id обязательны');
        }
        // Номер — ДО транзакции: на MySQL LOCK TABLES неявно коммитит транзакцию.
        $no = $this->nextDocNo();
        $now = wms_is_sqlite($this->pdo) ? "datetime('now')" : 'NOW()';
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                "INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,contract_no,warehouse_id,
                 status,vat_rate,plan_shipment_date,plan_delivery_date,delivery_type,delivery_method,
                 responsible_id,comment,created_by,consignee,gate,vehicle,loading_type,priority,cargo_places)
                 VALUES (:no,$now,:ord,:ctr,:cn,:wh,'draft',:vat,:psd,:pdd,:dt,:dm,:resp,:cmt,:cb,
                 :csg,:gate,:veh,:lt,:pri,:cp)"
            );
            $st->execute([
                ':no' => $no, ':ord' => $body['order_id'] ?? null, ':ctr' => (int)$body['contractor_id'],
                ':cn' => $body['contract_no'] ?? null, ':wh' => (int)$body['warehouse_id'],
                ':vat' => (int)($body['vat_rate'] ?? 20), ':psd' => $body['plan_shipment_date'] ?? null,
                ':pdd' => $body['plan_delivery_date'] ?? null, ':dt' => $body['delivery_type'] ?? null,
                ':dm' => $body['delivery_method'] ?? null, ':resp' => $body['responsible_id'] ?? $userId,
                ':cmt' => $body['comment'] ?? null, ':cb' => $userId,
                ':csg' => $body['consignee'] ?? null, ':gate' => $body['gate'] ?? null,
                ':veh' => $body['vehicle'] ?? null, ':lt' => $body['loading_type'] ?? null,
                ':pri' => $body['priority'] ?? 'normal', ':cp' => (int)($body['cargo_places'] ?? 0),
            ]);
            $id = (int)$this->pdo->lastInsertId();
            $this->recalc($id);
            $this->pdo->commit();
            return ['id' => $id, 'doc_no' => $no];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Пересчет шапки из позиций: сумма, НДС 20%, итого, долг, SKU, штуки. Вызывать в транзакции.
    public function recalc(int $shipmentId): void
    {
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) c, COALESCE(SUM(qty*price),0) s, COALESCE(SUM(qty),0) q,
                    COUNT(DISTINCT sku) sk FROM shipment_items WHERE shipment_id=:id"
        );
        $st->execute([':id' => $shipmentId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $total = round((float)$r['s'], 2);
        $count = (int)$r['c'];

        $h = $this->pdo->prepare("SELECT vat_rate, paid FROM shipments WHERE id=:id");
        $h->execute([':id' => $shipmentId]);
        $head = $h->fetch(PDO::FETCH_ASSOC);
        $vatRate = (int)($head['vat_rate'] ?? 20);
        $paid = (float)($head['paid'] ?? 0);

        // В макете сумма уже включает НДС: выделяем НДС = total*rate/(100+rate).
        $vatSum = round($total * $vatRate / (100 + $vatRate), 2);
        $debt = round($total - $paid, 2);

        $u = $this->pdo->prepare(
            "UPDATE shipments SET total=:t, vat_sum=:v, total_with_vat=:t,
             items_count=:c, sku_count=:sk, qty_total=:q, debt=:d WHERE id=:id"
        );
        $u->execute([':t' => $total, ':v' => $vatSum, ':c' => $count,
            ':sk' => (int)($r['sk'] ?? 0), ':q' => (float)($r['q'] ?? 0),
            ':d' => $debt, ':id' => $shipmentId]);
    }

    // Флаги документа (галки в карточке): is_crossdock — признак кроссдока.
    // Флаг ставится на уровне документа отгрузки: ТСД по нему выбирает
    // ветку алгоритма сортировки (сканирование паллет вместо построчного скана).
    public function setFlag(int $id, string $field, int $value, ?int $userId): void
    {
        $allowed = [
            'is_crossdock' => 'Кросдок',
            'serial_scan' => 'Сканирование серийных номеров',
            'marking_scan' => 'Сканирование маркировки ЧЗ',
            'need_check' => 'Требуется проверка',
            'need_pack' => 'Требуется упаковка',
            'is_danger' => 'Опасный груз',
        ];
        if (!isset($allowed[$field])) {
            throw new RuntimeException('Неизвестный флаг: ' . $field);
        }
        $st = $this->pdo->prepare("SELECT $field AS v FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            throw new RuntimeException('Отгрузка не найдена');
        }
        $old = (int)$cur;
        $new = $value ? 1 : 0;
        try {
            $this->pdo->prepare("UPDATE shipments SET $field=:v WHERE id=:id")
                ->execute([':v' => $new, ':id' => $id]);
        } catch (Throwable $e) {
            throw new RuntimeException('Поле ' . $field . ' отсутствует в БД — примените schema.sql');
        }
        if ($old !== $new) {
            $this->historyField($id, $userId, 'update', $allowed[$field], $old ? 'да' : 'нет', $new ? 'да' : 'нет');
        }
    }

    private function history(int $id, ?int $userId, string $action, ?string $old, ?string $new): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (?,?,?,?,?)"
        );
        $st->execute([$id, $userId, $action, $old, $new]);
    }

    /** История с полем/значением (для флагов документа). */
    private function historyField(int $id, ?int $userId, string $action, string $field, ?string $old, ?string $new): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value) VALUES (?,?,?,?,?,?)"
        );
        $st->execute([$id, $userId, $action, $field, $old, $new]);
    }

    // Задания на отбор, блокирующие удаление/расформирование документа.
    // @return string[] номера заданий
    private function pickTasksBlocking(int $shipmentId, string $docNo): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        $blocking = [];
        foreach ($taskPdos as $tPdo) {
            foreach (wms_find_pick_tasks($tPdo, [$shipmentId], [$docNo]) as $t) {
                $blocking[$t['doc_no']] = true;
            }
        }
        return array_keys($blocking);
    }

    // Полное удаление документа отгрузки со связками и строками (наряды не трогает).
    private function deleteShipmentRows(int $id): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach (['shipment_items', 'shipment_orders', 'shipment_history',
                      'shipment_documents', 'shipment_flags', 'tsd_picks',
                      // Марки ЧЗ и журнал сортировки тоже принадлежат отгрузке:
                      // без их удаления марки «висят» в реестре и блокируют
                      // загрузку этой же марки в другую отгрузку, а журнал
                      // сортировки даёт ложные «уже отсканировано».
                      'shipment_marks', 'shipment_sort_log'] as $tbl) {
                try {
                    $this->pdo->prepare("DELETE FROM $tbl WHERE shipment_id=:id")->execute([':id' => $id]);
                } catch (Throwable) {
                    // Нет таблицы/колонки в старой БД — пропускаем.
                }
            }
            $this->pdo->prepare('DELETE FROM shipments WHERE id=:id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // Вкладка "Наряды", ПКМ: убрать заказы из отгрузки.
    // Удаляет связку (shipment_orders) и строки состава этого заказа (shipment_items.order_id),
    // затем пересчитывает шапку. Сам наряд (client_orders) не удаляется.
    // Отгрузка без нарядов существовать не может: если убран последний наряд,
    // документ удаляется автоматически (при живых заданиях на отбор — отказ,
    // сначала ПКМ → «Снять резерв»).
    public function unlinkOrders(int $shipmentId, array $orderIds, ?int $userId): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        $orderIds = array_values(array_filter($orderIds, fn($v) => $v > 0));
        if (!$orderIds) {
            throw new RuntimeException('Выбери наряды (галка или ПКМ)');
        }
        $st = $this->pdo->prepare('SELECT doc_no FROM shipments WHERE id=:id');
        $st->execute([':id' => $shipmentId]);
        $shipDoc = $st->fetchColumn();
        if (!$shipDoc) {
            throw new RuntimeException('Документ не найден');
        }
        $shipDoc = (string)$shipDoc;
        // Снимок связей: поймём заранее, опустеет ли отгрузка после операции.
        $linkedBefore = null;
        try {
            $q = $this->pdo->prepare('SELECT order_id FROM shipment_orders WHERE shipment_id=:s');
            $q->execute([':s' => $shipmentId]);
            $linkedBefore = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
        }
        if (is_array($linkedBefore) && !array_diff($linkedBefore, $orderIds)) {
            $blocking = $this->pickTasksBlocking($shipmentId, $shipDoc);
            if ($blocking) {
                throw new RuntimeException(
                    'Это последний наряд, но по отгрузке есть задания на отбор (' .
                    implode(', ', $blocking) . ') — сначала ПКМ → «Снять резерв»'
                );
            }
        }
        $unlinked = [];
        $doneIds = [];
        $errors = [];
        foreach ($orderIds as $oid) {
            try {
                $doc = null;
                try {
                    $q = $this->pdo->prepare('SELECT doc_no FROM client_orders WHERE id=:id');
                    $q->execute([':id' => $oid]);
                    $doc = $q->fetchColumn() ?: null;
                } catch (Throwable) {
                }
                $label = $doc !== null && $doc !== '' ? (string)$doc : ('#' . $oid);
                try {
                    $chk = $this->pdo->prepare(
                        'SELECT 1 FROM shipment_orders WHERE shipment_id=:s AND order_id=:o LIMIT 1'
                    );
                    $chk->execute([':s' => $shipmentId, ':o' => $oid]);
                    $linked = (bool)$chk->fetchColumn();
                } catch (Throwable) {
                    throw new RuntimeException($label . ': нет связи с отгрузкой');
                }
                if (!$linked) {
                    throw new RuntimeException($label . ': не связан с отгрузкой');
                }
                $this->pdo->beginTransaction();
                try {
                    $this->pdo->prepare(
                        'DELETE FROM shipment_orders WHERE shipment_id=:s AND order_id=:o'
                    )->execute([':s' => $shipmentId, ':o' => $oid]);
                    try {
                        $this->pdo->prepare(
                            'DELETE FROM shipment_items WHERE shipment_id=:s AND order_id=:o'
                        )->execute([':s' => $shipmentId, ':o' => $oid]);
                    } catch (Throwable) {
                    }
                    $this->recalc($shipmentId);
                    $this->history($shipmentId, $userId, 'unlink', $label, null);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }
                $unlinked[] = $label;
                $doneIds[] = $oid;
            } catch (Throwable $e) {
                $errors[] = '#' . $oid . ': ' . $e->getMessage();
            }
        }
        if (!$unlinked) {
            throw new RuntimeException(implode('; ', $errors) ?: 'Нечего убирать');
        }
        // Отгрузка без нарядов существовать не может — удаляем документ автоматически.
        $shipmentDeleted = false;
        if (is_array($linkedBefore) && !array_diff($linkedBefore, $doneIds)) {
            $this->deleteShipmentRows($shipmentId);
            $shipmentDeleted = true;
        }
        return ['unlinked' => $unlinked, 'errors' => $errors,
                'shipment_deleted' => $shipmentDeleted, 'shipment_doc' => $shipDoc];
    }

    // ПКМ: Копировать — новый черновик с теми же позициями и нарядами.
    public function copy(int $id, ?int $userId): int
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id FOR UPDATE");
            $st->execute([':id' => $id]);
            $src = $st->fetch(PDO::FETCH_ASSOC);
            if (!$src) {
                throw new RuntimeException('Документ не найден');
            }
            $newNo = $this->nextDocNo();
            // NOW() не понимает SQLite — используем переносимое выражение.
            $now = wms_is_sqlite($this->pdo) ? "datetime('now')" : 'NOW()';
            $ins = $this->pdo->prepare(
                "INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,contract_no,warehouse_id,
                 status,vat_rate,paid,plan_shipment_date,plan_delivery_date,delivery_type,delivery_method,
                 responsible_id,comment,created_by,consignee,gate,vehicle,loading_type,priority,cargo_places,volume,weight)
                 VALUES (:no,$now,:ord,:ctr,:cn,:wh,'draft',:vat,0,:psd,:pdd,:dt,:dm,
                 :resp,:cmt,:cb,:csg,:gate,:veh,:lt,:pri,:cp,:vol,:wgt)"
            );
            $ins->execute([
                ':no' => $newNo, ':ord' => $src['order_id'] ?? null, ':ctr' => $src['contractor_id'],
                ':cn' => $src['contract_no'] ?? null, ':wh' => $src['warehouse_id'], ':vat' => $src['vat_rate'],
                ':psd' => $src['plan_shipment_date'] ?? null, ':pdd' => $src['plan_delivery_date'] ?? null,
                ':dt' => $src['delivery_type'] ?? null, ':dm' => $src['delivery_method'] ?? null,
                ':resp' => $userId, ':cmt' => $src['comment'] ?? null, ':cb' => $userId,
                ':csg' => $src['consignee'] ?? null, ':gate' => $src['gate'] ?? null,
                ':veh' => $src['vehicle'] ?? null, ':lt' => $src['loading_type'] ?? null,
                ':pri' => $src['priority'] ?? 'normal', ':cp' => $src['cargo_places'] ?? 0,
                ':vol' => $src['volume'] ?? 0, ':wgt' => $src['weight'] ?? 0,
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            // Позиции: копируем ВСЕ поля строки (код, единица, наряд, этикетка,
            // дата производства) — раньше терялись code/unit/order_id/check_label.
            $itemCols = ['code', 'order_id', 'sku', 'name', 'unit', 'qty', 'price', 'check_label',
                'warehouse_id', 'comment', 'prod_date'];
            $has = wms_table_columns($this->pdo, 'shipment_items');
            $cols = [];
            foreach ($itemCols as $c) {
                if (in_array($c, $has, true)) {
                    $cols[] = $c;
                }
            }
            $sumCol = wms_quote_ident($this->pdo, 'sum');
            $sel = [];
            foreach ($cols as $c) {
                $sel[] = ($c === 'prod_date') ? 'prod_date' : $c;
            }
            $this->pdo->prepare(
                "INSERT INTO shipment_items (shipment_id," . implode(',', $cols) . "," . $sumCol . ")
                 SELECT :nid," . implode(',', $sel) . ", qty*price FROM shipment_items WHERE shipment_id=:oid"
            )->execute([':nid' => $newId, ':oid' => $id]);
            // Наряды (shipment_orders): без них отгрузка «без нарядов»,
            // а модуль сам считает такой документ некорректным.
            try {
                $this->pdo->prepare(
                    "INSERT INTO shipment_orders (shipment_id,order_id,status,qty_total,collected,checked,packed,shipped,sum_novat,vat,sum_vat)
                     SELECT :nid,order_id,status,qty_total,0,0,0,0,sum_novat,vat,sum_vat
                     FROM shipment_orders WHERE shipment_id=:oid"
                )->execute([':nid' => $newId, ':oid' => $id]);
            } catch (Throwable) {
            }
            $this->recalc($newId);
            $this->history($newId, $userId, 'create', null, 'draft');
            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ПКМ: Провести / смена статуса по автомату ShipmentStatus::canTransitTo.
    public function changeStatus(int $id, ShipmentStatus $to, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT status, doc_no FROM shipments WHERE id=:id FOR UPDATE");
        $this->pdo->beginTransaction();
        try {
            $st->execute([':id' => $id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Документ не найден');
            }
            $from = ShipmentStatus::from($row['status']);
            if (!$from->canTransitTo($to)) {
                throw new RuntimeException("Нельзя перевести {$from->label()} → {$to->label()}");
            }
            $posted = in_array($to, [ShipmentStatus::Assembled, ShipmentStatus::Delivering, ShipmentStatus::Delivered], true) ? 1 : 0;
            $extra = '';
            $params = [':s' => $to->value, ':p' => $posted, ':id' => $id];
            if ($to === ShipmentStatus::Delivered) {
                $extra = ', fact_shipment_date = COALESCE(fact_shipment_date, CURDATE()), fact_delivery_date = COALESCE(fact_delivery_date, CURDATE())';
            }
            $this->pdo->prepare("UPDATE shipments SET status=:s, is_posted=:p $extra WHERE id=:id")->execute($params);
            $this->history($id, $userId, 'status', $from->value, $to->value);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        // Журнал склада (модуль 14): отгрузка — паллеты ушли со склада.
        if ($to === ShipmentStatus::Delivering) {
            $this->journalShipment($id, (string)($row['doc_no'] ?? ''), $userId);
        }
    }

    /**
     * Журнал склада (модуль 14): отгрузка — состав паллет, ушедших со склада.
     * Источник факта — сортировка ТСД (tsd_picks): паллета = SSCC.
     */
    private function journalShipment(int $id, string $docNo, ?int $userId): void
    {
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            $picks = $this->pdo->prepare(
                "SELECT sku, sscc, SUM(qty) AS q FROM tsd_picks WHERE shipment_id=:s GROUP BY sku, sscc"
            );
            $picks->execute([':s' => $id]);
            $rows = $picks->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                return;
            }
            $names = [];
            try {
                $it = $this->pdo->prepare("SELECT sku, MAX(name) AS name FROM shipment_items WHERE shipment_id=:s GROUP BY sku");
                $it->execute([':s' => $id]);
                foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $names[(string)($r['sku'] ?? '')] = (string)($r['name'] ?? '');
                }
            } catch (Throwable) {
            }
            foreach ($rows as $r) {
                $sku = (string)($r['sku'] ?? '');
                WmsJournal::log(wms_journal_pdo(), [
                    'op_type' => 'отгрузка',
                    'doc_type' => 'Отгрузка',
                    'doc_no' => $docNo,
                    'sku' => $sku,
                    'product_name' => $names[$sku] ?? '',
                    'qty' => (float)($r['q'] ?? 0),
                    'pallet' => (string)($r['sscc'] ?? ''),
                    'sscc' => (string)($r['sscc'] ?? ''),
                    'zone' => 'Отгрузка',
                    'user_id' => $userId,
                    'comment' => 'Отгрузка со склада',
                ]);
            }
        } catch (Throwable) {
        }
    }

    // ПКМ: Удалить (только черновик/отменена) / Архивировать (только доставлена/отменена).
    public function delete(int $id, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT doc_no, status FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Документ не найден');
        }
        if (!in_array($row['status'], ['draft', 'cancelled'], true)) {
            throw new RuntimeException('Удалять можно только черновик или отмененную');
        }
        $docNo = (string)($row['doc_no'] ?? '');
        // Связь с заданиями на отбор: удалять отгрузку с живыми заданиями нельзя —
        // иначе в ТСД остаются задания по несуществующей отгрузке.
        $blocking = $this->pickTasksBlocking($id, $docNo);
        if ($blocking) {
            throw new RuntimeException(
                'По отгрузке есть задания на отбор (' . implode(', ', $blocking) .
                ') — сначала ПКМ → «Снять резерв»'
            );
        }
        $this->dropPickLists($id, $docNo);
        $this->deleteShipmentRows($id);
    }

    // Списки отбора (PK-…) отгрузки — сносятся вместе с отгрузкой.
    private function dropPickLists(int $shipmentId, string $docNo): void
    {
        require_once __DIR__ . '/../shared/PickLists.php';
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        foreach ($taskPdos as $tPdo) {
            try {
                $st = $tPdo->prepare('SELECT id FROM task_lists WHERE shipment_id = :s');
                $st->execute([':s' => $shipmentId]);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                if ($docNo !== '') {
                    $st = $tPdo->prepare('SELECT id FROM task_lists WHERE shipment_doc_no = :d');
                    $st->execute([':d' => $docNo]);
                    $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
                }
                foreach (array_unique($ids) as $lid) {
                    wms_pick_list_drop($tPdo, (int)$lid);
                }
            } catch (Throwable) {
            }
        }
    }

    // ПКМ: Расформировать отгрузку — код отгрузки удаляется, наряды остаются.
    // Удаляет документ, связки, строки состава и историю; сами заказы не трогает.
    // Если по отгрузке выпущены задания на отбор — сначала требуем "Снять резерв".
    public function disband(int $id, ?int $userId): array
    {
        $st = $this->pdo->prepare('SELECT doc_no FROM shipments WHERE id=:id');
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        $docNo = (string)$head['doc_no'];
        $blocking = $this->pickTasksBlocking($id, $docNo);
        if ($blocking) {
            throw new RuntimeException(
                'По отгрузке есть задания на отбор (' . implode(', ', $blocking) .
                ') — сначала ПКМ → «Снять резерв»'
            );
        }
        $this->dropPickLists($id, $docNo);
        $this->deleteShipmentRows($id);
        return ['doc_no' => $docNo];
    }

    public function archive(int $id, ?int $userId): void
    {
        $this->changeStatus($id, ShipmentStatus::Archived, $userId);
    }

    // ПКМ: Приоритет > Повысить / Понизить
    public function priority(int $id, string $priority, ?int $userId): void
    {
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            throw new RuntimeException('Неверный приоритет');
        }
        try {
            $this->pdo->prepare("UPDATE shipments SET priority=:p WHERE id=:id")
                ->execute([':p' => $priority, ':id' => $id]);
            $this->history($id, $userId, 'update', null, null);
        } catch (Throwable $e) {
            throw new RuntimeException('Поле priority отсутствует в БД — примените schema.sql');
        }
    }

    // ПКМ: Выпуск заданий на отбор (волной / построчно).
    // Волна: один SKU = одно объединённое задание; ворота у отгрузок должны совпадать.
    // Построчно: каждая строка shipment_items = отдельное задание.
    public function issuePickTasks(array $shipmentIds, string $mode, ?int $userId): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        // Прод MySQL: задания и отгрузки в одной БД `wms` — пишем в тот же PDO.
        // Демо SQLite: tasks живут отдельно — подхватываем оба файла заданий.
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        return wms_issue_pick_tasks($this->pdo, $taskPdos, $shipmentIds, $mode, $userId);
    }

    // ПКМ: Снять резерв — удаляет ВСЕ задания на отбор, созданные по документам.
    public function releasePickTasks(array $shipmentIds, ?int $userId): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    $taskPdos = $extra;
                }
            }
        } catch (Throwable) {
        }
        return wms_release_pick_tasks($this->pdo, $taskPdos, $shipmentIds, $userId);
    }

    // Вкладка "Задания" карточки: задания на отбор, созданные по отгрузке.
    public function pickTasksForShipment(int $shipmentId): array
    {
        require_once __DIR__ . '/../shared/PickIssue.php';
        $st = $this->pdo->prepare('SELECT id, doc_no FROM shipments WHERE id=:id');
        $st->execute([':id' => $shipmentId]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        $taskPdos = [$this->pdo];
        try {
            if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                require_once __DIR__ . '/../shared/db.php';
                $extra = wms_tasks_pdos();
                if ($extra) {
                    // Детализацию берём из primary, чтобы не дублировать.
                    return wms_pick_tasks_detail($extra[0], [$shipmentId], [(string)$head['doc_no']]);
                }
            }
        } catch (Throwable) {
        }
        return wms_pick_tasks_detail($taskPdos[0], [$shipmentId], [(string)$head['doc_no']]);
    }

    // ПКМ: Запустить сборку / Подготовить автоматически (Волна)
    public function startPicking(int $id, ?int $userId, string $mode = 'wave'): void
    {
        $st = $this->pdo->prepare("SELECT status FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Документ не найден');
        }
        try {
            $this->pdo->prepare(
                "UPDATE shipments SET launched_pick=:m, assembly_start=COALESCE(assembly_start, CURRENT_TIMESTAMP) WHERE id=:id"
            )->execute([':m' => $mode === 'auto' ? 'Авто' : 'Волна', ':id' => $id]);
        } catch (Throwable $e) {
            // старые БД без колонок — только история
        }
        $this->history($id, $userId, 'picking', $row['status'], $row['status']);
    }

    /**
     * Мастер "Формирование отгрузки", шаг 2: создать документ из N заказов.
     * $params: contractor_id, warehouse_id, plan_shipment_date, plan_time,
     *          vehicle, gate, loading_type, delivery_type, priority, consignee...
     * Возвращает id новой отгрузки. Состав и итоги считаются из заказов.
     */
    // Переносимый номер для SQLite-демо (без LOCK TABLES) — та же серия S-205101...
    private function nextDocNoPortable(): string
    {
        return wms_next_shipment_no($this->pdo);
    }

    public function formFromOrders(array $orderIds, array $params, ?int $userId): int
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) {
            throw new RuntimeException('Выберите заказы (шаг 1 мастера)');
        }
        if (empty($params['warehouse_id'])) {
            throw new RuntimeException('Укажите склад (шаг 2 мастера)');
        }
        // Один наряд — только в одну отгрузку: уже связанные заказы отклоняем.
        try {
            $in = implode(',', $orderIds);
            $busy = $this->pdo->query(
                "SELECT o.doc_no AS order_no, s.doc_no AS ship_no
                 FROM shipment_orders so
                 JOIN shipments s ON s.id = so.shipment_id
                 JOIN client_orders o ON o.id = so.order_id
                 WHERE so.order_id IN ($in)"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($busy) {
                $names = [];
                foreach ($busy as $b) {
                    $names[] = $b['order_no'] . ' (уже в ' . $b['ship_no'] . ')';
                }
                throw new RuntimeException('Наряды уже добавлены в другие отгрузки: ' . implode(', ', $names));
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            // Нет таблицы связей — проверка невозможна, идём дальше.
        }
        $isSqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $nowFn = $isSqlite ? "datetime('now')" : 'NOW()';
        $this->pdo->beginTransaction();
        try {
            $no = $isSqlite ? $this->nextDocNoPortable() : $this->nextDocNo();
            $cols = "doc_no,doc_date,contractor_id,warehouse_id,status,plan_shipment_date,
                     vehicle,gate,loading_type,delivery_type,priority,consignee,
                     responsible_id,comment,created_by";
            $st = $this->pdo->prepare(
                "INSERT INTO shipments ($cols) VALUES (:no,$nowFn,:ctr,:wh,'assembling',:psd,:veh,:gate,:lt,:dt,:pri,:csg,:resp,:cmt,:cb)"
            );
            // Контрагент — по первому заказу, если не задан явно
            $ctr = (int)($params['contractor_id'] ?? 0);
            if (!$ctr) {
                try {
                    $q = $this->pdo->prepare("SELECT contractor_id FROM client_orders WHERE id=:id");
                    $q->execute([':id' => $orderIds[0]]);
                    $ctr = (int)$q->fetchColumn();
                } catch (Throwable $e) {
                }
            }
            $st->execute([
                ':no' => $no, ':ctr' => $ctr ?: 1, ':wh' => (int)$params['warehouse_id'],
                ':psd' => $params['plan_shipment_date'] ?? null,
                ':veh' => $params['vehicle'] ?? null, ':gate' => $params['gate'] ?? null,
                ':lt' => $params['loading_type'] ?? null, ':dt' => $params['delivery_type'] ?? 'Доставка',
                ':pri' => $params['priority'] ?? 'normal', ':csg' => $params['consignee'] ?? null,
                ':resp' => $userId, ':cmt' => $params['comment'] ?? null, ':cb' => $userId,
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            // Связка заказов (shipment_orders) — best effort, если таблицы нет — пропускаем
            try {
                $ins = $this->pdo->prepare(
                    "INSERT INTO shipment_orders (shipment_id, order_id) VALUES (:s,:o)"
                );
                foreach ($orderIds as $oid) {
                    $ins->execute([':s' => $newId, ':o' => $oid]);
                }
            } catch (Throwable $e) {
            }
            // Состав из заказов: строки нарядов (client_order_items) -> позиции отгрузки.
            // Наличие колонки shipment_items.prod_date проверяем ОДИН раз до цикла:
            // при частичном сбое прежний fallback (вставка без prod_date) продублировал
            // уже вставленные строки наряда.
            $hasProdDate = function_exists('wms_has_column')
                ? wms_has_column($this->pdo, 'shipment_items', 'prod_date') : true;
            try {
                $ins = $this->pdo->prepare(
                    "INSERT INTO shipment_items (shipment_id,order_id,sku,name,unit,qty,price," . wms_quote_ident($this->pdo, 'sum') . ($hasProdDate ? ',prod_date' : '') . ")
                     SELECT :s, order_id, sku, name, unit, qty, 0, 0" . ($hasProdDate ? ', prod_date' : '') . "
                     FROM client_order_items WHERE order_id=:o"
                );
                foreach ($orderIds as $oid) {
                    $ins->execute([':s' => $newId, ':o' => $oid]);
                }
            } catch (Throwable $e) {
            }
            $this->recalc($newId);
            $this->history($newId, $userId, 'create', null, 'assembling');
            $this->pdo->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // Данные для печати по макету: Акт, МХ3, Сборочный/Упаковочный лист, ШК, Паллетный, Реестр,
    // Наряд, УПД, ТН, ТТН.
    public function printData(int $id, string $kind, string $sscc = ''): array
    {
        if ($kind === 'pick_list' || $kind === 'p-sb') {
            return $this->pickDoc($id);
        }
        if ($kind === 'pack_list' || $kind === 'p-up') {
            return $this->packDoc($id, $sscc);
        }
        if ($kind === 'nar' || $kind === 'p-nar') {
            return $this->narDoc($id);
        }
        if ($kind === 'summary' || $kind === 'p-summary') {
            return $this->summaryDoc($id);
        }
        if ($kind === 'upd' || $kind === 'p-upd') {
            return $this->updDoc($id);
        }
        if ($kind === 'tn' || $kind === 'p-tn') {
            return $this->tnDoc($id);
        }
        if ($kind === 'ttn' || $kind === 'p-ttn') {
            return $this->ttnDoc($id);
        }
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        $items = $this->pdo->prepare("SELECT * FROM shipment_items WHERE shipment_id=:id ORDER BY id");
        $items->execute([':id' => $id]);
        return ['kind' => $kind, 'head' => $head, 'items' => $items->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * Наряд (без цен): все наряды (заказы) отгрузки со штрихкодами документа и каждого наряда.
     * В шапке: клиент, адрес доставки, ворота отгрузки. Таблица: п/п, артикул, наименование, кол-во.
     * Друг за другом: сначала отгрузка, потом каждый наряд.
     */
    /**
     * Объём и вес единицы товара из номенклатуры: sku => [volume_m3, weight_kg].
     * Если объём не заполнен — считаем по габаритам, вес — из weight_g.
     */
    private function productMetrics(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $skus)), fn($s) => $s !== '')));
        if (!$skus) {
            return [];
        }
        $out = [];
        try {
            require_once __DIR__ . '/../shared/db.php';
            $pdo = wms_nomenclature_pdo();
            $cols = wms_table_columns($pdo, 'products');
            $hasVol = in_array('volume_m3', $cols, true);
            $hasW = in_array('weight_g', $cols, true);
            $hasDims = in_array('length_mm', $cols, true) && in_array('width_mm', $cols, true) && in_array('height_mm', $cols, true);
            $sel = 'sku' . ($hasVol ? ', volume_m3' : '') . ($hasW ? ', weight_g' : '')
                . ($hasDims ? ', length_mm, width_mm, height_mm' : '');
            $ph = implode(',', array_fill(0, count($skus), '?'));
            $st = $pdo->prepare("SELECT $sel FROM products WHERE sku IN ($ph)");
            $st->execute($skus);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $vol = $hasVol ? (float)($r['volume_m3'] ?? 0) : 0.0;
                if ($vol <= 0 && $hasDims) {
                    $vol = ((float)($r['length_mm'] ?? 0) / 1000)
                        * ((float)($r['width_mm'] ?? 0) / 1000)
                        * ((float)($r['height_mm'] ?? 0) / 1000);
                }
                $wt = $hasW ? (float)($r['weight_g'] ?? 0) / 1000.0 : 0.0;
                $out[(string)$r['sku']] = ['volume_m3' => round($vol, 6), 'weight_kg' => round($wt, 4)];
            }
        } catch (Throwable) {
        }
        return $out;
    }

    /** Дополнить строки объёмом/весом по количеству и посчитать итоги. */
    private function applyItemMetrics(array $items): array
    {
        $metrics = $this->productMetrics(array_column($items, 'sku'));
        $qty = 0.0;
        $vol = 0.0;
        $wt = 0.0;
        foreach ($items as &$it) {
            $q = (float)($it['qty'] ?? 0);
            $m = $metrics[(string)($it['sku'] ?? '')] ?? ['volume_m3' => 0.0, 'weight_kg' => 0.0];
            $it['volume'] = round($q * (float)$m['volume_m3'], 6);
            $it['weight'] = round($q * (float)$m['weight_kg'], 3);
            $qty += $q;
            $vol += (float)$it['volume'];
            $wt += (float)$it['weight'];
        }
        unset($it);
        return ['items' => $items, 'qty' => round($qty, 3), 'volume' => round($vol, 6), 'weight' => round($wt, 3)];
    }

    public function narDoc(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }

        // Все наряды (заказы) в этой отгрузке.
        $orders = $this->pdo->prepare(
            "SELECT o.id, o.doc_no, o.created_at AS doc_date, o.consignee, o.address, o.phone, o.status,
                    o.total, o.warehouse_id
             FROM shipment_orders so
             JOIN client_orders o ON o.id = so.order_id
             WHERE so.shipment_id = :id
             ORDER BY o.doc_no"
        );
        $orders->execute([':id' => $id]);
        $orderList = $orders->fetchAll(PDO::FETCH_ASSOC);

        // Общие итоги отгрузки — всегда по фактическим строкам shipment_items.
        $allItems = $this->pdo->prepare(
            "SELECT sku, name, unit, qty FROM shipment_items WHERE shipment_id=:id ORDER BY id"
        );
        $allItems->execute([':id' => $id]);
        $allRows = $allItems->fetchAll(PDO::FETCH_ASSOC);
        $allCalc = $this->applyItemMetrics($allRows);

        $orderDocs = [];
        foreach ($orderList as $order) {
            // Состав наряда берём из фактических строк отгрузки (shipment_items):
            // client_order_items может быть пустым, если отгрузка собрана из другого источника.
            $items = $this->pdo->prepare(
                "SELECT sku, name, unit, qty FROM shipment_items
                  WHERE shipment_id = :sid AND order_id = :oid ORDER BY id"
            );
            $items->execute([':sid' => $id, ':oid' => $order['id']]);
            $rows = $items->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                $fallback = $this->pdo->prepare(
                    "SELECT sku, name, unit, qty FROM client_order_items WHERE order_id = :oid ORDER BY id"
                );
                $fallback->execute([':oid' => $order['id']]);
                $rows = $fallback->fetchAll(PDO::FETCH_ASSOC);
            }
            // В отгрузке один наряд и строки без order_id — весь состав относится к нему.
            if (!$rows && count($orderList) === 1) {
                $rows = $allRows;
            }
            $calc = $this->applyItemMetrics($rows);
            $order['items'] = $calc['items'];
            $order['qty_total'] = $calc['qty'];
            $order['volume_total'] = $calc['volume'];
            $order['weight_total'] = $calc['weight'];
            $orderDocs[] = $order;
        }
        $head['qty_total'] = $allCalc['qty'];
        $head['volume_total'] = $allCalc['volume'];
        $head['weight_total'] = $allCalc['weight'];

        return [
            'kind' => 'nar', 'title' => 'По отгрузке ' . $head['doc_no'],
            'head' => $head, 'orders' => $orderDocs,
        ];
    }

    /**
     * Сводный документ по отгрузке: одинаковые SKU из разных нарядов
     * объединяются в одну строку, количество/объём/вес суммируются.
     */
    public function summaryDoc(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Отгрузка не найдена');
        }
        $items = $this->pdo->prepare(
            "SELECT code, sku, name, unit, SUM(qty) AS qty
             FROM shipment_items WHERE shipment_id=:id
             GROUP BY code, sku, name, unit ORDER BY MIN(id)"
        );
        $items->execute([':id' => $id]);
        $calc = $this->applyItemMetrics($items->fetchAll(PDO::FETCH_ASSOC));
        $head['qty_total'] = $calc['qty'];
        $head['volume_total'] = $calc['volume'];
        $head['weight_total'] = $calc['weight'];
        return ['kind' => 'summary', 'title' => 'Сводный',
            'head' => $head, 'items' => $calc['items']];
    }

    /**
     * УПД — Универсальный передаточный документ (форма по приказу ФНС России № ММВ-7-15/82@).
     * Без цен в строковой части (только кол-во, артикул, наименование), цены — опционально в шапке.
     */
    public function updDoc(int $id): array
    {
        return $this->primaryDoc($id, 'upd', 'УПД — Универсальный передаточный документ');
    }

    /**
     * ТН — Торговая накладная (форма ТОГ-1).
     */
    public function tnDoc(int $id): array
    {
        return $this->primaryDoc($id, 'tn', 'Торговая накладная');
    }

    /**
     * ТТН — Транспортная накладная (форма по приказу Минтранса России № 272).
     */
    public function ttnDoc(int $id): array
    {
        return $this->primaryDoc($id, 'ttn', 'Транспортная накладная');
    }

    /**
     * Общая подготовка данных для первичных документов (УПД, ТН, ТТН).
     * Возвращает голову + позиции отгрузки без цен (товарная накладная часть).
     */
    private function primaryDoc(int $id, string $kind, string $title): array
    {
        $st = $this->pdo->prepare(
            "SELECT s.*, c.name AS contractor, w.name AS warehouse
             FROM shipments s
             LEFT JOIN contractors c ON c.id = s.contractor_id
             LEFT JOIN warehouses w ON w.id = s.warehouse_id
             WHERE s.id=:id"
        );
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        // Позиции с ценами — для УПД/ТН/ТТН; объём и вес считаем из номенклатуры.
        $items = $this->pdo->prepare(
            "SELECT code, sku, name, unit, qty, price, sum FROM shipment_items
             WHERE shipment_id=:id ORDER BY id"
        );
        $items->execute([':id' => $id]);
        $calc = $this->applyItemMetrics($items->fetchAll(PDO::FETCH_ASSOC));
        $head['qty_total'] = $calc['qty'];
        $head['volume_total'] = $calc['volume'];
        $head['weight_total'] = $calc['weight'];
        return ['kind' => $kind, 'title' => $title, 'head' => $head, 'items' => $calc['items']];
    }

    /**
     * Сборочный лист: состав отгрузки (план/факт отбора через ТСД).
     * В штрихкод зашивается номер отгрузки (на стороне печати).
     */
    public function pickDoc(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        try {
            $rows = $this->pdo->prepare(
                "SELECT i.id, i.code, i.sku, i.name, i.unit, i.qty AS plan_qty
                 FROM shipment_items i WHERE i.shipment_id = :id ORDER BY i.id"
            );
            $rows->execute([':id' => $id]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
            // Отбор в tsd_picks ведётся по SKU (без привязки к наряду), поэтому
            // факт по SKU нужно разложить по его строкам отгрузки. Иначе при
            // повторе артикула в двух нарядах обе строки показывали бы общий
            // факт (например 10/5 и 10/5 — по 200%).
            $picks = $this->pdo->prepare(
                "SELECT sku, COALESCE(SUM(qty),0) AS q FROM tsd_picks WHERE shipment_id = :id GROUP BY sku"
            );
            $picks->execute([':id' => $id]);
            $facts = [];
            foreach ($picks->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $facts[(string)$p['sku']] = (float)$p['q'];
            }
            $dist = self::distributePickFact($items, $facts);
            foreach ($items as &$it) {
                $it['fact_qty'] = $dist[(string)$it['id']] ?? 0.0;
            }
            unset($it);
        } catch (Throwable) {
            $rows = $this->pdo->prepare("SELECT id, code, sku, name, unit, qty AS plan_qty, 0 AS fact_qty FROM shipment_items WHERE shipment_id = :id ORDER BY id");
            $rows->execute([':id' => $id]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['kind' => 'pick_list', 'title' => 'Сборочный лист', 'head' => $head, 'items' => $items];
    }

    /**
     * Раскладывает факт отбора по SKU пропорционально плану его строк.
     * Остаток от деления отдаём последней строке — сумма факта по строкам
     * совпадает с фактом отбора с точностью до копейки.
     * @param array $items строки отгрузки (id, sku, qty)
     * @param array $facts sku => отобранное количество
     * @return array id строки => факт
     */
    private static function distributePickFact(array $items, array $facts): array
    {
        $bySku = [];
        foreach ($items as $it) {
            $bySku[(string)($it['sku'] ?? '')][] = $it;
        }
        $out = [];
        foreach ($bySku as $sku => $rows) {
            $total = (float)($facts[$sku] ?? 0.0);
            if (count($rows) === 1) {
                $out[(string)$rows[0]['id']] = $total;
                continue;
            }
            $planSum = 0.0;
            foreach ($rows as $r) {
                // В выборке план называется plan_qty, в общем виде — qty.
                $planSum += (float)($r['qty'] ?? $r['plan_qty'] ?? 0);
            }
            $acc = 0.0;
            $n = count($rows);
            foreach ($rows as $k => $r) {
                if ($k === $n - 1) {
                    $out[(string)$r['id']] = round($total - $acc, 3);
                    break;
                }
                $plan = (float)($r['qty'] ?? $r['plan_qty'] ?? 0);
                $share = $planSum > 0 ? round($total * $plan / $planSum, 3) : 0.0;
                $out[(string)$r['id']] = $share;
                $acc += $share;
            }
        }
        return $out;
    }

    /**
     * Упаковочный лист: содержимое ОДНОЙ SSCC (факт сортировки через ТСД).
     * В штрихкод зашивается номер SSCC (на стороне печати).
     */
    public function packDoc(int $id, string $sscc): array
    {
        $sscc = trim($sscc);
        if ($sscc === '') {
            throw new RuntimeException('Укажите SSCC');
        }
        $st = $this->pdo->prepare("SELECT * FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $head = $st->fetch(PDO::FETCH_ASSOC);
        if (!$head) {
            throw new RuntimeException('Документ не найден');
        }
        $head['sscc'] = $sscc;
        try {
            // LIMIT 1 без ORDER BY отдавал произвольную строку, если артикул
            // встречается в отгрузке несколько раз — делаем выборку детерминированной.
            $rows = $this->pdo->prepare(
                "SELECT COALESCE((SELECT i.code FROM shipment_items i WHERE i.shipment_id = p.shipment_id AND i.sku = p.sku ORDER BY i.id LIMIT 1), '') AS code,
                        p.sku,
                        COALESCE((SELECT i.name FROM shipment_items i WHERE i.shipment_id = p.shipment_id AND i.sku = p.sku ORDER BY i.id LIMIT 1), p.sku) AS name,
                        COALESCE((SELECT i.unit FROM shipment_items i WHERE i.shipment_id = p.shipment_id AND i.sku = p.sku ORDER BY i.id LIMIT 1), 'шт') AS unit,
                        SUM(p.qty) AS qty
                 FROM tsd_picks p WHERE p.shipment_id = :id AND p.sscc = :sscc GROUP BY p.sku ORDER BY MIN(p.id)"
            );
            $rows->execute([':id' => $id, ':sscc' => $sscc]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $items = [];
        }
        return ['kind' => 'pack_list', 'title' => 'Упаковочный лист', 'sscc' => $sscc, 'head' => $head, 'items' => $items];
    }

    /** SSCC сортировки по отгрузке: [{sscc, lines, qty}] — для выбора упаковочного листа. */
    public function ssccList(int $id): array
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT sscc, COUNT(*) AS lines, COALESCE(SUM(qty), 0) AS qty
                 FROM tsd_picks WHERE shipment_id = :id AND sscc IS NOT NULL AND sscc <> ''
                 GROUP BY sscc ORDER BY MIN(id)"
            );
            $st->execute([':id' => $id]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    // Экспорт реестра в CSV (для больших объемов — отдавать через очередь чанками).
    public function exportCsv(ShipmentFilter $f, object $repo): string
    {
        $page = $repo->paginate($f);
        $out = fopen('php://temp', 'r+');
        fputs($out, "\xEF\xBB\xBF"); // BOM для Excel
        fputcsv($out, ['№ документа', 'Дата', 'Заказ', 'Контрагент', 'Склад', 'Статус', 'Сумма', 'Позиций', 'План. отгрузка', 'Создан'], ';');
        foreach ($page['rows'] as $r) {
            fputcsv($out, [$r['doc_no'], $r['doc_date'], $r['order_no'], $r['contractor'],
                $r['warehouse'], ShipmentStatus::from($r['status'])->label(),
                $r['total'], $r['items_count'], $r['plan_shipment_date'], $r['created_by']], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
