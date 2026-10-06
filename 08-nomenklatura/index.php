<?php
declare(strict_types=1);

// Точка входа-пример: реестр + карточка номенклатуры. Запуск: php -S localhost:8000 index.php
// GET /?action=list&search=MK-ASM&page=1
// GET /?action=show|stock|history|movements|documents|tasks|notif_list|notif_counts|notif_subs&id=1
// POST /?action=store|update|copy|block|unblock|archive|delete|mass|import|defaults_save|notif_read|notif_read_all|notif_sub_save|notif_sub_delete&id=1
// Для Laravel: перенести логику в routes/api.php, здесь только демонстрация wiring.

require_once __DIR__ . '/ProductFilter.php';
require_once __DIR__ . '/ProductRepository.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/ProductStatus.php';
require_once __DIR__ . '/Notifications.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=wms;charset=utf8mb4',
    'wms_user',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$repo = new ProductRepository($pdo);
$service = new ProductService($pdo);
// $userId берите из сессии/JWT; в макете это "Иванов И.И., Администратор".
$userId = 1;

$action = $_GET['action'] ?? 'list';
$body = json_decode(file_get_contents('php://input'), true) ?? [];
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'list') {
        echo json_encode($repo->paginate(ProductFilter::fromArray($_GET)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'show') {
        $row = $repo->findById((int)($_GET['id'] ?? 0));
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Не найдено'], JSON_UNESCAPED_UNICODE); }
        else echo json_encode($row, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'stock') {
        echo json_encode($repo->stock((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'stock_by_receipt') {
        echo json_encode($repo->stockByReceipt((string)($_GET['doc_no'] ?? '')), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'sku_reserve') {
        // В каких отгрузках артикул в резерве (в проде задания в той же БД wms).
        // Группировка по паре (отгрузка, паллета).
        require_once __DIR__ . '/../shared/PickIssue.php';
        $sku = trim((string)($_GET['sku'] ?? ''));
        $out = [];
        if ($sku !== '') {
            try {
                $tbl = wms_task_tables($pdo);
                $st = $pdo->prepare(
                    "SELECT t.doc_no AS task_no, t.doc_ref, t.status,
                            i.cell_from, i.pallet, i.plan_qty, i.fact_qty
                     FROM {$tbl['i']} i JOIN {$tbl['t']} t ON t.id = i.task_id
                     WHERE i.sku = :s AND t.flags LIKE '%pick%' AND t.status IN ('new','work','paused')"
                );
                $st->execute([':s' => $sku]);
                $byShip = [];
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $need = max(0, (float)$r['plan_qty'] - (float)$r['fact_qty']);
                    if ($need <= 0) {
                        continue;
                    }
                    $pal = trim((string)($r['pallet'] ?? ''));
                    foreach (explode(',', (string)($r['doc_ref'] ?? '')) as $dn) {
                        $dn = trim($dn);
                        if ($dn === '') {
                            continue;
                        }
                        $key = $dn . "\x00" . $pal;
                        if (!isset($byShip[$key])) {
                            $byShip[$key] = ['doc_no' => $dn, 'pallet' => ($pal !== '' ? $pal : null), 'tasks' => [], 'qty' => 0.0];
                        }
                        if (!in_array($r['task_no'], $byShip[$key]['tasks'], true)) {
                            $byShip[$key]['tasks'][] = $r['task_no'];
                        }
                        $byShip[$key]['qty'] += $need;
                    }
                }
                ksort($byShip);
                $out = array_values($byShip);
            } catch (Throwable) {
            }
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'history') {
        echo json_encode($repo->history((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'movements') {
        echo json_encode($repo->movements((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'documents') {
        echo json_encode($repo->documents((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'tasks') {
        echo json_encode($repo->tasks((int)($_GET['id'] ?? 0)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'defaults') {
        echo json_encode($repo->defaults(), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'defaults_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $role = (string)($body['role'] ?? $_GET['role'] ?? 'admin');
        unset($body['role']);
        echo json_encode(['saved' => $service->saveDefaults($body, $userId, $role)], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'store' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->create($body, $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->update((int)($_GET['id'] ?? 0), $body, $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'copy' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = $service->copy((int)($_GET['id'] ?? 0), $userId);
        http_response_code(201);
        echo json_encode(['id' => $id], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'block' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->block((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'unblock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->unblock((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->archive((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $service->delete((int)($_GET['id'] ?? 0), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'mass' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $n = $service->massUpdate((array)($body['ids'] ?? []), (array)($body['patch'] ?? []), $userId);
        echo json_encode(['updated' => $n], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'print') {
        $pId = (int)($_GET['id'] ?? 0);
        $pKind = (string)($_GET['kind'] ?? 'barcode');
        $pBc = isset($_GET['bc']) ? (string)$_GET['bc'] : null;
        $pFmt = (string)($_GET['format'] ?? '');
        $pW = isset($_GET['w']) ? (float)$_GET['w'] : 58.0;
        $pH = isset($_GET['h']) ? (float)$_GET['h'] : 40.0;
        $pN = isset($_GET['copies']) ? max(1, min(999, (int)$_GET['copies'])) : 1;
        $pDpi = isset($_GET['dpi']) ? (int)$_GET['dpi'] : 203;
        if ($pFmt === 'zpl') {
            require_once __DIR__ . '/../shared/Zpl.php';
            try {
                $pd = $service->printData($pId, $pKind, $pBc);
                $zpl = wms_zpl_product_barcode((string)($pd['sku'] ?? ''), (string)($pd['name'] ?? ''), (string)($pd['barcode'] ?? ''), $pW, $pH, $pN, $pDpi);
                if (!headers_sent()) {
                    header('Content-Type: text/plain; charset=utf-8');
                    header('Content-Disposition: attachment; filename="' . wms_zpl_filename((string)($pd['sku'] ?? 'barcode'), $pId) . '"');
                }
                echo $zpl;
            } catch (Throwable $e) {
                http_response_code(400);
                if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка ZPL: ' . $e->getMessage();
            }
            exit;
        }
        if ($pFmt === 'label') {
            require_once __DIR__ . '/../shared/Zpl.php';
            try {
                $pd = $service->printData($pId, $pKind, $pBc);
                if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
                echo wms_label_html((string)($pd['sku'] ?? ''), (string)($pd['name'] ?? ''), (string)($pd['barcode'] ?? ''), $pW, $pH, $pN, isset($_GET['autoprint']));
            } catch (Throwable $e) {
                http_response_code(400);
                if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка этикетки: ' . $e->getMessage();
            }
            exit;
        }
        if ($pFmt === 'pdf') {
            require_once __DIR__ . '/../shared/Pdf.php';
            try {
                $pd = $service->printData($pId, $pKind, $pBc);
                if ($pKind === 'barcode') {
                    if (($pd['barcode'] ?? '') === '') throw new RuntimeException('У товара нет штрихкода');
                    wms_pdf_output(
                        wms_pdf_product_barcode((string)($pd['sku'] ?? ''), (string)($pd['name'] ?? ''), (string)$pd['barcode']),
                        wms_pdf_filename('barcode_' . ($pd['sku'] ?? $pKind), $pId)
                    );
                } else {
                    wms_pdf_output(wms_pdf_auto($pd), wms_pdf_filename($pKind, $pId));
                }
            } catch (Throwable $e) {
                http_response_code(400);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Ошибка PDF: ' . $e->getMessage();
            }
            exit;
        }
        echo json_encode($service->printData($pId, $pKind, $pBc), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'export') {
        $f = ProductFilter::fromArray($_GET);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="nomenclature.csv"');
        echo $service->exportCsv($f, $repo);
        exit;
    } elseif ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $rows = $body['rows'] ?? [];
        echo json_encode($service->importRows($rows, $userId), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_list') {
        echo json_encode(Notifications::list($pdo, (string)($_GET['tab'] ?? 'all'), isset($_GET['search']) ? (string)$_GET['search'] : null, isset($_GET['type']) ? (string)$_GET['type'] : null, (int)($_GET['limit'] ?? 50)), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_counts') {
        echo json_encode(Notifications::counts($pdo), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_read' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        Notifications::markRead($pdo, (int)($body['id'] ?? $_GET['id'] ?? 0));
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_read_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(['updated' => Notifications::markAllRead($pdo)], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_subs') {
        echo json_encode(Notifications::subscriptions($pdo, $userId), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_sub_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        echo json_encode(Notifications::saveSubscription($pdo, $body, $userId), JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'notif_sub_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        Notifications::deleteSubscription($pdo, (string)($body['event_kind'] ?? $_GET['event_kind'] ?? ''), $userId);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
