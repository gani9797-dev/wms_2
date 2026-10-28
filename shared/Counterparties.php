<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pg.php';

/**
 * Контрагенты (клиенты) и их настройки:
 *   - принцип резервирования (впуск заданий на отбор): FIFO / FEFO / LIFO,
 *     по умолчанию FIFO;
 *   - глобальные флаги сканирования серийных номеров на входе и на выходе;
 *   - зоны (и ячейки) размещения клиента — несколько зон допустимо.
 *
 * Глобальный флаг клиента наследуется всеми его артикулами. На уровне
 * артикула флаг можно точечно снять (products.scan_serial_*_override = 0)
 * или включить принудительно (= 1). NULL означает «наследовать от клиента».
 */
final class WmsCounterparties
{
    /** Принципы резервирования. */
    public const STRATEGIES = ['FIFO', 'FEFO', 'LIFO'];

    /** Принцип по умолчанию, если у клиента не задан. */
    public const DEFAULT_STRATEGY = 'FIFO';

    public static function table(PDO $pdo): string
    {
        return wms_ext_table($pdo, 'counterparties');
    }

    /** @return array<int,array> */
    public static function listAll(PDO $pdo, bool $onlyActive = false): array
    {
        try {
            $t = self::table($pdo);
            $sql = "SELECT * FROM $t" . ($onlyActive ? ' WHERE COALESCE(is_active,1)=1' : '') . ' ORDER BY name';
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        try {
            $t = self::table($pdo);
            $st = $pdo->prepare("SELECT * FROM $t WHERE id = :id");
            $st->execute([':id' => $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Найти контрагента по названию (для документов, где id клиента
     * ведётся по старому справочнику contractors).
     */
    public static function findByName(PDO $pdo, string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        try {
            $t = self::table($pdo);
            $st = $pdo->prepare("SELECT * FROM $t WHERE name = :n LIMIT 1");
            $st->execute([':n' => $name]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $r ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Автосоздание контрагента по имени из загрузки прихода/отгрузки.
     *
     * Приход -> тип «клиент», отгрузка -> тип «грузополучатель». Если имя уже
     * есть как грузополучатель и встречается в приходе, тип повышается до
     * «клиент» (одно и то же значение и в приходе, и в отгрузке).
     * Новые записи создаются с флагами сканирования с/н «нет» (0) и FIFO.
     */
    public static function ensure(PDO $pdo, string $name, string $ctype): ?int
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));
        if ($name === '') {
            return null;
        }
        if (!in_array($ctype, ['supplier', 'client', 'consignee'], true)) {
            $ctype = 'client';
        }
        // Таблица справочника может ещё не существовать (демо/чистая БД).
        if (function_exists('wms_ensure_ext_schema')) {
            try {
                wms_ensure_cached($pdo, 'ext', 'wms_ensure_ext_schema');
            } catch (Throwable) {
            }
        }
        $norm = static function (string $s): string {
            $s = (string)preg_replace('/\s+/u', ' ', trim($s));
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($s, 'UTF-8');
            }
            // Без mbstring strtolower не трогает кириллицу — понижаем регистр вручную.
            return strtolower(strtr($s,
                'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',
                'абвгдеёжзийклмнопрстуфхцчшщъыьэюя'
            ));
        };
        $want = $norm($name);
        $t = self::table($pdo);
        $found = null;
        try {
            foreach ($pdo->query("SELECT id, name, ctype FROM $t")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if ($norm((string)($r['name'] ?? '')) === $want) {
                    $found = $r;
                    break;
                }
            }
        } catch (Throwable) {
            return null;
        }
        if ($found) {
            $id = (int)$found['id'];
            if ($ctype === 'client' && (string)($found['ctype'] ?? '') !== 'client') {
                try {
                    $pdo->prepare("UPDATE $t SET ctype = 'client' WHERE id = :id")
                        ->execute([':id' => $id]);
                } catch (Throwable) {
                }
            }
            return $id;
        }
        try {
            $st = $pdo->prepare(
                "INSERT INTO $t (name, ctype, is_active, reserve_strategy, scan_serial_in, scan_serial_out)
                 VALUES (:n, :ct, 1, 'FIFO', 0, 0)"
            );
            $st->execute([':n' => $name, ':ct' => $ctype]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Эффективные флаги артикула (единый источник для ТСД).
     *
     *   override IS NULL  -> наследование глобального флага клиента;
     *   override = 0      -> флаг снят на артикуле;
     *   override = 1      -> флаг включён принудительно.
     *
     * effective_in  = COALESCE(scan_serial_in_override, counterparties.scan_serial_in, 0)
     * effective_out = COALESCE(scan_serial_out_override, counterparties.scan_serial_out, 0)
     *
     * @param PDO $nomPdo подключение к номенклатуре (таблица products)
     * @return array{sku:string,in:int,out:int,prod_date:int,contractor_id:?int,inherited_in:bool,inherited_out:bool}
     */
    public static function productFlags(PDO $nomPdo, string $sku): array
    {
        $sku = trim($sku);
        $out = ['sku' => $sku, 'in' => 0, 'out' => 0, 'prod_date' => 0,
            'contractor_id' => null, 'inherited_in' => true, 'inherited_out' => true];
        if ($sku === '') {
            return $out;
        }
        try {
            $st = $nomPdo->prepare(
                'SELECT contractor_id, scan_serial_in_override, scan_serial_out_override, control_prod_date
                   FROM products WHERE sku = :s LIMIT 1'
            );
            $st->execute([':s' => $sku]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $p = null;
        }
        if (!$p) {
            return $out;
        }
        $cid = (isset($p['contractor_id']) && $p['contractor_id'] !== '' && $p['contractor_id'] !== null)
            ? (int)$p['contractor_id'] : null;
        $out['contractor_id'] = $cid;
        $out['prod_date'] = (int)($p['control_prod_date'] ?? 0) ? 1 : 0;
        $client = $cid ? self::serialFlags($nomPdo, $cid) : ['in' => 0, 'out' => 0];
        $inOv = $p['scan_serial_in_override'] ?? null;
        $outOv = $p['scan_serial_out_override'] ?? null;
        if ($inOv === null || $inOv === '') {
            $out['in'] = (int)$client['in'] ? 1 : 0;
            $out['inherited_in'] = true;
        } else {
            $out['in'] = (int)$inOv ? 1 : 0;
            $out['inherited_in'] = false;
        }
        if ($outOv === null || $outOv === '') {
            $out['out'] = (int)$client['out'] ? 1 : 0;
            $out['inherited_out'] = true;
        } else {
            $out['out'] = (int)$outOv ? 1 : 0;
            $out['inherited_out'] = false;
        }
        return $out;
    }

    /**
     * Эффективные флаги для списка артикулов (быстрый вариант: два запроса).
     * @param string[] $skus
     * @return array<string,array> ключ — SKU
     */
    public static function productFlagsBatch(PDO $nomPdo, array $skus): array
    {
        $out = [];
        $list = [];
        foreach ($skus as $s) {
            $s = trim((string)$s);
            if ($s === '') {
                continue;
            }
            $list[$s] = true;
            $out[$s] = ['sku' => $s, 'in' => 0, 'out' => 0, 'prod_date' => 0,
                'contractor_id' => null, 'inherited_in' => true, 'inherited_out' => true];
        }
        if (!$list) {
            return $out;
        }
        $skus = array_keys($list);
        try {
            $in = implode(',', array_fill(0, count($skus), '?'));
            $st = $nomPdo->prepare(
                "SELECT sku, contractor_id, scan_serial_in_override, scan_serial_out_override, control_prod_date
                   FROM products WHERE sku IN ($in)"
            );
            $st->execute($skus);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $out;
        }
        $clientCache = [];
        foreach ($rows as $p) {
            $sku = trim((string)($p['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $cid = (isset($p['contractor_id']) && $p['contractor_id'] !== '' && $p['contractor_id'] !== null)
                ? (int)$p['contractor_id'] : null;
            if ($cid !== null && !array_key_exists($cid, $clientCache)) {
                $clientCache[$cid] = self::serialFlags($nomPdo, $cid);
            }
            $client = $cid !== null ? $clientCache[$cid] : ['in' => 0, 'out' => 0];
            $inOv = $p['scan_serial_in_override'] ?? null;
            $outOv = $p['scan_serial_out_override'] ?? null;
            $out[$sku] = [
                'sku' => $sku,
                'in' => ($inOv === null || $inOv === '') ? ((int)$client['in'] ? 1 : 0) : ((int)$inOv ? 1 : 0),
                'out' => ($outOv === null || $outOv === '') ? ((int)$client['out'] ? 1 : 0) : ((int)$outOv ? 1 : 0),
                'prod_date' => (int)($p['control_prod_date'] ?? 0) ? 1 : 0,
                'contractor_id' => $cid,
                'inherited_in' => ($inOv === null || $inOv === ''),
                'inherited_out' => ($outOv === null || $outOv === ''),
            ];
        }
        return $out;
    }

    /**
     * Настройки клиента с нормализацией значений.
     * @return array{reserve_strategy:string,scan_serial_in:int,scan_serial_out:int,placement_zones:array,placement_cell_codes:array}
     */
    public static function settings(PDO $pdo, ?int $contractorId): array
    {
        $out = [
            'reserve_strategy' => self::DEFAULT_STRATEGY,
            'scan_serial_in' => 0,
            'scan_serial_out' => 0,
            'placement_zones' => [],
            'placement_cell_codes' => [],
        ];
        if (!$contractorId) {
            return $out;
        }
        $cp = self::find($pdo, $contractorId);
        if (!$cp) {
            return $out;
        }
        $s = strtoupper(trim((string)($cp['reserve_strategy'] ?? '')));
        if (in_array($s, self::STRATEGIES, true)) {
            $out['reserve_strategy'] = $s;
        }
        $out['scan_serial_in'] = (int)($cp['scan_serial_in'] ?? 0) ? 1 : 0;
        $out['scan_serial_out'] = (int)($cp['scan_serial_out'] ?? 0) ? 1 : 0;
        $out['placement_zones'] = self::splitList($cp['placement_zones'] ?? null);
        $out['placement_cell_codes'] = self::splitList($cp['placement_cell_codes'] ?? null);
        return $out;
    }

    /** Принцип резервирования клиента (по умолчанию FIFO). */
    public static function reserveStrategy(PDO $pdo, ?int $contractorId): string
    {
        return self::settings($pdo, $contractorId)['reserve_strategy'];
    }

    /** Глобальные флаги сканирования с/н клиента. */
    public static function serialFlags(PDO $pdo, ?int $contractorId): array
    {
        $s = self::settings($pdo, $contractorId);
        return ['in' => $s['scan_serial_in'], 'out' => $s['scan_serial_out']];
    }

    /** Зоны размещения клиента (коды зон). */
    public static function placementZones(PDO $pdo, ?int $contractorId): array
    {
        return self::settings($pdo, $contractorId)['placement_zones'];
    }

    /** Конкретные ячейки размещения клиента (коды ячеек через запятую). */
    public static function placementCellCodes(PDO $pdo, ?int $contractorId): array
    {
        return self::settings($pdo, $contractorId)['placement_cell_codes'];
    }

    /**
     * Сохранить настройки клиента и пересчитать наследование флагов по его артикулам.
     */
    public static function save(PDO $pdo, int $id, array $b): array
    {
        $t = self::table($pdo);
        $cur = self::find($pdo, $id);
        if (!$cur) {
            throw new RuntimeException('Контрагент не найден');
        }
        $strategy = strtoupper(trim((string)($b['reserve_strategy'] ?? $cur['reserve_strategy'] ?? self::DEFAULT_STRATEGY)));
        if (!in_array($strategy, self::STRATEGIES, true)) {
            throw new RuntimeException('Принцип резервирования: FIFO / FEFO / LIFO');
        }
        $zones = self::splitList($b['placement_zones'] ?? ($cur['placement_zones'] ?? null));
        $cells = self::splitList($b['placement_cell_codes'] ?? ($cur['placement_cell_codes'] ?? null));
        $set = [
            ':id' => $id,
            ':rs' => $strategy,
            ':si' => self::boolInt($b['scan_serial_in'] ?? ($cur['scan_serial_in'] ?? 0)),
            ':so' => self::boolInt($b['scan_serial_out'] ?? ($cur['scan_serial_out'] ?? 0)),
            ':pz' => $zones === [] ? null : implode(',', $zones),
            ':pc' => $cells === [] ? null : implode(',', $cells),
        ];
        $st = $pdo->prepare(
            "UPDATE $t SET reserve_strategy = :rs, scan_serial_in = :si, scan_serial_out = :so,
                    placement_zones = :pz, placement_cell_codes = :pc WHERE id = :id"
        );
        $st->execute($set);
        // Глобальный флаг клиента проставляется всем его артикулам
        // (в т.ч. новым) — если по артикулу нет точечного решения.
        self::applyFlagsToProducts($pdo, $id);
        return self::settings($pdo, $id);
    }

    /**
     * Пересчитать эффективные флаги сканирования с/н для всех артикулов клиента.
     * @return int сколько артикулов обновлено
     */
    public static function applyFlagsToProducts(PDO $pdo, int $contractorId): int
    {
        try {
            $cp = self::find($pdo, $contractorId);
            if (!$cp) {
                return 0;
            }
            $gi = (int)($cp['scan_serial_in'] ?? 0) ? 1 : 0;
            $go = (int)($cp['scan_serial_out'] ?? 0) ? 1 : 0;
            $st = $pdo->prepare(
                "UPDATE products SET scan_serial_in = COALESCE(scan_serial_in_override, :gi),
                        scan_serial_out = COALESCE(scan_serial_out_override, :go)
                 WHERE contractor_id = :cid"
            );
            $st->execute([':gi' => $gi, ':go' => $go, ':cid' => $contractorId]);
            return $st->rowCount();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Пересчитать эффективные флаги одного артикула (override + глобальный флаг клиента).
     * Вызывается при создании/изменении карточки и при смене клиента.
     */
    public static function syncProduct(PDO $pdo, int $productId): void
    {
        try {
            $st = $pdo->prepare("SELECT contractor_id, scan_serial_in_override, scan_serial_out_override FROM products WHERE id = :id");
            $st->execute([':id' => $productId]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                return;
            }
            $cid = isset($p['contractor_id']) && $p['contractor_id'] !== '' ? (int)$p['contractor_id'] : null;
            $flags = self::serialFlags($pdo, $cid);
            $inOv = $p['scan_serial_in_override'];
            $outOv = $p['scan_serial_out_override'];
            $in = $inOv === null ? $flags['in'] : ((int)$inOv ? 1 : 0);
            $out = $outOv === null ? $flags['out'] : ((int)$outOv ? 1 : 0);
            $u = $pdo->prepare("UPDATE products SET scan_serial_in = :i, scan_serial_out = :o WHERE id = :id");
            $u->execute([':i' => $in, ':o' => $out, ':id' => $productId]);
        } catch (Throwable) {
        }
    }

    /**
     * Применить глобальные флаги клиента к НОВОМУ артикулу (новые зашедшие товары).
     */
    public static function applyToNewProduct(PDO $pdo, int $productId, ?int $contractorId): void
    {
        try {
            if ($contractorId) {
                $pdo->prepare("UPDATE products SET contractor_id = :c WHERE id = :id")
                    ->execute([':c' => $contractorId, ':id' => $productId]);
            }
            self::syncProduct($pdo, $productId);
        } catch (Throwable) {
        }
    }

    /** Разбить список «a, b, c» в массив уникальных непустых значений. */
    public static function splitList(mixed $v): array
    {
        if (is_array($v)) {
            $parts = $v;
        } else {
            $parts = preg_split('/[,\s;]+/', (string)($v ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        }
        $out = [];
        foreach ((array)$parts as $s) {
            $s = trim((string)$s);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    private static function boolInt(mixed $v): int
    {
        if ($v === true) {
            return 1;
        }
        $s = strtolower(trim((string)$v));
        return ($s === '1' || $s === 'on' || $s === 'true' || $s === 'да') ? 1 : 0;
    }
}
