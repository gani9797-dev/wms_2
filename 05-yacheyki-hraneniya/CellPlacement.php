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
//          одна свободная ячейка яруса блокируется is_locked=1,
//          lock_reason='временно занята' (снимается при освобождении).
// Подбор онлайновый: приоритет — добивка непустых секций до полной
// (сначала секции с занятостью, самые заполненные первыми).
// Плюс фильтры: высота (cell_height_mm >= высоты паллеты), тип тары
// (allowed_pallet_types), только свободные незаблокированные ячейки хранения.

final class CellPlacement
{
    public const TEMP_BUSY_REASON = 'временно занята';

    public const BEAM_32 = 3.2;
    public const BEAM_36 = 3.6;

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

    /** Вместимость яруса по типу тары. */
    public static function levelCapacity(float $beam, string $palletType): int
    {
        $beam = self::normalizeBeam($beam);
        $t = self::normalizePalletType($palletType);
        if ($beam <= 3.4) {
            return $t === 'fin' ? 2 : 3;
        }
        return $t === 'fin' ? 3 : 4;
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
        return ((int)($c['is_locked'] ?? 0) === 1)
            && trim((string)($c['lock_reason'] ?? '')) === self::TEMP_BUSY_REASON;
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

    /**
     * Снимок всех ячеек склада для секционных проверок.
     * @return array{bySection: array, byLevel: array}
     */
    public static function snapshot(PDO $pdo, ?int $warehouseId = null): array
    {
        $all = $pdo->query(
            "SELECT id, code, warehouse_id, building, row_no, level_no, place_no, status, qty,
                    pallet_count, beam_width, cell_height_mm, allowed_pallet_types, pallet_type,
                    is_locked, lock_reason, cell_type, zone
             FROM storage_cells WHERE is_archived = 0" .
            ($warehouseId ? " AND warehouse_id = " . (int)$warehouseId : "")
        )->fetchAll(PDO::FETCH_ASSOC);
        $bySection = [];
        $byLevel = [];
        foreach ($all as $c) {
            $bySection[self::sectionKey($c)][] = $c;
            $byLevel[self::levelKey($c)][] = $c;
        }
        return ['bySection' => $bySection, 'byLevel' => $byLevel];
    }

    /**
     * Проверка конкретной ячейки под паллету.
     * $ignoreId — ячейка, чей резерв не считаем занятостью (смена ячейки: старый резерв скоро освободят).
     * @return array{ok: bool, error: ?string, sec_busy: int, sec_free: int, lvl_busy: int, lvl_cap: int, h_slack: ?int}
     */
    public static function checkCell(array $cell, string $palletType, ?int $palletHeightMm,
        array $bySection, array $byLevel, ?int $ignoreId = null): array
    {
        $palletType = self::normalizePalletType($palletType);
        $bad = static fn(string $e) => ['ok' => false, 'error' => $e,
            'sec_busy' => 0, 'sec_free' => 0, 'lvl_busy' => 0, 'lvl_cap' => 0, 'h_slack' => null];
        if ((int)($cell['is_locked'] ?? 0) === 1) {
            return $bad('Ячейка заблокирована'
                . (trim((string)($cell['lock_reason'] ?? '')) !== '' ? ': ' . $cell['lock_reason'] : ''));
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
        $isIgn = static fn(array $s): bool => $ignoreId !== null && (int)($s['id'] ?? -1) === $ignoreId;
        $secCells = $bySection[self::sectionKey($cell)] ?? [];
        $lvlCells = $byLevel[self::levelKey($cell)] ?? [];

        // Режим секции: хоть один евро => вся секция под евро.
        $secMode = null; // euro|fin|null
        foreach ($secCells as $s) {
            if ($isIgn($s) || !self::isOccupied($s)) {
                continue;
            }
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
        if ($secMode !== null && $secMode !== $palletType) {
            return $bad($secMode === 'euro'
                ? 'Секция настроена под евро: фин ставить нельзя'
                : 'Секция занята финами: добивка только финами');
        }

        $beam = self::normalizeBeam($cell['beam_width'] ?? self::BEAM_32);
        $cap = self::levelCapacity($beam, $palletType);
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
            return $bad('Ярус уже полон (' . $lvlBusy . '/' . $cap . ' на балке ' . ($beam <= 3.4 ? '3,2' : '3,6') . ')');
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
            'lvl_busy' => $lvlBusy, 'lvl_cap' => $cap, 'h_slack' => $hSlack];
    }

    public static function sameCellCode(string $a, string $b): bool
    {
        $n = static fn(string $s): string => function_exists('mb_strtoupper')
            ? mb_strtoupper(trim($s), 'UTF-8') : strtoupper(trim($s));
        return $n($a) !== '' && $n($a) === $n($b);
    }

    /**
     * Подобрать свободную ячейку онлайн.
     * @return array{cell: array, section: array}|null
     */
    public static function suggest(PDO $pdo, string $palletType, ?int $palletHeightMm = null, ?int $warehouseId = null): ?array
    {
        $palletType = self::normalizePalletType($palletType);
        $sql = "SELECT * FROM storage_cells WHERE is_archived = 0 AND is_locked = 0
                AND status IN ('free') AND (qty IS NULL OR qty <= 0)
                AND (cell_type IS NULL OR cell_type = 'pallet') AND zone = 'storage'";
        $params = [];
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

        $cands = [];
        foreach ($free as $cell) {
            $chk = self::checkCell($cell, $palletType, $palletHeightMm, $bySection, $byLevel);
            if (!$chk['ok']) {
                continue;
            }
            $cands[] = [
                'cell' => $cell,
                'sec_busy' => $chk['sec_busy'],
                'sec_free' => $chk['sec_free'],
                'lvl_busy' => $chk['lvl_busy'],
                'lvl_cap' => $chk['lvl_cap'],
                'h_slack' => $chk['h_slack'],
            ];
        }

        if (!$cands) {
            return null;
        }

        // Приоритет: непустые секции первыми, среди них — самые заполненные
        // (добиваем до полной), затем минимальная остаточная высота.
        usort($cands, function ($a, $b) {
            $aNe = $a['sec_busy'] > 0 ? 0 : 1;
            $bNe = $b['sec_busy'] > 0 ? 0 : 1;
            if ($aNe !== $bNe) {
                return $aNe <=> $bNe;
            }
            if ($a['sec_busy'] !== $b['sec_busy']) {
                return $b['sec_busy'] <=> $a['sec_busy'];
            }
            if ($a['lvl_busy'] !== $b['lvl_busy']) {
                return $b['lvl_busy'] <=> $a['lvl_busy'];
            }
            $as = $a['h_slack'] ??  PHP_INT_MAX;
            $bs = $b['h_slack'] ?? PHP_INT_MAX;
            if ($as !== $bs) {
                return $as <=> $bs;
            }
            return strcmp((string)$a['cell']['code'], (string)$b['cell']['code']);
        });

        $top = $cands[0];
        return [
            'cell' => $top['cell'],
            'section' => [
                'key' => self::sectionKey($top['cell']),
                'busy' => $top['sec_busy'],
                'free' => $top['sec_free'],
                'level_busy' => $top['lvl_busy'],
                'level_cap' => $top['lvl_cap'],
                'beam' => self::normalizeBeam($top['cell']['beam_width'] ?? self::BEAM_32),
            ],
        ];
    }

    /**
     * Занять подобранную ячейку: резерв + простановка типа паллеты.
     * Для 3.6 + фин при достижении 3 занятых на ярусе — блокирует одну
     * свободную ячейку яруса с причиной "временно занята".
     * Для 3.2 первое евро в пустой секции переводит свободные ячейки секции на euro.
     * @return array{cell: array, temp_blocked: ?array, section_tuned: bool}
     */
    public static function occupy(PDO $pdo, int $cellId, string $palletType, ?string $sscc = null, int $userId = 1, string $userFio = 'ТСД'): array
    {
        $palletType = self::normalizePalletType($palletType);
        $st = $pdo->prepare("SELECT * FROM storage_cells WHERE id = :id");
        $st->execute([':id' => $cellId]);
        $cell = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cell) {
            throw new RuntimeException('Ячейка не найдена');
        }
        if ((int)($cell['is_locked'] ?? 0) === 1) {
            throw new RuntimeException('Ячейка заблокирована: ' . ($cell['code'] ?? $cellId));
        }
        if ((float)($cell['qty'] ?? 0) > 0 || ($cell['status'] ?? '') !== 'free') {
            throw new RuntimeException('Ячейка уже занята: ' . ($cell['code'] ?? $cellId));
        }

        $beam = self::normalizeBeam($cell['beam_width'] ?? self::BEAM_32);
        $wasTx = $pdo->inTransaction();
        if (!$wasTx) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare(
                "UPDATE storage_cells SET pallet_type = :pt, pallet_count = 1, status = 'reserved',
                        reserved_qty = COALESCE(reserved_qty, 0) + 1 WHERE id = :id"
            )->execute([':pt' => $palletType, ':id' => $cellId]);

            try {
                $pdo->prepare(
                    "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                     VALUES (:c, :u, :f, 'place', 'pallet_type', 'Размещение (ТСД)', 'free', :n)"
                )->execute([':c' => $cellId, ':u' => $userId, ':f' => $userFio,
                    ':n' => $palletType . ($sscc ? ' · SSCC ' . $sscc : '')]);
            } catch (Throwable) {
            }

            // Вся секция под евро после первого евро (3.2): перенастраиваем свободные.
            $tuned = false;
            if ($beam <= 3.4 && $palletType === 'euro') {
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
                           AND (qty IS NULL OR qty <= 0)"
                    )->execute([':w' => $cell['warehouse_id'], ':b' => $cell['building'], ':s' => $cell['row_no']]);
                    $tuned = true;
                }
            }

            // 3.6 + фин: при 3 занятых на ярусе — блокируем одну свободную как временно занятую.
            $blocked = null;
            if ($beam > 3.4 && $palletType === 'fin') {
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
                    $f = $pdo->prepare(
                        "SELECT * FROM storage_cells
                         WHERE warehouse_id = :w AND building = :b AND row_no = :s AND level_no = :l
                           AND is_archived = 0 AND is_locked = 0 AND status = 'free'
                           AND (qty IS NULL OR qty <= 0) AND id != :id
                         ORDER BY code ASC LIMIT 1"
                    );
                    $f->execute([':w' => $cell['warehouse_id'], ':b' => $cell['building'],
                        ':s' => $cell['row_no'], ':l' => $cell['level_no'], ':id' => $cellId]);
                    if ($row = $f->fetch(PDO::FETCH_ASSOC)) {
                        $pdo->prepare(
                            "UPDATE storage_cells SET is_locked = 1, status = 'blocked', lock_reason = :r WHERE id = :id"
                        )->execute([':r' => self::TEMP_BUSY_REASON, ':id' => $row['id']]);
                        try {
                            $pdo->prepare(
                                "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value)
                                 VALUES (:c, :u, :f, 'block', 'is_locked', 'Блокировка', 'Нет', 'Да (временно занята: 3 фин на балке 3.6)')"
                            )->execute([':c' => $row['id'], ':u' => $userId, ':f' => $userFio]);
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

        $st->execute([':id' => $cellId]);
        return ['cell' => $st->fetch(PDO::FETCH_ASSOC), 'temp_blocked' => $blocked, 'section_tuned' => $tuned];
    }

    /**
     * Смена ячейки: снять резерв со старой, занять новую с теми же проверками
     * (высота/тара/секция/ярус; старый резерв в расчетах не участвует).
     * @return array{cell: array, old_cell: array, temp_blocked: ?array, section_tuned: bool}
     */
    public static function retarget(PDO $pdo, int $oldCellId, string $newCode, string $palletType,
        ?int $palletHeightMm = null, ?int $warehouseId = null, int $userId = 0, string $userFio = 'ТСД'): array
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
        $chk = self::checkCell($new, $palletType, $palletHeightMm,
            $snap['bySection'], $snap['byLevel'], (int)$old['id']);
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
            } catch (Throwable) {
            }
            // Занимаем новую (вложенный occupy участвует в той же транзакции).
            $occ = self::occupy($pdo, (int)$new['id'], $palletType, null, $userId, $userFio . ' (смена ячейки)');
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
     * Пересчет временных блокировок яруса (вызывать при освобождении ячейки):
     * если финов на ярусе 3.6 стало < 3 — снимаем "временно занята".
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
               AND is_archived = 0 AND is_locked = 1 AND lock_reason = :r
               AND (qty IS NULL OR qty <= 0)"
        );
        $st->execute([':w' => $warehouseId, ':b' => $building, ':s' => $sec, ':l' => $level, ':r' => self::TEMP_BUSY_REASON]);
        return $st->rowCount();
    }
}
