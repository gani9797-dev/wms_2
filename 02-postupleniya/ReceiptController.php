<?php
declare(strict_types=1);

require_once __DIR__ . '/ReceiptFilter.php';
require_once __DIR__ . '/ReceiptRepository.php';
require_once __DIR__ . '/ReceiptService.php';
require_once __DIR__ . '/ReceiptStatus.php';

// REST-контроллер поступлений. Маппинг на UI макета:
//  GET /receipts?search=&status[]=accepting&page=1 -> реестр + "Показано x–y из z"
//  GET /receipts/7                                -> карточка (вкладка Основное)
//  POST /receipts                                 -> "+ Создать"
//  POST /receipts/7/copy                          -> ПКМ Копировать
//  POST /receipts/7/status                        -> ПКМ Принять/Отменить {"to":"closed"}
//  POST /receipts/7/archive                       -> ПКМ Архивировать
//  GET /receipts/export.csv?...                   -> Экспорт

final class ReceiptController
{
    public function __construct(
        private ReceiptRepository $repo,
        private ReceiptService $service,
        private ?int $userId = null
    ) {}

    public function index(array $query): void
    {
        $f = ReceiptFilter::fromArray($query);
        $this->json($this->repo->paginate($f));
    }

    public function show(int $id): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            $this->json(['error' => 'Не найдено'], 404);
            return;
        }
        $this->json($row);
    }

    public function store(array $body): void
    {
        if (empty($body['supplier_id']) || empty($body['warehouse_id'])) {
            $this->json(['error' => 'supplier_id и warehouse_id обязательны'], 422);
            return;
        }
        if (empty($body['cargo_places_plan'])) {
            $this->json(['error' => 'Укажите кол-во грузовых мест (план)'], 422);
            return;
        }
        $pdo = $this->prop('pdo');
        // Номер документа — ДО открытия транзакции: на MySQL LOCK TABLES
        // неявно коммитит активную транзакцию, и последующий commit() падал бы
        // с «There is no active transaction» (строка всё равно была бы записана).
        $no = $this->service->nextDocNo();
        $pdo->beginTransaction();
        try {
            $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'NOW()' : "datetime('now')";
            $st = $pdo->prepare(
                "INSERT INTO receipts (doc_no,external_no,doc_date,plan_delivery_date,supplier_id,contractor_id,
                 contract_no,warehouse_id,status,receipt_type,accept_type,unload_type,
                 vehicle_plan,gate,cargo_places_plan,priority,vat_rate,comment,creator_id,created_by)
                 VALUES (:no,:ext,$now,:plan,:sup,:ctr,:cn,:wh,'draft',:rt,:at,:ut,:vp,:gate,:cp,:pri,:vat,:cmt,:cb,:cb)"
            );
            $st->execute([
                ':no' => $no, ':ext' => $body['external_no'] ?? null,
                ':plan' => $body['plan_delivery_date'] ?? null,
                ':sup' => (int)$body['supplier_id'], ':ctr' => (int)($body['contractor_id'] ?? $body['supplier_id']),
                ':cn' => $body['contract_no'] ?? null, ':wh' => (int)$body['warehouse_id'],
                ':rt' => $body['receipt_type'] ?? 'supply', ':at' => $body['accept_type'] ?? null,
                ':ut' => $body['unload_type'] ?? null, ':vp' => $body['vehicle_plan'] ?? null,
                ':gate' => $body['gate'] ?? null, ':cp' => (int)$body['cargo_places_plan'],
                ':pri' => $body['priority'] ?? 'normal', ':vat' => (int)($body['vat_rate'] ?? 20),
                ':cmt' => $body['comment'] ?? null, ':cb' => $this->userId,
            ]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
            $this->json(['id' => $id, 'doc_no' => $no], 201);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $this->json(['error' => $e->getMessage()], 500);
        }
    }

    public function copy(int $id): void
    {
        try {
            $nid = $this->service->copy($id, $this->userId);
            $this->json(['id' => $nid]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function changeStatus(int $id, array $body): void
    {
        try {
            $to = ReceiptStatus::from($body['to'] ?? '');
            $closed = $this->service->changeStatus($id, $to, $this->userId);
            $stock = $this->maybePushStock($id, $to);
            // При закрытии статус выводится из факта расхождений.
            $status = is_array($closed) && isset($closed['status']) ? (string)$closed['status'] : $to->value;
            $this->json(['ok' => true, 'status' => $status, 'label' => ReceiptStatus::tryLabel($status),
                'stock' => $stock,
                'acts' => $closed['acts'] ?? [], 'excess_qty' => $closed['excess_qty'] ?? 0,
                'shortage_qty' => $closed['shortage_qty'] ?? 0, 'deviation' => $closed['deviation'] ?? '']);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    // Кнопка "Изменить" в карточке: дата поставки (план) + статус.
    public function updateHead(int $id, array $body): void
    {
        try {
            $status = $this->service->updateHead($id, $body, $this->userId);
            try {
                $to = ReceiptStatus::from($status);
            } catch (Throwable) {
                $this->json(['ok' => true, 'status' => $status]);
                return;
            }
            $stock = $this->maybePushStock($id, $to);
            $this->json(['ok' => true, 'status' => $to->value, 'label' => $to->label(), 'stock' => $stock]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    // Закрытие приходует факт в Номенклатуру (прод MySQL — та же БД `wms`, демо — второй файл через shared).
    // ВНИМАНИЕ: само закрытие (гарды + акты расхождений + статус по факту)
    // уже выполнено в ReceiptService::changeStatus() через wms_close_receipt_guarded().
    // Здесь только проброс факта в Номенклатуру.
    private function maybePushStock(int $id, ReceiptStatus $to): ?array
    {
        if (!in_array($to, [ReceiptStatus::Closed, ReceiptStatus::ClosedShort], true)) {
            return null;
        }
        try {
            $pdo = $this->prop('pdo');
            $isMysql = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
            $out = [];
            require_once __DIR__ . '/../shared/StockSync.php';
            if ($isMysql) {
                $out['stock'] = wms_push_receipt_to_nomenclature($pdo, $pdo, $id, $this->userId, 'Реестр поступлений');
            } else {
                require_once __DIR__ . '/../shared/db.php';
                $out['stock'] = wms_push_receipt_to_nomenclature($pdo, wms_nomenclature_pdo(), $id, $this->userId, 'Реестр поступлений');
            }
            return $out;
        } catch (Throwable $e) {
            return ['synced' => false, 'error' => $e->getMessage()];
        }
    }

    public function destroy(int $id): void
    {
        try {
            $this->service->delete($id, $this->userId);
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function items(int $id): void
    {
        $this->json($this->repo->items($id));
    }

    public function operations(int $id): void
    {
        $this->json($this->repo->operations($id));
    }

    public function documents(int $id): void
    {
        $this->json($this->repo->documents($id));
    }

    public function history(int $id): void
    {
        $this->json($this->repo->history($id));
    }

    public function flags(int $id): void
    {
        $this->json($this->repo->flags($id));
    }

    public function stockin(int $id): void
    {
        $this->json($this->repo->stockin($id));
    }

    public function archive(int $id): void
    {
        try {
            $this->service->archive($id, $this->userId);
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function priority(int $id, array $body): void
    {
        try {
            $this->service->priority($id, (string)($body['priority'] ?? 'normal'), $this->userId);
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function assign(int $id, array $body): void
    {
        try {
            $this->service->assignReceiver($id, (int)($body['receiver_id'] ?? 0), $this->userId);
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function print(int $id, array $query): void
    {
        $kind = (string)($query['kind'] ?? 'act');
        $type = (string)($query['type'] ?? '');
        if (($query['format'] ?? '') === 'pdf') {
            require_once __DIR__ . '/../shared/Pdf.php';
            try {
                $data = $this->service->printData($id, $kind, $type);
                $force = (!in_array($kind, ['act_rasx', 'receiver_doc'], true) && isset($data['items']))
                    ? ['line_no' => 'п/п', 'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование', 'unit' => 'Ед.', 'qty' => 'Кол-во']
                    : null;
                $bcText = ($kind === 'receiver_doc') ? (string)($data['head']['doc_no'] ?? '') : '';
                // Печатные формы поступлений печатаем в альбомной ориентации.
                wms_pdf_output(wms_pdf_auto($data, $force, $bcText !== '' ? $bcText : null, true), wms_pdf_filename($data['title'] ?? $kind, $id));
            } catch (Throwable $e) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка PDF: ' . $e->getMessage();
            }
            return;
        }
        try {
            $this->json($this->service->printData($id, $kind, $type));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function actTypes(int $id): void
    {
        try {
            $this->json($this->service->actTypes($id));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function import(array $body): void
    {
        $rows = (array)($body['rows'] ?? []);
        $created = 0;
        foreach ($rows as $r) {
            if (!empty($r['supplier_id']) && !empty($r['warehouse_id'])) {
                $created++;
            }
        }
        $this->json(['ok' => true, 'note' => 'Импорт принят (демо): ' . $created]);
    }

    public function export(array $query): void
    {
        $f = ReceiptFilter::fromArray($query);
        $f->page = 1;
        $f->perPage = 5000;
        $csv = $this->service->exportCsv($f, $this->repo);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="receipts.csv"');
        echo $csv;
    }

    private function prop(string $name): mixed
    {
        $r = new ReflectionClass($this->service);
        $p = $r->getProperty($name);
        $p->setAccessible(true);
        return $p->getValue($this->service);
    }

    private function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
