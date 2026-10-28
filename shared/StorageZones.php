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
            $out[] = $r;
        }
        // Сколько ячеек реально используют зону (для «пустышки»).
        foreach ($out as &$z) {
            try {
                $sql = "SELECT COUNT(*) FROM storage_cells c WHERE LOWER(c.zone)=:z
                        OR EXISTS (SELECT 1 FROM storage_cell_extra_zones ez WHERE ez.cell_id=c.id AND LOWER(ez.zone)=:z)";
                $st = $pdo->prepare($sql);
                $st->execute([':z' => strtolower($z['code'])]);
                $z['cells_count'] = (int)$st->fetchColumn();
            } catch (Throwable) {
                try {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM storage_cells WHERE LOWER(zone)=:z");
                    $st->execute([':z' => strtolower($z['code'])]);
                    $z['cells_count'] = (int)$st->fetchColumn();
                } catch (Throwable) {
                    $z['cells_count'] = 0;
                }
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
        if ($cid !== null && (!$contractorId || (int)$cid !== (int)$contractorId)) {
            continue;
        }
        $out[(string)$z['code']] = (string)$z['name'];
    }
    return $out;
}
