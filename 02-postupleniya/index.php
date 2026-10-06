<?php
declare(strict_types=1);

// Точка входа-пример: реестр + карточка поступлений. Запуск: php -S localhost:8001 index.php
// GET /?action=list&search=СпортОпт&status[]=accepting&page=1
// GET /?action=show&id=1
// POST /?action=store (JSON: supplier_id, warehouse_id, cargo_places_plan)

require_once __DIR__ . '/ReceiptFilter.php';
require_once __DIR__ . '/ReceiptRepository.php';
require_once __DIR__ . '/ReceiptService.php';
require_once __DIR__ . '/ReceiptController.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=wms;charset=utf8mb4',
    'wms_user',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$repo = new ReceiptRepository($pdo);
$service = new ReceiptService($pdo);
$controller = new ReceiptController($repo, $service, 1);

$action = $_GET['action'] ?? 'list';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
if ($action === 'list') {
    $controller->index($_GET);
} elseif ($action === 'show') {
    $controller->show((int)($_GET['id'] ?? 0));
} elseif ($action === 'operations') {
    $controller->operations((int)($_GET['id'] ?? 0));
} elseif ($action === 'items') {
    $controller->items((int)($_GET['id'] ?? 0));
} elseif ($action === 'documents') {
    $controller->documents((int)($_GET['id'] ?? 0));
} elseif ($action === 'history') {
    $controller->history((int)($_GET['id'] ?? 0));
} elseif ($action === 'flags') {
    $controller->flags((int)($_GET['id'] ?? 0));
} elseif ($action === 'stockin') {
    $controller->stockin((int)($_GET['id'] ?? 0));
} elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->store($body);
} elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->copy((int)($_GET['id'] ?? 0));
} elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->changeStatus((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->updateHead((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'priority' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->priority((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->assign((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->archive((int)($_GET['id'] ?? 0));
} elseif ($action === 'print') {
    $controller->print((int)($_GET['id'] ?? 0), $_GET);
} elseif ($action === 'act_types') {
    $controller->actTypes((int)($_GET['id'] ?? 0));
} elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->import($body);
} elseif ($action === 'refs') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'suppliers' => $pdo->query("SELECT id, name FROM contractors ORDER BY name")->fetchAll(),
        'warehouses' => $pdo->query("SELECT id, name FROM warehouses ORDER BY id")->fetchAll(),
    ], JSON_UNESCAPED_UNICODE);
} elseif ($action === 'csv_receipt' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../shared/CsvUpload.php';
    try {
        $parsed = csv_upload_parse($_FILES['file'] ?? [], csv_upload_receipt_spec());
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            // В проде поступления и номенклатура живут в одной БД wms.
            // Поставщик — из колонки «Поставщик» файла (нового добавляем в справочник).
            $hdr = $parsed['header'] ?? [];
            $sup = csv_upload_resolve_contractor($pdo, (string)($hdr['supplier'] ?? ''));
            $r = csv_upload_import_receipt($pdo, $pdo, $parsed['rows'],
                $sup['id'], (int)($_POST['warehouse_id'] ?? 0), 1,
                (string)($_FILES['file']['name'] ?? 'upload.csv'));
            $r += ['supplier' => $sup['name'], 'supplier_new' => $sup['created']];
            http_response_code(201);
            echo json_encode($r + ['row_errors' => $parsed['errors']], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'mark_docs') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../shared/CsvUpload.php';
    csv_upload_ensure_receipt_marks($pdo);
    $in = "'expected','arrived','unloading','on_stock','ready_accept','accepting','placing','draft'";
    echo json_encode($pdo->query(
        "SELECT r.id, r.doc_no, r.status,
                (SELECT COUNT(*) FROM receipt_items WHERE receipt_id = r.id) AS items,
                (SELECT COUNT(*) FROM receipt_marks WHERE receipt_id = r.id) AS marks
         FROM receipts r WHERE r.status IN ($in) ORDER BY r.id DESC LIMIT 100"
    )->fetchAll(), JSON_UNESCAPED_UNICODE);
} elseif ($action === 'csv_marks' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../shared/CsvUpload.php';
    try {
        $parsed = csv_upload_parse_marks($_FILES['file'] ?? []);
        if (!$parsed['rows']) {
            http_response_code(422);
            echo json_encode(['error' => implode('; ', $parsed['errors']) ?: 'Нет корректных строк'], JSON_UNESCAPED_UNICODE);
        } else {
            $r = csv_upload_import_marks($pdo, (int)($_POST['receipt_id'] ?? 0), $parsed['rows'], 1,
                (string)($_FILES['file']['name'] ?? 'marks.csv'));
            $r['row_errors'] = array_merge($parsed['errors'], $r['row_errors']);
            http_response_code(201);
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->destroy((int)($_GET['id'] ?? 0));
} elseif ($action === 'export') {
    $controller->export($_GET);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
}
