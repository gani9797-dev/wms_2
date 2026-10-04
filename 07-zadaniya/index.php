<?php
declare(strict_types=1);

// Точка входа-пример: реестр + детализация. Запуск: php -S localhost:8000 index.php
// GET /?action=list&search=TASK&status[]=work&page=1
// GET /?action=show&id=1  (шапка + items)
// GET /?action=items&id=1&page=1  (Детализация по заданию)
// Для Laravel: перенести логику в routes/api.php, здесь только демонстрация wiring.

require_once __DIR__ . '/TaskFilter.php';
require_once __DIR__ . '/TaskRepository.php';
require_once __DIR__ . '/TaskService.php';
require_once __DIR__ . '/TaskStatus.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=wms;charset=utf8mb4',
    'wms_user',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$repo = new TaskRepository($pdo);
$service = new TaskService($pdo);
// $userId берите из сессии/JWT; в макете это "Иванов И.И., Администратор".
$userId = 1;

$action = $_GET['action'] ?? 'list';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        echo json_encode($repo->paginate(TaskFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'show') {
        $row = $repo->findById((int)($_GET['id'] ?? 0));
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($row, JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'items') {
        echo json_encode($repo->items((int)($_GET['id'] ?? 0), (int)($_GET['page'] ?? 1), (int)($_GET['per_page'] ?? 50)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'history') {
        echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'documents') {
        echo json_encode($repo->documents((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->create($body, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->changeStatus((int)($_GET['id'] ?? 0), TaskStatus::from($body['to'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'pause' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->pause((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'restart' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->restart((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'priority' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->priority((int)($_GET['id'] ?? 0), (string)($body['priority'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->assign((int)($_GET['id'] ?? 0), isset($body['executor_id']) ? (int)$body['executor_id'] : null, (string)($body['executor_fio'] ?? $body['executor'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'auto' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->autoRun((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'pick' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // ТСД-скан: {item_id, qty}
        $itemId = (int)($body['item_id'] ?? $_GET['item_id'] ?? 0);
        $qty = (float)($body['qty'] ?? 1);
        if ($itemId <= 0) {
            throw new RuntimeException('нужен item_id');
        }
        $service->pick($itemId, $qty, $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'print') {
        $pId = (int)($_GET['id'] ?? 0);
        $pKind = (string)($_GET['kind'] ?? 'list');
        if (($_GET['format'] ?? '') === 'pdf') {
            require_once __DIR__ . '/../shared/Pdf.php';
            try {
                wms_pdf_output(wms_pdf_auto($service->printData($pId, $pKind)), wms_pdf_filename($pKind, $pId));
            } catch (Throwable $e) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка PDF: ' . $e->getMessage();
            }
            exit;
        }
        echo json_encode($service->printData($pId, $pKind), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'export') {
        $f = TaskFilter::fromArray($_GET);
        $f->page = 1;
        $f->perPage = 5000;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="tasks.csv"');
        echo $service->exportCsv($f, $repo);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
