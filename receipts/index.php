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
} elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->store($body);
} elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->copy((int)($_GET['id'] ?? 0));
} elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->changeStatus((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'priority' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->priority((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->assign((int)($_GET['id'] ?? 0), $body);
} elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->archive((int)($_GET['id'] ?? 0));
} elseif ($action === 'print') {
    $controller->print((int)($_GET['id'] ?? 0), $_GET);
} elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->import($body);
} elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->destroy((int)($_GET['id'] ?? 0));
} elseif ($action === 'export') {
    $controller->export($_GET);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
}
