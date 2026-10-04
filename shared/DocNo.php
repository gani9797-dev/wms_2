<?php
declare(strict_types=1);

// Единый генератор номеров документов WMS (латиница, с дефисом).
//
// Поступления: A-105101, A-105102, ... (префикс A, старт 105101).
// Отгрузки:    S-205101, S-205102, ... (префикс S, старт 205101).
//
// Старые номера (10005678П, 10005678О, ОТГ-0001287) считаются legacy:
// на генерацию не влияют — счетчики новых серий ведутся независимо.
// Переносимо между MySQL (прод, с LOCK TABLES) и SQLite (демо).

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
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($isMysql) {
        $pdo->exec("LOCK TABLES `$table` WRITE");
    }
    try {
        $max = null;
        try {
            $rows = $pdo->query("SELECT doc_no FROM `$table` WHERE doc_no LIKE '$prefix-%'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return $prefix . '-' . $start;
        }
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
        try {
            $chk = $pdo->prepare("SELECT 1 FROM `$table` WHERE doc_no = :no LIMIT 1");
            for ($guard = 0; $guard < 100000; $guard++) {
                $chk->execute([':no' => $prefix . '-' . $n]);
                if (!$chk->fetchColumn()) {
                    return $prefix . '-' . $n;
                }
                $n++;
            }
        } catch (Throwable) {
            return $prefix . '-' . $n;
        }
        throw new RuntimeException('Не удалось подобрать свободный номер документа');
    } finally {
        if ($isMysql) {
            $pdo->exec('UNLOCK TABLES');
        }
    }
}
