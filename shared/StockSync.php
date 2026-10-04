<?php
declare(strict_types=1);

// Проброс факта приемки (receipt_operations accept_status='Принято')
// в Номенклатуру: products.qty += fact, product_stock, product_movements, product_history.
// Идемпотентно: повторный close того же receipt_id не удваивает остаток
// (guard-таблица receipt_stock_sync в БД поступлений).
// Работает и для SQLite-демо (две БД-файла), и для MySQL-прода (одна БД `wms`).

require_once __DIR__ . '/ProductCode.php';

function wms_now_expr(PDO $pdo): string
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'NOW()' : "datetime('now')";
}

/**
 * @return array{synced: bool, already?: bool, doc_no?: string, items?: array, created?: array}
 */
function wms_push_receipt_to_nomenclature(PDO $recPdo, PDO $nomPdo, int $receiptId, ?int $userId = null, string $userFio = 'ТСД'): array
{
    $h = $recPdo->prepare("SELECT id, doc_no, warehouse_id FROM receipts WHERE id=:id");
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
            return ['synced' => false, 'already' => true, 'doc_no' => (string)$head['doc_no']];
        }
    } catch (Throwable $e) {
    }

    $st = $recPdo->prepare(
        "SELECT sku, COALESCE(SUM(qty),0) AS q FROM receipt_operations
          WHERE receipt_id=:r AND accept_status='Принято' AND sku IS NOT NULL AND sku != ''
          GROUP BY sku HAVING q > 0"
    );
    $st->execute([':r' => $receiptId]);
    $facts = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$facts) {
        // Нечего приходовать (все — брак / пусто): фиксируем sync чтобы не крутиться.
        $recPdo->prepare("INSERT OR IGNORE INTO receipt_stock_sync (receipt_id, payload) VALUES (:r, :p)")
            ->execute([':r' => $receiptId, ':p' => json_encode([], JSON_UNESCAPED_UNICODE)]);
        return ['synced' => true, 'already' => false, 'doc_no' => (string)$head['doc_no'], 'items' => [], 'created' => []];
    }

    $isMysql = $nomPdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    // Атрибут «Качество»: заводим колонку, если её ещё нет.
    try {
        $have = [];
        if ($isMysql) {
            foreach ($nomPdo->query("SHOW COLUMNS FROM `products`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[] = $c['Field'];
            }
        } else {
            foreach ($nomPdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[] = $c['name'];
            }
        }
        if (!in_array('quality', $have, true)) {
            $nomPdo->exec($isMysql
                ? "ALTER TABLE `products` ADD COLUMN quality VARCHAR(20) NULL"
                : "ALTER TABLE products ADD COLUMN quality TEXT");
        }
    } catch (Throwable) {
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
    $now = wms_now_expr($nomPdo);
    $whId = (int)($head['warehouse_id'] ?? 1) ?: 1;
    $docNo = (string)$head['doc_no'];

    // Привязка запаса к приходу: колонки receipt_id/receipt_no.
    try {
        $have = [];
        if ($isMysql) {
            foreach ($nomPdo->query("SHOW COLUMNS FROM `product_stock`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[] = $c['Field'];
            }
        } else {
            foreach ($nomPdo->query("PRAGMA table_info(product_stock)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[] = $c['name'];
            }
        }
        if (!in_array('receipt_id', $have, true)) {
            $nomPdo->exec($isMysql
                ? "ALTER TABLE `product_stock` ADD COLUMN receipt_id BIGINT UNSIGNED NULL"
                : "ALTER TABLE product_stock ADD COLUMN receipt_id INTEGER");
        }
        if (!in_array('receipt_no', $have, true)) {
            $nomPdo->exec($isMysql
                ? "ALTER TABLE `product_stock` ADD COLUMN receipt_no VARCHAR(50) NULL"
                : "ALTER TABLE product_stock ADD COLUMN receipt_no TEXT");
        }
    } catch (Throwable) {
    }

    $items = [];
    $created = [];
    $nomPdo->beginTransaction();
    try {
        foreach ($facts as $f) {
            $sku = trim((string)$f['sku']);
            $qty = (float)$f['q'];
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $p = $nomPdo->prepare("SELECT * FROM products WHERE sku=:s LIMIT 1");
            $p->execute([':s' => $sku]);
            $prod = $p->fetch(PDO::FETCH_ASSOC);
            if (!$prod) {
                // SKU из ТСД (в т.ч. «не заявлен») — создаем минимальную карточку, чтобы факт не потерялся.
                // Внутренний код WMS: T-101..T-999, далее T-1001..T-1999, T-2001...
                $code = $sku;
                try {
                    $code = wms_next_product_code($nomPdo);
                } catch (Throwable $e) {
                }
                if ($isMysql) {
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
                $pid = (int)$nomPdo->lastInsertId();
                $p->execute([':s' => $sku]);
                $prod = $p->fetch(PDO::FETCH_ASSOC);
                $created[] = $sku;
            }
            $pid = (int)$prod['id'];
            $oldQty = (float)($prod['qty'] ?? 0);
            $newQty = round($oldQty + $qty, 3);

            $nomPdo->prepare("UPDATE products SET qty=:q WHERE id=:id")->execute([':q' => $newQty, ':id' => $pid]);

            // Итог приемки: качество товара в атрибут номенклатуры.
            $qNew = isset($badSkus[$sku]) ? 'Брак' : 'Годен';
            $qOld = isset($prod['quality']) ? (string)$prod['quality'] : null;
            if ($qOld !== $qNew) {
                try {
                    $nomPdo->prepare("UPDATE products SET quality=:q WHERE id=:id")->execute([':q' => $qNew, ':id' => $pid]);
                } catch (Throwable) {
                }
                try {
                    $nomPdo->prepare(
                        "INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value) VALUES (:p,:u,:f,'quality','Качество',:o,:n)"
                    )->execute([':p' => $pid, ':u' => $userId, ':f' => $userFio, ':o' => $qOld, ':n' => $qNew]);
                } catch (Throwable $e) {
                }
            }

            // Товарный запас: зона приемки (ячейка появится на размещении в 05).
            // Строка привязана к приходу: один товар из разных приходов — разные строки.
            $cell = 'ЗОНА-ПРИЕМКИ';
            try {
                $sRow = $nomPdo->prepare(
                    "SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w AND cell=:c AND COALESCE(receipt_id,0)=:r LIMIT 1"
                );
                $sRow->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':r' => $receiptId]);
                $s = $sRow->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                // Старая схема без привязки к приходу.
                $sRow = $nomPdo->prepare(
                    "SELECT id, qty FROM product_stock WHERE product_id=:p AND warehouse_id=:w AND cell=:c LIMIT 1"
                );
                $sRow->execute([':p' => $pid, ':w' => $whId, ':c' => $cell]);
                $s = $sRow->fetch(PDO::FETCH_ASSOC);
            }
            if ($s) {
                $nomPdo->prepare("UPDATE product_stock SET qty=qty+:q WHERE id=:id")
                    ->execute([':q' => $qty, ':id' => $s['id']]);
            } else {
                try {
                    $nomPdo->prepare(
                        "INSERT INTO product_stock (product_id,warehouse_id,cell,qty,receipt_id,receipt_no) VALUES (:p,:w,:c,:q,:r,:d)"
                    )->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':q' => $qty, ':r' => $receiptId, ':d' => $docNo]);
                } catch (Throwable) {
                    // Нет колонок прихода (старая схема) — пишем без привязки.
                    $nomPdo->prepare(
                        "INSERT INTO product_stock (product_id,warehouse_id,cell,qty) VALUES (:p,:w,:c,:q)"
                    )->execute([':p' => $pid, ':w' => $whId, ':c' => $cell, ':q' => $qty]);
                }
            }

            if ($isMysql) {
                $nomPdo->prepare(
                    "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                     VALUES (:p,NOW(),'receipt',:d,:c,:q,:b)"
                )->execute([':p' => $pid, ':d' => 'Поступление ' . $docNo, ':c' => $cell, ':q' => $qty, ':b' => $newQty]);
            } else {
                $nomPdo->prepare(
                    "INSERT INTO product_movements (product_id,doc_date,doc_type,doc_no,cell,qty_change,balance)
                     VALUES (:p,datetime('now'),'receipt',:d,:c,:q,:b)"
                )->execute([':p' => $pid, ':d' => 'Поступление ' . $docNo, ':c' => $cell, ':q' => $qty, ':b' => $newQty]);
            }
            try {
                $nomPdo->prepare(
                    "INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value) VALUES (:p,:u,:f,'qty','Остаток',:o,:n)"
                )->execute([':p' => $pid, ':u' => $userId, ':f' => $userFio, ':o' => (string)$oldQty, ':n' => (string)$newQty]);
            } catch (Throwable $e) {
            }
            $items[] = ['sku' => $sku, 'qty' => $qty, 'old' => $oldQty, 'new' => $newQty];
        }
        $nomPdo->commit();
    } catch (Throwable $e) {
        $nomPdo->rollBack();
        throw $e;
    }

    // Guard — только после успешного коммита в номенклатуру.
    try {
        $ins = $recPdo->prepare("INSERT OR IGNORE INTO receipt_stock_sync (receipt_id, payload) VALUES (:r, :p)");
        $ins->execute([':r' => $receiptId, ':p' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {
        // MySQL-вариант guard.
        try {
            $recPdo->prepare("INSERT IGNORE INTO receipt_stock_sync (receipt_id, payload) VALUES (:r, :p)")
                ->execute([':r' => $receiptId, ':p' => json_encode($items, JSON_UNESCAPED_UNICODE)]);
        } catch (Throwable $e2) {
        }
    }

    return ['synced' => true, 'already' => false, 'doc_no' => $docNo, 'items' => $items, 'created' => $created];
}
