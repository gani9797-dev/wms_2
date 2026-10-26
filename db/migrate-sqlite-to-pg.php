<?php
declare(strict_types=1);
// Миграция данных SQLite -> PostgreSQL.
// Запуск: php db/migrate-sqlite-to-pg.php [--fresh]
// --fresh: пересоздать схему из db/schema-pg.sql перед заливкой.
// Требует: pdo_sqlite + pdo_pgsql, env PG_* (см. .env.example).
// Источники:
//   02-postupleniya/wms_receipts.sqlite -> receipts* + tsd_* + receipt_stock_sync
//   08-nomenklatura/wms_nomenclature.sqlite -> products*
//   05-yacheyki-hraneniya/wms_storage.sqlite -> storage_cells*
//   tasks/wms_tasks.sqlite -> tasks*
//   03-otgruzki/wms_demo.sqlite -> shipments*, client_orders*

require_once __DIR__ . '/../shared/pg.php';

$fresh = in_array('--fresh', $argv ?? [], true);
$root = dirname(__DIR__);

$pg = wms_pg_pdo();
echo "PG: " . $pg->query('SELECT version()')->fetchColumn() . PHP_EOL;

if ($fresh) {
    echo "Applying db/schema-pg.sql...\n";
    $sql = file_get_contents(__DIR__ . '/schema-pg.sql');
    if ($sql === false) throw new RuntimeException('no schema-pg.sql');
    $pg->exec($sql);
    echo "Schema OK\n";
}

$jobs = [
    // Сотрудники — единый справочник (shared/wms_users.sqlite) для всех модулей.
    ['file' => '/shared/wms_users.sqlite', 'tables' => ['users']],
    ['file' => '/02-postupleniya/wms_receipts.sqlite', 'tables' => [
        'warehouses','contractors','receipts','receipt_items','receipt_operations',
        'receipt_documents','receipt_flags','receipt_history','receipt_marks',
        'tsd_pallets','tsd_crossdock','tsd_act_rasx','receipt_stock_sync','user_columns',
    ]],
    ['file' => '/08-nomenklatura/wms_nomenclature.sqlite', 'tables' => [
        'warehouses','products','product_barcodes','product_stock',
        'product_history','product_movements','product_defaults',
        'notifications','notification_subscriptions','user_columns',
    ]],
    ['file' => '/05-yacheyki-hraneniya/wms_storage.sqlite', 'tables' => [
        'warehouses','storage_cells','cell_content','cell_history','cell_movements','user_columns',
    ]],
    ['file' => '/07-zadaniya/wms_tasks.sqlite', 'tables' => [
        'warehouses','tasks','task_items','task_history',
        'tsd_task_locks','tsd_task_logs','tsd_otbor_locks','tsd_otbor_logs',
        'wms_stock','wms_tasks','wms_task_items','wms_task_history','user_columns',
    ]],
    ['file' => '/03-otgruzki/wms_demo.sqlite', 'tables' => [
        'warehouses','contractors','client_orders','client_order_items',
        'shipments','shipment_orders','shipment_items','tsd_picks',
        'shipment_flags','shipment_documents','shipment_history','shipment_marks',
        'shipment_sort_log','tsd_otbor_locks','tsd_otbor_logs','user_columns',
    ]],
];

$total = 0;
foreach ($jobs as $j) {
    $f = $root . $j['file'];
    // Прежнее расположение БД заданий — поддерживаем на время миграции.
    if (!is_file($f) && $j['file'] === '/07-zadaniya/wms_tasks.sqlite') {
        $legacy = $root . '/tasks/wms_tasks.sqlite';
        if (is_file($legacy)) {
            $f = $legacy;
            echo "МИГРАЦИЯ: используется прежний файл заданий {$j['file']}\n";
        }
    }
    if (!is_file($f)) { echo "SKIP (нет файла): {$j['file']}\n"; continue; }
    $src = new PDO('sqlite:' . $f, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach ($j['tables'] as $t) {
        try {
            $has = $src->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $src->quote($t))->fetchColumn();
        } catch (Throwable) { $has = false; }
        if (!$has) continue;
        $rows = $src->query("SELECT * FROM \"$t\"")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) { echo "$t: 0 строк ($j[file])\n"; continue; }
        $cols = array_keys($rows[0]);
        // Проверка наличия таблицы в PG
        try {
            $pgCols = [];
            $st = $pg->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = :t");
            $st->execute([':t' => $t]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cc) $pgCols[] = (string)$cc;
        } catch (Throwable $e) { echo "$t: нет в PG ({$e->getMessage()})\n"; continue; }
        $cols = array_values(array_intersect($cols, $pgCols));
        if (!$cols) { echo "$t: нет общих колонок\n"; continue; }
        $colList = '"' . implode('","', $cols) . '"';
        $ph = ':' . implode(',:', $cols);
        // UPSERT по id/user_key чтобы повторный запуск не дублировал
        $pk = in_array('id', $cols, true) ? 'id' : (in_array('receipt_id', $cols, true) && $t === 'receipt_stock_sync' ? 'receipt_id' : null);
        $sql = "INSERT INTO \"$t\" ($colList) VALUES ($ph)";
        if ($pk === 'id') $sql .= ' ON CONFLICT (id) DO UPDATE SET ' . implode(', ', array_map(fn($c) => "\"$c\"=EXCLUDED.\"$c\"", array_filter($cols, fn($c) => $c !== 'id')));
        elseif ($pk === 'receipt_id') $sql .= ' ON CONFLICT (receipt_id) DO NOTHING';
        elseif ($t === 'user_columns') $sql .= ' ON CONFLICT (user_key, form) DO UPDATE SET cols=EXCLUDED.cols, updated_at=NOW()';
        elseif ($t === 'receipt_marks') $sql .= ' ON CONFLICT (receipt_id, marking_code) DO NOTHING';
        elseif ($t === 'shipment_marks') $sql .= ' ON CONFLICT (shipment_id, marking_code) DO NOTHING';
        elseif ($t === 'tsd_pallets') $sql .= ' ON CONFLICT (receipt_id, sscc) DO NOTHING';
        elseif ($t === 'shipment_orders') $sql .= ' ON CONFLICT (shipment_id, order_id) DO NOTHING';
        elseif ($t === 'tsd_task_locks') $sql .= ' ON CONFLICT (task_id) DO NOTHING';
        elseif ($t === 'tsd_otbor_locks') $sql .= ' ON CONFLICT (shipment_id) DO NOTHING';
        $ins = $pg->prepare($sql);
        $n = 0; $skip = 0;
        $pg->beginTransaction();
        try {
            foreach ($rows as $r) {
                $params = [];
                foreach ($cols as $c) {
                    $v = $r[$c] ?? null;
                    // SQLite хранит bool как 0/1 int — PG SMALLINT ок; пустая строка в TIMESTAMP -> NULL
                    if ($v === '') {
                        // оставить '' для текстовых, но для дат — NULL
                        $params[":$c"] = $v;
                    } else {
                        $params[":$c"] = $v;
                    }
                }
                // Вторая попытка с нормализацией: '' -> NULL (для TIMESTAMP/DATE колонок)
                $paramsNull = $params;
                foreach ($paramsNull as $k => $v) {
                    if ($v === '') $paramsNull[$k] = null;
                }
                // Построчный SAVEPOINT: одна битая строка (напр. сирота без родителя по FK —
                // в SQLite FK не enforced) пропускается, не роняя всю таблицу.
                $done = false;
                foreach ([$params, $paramsNull] as $attempt) {
                    $pg->exec('SAVEPOINT wms_row');
                    try {
                        $ins->execute($attempt);
                        $pg->exec('RELEASE SAVEPOINT wms_row');
                        $done = true;
                        break;
                    } catch (Throwable) {
                        $pg->exec('ROLLBACK TO SAVEPOINT wms_row');
                    }
                }
                if ($done) $n++;
                else {
                    $skip++;
                    if ($skip <= 5) echo "$t: пропуск битой строки id=" . var_export($r['id'] ?? $r, true) . "\n";
                }
            }
            // Подтянуть sequence под max(id), иначе следующий INSERT упадет на duplicate key
            if ($pk === 'id') {
                try {
                    $pg->exec("SELECT setval(pg_get_serial_sequence('\"$t\"','id'), COALESCE((SELECT MAX(id) FROM \"$t\"),0)+1, false)");
                } catch (Throwable) {}
            }
            $pg->commit();
        } catch (Throwable $e) {
            $pg->rollBack();
            echo "$t: ОШИБКА {$e->getMessage()}\n";
            continue;
        }
        echo "$t: $n строк ($j[file])" . ($skip ? ", пропущено битых: $skip" : "") . "\n";
        $total += $n;
    }
}
echo "Готово, всего $total строк.\n";
