<?php
declare(strict_types=1);

// Единый генератор номеров документов WMS (латиница, с дефисом).
//
// Поступления: A-105101, A-105102, ... (префикс A, старт 105101).
// Отгрузки:    S-205101, S-205102, ... (префикс S, старт 205101).
//
// Старые номера (10005678П, 10005678О, ОТГ-0001287) считаются legacy:
// на генерацию не влияют — счетчики новых серий ведутся независимо.
// Переносимо между MySQL (прод, с LOCK TABLES), SQLite (демо) и PostgreSQL.
// PG: без LOCK TABLES — генерация через SELECT + UNIQUE(doc_no) как арбитр.
require_once __DIR__ . '/pg.php';

/** Следующий номер поступления: A-105101... */
function wms_next_receipt_no(PDO $pdo): string
{
    return wms_next_doc_no($pdo, 'receipts', 'A', 105101);
}

/** Следующий номер отгрузки: S-205101... */
function wms_next_shipment_no(PDO $pdo): string
{
    return wms_next_doc_no($pdo, 'shipments', 'S', 205101);
}

/** @internal */
function wms_next_doc_no(PDO $pdo, string $table, string $prefix, int $start): string
{
    if (!in_array($table, ['receipts', 'shipments'], true)) {
        throw new RuntimeException('Неизвестная таблица документов: ' . $table);
    }
    if (!preg_match('/^[A-Z]$/', $prefix)) {
        throw new RuntimeException('Недопустимый префикс документа: ' . $prefix);
    }
    $isMysql = function_exists('wms_is_mysql') ? wms_is_mysql($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    // Идентификатор таблицы — по правилам СУБД: backticks для MySQL,
    // двойные кавычки для PostgreSQL/SQLite. Раньше здесь стояли двойные
    // кавычки — в MySQL это строковый литерал, запрос падал, а catch отдавал
    // ОДИН И ТОТ ЖЕ номер (A-105101) на каждый документ -> нарушение UNIQUE.
    $qid = function_exists('wms_quote_ident')
        ? wms_quote_ident($pdo, $table)
        : ($isMysql ? '`' . $table . '`' : '"' . $table . '"');
    if ($isMysql) {
        $pdo->exec("LOCK TABLES $table WRITE");
    }
    try {
        $max = null;
        // Префикс проверен регулярным выражением выше — безопасно вставлять в LIKE.
        $rows = $pdo->query("SELECT doc_no FROM $qid WHERE doc_no LIKE '" . $prefix . "-%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $no) {
            if (preg_match('/^' . $prefix . '-(\d+)$/', trim((string)$no), $m)) {
                $n = (int)$m[1];
                if ($max === null || $n > $max) {
                    $max = $n;
                }
            }
        }
        $n = ($max === null) ? $start : max($start, $max + 1);
        // Защита от дублей (следующий номер мог занять ручной ввод).
        $chk = $pdo->prepare("SELECT 1 FROM $qid WHERE doc_no = :no LIMIT 1");
        for ($guard = 0; $guard < 100000; $guard++) {
            $chk->execute([':no' => $prefix . '-' . $n]);
            if (!$chk->fetchColumn()) {
                return $prefix . '-' . $n;
            }
            $n++;
        }
        throw new RuntimeException('Не удалось подобрать свободный номер документа');
    } finally {
        if ($isMysql) {
            $pdo->exec('UNLOCK TABLES');
        }
    }
}
