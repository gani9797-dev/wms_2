<?php
declare(strict_types=1);
// Модуль 14 «Журнал»: журнал склада и журнал серийных номеров.
// Открыть: /14-zhurnal/demo_sqlite.php?action=list
// GET ?action=list       — журнал склада (WmsJournal::query)
// GET ?action=serial_list— журнал серийных номеров (WmsJournal::querySerial)
// GET ?action=export     — выгрузка CSV активного журнала (&journal=stock|serial)
// GET ?action=op_types   — справочник типов операций
// Фильтры (для обоих журналов): op_type[], date_from, date_to, sku, doc_no, pallet, search,
//                                page, per_page, sort, dir.

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Journal.php';

// Миграция колонки quality в wms_journal (идемпотентно, SQLite/MySQL/PostgreSQL).
try {
    wms_ensure_column(wms_journal_pdo(), wms_ext_table(wms_journal_pdo(), 'wms_journal'), 'quality',
        "VARCHAR(20) NULL DEFAULT 'новый'", "quality TEXT DEFAULT 'новый'", "VARCHAR(20) NULL DEFAULT 'новый'");
} catch (Throwable) {
}

/** Сколько фильтров активно (для бейджа «Фильтры (N)»). */
function wms_journal_active_filters(array $f): int
{
    $n = 0;
    $ops = $f['op_type'] ?? [];
    if (is_string($ops)) {
        $ops = $ops === '' ? [] : [$ops];
    }
    if (is_array($ops)) {
        $ops = array_values(array_filter(array_map('strval', $ops), fn($v) => trim($v) !== ''));
        if ($ops) {
            $n++;
        }
    }
    foreach (['date_from', 'date_to', 'sku', 'doc_no', 'pallet', 'search'] as $k) {
        if (!empty($f[$k])) {
            $n++;
        }
    }
    return $n;
}

/** Выборка всех строк журнала постранично (для экспорта CSV). */
function wms_journal_collect(callable $query, array $f): array
{
    $rows = [];
    $total = 0;
    $from = 0;
    $to = 0;
    $page = 1;
    do {
        $f['page'] = $page;
        $f['per_page'] = 500;
        $d = $query($f);
        $rows = array_merge($rows, $d['rows'] ?? []);
        $total = (int)($d['total'] ?? 0);
        $from = (int)($d['from'] ?? 0);
        $to = (int)($d['to'] ?? 0);
        $page++;
    } while (count($rows) < $total && $page <= 60);
    return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to];
}

/** Значение ячейки CSV: кавычки и разделитель приводим к безопасному виду. */
function wms_journal_csv_val(mixed $v): string
{
    $s = trim((string)($v ?? ''));
    return str_replace(';', ',', $s);
}

function wms_journal_csv_stock(array $rows): string
{
    $head = ['Дата/время', 'Тип операции', 'Тип документа', 'Документ', 'Артикул', 'Наименование',
        'Количество', 'Паллета', 'SSCC', 'Откуда', 'Куда', 'Зона', 'Пользователь', 'Комментарий', 'Качество'];
    $lines = ["\xEF\xBB\xBF" . implode(';', $head)];
    foreach ($rows as $r) {
        $lines[] = implode(';', [
            wms_journal_csv_val($r['doc_date'] ?? ''),
            wms_journal_csv_val($r['op_type'] ?? ''),
            wms_journal_csv_val($r['doc_type'] ?? ''),
            wms_journal_csv_val($r['doc_no'] ?? ''),
            wms_journal_csv_val($r['sku'] ?? ''),
            wms_journal_csv_val($r['product_name'] ?? ''),
            wms_journal_csv_val($r['qty'] ?? 0),
            wms_journal_csv_val($r['pallet'] ?? ''),
            wms_journal_csv_val($r['sscc'] ?? ''),
            wms_journal_csv_val($r['cell_from'] ?? ''),
            wms_journal_csv_val($r['cell_to'] ?? ''),
            wms_journal_csv_val($r['zone'] ?? ''),
            wms_journal_csv_val($r['user_fio'] ?? ''),
            wms_journal_csv_val($r['comment'] ?? ''),
            wms_journal_csv_val($r['quality'] ?? 'новый'),
        ]);
    }
    return implode("\n", $lines);
}

function wms_journal_csv_serial(array $rows): string
{
    $head = ['Дата/время', 'Серийный номер', 'Операция', 'Артикул', 'Наименование',
        'Приход', 'Отгрузка', 'Паллета', 'Количество', 'Ячейка', 'Пользователь'];
    $lines = ["\xEF\xBB\xBF" . implode(';', $head)];
    foreach ($rows as $r) {
        $lines[] = implode(';', [
            wms_journal_csv_val($r['doc_date'] ?? ''),
            wms_journal_csv_val($r['serial_no'] ?? ''),
            wms_journal_csv_val($r['op_type'] ?? ''),
            wms_journal_csv_val($r['sku'] ?? ''),
            wms_journal_csv_val($r['product_name'] ?? ''),
            wms_journal_csv_val($r['receipt_doc_no'] ?? ''),
            wms_journal_csv_val($r['shipment_doc_no'] ?? ''),
            wms_journal_csv_val($r['pallet'] ?? ''),
            wms_journal_csv_val($r['qty'] ?? 0),
            wms_journal_csv_val($r['cell'] ?? ''),
            wms_journal_csv_val($r['user_fio'] ?? ''),
        ]);
    }
    return implode("\n", $lines);
}

$action = (string)($_GET['action'] ?? 'list');

try {
    $pdo = wms_journal_pdo();

    if ($action === 'list') {
        $f = $_GET;
        $d = WmsJournal::query($pdo, $f);
        $d['active_filters'] = wms_journal_active_filters($f);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($action === 'serial_list') {
        $f = $_GET;
        $d = WmsJournal::querySerial($pdo, $f);
        $d['active_filters'] = wms_journal_active_filters($f);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    } elseif ($action === 'op_types') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'op_types' => WmsJournal::OP_TYPES,
            'serial_op_types' => WmsJournal::SERIAL_OP_TYPES,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } elseif ($action === 'export') {
        $journal = (string)($_GET['journal'] ?? 'stock');
        $f = $_GET;
        unset($f['journal'], $f['action']);
        if ($journal === 'serial') {
            $d = wms_journal_collect(fn(array $ff) => WmsJournal::querySerial($pdo, $ff), $f);
            $csv = wms_journal_csv_serial($d['rows']);
            $name = 'serial_journal.csv';
        } else {
            $d = wms_journal_collect(fn(array $ff) => WmsJournal::query($pdo, $ff), $f);
            $csv = wms_journal_csv_stock($d['rows']);
            $name = 'journal.csv';
        }
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $name . '"');
        }
        echo $csv;
        exit;
    } else {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Неизвестное действие. Доступно: list, serial_list, export, op_types'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
