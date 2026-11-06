<?php
declare(strict_types=1);
/**
 * Интеграция WMS с внешней системой (ERP/TMS/1С/маркетплейс).
 *
 * REST /api/v1, JSON, Bearer JWT (OAuth2 client_credentials), HTTPS на стороне
 * прокси. Идемпотентность по external_id, пагинация, rate limit, единый формат
 * ошибок, журнал запросов (30 дней) и исходящие вебхуки с HMAC-SHA256 и
 * повторами 1/5/30 минут (outbox).
 *
 * Сценарии:
 *   1) POST /orders          — заказ ERP → WMS (идемпотентно по external_id);
 *   2) вебхуки shipment.status / order.status — WMS → ERP (подпись HMAC);
 *   3) GET  /stock           — остатки по ячейкам WMS → ERP;
 *   4) POST /nomenclature    — номенклатура и штрихкоды ERP → WMS.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/Journal.php';

function wms_api_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = function_exists('wms_nomenclature_pdo') ? wms_nomenclature_pdo() : wms_pg_pdo();
    wms_api_ensure($pdo);
    return $pdo;
}

/** Схема интеграции (идемпотентно). */
function wms_api_ensure(PDO $pdo): void
{
    static $done = [];
    $id = spl_object_id($pdo);
    if (isset($done[$id])) {
        return;
    }
    $done[$id] = true;
    try {
        $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pg = $drv === 'pgsql';
        $auto = $pg ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $ts = $pg ? 'TIMESTAMP' : 'TEXT';
        $now = $pg ? 'NOW()' : "datetime('now')";
        $pdo->exec("CREATE TABLE IF NOT EXISTS wms_api_settings (
            id " . ($pg ? 'BIGINT PRIMARY KEY' : 'INTEGER PRIMARY KEY') . ",
            enabled " . ($pg ? 'SMALLINT' : 'INTEGER') . " NOT NULL DEFAULT 1,
            client_id VARCHAR(100) NOT NULL DEFAULT 'wms-client',
            client_secret VARCHAR(255) NOT NULL DEFAULT '',
            jwt_secret VARCHAR(255) NOT NULL DEFAULT '',
            callback_url VARCHAR(500) NULL,
            webhook_secret VARCHAR(255) NULL,
            rate_limit INTEGER NOT NULL DEFAULT 100,
            token_ttl INTEGER NOT NULL DEFAULT 3600,
            updated_at $ts NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wms_api_log (
            id $auto,
            request_id VARCHAR(64) NULL,
            direction VARCHAR(10) NOT NULL DEFAULT 'IN',
            client_id VARCHAR(100) NULL,
            method VARCHAR(10) NULL,
            path VARCHAR(500) NULL,
            status_code INTEGER NULL,
            duration_ms INTEGER NULL,
            ip VARCHAR(64) NULL,
            request_body TEXT NULL,
            response_body TEXT NULL,
            error TEXT NULL,
            created_at $ts DEFAULT $now)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wms_api_rate (
            id $auto,
            api_key VARCHAR(100) NOT NULL,
            ts $ts DEFAULT $now)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wms_webhook_outbox (
            id $auto,
            event VARCHAR(50) NOT NULL,
            external_id VARCHAR(100) NULL,
            payload TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at $ts NULL,
            last_error TEXT NULL,
            http_code INTEGER NULL,
            created_at $ts DEFAULT $now,
            sent_at $ts NULL)");
        try {
            wms_ensure_column($pdo, 'client_orders', 'external_id', 'VARCHAR(100) NULL', 'external_id TEXT', 'VARCHAR(100) NULL');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_order_external ON client_orders (external_id) WHERE external_id IS NOT NULL');
        } catch (Throwable) {
        }
        foreach ([
            'CREATE INDEX IF NOT EXISTS idx_api_log_created ON wms_api_log (created_at)',
            'CREATE INDEX IF NOT EXISTS idx_api_log_status ON wms_api_log (status_code)',
            'CREATE INDEX IF NOT EXISTS idx_api_rate_key ON wms_api_rate (api_key, ts)',
            'CREATE INDEX IF NOT EXISTS idx_wh_outbox_status ON wms_webhook_outbox (status, next_attempt_at)',
        ] as $ix) {
            try {
                $pdo->exec($ix);
            } catch (Throwable) {
            }
        }
        try {
            $row = $pdo->query('SELECT * FROM wms_api_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $row = null;
        }
        if (!$row) {
            try {
                $secret = wms_api_random(32);
                $pdo->prepare("INSERT INTO wms_api_settings (id,enabled,client_id,client_secret,jwt_secret,rate_limit,token_ttl)
                        VALUES (1,1,'wms-client',:cs,:js,100,3600)")
                    ->execute([':cs' => $secret, ':js' => wms_api_random(48)]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

function wms_api_random(int $len): string
{
    try {
        return substr(bin2hex(random_bytes(max(8, intdiv($len, 2)))), 0, $len);
    } catch (Throwable) {
        return substr(sha1(uniqid('', true) . mt_rand()), 0, $len);
    }
}

/** Настройки интеграции (секреты — как есть; наружу отдаём маскированными). */
function wms_api_settings(PDO $pdo): array
{
    $def = ['id' => 1, 'enabled' => 1, 'client_id' => 'wms-client', 'client_secret' => '', 'jwt_secret' => '',
        'callback_url' => '', 'webhook_secret' => '', 'rate_limit' => 100, 'token_ttl' => 3600, 'updated_at' => null];
    try {
        $row = $pdo->query('SELECT * FROM wms_api_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return array_merge($def, $row);
        }
    } catch (Throwable) {
    }
    return $def;
}

/** Сохранить настройки (секреты можно не передавать — останутся прежними). */
function wms_api_settings_save(PDO $pdo, array $b): array
{
    wms_api_ensure($pdo);
    $cur = wms_api_settings($pdo);
    $set = [
        'enabled' => !empty($b['enabled']) ? 1 : 0,
        'client_id' => trim((string)($b['client_id'] ?? $cur['client_id'])) ?: 'wms-client',
        'callback_url' => trim((string)($b['callback_url'] ?? $cur['callback_url'])),
        'rate_limit' => max(1, min(10000, (int)($b['rate_limit'] ?? $cur['rate_limit']))),
        'token_ttl' => max(60, min(3600, (int)($b['token_ttl'] ?? $cur['token_ttl']))),
    ];
    foreach (['client_secret', 'jwt_secret', 'webhook_secret'] as $k) {
        $v = trim((string)($b[$k] ?? ''));
        if ($v !== '') {
            $set[$k] = $v;
        }
    }
    if (array_key_exists('client_secret', $set) && $set['client_secret'] === '') {
        $set['client_secret'] = wms_api_random(32);
    }
    $now = function_exists('wms_now_sql') ? wms_now_sql($pdo) : "datetime('now')";
    $cols = array_keys($set);
    $p = [];
    foreach ($set as $k => $v) {
        $p[':' . $k] = $v;
    }
    $sql = 'UPDATE wms_api_settings SET ' . implode(', ', array_map(fn($c) => "$c = :$c", $cols)) . ", updated_at = $now WHERE id = 1";
    $pdo->prepare($sql)->execute($p);
    return wms_api_settings($pdo);
}

/** Ротация секрета клиента. */
function wms_api_regenerate(PDO $pdo, string $which): array
{
    $which = in_array($which, ['client_secret', 'jwt_secret', 'webhook_secret'], true) ? $which : 'client_secret';
    $v = wms_api_random($which === 'webhook_secret' ? 40 : 32);
    $pdo->prepare("UPDATE wms_api_settings SET $which = :v WHERE id = 1")->execute([':v' => $v]);
    return wms_api_settings($pdo);
}

// ============================================================
// ТОКЕНЫ (JWT HS256) и RATE LIMIT
// ============================================================

function wms_api_b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function wms_api_b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return (string)base64_decode(strtr($s, '-_', '+/'));
}

/** Выдать access_token (JWT HS256). */
function wms_api_issue_token(array $settings, array $claims = []): array
{
    $ttl = max(60, min(3600, (int)($settings['token_ttl'] ?? 3600)));
    $now = time();
    $payload = array_merge([
        'iss' => 'wms',
        'sub' => (string)($settings['client_id'] ?? 'wms-client'),
        'iat' => $now,
        'exp' => $now + $ttl,
        'jti' => wms_api_random(24),
    ], $claims);
    $head = wms_api_b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_UNICODE));
    $body = wms_api_b64url(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = wms_api_b64url(hash_hmac('sha256', $head . '.' . $body, (string)($settings['jwt_secret'] ?? ''), true));
    return [
        'access_token' => $head . '.' . $body . '.' . $sig,
        'token_type' => 'Bearer',
        'expires_in' => $ttl,
    ];
}

/** Проверить Bearer-токен. @return array|null claims */
function wms_api_verify_token(PDO $pdo, ?string $header): ?array
{
    $h = trim((string)$header);
    if (!preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
        return null;
    }
    $parts = explode('.', trim($m[1]));
    if (count($parts) !== 3) {
        return null;
    }
    $settings = wms_api_settings($pdo);
    $expect = wms_api_b64url(hash_hmac('sha256', $parts[0] . '.' . $parts[1], (string)$settings['jwt_secret'], true));
    if (!hash_equals($expect, $parts[2])) {
        return null;
    }
    $payload = json_decode(wms_api_b64url_decode($parts[1]), true);
    if (!is_array($payload)) {
        return null;
    }
    if ((int)($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

/** Проверка лимита запросов. @return bool true — можно, false — превышен */
function wms_api_rate_ok(PDO $pdo, string $key, int $limit): bool
{
    try {
        $now = time();
        $pdo->prepare('INSERT INTO wms_api_rate (api_key, ts) VALUES (:k, :ts)')
            ->execute([':k' => $key, ':ts' => date('Y-m-d H:i:s', $now)]);
        $st = $pdo->prepare("SELECT COUNT(*) FROM wms_api_rate WHERE api_key=:k AND ts >= :from");
        $st->execute([':k' => $key, ':from' => date('Y-m-d H:i:s', $now - 1)]);
        $cnt = (int)$st->fetchColumn();
        // Чистим старые метки (раз в ~50 запросов).
        if (mt_rand(1, 50) === 1) {
            try {
                $pdo->prepare('DELETE FROM wms_api_rate WHERE ts < :old')->execute([':old' => date('Y-m-d H:i:s', $now - 120)]);
            } catch (Throwable) {
            }
        }
        return $cnt <= max(1, $limit);
    } catch (Throwable) {
        return true;
    }
}

// ============================================================
// ЛОГИ (30 дней) и МАСКИРОВАНИЕ СЕКРЕТОВ
// ============================================================

function wms_api_mask(string $s): string
{
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/((?:client_secret|secret|password|token|authorization)"?\s*[:=]\s*"?)([^",}\s]+)/i', '$1***', $s) ?? $s;
    // Персональные данные в журнал не пишем.
    $s = preg_replace('/("(?:consignee|phone|address|email|fio)"\s*:\s*")([^"]*)(")/iu', '$1***$3', $s) ?? $s;
    $s = preg_replace('/((?:consignee|phone|address|email|fio)"?\s*[:=]\s*"?)([^",}\s]+)/iu', '$1***', $s) ?? $s;
    return function_exists('wms_error_cut') ? wms_error_cut($s, 4000) : substr($s, 0, 4000);
}

/** Записать запрос/ответ в журнал API. */
function wms_api_log(PDO $pdo, array $r): void
{
    try {
        $st = $pdo->prepare("INSERT INTO wms_api_log (request_id,direction,client_id,method,path,status_code,
                duration_ms,ip,request_body,response_body,error,created_at)
            VALUES (:rid,:dir,:cl,:m,:p,:sc,:ms,:ip,:req,:res,:err," . (function_exists('wms_now_sql') ? wms_now_sql($pdo) : "datetime('now')") . ')');
        $st->execute([
            ':rid' => substr((string)($r['request_id'] ?? ''), 0, 64),
            ':dir' => (string)($r['direction'] ?? 'IN'),
            ':cl' => substr((string)($r['client_id'] ?? ''), 0, 100),
            ':m' => substr((string)($r['method'] ?? ''), 0, 10),
            ':p' => substr((string)($r['path'] ?? ''), 0, 500),
            ':sc' => isset($r['status_code']) ? (int)$r['status_code'] : null,
            ':ms' => isset($r['duration_ms']) ? (int)$r['duration_ms'] : null,
            ':ip' => substr((string)($r['ip'] ?? ''), 0, 64),
            ':req' => wms_api_mask((string)($r['request_body'] ?? '')),
            ':res' => wms_api_mask((string)($r['response_body'] ?? '')),
            ':err' => wms_api_mask((string)($r['error'] ?? '')),
        ]);
        if (mt_rand(1, 20) === 1) {
            try {
                $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $pdo->prepare('DELETE FROM wms_api_log WHERE created_at < :old')
                    ->execute([':old' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

// ============================================================
// ИДЕМПОТЕНТНОСТЬ: заказы и номенклатура
// ============================================================

/** Найти заказ по external_id. */
function wms_api_order_by_external(PDO $pdo, string $ext): ?array
{
    if ($ext === '') {
        return null;
    }
    try {
        if (!wms_has_column($pdo, 'client_orders', 'external_id')) {
            wms_ensure_column($pdo, 'client_orders', 'external_id', 'VARCHAR(100) NULL', 'external_id TEXT', 'VARCHAR(100) NULL');
        }
        $st = $pdo->prepare('SELECT * FROM client_orders WHERE external_id=:e LIMIT 1');
        $st->execute([':e' => $ext]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Создать/обновить заказ. Идемпотентно по external_id.
 * @return array{created:bool,order:array,external_id:string,doc_no:string}
 */
function wms_api_upsert_order(PDO $pdo, array $in): array
{
    $ext = trim((string)($in['external_id'] ?? ''));
    if ($ext === '') {
        throw new WmsApiError('VALIDATION_ERROR', 'external_id обязателен', 400, [['field' => 'external_id']]);
    }
    $items = $in['items'] ?? [];
    if (!is_array($items) || !$items) {
        throw new WmsApiError('VALIDATION_ERROR', 'items обязателен (хотя бы одна строка)', 400, [['field' => 'items']]);
    }
    $existing = wms_api_order_by_external($pdo, $ext);
    $docNo = trim((string)($in['number'] ?? ''));
    if ($docNo === '' || strlen($docNo) > 20) {
        $san = preg_replace('/[^A-Za-z0-9_-]/', '', $ext) ?? '';
        $docNo = 'API-' . (strlen($san) <= 16 ? $san : strtoupper(substr(sha1($ext), 0, 12)));
    }
    $now = function_exists('wms_now_sql') ? wms_now_sql($pdo) : "datetime('now')";

    // Валидация строк: SKU существует в номенклатуре, количество > 0.
    $nom = wms_nomenclature_pdo();
    $checked = [];
    $details = [];
    foreach ($items as $i => $it) {
        $sku = trim((string)($it['sku'] ?? ''));
        $qty = (float)($it['qty'] ?? 0);
        if ($sku === '') {
            $details[] = ['line' => $i + 1, 'field' => 'sku', 'message' => 'SKU обязателен'];
            continue;
        }
        if ($qty <= 0) {
            $details[] = ['line' => $i + 1, 'field' => 'qty', 'message' => 'Количество должно быть > 0'];
            continue;
        }
        $st = $nom->prepare('SELECT sku, name, unit, code FROM products WHERE sku=:s LIMIT 1');
        $st->execute([':s' => $sku]);
        $prod = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prod) {
            $details[] = ['line' => $i + 1, 'field' => 'sku', 'message' => 'SKU не найден в номенклатуре: ' . $sku];
            continue;
        }
        $checked[] = [
            'sku' => (string)$prod['sku'], 'name' => (string)($it['name'] ?? $prod['name']),
            'unit' => (string)($it['unit'] ?? $prod['unit'] ?? 'шт'), 'qty' => $qty,
            'barcode' => trim((string)($it['barcode'] ?? '')),
        ];
    }
    if ($details) {
        throw new WmsApiError('VALIDATION_ERROR', 'Ошибка валидации заказа', 400, $details);
    }

    if ($existing) {
        // Повторная отправка — возвращаем результат первой обработки (без дубля).
        return ['created' => false, 'order' => $existing, 'external_id' => $ext,
            'doc_no' => (string)$existing['doc_no'], 'status' => (string)$existing['status']];
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO client_orders (doc_no,contractor_id,consignee,address,phone,status,warehouse_id,
                comment,external_id,created_at) VALUES (:no,:c,:cg,:ad,:ph,'created',:w,:cm,:ext,$now)");
        $ins->execute([
            ':no' => $docNo,
            ':c' => (int)($in['contractor_id'] ?? 0) ?: null,
            ':cg' => trim((string)($in['consignee'] ?? '')) ?: null,
            ':ad' => trim((string)($in['address'] ?? '')) ?: null,
            ':ph' => trim((string)($in['phone'] ?? '')) ?: null,
            ':w' => (int)($in['warehouse_id'] ?? 1) ?: 1,
            ':cm' => trim((string)($in['comment'] ?? '')) ?: null,
            ':ext' => $ext,
        ]);
        $oid = (int)$pdo->lastInsertId();
        $itemIns = $pdo->prepare("INSERT INTO client_order_items (order_id,sku,name,unit,qty,scan_serial,scan_chz)
                VALUES (:o,:s,:n,:u,:q,0,0)");
        foreach ($checked as $c) {
            $itemIns->execute([':o' => $oid, ':s' => $c['sku'], ':n' => $c['name'], ':u' => $c['unit'], ':q' => $c['qty']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new WmsApiError('INTERNAL_ERROR', 'Не удалось создать заказ: ' . $e->getMessage(), 500);
    }
    $order = wms_api_order_by_external($pdo, $ext) ?: ['id' => $oid ?? 0, 'doc_no' => $docNo, 'status' => 'created'];
    try {
        wms_webhook_enqueue($pdo, 'order.created', $ext, [
            'external_id' => $ext, 'order_id' => (int)($order['id'] ?? 0), 'doc_no' => $docNo, 'status' => 'created',
        ]);
    } catch (Throwable) {
    }
    return ['created' => true, 'order' => $order, 'external_id' => $ext, 'doc_no' => $docNo, 'status' => 'created'];
}

/**
 * Создать/обновить номенклатуру (SKU + штрихкоды). Идемпотентно по SKU.
 * @return array{created:bool,sku:string}
 */
function wms_api_upsert_product(PDO $pdo, array $in): array
{
    $nom = wms_nomenclature_pdo();
    $sku = trim((string)($in['sku'] ?? ''));
    $name = trim((string)($in['name'] ?? ''));
    if ($sku === '' || $name === '') {
        throw new WmsApiError('VALIDATION_ERROR', 'sku и name обязательны', 400,
            [['field' => $sku === '' ? 'sku' : 'name']]);
    }
    $unit = trim((string)($in['unit'] ?? 'шт')) ?: 'шт';
    $code = trim((string)($in['code'] ?? '')) ?: $sku;
    $st = $nom->prepare('SELECT id FROM products WHERE sku=:s LIMIT 1');
    $st->execute([':s' => $sku]);
    $pid = (int)($st->fetchColumn() ?: 0);
    $created = false;
    if ($pid > 0) {
        $nom->prepare('UPDATE products SET name=:n, unit=:u WHERE id=:id')
            ->execute([':n' => $name, ':u' => $unit, ':id' => $pid]);
    } else {
        $ins = $nom->prepare('INSERT INTO products (code,sku,name,unit) VALUES (:c,:s,:n,:u)');
        try {
            $ins->execute([':c' => $code, ':s' => $sku, ':n' => $name, ':u' => $unit]);
            $pid = (int)$nom->lastInsertId();
            $created = true;
        } catch (Throwable $e) {
            throw new WmsApiError('CONFLICT', 'Не удалось создать SKU (код занят?): ' . $e->getMessage(), 409);
        }
    }
    $barcodes = $in['barcodes'] ?? ($in['barcode'] ?? []);
    if (is_string($barcodes)) {
        $barcodes = preg_split('/[\s,;]+/', $barcodes, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
    if (is_array($barcodes)) {
        foreach ($barcodes as $bc) {
            $bc = trim((string)$bc);
            if ($bc === '') {
                continue;
            }
            try {
                $q = $nom->prepare('SELECT id FROM product_barcodes WHERE barcode=:b LIMIT 1');
                $q->execute([':b' => $bc]);
                if ($q->fetchColumn()) {
                    $nom->prepare('UPDATE product_barcodes SET product_id=:p WHERE barcode=:b')
                        ->execute([':p' => $pid, ':b' => $bc]);
                } else {
                    $nom->prepare('INSERT INTO product_barcodes (product_id,barcode) VALUES (:p,:b)')
                        ->execute([':p' => $pid, ':b' => $bc]);
                }
            } catch (Throwable) {
            }
        }
    }
    return ['created' => $created, 'sku' => $sku, 'product_id' => $pid];
}

/** Остатки по ячейкам (пагинация). */
function wms_api_stock(PDO $pdo, int $page, int $pageSize): array
{
    $nom = wms_nomenclature_pdo();
    $page = max(1, $page);
    $pageSize = max(1, min(100, $pageSize));
    $total = (int)$nom->query('SELECT COUNT(*) FROM product_stock s JOIN products p ON p.id=s.product_id WHERE s.qty>0')->fetchColumn();
    $off = ($page - 1) * $pageSize;
    $st = $nom->prepare(
        'SELECT p.sku, p.code AS product_code, p.name, p.unit, p.owner, s.warehouse_id, s.cell, s.pallet,
                s.pallet_type, s.qty, s.quality, s.receipt_no, s.prod_date
           FROM product_stock s JOIN products p ON p.id=s.product_id
          WHERE s.qty>0 ORDER BY s.id LIMIT :lim OFFSET :off'
    );
    $st->bindValue(':lim', $pageSize, PDO::PARAM_INT);
    $st->bindValue(':off', $off, PDO::PARAM_INT);
    $st->execute();
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rows[] = [
            'sku' => (string)$r['sku'], 'product_code' => (string)($r['product_code'] ?? ''),
            'name' => (string)($r['name'] ?? ''), 'unit' => (string)($r['unit'] ?? 'шт'),
            'owner' => (string)($r['owner'] ?? ''), 'warehouse_id' => (int)($r['warehouse_id'] ?? 0),
            'cell' => (string)($r['cell'] ?? ''), 'pallet' => (string)($r['pallet'] ?? ''),
            'pallet_type' => (string)($r['pallet_type'] ?? ''), 'qty' => (float)($r['qty'] ?? 0),
            'quality' => (string)($r['quality'] ?? ''), 'receipt_no' => (string)($r['receipt_no'] ?? ''),
            'prod_date' => $r['prod_date'] ? substr((string)$r['prod_date'], 0, 10) : null,
        ];
    }
    return ['items' => $rows, 'page' => $page, 'page_size' => $pageSize, 'total' => $total,
        'pages' => (int)ceil($total / $pageSize)];
}

// ============================================================
// ВЕБХУКИ (WMS → внешняя система): HMAC + outbox 1/5/30 мин
// ============================================================

/** Поставить событие в очередь вебхуков (отправка — диспетчером). */
function wms_webhook_enqueue(PDO $pdo, string $event, ?string $externalId, array $payload): int
{
    wms_api_ensure($pdo);
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("INSERT INTO wms_webhook_outbox (event,external_id,payload,status,attempts,next_attempt_at,created_at)
            VALUES (:e,:ext,:p,'PENDING',0,:na,:ca)");
    $st->execute([
        ':e' => substr($event, 0, 50), ':ext' => $externalId !== null ? substr($externalId, 0, 100) : null,
        ':p' => json_encode(['event' => $event, 'external_id' => $externalId, 'sent_at' => date('c'),
            'data' => $payload], JSON_UNESCAPED_UNICODE),
        ':na' => $now, ':ca' => $now,
    ]);
    return (int)$pdo->lastInsertId();
}

/** Статус WMS → статус заказа для ERP (принят, сборка, отгружен). */
function wms_api_erp_status(string $wms): string
{
    return match (strtolower($wms)) {
        'draft', 'created' => 'accepted',
        'assembling', 'assembled', 'packing', 'packed', 'on_check', 'checked', 'ready' => 'assembling',
        'shipped', 'delivering', 'delivered' => 'shipped',
        'cancelled', 'archived' => 'cancelled',
        default => strtolower($wms),
    };
}

/**
 * Поставить в очередь вебхук смены статуса отгрузки/заказа.
 * external_id = заказ клиента, связанный с отгрузкой (fallback — doc_no).
 */
function wms_webhook_shipment_status(PDO $pdo, int $shipmentId, string $wmsStatus, array $extra = []): void
{
    try {
        $st = $pdo->prepare(
            'SELECT s.doc_no, s.order_id, o.external_id
               FROM shipments s LEFT JOIN client_orders o ON o.id = s.order_id
              WHERE s.id = :id'
        );
        $st->execute([':id' => $shipmentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $ext = trim((string)($row['external_id'] ?? ''));
        if ($ext === '') {
            $ext = trim((string)($row['doc_no'] ?? ''));
        }
        if ($ext === '') {
            return;
        }
        wms_webhook_enqueue(wms_api_pdo(), 'order.status', $ext, array_merge([
            'external_id' => $ext,
            'shipment_id' => $shipmentId,
            'shipment_doc_no' => (string)($row['doc_no'] ?? ''),
            'wms_status' => $wmsStatus,
            'status' => wms_api_erp_status($wmsStatus),
        ], $extra));
    } catch (Throwable) {
    }
}

/** Отправить один вебхук (HMAC-SHA256). @return array{ok:bool,http:int,error:string} */
function wms_webhook_send_one(PDO $pdo, array $row): array
{
    $s = wms_api_settings($pdo);
    $url = trim((string)($s['callback_url'] ?? ''));
    if ($url === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'callback_url не задан'];
    }
    $body = (string)($row['payload'] ?? '');
    $rid = wms_api_random(16);
    $sig = 'sha256=' . hash_hmac('sha256', $body, (string)($s['webhook_secret'] ?? ''));
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Wms-Signature: $sig\r\nX-Wms-Event: " . (string)$row['event']
            . "\r\nX-Request-Id: $rid\r\n",
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $started = microtime(true);
    $res = @file_get_contents($url, false, $ctx);
    $code = (int)substr((string)($http_response_header[0] ?? '0'), 9, 3);
    $ms = (int)round((microtime(true) - $started) * 1000);
    $ok = $code >= 200 && $code < 300;
    wms_api_log($pdo, [
        'request_id' => $rid, 'direction' => 'OUT', 'method' => 'POST', 'path' => $url,
        'status_code' => $code ?: 0, 'duration_ms' => $ms, 'ip' => 'webhook',
        'request_body' => $body, 'response_body' => (string)$res,
        'error' => $ok ? '' : ('HTTP ' . $code),
    ]);
    return ['ok' => $ok, 'http' => $code, 'error' => $ok ? '' : ('HTTP ' . ($code ?: 'network'))];
}

/**
 * Отправить все созревшие вебхуки (повторы 1/5/30 минут; после — DEAD).
 * @return array{sent:int,failed:int,dead:int}
 */
function wms_webhooks_dispatch_due(PDO $pdo, int $limit = 20): array
{
    wms_api_ensure($pdo);
    $out = ['sent' => 0, 'failed' => 0, 'dead' => 0];
    $delays = [1, 5, 30]; // минуты повторных попыток
    try {
        $st = $pdo->prepare("SELECT * FROM wms_webhook_outbox
                              WHERE status IN ('PENDING','RETRY') AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                              ORDER BY id LIMIT " . max(1, min(100, $limit)));
        $st->execute([':now' => date('Y-m-d H:i:s')]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return $out;
    }
    foreach ($rows as $row) {
        $r = wms_webhook_send_one($pdo, $row);
        $attempts = (int)$row['attempts'] + 1;
        if ($r['ok']) {
            $out['sent']++;
            $pdo->prepare("UPDATE wms_webhook_outbox SET status='SENT', attempts=:a, http_code=:h,
                    sent_at=:st, last_error=NULL WHERE id=:id")
                ->execute([':a' => $attempts, ':h' => $r['http'], ':st' => date('Y-m-d H:i:s'), ':id' => (int)$row['id']]);
        } else {
            $out['failed']++;
            if ($attempts >= count($delays) + 1) {
                $out['dead']++;
                $pdo->prepare("UPDATE wms_webhook_outbox SET status='DEAD', attempts=:a, http_code=:h, last_error=:e WHERE id=:id")
                    ->execute([':a' => $attempts, ':h' => $r['http'], ':e' => $r['error'], ':id' => (int)$row['id']]);
            } else {
                $delay = $delays[$attempts - 1] ?? 30;
                $pdo->prepare("UPDATE wms_webhook_outbox SET status='RETRY', attempts=:a, http_code=:h, last_error=:e,
                        next_attempt_at=:na WHERE id=:id")
                    ->execute([':a' => $attempts, ':h' => $r['http'], ':e' => $r['error'],
                        ':na' => date('Y-m-d H:i:s', time() + $delay * 60), ':id' => (int)$row['id']]);
            }
        }
    }
    return $out;
}

/** Ручной повтор вебхука из журнала. */
function wms_webhook_retry(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM wms_webhook_outbox WHERE id=:id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Вебхук не найден'];
    }
    $pdo->prepare("UPDATE wms_webhook_outbox SET status='PENDING', next_attempt_at=:n, attempts=0 WHERE id=:id")
        ->execute([':n' => date('Y-m-d H:i:s'), ':id' => $id]);
    return wms_webhooks_dispatch_due($pdo, 1) + ['ok' => true];
}

// ============================================================
// ЗДОРОВЬЕ ИНТЕГРАЦИИ (алерты)
// ============================================================

/**
 * Сводка: доля 5xx за последний час, очередь вебхуков, последние ошибки.
 * @return array{errors_1h:int,requests_1h:int,bad_share:float,webhooks_pending:int,webhooks_dead:int,alerts:array}
 */
function wms_api_health(PDO $pdo): array
{
    wms_api_ensure($pdo);
    $out = ['errors_1h' => 0, 'requests_1h' => 0, 'bad_share' => 0.0,
        'webhooks_pending' => 0, 'webhooks_dead' => 0, 'last_error' => null, 'alerts' => []];
    $from = date('Y-m-d H:i:s', time() - 3600);
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM wms_api_log WHERE direction=\'IN\' AND created_at >= :f');
        $st->execute([':f' => $from]);
        $out['requests_1h'] = (int)$st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM wms_api_log WHERE direction=\'IN\' AND status_code >= 500 AND created_at >= :f');
        $st->execute([':f' => $from]);
        $out['errors_1h'] = (int)$st->fetchColumn();
        $out['bad_share'] = $out['requests_1h'] > 0 ? round($out['errors_1h'] / $out['requests_1h'], 4) : 0.0;
        $out['webhooks_pending'] = (int)$pdo->query("SELECT COUNT(*) FROM wms_webhook_outbox WHERE status IN ('PENDING','RETRY')")->fetchColumn();
        $out['webhooks_dead'] = (int)$pdo->query("SELECT COUNT(*) FROM wms_webhook_outbox WHERE status='DEAD'")->fetchColumn();
        $st = $pdo->prepare('SELECT message, created_at FROM wms_api_log WHERE status_code >= 400 AND direction=\'IN\' ORDER BY id DESC LIMIT 1');
        $st->execute();
        $out['last_error'] = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {
    }
    if ($out['requests_1h'] >= 20 && $out['bad_share'] > 0.05) {
        $out['alerts'][] = ['level' => 'error', 'code' => 'HIGH_5XX',
            'message' => 'Рост доли 5xx: ' . round($out['bad_share'] * 100, 1) . '% за последний час (' . $out['errors_1h'] . ' из ' . $out['requests_1h'] . ')'];
    }
    if ($out['webhooks_dead'] > 0) {
        $out['alerts'][] = ['level' => 'error', 'code' => 'WEBHOOK_DEAD',
            'message' => 'Недоставленных вебхуков (DEAD): ' . $out['webhooks_dead'] . ' — данные не потеряны, требуется повтор'];
    } elseif ($out['webhooks_pending'] > 20) {
        $out['alerts'][] = ['level' => 'warning', 'code' => 'WEBHOOK_BACKLOG',
            'message' => 'Очередь вебхуков растёт: ' . $out['webhooks_pending'] . ' ожидают отправки'];
    }
    if ($out['alerts']) {
        try {
            require_once __DIR__ . '/ErrorLog.php';
            foreach ($out['alerts'] as $a) {
                wms_error_log_write($a['level'] === 'error' ? 'error' : 'warning',
                    'Интеграция API: ' . $a['message'], ['context' => ['code' => $a['code']]]);
            }
        } catch (Throwable) {
        }
    }
    return $out;
}

/** Ошибка API с кодом и деталями (единый формат ответа). */
class WmsApiError extends RuntimeException
{
    public function __construct(public string $apiCode, string $message, public int $httpStatus = 400,
        public array $details = [])
    {
        parent::__construct($message);
    }
}
