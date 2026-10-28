<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function wms_cell_extra_zones_ensure(PDO $pdo): void
{
    try {
        if (!wms_has_column($pdo, 'storage_cell_extra_zones', 'id')) {
            $drv = wms_driver($pdo);
            if ($drv === 'pgsql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cell_extra_zones (
                    id BIGSERIAL PRIMARY KEY,
                    cell_id BIGINT NOT NULL,
                    zone VARCHAR(50) NOT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    UNIQUE (cell_id, zone))");
            } elseif ($drv === 'mysql') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cell_extra_zones (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    cell_id BIGINT UNSIGNED NOT NULL,
                    zone VARCHAR(50) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_cell_extra_zone (cell_id, zone))");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS storage_cell_extra_zones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    cell_id INTEGER NOT NULL,
                    zone TEXT NOT NULL,
                    created_at TEXT DEFAULT (datetime('now')),
                    UNIQUE (cell_id, zone))");
            }
        }
    } catch (Throwable) {
    }
}

function wms_cell_extra_zones_map(PDO $pdo, array $cellIds): array
{
    wms_cell_extra_zones_ensure($pdo);
    $cellIds = array_values(array_unique(array_filter(array_map('intval', $cellIds))));
    if (!$cellIds) {
        return [];
    }
    $in = implode(',', $cellIds);
    $out = [];
    try {
        foreach ($pdo->query("SELECT cell_id, zone FROM storage_cell_extra_zones WHERE cell_id IN ($in) ORDER BY id") as $r) {
            $z = trim((string)($r['zone'] ?? ''));
            if ($z !== '') {
                $out[(int)$r['cell_id']][] = $z;
            }
        }
    } catch (Throwable) {
    }
    return $out;
}

function wms_cell_extra_zones_replace(PDO $pdo, int $cellId, array $zones): void
{
    wms_cell_extra_zones_ensure($pdo);
    $pdo->prepare("DELETE FROM storage_cell_extra_zones WHERE cell_id=:c")->execute([':c' => $cellId]);
    $st = $pdo->prepare("INSERT INTO storage_cell_extra_zones (cell_id, zone) VALUES (:c,:z)");
    $seen = [];
    foreach ($zones as $z) {
        $z = trim((string)$z);
        if ($z === '' || isset($seen[$z])) {
            continue;
        }
        $seen[$z] = true;
        try {
            $st->execute([':c' => $cellId, ':z' => $z]);
        } catch (Throwable) {
        }
    }
}
