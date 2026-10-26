<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/CsvUpload.php';
require_once __DIR__ . '/ReceiptExcel.php';
require_once __DIR__ . '/ShipmentExcel.php';
require_once __DIR__ . '/../08-nomenklatura/Notifications.php';

/**
 * Робот импорта in/out.
 *   in/  — приходы (модуль 02 «Поступления»);
 *   out/ — заказы (модуль 03.1 «Заказы»).
 * Один файл = один документ. Дубликаты определяются по SHA-256 файла,
 * а для приходов — ещё и по внешнему номеру документа.
 * После обработки файл и txt-отчёт переносятся в success/ или error/.
 */
function wms_robot_paths(): array
{
    $root = dirname(__DIR__);
    return [
        'root' => $root,
        'in' => $root . DIRECTORY_SEPARATOR . 'in',
        'out' => $root . DIRECTORY_SEPARATOR . 'out',
        'settings' => __DIR__ . DIRECTORY_SEPARATOR . 'robot_settings.json',
        'log' => __DIR__ . DIRECTORY_SEPARATOR . 'robot.log',
    ];
}

function wms_robot_defaults(): array
{
    return [
        'enabled' => 1,
        'interval_min' => 10,
        'warehouse_id' => 1,
        'formats' => ['csv', 'txt', 'xlsx', 'xlsm'],
        'code' => "Робот проверяет папки in/out.\n"
            . "in  -> приходы (модуль 02).\n"
            . "out -> заказы (модуль 03.1).\n"
            . "Файл переносится в success или error, рядом пишется txt-отчёт.",
        'last_run' => 0,
        'last_result' => '',
    ];
}

function wms_robot_ensure_dirs(): void
{
    $p = wms_robot_paths();
    foreach ([$p['in'], $p['out'], $p['in'] . '/success', $p['in'] . '/error',
                 $p['out'] . '/success', $p['out'] . '/error'] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}

function wms_robot_settings(): array
{
    $p = wms_robot_paths();
    $def = wms_robot_defaults();
    $raw = is_file($p['settings']) ? @file_get_contents($p['settings']) : '';
    $data = $raw !== '' ? json_decode((string)$raw, true) : null;
    if (!is_array($data)) {
        $data = [];
    }
    $out = array_merge($def, $data);
    $out['enabled'] = (int)!empty($out['enabled']);
    $out['interval_min'] = max(1, (int)($out['interval_min'] ?? 10));
    $out['warehouse_id'] = max(1, (int)($out['warehouse_id'] ?? 1));
    $fmt = $out['formats'] ?? $def['formats'];
    if (is_string($fmt)) {
        $fmt = preg_split('/[\s,;]+/', $fmt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    $out['formats'] = array_values(array_unique(array_filter(array_map(
        static fn($x): string => strtolower(trim((string)$x)), (array)$fmt
    ))));
    if (!$out['formats']) {
        $out['formats'] = $def['formats'];
    }
    return $out;
}

function wms_robot_settings_save(array $b): array
{
    $cur = wms_robot_settings();
    $next = array_merge($cur, [
        'enabled' => (int)!empty($b['enabled']),
        'interval_min' => max(1, (int)($b['interval_min'] ?? $cur['interval_min'])),
        'warehouse_id' => max(1, (int)($b['warehouse_id'] ?? $cur['warehouse_id'])),
        'code' => (string)($b['code'] ?? $cur['code']),
    ]);
    if (isset($b['formats'])) {
        $fmt = is_array($b['formats']) ? $b['formats'] : preg_split('/[\s,;]+/', (string)$b['formats'], -1, PREG_SPLIT_NO_EMPTY);
        $next['formats'] = array_values(array_unique(array_filter(array_map(
            static fn($x): string => strtolower(trim((string)$x)), (array)$fmt
        ))));
    }
    $p = wms_robot_paths();
    wms_robot_ensure_dirs();
    @file_put_contents($p['settings'], json_encode($next, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $next;
}

/** Журнал импортов (номенклатурная БД / общая PG-база). */
function wms_robot_log_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = wms_nomenclature_pdo();
    try {
        $drv = wms_driver($pdo);
        if (!wms_has_column($pdo, 'wms_import_log', 'id')) {
            if ($drv === 'pgsql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS wms_import_log (
                    id BIGSERIAL PRIMARY KEY,
                    folder VARCHAR(12) NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    file_hash VARCHAR(64) NULL,
                    status VARCHAR(20) NOT NULL,
                    doc_no VARCHAR(50) NULL,
                    message TEXT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW())");
            } elseif ($drv === 'mysql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS wms_import_log (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    folder VARCHAR(12) NOT NULL,
                    filename VARCHAR(255) NOT NULL,
                    file_hash VARCHAR(64) NULL,
                    status VARCHAR(20) NOT NULL,
                    doc_no VARCHAR(50) NULL,
                    message TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS wms_import_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    folder TEXT NOT NULL, filename TEXT NOT NULL, file_hash TEXT,
                    status TEXT NOT NULL, doc_no TEXT, message TEXT,
                    created_at TEXT DEFAULT (datetime('now')))");
            }
        }
        try {
            wms_ensure_index($pdo, 'wms_import_log', 'idx_ilog_hash', 'file_hash');
        } catch (Throwable) {
        }
    } catch (Throwable) {
    }
    return $pdo;
}

function wms_robot_log_add(string $folder, string $filename, ?string $hash, string $status,
    ?string $docNo, ?string $message): int
{
    try {
        $pdo = wms_robot_log_pdo();
        $st = $pdo->prepare(
            "INSERT INTO wms_import_log (folder,filename,file_hash,status,doc_no,message)
             VALUES (:f,:n,:h,:s,:d,:m)"
        );
        $st->execute([':f' => $folder, ':n' => $filename, ':h' => $hash,
            ':s' => $status, ':d' => $docNo, ':m' => $message]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable) {
        return 0;
    }
}

function wms_robot_log_rows(int $limit = 100): array
{
    try {
        $pdo = wms_robot_log_pdo();
        $limit = max(1, min(500, $limit));
        return $pdo->query("SELECT * FROM wms_import_log ORDER BY id DESC LIMIT $limit")
            ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}

function wms_robot_hash_seen(string $hash): ?array
{
    try {
        $pdo = wms_robot_log_pdo();
        $st = $pdo->prepare("SELECT * FROM wms_import_log WHERE file_hash=:h AND status='success' ORDER BY id DESC LIMIT 1");
        $st->execute([':h' => $hash]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable) {
        return null;
    }
}

function wms_robot_safe_name(string $s): string
{
    $s = preg_replace('/[^A-Za-zА-Яа-я0-9_.-]+/u', '_', trim($s));
    return $s !== '' ? $s : 'file';
}

function wms_robot_move(string $path, string $destDir): string
{
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }
    $base = basename($path);
    $target = $destDir . DIRECTORY_SEPARATOR . $base;
    if (is_file($target)) {
        $target = $destDir . DIRECTORY_SEPARATOR . date('Ymd-His') . '_' . $base;
    }
    @rename($path, $target);
    return $target;
}

function wms_robot_report(string $dir, string $name, string $text): string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . DIRECTORY_SEPARATOR . wms_robot_safe_name($name) . '.txt';
    $n = 2;
    while (is_file($file)) {
        $file = $dir . DIRECTORY_SEPARATOR . wms_robot_safe_name($name) . '_' . $n . '.txt';
        $n++;
    }
    @file_put_contents($file, $text);
    return $file;
}

function wms_robot_duplicate_external(string $kind, string $externalNo): bool
{
    $externalNo = trim($externalNo);
    if ($externalNo === '') {
        return false;
    }
    try {
        if ($kind === 'in') {
            $st = wms_receipts_pdo()->prepare("SELECT 1 FROM receipts WHERE external_no=:n LIMIT 1");
            $st->execute([':n' => $externalNo]);
            return (bool)$st->fetchColumn();
        }
        $st = wms_shipments_pdo()->prepare("SELECT 1 FROM client_orders WHERE comment LIKE :n LIMIT 1");
        $st->execute([':n' => '%Заказ № ' . $externalNo . '%']);
        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Один проход робота по in/out.
 * @return array{processed:array, errors:array, skipped:array, last_run:int}
 */
function wms_robot_run(): array
{
    $p = wms_robot_paths();
    wms_robot_ensure_dirs();
    $settings = wms_robot_settings();
    $res = ['processed' => [], 'errors' => [], 'skipped' => [], 'last_run' => time()];

    $lockFile = $p['root'] . DIRECTORY_SEPARATOR . 'shared' . DIRECTORY_SEPARATOR . '.robot.lock';
    $lock = @fopen($lockFile, 'c');
    if ($lock && !@flock($lock, LOCK_EX | LOCK_NB)) {
        $res['skipped'][] = 'Робот уже выполняется';
        return $res;
    }
    try {
        foreach (['in', 'out'] as $kind) {
            $dir = $p[$kind];
            $files = @scandir($dir) ?: [];
            foreach ($files as $fn) {
                if ($fn === '.' || $fn === '..' || $fn === '.gitkeep' || $fn[0] === '.') {
                    continue;
                }
                $path = $dir . DIRECTORY_SEPARATOR . $fn;
                if (!is_file($path)) {
                    continue;
                }
                $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
                if (!in_array($ext, $settings['formats'], true)) {
                    continue;
                }
                $hash = @hash_file('sha256', $path) ?: '';
                $now = date('Y-m-d H:i:s');
                $detail = '';
                $docNo = '';
                $status = 'error';

                $dup = $hash !== '' ? wms_robot_hash_seen($hash) : null;
                if ($dup) {
                    $detail = 'Дубликат файла. Ранее загружен документ ' . (string)($dup['doc_no'] ?? '') . '.';
                } else {
                    try {
                        if ($kind === 'in') {
                            $parsed = receipt_upload_parse_file(['tmp_name' => $path, 'name' => $fn, 'error' => 0]);
                            if (!empty($parsed['errors'])) {
                                throw new RuntimeException(implode('; ', array_slice($parsed['errors'], 0, 5)));
                            }
                            if (empty($parsed['rows'])) {
                                throw new RuntimeException('Нет корректных строк для прихода');
                            }
                            $hdr = $parsed['header'] ?? [];
                            if (wms_robot_duplicate_external('in', (string)($hdr['external_no'] ?? ''))) {
                                throw new RuntimeException('Дубликат прихода по внешнему номеру ' . (string)$hdr['external_no']);
                            }
                            $rcp = wms_receipts_pdo();
                            $nom = wms_nomenclature_pdo();
                            $sup = csv_upload_resolve_contractor($rcp, (string)($hdr['supplier'] ?? ''));
                            $r = csv_upload_import_receipt($rcp, $nom, $parsed['rows'], (int)$sup['id'],
                                (int)$settings['warehouse_id'], 1, $fn, $hdr);
                            $docNo = (string)($r['doc_no'] ?? '');
                            $detail = 'Приход ' . $docNo . ' загружен: строк ' . count($parsed['rows']) . '.';
                            $status = 'success';
                        } else {
                            $parsed = shipment_upload_parse_file(['tmp_name' => $path, 'name' => $fn, 'error' => 0]);
                            if (!empty($parsed['errors'])) {
                                throw new RuntimeException(implode('; ', array_slice($parsed['errors'], 0, 5)));
                            }
                            if (empty($parsed['rows'])) {
                                throw new RuntimeException('Нет корректных строк для заказа');
                            }
                            $hdr = $parsed['header'] ?? [];
                            if (wms_robot_duplicate_external('out', (string)($hdr['external_no'] ?? ''))) {
                                throw new RuntimeException('Дубликат заказа по номеру ' . (string)$hdr['external_no']);
                            }
                            $shp = wms_shipments_pdo();
                            $nom = wms_nomenclature_pdo();
                            $cc = csv_upload_resolve_contractor($shp, (string)($hdr['contractor'] ?? ''));
                            $orderNo = csv_upload_next_order_no($shp);
                            $r = csv_upload_import_orders($shp, $nom, $parsed['rows'], (int)$cc['id'],
                                (int)$settings['warehouse_id'], 1, $fn, $orderNo, $hdr);
                            $docNo = (string)($r['doc_no'] ?? $orderNo);
                            $detail = 'Заказ ' . $docNo . ' загружен: строк ' . count($parsed['rows']) . '.';
                            $status = 'success';
                        }
                    } catch (Throwable $e) {
                        $detail = 'Ошибка: ' . $e->getMessage();
                        $status = 'error';
                    }
                }

                $destRoot = $dir . DIRECTORY_SEPARATOR . ($status === 'success' ? 'success' : 'error');
                $moved = wms_robot_move($path, $destRoot);
                $reportName = $docNo !== '' ? $docNo : (pathinfo($fn, PATHINFO_FILENAME) . '_' . date('His'));
                $report = "Тип: " . ($kind === 'in' ? 'приход (02)' : 'заказ (03.1)') . "\n"
                    . "Файл: " . $fn . "\n"
                    . "Внутренний код: " . ($docNo !== '' ? $docNo : '—') . "\n"
                    . "Статус: " . ($status === 'success' ? 'успех' : 'ошибка') . "\n"
                    . "Дата: " . $now . "\n"
                    . "Результат: " . $detail . "\n"
                    . "Файл перемещён: " . $moved . "\n";
                wms_robot_report($destRoot, $reportName, $report);

                wms_robot_log_add($kind, $fn, $hash, $status, $docNo, $detail);
                try {
                    Notifications::push(wms_nomenclature_pdo(), 'robot',
                        ($status === 'success' ? 'Робот: ' : 'Робот (ошибка): ')
                            . ($kind === 'in' ? 'приход ' : 'заказ ') . ($docNo !== '' ? $docNo : $fn),
                        $detail . ' · ' . $fn, null, $status === 'success' ? 0 : 1);
                } catch (Throwable) {
                }
                if ($status === 'success') {
                    $res['processed'][] = ['folder' => $kind, 'file' => $fn, 'doc_no' => $docNo, 'message' => $detail];
                } else {
                    $res['errors'][] = ['folder' => $kind, 'file' => $fn, 'doc_no' => $docNo, 'message' => $detail];
                }
            }
        }
    } finally {
        if ($lock) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    $settings['last_run'] = time();
    $settings['last_result'] = 'Обработано: ' . count($res['processed']) . ', ошибок: ' . count($res['errors']);
    $p2 = wms_robot_paths();
    @file_put_contents($p2['settings'], json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $res;
}

/** Запустить робота, если включён таймер и интервал истёк. */
function wms_robot_tick(): ?array
{
    $s = wms_robot_settings();
    if (empty($s['enabled'])) {
        return null;
    }
    $due = (int)$s['last_run'] + max(1, (int)$s['interval_min']) * 60;
    if (time() < $due) {
        return null;
    }
    return wms_robot_run();
}
