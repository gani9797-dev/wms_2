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

    private function history(int $id, ?int $userId, string $action, ?string $old, ?string $new): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (?,?,?,?,?)"
        );
        $st->execute([$id, $userId, $action, $old, $new]);
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
                      'shipment_documents', 'shipment_flags', 'tsd_picks'] as $tbl) {
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

    // ПКМ: Копировать — новый черновик с теми же позициями.
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
            $ins = $this->pdo->prepare(
                "INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,contract_no,warehouse_id,
                 status,vat_rate,paid,plan_shipment_date,plan_delivery_date,delivery_type,delivery_method,
                 responsible_id,comment,created_by)
                 VALUES (:no,NOW(),:ord,:ctr,:cn,:wh,'draft',:vat,0,:psd,:pdd,:dt,:dm,:resp,:cmt,:cb)"
            );
            $ins->execute([
                ':no' => $newNo, ':ord' => $src['order_id'], ':ctr' => $src['contractor_id'],
                ':cn' => $src['contract_no'], ':wh' => $src['warehouse_id'], ':vat' => $src['vat_rate'],
                ':psd' => $src['plan_shipment_date'], ':pdd' => $src['plan_delivery_date'],
                ':dt' => $src['delivery_type'], ':dm' => $src['delivery_method'],
                ':resp' => $userId, ':cmt' => $src['comment'], ':cb' => $userId,
            ]);
            $newId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare(
                "INSERT INTO shipment_items (shipment_id,sku,name,qty,price,`sum`)
                 SELECT :nid,sku,name,qty,price,qty*price FROM shipment_items WHERE shipment_id=:oid"
            )->execute([':nid' => $newId, ':oid' => $id]);
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
        $st = $this->pdo->prepare("SELECT status FROM shipments WHERE id=:id FOR UPDATE");
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
    }

    // ПКМ: Удалить (только черновик/отменена) / Архивировать (только доставлена/отменена).
    public function delete(int $id, ?int $userId): void
    {
        $st = $this->pdo->prepare("SELECT status FROM shipments WHERE id=:id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Документ не найден');
        }
        if (!in_array($row['status'], ['draft', 'cancelled'], true)) {
            throw new RuntimeException('Удалять можно только черновик или отмененную');
        }
        $this->pdo->prepare("DELETE FROM shipments WHERE id=:id")->execute([':id' => $id]);
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
            // Best effort: у старых заказов строк может не быть; в старых БД
            // может не быть колонки shipment_items.prod_date — тогда без неё.
            try {
                $ins = $this->pdo->prepare(
                    "INSERT INTO shipment_items (shipment_id,order_id,sku,name,unit,qty,price,`sum`,prod_date)
                     SELECT :s, order_id, sku, name, unit, qty, 0, 0, prod_date
                     FROM client_order_items WHERE order_id=:o"
                );
                foreach ($orderIds as $oid) {
                    $ins->execute([':s' => $newId, ':o' => $oid]);
                }
            } catch (Throwable $e) {
                try {
                    $ins = $this->pdo->prepare(
                        "INSERT INTO shipment_items (shipment_id,order_id,sku,name,unit,qty,price,`sum`)
                         SELECT :s, order_id, sku, name, unit, qty, 0, 0
                         FROM client_order_items WHERE order_id=:o"
                    );
                    foreach ($orderIds as $oid) {
                        $ins->execute([':s' => $newId, ':o' => $oid]);
                    }
                } catch (Throwable $e2) {
                }
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

    // Данные для печати по макету: Акт, МХ3, Сборочный/Упаковочный лист, ШК, Паллетный, Реестр
    public function printData(int $id, string $kind, string $sscc = ''): array
    {
        if ($kind === 'pick_list' || $kind === 'p-sb') {
            return $this->pickDoc($id);
        }
        if ($kind === 'pack_list' || $kind === 'p-up') {
            return $this->packDoc($id, $sscc);
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
                "SELECT i.code, i.sku, i.name, i.unit, i.qty AS plan_qty,
                        COALESCE((SELECT SUM(p.qty) FROM tsd_picks p WHERE p.shipment_id = i.shipment_id AND p.sku = i.sku), 0) AS fact_qty
                 FROM shipment_items i WHERE i.shipment_id = :id ORDER BY i.id"
            );
            $rows->execute([':id' => $id]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $rows = $this->pdo->prepare("SELECT code, sku, name, unit, qty AS plan_qty, 0 AS fact_qty FROM shipment_items WHERE shipment_id = :id ORDER BY id");
            $rows->execute([':id' => $id]);
            $items = $rows->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['kind' => 'pick_list', 'title' => 'Сборочный лист', 'head' => $head, 'items' => $items];
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
            $rows = $this->pdo->prepare(
                "SELECT COALESCE((SELECT i.code FROM shipment_items i WHERE i.shipment_id = p.shipment_id AND i.sku = p.sku LIMIT 1), '') AS code,
                        p.sku,
                        COALESCE((SELECT i.name FROM shipment_items i WHERE i.shipment_id = p.shipment_id AND i.sku = p.sku LIMIT 1), p.sku) AS name,
                        COALESCE((SELECT i.unit FROM shipment_items i WHERE i.shipment_id = p.shipment_id AND i.sku = p.sku LIMIT 1), 'шт') AS unit,
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
