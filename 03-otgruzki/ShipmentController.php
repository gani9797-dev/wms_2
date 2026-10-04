<?php
declare(strict_types=1);

require_once __DIR__ . '/ShipmentFilter.php';
require_once __DIR__ . '/ShipmentRepository.php';
require_once __DIR__ . '/ShipmentService.php';
require_once __DIR__ . '/ShipmentStatus.php';

// REST-контроллер реестра. Маппинг на UI макета:
//  GET /shipments?search=&status[]=delivering&page=1  -> реестр + "Показано x–y из z"
//  GET /shipments/1287                               -> карточка (вкладка Основное)
//  POST /shipments                                   -> "+ Создать"
//  PUT /shipments/1287                               -> "Изменить"
//  POST /shipments/1287/copy                         -> ПКМ Копировать
//  POST /shipments/1287/status                       -> ПКМ Провести / Отменить/Вернуть {"to":"delivered"}
//  POST /shipments/1287/archive                      -> ПКМ Архивировать
//  DELETE /shipments/1287                            -> ПКМ Удалить
//  GET /shipments/export.csv?...                     -> Экспорт в Excel/CSV
// Под Laravel этот же класс становится Http/Controllers/ShipmentController
// с FormRequest вместо ShipmentFilter::fromArray($_GET).

final class ShipmentController
{
    public function __construct(
        private ShipmentRepository $repo,
        private ShipmentService $service,
        private ?int $userId = null
    ) {}

    public function index(array $query): void
    {
        $f = ShipmentFilter::fromArray($query);
        $this->json($this->repo->paginate($f));
    }

    public function show(int $id): void
    {
        $row = $this->repo->findById($id);
        if (!$row) {
            $this->json(['error' => 'Не найдено'], 404);
            return;
        }
        // Вкладки Товары/Доставка/Документы/История/Движение догружаются
        // отдельными эндпоинтами, чтобы карточка открывалась быстро.
        $this->json($row);
    }

    public function store(array $body): void
    {
        // Валидация минимума (в Laravel — ShipmentStoreRequest):
        // contractor_id, warehouse_id обязательны; order_id, plan_shipment_date опциональны.
        if (empty($body['contractor_id']) || empty($body['warehouse_id'])) {
            $this->json(['error' => 'contractor_id и warehouse_id обязательны'], 422);
            return;
        }
        $pdo = $this->prop('pdo');
        $pdo->beginTransaction();
        try {
            $no = $this->service->nextDocNo();
            $st = $pdo->prepare(
                "INSERT INTO shipments (doc_no,doc_date,order_id,contractor_id,contract_no,warehouse_id,
                 status,vat_rate,plan_shipment_date,plan_delivery_date,delivery_type,delivery_method,
                 responsible_id,comment,created_by)
                 VALUES (:no,NOW(),:ord,:ctr,:cn,:wh,'draft',:vat,:psd,:pdd,:dt,:dm,:resp,:cmt,:cb)"
            );
            $st->execute([
                ':no' => $no, ':ord' => $body['order_id'] ?? null, ':ctr' => (int)$body['contractor_id'],
                ':cn' => $body['contract_no'] ?? null, ':wh' => (int)$body['warehouse_id'],
                ':vat' => (int)($body['vat_rate'] ?? 20), ':psd' => $body['plan_shipment_date'] ?? null,
                ':pdd' => $body['plan_delivery_date'] ?? null, ':dt' => $body['delivery_type'] ?? null,
                ':dm' => $body['delivery_method'] ?? null, ':resp' => $body['responsible_id'] ?? $this->userId,
                ':cmt' => $body['comment'] ?? null, ':cb' => $this->userId,
            ]);
            $id = (int)$pdo->lastInsertId();
            $this->service->recalc($id);
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
            $to = ShipmentStatus::from($body['to'] ?? '');
            $this->service->changeStatus($id, $to, $this->userId);
            $this->json(['ok' => true, 'status' => $to->value, 'label' => $to->label()]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
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

    public function disband(int $id): void
    {
        try {
            $res = $this->service->disband($id, $this->userId);
            $this->json(['ok' => true] + $res);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    // Вкладки карточки
    public function orders(int $id): void
    {
        $this->json($this->repo->orders($id));
    }

    public function unlinkOrders(int $id, array $body): void
    {
        try {
            $ids = (array)($body['order_ids'] ?? $body['ids'] ?? []);
            $res = $this->service->unlinkOrders($id, $ids, $this->userId);
            $this->json(['ok' => true] + $res);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function items(int $id): void
    {
        $this->json($this->repo->items($id));
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

    public function candidates(array $query): void
    {
        $this->json($this->repo->pickCandidates($query));
    }

    // ПКМ: Архивировать / Приоритет / Запустить сборку / Печать / Формирование
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

    public function startPicking(int $id, array $body): void
    {
        try {
            $this->service->startPicking($id, $this->userId, (string)($body['mode'] ?? 'wave'));
            $this->json(['ok' => true]);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function issuePick(array $body): void
    {
        try {
            $ids = (array)($body['shipment_ids'] ?? $body['ids'] ?? []);
            $res = $this->service->issuePickTasks($ids, (string)($body['mode'] ?? 'wave'), $this->userId);
            $this->json(['ok' => true] + $res);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function releasePick(array $body): void
    {
        try {
            $ids = (array)($body['shipment_ids'] ?? $body['ids'] ?? []);
            $res = $this->service->releasePickTasks($ids, $this->userId);
            $this->json(['ok' => true] + $res);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function pickTasks(int $id): void
    {
        try {
            $this->json($this->service->pickTasksForShipment($id));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function print(int $id, array $query): void
    {
        $kind = (string)($query['kind'] ?? 'act');
        if (($query['format'] ?? '') === 'pdf') {
            require_once __DIR__ . '/../shared/Pdf.php';
            try {
                $pSscc = (string)($query['sscc'] ?? '');
                [$pData, $pForce] = wms_shipment_print($this->service->printData($id, $kind, $pSscc));
                $pBc = ($kind === 'pick_list' || $kind === 'p-sb') ? (string)($pData['head']['doc_no'] ?? '')
                    : ((($kind === 'pack_list' || $kind === 'p-up') && $pSscc !== '') ? $pSscc : '');
                wms_pdf_output(wms_pdf_auto($pData, $pForce, $pBc !== '' ? $pBc : null), wms_pdf_filename($pData['title'] ?? $kind, $id));
            } catch (Throwable $e) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка PDF: ' . $e->getMessage();
            }
            return;
        }
        try {
            $this->json($this->service->printData($id, $kind));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function ssccList(int $id): void
    {
        try {
            $this->json($this->service->ssccList($id));
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function form(array $body): void
    {
        try {
            $nid = $this->service->formFromOrders(
                (array)($body['order_ids'] ?? []),
                (array)($body['params'] ?? []),
                $this->userId
            );
            $this->json(['id' => $nid], 201);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    public function import(array $body): void
    {
        // Импорт из Excel: в проде — очередь чанками; здесь принимаем строки и создаем черновики.
        $rows = (array)($body['rows'] ?? []);
        $created = 0;
        foreach ($rows as $r) {
            if (!empty($r['contractor_id']) && !empty($r['warehouse_id'])) {
                try {
                    $this->store((array)$r);
                    // store уже ответил json — подавляем повтор; считаем только валидные
                    $created++;
                } catch (Throwable $e) {
                }
            }
        }
        $this->json(['ok' => true, 'note' => 'Импорт принят (демо): ' . $created]);
    }

    public function export(array $query): void
    {
        $f = ShipmentFilter::fromArray($query);
        $f->page = 1;
        $f->perPage = 5000; // чанк; для 100k+ — Job + cursor
        $csv = $this->service->exportCsv($f, $this->repo);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="shipments.csv"');
        echo $csv;
    }

    private function prop(string $name): mixed
    {
        $r = new ReflectionClass($this->service);
        $p = $r->getProperty($name);
        // PDO хранится как private в сервисе; для примера отдаем через рефлексию.
        // В Laravel вместо этого — DB::transaction() и Eloquent.
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
