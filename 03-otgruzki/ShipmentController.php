<?php
declare(strict_types=1);

require_once __DIR__ . '/ShipmentFilter.php';
require_once __DIR__ . '/ShipmentRepository.php';
require_once __DIR__ . '/ShipmentService.php';
require_once __DIR__ . '/ShipmentStatus.php';
require_once __DIR__ . '/../shared/Counterparties.php';

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
        try {
            $res = $this->service->createShipment($body, $this->userId);
            $this->json(['id' => $res['id'], 'doc_no' => $res['doc_no']], 201);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
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
        // Раньше в цикле звался store(), который САМ печатал JSON: ответ состоял из
        // N склеенных JSON-документов (невалидный JSON), а счётчик created рос
        // даже для строк, упавших с ошибкой. Теперь создаём через сервис и
        // отвечаем один раз, со списком созданных и списком ошибок.
        $rows = (array)($body['rows'] ?? []);
        $created = [];
        $errors = [];
        foreach ($rows as $idx => $r) {
            $r = (array)$r;
            if (empty($r['contractor_id']) || empty($r['warehouse_id'])) {
                $errors[] = 'строка ' . ($idx + 1) . ': нет contractor_id / warehouse_id';
                continue;
            }
            try {
                $res = $this->service->createShipment($r, $this->userId);
                $created[] = $res['doc_no'];
            } catch (Throwable $e) {
                $errors[] = 'строка ' . ($idx + 1) . ': ' . $e->getMessage();
            }
        }
        $this->json([
            'ok' => true,
            'created' => count($created),
            'doc_nos' => $created,
            'errors' => $errors,
            'note' => 'Импорт: создано ' . count($created) . ' из ' . count($rows)
                . ($errors ? ', ошибок: ' . count($errors) : ''),
        ]);
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

    /**
     * Справочники для форм: контрагенты и склады.
     * Перенесено из legacy-дубля shipments/ при сведении модулей.
     */
    public function refs(): void
    {
        $pdo = $this->prop('pdo');
        $this->json([
            'contractors' => $pdo->query("SELECT id, name FROM contractors ORDER BY name")->fetchAll(),
            'warehouses' => $pdo->query("SELECT id, name FROM warehouses ORDER BY id")->fetchAll(),
        ]);
    }

    /**
     * Загрузка отгрузки из CSV/Excel-заявки: наряд + автоматическая отгрузка.
     * Перенесено из legacy-дубля shipments/ при сведении модулей.
     */
    public function csvShipment(): void
    {
        require_once __DIR__ . '/../shared/CsvUpload.php';
        require_once __DIR__ . '/../shared/ShipmentExcel.php';
        $pdo = $this->prop('pdo');
        try {
            $parsed = shipment_upload_parse_file($_FILES['file'] ?? []);
            if (!$parsed['rows']) {
                $this->json(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], 422);
                return;
            }
            $wh = (int)($_POST['warehouse_id'] ?? 0);
            $hdr = $parsed['header'] ?? [];
            $cc = csv_upload_resolve_contractor($pdo, (string)($hdr['contractor'] ?? ''));
            $r = csv_upload_import_orders($pdo, $pdo, $parsed['rows'],
                $cc['id'], $wh, 1,
                (string)($_FILES['file']['name'] ?? 'upload.csv'), null, $hdr);
            // Грузополучатель из отгрузки автоматически попадает в справочник (тип «грузополучатель»).
            WmsCounterparties::ensure($pdo, (string)($hdr['consignee'] ?? ''), 'consignee');
            $r += ['contractor' => $cc['name'], 'contractor_new' => $cc['created'],
                'format' => ($parsed['format'] ?? '')];
            // Отгрузка из наряда формируется автоматически — вручную не нужно.
            $ship = [];
            try {
                $formId = $this->service->formFromOrders([$r['id']], [
                    'warehouse_id' => $wh,
                    'consignee' => ($hdr['consignee'] ?? '') !== '' ? $hdr['consignee'] : null,
                    'delivery_type' => ($hdr['ship_type'] ?? '') !== '' ? $hdr['ship_type'] : null,
                    'plan_shipment_date' => ($hdr['plan_date'] ?? '') !== '' ? $hdr['plan_date'] : null,
                    'comment' => isset($r['order_no']) ? ('Авто: наряд ' . $r['order_no']) : null,
                ], $this->userId);
                csv_upload_apply_shipment_header($pdo, $formId, $hdr);
                $ship = ['shipment_id' => $formId,
                    'shipment_no' => (string)$pdo->query('SELECT doc_no FROM shipments WHERE id=' . (int)$formId)->fetchColumn()];
            } catch (Throwable) {
            }
            $this->json($r + ['row_errors' => $parsed['errors']] + $ship, 201);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Загрузка наряда из CSV/Excel-заявки (номер or_XXXXXX, один файл = один наряд).
     * Перенесено из legacy-дубля shipments/ при сведении модулей.
     */
    public function csvOrders(): void
    {
        require_once __DIR__ . '/../shared/CsvUpload.php';
        require_once __DIR__ . '/../shared/ShipmentExcel.php';
        $pdo = $this->prop('pdo');
        try {
            $parsed = shipment_upload_parse_file($_FILES['file'] ?? []);
            if (!$parsed['rows']) {
                $this->json(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], 422);
                return;
            }
            $wh = (int)($_POST['warehouse_id'] ?? 0);
            $hdr = $parsed['header'] ?? [];
            // Контрагент — из файла (шапка/колонка) либо явно переданный contractor_id.
            $contractorId = (int)($_POST['contractor_id'] ?? 0);
            if ($contractorId <= 0) {
                $contractorId = csv_upload_resolve_contractor($pdo, (string)($hdr['contractor'] ?? ''))['id'];
            }
            $r = csv_upload_import_orders($pdo, $pdo, $parsed['rows'],
                $contractorId, $wh, $this->userId,
                (string)($_FILES['file']['name'] ?? 'upload.csv'), null, $hdr);
            // Грузополучатель из отгрузки автоматически попадает в справочник (тип «грузополучатель»).
            WmsCounterparties::ensure($pdo, (string)($hdr['consignee'] ?? ''), 'consignee');
            // Отгрузка из наряда формируется автоматически — вручную не нужно.
            $ship = [];
            try {
                $formId = $this->service->formFromOrders([$r['id']], ['warehouse_id' => $wh], $this->userId);
                $ship = ['shipment_id' => $formId,
                    'shipment_no' => (string)$pdo->query('SELECT doc_no FROM shipments WHERE id=' . (int)$formId)->fetchColumn()];
            } catch (Throwable) {
            }
            $this->json($r + ['row_errors' => $parsed['errors']] + $ship, 201);
        } catch (Throwable $e) {
            $this->json(['error' => $e->getMessage()], 400);
        }
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
