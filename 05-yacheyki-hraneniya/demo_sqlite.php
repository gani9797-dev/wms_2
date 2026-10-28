<?php
declare(strict_types=1);
// Демо "Ячейки хранения" без MySQL: SQLite + те же классы реестра.
// Открыть: /05-yacheyki-hraneniya/demo_sqlite.php?action=list
// Вкладки: ?action=show|content|history|movements|tasks&id=1
// ПКМ: POST ?action=store|update|copy|block|unblock|archive|delete|move|mass&id=1
// Печать этикетки: ?action=print&id=1

require_once __DIR__ . '/CellStatus.php';
require_once __DIR__ . '/CellFilter.php';
require_once __DIR__ . '/CellRepository.php';
require_once __DIR__ . '/CellService.php';
require_once __DIR__ . '/CellPlacement.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/StorageZones.php';

$pdo = wms_storage_pdo();
wms_storage_zones_ensure($pdo);
$userId = 1;

// --- Сид из схемы 5, если пусто ---
if ((int)$pdo->query("SELECT COUNT(*) FROM storage_cells")->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO warehouses (id,name) VALUES (1,'Основной склад')");
    // Сотрудники — в общей базе (shared/wms_users.sqlite), сиды там.

    // [code, wh, zone, type, category, status, capacity, qty, reserved, pallets, building, row, level, place, x, y, temp_from, temp_to, locked, comment]
    $rows = [
        ['A3-100-06-1', 1, 'storage', 'pallet', 'КГТ', 'free', 160, 0, 0, 0, 'A3', '100', '06', '1', 10, 25, 2, 6, 0, null],
        ['A3-100-06-2', 1, 'storage', 'pallet', 'Спорт инвентарь', 'busy', 160, 120, 0, 1, 'A3', '100', '06', '2', 10, 26, -18, -14, 0, 'Провести инвент'],
        ['B2-080-03-2', 1, 'storage', 'pallet', 'КГТ', 'busy', 250, 250, 0, 2, 'B2', '080', '03', '2', 20, 12, 2, 6, 0, null],
        ['B2-080-03-3', 1, 'storage', 'pallet', 'КГТ', 'free', 250, 0, 0, 0, 'B2', '080', '03', '3', 20, 13, 2, 6, 0, null],
        ['C1-120-01-3', 1, 'shipment', 'pallet', 'Косметика', 'reserved', 160, 80, 80, 1, 'C1', '120', '01', '3', 30, 5, 2, 6, 0, null],
        ['C1-120-01-4', 1, 'shipment', 'pallet', 'Косметика', 'free', 160, 0, 0, 0, 'C1', '120', '01', '4', 30, 6, 2, 6, 0, null],
        ['D4-003-05-4', 1, 'storage', 'box', 'КГТ', 'free', 135, 0, 0, 0, 'D4', '003', '05', '4', 40, 8, 15, 25, 0, null],
        ['D4-003-05-3', 1, 'storage', 'box', 'КГТ', 'busy', 135, 45, 0, 0, 'D4', '003', '05', '3', 40, 7, 15, 25, 0, null],
        ['E5-001-09-1', 1, 'storage', 'pallet', 'Бытовая химия', 'free', 200, 0, 0, 0, 'E5', '001', '09', '1', 50, 1, 15, 25, 0, null],
        ['E5-001-09-2', 1, 'storage', 'pallet', 'Бытовая химия', 'free', 200, 0, 0, 0, 'E5', '001', '09', '2', 50, 2, 15, 25, 0, null],
        ['E5-001-09-3', 1, 'storage', 'pallet', 'Бытовая химия', 'blocked', 200, 20, 0, 1, 'E5', '001', '09', '3', 50, 3, 15, 25, 1, 'Инвентаризация: ячейка закрыта'],
        ['E5-002-09-1', 1, 'storage', 'pallet', 'Бытовая химия', 'free', 200, 0, 0, 0, 'E5', '002', '09', '1', 51, 1, 2, 6, 0, null],
        ['E5-002-09-2', 1, 'storage', 'pallet', 'Бытовая химия', 'free', 200, 0, 0, 0, 'E5', '002', '09', '2', 51, 2, 2, 6, 0, null],
        ['E5-002-09-3', 1, 'storage', 'pallet', 'Бытовая химия', 'free', 200, 0, 0, 0, 'E5', '002', '09', '3', 51, 3, 2, 6, 0, null],
    ];
    $ins = $pdo->prepare(
        "INSERT INTO storage_cells (code,warehouse_id,zone,cell_type,category,status,capacity,qty,reserved_qty,pallet_count,
          building,row_no,level_no,place_no,coord_x,coord_y,temp_from,temp_to,is_locked,comment)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($rows as $r) {
        $ins->execute($r);
    }

    // Содержимое: A3-100-06-2 ← Мяч баскетбольный А, 120 шт (пример вкладки "Содержимое").
    $cid = (int)$pdo->query("SELECT id FROM storage_cells WHERE code='A3-100-06-2'")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO cell_content (cell_id,product_code,sku,name,unit,qty,reserved_qty,barcode,pallet) VALUES
         ($cid,'T-101','MK-ASM-1315','Мяч баскетбольный А','шт',120,0,'2178899952100',1)"
    )->execute();
    $pdo->exec(
        "INSERT INTO cell_history (cell_id,user_id,user_fio,action,field,field_label,old_value,new_value,created_at) VALUES
         ($cid,1788,'1788 - Смирнов Николай','update','Создание','Создание',NULL,'A3-100-06-2','2025-06-01 10:00:00')"
    );
    $pdo->exec(
        "INSERT INTO cell_movements (cell_id,doc_date,doc_type,doc_no,sku,qty_change,balance,cell_from,cell_to) VALUES
         ($cid,'2025-06-01 10:05:00','place','Размещение №РЗ-12','MK-ASM-1315',120,120,NULL,'A3-100-06-2')"
    );
    $blk = (int)$pdo->query("SELECT id FROM storage_cells WHERE code='E5-001-09-3'")->fetchColumn();
    $pdo->exec(
        "INSERT INTO cell_history (cell_id,user_id,user_fio,action,field,field_label,old_value,new_value,created_at) VALUES
         ($blk,1,'Иванов И.И.','update','is_locked','Блокировка','Нет','Да (Инвентаризация: ячейка закрыта)','2025-08-20 09:00:00')"
    );
}

$repo = new CellRepository($pdo);
$service = new CellService($pdo);
$action = $_GET['action'] ?? 'list';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        echo json_encode($repo->paginate(CellFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'show') {
        $row = $repo->findById((int)($_GET['id'] ?? 0));
        if (!$row) {
            http_response_code(404);
        }
        echo json_encode($row ?? ['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'content') {
        echo json_encode($repo->content((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'history') {
        echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'movements') {
        echo json_encode($repo->movements((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'tasks') {
        echo json_encode($repo->tasks((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'zones') {
        echo json_encode(['rows' => wms_storage_zone_rows($pdo, true)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'suggest') {
        // Онлайн-подбор ячейки: ?pallet_type=euro|fin&height_mm=1500&warehouse_id=1&contractor_id=2
        $pt = (string)($_GET['pallet_type'] ?? $_GET['pallet'] ?? 'euro');
        $h = isset($_GET['height_mm']) && $_GET['height_mm'] !== '' ? (int)$_GET['height_mm'] : null;
        $w = isset($_GET['warehouse_id']) && $_GET['warehouse_id'] !== '' ? (int)$_GET['warehouse_id'] : null;
        $cid = isset($_GET['contractor_id']) && $_GET['contractor_id'] !== '' ? (int)$_GET['contractor_id'] : null;
        $s = CellPlacement::suggest($pdo, $pt, $h, $w, null, $cid);
        if (!$s) {
            http_response_code(404);
            echo json_encode(['error' => 'Подходящая ячейка не найдена (высота/тара/секция/зоны клиента)'], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => true, 'pallet_type' => CellPlacement::normalizePalletType($pt),
                'height_mm' => $h, 'cell' => $s['cell'], 'section' => $s['section'],
                'placement' => $s['placement'],
                'hint' => 'Приоритет: неполные секции, затем близость к клиенту'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    } elseif ($action === 'place' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Занять ячейку под паллету: {cell_id?, pallet_type, height_mm?, sscc?, warehouse_id?, contractor_id?}
        // Без cell_id — сначала онлайн-подбор, затем резерв.
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $pt = (string)($b['pallet_type'] ?? $b['pallet'] ?? 'euro');
        $h = isset($b['height_mm']) && $b['height_mm'] !== '' ? (int)$b['height_mm'] : null;
        $w = isset($b['warehouse_id']) && $b['warehouse_id'] !== '' ? (int)$b['warehouse_id'] : null;
        $clientId = isset($b['contractor_id']) && $b['contractor_id'] !== '' ? (int)$b['contractor_id'] : null;
        $sscc = trim((string)($b['sscc'] ?? ''));
        $cid = (int)($b['cell_id'] ?? 0);
        if ($cid <= 0) {
            $s = CellPlacement::suggest($pdo, $pt, $h, $w, null, $clientId);
            if (!$s) {
                http_response_code(404);
                echo json_encode(['error' => 'Подходящая ячейка не найдена (высота/тара/секция/зоны клиента)'], JSON_UNESCAPED_UNICODE);
            } else {
                $r = CellPlacement::occupy($pdo, (int)$s['cell']['id'], $pt, ($sscc !== '' ? $sscc : null), $userId);
                http_response_code(201);
                echo json_encode(['ok' => true, 'cell' => $r['cell'], 'section' => $s['section'],
                    'placement' => $s['placement'],
                    'temp_blocked' => $r['temp_blocked'], 'section_tuned' => $r['section_tuned']], JSON_UNESCAPED_UNICODE);
            }
        } else {
            $r = CellPlacement::occupy($pdo, $cid, $pt, ($sscc !== '' ? $sscc : null), $userId);
            http_response_code(201);
            echo json_encode(['ok' => true, 'cell' => $r['cell'],
                'temp_blocked' => $r['temp_blocked'], 'section_tuned' => $r['section_tuned']], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'sync_temp' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $n = CellPlacement::syncTempBlocks($pdo, (int)($b['warehouse_id'] ?? $_GET['warehouse_id'] ?? 1),
            $b['building'] ?? $_GET['building'] ?? null, $b['sec'] ?? $b['row_no'] ?? $_GET['sec'] ?? null,
            $b['level'] ?? $b['level_no'] ?? $_GET['level'] ?? null);
        echo json_encode(['ok' => true, 'released' => $n], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $id = $service->create($b, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'bulk_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $r = $service->bulkCreate($b, $userId);
        http_response_code(201);
        echo json_encode($r, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $service->update((int)($_GET['id'] ?? 0), $b, $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->copy((int)($_GET['id'] ?? 0), $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $service->block((int)($_GET['id'] ?? 0), $userId, (string)($b['reason'] ?? ''));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'unblock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->unblock((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->archive((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Одиночное: POST ?action=delete&id=1 · Массовое: POST ?action=delete {"ids":[...]}
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = $b['ids'] ?? $b['id'] ?? null;
        if (is_array($ids)) {
            echo json_encode($service->deleteMany($ids), JSON_UNESCAPED_UNICODE);
        } else {
            $id = (int)($ids ?? $_GET['id'] ?? 0);
            $service->delete($id);
            echo json_encode(['ok' => true, 'deleted' => [$id]], JSON_UNESCAPED_UNICODE);
        }
    } elseif ($action === 'move' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $r = $service->moveGoods(
            (int)($_GET['id'] ?? 0),
            trim((string)($b['to_cell'] ?? '')),
            trim((string)($b['sku'] ?? '')),
            (float)($b['qty'] ?? 0),
            $userId,
            trim((string)($b['doc_no'] ?? ''))
        );
        echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'mass' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $n = $service->massUpdate((array)($b['ids'] ?? []), (array)($b['patch'] ?? []), $userId);
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
        echo json_encode($service->printData($pId, $pKind), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'export') {
        $f = CellFilter::fromArray($_GET);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="storage_cells.csv"');
        echo $service->exportCsv($f, $repo);
        exit;
    } elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rows = [];
        if (!empty($_FILES['file']['tmp_name'])) {
            $txt = file_get_contents($_FILES['file']['tmp_name']);
            $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
            $lines = preg_split('/\r?\n/', trim($txt));
            $head = str_getcsv(array_shift($lines) ?: '', ';');
            foreach ($lines as $ln) {
                if (!trim($ln)) {
                    continue;
                }
                $row = str_getcsv($ln, ';');
                $assoc = [];
                foreach ($head as $i => $h) {
                    $assoc[trim($h)] = $row[$i] ?? '';
                }
                $rows[] = [
                    'code' => $assoc['Адрес'] ?? '', 'warehouse_id' => 1,
                    'zone' => 'storage', 'cell_type' => 'pallet',
                    'capacity' => $assoc['Остаток'] ?? 0,
                ];
            }
        } else {
            $b = json_decode(file_get_contents('php://input'), true) ?? [];
            $rows = $b['rows'] ?? [];
        }
        echo json_encode($service->importRows($rows, $userId), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'columns_get') {
        require_once __DIR__ . '/../shared/UserColumns.php';
        echo json_encode(['user' => wms_columns_user(), 'form' => (string)($_GET['form'] ?? ''), 'cols' => wms_columns_get($pdo, (string)($_GET['form'] ?? ''))], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'columns_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/../shared/UserColumns.php';
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        try {
            wms_columns_save($pdo, (string)($b['form'] ?? ''), $b['cols'] ?? null);
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action, use ?action=list|show|content|history|movements|tasks&id=1'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
