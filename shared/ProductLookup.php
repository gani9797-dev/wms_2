<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Поиск товара по артикулу, коду WMS или штрихкоду.
 * Используется для автозаполнения строк прихода/заказа.
 */
function wms_product_lookup(string $query): ?array
{
    $query = trim($query);
    if ($query === '') {
        return null;
    }
    try {
        $pdo = wms_nomenclature_pdo();
        $row = null;
        $st = $pdo->prepare("SELECT * FROM products WHERE sku = :q OR code = :q ORDER BY id LIMIT 1");
        $st->execute([':q' => $query]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            $st = $pdo->prepare(
                "SELECT p.* FROM products p
                   JOIN product_barcodes b ON b.product_id = p.id
                  WHERE b.barcode = :q
                  ORDER BY p.id LIMIT 1"
            );
            $st->execute([':q' => $query]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) {
            return null;
        }
        $productId = (int)$row['id'];
        $barcodes = [];
        try {
            $bc = $pdo->prepare("SELECT barcode FROM product_barcodes WHERE product_id=:id ORDER BY id");
            $bc->execute([':id' => $productId]);
            foreach ($bc->fetchAll(PDO::FETCH_COLUMN) as $b) {
                $b = trim((string)$b);
                if ($b !== '') $barcodes[] = $b;
            }
        } catch (Throwable) {
        }
        $barcode = in_array($query, $barcodes, true) ? $query : ($barcodes[0] ?? null);
        $ownerId = ($row['contractor_id'] ?? null) !== null && $row['contractor_id'] !== ''
            ? (int)$row['contractor_id'] : null;
        $ownerName = '';
        if ($ownerId) {
            try {
                $cp = $pdo->prepare("SELECT name FROM counterparties WHERE id=:id LIMIT 1");
                $cp->execute([':id' => $ownerId]);
                $ownerName = trim((string)$cp->fetchColumn());
            } catch (Throwable) {
            }
        }
        if ($ownerName === '') {
            $ownerName = trim((string)($row['owner'] ?? ''));
        }
        return [
            'id' => $productId,
            'wms_code' => (string)($row['code'] ?? ''),
            'sku' => (string)($row['sku'] ?? ''),
            'name' => (string)($row['name'] ?? ''),
            'unit' => (string)($row['unit'] ?? 'шт'),
            'barcode' => $barcode,
            'barcodes' => $barcodes,
            'scan_serial_in' => (int)($row['scan_serial_in'] ?? 0),
            'scan_serial_out' => (int)($row['scan_serial_out'] ?? 0),
            'scan_chz' => (int)($row['is_chz'] ?? 0),
            'owner_contractor_id' => $ownerId,
            'owner_name' => $ownerName,
        ];
    } catch (Throwable) {
        return null;
    }
}
