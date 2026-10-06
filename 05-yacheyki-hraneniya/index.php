<?php
declare(strict_types=1);

// Точка входа-пример: реестр + карточка ячеек хранения. Запуск: php -S localhost:8000 index.php
// GET /?action=list&search=A3&page=1
// GET /?action=show|content|history|movements|tasks&id=1
// POST /?action=store|update|copy|block|unblock|archive|move|mass|import&id=1
// Для Laravel: перенести логику в routes/api.php, здесь только демонстрация wiring.

require_once __DIR__ . '/CellFilter.php';
require_once __DIR__ . '/CellRepository.php';
require_once __DIR__ . '/CellService.php';
require_once __DIR__ . '/CellPlacement.php';
require_once __DIR__ . '/CellStatus.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=wms;charset=utf8mb4',
    'wms_user',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$repo = new CellRepository($pdo);
$service = new CellService($pdo);
// $userId берите из сессии/JWT; в макете это "Иванов И.И., Администратор".
$userId = 1;

$action = $_GET['action'] ?? 'list';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        echo json_encode($repo->paginate(CellFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'show') {
        $row = $repo->findById((int)($_GET['id'] ?? 0));
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($row, JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'content') {
        echo json_encode($repo->content((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'history') {
        echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'movements') {
        echo json_encode($repo->movements((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'tasks') {
        echo json_encode($repo->tasks((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'suggest') {
        $pt = (string)($_GET['pallet_type'] ?? $_GET['pallet'] ?? 'euro');
        $h = isset($_GET['height_mm']) && $_GET['height_mm'] !== '' ? (int)$_GET['height_mm'] : null;
        $w = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
        $s = CellPlacement::suggest($pdo, $pt, $h, $w);
        if (!$s) {
            http_response_code(404);
            echo json_encode(['error' => 'Подходящая ячейка не найдена (высота/тара/секция)'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'pallet_type' => CellPlacement::normalizePalletType($pt),
                'height_mm' => $h, 'cell' => $s['cell'], 'section' => $s['section'],
                'hint' => 'Приоритет: добивка непустых секций до полной'], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'place' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pt = (string)($body['pallet_type'] ?? $body['pallet'] ?? 'euro');
        $h = isset($body['height_mm']) && $body['height_mm'] !== '' ? (int)$body['height_mm'] : null;
        $w = isset($body['warehouse_id']) && $body['warehouse_id'] !== '' ? (int)$body['warehouse_id'] : null;
        $sscc = trim((string)($body['sscc'] ?? ''));
        $cid = (int)($body['cell_id'] ?? 0);
        if ($cid <= 0) {
            $s = CellPlacement::suggest($pdo, $pt, $h, $w);
            if (!$s) {
                http_response_code(404);
                echo json_encode(['error' => 'Подходящая ячейка не найдена (высота/тара/секция)'], JSON_UNESCAPED_UNICODE);
            } else {
                $r = CellPlacement::occupy($pdo, (int)$s['cell']['id'], $pt, ($sscc !== '' ? $sscc : null), $userId);
                http_response_code(201);
                echo json_encode(['ok' => true, 'cell' => $r['cell'], 'section' => $s['section'],
                    'temp_blocked' => $r['temp_blocked'], 'section_tuned' => $r['section_tuned']], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $r = CellPlacement::occupy($pdo, $cid, $pt, ($sscc !== '' ? $sscc : null), $userId);
            http_response_code(201);
            echo json_encode(['ok' => true, 'cell' => $r['cell'],
                'temp_blocked' => $r['temp_blocked'], 'section_tuned' => $r['section_tuned']], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->create($body, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'bulk_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = $service->bulkCreate($body, $userId);
        http_response_code(201);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->update((int)($_GET['id'] ?? 0), $body, $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->copy((int)($_GET['id'] ?? 0), $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->block((int)($_GET['id'] ?? 0), $userId, (string)($body['reason'] ?? ''));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'unblock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->unblock((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->archive((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Одиночное: POST ?action=delete&id=1 · Массовое: POST ?action=delete {"ids":[...]}
        $ids = $body['ids'] ?? $body['id'] ?? null;
        if (is_array($ids)) {
            echo json_encode($service->deleteMany($ids), JSON_UNESCAPED_UNICODE);
        } else {
            $id = (int)($ids ?? $_GET['id'] ?? 0);
            $service->delete($id);
            echo json_encode(['ok' => true, 'deleted' => [$id]], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $r = $service->moveGoods(
            (int)($_GET['id'] ?? 0),
            trim((string)($body['to_cell'] ?? '')),
            trim((string)($body['sku'] ?? '')),
            (float)($body['qty'] ?? 0),
            $userId,
            trim((string)($body['doc_no'] ?? ''))
        );
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'mass' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $n = $service->massUpdate((array)($body['ids'] ?? []), (array)($body['patch'] ?? []), $userId);
        echo json_encode(['updated' => $n], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'print') {
        $pId = (int)($_GET['id'] ?? 0);
        $pKind = (string)($_GET['kind'] ?? 'label');
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
        $f = CellFilter::fromArray($_GET);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="storage_cells.csv"');
        echo $service->exportCsv($f, $repo);
        exit;
    } elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rows = $body['rows'] ?? [];
        echo json_encode($service->importRows($rows, $userId), JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
