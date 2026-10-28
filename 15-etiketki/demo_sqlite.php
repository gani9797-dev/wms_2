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
    $zpl = <<<'ZPL'
^XA
^CI28
^PW464
^LL320
^FO0,10^A0N,30,30^FB464,1,0,C^FD{{TITLE}}^FS
^FO55,80^BY2,2.0,110^BCN,110,Y,N,N^FD{{CODE}}^FS
^FO0,210^A0N,24,24^FB464,1,0,C^FD{{CODE}}^FS
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
        ['shipment', 'Отгрузка', 'Отгрузка', 'S-', $zpl, $sqlShipment, 58, 40],
        ['receipt', 'Приемка', 'Приемка', 'A-', $zpl, $sqlReceipt, 58, 40],
        ['picking', 'Комплектация', 'Комплектация', 'PL-', $zpl, $sqlPicking, 58, 40],
        ['stock_sscc', 'SSCC (товарный запас)', 'Комплектация', 'SSCC', $zpl, $sqlStock, 58, 40],
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
    $ins = $pdo->prepare("INSERT INTO label_templates (label_type,label_name,title,prefix,zpl_template,sql_query,width_mm,height_mm) VALUES (:t,:n,:title,:p,:zpl,:sql,58,40)");
    foreach (labels_default_templates() as $tpl) {
        $exists->execute([':t' => $tpl[0]]);
        if ((int)$exists->fetchColumn() === 0) {
            $ins->execute([
                ':t' => $tpl[0], ':n' => $tpl[1], ':title' => $tpl[2], ':p' => $tpl[3],
                ':zpl' => $tpl[4], ':sql' => $tpl[5],
            ]);
        }
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

function labels_actor(): array
{
    $u = wms_auth_user();
    return [
        'id' => $u ? (int)($u['id'] ?? 0) : 0,
        'login' => $u ? (string)($u['login'] ?? '') : '',
        'fio' => $u ? (string)($u['fio'] ?? '') : '',
    ];
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
header('Content-Type: application/json; charset=utf-8');

try {
    if ($action === 'templates') {
        $rows = $pdo->query("SELECT label_type,label_name,title,prefix,width_mm,height_mm,updated_at FROM label_templates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
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
        $type = (string)($b['type'] ?? '');
        $qty = (int)($b['qty'] ?? 1);
        if (!in_array($type, ['shipment', 'receipt', 'picking'], true)) throw new RuntimeException('Неверный тип этикетки');
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
            'qty' => $qty, 'codes' => $codes, 'zpl' => $zpl,
            'sql' => (string)($tpl['sql_query'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
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
            'label_name' => $tpl['label_name'], 'qty' => 1, 'codes' => [$code],
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
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
