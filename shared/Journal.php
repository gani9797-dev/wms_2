<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pg.php';

/**
 * Журнал склада и журнал серийных номеров (модуль 14 «Журнал»).
 *
 * Журнал склада пишется на каждое перемещение паллеты/товара:
 *   приход, отбор, перемещение, размещение, пополнение, отгрузка.
 *
 * Журнал серийных номеров фиксирует ТОЛЬКО факт сканирования:
 *   «Приход» — серийные номера приняты (код документа приёмки);
 *   «Отгрузка» — серийные номера отгружены (код отгрузки).
 * Паллета записывается та, на которую номера приняты/отгружены ИЗНАЧАЛЬНО:
 * при расформировании паллеты (например, 1 штука из 10 ушла в другую ячейку)
 * журнал не переписывается.
 */
/**
 * Подключение к БД журналов (одно на запрос).
 * PostgreSQL — единая база `wms` (журналы в схеме public).
 * SQLite-демо — общий файл shared/wms_common.sqlite (подключается как wms_common).
 * Соединение кэшируется: журнал пишется в циклах (паллета за паллетой).
 */
function wms_journal_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (function_exists('wms_use_pgsql') && wms_use_pgsql()) {
        $pdo = wms_pg_pdo();
        // Таблицы журналов создаёт schema.sql / db/schema-pg.sql; если их нет —
        // досоздаём сквозной схемой (идемпотентно).
        try {
            if (!wms_has_column($pdo, 'wms_journal', 'id') || !wms_has_column($pdo, 'wms_serial_journal', 'id')) {
                wms_ensure_cached($pdo, 'ext', 'wms_ensure_ext_schema');
            }
            // Старые базы: без quality INSERT в журнал падал и операции не
            // отображались. Дотягиваем колонку при каждом подключении (дёшево).
            wms_ensure_column($pdo, 'wms_journal', 'quality', "VARCHAR(20) NULL DEFAULT 'новый'", "quality TEXT DEFAULT 'новый'", "VARCHAR(20) NULL DEFAULT 'новый'");
        } catch (Throwable) {
        }
        return $pdo;
    }
    // SQLite: номенклатурная БД уже подключает общий файл wms_common
    // и дотягивает качество журнала в wms_ensure_ext_schema().
    $pdo = wms_nomenclature_pdo();
    return $pdo;
}

final class WmsJournal
{
    /** Типы операций журнала склада (в т.ч. «пополнение» — пока операции нет). */
    public const OP_TYPES = ['приход', 'отбор', 'перемещение', 'размещение', 'пополнение', 'отгрузка', 'инвентаризация', 'корректировка'];

    /** Типы операций журнала серийных номеров. */
    public const SERIAL_OP_TYPES = ['Приход', 'Отгрузка'];

    /**
     * Записать движение в журнал склада.
     * @param array $r op_type, doc_type, doc_no, sku, product_name, supplier_batch, qty, pallet,
     *                 sscc, cell_from, cell_to, zone, user_id, user_fio, comment, doc_date, quality
     */
    public static function log(PDO $pdo, array $r): int
    {
        try {
            $op = strtolower(trim((string)($r['op_type'] ?? '')));
            if ($op === '') {
                return 0;
            }
            if (!in_array($op, self::OP_TYPES, true)) {
                $op = 'перемещение';
            }
            $t = wms_ext_table($pdo, 'wms_journal');
            $st = $pdo->prepare(
                "INSERT INTO $t (doc_date, op_type, doc_type, doc_no, sku, product_name, supplier_batch, qty,
                        pallet, sscc, cell_from, cell_to, zone, user_id, user_fio, comment, quality)
                 VALUES (:d, :op, :dt, :dn, :sku, :pn, :sb, :q, :pal, :sscc, :cf, :ct, :z, :uid, :uf, :cm, :ql)"
            );
            $st->execute([
                ':d' => trim((string)($r['doc_date'] ?? '')) !== '' ? (string)$r['doc_date'] : date('Y-m-d H:i:s'),
                ':op' => $op,
                ':dt' => self::s($r['doc_type'] ?? null),
                ':dn' => self::s($r['doc_no'] ?? null),
                ':sku' => self::s($r['sku'] ?? null),
                ':pn' => self::s($r['product_name'] ?? null),
                ':sb' => self::s($r['supplier_batch'] ?? null),
                ':q' => (float)($r['qty'] ?? 0),
                ':pal' => self::s($r['pallet'] ?? null),
                ':sscc' => self::s($r['sscc'] ?? null),
                ':cf' => self::s($r['cell_from'] ?? null),
                ':ct' => self::s($r['cell_to'] ?? null),
                ':z' => self::s($r['zone'] ?? null),
                ':uid' => isset($r['user_id']) && $r['user_id'] !== '' ? (int)$r['user_id'] : null,
                ':uf' => self::s($r['user_fio'] ?? null),
                ':cm' => self::s($r['comment'] ?? null),
                ':ql' => self::s($r['quality'] ?? null) ?? 'новый',
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Записать серийный номер в журнал серийных номеров.
     * @param array $r serial_no, sku, product_name, op_type (Приход/Отгрузка),
     *                 receipt_doc_no, shipment_doc_no, pallet, qty, cell, doc_date, user_id, user_fio
     */
    public static function logSerial(PDO $pdo, array $r): int
    {
        try {
            $sn = trim((string)($r['serial_no'] ?? ''));
            if ($sn === '') {
                return 0;
            }
            $op = trim((string)($r['op_type'] ?? 'Приход'));
            if (!in_array($op, self::SERIAL_OP_TYPES, true)) {
                $op = 'Приход';
            }
            $t = wms_ext_table($pdo, 'wms_serial_journal');
            $st = $pdo->prepare(
                "INSERT INTO $t (serial_no, sku, product_name, op_type, receipt_doc_no,
                        shipment_doc_no, pallet, qty, cell, doc_date, user_id, user_fio)
                 VALUES (:sn, :sku, :pn, :op, :rd, :sd, :pal, :q, :cell, :d, :uid, :uf)"
            );
            $st->execute([
                ':sn' => $sn,
                ':sku' => self::s($r['sku'] ?? null),
                ':pn' => self::s($r['product_name'] ?? null),
                ':op' => $op,
                ':rd' => self::s($r['receipt_doc_no'] ?? null),
                ':sd' => self::s($r['shipment_doc_no'] ?? null),
                ':pal' => self::s($r['pallet'] ?? null),
                ':q' => (float)($r['qty'] ?? 1),
                ':cell' => self::s($r['cell'] ?? null),
                ':d' => trim((string)($r['doc_date'] ?? '')) !== '' ? (string)$r['doc_date'] : date('Y-m-d H:i:s'),
                ':uid' => isset($r['user_id']) && $r['user_id'] !== '' ? (int)$r['user_id'] : null,
                ':uf' => self::s($r['user_fio'] ?? null),
            ]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Выборка журнала склада.
     * @param array $f op_type[], date_from, date_to, sku, doc_no, search, page, per_page, sort, dir
     */
    public static function query(PDO $pdo, array $f = []): array
    {
        $t = wms_ext_table($pdo, 'wms_journal');
        [$where, $params] = self::buildWhere($f);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM $t $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $page = max(1, (int)($f['page'] ?? 1));
        $perPage = wms_per_page($f['per_page'] ?? 50, 50, 500, 5);
        $sortMap = [
            'doc_date' => ['doc_date', 'd'], 'op_type' => ['op_type', 't'],
            'doc_no' => ['doc_no', 't'], 'sku' => ['sku', 't'],
            'qty' => ['qty', 'n'], 'cell_from' => ['cell_from', 't'], 'cell_to' => ['cell_to', 't'],
        ];
        $orderBy = wms_order_by($pdo, (string)($f['sort'] ?? 'doc_date'), (string)($f['dir'] ?? 'DESC'), $sortMap, 'doc_date', 'd');
        $st = $pdo->prepare("SELECT * FROM $t $where ORDER BY $orderBy LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
        $to = min(($page - 1) * $perPage + $perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to];
    }

    /**
     * Выборка журнала серийных номеров.
     * @param array $f op_type[], date_from, date_to, serial_no, sku, doc_no, search, page, per_page
     */
    public static function querySerial(PDO $pdo, array $f = []): array
    {
        $t = wms_ext_table($pdo, 'wms_serial_journal');
        [$where, $params] = self::buildWhere($f, true);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM $t $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $page = max(1, (int)($f['page'] ?? 1));
        $perPage = wms_per_page($f['per_page'] ?? 50, 50, 500, 5);
        $sortMap = ['doc_date' => ['doc_date', 'd'], 'serial_no' => ['serial_no', 't'],
            'sku' => ['sku', 't'], 'pallet' => ['pallet', 't'], 'op_type' => ['op_type', 't']];
        $orderBy = wms_order_by($pdo, (string)($f['sort'] ?? 'doc_date'), (string)($f['dir'] ?? 'DESC'), $sortMap, 'doc_date', 'd');
        $st = $pdo->prepare("SELECT * FROM $t $where ORDER BY $orderBy LIMIT :lim OFFSET :off");
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $st->bindValue(':off', ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $from = $total === 0 ? 0 : ($page - 1) * $perPage + 1;
        $to = min(($page - 1) * $perPage + $perPage, $total);
        return ['rows' => $rows, 'total' => $total, 'from' => $from, 'to' => $to];
    }

    /** @return array{0:string,1:array} */
    private static function buildWhere(array $f, bool $serial = false): array
    {
        $w = ['1=1'];
        $p = [];
        $ops = $f['op_type'] ?? [];
        if (is_string($ops)) {
            $ops = $ops === '' ? [] : [$ops];
        }
        if (is_array($ops) && $ops) {
            $ph = [];
            foreach (array_values(array_filter(array_map('strval', $ops), fn($v) => $v !== '')) as $i => $v) {
                $k = ':op' . $i;
                $ph[] = $k;
                $p[$k] = $serial ? $v : strtolower($v);
            }
            if ($ph) {
                $w[] = 'op_type IN (' . implode(',', $ph) . ')';
            }
        }
        if (!empty($f['date_from'])) {
            $w[] = 'doc_date >= :df';
            $p[':df'] = $f['date_from'] . ' 00:00:00';
        }
        if (!empty($f['date_to'])) {
            $w[] = 'doc_date <= :dt';
            $p[':dt'] = $f['date_to'] . ' 23:59:59';
        }
        if (!empty($f['sku'])) {
            $w[] = 'sku = :sku';
            $p[':sku'] = (string)$f['sku'];
        }
        if (!empty($f['doc_no'])) {
            $w[] = 'doc_no = :dn';
            $p[':dn'] = (string)$f['doc_no'];
        }
        if (!empty($f['serial_no'])) {
            $w[] = 'serial_no = :sn';
            $p[':sn'] = (string)$f['serial_no'];
        }
        if (!empty($f['pallet'])) {
            $w[] = 'pallet = :pal';
            $p[':pal'] = (string)$f['pallet'];
        }
        if (!empty($f['search'])) {
            $q = '%' . (string)$f['search'] . '%';
            if ($serial) {
                $w[] = '(serial_no LIKE :q OR sku LIKE :q OR pallet LIKE :q OR receipt_doc_no LIKE :q OR shipment_doc_no LIKE :q)';
            } else {
                $w[] = '(sku LIKE :q OR doc_no LIKE :q OR product_name LIKE :q OR pallet LIKE :q OR sscc LIKE :q OR cell_from LIKE :q OR cell_to LIKE :q)';
            }
            $p[':q'] = $q;
        }
        return ['WHERE ' . implode(' AND ', $w), $p];
    }

    private static function s(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }
}
