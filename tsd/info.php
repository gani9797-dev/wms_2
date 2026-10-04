<?php
declare(strict_types=1);
// ТСД «Информация»: справки по товару (SSCC/штрихкод), честному знаку, ячейке, отгрузке.
// Только чтение. Авторизация — как в остальных точках ТСД.

const INFO_TOKEN = 'tsd-12345';

function info_ok(): bool
{
    $t = $_GET['token'] ?? $_POST['token'] ?? '';
    if ($t === '') {
        $h = $_SERVER['HTTP_X_TSD_TOKEN'] ?? '';
        if ($h !== '') {
            $t = $h;
        } else {
            $a = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (str_starts_with($a, 'Bearer ')) {
                $t = substr($a, 7);
            }
        }
    }
    if ($t !== '' && hash_equals(INFO_TOKEN, (string)$t)) {
        return true;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    return isset($_SESSION['wms_auth_user']);
}

function ij(mixed $d, int $c = 200): void
{
    http_response_code($c);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!info_ok()) {
    ij(['error' => 'unauthorized: нужен ?token= или вход через /login'], 401);
}
require_once __DIR__ . '/../shared/db.php';

function idb(string $kind): ?PDO
{
    try {
        if ($kind === 'rcp') {
            return wms_receipts_pdo();
        }
        if ($kind === 'nom') {
            return wms_nomenclature_pdo();
        }
        if ($kind === 'cell') {
            return wms_storage_pdo();
        }
        if ($kind === 'ship') {
            $f = __DIR__ . '/../shipments/wms_demo.sqlite';
            if (!is_file($f)) {
                return null;
            }
            $pdo = new PDO('sqlite:' . $f, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        }
    } catch (Throwable) {
    }
    return null;
}

$action = $_GET['action'] ?? '';
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' && $action !== '') {
    ij(['error' => 'пустой запрос: отсканируй код'], 422);
}

// --- По товару: SSCC (паллета) и/или штрихкод/артикул ---
if ($action === 'product') {
    $sections = [];
    // Паллета по SSCC.
    try {
        $rcp = idb('rcp');
        if ($rcp) {
            $p = $rcp->prepare("SELECT sscc, status, qty_total, weight, receipt_id, created_at, closed_at FROM tsd_pallets WHERE sscc = :s LIMIT 5");
            $p->execute([':s' => $q]);
            foreach ($p->fetchAll() as $r) {
                $sections[] = ['title' => 'Паллета ' . $r['sscc'], 'rows' => [
                    ['Статус', $r['status']], ['Кол-во, шт', $r['qty_total']], ['Вес, кг', $r['weight'] ?? '—'],
                    ['Документ прихода', $r['receipt_id']], ['Открыта', $r['created_at'] ?? '—'], ['Закрыта', $r['closed_at'] ?? '—'],
                ]];
            }
            $st = $rcp->prepare("SELECT receipt_id, pallet, qty FROM tsd_stock WHERE pallet = :s OR sku = :s LIMIT 20");
            $st->execute([':s' => $q]);
            foreach ($st->fetchAll() as $r) {
                $sections[] = ['title' => 'Остаток на паллете', 'rows' => [
                    ['Документ', $r['receipt_id']], ['Паллета', $r['pallet'] ?? '—'], ['Кол-во', $r['qty']],
                ]];
            }
        }
    } catch (Throwable) {
    }
    // Товар по штрихкоду/артикулу из номенклатуры.
    try {
        $nom = idb('nom');
        if ($nom) {
            $pid = null;
            $bp = $nom->prepare("SELECT product_id FROM product_barcodes WHERE barcode = :s LIMIT 1");
            $bp->execute([':s' => $q]);
            $pid = $bp->fetchColumn();
            if (!$pid) {
                $sp = $nom->prepare("SELECT id FROM products WHERE sku = :s LIMIT 1");
                $sp->execute([':s' => $q]);
                $pid = $sp->fetchColumn();
            }
            if ($pid) {
                $pr = $nom->prepare("SELECT sku, name, unit, category, length_mm, width_mm, height_mm, weight_g, qty FROM products WHERE id = :id");
                $pr->execute([':id' => $pid]);
                $p = $pr->fetch();
                if ($p) {
                    $dims = ($p['length_mm'] ?? null) !== null
                        ? ($p['length_mm'] . '×' . $p['width_mm'] . '×' . $p['height_mm'] . ' мм, ' . $p['weight_g'] . ' г')
                        : 'нет обмеров';
                    $rows = [['Артикул', $p['sku']], ['Наименование', $p['name']], ['Ед.', $p['unit'] ?? 'шт'],
                        ['Категория', $p['category'] ?? '—'], ['Обмеры', $dims], ['Остаток, шт', $p['qty'] ?? 0]];
                    try {
                        $sp2 = $nom->prepare("SELECT warehouse_id, cell, pallet, qty FROM product_stock WHERE product_id = :p LIMIT 20");
                        $sp2->execute([':p' => $pid]);
                        $cells = [];
                        foreach ($sp2->fetchAll() as $s) {
                            $cells[] = ($s['cell'] ?? '?') . ' (' . $s['qty'] . ')';
                        }
                        if ($cells) {
                            $rows[] = ['Ячейки', implode(', ', $cells)];
                        }
                    } catch (Throwable) {
                    }
                    $sections[] = ['title' => 'Товар', 'rows' => $rows];
                }
            }
        }
    } catch (Throwable) {
    }
    if (!$sections) {
        ij(['ok' => true, 'sections' => [], 'empty' => 'Ничего не найдено: ни паллеты, ни товара с таким кодом']);
    }
    ij(['ok' => true, 'sections' => $sections]);
}

// --- По честному знаку: где марка сканировалась + чего ждем ---
if ($action === 'mark') {
    $sections = [];
    try {
        $rcp = idb('rcp');
        if ($rcp) {
            $f = $rcp->prepare("SELECT receipt_id, sku, status, reason, flag_date FROM receipt_flags WHERE marking_code = :m ORDER BY id DESC LIMIT 20");
            $f->execute([':m' => $q]);
            foreach ($f->fetchAll() as $r) {
                $sections[] = ['title' => 'Скан марки', 'rows' => [
                    ['Документ прихода', $r['receipt_id']], ['Артикул', $r['sku'] ?? '—'],
                    ['Статус', $r['status'] ?? '—'], ['Причина', $r['reason'] ?? '—'], ['Дата', $r['flag_date'] ?? '—'],
                ]];
            }
            try {
                $e = $rcp->prepare("SELECT receipt_id, sku, status FROM receipt_marks WHERE marking_code = :m LIMIT 20");
                $e->execute([':m' => $q]);
                foreach ($e->fetchAll() as $r) {
                    $sections[] = ['title' => 'Ожидаемая марка', 'rows' => [
                        ['Документ прихода', $r['receipt_id']], ['Артикул', $r['sku']], ['Статус', $r['status']],
                    ]];
                }
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
    if (!$sections) {
        ij(['ok' => true, 'sections' => [], 'empty' => 'Марка не найдена: ни в сканах, ни в файлах марок']);
    }
    ij(['ok' => true, 'sections' => $sections]);
}

// --- По ячейке: статус, остаток, содержимое ---
if ($action === 'cell') {
    $sections = [];
    try {
        $cell = idb('cell');
        if ($cell) {
            $c = $cell->prepare("SELECT code, zone, cell_type, category, status, capacity, qty, pallet_count, is_locked FROM storage_cells WHERE code = :c LIMIT 1");
            $c->execute([':c' => $q]);
            $row = $c->fetch();
            if ($row) {
                $sections[] = ['title' => 'Ячейка ' . $row['code'], 'rows' => [
                    ['Зона', $row['zone'] ?? '—'], ['Тип', $row['cell_type'] ?? '—'], ['Категория', $row['category'] ?? '—'],
                    ['Статус', $row['status'] ?? '—'], ['Вместимость', $row['capacity'] ?? 0], ['Остаток', $row['qty'] ?? 0],
                    ['Паллет', $row['pallet_count'] ?? 0], ['Блокировка', ((int)($row['is_locked'] ?? 0) === 1) ? 'Да' : 'Нет'],
                ]];
                try {
                    $cc = $cell->prepare("SELECT sku, name, qty FROM cell_content WHERE cell_id = (SELECT id FROM storage_cells WHERE code = :c LIMIT 1) LIMIT 20");
                    $cc->execute([':c' => $q]);
                    foreach ($cc->fetchAll() as $r) {
                        $sections[] = ['title' => 'Содержимое', 'rows' => [
                            ['Артикул', $r['sku']], ['Наименование', $r['name'] ?? '—'], ['Кол-во', $r['qty']],
                        ]];
                    }
                } catch (Throwable) {
                }
            }
        }
    } catch (Throwable) {
    }
    if (!$sections) {
        ij(['ok' => true, 'sections' => [], 'empty' => 'Ячейка не найдена']);
    }
    ij(['ok' => true, 'sections' => $sections]);
}

// --- По отгрузке: шапка + итоги позиций ---
if ($action === 'shipment') {
    $sections = [];
    try {
        $sh = idb('ship');
        if ($sh) {
            $s = $sh->prepare("SELECT id, doc_no, status, consignee, warehouse_id, total, items_count, sku_count, qty_total, plan_shipment_date FROM shipments WHERE doc_no = :d LIMIT 1");
            $s->execute([':d' => $q]);
            $row = $s->fetch();
            if (!$row) {
                $s = $sh->prepare("SELECT id, doc_no, status, consignee, warehouse_id, total, items_count, sku_count, qty_total, plan_shipment_date FROM shipments WHERE doc_no LIKE :d ORDER BY id DESC LIMIT 1");
                $s->execute([':d' => '%' . $q . '%']);
                $row = $s->fetch();
            }
            if ($row) {
                $sections[] = ['title' => 'Отгрузка ' . $row['doc_no'], 'rows' => [
                    ['Статус', $row['status']], ['Грузополучатель', $row['consignee'] ?? '—'],
                    ['Склад', $row['warehouse_id']], ['Сумма', $row['total'] ?? 0],
                    ['Позиций', $row['items_count'] ?? 0], ['SKU', $row['sku_count'] ?? 0], ['Штук', $row['qty_total'] ?? 0],
                    ['План. отгрузка', $row['plan_shipment_date'] ?? '—'],
                ]];
            }
        }
    } catch (Throwable) {
    }
    if (!$sections) {
        ij(['ok' => true, 'sections' => [], 'empty' => 'Отгрузка не найдена']);
    }
    ij(['ok' => true, 'sections' => $sections]);
}

ij(['error' => 'unknown action: ?action=product|mark|cell|shipment&q=...'], 404);
