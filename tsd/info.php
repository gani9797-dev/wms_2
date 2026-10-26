<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/tsd_auth.php';
// ТСД «Информация»: справки по товару (SSCC/штрихкод), честному знаку, ячейке, отгрузке.
// Только чтение. Авторизация — как в остальных точках ТСД.


function info_ok(): bool
{
    if (wms_tsd_token_ok()) {
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
            // Единая БД отгрузок — через shared/db.php (канон 03-otgruzki).
            try {
                return wms_shipments_pdo();
            } catch (Throwable) {
            }
            return null;
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
// ЕДИНЫЙ ИСТОЧНИК ПРАВДЫ (связь с модулем 8.1 «Товарный запас»):
//   живой факт приёмки  = receipt_operations (accept_status='Принято', GROUP BY sku, pallet)
//                         — тот же запрос, что в StockSync и в place_scan;
//   оприходованный остаток = product_stock в БД номенклатуры — ТА ЖЕ таблица,
//                         которую читает 08.1-tovarny-zapas/demo_sqlite.php?action=list.
// tsd_pallets.qty_total — лишь кэш на момент pallet_close (0 пока паллета открыта).
// Таблица-дубль tsd_stock удалена: её данные (= агрегат receipt_operations на момент
// fix) расходились с фактом и с 8.1, поэтому больше не пишется и не читается.
if ($action === 'product') {
    $sections = [];
    // Паллета по SSCC.
    try {
        $rcp = idb('rcp');
        if ($rcp) {
            $p = $rcp->prepare("SELECT sscc, status, qty_total, weight, receipt_id, created_at, closed_at FROM tsd_pallets WHERE sscc = :s LIMIT 5");
            $p->execute([':s' => $q]);
            $pallets = $p->fetchAll();
            // Живой факт по этой паллете: сколько реально принято (не зависит от закрытия).
            $liveBySku = [];
            $liveTotal = 0.0;
            try {
                $lv = $rcp->prepare("SELECT sku, COALESCE(SUM(qty),0) AS q FROM receipt_operations WHERE pallet = :s AND accept_status = 'Принято' GROUP BY sku");
                $lv->execute([':s' => $q]);
                foreach ($lv->fetchAll() as $lr) {
                    $sku = trim((string)($lr['sku'] ?? ''));
                    if ($sku === '') {
                        continue;
                    }
                    $qq = round((float)($lr['q'] ?? 0), 3);
                    $liveBySku[$sku] = $qq;
                    $liveTotal = round($liveTotal + $qq, 3);
                }
            } catch (Throwable) {
            }
            foreach ($pallets as $r) {
                $cached = (float)($r['qty_total'] ?? 0);
                $qtyShow = $liveTotal;
                $rows = [
                    ['Статус', $r['status']],
                    ['Кол-во, шт', $qtyShow],
                    ['Вес, кг', $r['weight'] ?? '—'],
                    ['Документ прихода', $r['receipt_id']],
                    ['Открыта', $r['created_at'] ?? '—'],
                    ['Закрыта', $r['closed_at'] ?? '—'],
                ];
                if (abs($cached - $liveTotal) > 1e-9) {
                    $rows[] = ['Учёт', ($r['status'] === 'Закрыта'
                        ? 'итог закрытия: ' . $cached . ' (расходится с фактом — проверь закрытие)'
                        : 'паллета открыта: итог подведётся при закрытии (кэш: ' . $cached . ')')];
                }
                if ($liveBySku) {
                    $parts = [];
                    foreach ($liveBySku as $sku => $qq) {
                        $parts[] = $sku . ' — ' . $qq . ' шт';
                    }
                    $rows[] = ['Состав (факт приёмки)', implode('; ', $parts)];
                }
                $sections[] = ['title' => 'Паллета ' . $r['sscc'], 'rows' => $rows];
            }
            // Если паллеты в tsd_pallets нет, но живой факт есть — всё равно показать.
            if (!$pallets && $liveBySku) {
                $parts = [];
                foreach ($liveBySku as $sku => $qq) {
                    $parts[] = $sku . ' — ' . $qq . ' шт';
                }
                $sections[] = ['title' => 'Паллета ' . $q . ' (факт приёмки)', 'rows' => [
                    ['Кол-во, шт', $liveTotal],
                    ['Состав', implode('; ', $parts)],
                    ['Учёт', 'карточки паллеты нет в tsd_pallets — принята сканом без вскрытия (sscc_accept)'],
                ]];
            }
            // Оприходованный остаток — ТА ЖЕ таблица product_stock, что в модуле 8.1.
            try {
                $nom0 = idb('nom');
                if ($nom0) {
                    $ps = $nom0->prepare("SELECT s.cell, s.pallet, s.qty, s.receipt_no, s.warehouse_id, p.sku, p.name FROM product_stock s LEFT JOIN products p ON p.id = s.product_id WHERE s.pallet = :s LIMIT 20");
                    $ps->execute([':s' => $q]);
                    foreach ($ps->fetchAll() as $pr) {
                        $sections[] = ['title' => 'Запас 8.1: паллета ' . ($pr['pallet'] ?? $q), 'rows' => [
                            ['Артикул', ($pr['sku'] ?? '—') . ' · ' . ($pr['name'] ?? '')],
                            ['Ячейка', $pr['cell'] ?? '—'],
                            ['Кол-во', $pr['qty']],
                            ['Приход', $pr['receipt_no'] ?? '—'],
                            ['Склад', $pr['warehouse_id'] ?? '—'],
                        ]];
                    }
                }
            } catch (Throwable) {
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
