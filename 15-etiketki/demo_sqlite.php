<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/Auth.php';

function labels_driver(PDO $pdo): string
{
    return function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function labels_now_sql(PDO $pdo): string
{
    return labels_driver($pdo) === 'pgsql' ? 'NOW()' : "datetime('now')";
}

function labels_default_templates(): array
{
    // Исходный макет — 58×40 мм при 203 dpi (464×320 точек).
    $zpl58 = <<<'ZPL'
^XA
^CI28
^PW464
^LL320
^FO0,10^A0N,30,30^FB464,1,0,C^FD{{TITLE}}^FS
^FO55,80^BY2,2.0,110^BCN,110,Y,N,N^FD{{CODE}}^FS
^FO0,210^A0N,24,24^FB464,1,0,C^FD{{CODE}}^FS
^XZ
ZPL;
    // Увеличенная копия — 70×48 мм при 203 dpi (560×384 точки).
    $zpl70 = <<<'ZPL'
^XA
^CI28
^PW560
^LL384
^FO0,12^A0N,36,36^FB560,1,0,C^FD{{TITLE}}^FS
^FO60,100^BY2,2.0,130^BCN,130,Y,N,N^FD{{CODE}}^FS
^FO0,248^A0N,28,28^FB560,1,0,C^FD{{CODE}}^FS
^XZ
ZPL;
    $sqlShipment = <<<'SQL'
SELECT 'S-' || to_char(CURRENT_DATE, 'DDMMYY') || '-' ||
       lpad((COALESCE((SELECT last_no FROM label_sequences
                       WHERE label_type = 'shipment'
                         AND seq_day = to_char(CURRENT_DATE, 'YYYY-MM-DD')), 0) + 1)::text, 5, '0') AS code;
SQL;
    $sqlReceipt = <<<'SQL'
SELECT 'A-' || to_char(CURRENT_DATE, 'DDMMYY') || '-' ||
       lpad((COALESCE((SELECT last_no FROM label_sequences
                       WHERE label_type = 'receipt'
                         AND seq_day = to_char(CURRENT_DATE, 'YYYY-MM-DD')), 0) + 1)::text, 5, '0') AS code;
SQL;
    $sqlPicking = <<<'SQL'
SELECT 'PL-' || to_char(CURRENT_DATE, 'DDMMYY') || '-' ||
       lpad((COALESCE((SELECT last_no FROM label_sequences
                       WHERE label_type = 'picking'
                         AND seq_day = to_char(CURRENT_DATE, 'YYYY-MM-DD')), 0) + 1)::text, 5, '0') AS code;
SQL;
    $sqlStock = <<<'SQL'
SELECT ps.pallet AS code
FROM product_stock ps
WHERE ps.id = :stock_id
  AND ps.pallet IS NOT NULL
  AND trim(ps.pallet) <> '';
SQL;
    return [
        // Исходные (58×40) — печать.
        ['shipment', 'Отгрузка 58×40', 'Отгрузка', 'S-', $zpl58, $sqlShipment, 58, 40],
        ['receipt', 'Приемка 58×40', 'Приемка', 'A-', $zpl58, $sqlReceipt, 58, 40],
        ['picking', 'Комплектация 58×40', 'Комплектация', 'PL-', $zpl58, $sqlPicking, 58, 40],
        ['stock_sscc', 'SSCC (товарный запас) 58×40', 'Комплектация', 'SSCC', $zpl58, $sqlStock, 58, 40],
        // Копии увеличенные (70×48) — печать.
        ['shipment_70', 'Отгрузка 70×48', 'Отгрузка', 'S-', $zpl70, $sqlShipment, 70, 48],
        ['receipt_70', 'Приемка 70×48', 'Приемка', 'A-', $zpl70, $sqlReceipt, 70, 48],
        ['picking_70', 'Комплектация 70×48', 'Комплектация', 'PL-', $zpl70, $sqlPicking, 70, 48],
        ['stock_sscc_70', 'SSCC 70×48', 'Комплектация', 'SSCC', $zpl70, $sqlStock, 70, 48],
        // Служебные типы: только редактирование кода (печать из других модулей).
        ['product', 'Товар', 'Товар', 'T-', $zpl70, '', 70, 48],
        ['cell', 'Ячейка', 'Ячейка', 'C-', $zpl70, '', 70, 48],
        ['pallet', 'Паллета', 'Паллета', 'P-', $zpl70, '', 70, 48],
        ['blank', 'Пустая этикетка', 'Этикетка', '', $zpl70, '', 70, 48],
    ];
}
function labels_ensure_schema(PDO $pdo): void
{
    $d = labels_driver($pdo);
    if ($d === 'pgsql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_templates (
            id BIGSERIAL PRIMARY KEY,
            label_type VARCHAR(30) NOT NULL UNIQUE,
            label_name VARCHAR(100) NOT NULL,
            title VARCHAR(100) NOT NULL,
            prefix VARCHAR(10) NOT NULL DEFAULT '',
            zpl_template TEXT NOT NULL,
            sql_query TEXT NULL,
            width_mm INTEGER NOT NULL DEFAULT 58,
            height_mm INTEGER NOT NULL DEFAULT 40,
            updated_at TIMESTAMP NOT NULL DEFAULT NOW())");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_sequences (
            label_type VARCHAR(30) NOT NULL,
            seq_day VARCHAR(10) NOT NULL,
            last_no INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (label_type, seq_day))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_print_log (
            id BIGSERIAL PRIMARY KEY,
            label_type VARCHAR(30) NOT NULL,
            label_name VARCHAR(100) NOT NULL,
            qty INTEGER NOT NULL,
            code_from VARCHAR(50) NULL,
            code_to VARCHAR(50) NULL,
            user_id BIGINT NULL,
            user_login VARCHAR(100) NULL,
            user_fio VARCHAR(150) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW())");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_codes (
            id BIGSERIAL PRIMARY KEY,
            label_type VARCHAR(30) NOT NULL,
            code VARCHAR(50) NOT NULL UNIQUE,
            batch_id BIGINT NULL,
            user_id BIGINT NULL,
            user_login VARCHAR(100) NULL,
            printed_at TIMESTAMP NOT NULL DEFAULT NOW())");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_label_log_created ON label_print_log (created_at)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_label_codes_batch ON label_codes (batch_id)");
    } elseif ($d === 'mysql') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_templates (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label_type VARCHAR(30) NOT NULL UNIQUE,
            label_name VARCHAR(100) NOT NULL,
            title VARCHAR(100) NOT NULL,
            prefix VARCHAR(10) NOT NULL DEFAULT '',
            zpl_template LONGTEXT NOT NULL,
            sql_query LONGTEXT NULL,
            width_mm INT NOT NULL DEFAULT 58,
            height_mm INT NOT NULL DEFAULT 40,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_sequences (
            label_type VARCHAR(30) NOT NULL,
            seq_day VARCHAR(10) NOT NULL,
            last_no INT NOT NULL DEFAULT 0,
            PRIMARY KEY (label_type, seq_day))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_print_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label_type VARCHAR(30) NOT NULL,
            label_name VARCHAR(100) NOT NULL,
            qty INT NOT NULL,
            code_from VARCHAR(50) NULL,
            code_to VARCHAR(50) NULL,
            user_id BIGINT NULL,
            user_login VARCHAR(100) NULL,
            user_fio VARCHAR(150) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            label_type VARCHAR(30) NOT NULL,
            code VARCHAR(50) NOT NULL UNIQUE,
            batch_id BIGINT NULL,
            user_id BIGINT NULL,
            user_login VARCHAR(100) NULL,
            printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    } else {
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label_type TEXT NOT NULL UNIQUE,
            label_name TEXT NOT NULL,
            title TEXT NOT NULL,
            prefix TEXT NOT NULL DEFAULT '',
            zpl_template TEXT NOT NULL,
            sql_query TEXT,
            width_mm INTEGER NOT NULL DEFAULT 58,
            height_mm INTEGER NOT NULL DEFAULT 40,
            updated_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_sequences (
            label_type TEXT NOT NULL,
            seq_day TEXT NOT NULL,
            last_no INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (label_type, seq_day))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_print_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label_type TEXT NOT NULL, label_name TEXT NOT NULL, qty INTEGER NOT NULL,
            code_from TEXT, code_to TEXT, user_id INTEGER, user_login TEXT, user_fio TEXT,
            created_at TEXT DEFAULT (datetime('now')))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS label_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label_type TEXT NOT NULL, code TEXT NOT NULL UNIQUE, batch_id INTEGER,
            user_id INTEGER, user_login TEXT, printed_at TEXT DEFAULT (datetime('now')))");
    }
    $exists = $pdo->prepare("SELECT COUNT(*) FROM label_templates WHERE label_type=:t");
    $ins = $pdo->prepare("INSERT INTO label_templates (label_type,label_name,title,prefix,zpl_template,sql_query,width_mm,height_mm) VALUES (:t,:n,:title,:p,:zpl,:sql,:w,:h)");
    foreach (labels_default_templates() as $tpl) {
        $exists->execute([':t' => $tpl[0]]);
        if ((int)$exists->fetchColumn() === 0) {
            $ins->execute([
                ':t' => $tpl[0], ':n' => $tpl[1], ':title' => $tpl[2], ':p' => $tpl[3],
                ':zpl' => $tpl[4], ':sql' => $tpl[5], ':w' => (int)$tpl[6], ':h' => (int)$tpl[7],
            ]);
        }
    }
    // Размеры этикеток: исходные — 58×40 (восстанавливаем), копии — 70×48.
    // Раньше базовые шаблоны были переведены на 70×48 — возвращаем размер и ZPL.
    try {
        $defs = [];
        foreach (labels_default_templates() as $tpl) {
            $defs[$tpl[0]] = $tpl;
        }
        $now = labels_now_sql($pdo);
        $fix = $pdo->prepare("UPDATE label_templates SET width_mm=:w, height_mm=:h, zpl_template=:z, updated_at=$now
                               WHERE label_type=:t AND (width_mm<>:w2 OR height_mm<>:h2 OR zpl_template LIKE '%^PW464%')");
        foreach ($defs as $type => $tpl) {
            if (!in_array($type, ['shipment', 'receipt', 'picking', 'stock_sscc'], true)) {
                continue;
            }
            $fix->execute([
                ':w' => (int)$tpl[6], ':h' => (int)$tpl[7], ':w2' => (int)$tpl[6], ':h2' => (int)$tpl[7],
                ':z' => (string)$tpl[4], ':t' => $type,
            ]);
        }
    } catch (Throwable) {
    }
}

function labels_pdo(): PDO
{
    if (wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
    } else {
        $pdo = new PDO('sqlite:' . __DIR__ . '/wms_labels.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    if (function_exists('wms_ensure_cached')) {
        wms_ensure_cached($pdo, 'labels', 'labels_ensure_schema');
    } else {
        labels_ensure_schema($pdo);
    }
    // Новые типы этикеток и перевод на 70×48: схема могла быть создана раньше,
    // а «готовность» закэширована — догоняем миграцию один раз.
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM label_templates WHERE label_type IN ('product','cell','pallet','blank')")->fetchColumn();
        $sm = (int)$pdo->query("SELECT COUNT(*) FROM label_templates WHERE width_mm=70 AND height_mm=48")->fetchColumn();
        $s58 = (int)$pdo->query("SELECT COUNT(*) FROM label_templates WHERE label_type IN ('shipment','receipt','picking','stock_sscc') AND width_mm=58 AND height_mm=40")->fetchColumn();
        if ($n < 4 || $sm < 8 || $s58 < 4) {
            labels_ensure_schema($pdo);
        }
    } catch (Throwable) {
    }
    return $pdo;
}

function labels_template(PDO $pdo, string $type): ?array
{
    $st = $pdo->prepare("SELECT * FROM label_templates WHERE label_type=:t LIMIT 1");
    $st->execute([':t' => $type]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function labels_zpl(array $tpl, string $code): string
{
    $zpl = (string)($tpl['zpl_template'] ?? '');
    $zpl = str_replace(['{{TITLE}}', '{{CODE}}'], [(string)$tpl['title'], $code], $zpl);
    return rtrim($zpl) . "\r\n";
}

/** Запись действия печати в журнал этикеток (термо/PDF). */
function labels_print_log_action(PDO $pdo, string $type, int $qty, string $target, string $mode): void
{
    try {
        $actor = labels_actor();
        $now = labels_now_sql($pdo);
        $name = 'Этикетка';
        if ($type !== '') {
            $tpl = labels_template($pdo, $type);
            if ($tpl) {
                $name = (string)($tpl['label_name'] ?? $type);
            }
        }
        $st = $pdo->prepare(
            "INSERT INTO label_print_log (label_type,label_name,qty,code_from,code_to,user_id,user_login,user_fio,created_at)
             VALUES (:t,:n,:q,:f,:to,:uid,:ul,:uf,$now)"
        );
        $st->execute([
            ':t' => $type !== '' ? $type : 'custom', ':n' => $name . ' [' . $mode . ': ' . $target . ']',
            ':q' => $qty, ':f' => null, ':to' => null,
            ':uid' => $actor['id'] ?: null, ':ul' => $actor['login'] ?: null, ':uf' => $actor['fio'] ?: null,
        ]);
    } catch (Throwable) {
    }
}
function labels_actor(): array
{
    $u = wms_auth_user();
    return [
        'id' => $u ? (int)($u['id'] ?? 0) : 0,
        'login' => $u ? (string)($u['login'] ?? '') : '',
        'fio' => $u ? (string)($u['fio'] ?? '') : '',
    ];
}

/**
 * К тексту в UTF-8: PowerShell без консоли пишет в OEM-кодировке (cp866),
 * из-за этого json_encode возвращал пустую строку и фронт получал
 * «Unexpected end of JSON input».
 */
function labels_utf8(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (preg_match('//u', $s) === 1) {
        return $s;
    }
    foreach (['CP866', 'Windows-1251'] as $enc) {
        $c = @iconv($enc, 'UTF-8//IGNORE', $s);
        if ($c !== false && $c !== '') {
            return $c;
        }
    }
    return (string)preg_replace('/[^\x20-\x7E\r\n\t]/', '?', $s);
}

function labels_run_process(array $cmd, int $timeout = 20): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить системную команду');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $started = microtime(true);
    $lastStatus = null;
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        $lastStatus = $status;
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $started > $timeout) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            throw new RuntimeException('Превышено время ожидания системной команды');
        }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    // Классическая ловушка PHP: после proc_get_status() proc_close() возвращает -1.
    // Берём код выхода из последнего статуса.
    $code = ($lastStatus !== null && isset($lastStatus['exitcode']) && (int)$lastStatus['exitcode'] !== -1)
        ? (int)$lastStatus['exitcode']
        : (int)proc_close($process);
    if ($code === -1 && $lastStatus !== null && isset($lastStatus['exitcode'])) {
        $code = (int)$lastStatus['exitcode'];
    }
    return [$code, labels_utf8($out), labels_utf8($err)];
}

function labels_printers(): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return [];
    }
    $script = '$ErrorActionPreference="Stop"; [Console]::OutputEncoding=[Text.Encoding]::UTF8; '
        . '@(Get-Printer | Select-Object Name,Default) | ConvertTo-Json -Compress';
    [$code, $out, $err] = labels_run_process([
        'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script,
    ], 15);
    if ($code !== 0) {
        throw new RuntimeException('Не удалось получить список принтеров: ' . trim($err ?: $out));
    }
    $out = trim($out);
    if ($out === '' || $out === 'null' || $out === '[]') {
        return [];
    }
    $data = json_decode($out, true);
    if (!is_array($data)) {
        throw new RuntimeException('Не удалось разобрать список принтеров');
    }
    if (isset($data['Name'])) {
        $data = [$data];
    }
    $rows = [];
    foreach ($data as $printer) {
        if (!is_array($printer) || trim((string)($printer['Name'] ?? '')) === '') {
            continue;
        }
        $rows[] = ['name' => (string)$printer['Name'], 'default' => !empty($printer['Default'])];
    }
    return $rows;
}

function labels_print_raw(string $printer, string $zpl): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Прямая печать доступна только на Windows-сервере');
    }
    $names = array_column(labels_printers(), 'name');
    if (!in_array($printer, $names, true)) {
        throw new RuntimeException('Принтер не найден');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'wms_zpl_');
    if ($tmp === false) {
        throw new RuntimeException('Не удалось создать временный файл');
    }
    try {
        if (file_put_contents($tmp, $zpl, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать ZPL во временный файл');
        }
        $script = __DIR__ . '/print-zpl.ps1';
        [$code, $out, $err] = labels_run_process([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $script, '-Printer', $printer, '-Path', $tmp,
        ], 30);
        if ($code !== 0) {
            throw new RuntimeException('Ошибка печати: ' . trim($err ?: $out));
        }
    } finally {
        @unlink($tmp);
    }
}

function labels_open_printer_dialog(string $mode, string $printer = ''): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('Окна Windows доступны только на Windows-сервере');
    }
    if ($mode === 'add') {
        $cmd = 'start "" rundll32.exe printui.dll,PrintUIEntry /il';
    } elseif ($mode === 'properties') {
        $names = array_column(labels_printers(), 'name');
        if (!in_array($printer, $names, true)) {
            throw new RuntimeException('Принтер не найден');
        }
        $cmd = 'start "" rundll32.exe printui.dll,PrintUIEntry /p /n ' . escapeshellarg($printer);
    } else {
        throw new RuntimeException('Неизвестное действие с принтером');
    }
    $handle = @popen($cmd, 'r');
    if (!is_resource($handle)) {
        throw new RuntimeException('Не удалось открыть системное окно Windows');
    }
    pclose($handle);
}

function labels_log_batch(PDO $pdo, array $tpl, array $codes, array $actor, string $type, int $qty): int
{
    $now = labels_now_sql($pdo);
    $from = $codes[0] ?? null;
    $to = $codes ? $codes[count($codes) - 1] : null;
    $st = $pdo->prepare("INSERT INTO label_print_log (label_type,label_name,qty,code_from,code_to,user_id,user_login,user_fio,created_at) VALUES (:t,:n,:q,:f,:to,:uid,:ul,:uf,$now)");
    $st->execute([
        ':t' => $type, ':n' => (string)$tpl['label_name'], ':q' => $qty,
        ':f' => $from, ':to' => $to,
        ':uid' => $actor['id'] ?: null, ':ul' => $actor['login'] ?: null, ':uf' => $actor['fio'] ?: null,
    ]);
    $batchId = (int)$pdo->lastInsertId();
    $d = labels_driver($pdo);
    foreach ($codes as $code) {
        try {
            if ($d === 'pgsql') {
                $pdo->prepare("INSERT INTO label_codes (label_type,code,batch_id,user_id,user_login) VALUES (:t,:c,:b,:u,:l) ON CONFLICT (code) DO NOTHING")
                    ->execute([':t' => $type, ':c' => $code, ':b' => $batchId, ':u' => $actor['id'] ?: null, ':l' => $actor['login'] ?: null]);
            } elseif ($d === 'mysql') {
                $pdo->prepare("INSERT IGNORE INTO label_codes (label_type,code,batch_id,user_id,user_login) VALUES (:t,:c,:b,:u,:l)")
                    ->execute([':t' => $type, ':c' => $code, ':b' => $batchId, ':u' => $actor['id'] ?: null, ':l' => $actor['login'] ?: null]);
            } else {
                $pdo->prepare("INSERT OR IGNORE INTO label_codes (label_type,code,batch_id,user_id,user_login) VALUES (:t,:c,:b,:u,:l)")
                    ->execute([':t' => $type, ':c' => $code, ':b' => $batchId, ':u' => $actor['id'] ?: null, ':l' => $actor['login'] ?: null]);
            }
        } catch (Throwable) {
        }
    }
    return $batchId;
}

$pdo = labels_pdo();
$action = $_GET['action'] ?? 'templates';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
require_once __DIR__ . '/../shared/LabelPrint.php';
// Печать этикеток и просмотр кодов — только администратор и разработчик.
if (!wms_labels_can_print()) {
    wms_labels_deny_json();
}

header('Content-Type: application/json; charset=utf-8');

/** JSON-ответ, который всегда отдаёт тело (даже при битой кодировке текста). */
function labels_json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $s = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($s === false) {
        $s = json_encode(['error' => 'Не удалось сформировать ответ сервера (кодировка)'], JSON_UNESCAPED_UNICODE);
    }
    echo $s;
    exit;
}

try {
    if ($action === 'templates') {
        $rows = $pdo->query("SELECT label_type,label_name,title,prefix,width_mm,height_mm,updated_at FROM label_templates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'printers' && $method === 'GET') {
        try {
            echo json_encode(['rows' => labels_printers(), 'supported' => PHP_OS_FAMILY === 'Windows'], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['rows' => [], 'supported' => PHP_OS_FAMILY === 'Windows', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'template_create' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $type = strtolower(trim((string)($b['type'] ?? '')));
        $name = trim((string)($b['name'] ?? ''));
        $title = trim((string)($b['title'] ?? ''));
        $prefix = trim((string)($b['prefix'] ?? ''));
        $zpl = (string)($b['zpl'] ?? '');
        $sql = (string)($b['sql'] ?? '');
        $width = (int)($b['width_mm'] ?? 58);
        $height = (int)($b['height_mm'] ?? 40);
        if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $type)) {
            throw new RuntimeException('Код типа: латиница, цифры и _, от 2 до 30 символов');
        }
        if ($type === 'stock_sscc') {
            throw new RuntimeException('Этот тип зарезервирован для SSCC товарного запаса');
        }
        if ($name === '' || $title === '') {
            throw new RuntimeException('Укажите название и заголовок этикетки');
        }
        if (trim($zpl) === '') {
            throw new RuntimeException('Укажите ZPL-код');
        }
        $prefixLen = function_exists('mb_strlen') ? mb_strlen($prefix) : strlen($prefix);
        if ($prefixLen > 10) {
            throw new RuntimeException('Префикс не длиннее 10 символов');
        }
        if ($width < 10 || $width > 200 || $height < 10 || $height > 200) {
            throw new RuntimeException('Размер этикетки должен быть от 10 до 200 мм');
        }
        $exists = $pdo->prepare("SELECT COUNT(*) FROM label_templates WHERE label_type=:t");
        $exists->execute([':t' => $type]);
        if ((int)$exists->fetchColumn() > 0) {
            throw new RuntimeException('Этикетка с таким кодом уже существует');
        }
        $pdo->prepare("INSERT INTO label_templates (label_type,label_name,title,prefix,zpl_template,sql_query,width_mm,height_mm) VALUES (:t,:n,:title,:p,:zpl,:sql,:w,:h)")
            ->execute([
                ':t' => $type, ':n' => $name, ':title' => $title, ':p' => $prefix,
                ':zpl' => $zpl, ':sql' => $sql, ':w' => $width, ':h' => $height,
            ]);
        echo json_encode(['ok' => true, 'type' => $type], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'template' && $method === 'GET') {
        $type = trim((string)($_GET['type'] ?? ''));
        $row = labels_template($pdo, $type);
        if (!$row) throw new RuntimeException('Шаблон не найден');
        echo json_encode(['row' => $row], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'template_save' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $type = trim((string)($b['type'] ?? ''));
        $zpl = (string)($b['zpl'] ?? '');
        $sql = (string)($b['sql'] ?? '');
        if ($type === '' || trim($zpl) === '') throw new RuntimeException('Укажите тип и ZPL-код');
        $now = labels_now_sql($pdo);
        $pdo->prepare("UPDATE label_templates SET zpl_template=:z, sql_query=:s, updated_at=$now WHERE label_type=:t")
            ->execute([':z' => $zpl, ':s' => $sql, ':t' => $type]);
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'print' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $type = trim((string)($b['type'] ?? ''));
        $qty = (int)($b['qty'] ?? 1);
        // Служебные типы (товар/ячейка/паллета/пустая) печатаются из других модулей,
        // здесь — только редактирование кода.
        if (in_array($type, ['product', 'cell', 'pallet', 'blank'], true)) {
            throw new RuntimeException('Печать этого типа недоступна в модуле «Этикетки» — только редактирование кода');
        }
        if ($type === '' || $type === 'stock_sscc') throw new RuntimeException('Неверный тип этикетки');
        if ($qty < 1 || $qty > 500) throw new RuntimeException('Количество должно быть от 1 до 500');
        $tpl = labels_template($pdo, $type);
        if (!$tpl) throw new RuntimeException('Шаблон не найден');
        $actor = labels_actor();
        $day = date('Y-m-d');
        $datePart = date('dmy');
        $pdo->beginTransaction();
        try {
            $lock = labels_driver($pdo) === 'pgsql' ? ' FOR UPDATE' : '';
            $st = $pdo->prepare("SELECT last_no FROM label_sequences WHERE label_type=:t AND seq_day=:d$lock");
            $st->execute([':t' => $type, ':d' => $day]);
            $last = $st->fetchColumn();
            if ($last === false) {
                try {
                    $pdo->prepare("INSERT INTO label_sequences (label_type,seq_day,last_no) VALUES (:t,:d,0)")
                        ->execute([':t' => $type, ':d' => $day]);
                } catch (Throwable) {
                }
                $st->execute([':t' => $type, ':d' => $day]);
                $last = $st->fetchColumn();
            }
            $last = (int)$last;
            $codes = [];
            $zpl = '';
            for ($i = 1; $i <= $qty; $i++) {
                $code = (string)$tpl['prefix'] . $datePart . '-' . str_pad((string)($last + $i), 5, '0', STR_PAD_LEFT);
                $codes[] = $code;
                $zpl .= labels_zpl($tpl, $code);
            }
            $pdo->prepare("UPDATE label_sequences SET last_no=:n WHERE label_type=:t AND seq_day=:d")
                ->execute([':n' => $last + $qty, ':t' => $type, ':d' => $day]);
            $batchId = labels_log_batch($pdo, $tpl, $codes, $actor, $type, $qty);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        echo json_encode([
            'ok' => true, 'batch_id' => $batchId, 'type' => $type, 'label_name' => $tpl['label_name'],
            'title' => (string)($tpl['title'] ?? ''), 'width_mm' => (int)($tpl['width_mm'] ?? 58),
            'height_mm' => (int)($tpl['height_mm'] ?? 40), 'qty' => $qty, 'codes' => $codes, 'zpl' => $zpl,
            'sql' => (string)($tpl['sql_query'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Печать этикеток (Термо): растровый ZPL с настоящей кириллицей ---
    // POST ?action=print_label {printer, type, codes:[...] | code, dpi?}
    if ($action === 'print_label' && $method === 'POST') {
        require_once __DIR__ . '/../shared/LabelPrint.php';
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $printer = trim((string)($b['printer'] ?? ''));
        $type = trim((string)($b['type'] ?? ''));
        $codes = array_values(array_filter(array_map('trim', (array)($b['codes'] ?? [])), fn($v) => $v !== ''));
        if (!$codes && trim((string)($b['code'] ?? '')) !== '') {
            $codes = [trim((string)$b['code'])];
        }
        if ($printer === '' || !$codes) {
            throw new RuntimeException('Не выбран принтер или нет кодов этикеток');
        }
        if (count($codes) > 500) {
            throw new RuntimeException('Не больше 500 этикеток за одну печать');
        }
        $tpl = $type !== '' ? labels_template($pdo, $type) : null;
        $title = $tpl ? (string)($tpl['title'] ?? '') : trim((string)($b['title'] ?? 'Этикетка'));
        $wMm = $tpl ? (float)($tpl['width_mm'] ?? 58) : 58.0;
        $hMm = $tpl ? (float)($tpl['height_mm'] ?? 40) : 40.0;
        if (isset($b['w']) && (float)$b['w'] > 0) {
            $wMm = (float)$b['w'];
        }
        if (isset($b['h']) && (float)$b['h'] > 0) {
            $hMm = (float)$b['h'];
        }
        $dpi = in_array((int)($b['dpi'] ?? 203), [203, 300], true) ? (int)$b['dpi'] : 203;
        $specs = [];
        foreach ($codes as $code) {
            $specs[] = wms_label_spec($title, (string)$code, (string)$code, $wMm, $hMm, $dpi, 1);
        }
        $joined = [
            'text' => implode("NEXT\n", array_column($specs, 'text')),
            'width' => $specs[0]['width'],
            'height' => $specs[0]['height'],
            'dpi' => $dpi,
            'copies' => count($codes),
        ];
        labels_print_log_action($pdo, $type, count($codes), $printer, 'thermal');
        wms_label_print_thermal($printer, $joined);
        labels_json_out(['ok' => true, 'printer' => $printer, 'count' => count($codes)]);
    }

    // --- Пустая этикетка: произвольные данные, ШК сверху (по галке «Печать ШК») ---
    // POST ?action=custom_print {printer, text, barcode?, barcode_top, w, h, copies, dpi?}
    if ($action === 'custom_print' && $method === 'POST') {
        require_once __DIR__ . '/../shared/LabelPrint.php';
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $printer = trim((string)($b['printer'] ?? ''));
        $text = (string)($b['text'] ?? '');
        $barcode = trim((string)($b['barcode'] ?? ''));
        $barcodeTop = !empty($b['barcode_top']);
        $wMm = (float)($b['w'] ?? 70);
        $hMm = (float)($b['h'] ?? 48);
        $copies = max(1, min(500, (int)($b['copies'] ?? 1)));
        $dpi = in_array((int)($b['dpi'] ?? 203), [203, 300], true) ? (int)$b['dpi'] : 203;
        if ($printer === '') {
            throw new RuntimeException('Не выбран принтер');
        }
        if (trim($text) === '' && $barcode === '') {
            throw new RuntimeException('Нет данных для этикетки');
        }
        $spec = wms_label_spec_custom($text, $barcode, $wMm, $hMm, $dpi, $copies, $barcodeTop);
        labels_print_log_action($pdo, 'blank', $copies, $printer, 'thermal');
        wms_label_print_thermal($printer, $spec);
        labels_json_out(['ok' => true, 'printer' => $printer, 'copies' => $copies]);
    }

    // GET/POST ?action=custom_pdf {text, barcode?, barcode_top, w, h}
    if ($action === 'custom_pdf') {
        require_once __DIR__ . '/../shared/Pdf.php';
        $b = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_GET;
        $text = (string)($b['text'] ?? '');
        $barcode = trim((string)($b['barcode'] ?? ''));
        $barcodeTop = !empty($b['barcode_top']);
        $wMm = (float)($b['w'] ?? 70);
        $hMm = (float)($b['h'] ?? 48);
        if (trim($text) === '' && $barcode === '') {
            throw new RuntimeException('Нет данных для этикетки');
        }
        $pdf = wms_pdf_label_pages([[
            'title' => '', 'subtitle' => '', 'code' => $barcodeTop ? $barcode : '',
            'barcode' => $barcode, 'lines' => preg_split('/\r\n|\r|\n/', $text) ?: [],
            'barcode_top' => $barcodeTop,
        ]], $wMm, $hMm);
        labels_print_log_action($pdo, 'blank', 1, 'pdf', 'pdf');
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="label_custom.pdf"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: no-store');
        }
        echo $pdf;
        exit;
    }
    // --- PDF-этикетка (как в модуле «Номенклатура»): страница ровно по размеру ---
    // GET ?action=pdf&batch_id=.. | &type=..&codes=code1,code2 | &code=..
    if ($action === 'pdf') {
        require_once __DIR__ . '/../shared/Pdf.php';
        $type = trim((string)($_GET['type'] ?? ''));
        $tpl = $type !== '' ? labels_template($pdo, $type) : null;
        $title = $tpl ? (string)($tpl['title'] ?? '') : trim((string)($_GET['title'] ?? 'Этикетка'));
        $wMm = $tpl ? (float)($tpl['width_mm'] ?? 58) : (float)($_GET['w'] ?? 58);
        $hMm = $tpl ? (float)($tpl['height_mm'] ?? 40) : (float)($_GET['h'] ?? 40);
        if (isset($_GET['w']) && (float)$_GET['w'] > 0) {
            $wMm = (float)$_GET['w'];
        }
        if (isset($_GET['h']) && (float)$_GET['h'] > 0) {
            $hMm = (float)$_GET['h'];
        }
        $codes = [];
        $batchId = (int)($_GET['batch_id'] ?? 0);
        if ($batchId > 0) {
            $st = $pdo->prepare('SELECT code FROM label_codes WHERE batch_id=:b ORDER BY id');
            $st->execute([':b' => $batchId]);
            $codes = $st->fetchAll(PDO::FETCH_COLUMN);
        }
        if (!$codes && isset($_GET['codes'])) {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string)$_GET['codes'])), fn($v) => $v !== ''));
        }
        if (!$codes && isset($_GET['code'])) {
            $codes = [trim((string)$_GET['code'])];
        }
        if (!$codes) {
            throw new RuntimeException('Нет кодов для PDF-этикетки');
        }
        $labels = [];
        foreach ($codes as $code) {
            $labels[] = ['title' => $title, 'code' => (string)$code, 'barcode' => (string)$code];
        }
        $pdf = wms_pdf_label_pages($labels, $wMm, $hMm);
        labels_print_log_action($pdo, $type, count($codes), 'pdf', 'pdf');
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="labels_' . preg_replace('/\W+/', '', $type ?: 'label') . '.pdf"');
            header('Content-Length: ' . strlen($pdf));
            header('Cache-Control: no-store');
        }
        echo $pdf;
        exit;
    }
    if ($action === 'print_raw' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $printer = trim((string)($b['printer'] ?? ''));
        $zpl = (string)($b['zpl'] ?? '');
        if ($printer === '' || trim($zpl) === '') {
            throw new RuntimeException('Не выбран принтер или нет ZPL');
        }
        if (strlen($zpl) > 2000000) {
            throw new RuntimeException('Слишком большой объём ZPL');
        }
        labels_print_raw($printer, $zpl);
        echo json_encode(['ok' => true, 'printer' => $printer], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'printer_dialog' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $mode = trim((string)($b['mode'] ?? ''));
        $printer = trim((string)($b['printer'] ?? ''));
        labels_open_printer_dialog($mode, $printer);
        echo json_encode(['ok' => true, 'mode' => $mode], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'print_sscc' && $method === 'POST') {
        $b = json_decode(file_get_contents('php://input'), true) ?? [];
        $stockId = (int)($b['stock_id'] ?? 0);
        if ($stockId <= 0) throw new RuntimeException('Не указана строка запаса');
        $npdo = wms_nomenclature_pdo();
        $st = $npdo->prepare("SELECT id, pallet FROM product_stock WHERE id=:id LIMIT 1");
        $st->execute([':id' => $stockId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Строка запаса не найдена');
        $code = trim((string)($row['pallet'] ?? ''));
        if ($code === '') throw new RuntimeException('У выбранной строки нет SSCC/паллеты');
        $tpl = labels_template($pdo, 'stock_sscc');
        if (!$tpl) throw new RuntimeException('Шаблон SSCC не найден');
        $actor = labels_actor();
        $pdo->beginTransaction();
        try {
            $batchId = labels_log_batch($pdo, $tpl, [$code], $actor, 'stock_sscc', 1);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        echo json_encode([
            'ok' => true, 'batch_id' => $batchId, 'type' => 'stock_sscc',
            'label_name' => $tpl['label_name'], 'title' => (string)($tpl['title'] ?? ''),
            'width_mm' => (int)($tpl['width_mm'] ?? 58), 'height_mm' => (int)($tpl['height_mm'] ?? 40),
            'qty' => 1, 'codes' => [$code],
            'zpl' => labels_zpl($tpl, $code), 'sql' => (string)($tpl['sql_query'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'log' && $method === 'GET') {
        $st = $pdo->query("SELECT * FROM label_print_log ORDER BY id DESC LIMIT 200");
        echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'codes' && $method === 'GET') {
        $id = (int)($_GET['batch_id'] ?? 0);
        $st = $pdo->prepare("SELECT code FROM label_codes WHERE batch_id=:id ORDER BY id");
        $st->execute([':id' => $id]);
        echo json_encode(['rows' => $st->fetchAll(PDO::FETCH_COLUMN)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException('Неизвестное действие');
} catch (Throwable $e) {
    require_once __DIR__ . '/../shared/ErrorLog.php';
    wms_error_log_write('error', $e->getMessage(), [
        'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString(),
    ]);
    labels_json_out(['error' => $e->getMessage()], 400);
}
