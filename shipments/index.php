<?php
declare(strict_types=1);

// Точка входа-пример: реестр + карточка. Запуск: php -S localhost:8000 index.php
// GET /?action=list&search=СпортОпт&status[]=delivering&page=1
// GET /?action=show&id=1
// POST /?action=store  (JSON: contractor_id, warehouse_id)
// Для Laravel: перенести логику в routes/api.php, здесь только демонстрация wiring.

require_once __DIR__ . '/ShipmentFilter.php';
require_once __DIR__ . '/ShipmentRepository.php';
require_once __DIR__ . '/ShipmentService.php';
require_once __DIR__ . '/ShipmentController.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=wms;charset=utf8mb4',
    'wms_user',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$repo = new ShipmentRepository($pdo);
$service = new ShipmentService($pdo);
// $userId берите из сессии/JWT; в макете это "Иванов И.И., Администратор".
$controller = new ShipmentController($repo, $service, 1);

$action = $_GET['action'] ?? 'list';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
if ($action === 'list') {
    $controller->index($_GET);
} elseif ($action === 'show') {
    $controller->show((int)($_GET['id'] ?? 0));
} elseif ($action === 'orders') {
    $controller->orders((int)($_GET['id'] ?? 0));
} elseif ($action === 'unlink_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->unlinkOrders((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'items') {
    $controller->items((int)($_GET['id'] ?? 0));
} elseif ($action === 'documents') {
    $controller->documents((int)($_GET['id'] ?? 0));
} elseif ($action === 'history') {
    $controller->history((int)($_GET['id'] ?? 0));
} elseif ($action === 'flags') {
    $controller->flags((int)($_GET['id'] ?? 0));
} elseif ($action === 'candidates') {
    $controller->candidates($_GET);
} elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->store($body);
} elseif ($action === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->form($body);
} elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->copy((int)($_GET['id'] ?? 0));
} elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->changeStatus((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'priority' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->priority((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'picking' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->startPicking((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'issue_pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->issuePick($body);
} elseif ($action === 'release_pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->releasePick($body);
} elseif ($action === 'pick_tasks') {
    $controller->pickTasks((int)($_GET['id'] ?? 0));
} elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->archive((int)($_GET['id'] ?? 0));
} elseif ($action === 'print') {
    $controller->print((int)($_GET['id'] ?? 0), $_GET);
} elseif ($action === 'sscc_list') {
    $controller->ssccList((int)($_GET['id'] ?? 0));
} elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->import($body);
} elseif ($action === 'refs') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'contractors' => $pdo->query("SELECT id, name FROM contractors ORDER BY name")->fetchAll(),
        'warehouses' => $pdo->query("SELECT id, name FROM warehouses ORDER BY id")->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'csv_shipment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../shared/CsvUpload.php';
    try {
        $parsed = csv_upload_parse($_FILES['file'] ?? [], csv_upload_shipment_spec());
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            // В проде отгрузки и номенклатура живут в одной БД wms.
            // Контрагент — из колонки «Контрагент» файла (нового добавляем в справочник).
            $wh = (int)($_POST['warehouse_id'] ?? 0);
            $hdr = $parsed['header'] ?? [];
            $cc = csv_upload_resolve_contractor($pdo, (string)($hdr['contractor'] ?? ''));
            $r = csv_upload_import_orders($pdo, $pdo, $parsed['rows'],
                $cc['id'], $wh, 1,
                (string)($_FILES['file']['name'] ?? 'upload.csv'), null, $hdr);
            $r += ['contractor' => $cc['name'], 'contractor_new' => $cc['created']];
            // Отгрузка из наряда формируется автоматически — вручную не нужно.
            $ship = [];
            try {
                $formId = $service->formFromOrders([$r['id']], [
                    'warehouse_id' => $wh,
                    'consignee' => ($hdr['consignee'] ?? '') !== '' ? $hdr['consignee'] : null,
                    'delivery_type' => ($hdr['ship_type'] ?? '') !== '' ? $hdr['ship_type'] : null,
                    'comment' => isset($r['order_no']) ? ('Авто: наряд ' . $r['order_no']) : null,
                ], 1);
                csv_upload_apply_shipment_header($pdo, $formId, $hdr);
                $ship = ['shipment_id' => $formId,
                    'shipment_no' => (string)$pdo->query('SELECT doc_no FROM shipments WHERE id=' . $formId)->fetchColumn()];
            } catch (Throwable) {
            }
            http_response_code(201);
            echo json_encode($r + ['row_errors' => $parsed['errors']] + $ship, JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'csv_orders' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Загрузка расхода как наряда (номер or_XXXXXX, один файл = один наряд).
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../shared/CsvUpload.php';
    try {
        $parsed = csv_upload_parse($_FILES['file'] ?? [], csv_upload_shipment_spec());
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            // В проде отгрузки и номенклатура живут в одной БД wms.
            $wh = (int)($_POST['warehouse_id'] ?? 0);
            $r = csv_upload_import_orders($pdo, $pdo, $parsed['rows'],
                (int)($_POST['contractor_id'] ?? 0), $wh, 1,
                (string)($_FILES['file']['name'] ?? 'upload.csv'));
            // Отгрузка из наряда формируется автоматически — вручную не нужно.
            $ship = [];
            try {
                $formId = $service->formFromOrders([$r['id']], ['warehouse_id' => $wh], 1);
                $ship = ['shipment_id' => $formId,
                    'shipment_no' => (string)$pdo->query('SELECT doc_no FROM shipments WHERE id=' . $formId)->fetchColumn()];
            } catch (Throwable) {
            }
            http_response_code(201);
            echo json_encode($r + ['row_errors' => $parsed['errors']] + $ship, JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->destroy((int)($_GET['id'] ?? 0));
} elseif ($action === 'disband' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->disband((int)($_GET['id'] ?? 0));
} elseif ($action === 'export') {
    $controller->export($_GET);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
}
