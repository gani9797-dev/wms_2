<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pg.php';

/**
 * Робот обновления ВГХ (вес-габаритные характеристики) товарного запаса.
 *
 * После изменения габаритов в карточке товара робот пересчитывает и сохраняет
 * снимок ВГХ в product_stock (vgh_*), а также объём единицы в products.volume_m3.
 * 8.1 «Товарный запас» использует снимок, если он есть, иначе считает на лету.
 *
 * Расписание: дата/время начала, дата/время окончания (пусто — бессрочно),
 * интервал в минутах. Есть разовый запуск (кнопка «Запустить единоразово»).
 */

function wms_vgh_robot_ensure(PDO $pdo): void
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
        $pdo->exec("CREATE TABLE IF NOT EXISTS vgh_robot_settings (
            id " . ($pg ? 'BIGINT PRIMARY KEY' : 'INTEGER PRIMARY KEY') . ",
            enabled " . ($pg ? 'SMALLINT' : 'INTEGER') . " NOT NULL DEFAULT 0,
            start_at $ts NULL,
            end_at $ts NULL,
            interval_min INTEGER NOT NULL DEFAULT 60,
            last_run_at $ts NULL,
            next_run_at $ts NULL,
            updated_at $ts NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS vgh_robot_log (
            id $auto,
            started_at $ts NULL,
            finished_at $ts NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'OK',
            processed INTEGER NOT NULL DEFAULT 0,
            updated INTEGER NOT NULL DEFAULT 0,
            products_updated INTEGER NOT NULL DEFAULT 0,
            duration_ms INTEGER NOT NULL DEFAULT 0,
            message TEXT NULL)");
        try {
            $row = $pdo->query('SELECT * FROM vgh_robot_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->prepare("INSERT INTO vgh_robot_settings (id,enabled,interval_min) VALUES (1,0,60)")->execute();
            }
        } catch (Throwable) {
        }
        foreach ([
            'vgh_length_mm' => ['INTEGER NULL', 'INTEGER', 'INTEGER NULL'],
            'vgh_width_mm' => ['INTEGER NULL', 'INTEGER', 'INTEGER NULL'],
            'vgh_height_mm' => ['INTEGER NULL', 'INTEGER', 'INTEGER NULL'],
            'vgh_weight_kg' => ['DECIMAL(14,3) NULL', 'REAL', 'NUMERIC(14,3) NULL'],
            'vgh_volume_m3' => ['DECIMAL(16,6) NULL', 'REAL', 'NUMERIC(16,6) NULL'],
            'vgh_updated_at' => ['DATETIME NULL', 'TEXT', 'TIMESTAMP NULL'],
        ] as $col => [$ddlMysql, $ddlSqlite, $ddlPg]) {
            try {
                wms_ensure_column($pdo, 'product_stock', $col, $ddlMysql, $ddlSqlite, $ddlPg);
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

/** Настройки робота (создаёт строку id=1 при необходимости). */
function wms_vgh_robot_settings(PDO $pdo): array
{
    wms_vgh_robot_ensure($pdo);
    $def = ['id' => 1, 'enabled' => 0, 'start_at' => null, 'end_at' => null, 'interval_min' => 60,
        'last_run_at' => null, 'next_run_at' => null, 'updated_at' => null];
    try {
        $row = $pdo->query('SELECT * FROM vgh_robot_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return $row ? array_merge($def, $row) : $def;
    } catch (Throwable) {
        return $def;
    }
}

function wms_vgh_norm_dt(?string $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') {
        return null;
    }
    $t = strtotime(str_replace('T', ' ', $v));
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/** Сохранить настройки. end_at пустой — бессрочно. */
function wms_vgh_robot_settings_save(PDO $pdo, array $b): array
{
    wms_vgh_robot_ensure($pdo);
    $cur = wms_vgh_robot_settings($pdo);
    $start = array_key_exists('start_at', $b) ? wms_vgh_norm_dt((string)$b['start_at']) : wms_vgh_norm_dt((string)$cur['start_at']);
    $end = array_key_exists('end_at', $b) ? wms_vgh_norm_dt((string)$b['end_at']) : wms_vgh_norm_dt((string)$cur['end_at']);
    if ($start !== null && $end !== null && $start > $end) {
        throw new RuntimeException('Дата начала позже даты окончания');
    }
    $interval = max(1, min(10080, (int)($b['interval_min'] ?? $cur['interval_min'] ?? 60)));
    $enabled = !empty($b['enabled']) ? 1 : 0;
    $nowStr = date('Y-m-d H:i:s');
    $next = $start;
    if ($cur['last_run_at']) {
        $next = date('Y-m-d H:i:s', strtotime((string)$cur['last_run_at']) + $interval * 60);
    }
    if ($next !== null && $end !== null && $next > $end) {
        $next = $end;
    }
    $pdo->prepare('UPDATE vgh_robot_settings SET enabled=:e, start_at=:s, end_at=:en, interval_min=:i,
            next_run_at=:n, updated_at=:u WHERE id=1')
        ->execute([':e' => $enabled, ':s' => $start, ':en' => $end, ':i' => $interval, ':n' => $next, ':u' => $nowStr]);
    return wms_vgh_robot_settings($pdo);
}

/** Состояние расписания (для UI). */
function wms_vgh_robot_state(PDO $pdo): array
{
    $s = wms_vgh_robot_settings($pdo);
    $now = time();
    $start = $s['start_at'] ? strtotime((string)$s['start_at']) : null;
    $end = $s['end_at'] ? strtotime((string)$s['end_at']) : null;
    $inWindow = ($start === null || $now >= $start) && ($end === null || $now <= $end);
    $next = $s['next_run_at'] ? strtotime((string)$s['next_run_at']) : $start;
    $due = $inWindow && ($next === null || $now >= $next);
    return [
        'in_window' => $inWindow,
        'due' => $due,
        'expired' => ($end !== null && $now > $end),
        'next_run_at' => $s['next_run_at'],
        'last_run_at' => $s['last_run_at'],
    ];
}

/** Паспорт паллеты (совпадает с 8.1). */
function wms_vgh_pallet_specs(): array
{
    return [
        'euro' => ['label' => 'Евро', 'length_mm' => 1200, 'width_mm' => 800, 'height_mm' => 144, 'tare_kg' => 25],
        'fin' => ['label' => 'Фин', 'length_mm' => 1200, 'width_mm' => 1000, 'height_mm' => 138, 'tare_kg' => 25],
    ];
}

/** Нормализация типа тары (как в 8.1). */
function wms_vgh_norm_pallet(?string $v): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$v)) : strtolower(trim((string)$v));
    if (in_array($s, ['фин', 'fin', 'fin-паллет', 'fin pallet'], true)) {
        return 'fin';
    }
    return 'euro';
}

/**
 * SQL, который показывает интерфейс. Формулы совпадают с расчётом робота
 * (выполняется построчно для совместимости с PostgreSQL и SQLite).
 */
function wms_vgh_robot_sql(): string
{
    return "UPDATE product_stock s\n"
        . "SET vgh_length_mm  = 1200,\n"
        . "    vgh_width_mm   = CASE WHEN COALESCE(s.pallet_type, p.pallet_type, 'euro') IN ('fin','фин') THEN 1000 ELSE 800 END,\n"
        . "    vgh_height_mm  = COALESCE(NULLIF(p.height_mm,0),0)\n"
        . "                     + CASE WHEN COALESCE(s.pallet_type, p.pallet_type, 'euro') IN ('fin','фин') THEN 138 ELSE 144 END,\n"
        . "    vgh_weight_kg  = ROUND(COALESCE(s.qty,0) * COALESCE(p.weight_g,0) / 1000.0\n"
        . "                     + CASE WHEN COALESCE(s.pallet,'') <> '' THEN 25 ELSE 0 END, 2),\n"
        . "    vgh_volume_m3  = ROUND(1.2\n"
        . "                     * (CASE WHEN COALESCE(s.pallet_type, p.pallet_type, 'euro') IN ('fin','фин') THEN 1.0 ELSE 0.8 END)\n"
        . "                     * ((COALESCE(NULLIF(p.height_mm,0),0)\n"
        . "                         + CASE WHEN COALESCE(s.pallet_type, p.pallet_type, 'euro') IN ('fin','фин') THEN 138 ELSE 144 END) / 1000.0), 3),\n"
        . "    vgh_updated_at = NOW()\n"
        . "FROM products p\n"
        . "WHERE p.id = s.product_id;\n\n"
        . "-- объём единицы товара в карточке (м³)\n"
        . "UPDATE products\n"
        . "SET volume_m3 = ROUND(length_mm * width_mm * height_mm / 1000000000.0, 6)\n"
        . "WHERE COALESCE(length_mm,0) > 0 AND COALESCE(width_mm,0) > 0 AND COALESCE(height_mm,0) > 0;";
}

/**
 * Пересчитать ВГХ. $force=true — разовый запуск (игнорирует расписание и enabled).
 * @return array{ok:bool,processed:int,updated:int,products_updated:int,duration_ms:int,errors:array}
 */
function wms_vgh_robot_run(PDO $pdo, bool $force = false): array
{
    wms_vgh_robot_ensure($pdo);
    $startedAt = date('Y-m-d H:i:s');
    $t0 = microtime(true);
    $lock = @fopen(__DIR__ . '/.vgh_robot.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return ['ok' => false, 'processed' => 0, 'updated' => 0, 'products_updated' => 0,
            'duration_ms' => 0, 'errors' => ['Робот уже выполняется']];
    }
    $specs = wms_vgh_pallet_specs();
    $stats = ['ok' => true, 'processed' => 0, 'updated' => 0, 'products_updated' => 0,
        'duration_ms' => 0, 'errors' => []];
    $stamp = date('Y-m-d H:i:s');
    try {
        $rows = $pdo->query(
            'SELECT s.id, s.qty, s.pallet, s.pallet_type, s.vgh_length_mm, s.vgh_width_mm, s.vgh_height_mm,
                    s.vgh_weight_kg, s.vgh_volume_m3, s.vgh_updated_at,
                    p.id AS product_id, p.length_mm, p.width_mm, p.height_mm, p.weight_g, p.volume_m3,
                    p.pallet_type AS prod_pallet_type, p.pallet_height_mm, p.sku
               FROM product_stock s JOIN products p ON p.id = s.product_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return ['ok' => false, 'processed' => 0, 'updated' => 0, 'products_updated' => 0,
            'duration_ms' => 0, 'errors' => [$e->getMessage()]];
    }
    $updStock = $pdo->prepare('UPDATE product_stock SET vgh_length_mm=:l, vgh_width_mm=:w, vgh_height_mm=:h,
            vgh_weight_kg=:wt, vgh_volume_m3=:v, vgh_updated_at=:ts WHERE id=:id');
    $updProd = $pdo->prepare('UPDATE products SET volume_m3=:v WHERE id=:id');
    $prodDone = [];
    try {
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            $stats['processed']++;
            $type = wms_vgh_norm_pallet((string)($r['pallet_type'] ?? '') !== ''
                ? (string)$r['pallet_type'] : (string)($r['prod_pallet_type'] ?? ''));
            $spec = $specs[$type] ?? $specs['euro'];
            $goodsH = (int)($r['height_mm'] ?? 0);
            $totalH = $goodsH > 0 ? $goodsH + (int)$spec['height_mm'] : (int)$spec['height_mm'];
            $len = (int)$spec['length_mm'];
            $wid = (int)$spec['width_mm'];
            $qty = (float)($r['qty'] ?? 0);
            $hasPallet = trim((string)($r['pallet'] ?? '')) !== '';
            $weight = round($qty * (float)($r['weight_g'] ?? 0) / 1000.0 + ($hasPallet ? (float)$spec['tare_kg'] : 0.0), 3);
            $volume = round(($len / 1000.0) * ($wid / 1000.0) * ($totalH / 1000.0), 6);
            $same = (int)($r['vgh_length_mm'] ?? 0) === $len
                && (int)($r['vgh_width_mm'] ?? 0) === $wid
                && (int)($r['vgh_height_mm'] ?? 0) === $totalH
                && abs((float)($r['vgh_weight_kg'] ?? -1) - $weight) < 0.0005
                && abs((float)($r['vgh_volume_m3'] ?? -1) - $volume) < 0.0000005
                && !empty($r['vgh_updated_at']);
            if (!$same) {
                $updStock->execute([':l' => $len, ':w' => $wid, ':h' => $totalH, ':wt' => $weight,
                    ':v' => $volume, ':ts' => $stamp, ':id' => (int)$r['id']]);
                $stats['updated']++;
            }
            $pid = (int)($r['product_id'] ?? 0);
            $L = (float)($r['length_mm'] ?? 0);
            $W = (float)($r['width_mm'] ?? 0);
            $H = (float)($r['height_mm'] ?? 0);
            if ($pid > 0 && $L > 0 && $W > 0 && $H > 0 && !isset($prodDone[$pid])) {
                $prodDone[$pid] = true;
                $unitVol = round($L * $W * $H / 1000000000.0, 6);
                if (abs((float)($r['volume_m3'] ?? 0) - $unitVol) > 0.0000005) {
                    $updProd->execute([':v' => $unitVol, ':id' => $pid]);
                    $stats['products_updated']++;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $stats['ok'] = false;
        $stats['errors'][] = $e->getMessage();
    }
    $stats['duration_ms'] = (int)round((microtime(true) - $t0) * 1000);
    if (!$force || $stats['ok']) {
        $s = wms_vgh_robot_settings($pdo);
        $interval = max(1, (int)$s['interval_min']);
        $next = date('Y-m-d H:i:s', time() + $interval * 60);
        $end = $s['end_at'] ? strtotime((string)$s['end_at']) : null;
        if ($end !== null && strtotime($next) > $end) {
            $next = date('Y-m-d H:i:s', $end);
        }
        try {
            $pdo->prepare('UPDATE vgh_robot_settings SET last_run_at=:l, next_run_at=:n, updated_at=:u WHERE id=1')
                ->execute([':l' => $startedAt, ':n' => $next, ':u' => $startedAt]);
        } catch (Throwable) {
        }
    }
    $message = $stats['ok']
        ? ('Обработано: ' . $stats['processed'] . ', обновлено: ' . $stats['updated']
            . ', объём товаров: ' . $stats['products_updated'])
        : implode('; ', $stats['errors']);
    try {
        $pdo->prepare('INSERT INTO vgh_robot_log (started_at,finished_at,status,processed,updated,products_updated,duration_ms,message)
                VALUES (:s,:f,:st,:p,:u,:pu,:d,:m)')
            ->execute([':s' => $startedAt, ':f' => date('Y-m-d H:i:s'), ':st' => $stats['ok'] ? 'OK' : 'ERROR',
                ':p' => $stats['processed'], ':u' => $stats['updated'], ':pu' => $stats['products_updated'],
                ':d' => $stats['duration_ms'], ':m' => substr($message, 0, 2000)]);
    } catch (Throwable) {
    }
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $stats;
}

/** Запуск по расписанию (вызывается при открытии настроек/по cron). */
function wms_vgh_robot_tick(PDO $pdo): ?array
{
    $s = wms_vgh_robot_settings($pdo);
    if (empty($s['enabled'])) {
        return null;
    }
    $state = wms_vgh_robot_state($pdo);
    if (!$state['due']) {
        return null;
    }
    return wms_vgh_robot_run($pdo, false);
}

/** Журнал запусков. */
function wms_vgh_robot_log(PDO $pdo, int $limit = 30): array
{
    wms_vgh_robot_ensure($pdo);
    $limit = max(1, min(200, $limit));
    try {
        return $pdo->query("SELECT * FROM vgh_robot_log ORDER BY id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return [];
    }
}
