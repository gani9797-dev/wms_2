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
