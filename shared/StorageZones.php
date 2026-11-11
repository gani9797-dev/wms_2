<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Справочник зон ячеек: создание/миграция и выборка.
 * Зона — не только код для фильтра. Она может:
 *   purpose              — назначение зоны (для отчётов и оператора);
 *   affects_placement=1  — приоритет при подборе ячейки под размещение;
 *   affects_reservation=1— приоритет при подборе остатка под резервирование;
 *   contractor_id        — зона «приравнена» к клиенту;
 *   is_virtual=1         — пустая зона без ячеек (для отчётов).
 */
function wms_storage_zones_ensure(PDO $pdo): void
{
    try {
        if (!wms_has_column($pdo, 'storage_zones', 'id')) {
            $drv = wms_driver($pdo);
            if ($drv === 'pgsql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS storage_zones (
                    id BIGSERIAL PRIMARY KEY,
                    warehouse_id BIGINT NULL,
                    code VARCHAR(30) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    is_active SMALLINT NOT NULL DEFAULT 1,
                    purpose VARCHAR(255) NULL,
                    affects_placement SMALLINT NOT NULL DEFAULT 0,
                    affects_reservation SMALLINT NOT NULL DEFAULT 0,
                    contractor_id BIGINT NULL,
                    is_virtual SMALLINT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW())");
            } elseif ($drv === 'mysql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS storage_zones (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    warehouse_id BIGINT UNSIGNED NULL,
                    code VARCHAR(30) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    purpose VARCHAR(255) NULL,
                    affects_placement TINYINT(1) NOT NULL DEFAULT 0,
                    affects_reservation TINYINT(1) NOT NULL DEFAULT 0,
                    contractor_id BIGINT UNSIGNED NULL,
                    is_virtual TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS storage_zones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    warehouse_id INTEGER NULL,
                    code TEXT NOT NULL,
                    name TEXT NOT NULL,
                    is_active INTEGER NOT NULL DEFAULT 1,
                    purpose TEXT NULL,
                    affects_placement INTEGER NOT NULL DEFAULT 0,
                    affects_reservation INTEGER NOT NULL DEFAULT 0,
                    contractor_id INTEGER NULL,
                    is_virtual INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT DEFAULT (datetime('now')),
                    updated_at TEXT DEFAULT (datetime('now')))");
            }
        }
        wms_ensure_column($pdo, 'storage_zones', 'purpose', 'VARCHAR(255) NULL', 'purpose TEXT', 'VARCHAR(255) NULL');
        wms_ensure_column($pdo, 'storage_zones', 'affects_placement', 'TINYINT(1) NOT NULL DEFAULT 0', 'affects_placement INTEGER NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        wms_ensure_column($pdo, 'storage_zones', 'affects_reservation', 'TINYINT(1) NOT NULL DEFAULT 0', 'affects_reservation INTEGER NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        wms_ensure_column($pdo, 'storage_zones', 'contractor_id', 'BIGINT UNSIGNED NULL', 'contractor_id INTEGER', 'BIGINT NULL');
        wms_ensure_column($pdo, 'storage_zones', 'is_virtual', 'TINYINT(1) NOT NULL DEFAULT 0', 'is_virtual INTEGER NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        wms_ensure_column($pdo, 'storage_zones', 'auto_add_clients', 'TINYINT(1) NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0', 'SMALLINT NOT NULL DEFAULT 0');
        wms_storage_zone_clients_ensure($pdo);
        wms_storage_zones_canonicalize($pdo);
    } catch (Throwable) {
    }
}

function wms_storage_zones_canonicalize(PDO $pdo): void
{
    static $ran = [];
    $key = spl_object_id($pdo);
    if (isset($ran[$key])) {
        return;
    }
    $ran[$key] = true;
    try {
        $rows = [];
        foreach ($pdo->query("SELECT id, code, name FROM storage_zones") as $r) {
            $r['code'] = trim((string)($r['code'] ?? ''));
            if ($r['code'] === '') {
                continue;
            }
            $rows[strtolower($r['code'])] = $r;
        }
        $aliases = ['accept' => 'receiving', 'ship' => 'shipment'];
        foreach ($aliases as $alias => $target) {
            if (!isset($rows[$alias])) {
                continue;
            }
            $old = $rows[$alias];
            $hasTarget = isset($rows[$target]);
            try {
                $pdo->prepare("UPDATE storage_cells SET zone = :t WHERE LOWER(TRIM(zone)) = :a")
                    ->execute([':t' => $target, ':a' => $alias]);
            } catch (Throwable) {
            }
            try {
                $ids = $pdo->prepare("SELECT DISTINCT cell_id FROM storage_cell_extra_zones WHERE LOWER(TRIM(zone)) = :a");
                $ids->execute([':a' => $alias]);
                foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $cellId) {
                    try {
                        $pdo->prepare("INSERT INTO storage_cell_extra_zones (cell_id, zone) VALUES (:c, :z)")
                            ->execute([':c' => (int)$cellId, ':z' => $target]);
                    } catch (Throwable) {
                    }
                    $pdo->prepare("DELETE FROM storage_cell_extra_zones WHERE cell_id = :c AND LOWER(TRIM(zone)) = :a")
                        ->execute([':c' => (int)$cellId, ':a' => $alias]);
                }
            } catch (Throwable) {
            }
            if ($hasTarget) {
                $pdo->prepare("DELETE FROM storage_zones WHERE id = :id")->execute([':id' => (int)$old['id']]);
            } else {
                $pdo->prepare("UPDATE storage_zones SET code = :t WHERE id = :id")
                    ->execute([':t' => $target, ':id' => (int)$old['id']]);
                $old['code'] = $target;
                $rows[$target] = $old;
            }
            unset($rows[$alias]);
        }
        if (!$rows) {
            $base = [
                ['receiving', 'Приемка'],
                ['storage', 'Хранение'],
                ['shipment', 'Отгрузка'],
                ['quarantine', 'Карантин'],
            ];
            $st = $pdo->prepare("INSERT INTO storage_zones (code, name, is_active) VALUES (:c, :n, 1)");
            foreach ($base as [$code, $name]) {
                $st->execute([':c' => $code, ':n' => $name]);
            }
        }
    } catch (Throwable) {
    }
}

/** Синонимы кодов зон: английский код + русское название ячейки. */
function wms_storage_zone_aliases(string $code): array
{
    $c = strtolower(trim($code));
    $map = [
        'sorting' => ['sorting', 'sort', 'сортировка'],
        'storage' => ['storage', 'хранение'],
        'receiving' => ['receiving', 'приемка', 'приёмка'],
        'shipment' => ['shipment', 'отгрузка'],
        'quarantine' => ['quarantine', 'карантин'],
    ];
    return $map[$c] ?? [$c];
}

/**
 * @return array<int,array<string,mixed>>
 */
function wms_storage_zone_rows(PDO $pdo, bool $activeOnly = false): array
{
    wms_storage_zones_ensure($pdo);
    $out = [];
    try {
        $sql = "SELECT * FROM storage_zones";
        if ($activeOnly) {
            $sql .= " WHERE COALESCE(is_active,1)=1";
        }
        $sql .= " ORDER BY code";
        foreach ($pdo->query($sql) as $r) {
            $r['code'] = trim((string)($r['code'] ?? ''));
            if ($r['code'] === '') {
                continue;
            }
            $r['name'] = trim((string)($r['name'] ?? $r['code']));
            $r['purpose'] = trim((string)($r['purpose'] ?? ''));
            $r['affects_placement'] = (int)($r['affects_placement'] ?? 0);
            $r['affects_reservation'] = (int)($r['affects_reservation'] ?? 0);
            $r['contractor_id'] = ($r['contractor_id'] ?? null) === null || $r['contractor_id'] === ''
                ? null : (int)$r['contractor_id'];
            $r['is_virtual'] = (int)($r['is_virtual'] ?? 0);
            $r['is_active'] = (int)($r['is_active'] ?? 1);
            $r['auto_add_clients'] = (int)($r['auto_add_clients'] ?? 0);
            $r['client_ids'] = wms_storage_zone_clients($pdo, (int)($r['id'] ?? 0));
            $out[] = $r;
        }
        // Сколько ячеек реально используют зону (для «пустышки»).
        foreach ($out as &$z) {
            $aliases = wms_storage_zone_aliases((string)$z['code']);
            $ph = [];
            $zp = [];
            foreach ($aliases as $i => $a) {
                $k = ':z' . $i;
                $ph[] = $k;
                $zp[$k] = $a;
            }
            $in = implode(',', $ph);
            try {
                // Дополнительные зоны ячейки (storage_cell_extra_zones) — отдельно.
                $sql = "SELECT COUNT(*) FROM storage_cells c WHERE LOWER(c.zone) IN ($in)
                        OR EXISTS (SELECT 1 FROM storage_cell_extra_zones ez WHERE ez.cell_id=c.id AND LOWER(ez.zone) IN ($in))";
                $st = $pdo->prepare($sql);
                $st->execute($zp);
                $z['cells_count'] = (int)$st->fetchColumn();
            } catch (Throwable) {
                try {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM storage_cells WHERE LOWER(zone) IN ($in)");
                    $st->execute($zp);
                    $z['cells_count'] = (int)$st->fetchColumn();
                } catch (Throwable) {
                    $z['cells_count'] = 0;
                }
            }
            // Заполненность зоны: свободные/занятые ячейки и процент.
            // «Свободна» — status=free и остаток 0; остальное считается занятым.
            try {
                $fs = $pdo->prepare(
                    "SELECT COUNT(*) AS total,
                            COALESCE(SUM(CASE WHEN status='free' AND COALESCE(qty,0)<=0 THEN 1 ELSE 0 END),0) AS free_cnt,
                            COALESCE(SUM(CASE WHEN status<>'free' OR COALESCE(qty,0)>0 THEN 1 ELSE 0 END),0) AS busy_cnt
                       FROM storage_cells WHERE LOWER(zone) IN ($in) AND COALESCE(is_archived,0)=0"
                );
                $fs->execute($zp);
                $fr = $fs->fetch(PDO::FETCH_ASSOC) ?: [];
                $total = (int)($fr['total'] ?? 0);
                $free = (int)($fr['free_cnt'] ?? 0);
                $busy = (int)($fr['busy_cnt'] ?? 0);
                $z['cells_free'] = $free;
                $z['cells_busy'] = $busy;
                $z['fill_pct'] = $total > 0 ? (int)round($busy / $total * 100) : 0;
                $z['is_full'] = $total > 0 && $free === 0;
            } catch (Throwable) {
                $z['cells_free'] = 0;
                $z['cells_busy'] = 0;
                $z['fill_pct'] = 0;
                $z['is_full'] = false;
            }
        }
        unset($z);
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Коды зон, которые приоритетны для размещения или резервирования.
 * Если зона привязана к клиенту — учитываем только для этого клиента.
 * @return array<string,string> code => name
 */
function wms_storage_zone_priority(PDO $pdo, ?int $contractorId, string $effect = 'placement'): array
{
    $col = $effect === 'reservation' ? 'affects_reservation' : 'affects_placement';
    $out = [];
    foreach (wms_storage_zone_rows($pdo, true) as $z) {
        if ((int)($z[$col] ?? 0) !== 1) {
            continue;
        }
        $cid = $z['contractor_id'];
        $members = (array)($z['client_ids'] ?? []);
        if ($cid !== null && (!$contractorId || (int)$cid !== (int)$contractorId)) {
            continue;
        }
        // Зона с несколькими клиентами: приоритетна только для них.
        if ($members) {
            if (!$contractorId || !in_array((int)$contractorId, array_map('intval', $members), true)) {
                continue;
            }
        }
        $out[(string)$z['code']] = (string)$z['name'];
    }
    return $out;
}

/** Таблица «зона ↔ клиенты»: у одной зоны может быть несколько клиентов. */
function wms_storage_zone_clients_ensure(PDO $pdo): void
{
    try {
        $drv = wms_driver($pdo);
        if ($drv === 'pgsql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS storage_zone_clients (
                id BIGSERIAL PRIMARY KEY,
                zone_id BIGINT NOT NULL,
                contractor_id BIGINT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (zone_id, contractor_id))");
        } elseif ($drv === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS storage_zone_clients (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                zone_id BIGINT UNSIGNED NOT NULL,
                contractor_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_zone_client (zone_id, contractor_id))");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS storage_zone_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zone_id INTEGER NOT NULL,
                contractor_id INTEGER NOT NULL,
                created_at TEXT DEFAULT (datetime('now')),
                UNIQUE (zone_id, contractor_id))");
        }
    } catch (Throwable) {
    }
}

/** @return int[] id клиентов зоны */
function wms_storage_zone_clients(PDO $pdo, int $zoneId): array
{
    wms_storage_zone_clients_ensure($pdo);
    try {
        $st = $pdo->prepare('SELECT contractor_id FROM storage_zone_clients WHERE zone_id=:z ORDER BY contractor_id');
        $st->execute([':z' => $zoneId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return [];
    }
}

/** Заменить список клиентов зоны (пустой массив — отвязать всех). */
function wms_storage_zone_clients_save(PDO $pdo, int $zoneId, array $clientIds): void
{
    wms_storage_zone_clients_ensure($pdo);
    $zoneId = (int)$zoneId;
    if ($zoneId <= 0) {
        return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds), fn($v) => $v > 0)));
    try {
        $pdo->prepare('DELETE FROM storage_zone_clients WHERE zone_id=:z')->execute([':z' => $zoneId]);
        $ins = $pdo->prepare('INSERT INTO storage_zone_clients (zone_id, contractor_id) VALUES (:z,:c)');
        foreach ($ids as $cid) {
            try {
                $ins->execute([':z' => $zoneId, ':c' => $cid]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}

/**
 * Автодобавление клиента во все зоны с флагом «добавлять новых автоматически».
 * Вызывается при создании/обнаружении клиента из приходного документа.
 */
function wms_storage_zone_auto_add_client(int $contractorId): void
{
    if ($contractorId <= 0) {
        return;
    }
    try {
        $pdo = wms_storage_pdo();
        wms_storage_zone_clients_ensure($pdo);
        $st = $pdo->query("SELECT id FROM storage_zones WHERE COALESCE(auto_add_clients,0)=1 AND COALESCE(is_active,1)=1");
        $ins = $pdo->prepare('INSERT INTO storage_zone_clients (zone_id, contractor_id) VALUES (:z,:c)');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $zoneId) {
            try {
                $ins->execute([':z' => (int)$zoneId, ':c' => $contractorId]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }
}
