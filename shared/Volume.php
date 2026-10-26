<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Объём единицы товара (м³) по артикулам.
 * Считаем как Д × Ш × В в метрах (без паллеты). Если габариты не заполнены,
 * используем готовое поле products.volume_m3.
 *
 * @param string[] $skus
 * @return array<string,float> sku => м³ за 1 шт.
 */
function wms_volume_unit_map(array $skus): array
{
    $skus = array_values(array_unique(array_filter(array_map(
        static fn($s): string => trim((string)$s),
        $skus
    ), static fn($s): bool => $s !== '')));
    if (!$skus) {
        return [];
    }
    $out = [];
    try {
        $pdo = wms_nomenclature_pdo();
        $cols = wms_table_columns($pdo, 'products');
        $hasDims = in_array('length_mm', $cols, true)
            && in_array('width_mm', $cols, true)
            && in_array('height_mm', $cols, true);
        $hasVol = in_array('volume_m3', $cols, true);
        if (!$hasDims && !$hasVol) {
            return [];
        }
        $sel = 'sku' . ($hasDims ? ', length_mm, width_mm, height_mm' : '')
            . ($hasVol ? ', volume_m3' : '');
        $ph = implode(',', array_fill(0, count($skus), '?'));
        $st = $pdo->prepare("SELECT $sel FROM products WHERE sku IN ($ph)");
        $st->execute($skus);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $vol = 0.0;
            if ($hasDims) {
                $l = (float)($r['length_mm'] ?? 0);
                $w = (float)($r['width_mm'] ?? 0);
                $h = (float)($r['height_mm'] ?? 0);
                if ($l > 0 && $w > 0 && $h > 0) {
                    $vol = ($l / 1000.0) * ($w / 1000.0) * ($h / 1000.0);
                }
            }
            if ($vol <= 0 && $hasVol) {
                $vol = (float)($r['volume_m3'] ?? 0);
            }
            $out[(string)$r['sku']] = round(max(0.0, $vol), 6);
        }
    } catch (Throwable) {
    }
    return $out;
}

/**
 * Суммарный объём по карте количеств: sku => qty.
 * @param array<string,float|int> $qtyBySku
 * @param array<string,float> $unitBySku
 */
function wms_volume_sum(array $qtyBySku, array $unitBySku): float
{
    $sum = 0.0;
    foreach ($qtyBySku as $sku => $qty) {
        $sum += (float)$qty * (float)($unitBySku[(string)$sku] ?? 0.0);
    }
    return round($sum, 6);
}
