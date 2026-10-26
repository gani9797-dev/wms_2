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
                $st = $pdo->prepare("SELECT COUNT(*) FROM storage_cells WHERE LOWER(zone)=:z");
                $st->execute([':z' => strtolower($z['code'])]);
                $z['cells_count'] = (int)$st->fetchColumn();
            } catch (Throwable) {
                $z['cells_count'] = 0;
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
