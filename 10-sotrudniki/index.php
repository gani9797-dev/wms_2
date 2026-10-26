<?php
declare(strict_types=1);
// Точка входа-пример: реестр сотрудников + карточка. Запуск: php -S localhost:8000 index.php
// GET  /?action=list&search=&page=1
// GET  /?action=show|schedules|access|history|exits|defaults|print&id=1
// POST /?action=store|update|delete|block|unblock|tsd|unplanned|access_save|access_copy|schedule_add|schedule_delete
// GET  /?action=export
// Схема таблиц — в schema.sql модуля. Для Laravel: перенести логику в routes/api.php,
// здесь только демонстрация wiring.

require_once __DIR__ . '/EmployeeFilter.php';
require_once __DIR__ . '/EmployeeRepository.php';
require_once __DIR__ . '/EmployeeService.php';
require_once __DIR__ . '/EmployeeStatus.php';
require_once __DIR__ . '/EmployeeAccess.php';
require_once __DIR__ . '/EmployeeController.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=wms;charset=utf8mb4',
    'wms_user',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$repo = new EmployeeRepository($pdo);
$service = new EmployeeService($pdo, $repo);
// $userId берите из сессии/JWT; в макете это "Иванов И.И., Администратор".
$userId = 1;
$controller = new EmployeeController($repo, $service, $userId);

$action = (string)($_GET['action'] ?? 'list');
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($_GET['id'] ?? 0);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    if ($action === 'list') {
        $controller->index($_GET);
    } elseif ($action === 'show') {
        $controller->show($id);
    } elseif ($action === 'store' && $isPost) {
        $controller->store($body);
    } elseif ($action === 'update' && $isPost) {
        $controller->update($id, $body);
    } elseif ($action === 'delete' && $isPost) {
        $controller->delete($id);
    } elseif ($action === 'block' && $isPost) {
        $controller->block($id);
    } elseif ($action === 'unblock' && $isPost) {
        $controller->unblock($id);
    } elseif ($action === 'tsd' && $isPost) {
        $controller->tsd($id, $body);
    } elseif ($action === 'unplanned' && $isPost) {
        $controller->unplanned($id, $body);
    } elseif ($action === 'access') {
        $controller->access($id);
    } elseif ($action === 'access_save' && $isPost) {
        $controller->accessSave($id, $body);
    } elseif ($action === 'access_copy' && $isPost) {
        $controller->accessCopy($id, $body);
    } elseif ($action === 'schedules') {
        $controller->schedules($id);
    } elseif ($action === 'schedule_add' && $isPost) {
        $controller->scheduleAdd($id, $body);
    } elseif ($action === 'schedule_delete' && $isPost) {
        $controller->scheduleDelete((int)($body['schedule_id'] ?? $_GET['schedule_id'] ?? 0));
    } elseif ($action === 'history') {
        $controller->history($id);
    } elseif ($action === 'exits') {
        $controller->exits($id);
    } elseif ($action === 'defaults') {
        $controller->defaults();
    } elseif ($action === 'print') {
        $controller->print($id, $_GET);
    } elseif ($action === 'export') {
        $controller->export($_GET);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
