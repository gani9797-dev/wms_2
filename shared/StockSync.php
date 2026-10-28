<?php
declare(strict_types=1);

// Проброс факта приемки (receipt_operations accept_status='Принято')
// в Номенклатуру: products.qty, product_stock (модуль 08.1), product_movements, product_history.
// Принятый товар встаёт в ЯЧЕЙКУ ПРИЁМКИ (ворота документа = ячейка хранения из модуля 05,
// зона «Приёмка», напр. DOK-01). Строка запаса привязана к приходу и паллете:
// один товар на разных паллетах — разные строки, чтобы номер паллеты (SSCC) был виден в 8.1.
// Идемпотентно: остаток НЕ накапливается, а приводится к факту приёмки
// (UPDATE qty = факт по паллете) — повторный вызов и отмена паллеты не двоят остаток.
// Работает и для SQLite-демо (две БД-файла), и для MySQL/PG-прода (одна БД `wms`).

require_once __DIR__ . '/ProductCode.php';
require_once __DIR__ . '/pg.php';
require_once __DIR__ . '/Journal.php';

function wms_now_expr(PDO $pdo): string
{
    return wms_is_sqlite($pdo) ? "datetime('now')" : 'NOW()';
}

/** Портированная проверка колонки: mysql SHOW / pgsql information_schema / sqlite PRAGMA. */
function wms_have_column(PDO $pdo, string $table, string $col): bool
{
    if (function_exists('wms_table_columns')) {
        return in_array($col, wms_table_columns($pdo, $table), true);
    }
    return true;
}

/** Дотягивает колонки product_stock (привязка к приходу, паллета, тара). */
function wms_stock_ensure_columns(PDO $nomPdo): void
{
    $isMysql = wms_is_mysql($nomPdo);
    $isPg = wms_is_pgsql($nomPdo);
    try {
        $have = wms_table_columns($nomPdo, 'product_stock');
        if (!in_array('receipt_id', $have, true)) {
            if ($isMysql) {
                $nomPdo->exec("ALTER TABLE `product_stock` ADD COLUMN receipt_id BIGINT UNSIGNED NULL");
            } elseif ($isPg) {
                $nomPdo->exec('ALTER TABLE "product_stock" ADD COLUMN receipt_id BIGINT NULL');
            } else {
                $nomPdo->exec('ALTER TABLE product_stock ADD COLUMN receipt_id INTEGER');
            }
        }
        if (!in_array('receipt_no', $have, true)) {
            if ($isMysql) {
                $nomPdo->exec("ALTER TABLE `product_stock` ADD COLUMN receipt_no VARCHAR(50) NULL");
            } elseif ($isPg) {
                $nomPdo->exec('ALTER TABLE "product_stock" ADD COLUMN receipt_no VARCHAR(50) NULL');
            } else {
                $nomPdo->exec('ALTER TABLE product_stock ADD COLUMN receipt_no TEXT');
            }
        }
        if (!in_array('quality', $have, true)) {
            if ($isMysql) {
                $nomPdo->exec("ALTER TABLE `product_stock` ADD COLUMN quality VARCHAR(20) NULL DEFAULT 'новый'");
            } elseif ($isPg) {
                $nomPdo->exec('ALTER TABLE "product_stock" ADD COLUMN quality VARCHAR(20) NULL DEFAULT \'новый\'');
            } else {
                $nomPdo->exec('ALTER TABLE product_stock ADD COLUMN quality TEXT DEFAULT \'новый\'');
            }
        }
        if (!in_array('pallet', $have, true)) {
            if ($isMysql) {
                $nomPdo->exec("ALTER TABLE `product_stock` ADD COLUMN pallet VARCHAR(50) NULL");
            } elseif ($isPg) {
                $nomPdo->exec('ALTER TABLE "product_stock" ADD COLUMN pallet VARCHAR(50) NULL');
            } else {
                $nomPdo->exec('ALTER TABLE product_stock ADD COLUMN pallet TEXT');
            }
        }
    } catch (Throwable) {
    }
}

/** Карточка товара по SKU; если нет — создаём минимальную (факт не должен теряться). */
function wms_ensure_product_card(PDO $nomPdo, string $sku, int $whId, array &$created): int
{
    $p = $nomPdo->prepare("SELECT * FROM products WHERE sku=:s LIMIT 1");
    $p->execute([':s' => $sku]);
    $prod = $p->fetch(PDO::FETCH_ASSOC);
    if ($prod) {
        return (int)$prod['id'];
    }
    // SKU из ТСД (в т.ч. «не заявлен») — создаем минимальную карточку.
    $code = $sku;
    try {
        $code = wms_next_product_code($nomPdo);
    } catch (Throwable $e) {
    }
    $isMysql = wms_is_mysql($nomPdo);
    $isPg = wms_is_pgsql($nomPdo);
    if ($isMysql || $isPg) {
        $nomPdo->prepare(
            "INSERT INTO products (code,sku,name,unit,category,brand,owner,warehouse_id,length_mm,width_mm,height_mm,weight_g,qty,reserved_qty,is_active,is_archived,created_at,updated_at)
             VALUES (:code,:sku,:name,'шт','Мячи','Wilson','ООО «Эталон»',:wh,100,100,100,500,0,0,1,0,NOW(),NOW())"
        )->execute([':code' => $code, ':sku' => $sku, ':name' => 'Не заявлен: ' . $sku, ':wh' => $whId]);
    } else {
        $nomPdo->prepare(
            "INSERT INTO products (code,sku,name,unit,category,brand,owner,warehouse_id,length_mm,width_mm,height_mm,weight_g,qty,reserved_qty,is_active,is_archived,created_at)
             VALUES (:code,:sku,:name,'шт','Мячи','Wilson','ООО «Эталон»',:wh,100,100,100,500,0,0,1,0,datetime('now'))"
        )->execute([':code' => $code, ':sku' => $sku, ':name' => 'Не заявлен: ' . $sku, ':wh' => $whId]);
    }
    $p->execute([':s' => $sku]);
    $prod = $p->fetch(PDO::FETCH_ASSOC);
    $created[] = $sku;
    return (int)($prod['id'] ?? 0);
}

/** Тип тары паллеты: из ячейки-приёмки, иначе из карточки, иначе euro. */
function wms_pallet_type_for(PDO $nomPdo, ?PDO $storagePdo, string $cell, array $prod): string
{
    $ptype = null;
    if ($storagePdo) {
        try {
            $cs = $storagePdo->prepare("SELECT pallet_type FROM storage_cells WHERE code=:c LIMIT 1");
            $cs->execute([':c' => $cell]);
            if ($r0 = $cs->fetch()) {
                $t = strtolower(trim((string)($r0['pallet_type'] ?? '')));
                if ($t !== '') {
                    $ptype = in_array($t, ['фин', 'финская', 'fin'], true) ? 'fin' : 'euro';
                }
            }
        } catch (Throwable) {
        }
    }
    if ($ptype === null) {
        $ptype = strtolower(trim((string)($prod['pallet_type'] ?? '')));
        if (!in_array($ptype, ['euro', 'fin'], true)) {
            $ptype = 'euro';
        }
    }
    return $ptype;
}

/**
 * Дата производства партии по позиции приёмки (receipt_items.prod_date).
 * Берётся строка позиции документа; если у неё даты нет — любая строка
 * этого артикула с заполненной датой (партии одного товара в разных приходах).
 * @return string|null дата в формате YYYY-MM-DD
 */
function wms_receipt_prod_date(PDO $recPdo, int $receiptId, string $sku): ?string
{
    $sku = trim($sku);
    if ($sku === '') {
        return null;
    }
    try {
        $st = $recPdo->prepare(
            "SELECT prod_date FROM receipt_items
              WHERE receipt_id = :r AND sku = :s AND prod_date IS NOT NULL
              ORDER BY (supplier_batch IS NULL) DESC, id LIMIT 1"
        );
        $st->execute([':r' => $receiptId, ':s' => $sku]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') {
            return substr(trim((string)$v), 0, 10);
        }
    } catch (Throwable) {
    }
    try {
        $st = $recPdo->prepare(
            "SELECT prod_date FROM receipt_items
              WHERE sku = :s AND prod_date IS NOT NULL ORDER BY id DESC LIMIT 1"
        );
        $st->execute([':s' => $sku]);
        $v = $st->fetchColumn();
        if ($v !== false && $v !== null && trim((string)$v) !== '') {
            return substr(trim((string)$v), 0, 10);
        }
    } catch (Throwable) {
    }
    return null;
}

/**
 * Синхронизация ОДНОЙ паллеты прихода в товарный запас (модуль 08.1, product_stock).
 * Остаток не накапливается, а приводится к факту приёмки по этой паллете (UPDATE),
 * поэтому повторный вызов и отмена принятого паллета не двоят остаток.
 * @return array{sku:string,pallet:string,qty:float,cell:string,stock_id:?int,changed:bool}
 */
function wms_sync_pallet_stock(PDO $recPdo, PDO $nomPdo, int $receiptId, string $sku, string $pallet, ?int $userId = null, string $userFio = 'ТСД'): array
{
    $sku = trim($sku);
    $pallet = trim($pallet);
    $out = ['sku' => $sku, 'pallet' => $pallet, 'qty' => 0.0, 'cell' => '', 'stock_id' => null, 'changed' => false];
    if ($sku === '') {
        return $out;
    }
    $h = $recPdo->prepare("SELECT id, doc_no, warehouse_id, gate FROM receipts WHERE id=:id");
    $h->execute([':id' => $receiptId]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        throw new RuntimeException('Поступление не найдено: ' . $receiptId);
    }
    $whId = (int)($head['warehouse_id'] ?? 1) ?: 1;
    $docNo = (string)$head['doc_no'];
    // Ячейка приёмки = ворота документа (DOK-01…DOK-10).
    // Если ворота не заданы или не являются реальной ячейкой приёмки —
    // берём первую существующую. Раньше подставлялась строка 'ЗОНА-ПРИЕМКИ',
    // которой НЕТ в storage_cells: товар попадал в несуществующее место.
    $cell = trim((string)($head['gate'] ?? ''));
    if ($cell === '' || !wms_is_receiving_cell($cell)) {
        $cell = wms_first_receiving_cell();
    }
    if ($cell === '') {
        // Справочник ячеек недоступен — пишем по воротам документа как есть.
        $cell = trim((string)($head['gate'] ?? ''));
    }
    $out['cell'] = $cell;
    wms_stock_ensure_columns($nomPdo);

    // Факт приёмки по этой паллете (без паллеты — операции без SSCC).
    $sql = "SELECT COALESCE(SUM(qty),0) FROM receipt_operations
            WHERE receipt_id=:r AND sku=:s AND accept_status='Принято'";
    $p = [':r' => $receiptId, ':s' => $sku];
    if ($pallet !== '') {
        $sql .= " AND pallet=:p";
        $p[':p'] = $pallet;
    } else {
        $sql .= " AND COALESCE(pallet,'')=''";
    }
    $f = $recPdo->prepare($sql);
    $f->execute($p);
    $fact = round((float)$f->fetchColumn(), 3);
    $out['qty'] = $fact;

    // Качество по паллете: если есть хотя бы одна операция 'Брак' — «Брак», иначе «новый».
    $quality = 'новый';
    try {
        if ($pallet !== '') {
            $qq = $recPdo->prepare(
                "SELECT COUNT(*) FROM receipt_operations
                 WHERE receipt_id=:r AND sku=:s AND accept_status='Брак' AND pallet=:p"
            );
            $qq->execute([':r' => $receiptId, ':s' => $sku, ':p' => $pallet]);
        } else {
            $qq = $recPdo->prepare(
                "SELECT COUNT(*) FROM receipt_operations
                 WHERE receipt_id=:r AND sku=:s AND accept_status='Брак' AND COALESCE(pallet,'')=''"
            );
            $qq->execute([':r' => $receiptId, ':s' => $sku]);
        }
        $badCnt = (int)$qq->fetchColumn();
        if ($badCnt > 0) {
            $quality = 'Брак';
        }
    } catch (Throwable) {
    }
    $out['quality'] = $quality;

    $created = [];
    $pid = wms_ensure_product_card($nomPdo, $sku, $whId, $created);
    if ($pid <= 0) {
        return $out;
    }
    $pp = $nomPdo->prepare("SELECT * FROM products WHERE id=:id");
    $pp->execute([':id' => $pid]);
    $prod = $pp->fetch(PDO::FETCH_ASSOC) ?: [];

    // Находим строку запаса: приход + паллета (+ ячейка приёмки).
    $row = null;
    try {
        $sRow = $nomPdo->prepare(
            "SELECT id, qty, COALESCE(supplier_batch,'') AS supplier_batch FROM product_stock WHERE product_id=:p AND warehouse_id=:w AND cell=:c
             AND COALESCE(receipt_id,0)=:r AND COALESCE(pallet,'')=:pal LIMIT 1"
        );
        $sRow->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':r' => $receiptId, ':pal' => $pallet]);
        $row = $sRow->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        $row = null;
    }
    // Партия для запаса и журнала: по конкретной паллете (receipt_operations),
    // иначе из позиции приёмки, иначе из строки запаса.
    $batch = trim((string)($row['supplier_batch'] ?? ''));
    if ($batch === '' && $pallet !== '') {
        try {
            $bq = $recPdo->prepare(
                "SELECT supplier_batch FROM receipt_operations
                  WHERE pallet=:p AND sku=:s AND COALESCE(supplier_batch,'')<>''
                  ORDER BY id LIMIT 1"
            );
            $bq->execute([':p' => $pallet, ':s' => $sku]);
            $batch = trim((string)($bq->fetchColumn() ?: ''));
        } catch (Throwable) {
        }
    }
    if ($batch === '') {
        try {
            $bq = $recPdo->prepare(
                "SELECT supplier_batch FROM receipt_items
                  WHERE receipt_id=:r AND sku=:s AND COALESCE(supplier_batch,'')<>''
                  ORDER BY id LIMIT 1"
            );
            $bq->execute([':r' => $receiptId, ':s' => $sku]);
            $batch = trim((string)($bq->fetchColumn() ?: ''));
        } catch (Throwable) {
        }
    }
    $storage = null;
    try {
        require_once __DIR__ . '/db.php';
        $storage = wms_storage_pdo();
    } catch (Throwable) {
    $palletDate = null;
    if ($pallet !== '') {
        try {
            $pq = $recPdo->prepare("SELECT created_at FROM tsd_pallets WHERE sscc=:s ORDER BY id DESC LIMIT 1");
            $pq->execute([':s' => $pallet]);
            $v = $pq->fetchColumn();
            if ($v !== false && $v !== null && trim((string)$v) !== '') {
                $palletDate = substr(trim((string)$v), 0, 10);
            }
        } catch (Throwable) {
        }
    }
    if ($palletDate === null) {
        $palletDate = date('Y-m-d');
    }
    $storage = null;
    }
    $ptype = wms_pallet_type_for($nomPdo, $storage, $cell, $prod);

    $oldStock = $row ? (float)($row['qty'] ?? 0) : 0.0;
    // Дата производства партии (receipt_items.prod_date) — в остаток.
    $prodDate = wms_receipt_prod_date($recPdo, $receiptId, $sku);
    if ($row) {
        // UPDATE — остаток приводится к факту приёмки по паллете.
        try {
            $nomPdo->prepare("UPDATE product_stock SET qty=:q, pallet_type=COALESCE(pallet_type,:pt), prod_date=COALESCE(:pd, prod_date), quality=COALESCE(:ql, quality), pallet_date=COALESCE(pallet_date, :pdt), supplier_batch=COALESCE(NULLIF(supplier_batch,''), :sb) WHERE id=:id")
                ->execute([':q' => $fact, ':pt' => $ptype, ':pd' => $prodDate, ':ql' => $quality, ':pdt' => $palletDate, ':sb' => ($batch !== '' ? $batch : null), ':id' => (int)$row['id']]);
        } catch (Throwable) {
            $nomPdo->prepare("UPDATE product_stock SET qty=:q WHERE id=:id")
                ->execute([':q' => $fact, ':id' => (int)$row['id']]);
        }
        $out['stock_id'] = (int)$row['id'];
    } else {
        try {
            $nomPdo->prepare(
                "INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,pallet_type,qty,receipt_id,receipt_no,prod_date,quality,pallet_date,supplier_batch)
                 VALUES (:p,:w,:c,:pal,:pt,:q,:r,:d,:pd,:ql,:pdt,:sb)"
            )->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':pal' => ($pallet !== '' ? $pallet : null),
                ':pt' => $ptype, ':q' => $fact, ':r' => $receiptId, ':d' => $docNo, ':pd' => $prodDate, ':ql' => $quality, ':pdt' => $palletDate,
                ':sb' => ($batch !== '' ? $batch : null)]);
            $out['stock_id'] = (int)$nomPdo->lastInsertId();
        } catch (Throwable) {
            try {
                $nomPdo->prepare(
                    "INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,qty,prod_date,quality,pallet_date,supplier_batch)
                     VALUES (:p,:w,:c,:pal,:q,:pd,:ql,:pdt,:sb)"
                )->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':pal' => ($pallet !== '' ? $pallet : null),
                    ':q' => $fact, ':pd' => $prodDate, ':ql' => $quality, ':pdt' => $palletDate, ':sb' => ($batch !== '' ? $batch : null)]);
            } catch (Throwable) {
                $nomPdo->prepare(
                    "INSERT INTO product_stock (product_id,warehouse_id,cell,qty,pallet_date,supplier_batch) VALUES (:p,:w,:c,:q,:pdt,:sb)"
                )->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':q' => $fact, ':pdt' => $palletDate, ':sb' => ($batch !== '' ? $batch : null)]);
            }
            $out['stock_id'] = (int)$nomPdo->lastInsertId();
        }
    }

    // Остаток в карточке товара = сумма строк запаса.
    try {
        $sum = $nomPdo->prepare("SELECT COALESCE(SUM(qty),0) FROM product_stock WHERE product_id=:p");
        $sum->execute([':p' => $pid]);
        $total = round((float)$sum->fetchColumn(), 3);
        $oldTotal = (float)($prod['qty'] ?? 0);
        $nomPdo->prepare("UPDATE products SET qty=:q WHERE id=:id")->execute([':q' => $total, ':id' => $pid]);
        if (abs($total - $oldTotal) > 0.0001) {
            try {
                $nomPdo->prepare(
                    "INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value) VALUES (:p,:u,:f,'qty','Остаток',:o,:n)"
                )->execute([':p' => $pid, ':u' => $userId, ':f' => $userFio, ':o' => (string)$oldTotal, ':n' => (string)$total]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable) {
    }

    // Движение по остатку — только если количество паллеты изменилось.
    $delta = round($fact - $oldStock, 3);
    if (abs($delta) > 0.0001) {
        try {
            $bal = $nomPdo->prepare("SELECT COALESCE(SUM(qty),0) FROM product_stock WHERE product_id=:p");
            $bal->execute([':p' => $pid]);
            $balance = (float)$bal->fetchColumn();
            $now = wms_now_expr($nomPdo);
            $nomPdo->prepare(
                "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                 VALUES (:p,$now,'receipt',:d,:c,:q,:b)"
            )->execute([':p' => $pid, ':d' => 'Поступление ' . $docNo, ':c' => $cell, ':q' => $delta, ':b' => $balance]);
        } catch (Throwable) {
        }
        // Журнал склада (модуль 14): приход — паллета принята на склад.
        // Пишем здесь же, где и product_movements, чтобы не задваивать движение.
        // Количество = изменение остатка паллеты (как qty_change в product_movements).
        try {
            WmsJournal::log(wms_journal_pdo(), [
                'op_type' => 'приход',
                'doc_type' => 'Поступление',
                'doc_no' => $docNo,
                'sku' => $sku,
                'product_name' => (string)($prod['name'] ?? ''),
                'supplier_batch' => $batch,
                'qty' => $delta,
                'pallet' => $pallet,
                'cell_to' => $cell,
                'zone' => 'Приёмка',
                'user_id' => $userId,
                'user_fio' => $userFio,
                'comment' => 'Приёмка поступления · принято всего: ' . $fact,
                'quality' => $quality,
            ]);
        } catch (Throwable) {
        }
        $out['changed'] = true;
    }
    return $out;
}

/**
 * Полный проброс прихода в номенклатуру: все паллеты документа.
 * @return array{synced: bool, already?: bool, doc_no?: string, items?: array, created?: array}
 */
function wms_push_receipt_to_nomenclature(PDO $recPdo, PDO $nomPdo, int $receiptId, ?int $userId = null, string $userFio = 'ТСД'): array
{
    $h = $recPdo->prepare("SELECT id, doc_no, warehouse_id, gate FROM receipts WHERE id=:id");
    $h->execute([':id' => $receiptId]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        throw new RuntimeException('Поступление не найдено: ' . $receiptId);
    }

    // Уже синхронизировано — не дублируем остаток.
    try {
        $g = $recPdo->prepare("SELECT payload FROM receipt_stock_sync WHERE receipt_id=:r");
        $g->execute([':r' => $receiptId]);
        $prev = $g->fetchColumn();
        if ($prev !== false && $prev !== null) {
            $decoded = json_decode((string)$prev, true);
            if (!is_array($decoded) || count($decoded) > 0) {
                return ['synced' => false, 'already' => true, 'doc_no' => (string)$head['doc_no']];
            }
            // Пустой payload = гард-пустышка (старый баг: гард писался и при
            // отсутствии принятого факта). Удаляем — иначе документ «залипнет»
            // и факт уже никогда не уедет в номенклатуру, даже после приёмки на ТСД.
            try {
                $recPdo->prepare("DELETE FROM receipt_stock_sync WHERE receipt_id=:r")->execute([':r' => $receiptId]);
            } catch (Throwable) {
            }
        }
    } catch (Throwable $e) {
    }

    $st = $recPdo->prepare(
        "SELECT sku, COALESCE(NULLIF(TRIM(pallet), ''), '') AS pallet, COALESCE(SUM(qty),0) AS q
           FROM receipt_operations
          WHERE receipt_id=:r AND accept_status='Принято' AND sku IS NOT NULL AND sku != ''
          GROUP BY sku, COALESCE(NULLIF(TRIM(pallet), ''), '') HAVING COALESCE(SUM(qty),0) > 0"
    );
    $st->execute([':r' => $receiptId]);
    $facts = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$facts) {
        // Нечего приходовать: всё — брак, либо товар ещё не принят на ТСД.
        // ВАЖНО: гард здесь НЕ пишем. Раньше он писался всегда, и документ
        // «залипал»: все последующие вызовы видели гард и факт уже никогда
        // не уходил в номенклатуру, даже когда оператор допринимал товар.
        return ['synced' => false, 'already' => false, 'doc_no' => (string)$head['doc_no'],
            'items' => [], 'created' => [], 'reason' => 'нет принятого товара'];
    }

    // Качество по позициям документа: был брак — «Брак», иначе «Годен».
    $badSkus = [];
    try {
        $bq = $recPdo->prepare("SELECT DISTINCT sku FROM receipt_operations WHERE receipt_id=:r AND accept_status='Брак'");
        $bq->execute([':r' => $receiptId]);
        foreach ($bq->fetchAll(PDO::FETCH_COLUMN) as $s) {
            $badSkus[trim((string)$s)] = true;
        }
    } catch (Throwable) {
    }

    $items = [];
    $created = [];
    foreach ($facts as $f) {
        $sku = trim((string)$f['sku']);
        $pallet = trim((string)($f['pallet'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $res = wms_sync_pallet_stock($recPdo, $nomPdo, $receiptId, $sku, $pallet, $userId, $userFio);
        $items[] = ['sku' => $sku, 'pallet' => $pallet, 'qty' => $res['qty'], 'cell' => $res['cell']];
        // Качество товара в атрибуте номенклатуры.
        try {
            $pp = $nomPdo->prepare("SELECT id, quality FROM products WHERE sku=:s LIMIT 1");
            $pp->execute([':s' => $sku]);
            $prod = $pp->fetch(PDO::FETCH_ASSOC);
            if ($prod) {
                $qNew = isset($badSkus[$sku]) ? 'Брак' : 'Годен';
                $qOld = isset($prod['quality']) ? (string)$prod['quality'] : null;
                if ($qOld !== $qNew) {
                    $nomPdo->prepare("UPDATE products SET quality=:q WHERE id=:id")
                        ->execute([':q' => $qNew, ':id' => (int)$prod['id']]);
                    try {
                        $nomPdo->prepare(
                            "INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value) VALUES (:p,:u,:f,'quality','Качество',:o,:n)"
                        )->execute([':p' => (int)$prod['id'], ':u' => $userId, ':f' => $userFio, ':o' => $qOld, ':n' => $qNew]);
                    } catch (Throwable) {
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    // Guard — только после успешного коммита в номенклатуру.
    try {
        if (wms_is_mysql($recPdo)) {
            $recPdo->prepare("INSERT IGNORE INTO receipt_stock_sync (receipt_id, payload) VALUES (:r, :p)")
                ->execute([':r' => $receiptId, ':p' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
        } elseif (wms_is_pgsql($recPdo)) {
            $recPdo->prepare("INSERT INTO receipt_stock_sync (receipt_id, payload) VALUES (:r, :p) ON CONFLICT (receipt_id) DO NOTHING")
                ->execute([':r' => $receiptId, ':p' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
        } else {
            $recPdo->prepare("INSERT OR IGNORE INTO receipt_stock_sync (receipt_id, payload) VALUES (:r, :p)")
                ->execute([':r' => $receiptId, ':p' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
        }
    } catch (Throwable $e) {
    }
    return ['synced' => true, 'already' => false, 'doc_no' => (string)$head['doc_no'], 'items' => $items, 'created' => $created];
}
