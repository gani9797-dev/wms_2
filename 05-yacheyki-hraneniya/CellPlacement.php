<?php
declare(strict_types=1);

// Подбор ячейки для размещения паллеты с учетом секционных правил.
// Секция = Ряд + Секция (building + row_no, т.е. вертикаль; все уровни вместе).
// Уровень балки = building + row_no + level_no (один ярус, на нем лежат места place_no).
//
// Правила (балка задается при создании батча, одна на всё создание):
//   3.2 м: на ярусе 3 евро ИЛИ 2 фин. Хоть один евро в секции => вся секция
//          настраивается под евро (свободные ячейки секции переводятся на euro).
//   3.6 м: на ярусе 4 евро (секция жестко под евро). Фин можно 3 шт, при этом
//          следующая свободная ячейка яруса (по порядку place_no/code) блокируется
//          is_locked=1, lock_reason='(Фин секция)' (снимается при освобождении).
// Подбор онлайновый (с 2026) — приоритет по шагам:
//   1) ячейка клиента (counterparties.placement_cell_codes) — попадание точно в «свою» ячейку;
//   2) неполные секции — секции, где кроме нашей ячейки остаются свободные
//      паллетные места (ячейки не заблокированы). Их заполняем первыми;
//   3) близость к клиенту — ячейка (или её секция), где уже лежит товар ЭТОГО клиента;
//   4) прежние правила: самая заполненная секция, самый заполненный ярус,
//      минимальная остаточная высота, код ячейки.
// Плюс фильтры: высота (cell_height_mm >= высоты паллеты), тип тары
// (allowed_pallet_types), только свободные незаблокированные ячейки хранения.
// Зоны: если у клиента заданы зоны размещения (counterparties.placement_zones) —
// подбор идёт ТОЛЬКО по этим зонам; если зоны не заданы — общие правила (зона storage).
// Дополнительно: правило «разные товары в одной ячейке» — если в ячейке
// allow_mixed_goods = 0, то класть в неё товар другого SKU нельзя.

final class CellPlacement
{
    /** Старая причина временной блокировки яруса (совместимость с выставленными ранее). */
    public const TEMP_BUSY_REASON = 'временно занята';

    /** Причина блокировки ячейки в «фин секции» (ярус набит тремя финами). */
    public const FIN_SECTION_REASON = '(Фин секция)';

    public const BEAM_32 = 3.2;
    public const BEAM_36 = 3.6;

    /**
     * Подключение к БД номенклатуры (SKU => products.contractor_id).
     * null — берётся wms_nomenclature_pdo(). Нужно, чтобы тесты/демо работали
     * в одной транзакции с БД хранения.
     */
    private static ?PDO $nomenclaturePdo = null;

    /** Кэш настроек робота размещения. */
    private static ?array $robotSettings = null;

    /** Подменить подключение к номенклатуре (null — вернуть штатное). */
    public static function setNomenclaturePdo(?PDO $pdo): void
    {
        self::$nomenclaturePdo = $pdo;
    }

    /**
     * Настройки робота размещения из таблицы robot_settings (модуль 13).
     * Если таблицы нет или она пуста — значения по умолчанию.
     * @return array{section_pallet_lock:int,euro_cap_per_section:int,fin_cap_per_section:int,fin_block_3rd_cell:int}
     */
    public static function robotSettings(PDO $pdo): array
    {
        if (self::$robotSettings !== null) {
            return self::$robotSettings;
        }
        $out = [
            'section_pallet_lock' => 1,
            'euro_cap_per_section' => 4,
            'fin_cap_per_section' => 3,
            'fin_block_3rd_cell' => 1,
            'beam32_euro_places' => 3,
            'beam32_fin_places' => 2,
            'beam36_euro_places' => 4,
            'beam36_fin_places' => 3,
            'beam36_fin_block_place' => 3,
        ];
        try {
            // Таблица настроек робота (модуль 13 «Склад и хранение»).
            $drv = function_exists('wms_driver') ? wms_driver($pdo) : (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $pg = $drv === 'pgsql';
            $auto = $pg ? '' : ' AUTOINCREMENT';
            $pdo->exec("CREATE TABLE IF NOT EXISTS robot_settings (
                id " . ($pg ? 'BIGINT PRIMARY KEY' : 'INTEGER PRIMARY KEY' . $auto) . ",
                section_pallet_lock " . ($pg ? 'SMALLINT' : 'INTEGER') . " NOT NULL DEFAULT 1,
                euro_cap_per_section INTEGER NOT NULL DEFAULT 4,
                fin_cap_per_section INTEGER NOT NULL DEFAULT 3,
                fin_block_3rd_cell " . ($pg ? 'SMALLINT' : 'INTEGER') . " NOT NULL DEFAULT 1,
                beam32_euro_places INTEGER NOT NULL DEFAULT 3,
                beam32_fin_places INTEGER NOT NULL DEFAULT 2,
                beam36_euro_places INTEGER NOT NULL DEFAULT 4,
                beam36_fin_places INTEGER NOT NULL DEFAULT 3,
                beam36_fin_block_place INTEGER NOT NULL DEFAULT 3,
                updated_at " . ($pg ? 'TIMESTAMP' : 'TEXT') . " NULL)");
            foreach ([
                ['beam32_euro_places', 3], ['beam32_fin_places', 2],
                ['beam36_euro_places', 4], ['beam36_fin_places', 3], ['beam36_fin_block_place', 3],
            ] as [$col, $def]) {
                if (function_exists('wms_ensure_column')) {
                    wms_ensure_column($pdo, 'robot_settings', $col,
                        'INT NOT NULL DEFAULT ' . $def, 'INTEGER NOT NULL DEFAULT ' . $def, 'INTEGER NOT NULL DEFAULT ' . $def);
                }
            }
            $st = $pdo->query('SELECT * FROM robot_settings WHERE id = 1 LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            if (!$row) {
                try {
                    $pdo->exec('INSERT INTO robot_settings (id) VALUES (1)');
                } catch (Throwable) {
                }
                $st = $pdo->query('SELECT * FROM robot_settings WHERE id = 1 LIMIT 1');
                $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            }
            if ($row) {
                foreach ($out as $k => $def) {
                    if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
                        $out[$k] = (int)$row[$k];
                    }
                }
            }
        } catch (Throwable) {
            // Таблицы ещё нет — используем значения по умолчанию.
        }
        return self::$robotSettings = $out;
    }

    /** Сохранить настройки робота размещения (модуль 13 «Склад и хранение»). */
    public static function saveRobotSettings(PDO $pdo, array $b): array
    {
        self::robotSettings($pdo); // гарантируем таблицу и строку id=1
        $fields = [
            'section_pallet_lock' => 0, 'euro_cap_per_section' => 4, 'fin_cap_per_section' => 3,
            'fin_block_3rd_cell' => 0, 'beam32_euro_places' => 3, 'beam32_fin_places' => 2,
            'beam36_euro_places' => 4, 'beam36_fin_places' => 3, 'beam36_fin_block_place' => 3,
        ];
        $flags = ['section_pallet_lock', 'fin_block_3rd_cell'];
        $positive = ['euro_cap_per_section', 'fin_cap_per_section', 'beam32_euro_places',
            'beam32_fin_places', 'beam36_euro_places', 'beam36_fin_places'];
        $sets = [];
        $p = [];
        foreach ($fields as $k => $def) {
            if (!array_key_exists($k, $b)) {
                continue;
            }
            if (in_array($k, $flags, true)) {
                $v = (int)!empty($b[$k]);
            } else {
                $v = (int)$b[$k];
                if (in_array($k, $positive, true)) {
                    $v = max(1, $v);
                }
                $v = max(0, $v);
            }
            $sets[] = $k . ' = :' . $k;
            $p[':' . $k] = $v;
        }
        if ($sets) {
            $p[':id'] = 1;
            try {
                $pdo->prepare('UPDATE robot_settings SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);
            } catch (Throwable) {
            }
        }
        self::resetRobotSettingsCache();
        return self::robotSettings($pdo);
    }
    /** Сбросить кэш настроек робота (вызывать после сохранения в модуле 13). */
    public static function resetRobotSettingsCache(): void
    {
        self::$robotSettings = null;
    }

    /** Причины временной блокировки яруса: новая и старая (для снятия старых). */
    public static function tempBlockReasons(): array
    {
        return [self::FIN_SECTION_REASON, self::TEMP_BUSY_REASON];
    }

    public static function normalizeBeam(mixed $v): float
    {
        $f = (float)($v ?? self::BEAM_32);
        // Допуск округления: всё <= 3.4 считаем 3.2, иначе 3.6.
        return $f <= 3.4 ? self::BEAM_32 : self::BEAM_36;
    }

    public static function normalizePalletType(?string $v): string
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($v ?? '')), 'UTF-8') : strtolower(trim((string)($v ?? '')));
        if (in_array($s, ['евро', 'euro', 'eur', 'е'], true)) {
            return 'euro';
        }
        if (in_array($s, ['фин', 'финская', 'fin', 'fan', 'ф'], true)) {
            return 'fin';
        }
        // По умолчанию считаем евро (самый частый кейс).
        return $s !== '' ? $s : 'euro';
    }

    /**
     * Вместимость яруса по типу тары с учётом настроек робота:
     *   балка 3,2: евро 3 / фин 2;  балка 3,6: евро 4 / фин 3 (3-я ячейка под фин блокируется).
     */
    public static function levelCapacity(float $beam, string $palletType, ?array $robot = null): int
    {
        $beam = self::normalizeBeam($beam);
        $t = self::normalizePalletType($palletType);
        $robot = $robot ?? [];
        if ($beam <= 3.4) {
            $key = $t === 'fin' ? 'beam32_fin_places' : 'beam32_euro_places';
            $def = $t === 'fin' ? 2 : 3;
            $v = (int)($robot[$key] ?? 0);
            return $v > 0 ? $v : $def;
        }
        $key = $t === 'fin' ? 'beam36_fin_places' : 'beam36_euro_places';
        $def = $t === 'fin' ? 3 : 4;
        $v = (int)($robot[$key] ?? 0);
        return $v > 0 ? $v : $def;
    }

    public static function sectionKey(array $cell): string
    {
        return ($cell['warehouse_id'] ?? '') . '|' . ($cell['building'] ?? '') . '|' . ($cell['row_no'] ?? '');
    }

    public static function levelKey(array $cell): string
    {
        return self::sectionKey($cell) . '|' . ($cell['level_no'] ?? '');
    }

    private static function isOccupied(array $c): bool
    {
        if ((float)($c['qty'] ?? 0) > 0) {
            return true;
        }
        if ((int)($c['pallet_count'] ?? 0) > 0) {
            return true;
        }
        if (!empty($c['pallet_type'])) {
            return true;
        }
        return in_array(($c['status'] ?? ''), ['busy', 'reserved'], true);
    }

    private static function isTempBlocked(array $c): bool
    {
        if ((int)($c['is_locked'] ?? 0) !== 1) {
            return false;
        }
        return in_array(trim((string)($c['lock_reason'] ?? '')), self::tempBlockReasons(), true);
    }

    /** Приводит SKU (строку или список) к списку строк. */
    public static function skuList(string|array|null $sku): array
    {
        if ($sku === null) {
            return [];
        }
        $list = is_array($sku) ? $sku : [$sku];
        $out = [];
        foreach ($list as $s) {
            $s = trim((string)$s);
            if ($s !== '' && !in_array($s, $out, true)) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Правило «разные товары в одной ячейке».
     * Если в ячейке снят флаг allow_mixed_goods, то в неё нельзя положить
     * товар, отличный от уже лежащего.
     * @param array $cell   строка ячейки (нужен allow_mixed_goods)
     * @param string[] $existing SKU, уже лежащие в ячейке
     * @param string[] $incoming SKU, которые кладут в ячейку
     * @return string|null текст ошибки (null — можно)
     */
    public static function mixedGoodsError(array $cell, array $existing, array $incoming): ?string
    {
        // Разные товары разрешены — правило не действует.
        if ((int)($cell['allow_mixed_goods'] ?? 1) === 1) {
            return null;
        }
        $existing = self::skuList($existing);
        if (!$existing) {
            return null;
        }
        $incoming = self::skuList($incoming);
        foreach ($existing as $sku) {
            if (!$incoming || !in_array($sku, $incoming, true)) {
                return 'В ячейке запрещено хранить разные товары (уже лежит: ' . $sku . ')';
            }
        }
        return null;
    }

    /**
     * SKU, уже лежащие в ячейке (таблица cell_content).
     * Учитываются только строки с ненулевым остатком/резервом.
     * @return string[]
     */
    public static function cellContentSkus(PDO $pdo, int $cellId): array
    {
        try {
            $st = $pdo->prepare(
                "SELECT sku FROM cell_content WHERE cell_id = :c
                   AND (COALESCE(qty,0) > 0 OR COALESCE(reserved_qty,0) > 0)"
            );
            $st->execute([':c' => $cellId]);
            return self::skuList($st->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable) {
            return [];
        }
    }

    private static function cellAllows(array $c, string $palletType): bool
    {
        $raw = trim((string)($c['allowed_pallet_types'] ?? 'euro,fin'));
        if ($raw === '' || $raw === 'Все' || $raw === '*') {
            return true;
        }
        $parts = array_map(fn($s) => self::normalizePalletType($s), preg_split('/[,\s;\/]+/', $raw, -1, PREG_SPLIT_NO_EMPTY));
        return in_array(self::normalizePalletType($palletType), $parts, true);
    }

    /** Допускает ли ячейка такую тару (публичная обёртка для повторных проверок). */
    public static function cellAllowsType(array $cell, string $palletType): bool
    {
        return self::cellAllows($cell, $palletType);
    }

    /**
     * Снимок всех ячеек склада для секционных проверок.
     * Дополнительно к каждой ячейке добавляется content_skus — SKU, которые
     * в ней уже лежат (для правила «разные товары в одной ячейке»).
     * @return array{bySection: array, byLevel: array, content: array}
     */
    public static function snapshot(PDO $pdo, ?int $warehouseId = null): array
    {
        $all = $pdo->query(
            "SELECT id, code, warehouse_id, building, row_no, level_no, place_no, status, qty,
                    pallet_count, beam_width, cell_height_mm, allowed_pallet_types, pallet_type,
                    is_locked, lock_reason, cell_type, zone, allow_mixed_goods, allow_multi_pallet_types, quality_lock
             FROM storage_cells WHERE is_archived = 0" .
            ($warehouseId ? " AND warehouse_id = " . (int)$warehouseId : "")
        )->fetchAll(PDO::FETCH_ASSOC);
        // Содержимое ячеек: [cell_id => ['SKU', ...]].
        $content = [];
        try {
            $ids = array_map(fn($c) => (int)($c['id'] ?? 0), $all);
            $ids = array_values(array_filter($ids));
            foreach (array_chunk($ids, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $st = $pdo->prepare(
                    "SELECT cell_id, sku FROM cell_content WHERE cell_id IN ($in)
                       AND (COALESCE(qty,0) > 0 OR COALESCE(reserved_qty,0) > 0)"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $cid = (int)($r['cell_id'] ?? 0);
                    $sku = trim((string)($r['sku'] ?? ''));
                    if ($cid <= 0 || $sku === '') {
                        continue;
                    }
                    if (!isset($content[$cid])) {
                        $content[$cid] = [];
                    }
                    if (!in_array($sku, $content[$cid], true)) {
                        $content[$cid][] = $sku;
                    }
                }
            }
        } catch (Throwable) {
        }
        $bySection = [];
        $byLevel = [];
        foreach ($all as $c) {
            $cid = (int)($c['id'] ?? 0);
            $c['content_skus'] = $content[$cid] ?? [];
            $bySection[self::sectionKey($c)][] = $c;
            $byLevel[self::levelKey($c)][] = $c;
        }
        return ['bySection' => $bySection, 'byLevel' => $byLevel, 'content' => $content];
    }

    /**
     * Проверка конкретной ячейки под паллету.
     * $ignoreId — ячейка, чей резерв не считаем занятостью (смена ячейки: старый резерв скоро освободят).
     * $sku — SKU, которые кладут в ячейку (для правила «разные товары в одной ячейке»).
     * $robot — настройки робота (section_pallet_lock, euro_cap_per_section, fin_cap_per_section, fin_block_3rd_cell).
     * @return array{ok: bool, error: ?string, sec_busy: int, sec_free: int, lvl_busy: int, lvl_cap: int, h_slack: ?int, sec_cap_ok: bool}
     */
    public static function checkCell(array $cell, string $palletType, ?int $palletHeightMm,
        array $bySection, array $byLevel, ?int $ignoreId = null, string|array|null $sku = null,
        ?array $robot = null, string $quality = 'новый'): array
    {
        $palletType = self::normalizePalletType($palletType);
        $robot = $robot ?? [
            'section_pallet_lock' => 0,
            'euro_cap_per_section' => 4,
            'fin_cap_per_section' => 3,
            'fin_block_3rd_cell' => 1,
        ];
        $bad = static fn(string $e) => ['ok' => false, 'error' => $e,
            'sec_busy' => 0, 'sec_free' => 0, 'lvl_busy' => 0, 'lvl_cap' => 0, 'h_slack' => null, 'sec_cap_ok' => true];
        $isIgn = static fn(array $s): bool => $ignoreId !== null && (int)($s['id'] ?? -1) === $ignoreId;
        $secCells = $bySection[self::sectionKey($cell)] ?? [];
        $lvlCells = $byLevel[self::levelKey($cell)] ?? [];
        $beam = self::normalizeBeam($cell['beam_width'] ?? self::BEAM_32);
        $cap = self::levelCapacity($beam, $palletType, $robot);

        // Флаг «разрешены разные типы паллет»: в этой ячейке робот не управляет
        // тарой (можно ставить любой тип), секционное закрепление для неё не действует.
        // Если флаг снят — робот ведёт всю секцию: первый тип закрепляется за секцией.
        $cellMixed = (int)($cell['allow_multi_pallet_types'] ?? 0) === 1;

        // Правило «разные товары в одной ячейке»: снятый флаг allow_mixed_goods.
        $mixed = self::mixedGoodsError($cell, (array)($cell['content_skus'] ?? []), self::skuList($sku));
        if ($mixed !== null) {
            return $bad($mixed);
        }
        // Качество: ячейка с флагом «Только новый товар» не принимает брак.
        if ((int)($cell['quality_lock'] ?? 0) === 1 && $quality === 'Брак') {
            return $bad('В ячейку запрещено размещать брак: установлен флаг «Только новый товар»');
        }
        if ((int)($cell['is_locked'] ?? 0) === 1) {
            $reason = trim((string)($cell['lock_reason'] ?? ''));
            if ($reason === self::FIN_SECTION_REASON && $palletType === 'fin') {
                // Ярус набит finaми — сообщаем про заполненный ярус.
                $finCap = self::levelCapacity($beam, 'fin');
                return $bad('Ярус уже полон (' . $finCap . '/' . $finCap
                    . ' на балке ' . ($beam <= 3.4 ? '3,2' : '3,6') . ')');
            }
            return $bad('Ячейка заблокирована'
                . ($reason !== '' ? ': ' . $reason : ''));
        }
        if (($cell['status'] ?? '') !== 'free' || (float)($cell['qty'] ?? 0) > 0) {
            return $bad('Ячейка уже занята: ' . ($cell['code'] ?? ''));
        }
        // Высота: ячейка без высоты принимает любую; иначе высота паллеты обязана влезть.
        if ($palletHeightMm !== null && $palletHeightMm > 0
            && $cell['cell_height_mm'] !== null && (int)$cell['cell_height_mm'] > 0
            && (int)$cell['cell_height_mm'] < $palletHeightMm) {
            return $bad('Не проходит по высоте: ячейка ' . ($cell['cell_height_mm'] ?? '?')
                . ' мм < паллеты ' . $palletHeightMm . ' мм');
        }
        if (!self::cellAllows($cell, $palletType)) {
            return $bad('Секция/ячейка не принимает тару «' . ($palletType === 'fin' ? 'фин' : 'евро') . '»');
        }

        // --- Секционная блокировка типа паллеты (робот) ---
        $secMode = null; // euro|fin|null
        $secHasManaged = false;
        foreach ($secCells as $s) {
            if ($isIgn($s) || !self::isOccupied($s)) {
                continue;
            }
            // Ячейки с флагом «разные типы» вне управления тарами робота.
            if ((int)($s['allow_multi_pallet_types'] ?? 0) === 1) {
                continue;
            }
            $secHasManaged = true;
            if (($s['pallet_type'] ?? '') === null || ($s['pallet_type'] ?? '') === '') {
                continue; // старые данные без типа — режим не блокируем
            }
            $t = self::normalizePalletType((string)$s['pallet_type']);
            if ($t === 'euro') {
                $secMode = 'euro';
                break;
            }
            if ($t === 'fin') {
                $secMode = $secMode ?? 'fin';
            }
        }
        // Секцию под один тип ведёт робот только для ячеек БЕЗ флага «разные типы».
        if (!$cellMixed && $robot['section_pallet_lock'] === 1 && $secHasManaged && $secMode !== null && $secMode !== $palletType) {
            return $bad($secMode === 'euro'
                ? 'Секция настроена под евро: фин ставить нельзя'
                : 'Секция занята финами: добивка только финами');
        }

        // --- Лимиты секции по количеству паллет (робот) ---
        $secPalletTotal = 0;
        foreach ($secCells as $s) {
            if ($isIgn($s)) {
                continue;
            }
            if (self::isOccupied($s)) {
                $secPalletTotal += (int)($s['pallet_count'] ?? 0) > 0 ? (int)($s['pallet_count'] ?? 0) : 1;
            }
        }
        $secCap = $palletType === 'fin' ? ($robot['fin_cap_per_section'] ?? 3) : ($robot['euro_cap_per_section'] ?? 4);
        $secCapOk = ($secPalletTotal + 1) <= $secCap;
        if (!$secCapOk) {
            $badRes = $bad('Секция заполнена по лимиту ' . $palletType . ': ' . $secPalletTotal . '/' . $secCap . ' паллет');
            $badRes['sec_cap_ok'] = false;
            return $badRes;
        }

        // Для 3.6 + фин: блокировка заданной ячейки яруса (по умолчанию 3-й).
        $blockPlace = (string)(int)($robot['beam36_fin_block_place'] ?? 3);
        if (!$cellMixed && $robot['fin_block_3rd_cell'] === 1 && $beam > 3.4 && $palletType === 'fin') {
            // На балке 3,6 под фин доступны 1-я, 2-я и 4-я ячейки;
            // заданная (по умолчанию 3-я) блокируется всегда.
            $placeNo = trim((string)($cell['place_no'] ?? ''));
            if ($placeNo !== '' && (int)$placeNo === (int)$blockPlace) {
                return $bad('Ячейка заблокирована: (Фин секция) — ячейка ' . $blockPlace . ' яруса недоступна для фин');
            }
        }

        $lvlBusy = 0;
        foreach ($lvlCells as $s) {
            if ($isIgn($s)) {
                continue;
            }
            if (self::isOccupied($s) || self::isTempBlocked($s)) {
                $lvlBusy++;
            }
        }
        if ($lvlBusy >= $cap) {
            return $bad('Ярус уже полон (' . min($lvlBusy, $cap) . '/' . $cap
                . ' на балке ' . ($beam <= 3.4 ? '3,2' : '3,6') . ')');
        }

        $secBusy = 0;
        $secFree = 0;
        foreach ($secCells as $s) {
            if ($isIgn($s)) {
                continue;
            }
            if (self::isOccupied($s) || self::isTempBlocked($s)) {
                $secBusy++;
            }
            if ((int)($s['is_locked'] ?? 0) === 0 && ($s['status'] ?? '') === 'free' && (float)($s['qty'] ?? 0) <= 0) {
                $secFree++;
            }
        }
        $hSlack = null;
        if ($palletHeightMm !== null && $palletHeightMm > 0
            && $cell['cell_height_mm'] !== null && (int)$cell['cell_height_mm'] > 0) {
            $hSlack = (int)$cell['cell_height_mm'] - $palletHeightMm;
        }
        return ['ok' => true, 'error' => null,
            'sec_busy' => $secBusy, 'sec_free' => $secFree,
            'lvl_busy' => $lvlBusy, 'lvl_cap' => $cap, 'h_slack' => $hSlack, 'sec_cap_ok' => true];
    }

    public static function sameCellCode(string $a, string $b): bool
    {
        $n = static fn(string $s): string => function_exists('mb_strtoupper')
            ? mb_strtoupper(trim($s), 'UTF-8') : strtoupper(trim($s));
        return $n($a) !== '' && $n($a) === $n($b);
    }

    /**
     * Синонимы кодов зон: справочник storage_zones и значения в ячейках
     * исторически различаются (ship/shipment, accept/receiving).
     * Ключ — нормализованный код, значение — все эквивалентные коды.
     * @return array<string,string[]>
     */
    public static function zoneAliases(): array
    {
        return [
            'storage' => ['storage'],
            'ship' => ['ship', 'shipment'],
            'shipment' => ['ship', 'shipment'],
            'accept' => ['accept', 'receiving'],
            'receiving' => ['accept', 'receiving'],
            'quarantine' => ['quarantine'],
            'хранение' => ['storage'],
            'отгрузка' => ['ship', 'shipment'],
            'приемка' => ['accept', 'receiving'],
            'приёмка' => ['accept', 'receiving'],
            'карантин' => ['quarantine'],
        ];
    }

    /** Нормализовать код зоны (регистр и пробелы). */
    public static function normalizeZone(string $z): string
    {
        $s = function_exists('mb_strtolower')
            ? mb_strtolower(trim($z), 'UTF-8') : strtolower(trim($z));
        return $s;
    }

    /**
     * Все эквивалентные коды зоны — чтобы фильтр по зоне клиента находил
     * ячейки и по «ship», и по «shipment» (и наоборот).
     * @return string[]
     */
    public static function zoneVariants(string $z): array
    {
        $n = self::normalizeZone($z);
        if ($n === '') {
            return [];
        }
        $al = self::zoneAliases();
        return $al[$n] ?? [$n];
    }

    /**
     * Настройки размещения клиента: зоны (коды) и «свои» ячейки.
     * Пустые массивы = клиент ничего не задал (общие правила размещения).
     * @return array{id:?int,name:string,zones:string[],cells:string[]}
     */
    public static function clientContext(PDO $pdo, ?int $contractorId): array
    {
        $out = ['id' => $contractorId, 'name' => '', 'zones' => [], 'cells' => []];
        if (!$contractorId) {
            return $out;
        }
        try {
            require_once __DIR__ . '/../shared/Counterparties.php';
            $cp = WmsCounterparties::find($pdo, $contractorId);
            if (!$cp) {
                return $out;
            }
            $out['name'] = trim((string)($cp['name'] ?? ''));
            $out['zones'] = array_values(array_filter(array_map(
                static fn($z) => self::normalizeZone((string)$z),
                WmsCounterparties::placementZones($pdo, $contractorId)
            ), static fn($z) => $z !== ''));
            $out['cells'] = array_values(array_filter(array_map(
                static fn($c) => trim((string)$c),
                WmsCounterparties::placementCellCodes($pdo, $contractorId)
            ), static fn($c) => $c !== ''));
        } catch (Throwable) {
        }
        return $out;
    }

    /**
     * Карта SKU => контрагент (products.contractor_id).
     * Артикул принадлежит контрагенту — по нему определяем «свой» товар.
     * SKU ищутся в БД номенклатуры (в PostgreSQL это одна БД, в SQLite-демо — свой файл).
     * @param string[] $skus
     * @return array<string,?int>
     */
    public static function skuContractors(array $skus): array
    {
        $skus = self::skuList($skus);
        if (!$skus) {
            return [];
        }
        $out = [];
        try {
            require_once __DIR__ . '/../shared/db.php';
            $ndb = self::$nomenclaturePdo ?? wms_nomenclature_pdo();
            if (!wms_has_column($ndb, 'products', 'contractor_id')) {
                return [];
            }
            foreach (array_chunk($skus, 300) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $st = $ndb->prepare("SELECT sku, contractor_id FROM products WHERE sku IN ($in)");
                $st->execute($chunk);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sku = trim((string)($r['sku'] ?? ''));
                    if ($sku === '') {
                        continue;
                    }
                    $cid = $r['contractor_id'] ?? null;
                    $out[$sku] = ($cid === null || $cid === '') ? null : (int)$cid;
                }
            }
        } catch (Throwable) {
        }
        return $out;
    }

    /**
     * Подобрать свободную ячейку онлайн.
     *
     * $sku — SKU, которые поедут в ячейку (правило «разные товары в одной ячейке»).
     * $contractorId — клиент (контрагент), которому размещаем товар:
     *   • если у клиента заданы зоны размещения (counterparties.placement_zones) —
     *     подбор идёт ТОЛЬКО по этим зонам;
     *   • если у клиента заданы конкретные ячейки (placement_cell_codes) —
     *     они идут первыми (даже если лежат в другой зоне);
     *   • товар стараемся положить ближе к товару ЭТОГО ЖЕ клиента.
     *   Клиент без зон и без своих ячеек → общие правила (зона storage), как раньше.
     *
     * section: busy — занятые ячейки секции, free — свободные места секции
     * (включая нашу ячейку), free_other — свободные места кроме нашей ячейки,
     * full — кроме нашей ячейки свободных мест нет (секция станет полной),
     * incomplete — !full.
     * @return array{cell: array, section: array, placement: array}|null
     */
    public static function suggest(PDO $pdo, string $palletType, ?int $palletHeightMm = null,
        ?int $warehouseId = null, string|array|null $sku = null, ?int $contractorId = null,
        string $quality = 'новый', array $excludeCodes = [], bool $allowFallback = false): ?array
    {
        $palletType = self::normalizePalletType($palletType);

        // --- Настройки робота размещения ---
        $robot = self::robotSettings($pdo);

        // --- Контекст клиента: зоны размещения и «свои» ячейки ---
        $ctx = self::clientContext($pdo, $contractorId);
        $clientZones = $ctx['zones'];
        $clientCells = $ctx['cells'];
        // Зоны из справочника, у которых включено «Влияет на размещение».
        // Если зона привязана к клиенту — приоритет только для его товара.
        $priorityZones = [];
        try {
            require_once __DIR__ . '/../shared/StorageZones.php';
            $priorityZones = wms_storage_zone_priority($pdo, $contractorId, 'placement');
        } catch (Throwable) {
        }
        $ctx['priority_zones'] = $priorityZones;
        // Клиентский контекст задан — включаем клиентские приоритеты.
        $clientMode = (bool)$clientZones || (bool)$clientCells || (bool)$priorityZones;

        $sql = "SELECT * FROM storage_cells WHERE is_archived = 0 AND is_locked = 0
                AND status IN ('free') AND (qty IS NULL OR qty <= 0)
                AND (cell_type IS NULL OR cell_type = 'pallet')";
        // Ячейки с флагом «Только новый товар» не предлагаются для брака.
        if ($quality === 'Брак') {
            $sql .= " AND COALESCE(quality_lock, 0) = 0";
        }
        $params = [];
        if ($allowFallback) {
            // Аварийный подбор: клиентская зона заполнена (например, strim).
            // Разрешаем другие клиентские зоны и обычное хранение. Зоны приёмки,
            // отгрузки и сортировки в подбор не попадают.
            $fb = [];
            foreach (array_keys($priorityZones) as $z) {
                if (strtolower(trim((string)$z)) === 'storage') {
                    continue;
                }
                foreach (self::zoneVariants((string)$z) as $v) {
                    $fb[$v] = true;
                }
            }
            try {
                $zq = $pdo->prepare(
                    "SELECT z.code FROM storage_zone_clients c
                       JOIN storage_zones z ON z.id = c.zone_id
                      WHERE c.contractor_id = :c AND COALESCE(z.is_active,1) = 1
                        AND LOWER(z.code) NOT IN ('storage','receiving','quarantine','shipment','sorting','сортировка')"
                );
                $zq->execute([':c' => (int)$contractorId]);
                foreach ($zq->fetchAll(PDO::FETCH_COLUMN) as $z) {
                    foreach (self::zoneVariants((string)$z) as $v) {
                        $fb[$v] = true;
                    }
                }
            } catch (Throwable) {
            }
            // Если у клиента есть другие зоны — уходим именно в них.
            // Общее хранение подключаем только когда других клиентских зон нет.
            $zoneCodes = $fb ? array_values(array_unique(array_keys($fb))) : ['storage'];
            $ph = [];
            foreach ($zoneCodes as $i => $z) {
                $k = ':fb' . $i;
                $ph[] = $k;
                $params[$k] = $z;
            }
            $sql .= ' AND LOWER(zone) IN (' . implode(',', $ph) . ')';
        } elseif ($clientZones) {
            // Только зоны клиента. Его конкретные ячейки добавляем в выборку
            // отдельным условием — они «свои» даже при другой зоне.
            $zoneCodes = [];
            foreach ($clientZones as $z) {
                foreach (self::zoneVariants($z) as $v) {
                    $zoneCodes[$v] = true;
                }
            }
            foreach ($priorityZones as $z => $_) {
                foreach (self::zoneVariants($z) as $v) {
                    $zoneCodes[$v] = true;
                }
            }
            $zoneCodes = array_keys($zoneCodes);
            $ph = [];
            foreach ($zoneCodes as $i => $z) {
                $k = ':cz' . $i;
                $ph[] = $k;
                $params[$k] = $z;
            }
            $sql .= ' AND (LOWER(zone) IN (' . implode(',', $ph) . ')';
            if ($clientCells) {
                $cp = [];
                foreach ($clientCells as $i => $c) {
                    $k = ':cc' . $i;
                    $cp[] = $k;
                    $params[$k] = strtoupper($c);
                }
                $sql .= ' OR UPPER(code) IN (' . implode(',', $cp) . ')';
            }
            $sql .= ')';
        } elseif ($clientCells || $priorityZones) {
            // Свои ячейки и/или приоритетные зоны размещения + обычная зона.
            $zoneCodes = [];
            foreach ($priorityZones as $z => $_) {
                foreach (self::zoneVariants($z) as $v) {
                    $zoneCodes[$v] = true;
                }
            }
            $zoneCodes = array_keys($zoneCodes);
            $parts = ["zone = 'storage'"];
            if ($zoneCodes) {
                $ph = [];
                foreach ($zoneCodes as $i => $z) {
                    $k = ':pz' . $i;
                    $ph[] = $k;
                    $params[$k] = $z;
                }
                $parts[] = 'LOWER(zone) IN (' . implode(',', $ph) . ')';
            }
            if ($clientCells) {
                $cp = [];
                foreach ($clientCells as $i => $c) {
                    $k = ':cc' . $i;
                    $cp[] = $k;
                    $params[$k] = strtoupper($c);
                }
                $parts[] = 'UPPER(code) IN (' . implode(',', $cp) . ')';
            }
            $sql .= ' AND (' . implode(' OR ', $parts) . ')';
        } else {
            // Общие правила: только зона хранения.
            $sql .= " AND zone = 'storage'";
        }
        if ($warehouseId) {
            $sql .= " AND warehouse_id = :w";
            $params[':w'] = $warehouseId;
        }
        $sql .= " ORDER BY code ASC LIMIT 2000";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $free = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$free) {
            return null;
        }

        // Снимок секций: все ячейки (для режима секции и занятости ярусов).
        $snap = self::snapshot($pdo, $warehouseId);
        $bySection = $snap['bySection'];
        $byLevel = $snap['byLevel'];
        $content = $snap['content'];

        $cands = [];
        foreach ($free as $i => $cell) {
            // Исключить уже предложенные/текущие ячейки (кнопка «другая ячейка»).
            if ($excludeCodes) {
                $skip = false;
                foreach ($excludeCodes as $ex) {
                    if (self::sameCellCode((string)$ex, (string)($cell['code'] ?? ''))) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
            }
            $cell['content_skus'] = $content[(int)($cell['id'] ?? 0)] ?? [];
            $chk = self::checkCell($cell, $palletType, $palletHeightMm, $bySection, $byLevel, null, $sku, $robot, $quality);
            if (!$chk['ok']) {
                continue;
            }
            $secKey = self::sectionKey($cell);
            // Свободные места секции без самой нашей ячейки: если они есть —
            // секция неполная (в ней ещё можно размещать), и её заполняем первой.
            $secFreeOther = max(0, (int)$chk['sec_free'] - 1);
            // Лимит секции: проверяем, не превысит ли размещение лимит паллет в секции.
            $secPalletTotal = 0;
            foreach ($bySection[$secKey] ?? [] as $s) {
                if (self::isOccupied($s)) {
                    $secPalletTotal += (int)($s['pallet_count'] ?? 0) > 0 ? (int)($s['pallet_count'] ?? 0) : 1;
                }
            }
            $secCap = $palletType === 'fin' ? ($robot['fin_cap_per_section'] ?? 3) : ($robot['euro_cap_per_section'] ?? 4);
            $cands[] = [
                'cell' => $cell,
                'sec_key' => $secKey,
                'sec_busy' => $chk['sec_busy'],
                'sec_free' => $chk['sec_free'],
                'sec_free_other' => $secFreeOther,
                'sec_pallet_total' => $secPalletTotal,
                'sec_cap' => $secCap,
                'sec_cap_ok' => ($secPalletTotal + 1) <= $secCap,
                // Секция полна = кроме нашей ячейки свободных мест нет.
                'section_full' => ($secFreeOther === 0),
                'lvl_busy' => $chk['lvl_busy'],
                'lvl_cap' => $chk['lvl_cap'],
                'h_slack' => $chk['h_slack'],
                // 0 — ячейка клиента (placement_cell_codes), 1 — обычная ячейка.
                'client_cell' => 1,
                // 0 — зона с приоритетом размещения, 1 — обычная.
                'zone_priority' => isset($priorityZones[strtolower(trim((string)($cell['zone'] ?? '')))]) ? 0 : 1,
                // Близость к клиенту: 0 — товар клиента в ячейке, 1 — в секции,
                // 2 — пусто, 3 — в секции чужой товар.
                'proximity' => 2,
            ];
        }

        if (!$cands) {
            return null;
        }

        // --- Клиентские приоритеты: «ячейка клиента» и «ближе к клиенту» ---
        if ($clientMode && $contractorId) {
            $upper = array_map(static fn($c) => strtoupper($c), $clientCells);
            foreach ($cands as $i => $c) {
                if ($upper && in_array(strtoupper((string)$c['cell']['code']), $upper, true)) {
                    $cands[$i]['client_cell'] = 0;
                }
            }
            // SKU, которые уже лежат в секциях кандидатов — по ним понимаем,
            // чей товар в ячейке/секции (SKU -> products.contractor_id).
            $secKeys = [];
            $skus = [];
            foreach ($cands as $c) {
                $secKeys[$c['sec_key']] = true;
                foreach ((array)($c['cell']['content_skus'] ?? []) as $s) {
                    $skus[$s] = true;
                }
            }
            foreach ($secKeys as $k => $_) {
                foreach ((array)($bySection[$k] ?? []) as $sc) {
                    foreach ((array)($sc['content_skus'] ?? []) as $s) {
                        $skus[$s] = true;
                    }
                }
            }
            $owner = self::skuContractors(array_keys($skus));
            // Есть ли в секции товар клиента / чужой товар.
            $secOwn = [];
            $secOther = [];
            foreach ($secKeys as $k => $_) {
                foreach ((array)($bySection[$k] ?? []) as $sc) {
                    foreach ((array)($sc['content_skus'] ?? []) as $s) {
                        $cid = $owner[$s] ?? null;
                        if ($cid !== null && (int)$cid === (int)$contractorId) {
                            $secOwn[$k] = true;
                        } else {
                            $secOther[$k] = true;
                        }
                    }
                }
            }
            foreach ($cands as $i => $c) {
                $own = false;
                $other = false;
                foreach ((array)($c['cell']['content_skus'] ?? []) as $s) {
                    $cid = $owner[$s] ?? null;
                    if ($cid !== null && (int)$cid === (int)$contractorId) {
                        $own = true;
                    } else {
                        $other = true;
                    }
                }
                if ($own) {
                    $cands[$i]['proximity'] = 0;
                } elseif ($secOwn[$c['sec_key']] ?? false) {
                    $cands[$i]['proximity'] = 1;
                } elseif ($other || ($secOther[$c['sec_key']] ?? false)) {
                    // В секции товар другого клиента — размещаемся дальше,
                    // чтобы не мешать чужому товару.
                    $cands[$i]['proximity'] = 3;
                } else {
                    $cands[$i]['proximity'] = 2;
                }
            }
        }

        usort($cands, [self::class, 'compareCandidates']);

        $top = $cands[0];
        $section = [
            'key' => self::sectionKey($top['cell']),
            'busy' => $top['sec_busy'],
            'free' => $top['sec_free'],
            'level_busy' => $top['lvl_busy'],
            'level_cap' => $top['lvl_cap'],
            'beam' => self::normalizeBeam($top['cell']['beam_width'] ?? self::BEAM_32),
            // incomplete — в секции кроме нашей ячейки остаются свободные места.
            'incomplete' => !$top['section_full'],
            'full' => (bool)$top['section_full'],
            'free_other' => $top['sec_free_other'],
        ];
        $placement = [
            'contractor_id' => $contractorId,
            'contractor_name' => $ctx['name'],
            'zones' => $clientZones,
            'priority_zones' => array_keys($priorityZones),
            'client_cells' => $clientCells,
            'client_cell' => ($top['client_cell'] === 0),
            'own_goods' => ($top['proximity'] === 0 || $top['proximity'] === 1),
            'proximity' => $top['proximity'],
            'zone' => (string)($top['cell']['zone'] ?? ''),
            'reasons' => self::placementReasons($top, $ctx),
        ];
        // Аварийное размещение: ячейка вне заявленных зон клиента.
        if ($allowFallback && $clientZones) {
            $chosen = strtolower(trim((string)($top['cell']['zone'] ?? '')));
            $inRequested = false;
            foreach ($clientZones as $z) {
                foreach (self::zoneVariants((string)$z) as $v) {
                    if ($v === $chosen) {
                        $inRequested = true;
                        break 2;
                    }
                }
            }
            if (!$inRequested) {
                $zoneName = self::zoneDisplayName($pdo, (string)($top['cell']['zone'] ?? ''));
                $placement['fallback'] = true;
                $placement['requested_zones'] = $clientZones;
                $placement['zone_name'] = $zoneName;
                $placement['warning'] = 'Товар будет размещен в зоне «' . $zoneName . '»';
                $placement['reasons'][] = $placement['warning'];
            }
        }
        $placement['reason'] = implode('; ', $placement['reasons']);
        return [
            'cell' => $top['cell'],
            'section' => $section,
            'placement' => $placement,
            // Дублируем на верхнем уровне — пригодится UI и тестам.
            'section_full' => (bool)$top['section_full'],
            'section_free' => (int)$top['sec_free'],
            'section_incomplete' => !$top['section_full'],
        ];
    }

    /** Человекочитаемое название зоны по коду (для предупреждений на ТСД). */
    public static function zoneDisplayName(PDO $pdo, string $zoneCode): string
    {
        $code = trim($zoneCode);
        if ($code === '') {
            return 'хранение';
        }
        try {
            $st = $pdo->prepare('SELECT name FROM storage_zones WHERE LOWER(code)=LOWER(:c) LIMIT 1');
            $st->execute([':c' => $code]);
            $name = trim((string)($st->fetchColumn() ?: ''));
            if ($name !== '') {
                return $name;
            }
        } catch (Throwable) {
        }
        $map = ['storage' => 'Хранение', 'shipment' => 'Отгрузка', 'receiving' => 'Приёмка',
            'quarantine' => 'Карантин', 'sorting' => 'Сортировка', 'сортировка' => 'Сортировка'];
        return $map[strtolower($code)] ?? $code;
    }

    /**
     * Диагностика размещения: объясняет, почему паллета не подходит к ячейке.
     * Используется модулем 13 → «Роботы» → «Эмитация размещения».
     *
     * Проверки повторяют правила suggest()/checkCell(): клиентские зоны, занятость,
     * блокировки, высота, тара, смешанные товары, качество, секционные правила,
     * лимиты и ярус. Возвращает пошаговый отчёт и фактическое предложение робота.
     *
     * @return array{ok:bool,summary:string,pallet:array,cell:array,checks:array,
     *               blockers:array,suggested:?array,same_as_suggested:bool,robot:array}
     */
    public static function diagnose(PDO $sdb, string $palletCode, string $cellCode, ?int $warehouseId = null): array
    {
        require_once __DIR__ . '/../shared/db.php';
        $palletCode = trim($palletCode);
        $cellCode = trim($cellCode);
        $checks = [];
        $add = static function (string $key, string $label, bool $ok, string $detail) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $ndb = self::$nomenclaturePdo ?? (function_exists('wms_nomenclature_pdo') ? wms_nomenclature_pdo() : $sdb);

        // --- 1. Паллета: строки товарного запаса по SSCC ---
        $rows = [];
        if ($palletCode !== '') {
            try {
                $st = $ndb->prepare(
                    "SELECT s.*, p.sku, p.name, p.length_mm, p.width_mm, p.height_mm, p.weight_g,
                            p.pallet_type AS prod_pallet_type, p.pallet_height_mm, p.contractor_id, p.unit
                       FROM product_stock s LEFT JOIN products p ON p.id = s.product_id
                      WHERE TRIM(COALESCE(s.pallet, '')) = :p
                      ORDER BY s.id LIMIT 100"
                );
                $st->execute([':p' => $palletCode]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
            }
        }
        $skus = [];
        $qty = 0.0;
        $stockType = '';
        $prodType = '';
        $heightSnapshot = 0;
        $heightCard = 0;
        $weightKg = 0.0;
        $quality = 'новый';
        $contractorId = null;
        $palletCells = [];
        $name = '';
        foreach ($rows as $r) {
            if (trim((string)($r['sku'] ?? '')) !== '') {
                $skus[(string)$r['sku']] = true;
            }
            $qty += (float)($r['qty'] ?? 0);
            if (trim((string)($r['pallet_type'] ?? '')) !== '') {
                $stockType = self::normalizePalletType((string)$r['pallet_type']);
            }
            if (trim((string)($r['prod_pallet_type'] ?? '')) !== '') {
                $prodType = self::normalizePalletType((string)$r['prod_pallet_type']);
            }
            $heightSnapshot = max($heightSnapshot, (int)($r['vgh_height_mm'] ?? 0));
            $heightCard = max($heightCard, (int)($r['pallet_height_mm'] ?? 0));
            $w = (float)($r['vgh_weight_kg'] ?? 0);
            if ($w <= 0 && (float)($r['weight_g'] ?? 0) > 0) {
                $w = (float)$r['qty'] * (float)$r['weight_g'] / 1000.0 + 25;
            }
            $weightKg += $w;
            if ($contractorId === null && isset($r['contractor_id']) && $r['contractor_id'] !== null && $r['contractor_id'] !== '') {
                $contractorId = (int)$r['contractor_id'];
            }
            if ((string)($r['quality'] ?? '') === 'Брак') {
                $quality = 'Брак';
            }
            if ($name === '' && trim((string)($r['name'] ?? '')) !== '') {
                $name = (string)$r['name'];
            }
            $pc = trim((string)($r['cell'] ?? ''));
            if ($pc !== '') {
                $palletCells[$pc] = true;
            }
        }
        $skus = array_keys($skus);
        $ptype = $stockType !== '' ? $stockType : ($prodType !== '' ? $prodType : 'euro');
        $heightMm = null;
        $heightSource = 'не задана (проверка высоты пропускается)';
        if ($heightSnapshot > 0) {
            $heightMm = $heightSnapshot;
            $heightSource = 'снимок ВГХ (робот обновления ВГХ)';
        } elseif ($heightCard > 0) {
            $heightMm = $heightCard;
            $heightSource = 'карточка товара (pallet_height_mm)';
        }
        $tsdPallet = null;
        if (!$rows && $palletCode !== '') {
            try {
                $rdb = function_exists('wms_receipts_pdo') ? wms_receipts_pdo() : null;
                if ($rdb) {
                    $st = $rdb->prepare('SELECT sscc,status,qty_total,weight,created_at,receipt_id FROM tsd_pallets WHERE sscc=:s LIMIT 1');
                    $st->execute([':s' => $palletCode]);
                    $tsdPallet = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                }
            } catch (Throwable) {
            }
        }
        $client = self::clientContext($sdb, $contractorId);
        $add('pallet', 'Паллета найдена в товарном запасе', (bool)$rows,
            $rows
                ? ('SSCC ' . $palletCode . ' · SKU: ' . (implode(', ', $skus) ?: '—') . ' · ' . round($qty, 3) . ' шт'
                    . ($palletCells ? ' · ячейки: ' . implode(', ', array_keys($palletCells)) : ''))
                : ('SSCC ' . $palletCode . ' не найден в product_stock'
                    . ($tsdPallet ? ' (есть в приёмке, статус: ' . (string)($tsdPallet['status'] ?? '') . ')' : '')
                    . '. Размещение возможно только после приёмки.'));
        $pallet = [
            'code' => $palletCode,
            'found' => (bool)$rows,
            'sku' => implode(', ', $skus),
            'name' => $name,
            'qty' => round($qty, 3),
            'pallet_type' => $ptype,
            'pallet_type_label' => $ptype === 'fin' ? 'Фин' : 'Евро',
            'height_mm' => $heightMm,
            'height_source' => $heightSource,
            'weight_kg' => round($weightKg, 2),
            'quality' => $quality,
            'contractor_id' => $contractorId,
            'contractor_name' => (string)($client['name'] ?? ''),
            'cells' => array_keys($palletCells),
            'tsd' => $tsdPallet,
        ];

        // --- 2. Ячейка ---
        $cellRows = [];
        if ($cellCode !== '') {
            try {
                $sql = 'SELECT * FROM storage_cells WHERE UPPER(code)=UPPER(:c)';
                $cp = [':c' => $cellCode];
                if ($warehouseId) {
                    $sql .= ' AND warehouse_id=:w';
                    $cp[':w'] = $warehouseId;
                }
                $sql .= ' ORDER BY id LIMIT 5';
                $st = $sdb->prepare($sql);
                $st->execute($cp);
                $cellRows = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
            }
        }
        if (!$cellRows) {
            $add('cell', 'Ячейка существует', false, 'Ячейка ' . ($cellCode !== '' ? $cellCode : '(не указана)') . ' не найдена');
            $blockers = [];
            foreach ($checks as $c) {
                if (!$c['ok']) {
                    $blockers[] = $c['detail'];
                }
            }
            return [
                'ok' => false,
                'summary' => 'Не подходит: ' . ($blockers[0] ?? 'ячейка не найдена'),
                'pallet' => $pallet,
                'cell' => ['code' => $cellCode],
                'checks' => $checks,
                'blockers' => $blockers,
                'suggested' => null,
                'same_as_suggested' => false,
                'robot' => self::robotSettings($sdb),
            ];
        }
        $cell = $cellRows[0];
        $add('cell', 'Ячейка существует', true,
            'Код ' . (string)$cell['code'] . ' · склад ' . (string)($cell['warehouse_id'] ?? '')
            . ($cellRows[1] ?? null ? ' (найдено несколько ячеек с таким кодом — проверьте склад)' : ''));
        $add('archived', 'Ячейка не в архиве', (int)($cell['is_archived'] ?? 0) !== 1,
            (int)($cell['is_archived'] ?? 0) === 1 ? 'Ячейка в архиве — робот её не рассматривает' : 'OK');
        $ct = strtolower(trim((string)($cell['cell_type'] ?? '')));
        $add('cell_type', 'Ячейка паллетного типа', $ct === '' || $ct === 'pallet',
            $ct === '' || $ct === 'pallet' ? 'OK' : ('Тип ячейки: ' . $ct . ' — подбор идёт только по pallet'));

        $snap = self::snapshot($sdb, $warehouseId);
        $robot = self::robotSettings($sdb);
        $contentSkus = $snap['content'][(int)$cell['id']] ?? [];
        $secKey = self::sectionKey($cell);
        $lvlKey = self::levelKey($cell);
        $beam = self::normalizeBeam($cell['beam_width'] ?? self::BEAM_32);
        $cap = self::levelCapacity($beam, $ptype, $robot);

        // --- 3. Зона: клиентские зоны / storage ---
        $priorityZones = [];
        try {
            require_once __DIR__ . '/../shared/StorageZones.php';
            $priorityZones = wms_storage_zone_priority($sdb, $contractorId, 'placement');
        } catch (Throwable) {
        }
        $zone = self::normalizeZone((string)($cell['zone'] ?? ''));
        $zoneVariants = [];
        foreach ($client['zones'] as $z) {
            foreach (self::zoneVariants($z) as $v) {
                $zoneVariants[$v] = true;
            }
        }
        foreach (array_keys($priorityZones) as $z) {
            foreach (self::zoneVariants((string)$z) as $v) {
                $zoneVariants[$v] = true;
            }
        }
        $isClientCell = false;
        foreach ($client['cells'] as $cc) {
            if (self::sameCellCode((string)$cc, (string)$cell['code'])) {
                $isClientCell = true;
                break;
            }
        }
        if ($client['zones']) {
            $zoneOk = isset($zoneVariants[$zone]) || $isClientCell;
            $zoneDetail = $zoneOk
                ? ('Зона ячейки "' . $zone . '" входит в зоны клиента «' . (string)$client['name'] . '»')
                : ('Клиент «' . (string)$client['name'] . '» размещает только в зонах: '
                    . implode(', ', $client['zones']) . '. Зона ячейки — "' . $zone . '"'
                    . ($isClientCell ? ' (ячейка указана в «своих» ячейках клиента)' : '') . '.');
        } elseif ($priorityZones || $client['cells']) {
            $zoneOk = $zone === 'storage' || isset($zoneVariants[$zone]) || $isClientCell;
            $zoneDetail = $zoneOk
                ? ('Зона "' . $zone . '" разрешена')
                : ('Робот размещает в зоне storage и приоритетных зонах клиента; зона ячейки — "' . $zone . '".');
        } else {
            $zoneOk = $zone === 'storage';
            $zoneDetail = $zoneOk
                ? 'Зона хранения (storage) — OK'
                : ('Робот по общим правилам размещает только в зоне storage; зона ячейки — "' . $zone . '".');
        }
        $add('zone', 'Зона ячейки разрешена для размещения', $zoneOk, $zoneDetail);

        // --- 4. Занятость / блокировки ---
        $lockOk = (int)($cell['is_locked'] ?? 0) !== 1;
        $lockReason = trim((string)($cell['lock_reason'] ?? ''));
        $add('locked', 'Ячейка не заблокирована', $lockOk,
            $lockOk ? 'Блокировки нет' : ('Заблокирована' . ($lockReason !== '' ? ': ' . $lockReason : '')));
        $freeOk = (($cell['status'] ?? '') === 'free') && (float)($cell['qty'] ?? 0) <= 0;
        $add('free', 'Ячейка свободна', $freeOk,
            $freeOk ? 'Статус free, остаток 0' : ('Статус: ' . (string)($cell['status'] ?? '') . ', остаток: ' . (float)($cell['qty'] ?? 0)));

        // --- 5. Высота ---
        $ch = (int)($cell['cell_height_mm'] ?? 0);
        if ($heightMm !== null && $heightMm > 0 && $ch > 0) {
            $hOk = $ch >= $heightMm;
            $add('height', 'Высота паллеты проходит', $hOk,
                'Паллета ' . $heightMm . ' мм (' . $heightSource . '), ячейка ' . $ch . ' мм'
                . ($hOk ? ' — запас ' . ($ch - $heightMm) . ' мм' : ' — не хватает ' . ($heightMm - $ch) . ' мм'));
        } else {
            $add('height', 'Проверка высоты', true,
                $ch > 0
                    ? ('Высота паллеты не задана — проверка пропущена (ячейка ' . $ch . ' мм)')
                    : 'Высота ячейки не задана — проверка пропущена');
        }

        // --- 6. Тара ---
        $typeOk = self::cellAllowsType($cell, $ptype);
        $add('pallet_type', 'Тара разрешена в ячейке/секции', $typeOk,
            'Паллета: ' . ($ptype === 'fin' ? 'Фин' : 'Евро') . ', ячейка разрешает: '
            . (string)($cell['allowed_pallet_types'] ?? 'euro,fin'));

        // --- 7. Смешанные товары ---
        $mixed = self::mixedGoodsError($cell, (array)$contentSkus, $skus);
        $add('mixed', 'Правило «разные товары в одной ячейке»', $mixed === null,
            $mixed ?? ('В ячейке уже лежит: ' . (implode(', ', (array)$contentSkus) ?: 'ничего') . ' — совместимо'));

        // --- 8. Качество ---
        $qOk = !((int)($cell['quality_lock'] ?? 0) === 1 && $quality === 'Брак');
        $add('quality', 'Качество товара допускается', $qOk,
            $qOk ? ('Паллета: ' . $quality) : 'В ячейке запрещён брак (флаг «Только новый товар»), паллета помечена «Брак»');

        // --- 9. Управление тарой (флаг «разные типы паллет») ---
        $cellMixed = (int)($cell['allow_multi_pallet_types'] ?? 0) === 1;
        $add('section_multi', 'Режим управления тарой', true,
            $cellMixed
                ? 'В ячейке разрешены разные типы паллет — робот не управляет тарой для этой ячейки (можно ставить любой тип)'
                : 'Разные типы отключены — робот закрепляет тип тары за секцией (первый размещённый тип)');

        // --- 10. Секционная блокировка типа (робот) ---
        $secHasManaged = false;
        $secMode = null;
        foreach ($snap['bySection'][$secKey] ?? [] as $s) {
            if (!self::isOccupied($s)) {
                continue;
            }
            // Ячейки с флагом «разные типы» в управлении тарой секции не участвуют.
            if ((int)($s['allow_multi_pallet_types'] ?? 0) === 1) {
                continue;
            }
            $secHasManaged = true;
            if (trim((string)($s['pallet_type'] ?? '')) === '') {
                continue;
            }
            $t = self::normalizePalletType((string)$s['pallet_type']);
            if ($t === 'euro') {
                $secMode = 'euro';
                break;
            }
            if ($t === 'fin') {
                $secMode = $secMode ?? 'fin';
            }
        }
        $secTypeOk = $cellMixed || !($robot['section_pallet_lock'] === 1 && $secHasManaged && $secMode !== null && $secMode !== $ptype);
        $add('section_type', 'Тип тары секции соответствует паллете', $secTypeOk,
            $cellMixed
                ? 'Не применяется: в ячейке разрешены разные типы паллет'
                : (!$secTypeOk
                    ? ('Секция занята тарой «' . ($secMode === 'euro' ? 'Евро' : 'Фин') . '» — «'
                        . ($ptype === 'fin' ? 'Фин' : 'Евро') . '» ставить нельзя')
                    : ($secHasManaged ? ('Тип секции: ' . ($secMode === 'euro' ? 'Евро' : ($secMode === 'fin' ? 'Фин' : 'не определён')))
                        : 'Секция пуста — тип закрепится за первой паллетой')));

        // --- 11. Лимит паллет в секции ---
        $secPalletTotal = 0;
        foreach ($snap['bySection'][$secKey] ?? [] as $s) {
            if (self::isOccupied($s)) {
                $secPalletTotal += max(1, (int)($s['pallet_count'] ?? 0));
            }
        }
        $secCap = $ptype === 'fin' ? (int)($robot['fin_cap_per_section'] ?? 3) : (int)($robot['euro_cap_per_section'] ?? 4);
        $secCapOk = ($secPalletTotal + 1) <= $secCap;
        $add('section_cap', 'Лимит паллет в секции', $secCapOk,
            'Занято ' . $secPalletTotal . ' из ' . $secCap . ' (' . ($ptype === 'fin' ? 'фин' : 'евро') . ')'
            . ($secCapOk ? '' : ' — размещение превысит лимит'));

        // --- 12. 3-я фин-ячейка на балке 3,6 ---
        $finBlockOk = true;
        $finBlockDetail = 'Правило не применяется (не фин или балка 3,2 м)';
        if (!$cellMixed && $robot['fin_block_3rd_cell'] === 1 && $beam > 3.4 && $ptype === 'fin') {
            $blockPlace = (int)($robot['beam36_fin_block_place'] ?? 3);
            $placeNo = (int)($cell['place_no'] ?? 0);
            $finBlockOk = $placeNo !== $blockPlace;
            $finBlockDetail = $finBlockOk
                ? ('Балка 3,6 м, место ' . $placeNo . ' не блокируется (заблокировано место ' . $blockPlace . ')')
                : ('Балка 3,6 м: место ' . $blockPlace . ' яруса заблокировано для фин-паллет');
        }
        $add('fin_block', 'Ячейка не заблокирована под фин (балка 3,6)', $finBlockOk, $finBlockDetail);

        // --- 13. Ярус ---
        $lvlBusy = 0;
        foreach ($snap['byLevel'][$lvlKey] ?? [] as $s) {
            if (self::isOccupied($s) || self::isTempBlocked($s)) {
                $lvlBusy++;
            }
        }
        $lvlOk = $lvlBusy < $cap;
        $add('level', 'На ярусе есть свободное место', $lvlOk,
            'Ярус: ' . $lvlBusy . ' из ' . $cap . ' мест занято (балка '
            . ($beam <= 3.4 ? '3,2' : '3,6') . ' м, ' . ($ptype === 'fin' ? 'фин' : 'евро') . ')'
            . ($lvlOk ? '' : ' — ярус полон'));

        // --- 14. Итоговая проверка checkCell ---
        $verdict = self::checkCell($cell, $ptype, $heightMm, $snap['bySection'], $snap['byLevel'],
            null, $skus, $robot, $quality);
        $add('verdict', 'Итоговая проверка робота (checkCell)', $verdict['ok'],
            $verdict['ok'] ? 'Все правила выполнены' : (string)($verdict['error'] ?? 'не подходит'));

        // --- 15. Что предлагает робот ---
        $sug = null;
        try {
            $sug = self::suggest($sdb, $ptype, $heightMm, $warehouseId, $skus, $contractorId, $quality);
        } catch (Throwable) {
        }
        $suggested = null;
        if ($sug) {
            $reasons = (array)($sug['placement']['reasons'] ?? []);
            $suggested = [
                'cell' => (string)($sug['cell']['code'] ?? ''),
                'reasons' => $reasons,
                'reason' => (string)($sug['placement']['reason'] ?? implode('; ', $reasons)),
            ];
        }
        $same = $suggested ? self::sameCellCode($cell['code'], $suggested['cell']) : false;

        $blockers = [];
        foreach ($checks as $c) {
            if (!$c['ok']) {
                $blockers[] = $c['detail'];
            }
        }
        $ok = !$blockers;
        if ($ok && $suggested && !$same) {
            $add('priority', 'Приоритет робота', true,
                'Ячейка подходит, но робот предлагает ' . $suggested['cell'] . ' по приоритету: '
                . ($suggested['reason'] !== '' ? $suggested['reason'] : 'свободная ячейка'));
        } elseif ($ok && !$suggested) {
            $add('priority', 'Приоритет робота', true,
                'Ячейка подходит, но сейчас у робота нет свободных кандидатов (проверьте занятость и зоны).');
        }
        $summary = $ok
            ? ('Ячейка ' . (string)$cell['code'] . ' подходит для паллеты ' . $palletCode)
            : ('Не подходит: ' . implode('; ', array_slice($blockers, 0, 3))
                . (count($blockers) > 3 ? ' …' : ''));

        return [
            'ok' => $ok,
            'summary' => $summary,
            'pallet' => $pallet,
            'cell' => [
                'code' => (string)$cell['code'],
                'warehouse_id' => (int)($cell['warehouse_id'] ?? 0),
                'zone' => (string)($cell['zone'] ?? ''),
                'status' => (string)($cell['status'] ?? ''),
                'is_locked' => (int)($cell['is_locked'] ?? 0),
                'lock_reason' => (string)($cell['lock_reason'] ?? ''),
                'cell_height_mm' => (int)($cell['cell_height_mm'] ?? 0),
                'allowed_pallet_types' => (string)($cell['allowed_pallet_types'] ?? ''),
                'beam_width' => $beam,
                'place_no' => (int)($cell['place_no'] ?? 0),
                'level_no' => (int)($cell['level_no'] ?? 0),
                'allow_multi_pallet_types' => (int)($cell['allow_multi_pallet_types'] ?? 0),
                'quality_lock' => (int)($cell['quality_lock'] ?? 0),
                'allow_mixed_goods' => (int)($cell['allow_mixed_goods'] ?? 1),
                'section' => $secKey,
                'level' => $lvlKey,
            ],
            'checks' => $checks,
            'blockers' => $blockers,
            'suggested' => $suggested,
            'same_as_suggested' => $same,
            'robot' => $robot,
        ];
    }

    /**
     * Подобрать ячейку для конкретного клиента (зоны и «свои» ячейки клиента).
     * @return array{cell: array, section: array, placement: array}|null
     */
    public static function suggestForClient(PDO $pdo, int $contractorId, string $palletType,
        ?int $palletHeightMm = null, ?int $warehouseId = null, string|array|null $sku = null): ?array
    {
        return self::suggest($pdo, $palletType, $palletHeightMm, $warehouseId, $sku, $contractorId);
    }

    /**
     * Явная функция приоритета подбора.
     *
     * Порядок (по убыванию важности) и ПОЧЕМУ он такой:
     *   1. Ячейка клиента (placement_cell_codes) — клиент указал конкретные
     *      адреса: попадаем точно в них, даже если они в другой зоне.
     *   2. Неполные секции — секции, где товар уже лежит и в секции есть
     *      свободные паллетные места (ячейки не заблокированы). Такие секции
     *      заполняем первыми: товар не расползается по новым секциям.
     *      Пустая секция (в ней ничего не лежит) — в последнюю очередь.
     *   3. Близость к клиенту — ячейка, где уже лежит товар ЭТОГО клиента
     *      (0), или его секция (1), затем пустая ячейка (2), и только потом
     *      ячейка рядом с товаром другого клиента (3): «как можно ближе
     *      к этому же клиенту» и не мешаем чужому товару.
     *   4. Прежние правила: самая заполненная секция, самый заполненный ярус,
     *      минимальная остаточная высота, код ячейки.
     */
    public static function compareCandidates(array $a, array $b): int
    {
        // 1. Ячейка клиента.
        if ($a['client_cell'] !== $b['client_cell']) {
            return $a['client_cell'] <=> $b['client_cell'];
        }
        // 1б. Приоритетная зона размещения (0 — да, 1 — обычная).
        $az = (int)($a['zone_priority'] ?? 1);
        $bz = (int)($b['zone_priority'] ?? 1);
        if ($az !== $bz) {
            return $az <=> $bz;
        }
        // 2. Неполная секция (0 — неполная/непустая, 1 — пустая).
        $ar = self::sectionRank($a);
        $br = self::sectionRank($b);
        if ($ar !== $br) {
            return $ar <=> $br;
        }
        // 3. Близость к клиенту.
        if ($a['proximity'] !== $b['proximity']) {
            return $a['proximity'] <=> $b['proximity'];
        }
        // 4. Прежние правила.
        if ($a['sec_busy'] !== $b['sec_busy']) {
            return $b['sec_busy'] <=> $a['sec_busy'];
        }
        if ($a['lvl_busy'] !== $b['lvl_busy']) {
            return $b['lvl_busy'] <=> $a['lvl_busy'];
        }
        $as = $a['h_slack'] ?? PHP_INT_MAX;
        $bs = $b['h_slack'] ?? PHP_INT_MAX;
        if ($as !== $bs) {
            return $as <=> $bs;
        }
        return strcmp((string)$a['cell']['code'], (string)$b['cell']['code']);
    }

    /**
     * Группа секции для приоритета:
     *   0 — неполная секция: товар в секции уже лежит и в ней есть свободные
     *       паллетные места (в т.ч. наша ячейка) — такие секции заполняем первыми;
     *   1 — пустая секция: товара в секции нет, открываем её в последнюю очередь.
     * Ячейка клиента (placement_cell_codes) приоритетнее обеих групп.
     */
    private static function sectionRank(array $c): int
    {
        return ((int)($c['sec_busy'] ?? 0) > 0) ? 0 : 1;
    }

    /**
     * Почему выбрана именно эта ячейка — текст для оператора (ТСД/веб).
     * @return string[]
     */
    public static function placementReasons(array $c, array $ctx): array
    {
        $out = [];
        $name = trim((string)($ctx['name'] ?? ''));
        if (($c['client_cell'] ?? 1) === 0) {
            $out[] = 'Ячейка клиента' . ($name !== '' ? ' ' . $name : '') . ' — адрес задан в настройках клиента';
        }
        if (($c['zone_priority'] ?? 1) === 0) {
            $pz = (array)($ctx['priority_zones'] ?? []);
            $code = strtolower(trim((string)($c['cell']['zone'] ?? '')));
            $zn = (string)($pz[$code] ?? $code);
            $out[] = 'Приоритетная зона размещения: ' . ($zn !== '' ? $zn : $code);
        }
        $free = (int)($c['sec_free'] ?? 0);
        if ((int)($c['sec_busy'] ?? 0) > 0) {
            if (empty($c['section_full'])) {
                $out[] = 'Секция неполная (свободно ' . $free . ' ' . self::placesWord($free) . ')';
            } else {
                $out[] = 'Секция неполная (свободно ' . $free . ' ' . self::placesWord($free)
                    . ') — после размещения секция заполнится полностью';
            }
        } else {
            $out[] = 'Новая (пустая) секция — добиваем секции, где товар уже лежит';
        }
        $prox = (int)($c['proximity'] ?? 2);
        if ($prox === 0) {
            $out[] = 'В ячейке уже лежит товар клиента' . ($name !== '' ? ' ' . $name : '');
        } elseif ($prox === 1) {
            $out[] = 'Рядом товар клиента' . ($name !== '' ? ' ' . $name : '') . ' — та же секция';
        } elseif ($prox === 3) {
            $out[] = 'В секции лежит товар другого клиента';
        }
        if (!empty($ctx['zones'])) {
            $out[] = 'Зона размещения клиента: ' . implode(', ', $ctx['zones']);
        }
        return $out;
    }

    /** Склонение: 1 место / 2 места / 5 мест. */
    private static function placesWord(int $n): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;
        if ($n > 10 && $n < 20) {
            return 'мест';
        }
        if ($n1 === 1) {
            return 'место';
        }
        if ($n1 >= 2 && $n1 <= 4) {
            return 'места';
        }
        return 'мест';
    }

    /**
     * Занять подобранную ячейку: резерв + простановка типа паллеты.
     * Для 3.6 + фин при достижении 3 занятых на ярусе — блокирует следующую
     * свободную ячейку яруса (по порядку place_no/code) с причиной "(Фин секция)".
     * Для 3.2 первое евро в пустой секции переводит свободные ячейки секции на euro.
     * $qty / $weightKg — размер груза: проверяются вместимость (шт) и предельный
     * вес ячейки. Раньше эти ограничения проверялись только при ПЕРЕМЕЩЕНИИ
     * (CellMoveCheck), а при РАЗМЕЩЕНИИ товар можно было положить в ячейку
     * сверх её вместимости и веса.
     * $sku — SKU груза (правило «разные товары в одной ячейке»).
     * @return array{cell: array, temp_blocked: ?array, section_tuned: bool}
     */
    public static function occupy(PDO $pdo, int $cellId, string $palletType, ?string $sscc = null,
        int $userId = 1, string $userFio = 'ТСД', float $qty = 0.0, ?float $weightKg = null,
        string|array|null $sku = null, string $quality = 'новый', bool $logJournal = true): array
    {
        $palletType = self::normalizePalletType($palletType);
        $st = $pdo->prepare("SELECT * FROM storage_cells WHERE id = :id");
        $st->execute([':id' => $cellId]);
        $cell = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cell) {
            throw new RuntimeException('Ячейка не найдена');
        }
        // Правило «разные товары в одной ячейке» — проверяем до занятости,
        // чтобы оператор увидел точную причину отказа.
        $mixed = self::mixedGoodsError($cell, self::cellContentSkus($pdo, $cellId), self::skuList($sku));
        if ($mixed !== null) {
            throw new RuntimeException($mixed);
        }
        // Качество: ячейка с флагом «Только новый товар» не принимает брак.
        if ((int)($cell['quality_lock'] ?? 0) === 1 && $quality === 'Брак') {
            throw new RuntimeException('В ячейку ' . ($cell['code'] ?? $cellId)
                . ' запрещено размещать брак: установлен флаг «Только новый товар»');
        }
        if ((int)($cell['is_locked'] ?? 0) === 1) {
            throw new RuntimeException('Ячейка заблокирована: ' . ($cell['code'] ?? $cellId));
        }
        if ((float)($cell['qty'] ?? 0) > 0 || ($cell['status'] ?? '') !== 'free') {
            throw new RuntimeException('Ячейка уже занята: ' . ($cell['code'] ?? $cellId));
        }
        // Вместимость (шт) и предельный вес — как в CellMoveCheck.
        $cap = (float)($cell['capacity'] ?? 0);
        if ($cap > 0 && $qty > 0 && $qty - $cap > 1e-9) {
            throw new RuntimeException('Не хватает места по вместимости: в ячейке '
                . ($cell['code'] ?? $cellId) . ' ' . $cap . ' шт, а на паллете ' . $qty . ' шт');
        }
        $maxW = $cell['max_weight_kg'];
        if ($maxW !== null && $maxW !== '' && (float)$maxW > 0
            && $weightKg !== null && $weightKg > 0 && $weightKg - (float)$maxW > 1e-9) {
            throw new RuntimeException('Превышен макс. вес ячейки ' . ($cell['code'] ?? $cellId)
                . ': паллета ' . $weightKg . ' кг > лимита ' . (float)$maxW . ' кг');
        }
        // Предельное число паллет в ячейке.
        $maxPal = $cell['max_pallets'];
        if ($maxPal !== null && $maxPal !== '' && (int)$maxPal > 0
            && (int)($cell['pallet_count'] ?? 0) + 1 > (int)$maxPal) {
            throw new RuntimeException('Не хватает места по паллетам: в ячейке '
                . ($cell['code'] ?? $cellId) . ' занято ' . (int)($cell['pallet_count'] ?? 0)
                . ' из ' . (int)$maxPal);
        }

        $beam = self::normalizeBeam($cell['beam_width'] ?? self::BEAM_32);
        // Настройки робота размещения.
        $robot = self::robotSettings($pdo);
        $wasTx = $pdo->inTransaction();
        if (!$wasTx) {
            $pdo->beginTransaction();
        }
        try {
            // --- Лимит секции по количеству паллет (робот) ---
            $secKey = self::sectionKey($cell);
            $managed = (int)($cell['allow_multi_pallet_types'] ?? 0) === 0;
            $secPalletTotal = 0;
            $secHasOccupied = false;
            $secHasManaged = false;
            $secMode = null;
            $stSec = $pdo->prepare(
                "SELECT id, pallet_count, pallet_type, qty, status FROM storage_cells
                 WHERE warehouse_id = :w AND building = :b AND row_no = :s
                   AND is_archived = 0 AND id != :id"
            );
            $stSec->execute([
                ':w' => $cell['warehouse_id'], ':b' => $cell['building'], ':s' => $cell['row_no'], ':id' => $cellId
            ]);
            foreach ($stSec->fetchAll(PDO::FETCH_ASSOC) as $sc) {
                if (self::isOccupied($sc)) {
                    $secHasOccupied = true;
                    $secPalletTotal += (int)($sc['pallet_count'] ?? 0) > 0 ? (int)($sc['pallet_count'] ?? 0) : 1;
                    // Занятость для управления секцией: только ячейки без флага «разные типы».
                    if ((int)($sc['allow_multi_pallet_types'] ?? 0) === 0) {
                        $secHasManaged = true;
                        $ptSec = trim((string)($sc['pallet_type'] ?? ''));
                        if ($ptSec !== '') {
                            $tSec = self::normalizePalletType($ptSec);
                            if ($tSec === 'euro') {
                                $secMode = 'euro';
                            } elseif ($tSec === 'fin') {
                                $secMode = $secMode ?? 'fin';
                            }
                        }
                    }
                }
            }
            // Тип секции уже закреплён другой управляемой паллетой — несовместимую тару не принимаем.
            if ($managed && $secMode !== null && $secMode !== $palletType) {
                throw new RuntimeException('Секция настроена под ' . ($secMode === 'euro' ? 'евро' : 'фин')
                    . ': ' . ($palletType === 'fin' ? 'фин' : 'евро') . ' ставить нельзя');
            }
            $secCap = $palletType === 'fin' ? ($robot['fin_cap_per_section'] ?? 3) : ($robot['euro_cap_per_section'] ?? 4);
            if (($secPalletTotal + 1) > $secCap) {
                throw new RuntimeException('Секция заполнена по лимиту ' . $palletType . ': '
                    . $secPalletTotal . '/' . $secCap . ' паллет (секция ' . $secKey . ')');
            }

            // --- Секционная блокировка типа паллеты (робот) ---
            // Размещённая паллета закрепляет тип за секцией: свободные управляемые
            // ячейки (без флага «разные типы») получают только этот тип — вторая тара
            // (например, фин при евро) отмечается крестом и блокируется к размещению.
            // Работает и когда секция уже частично занята управляемой паллетой.
            $tuned = false;
            if ($managed && $robot['section_pallet_lock'] === 1) {
                $pdo->prepare(
                    "UPDATE storage_cells SET allowed_pallet_types = :pt
                     WHERE warehouse_id = :w AND building = :b AND row_no = :s
                       AND is_archived = 0 AND is_locked = 0 AND status = 'free'
                       AND (qty IS NULL OR qty <= 0)
                       AND COALESCE(allow_multi_pallet_types, 0) = 0"
                )->execute([':pt' => $palletType, ':w' => $cell['warehouse_id'],
                    ':b' => $cell['building'], ':s' => $cell['row_no']]);
                $tuned = true;
            }

            $pdo->prepare(
                "UPDATE storage_cells SET pallet_type = :pt, pallet_count = 1, status = 'reserved',
                        reserved_qty = COALESCE(reserved_qty, 0) + 1 WHERE id = :id"
            )->execute([':pt' => $palletType, ':id' => $cellId]);
            // Занятая ячейка тоже фиксирует разрешённый тип (колонка Евро/Фин).
            if ($managed) {
                $pdo->prepare('UPDATE storage_cells SET allowed_pallet_types = :pt WHERE id = :id')
                    ->execute([':pt' => $palletType, ':id' => $cellId]);
            }

            try {
                $pdo->prepare(
                    "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                     VALUES (:c, :u, :f, 'place', 'pallet_type', 'Размещение (ТСД)', 'free', :n)"
                )->execute([':c' => $cellId, ':u' => $userId, ':f' => $userFio,
                    ':n' => $palletType . ($sscc ? ' · SSCC ' . $sscc : '')]);
            } catch (Throwable) {
            }

            // Если робот выключен — старая логика: только 3.2 + евро перенастраивает секцию.
            if ($managed && $robot['section_pallet_lock'] === 0 && $beam <= 3.4 && $palletType === 'euro') {
                $cnt = $pdo->prepare(
                    "SELECT COUNT(*) FROM storage_cells
                     WHERE warehouse_id = :w AND building = :b AND row_no = :s
                       AND is_archived = 0 AND id != :id
                       AND pallet_type IS NOT NULL AND pallet_type != 'euro'
                       AND ((qty IS NOT NULL AND qty > 0) OR status IN ('busy','reserved'))"
                );
                $cnt->execute([':w' => $cell['warehouse_id'], ':b' => $cell['building'], ':s' => $cell['row_no'], ':id' => $cellId]);
                if ((int)$cnt->fetchColumn() === 0) {
                    $pdo->prepare(
                        "UPDATE storage_cells SET allowed_pallet_types = 'euro'
                         WHERE warehouse_id = :w AND building = :b AND row_no = :s
                           AND is_archived = 0 AND is_locked = 0 AND status = 'free'
                           AND (qty IS NULL OR qty <= 0)
                           AND COALESCE(allow_multi_pallet_types, 0) = 0"
                    )->execute([':w' => $cell['warehouse_id'], ':b' => $cell['building'], ':s' => $cell['row_no']]);
                    $tuned = true;
                }
            }

            // 3.6 + фин: при 3 занятых на ярусе — блокируем следующую свободную
            // ячейку яруса (по порядку place_no/code) с причиной "(Фин секция)".
            $blocked = null;
            if ($managed && $beam > 3.4 && $palletType === 'fin') {
                $q = $pdo->prepare(
                    "SELECT COUNT(*) FROM storage_cells
                     WHERE warehouse_id = :w AND building = :b AND row_no = :s AND level_no = :l
                       AND is_archived = 0 AND is_locked = 0
                       AND (status IN ('busy','reserved') OR (qty IS NOT NULL AND qty > 0) OR pallet_type IS NOT NULL)"
                );
                $q->execute([':w' => $cell['warehouse_id'], ':b' => $cell['building'],
                    ':s' => $cell['row_no'], ':l' => $cell['level_no']]);
                $busy = (int)$q->fetchColumn();
                if ($busy >= 3) {
                    // Блокируем наименьшую по порядку (place_no, затем code) свободную ячейку яруса.
                    $f = $pdo->prepare(
                        "SELECT * FROM storage_cells
                         WHERE warehouse_id = :w AND building = :b AND row_no = :s AND level_no = :l
                           AND is_archived = 0 AND is_locked = 0 AND status = 'free'
                           AND (qty IS NULL OR qty <= 0) AND id != :id
                         ORDER BY CASE WHEN place_no IS NULL OR place_no = '' THEN 1 ELSE 0 END,
                                  place_no ASC, code ASC LIMIT 1"
                    );
                    $f->execute([':w' => $cell['warehouse_id'], ':b' => $cell['building'],
                        ':s' => $cell['row_no'], ':l' => $cell['level_no'], ':id' => $cellId]);
                    if ($row = $f->fetch(PDO::FETCH_ASSOC)) {
                        $pdo->prepare(
                            "UPDATE storage_cells SET is_locked = 1, status = 'blocked', lock_reason = :r WHERE id = :id"
                        )->execute([':r' => self::FIN_SECTION_REASON, ':id' => $row['id']]);
                        try {
                            $pdo->prepare(
                                "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                                 VALUES (:c, :u, :f, 'block', 'is_locked', 'Блокировка', 'Нет', :n)"
                            )->execute([':c' => $row['id'], ':u' => $userId, ':f' => $userFio,
                                ':n' => 'Да (' . self::FIN_SECTION_REASON . ': 3 фин на балке 3.6)']);
                        } catch (Throwable) {
                        }
                        $blocked = $row;
                    }
                }
            }

            if (!$wasTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$wasTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Журнал склада (модуль 14): размещение паллеты в ячейку хранения.
        // Пишем после фиксации размещения — рядом с cell_history ('place').
        // ТСД-размещение пишет журнал на подтверждении ячейки (place_confirm),
        // поэтому там резерв не журналируем — иначе была бы двойная запись.
        if ($logJournal) {
            try {
                require_once __DIR__ . '/../shared/Journal.php';
                $skus = self::skuList($sku);
                $batchJ = '';
                if ($sscc !== null && $sscc !== '' && $skus) {
                    try {
                        require_once __DIR__ . '/../shared/db.php';
                        $ndb = wms_nomenclature_pdo();
                        $bq = $ndb->prepare(
                            "SELECT COALESCE(s.supplier_batch,'') FROM product_stock s
                             JOIN products p ON p.id=s.product_id WHERE s.pallet=:p AND p.sku=:s LIMIT 1"
                        );
                        $bq->execute([':p' => $sscc, ':s' => $skus[0]]);
                        $batchJ = trim((string)($bq->fetchColumn() ?: ''));
                    } catch (Throwable) {
                    }
                }
                WmsJournal::log(wms_journal_pdo(), [
                    'op_type' => 'размещение',
                    'doc_type' => 'Размещение',
                    'doc_no' => ($sscc !== null && $sscc !== '') ? $sscc : ('РАЗМ-' . date('Ymd-His')),
                    'sku' => $skus ? $skus[0] : null,
                    'supplier_batch' => $batchJ,
                    'qty' => $qty,
                    'pallet' => $sscc,
                    'sscc' => $sscc,
                    'cell_to' => (string)($cell['code'] ?? ''),
                    'zone' => 'Хранение',
                    'user_id' => $userId,
                    'user_fio' => $userFio,
                    'comment' => 'Размещение паллеты: ' . $palletType
                        . ($quality === 'Брак' ? ' · качество: брак' : ''),
                    'quality' => $quality,
                ]);
            } catch (Throwable) {
            }
        }

        $st->execute([':id' => $cellId]);
        return ['cell' => $st->fetch(PDO::FETCH_ASSOC), 'temp_blocked' => $blocked, 'section_tuned' => $tuned];
    }

    /**
     * Смена ячейки: снять резерв со старой, занять новую с теми же проверками
     * (высота/тара/секция/ярус; старый резерв в расчетах не участвует).
     * @return array{cell: array, old_cell: array, temp_blocked: ?array, section_tuned: bool}
     */
    public static function retarget(PDO $pdo, int $oldCellId, string $newCode, string $palletType,
        ?int $palletHeightMm = null, ?int $warehouseId = null, int $userId = 0, string $userFio = 'ТСД',
        string|array|null $sku = null, string $quality = 'новый', bool $logJournal = true): array
    {
        $palletType = self::normalizePalletType($palletType);
        $st = $pdo->prepare("SELECT * FROM storage_cells WHERE id = :id");
        $st->execute([':id' => $oldCellId]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            throw new RuntimeException('Старая ячейка не найдена');
        }
        $sql = "SELECT * FROM storage_cells WHERE code = :c AND is_archived = 0";
        $p = [':c' => trim($newCode)];
        if ($warehouseId) {
            $sql .= " AND warehouse_id = :w";
            $p[':w'] = $warehouseId;
        } elseif (!empty($old['warehouse_id'])) {
            $sql .= " AND warehouse_id = :w";
            $p[':w'] = (int)$old['warehouse_id'];
        }
        $nt = $pdo->prepare($sql . " LIMIT 1");
        $nt->execute($p);
        $new = $nt->fetch(PDO::FETCH_ASSOC);
        if (!$new) {
            throw new RuntimeException('Ячейка не найдена: ' . trim($newCode));
        }
        if ((int)$new['id'] === (int)$old['id']) {
            return ['cell' => $new, 'old_cell' => $old, 'temp_blocked' => null, 'section_tuned' => false];
        }
        $snap = self::snapshot($pdo, (int)($new['warehouse_id'] ?? $warehouseId ?? 0) ?: null);
        $new['content_skus'] = $snap['content'][(int)($new['id'] ?? 0)] ?? [];
        $chk = self::checkCell($new, $palletType, $palletHeightMm,
            $snap['bySection'], $snap['byLevel'], (int)$old['id'], $sku,
            self::robotSettings($pdo), $quality);
        if (!$chk['ok']) {
            throw new RuntimeException('Ячейка ' . $new['code'] . ': ' . $chk['error']);
        }

        $wasTx = $pdo->inTransaction();
        if (!$wasTx) {
            $pdo->beginTransaction();
        }
        try {
            // Снимаем старый резерв.
            $pdo->prepare(
                "UPDATE storage_cells SET pallet_type = NULL, pallet_count = 0,
                        reserved_qty = CASE WHEN COALESCE(reserved_qty,0) > 0 THEN reserved_qty - 1 ELSE 0 END,
                        status = CASE WHEN COALESCE(qty,0) > 0 THEN 'busy' ELSE 'free' END
                 WHERE id = :id"
            )->execute([':id' => $old['id']]);
            try {
                self::syncTempBlocks($pdo, (int)$old['warehouse_id'],
                    $old['building'] ?? null, $old['row_no'] ?? null, $old['level_no'] ?? null);
                self::syncSectionLock($pdo, (int)$old['warehouse_id'],
                    $old['building'] ?? null, $old['row_no'] ?? null);
            } catch (Throwable) {
            }
            // Занимаем новую (вложенный occupy участвует в той же транзакции).
            $occ = self::occupy($pdo, (int)$new['id'], $palletType, null, $userId,
                $userFio . ' (смена ячейки)', 0.0, null, $sku, $quality, $logJournal);
            if (!$wasTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$wasTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['cell' => $occ['cell'], 'old_cell' => $old,
            'temp_blocked' => $occ['temp_blocked'], 'section_tuned' => $occ['section_tuned']];
    }

    /**
     * Автокоррекция «пустых, но занятых» ячеек: если в ячейке нет остатка ни в
     * product_stock, ни в cell_content, а статус busy — возвращаем свободный статус.
     * Лечит случаи, когда паллету убрали, а статус ячейки остался занятым.
     * @return int количество исправленных ячеек
     */
    public static function reconcileEmptyCells(PDO $cellPdo, ?PDO $nomPdo = null): int
    {
        if ($nomPdo === null && function_exists('wms_nomenclature_pdo')) {
            $nomPdo = wms_nomenclature_pdo();
        }
        if (!$nomPdo) {
            return 0;
        }
        try {
            $rows = $cellPdo->query(
                "SELECT id, code, qty, pallet_count, pallet_type
                   FROM storage_cells
                  WHERE is_archived = 0 AND status = 'busy' AND COALESCE(qty, 0) > 0"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return 0;
        }
        $n = 0;
        foreach ($rows as $r) {
            $code = trim((string)($r['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $stock = 0.0;
            try {
                $st = $nomPdo->prepare(
                    'SELECT COALESCE(SUM(qty), 0) FROM product_stock
                      WHERE LOWER(TRIM(cell)) = LOWER(:c) AND qty > 0'
                );
                $st->execute([':c' => $code]);
                $stock = (float)$st->fetchColumn();
            } catch (Throwable) {
            }
            $content = 0.0;
            try {
                $ct = $cellPdo->prepare('SELECT COALESCE(SUM(qty), 0) FROM cell_content WHERE cell_id = :id');
                $ct->execute([':id' => (int)$r['id']]);
                $content = (float)$ct->fetchColumn();
            } catch (Throwable) {
            }
            if ($stock > 0.0005 || $content > 0.0005) {
                continue;
            }
            try {
                $cellPdo->prepare(
                    "UPDATE storage_cells SET status = 'free', qty = 0, reserved_qty = 0,
                            pallet_count = 0, pallet_type = NULL WHERE id = :id"
                )->execute([':id' => (int)$r['id']]);
                $cellPdo->prepare(
                    "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                     VALUES (:c, 1, 'Система', 'update', 'status', 'Статус', :o, 'Свободна (пустая)')"
                )->execute([':c' => (int)$r['id'], ':o' => 'Занята · ' . (float)($r['qty'] ?? 0) . ' шт']);
                $n++;
            } catch (Throwable) {
            }
        }
        return $n;
    }
    /**
     * Самовосстановление настроек тары по секциям (робот):
     * если в секции есть занятая управляемая паллета (флаг «разные типы» снят),
     * все управляемые ячейки секции настраиваются под её тип (евро/фин) —
     * вторая тара получает крест и блокируется к размещению.
     * Пустая секция: свободные управляемые ячейки — под оба типа.
     * Ячейки с флагом «разные типы паллет» не трогаем.
     * @return int количество обновлённых ячеек
     */
    public static function syncManagedSections(PDO $pdo): int
    {
        $robot = self::robotSettings($pdo);
        if ((int)$robot['section_pallet_lock'] !== 1) {
            return 0;
        }
        try {
            $rows = $pdo->query(
                "SELECT id,warehouse_id,building,row_no,level_no,place_no,status,qty,pallet_count,
                        pallet_type,allow_multi_pallet_types,allowed_pallet_types,is_locked
                   FROM storage_cells WHERE is_archived = 0"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return 0;
        }
        $groups = [];
        foreach ($rows as $r) {
            $groups[self::sectionKey($r)][] = $r;
        }
        $upd = $pdo->prepare('UPDATE storage_cells SET allowed_pallet_types = :t WHERE id = :id');
        $n = 0;
        foreach ($groups as $cells) {
            $mode = null;
            $conflict = false;
            foreach ($cells as $c) {
                if ((int)($c['allow_multi_pallet_types'] ?? 0) === 1 || !self::isOccupied($c)) {
                    continue;
                }
                $t = trim((string)($c['pallet_type'] ?? ''));
                if ($t === '') {
                    continue;
                }
                $t = self::normalizePalletType($t);
                if ($mode === null) {
                    $mode = $t;
                } elseif ($mode !== $t) {
                    $conflict = true;
                }
            }
            if ($conflict) {
                continue;
            }
            $target = $mode ?? 'euro,fin';
            foreach ($cells as $c) {
                if ((int)($c['allow_multi_pallet_types'] ?? 0) === 1) {
                    continue;
                }
                if ($mode === null && self::isOccupied($c)) {
                    continue;
                }
                if ((string)($c['allowed_pallet_types'] ?? '') === $target) {
                    continue;
                }
                try {
                    $upd->execute([':t' => $target, ':id' => (int)$c['id']]);
                    $n++;
                } catch (Throwable) {
                }
            }
        }
        return $n;
    }
    /**
     * Разблокировать тип паллеты секции, если она стала полностью пустой.
     * Вызывается после освобождения ячейки: если в секции больше нет занятых ячеек,
     * все свободные ячейки секции возвращаются к allowed_pallet_types = 'euro,fin'.
     * @return int количество разблокированных ячеек
     */
    public static function syncSectionLock(PDO $pdo, int $warehouseId, ?string $building, ?string $rowNo): int
    {
        $robot = self::robotSettings($pdo);
        if ($robot['section_pallet_lock'] !== 1) {
            return 0;
        }
        if ($building === null || $rowNo === null) {
            return 0;
        }
        // Проверяем, есть ли в секции хоть одна занятая ячейка.
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM storage_cells
             WHERE warehouse_id = :w AND building = :b AND row_no = :s
               AND is_archived = 0
               AND ((qty IS NOT NULL AND qty > 0) OR status IN ('busy','reserved') OR pallet_type IS NOT NULL)"
        );
        $st->execute([':w' => $warehouseId, ':b' => $building, ':s' => $rowNo]);
        if ((int)$st->fetchColumn() > 0) {
            return 0; // Секция не пустая — не разблокируем
        }
        // Секция пустая — возвращаем все свободные ячейки к 'euro,fin'.
        $st = $pdo->prepare(
            "UPDATE storage_cells SET allowed_pallet_types = 'euro,fin'
             WHERE warehouse_id = :w AND building = :b AND row_no = :s
               AND is_archived = 0 AND is_locked = 0 AND status = 'free'
               AND (qty IS NULL OR qty <= 0)
               AND COALESCE(allow_multi_pallet_types, 0) = 0"
        );
        $st->execute([':w' => $warehouseId, ':b' => $building, ':s' => $rowNo]);
        return $st->rowCount();
    }

    /**
     * Пересчет временных блокировок яруса (вызывать при освобождении ячейки):
     * если финов на ярусе 3.6 стало < 3 — снимаем блокировку "(Фин секция)".
     * Снимаются и блокировки со старой причиной "временно занята" (совместимость).
     */
    public static function syncTempBlocks(PDO $pdo, int $warehouseId, ?string $building, ?string $sec, ?string $level): int
    {
        $q = $pdo->prepare(
            "SELECT COUNT(*) FROM storage_cells
             WHERE warehouse_id = :w AND building = :b AND row_no = :s AND level_no = :l
               AND is_archived = 0 AND is_locked = 0
               AND (status IN ('busy','reserved') OR (qty IS NOT NULL AND qty > 0) OR pallet_type IS NOT NULL)"
        );
        $q->execute([':w' => $warehouseId, ':b' => $building, ':s' => $sec, ':l' => $level]);
        if ((int)$q->fetchColumn() >= 3) {
            return 0;
        }
        $st = $pdo->prepare(
            "UPDATE storage_cells SET is_locked = 0, lock_reason = NULL, status = 'free'
             WHERE warehouse_id = :w AND building = :b AND row_no = :s AND level_no = :l
               AND is_archived = 0 AND is_locked = 1 AND lock_reason IN (:r1, :r2)
               AND (qty IS NULL OR qty <= 0)"
        );
        $st->execute([':w' => $warehouseId, ':b' => $building, ':s' => $sec, ':l' => $level,
            ':r1' => self::FIN_SECTION_REASON, ':r2' => self::TEMP_BUSY_REASON]);
        // Также разблокируем секцию если она стала пустой.
        self::syncSectionLock($pdo, $warehouseId, $building, $sec);
        return $st->rowCount();
    }
}
