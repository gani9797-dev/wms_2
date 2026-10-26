<?php
declare(strict_types=1);

require_once __DIR__ . '/CellPlacement.php';

// Проверка ячейки-назначения под перемещение ЦЕЛОГО паллета.
// Вызывается с ТСД после скана ячейки (move_check / move_confirm):
// на любое несоответствие возвращает ['ok'=>false,'error'=><текст для ТСД>].
//
// $cell — строка storage_cells (findByCode).
// $pallet — агрегат паллеты:
//   ['sscc'=>..., 'cell_from'=>..., 'qty'=>float шт,
//    'weight_kg'=>float|null, 'volume_m3'=>float|null,
//    'height_mm'=>int|null, 'pallet_type'=>'euro'|'fin',
//    'has_danger'=>bool, 'temp_from'=>float|null, 'temp_to'=>float|null,
//    'categories'=>string[], 'lines'=>[['sku'=>...,'qty'=>...], ...]]
// $snap — snapshot(PDO) для секционных проверок (bySection/byLevel), опционально.
// $pdo — подключение к БД ячеек: нужен, если снимок не содержит содержимое ячейки.

final class CellMoveCheck
{
    public static function palletTypeLabel(string $t): string
    {
        return CellPlacement::normalizePalletType($t) === 'fin' ? 'фин' : 'евро';
    }

    /** SKU перемещаемой паллеты (из lines или из поля sku). */
    public static function palletSkus(array $pallet): array
    {
        $out = [];
        foreach ((array)($pallet['lines'] ?? []) as $ln) {
            $sku = trim((string)(is_array($ln) ? ($ln['sku'] ?? '') : $ln));
            if ($sku !== '' && !in_array($sku, $out, true)) {
                $out[] = $sku;
            }
        }
        $one = trim((string)($pallet['sku'] ?? ''));
        if ($one !== '' && !in_array($one, $out, true)) {
            $out[] = $one;
        }
        return $out;
    }

    /** SKU, уже лежащие в целевой ячейке (из снимка или напрямую из БД). */
    public static function cellSkus(array $cell, ?array $snap, ?PDO $pdo): array
    {
        $cid = (int)($cell['id'] ?? 0);
        if ($snap !== null && isset($snap['content'][$cid]) && is_array($snap['content'][$cid])) {
            return CellPlacement::skuList($snap['content'][$cid]);
        }
        if (is_array($cell['content_skus'] ?? null)) {
            return CellPlacement::skuList($cell['content_skus']);
        }
        if ($pdo instanceof PDO && $cid > 0) {
            return CellPlacement::cellContentSkus($pdo, $cid);
        }
        return [];
    }

    /** @return array{ok: bool, code?: string, error?: string} */
    public static function check(array $cell, array $pallet, ?array $snap = null, ?PDO $pdo = null): array
    {
        $bad = static fn(string $code, string $err) => ['ok' => false, 'code' => $code, 'error' => $err];
        $code = (string)($cell['code'] ?? '?');
        $sscc = trim((string)($pallet['sscc'] ?? ''));
        $ptype = CellPlacement::normalizePalletType((string)($pallet['pallet_type'] ?? 'euro'));
        $qty = round((float)($pallet['qty'] ?? 0), 3);
        $pheight = isset($pallet['height_mm']) && $pallet['height_mm'] !== null && (int)$pallet['height_mm'] > 0
            ? (int)$pallet['height_mm'] : null;
        $pweight = isset($pallet['weight_kg']) && $pallet['weight_kg'] !== null
            ? round((float)$pallet['weight_kg'], 3) : null;

        // 0) Та же ячейка.
        $from = trim((string)($pallet['cell_from'] ?? ''));
        if ($from !== '' && CellPlacement::sameCellCode($from, $code)) {
            return $bad('same_cell', 'Паллета ' . $sscc . ' уже находится в ячейке ' . $code);
        }
        // 1) Архив.
        if ((int)($cell['is_archived'] ?? 0) === 1) {
            return $bad('archived', 'Ячейка ' . $code . ' архивирована — перемещение запрещено');
        }
        // 2) Блокировка (любая, включая «временно занята»).
        if ((int)($cell['is_locked'] ?? 0) === 1) {
            $r = trim((string)($cell['lock_reason'] ?? ''));
            return $bad('locked', 'Ячейка ' . $code . ' заблокирована'
                . ($r !== '' ? ': ' . $r : '') . ' — перемещение запрещено');
        }
        if (($cell['status'] ?? '') === 'blocked') {
            $r = trim((string)($cell['lock_reason'] ?? ''));
            return $bad('blocked', 'Ячейка ' . $code . ' заблокирована'
                . ($r !== '' ? ': ' . $r : '') . ' — перемещение запрещено');
        }
        // 3) Зона и тип: целый паллет — только паллетная ячейка зоны хранения.
        $zone = trim((string)($cell['zone'] ?? 'storage'));
        if ($zone !== '' && $zone !== 'storage') {
            return $bad('zone', 'Ячейка ' . $code . ' не в зоне хранения (зона: ' . $zone . ') — ставить паллету нельзя');
        }
        $ctype = trim((string)($cell['cell_type'] ?? 'pallet'));
        if ($ctype !== '' && $ctype !== 'pallet') {
            return $bad('cell_type', 'Ячейка ' . $code . ' не паллетная (тип: ' . $ctype . ') — целый паллет не встанет');
        }
        // 4) Правило «разные товары в одной ячейке» (галка allow_mixed_goods снята).
        // Проверяем ДО проверки занятости: оператор должен увидеть точную причину.
        if ((int)($cell['allow_mixed_goods'] ?? 1) === 0) {
            $mixed = CellPlacement::mixedGoodsError($cell,
                self::cellSkus($cell, $snap, $pdo), self::palletSkus($pallet));
            if ($mixed !== null) {
                return $bad('mixed_goods', $mixed);
            }
        }
        // 5) Занятость: назначение обязано быть свободным.
        $cQty = (float)($cell['qty'] ?? 0);
        $cRes = (float)($cell['reserved_qty'] ?? 0);
        $cPal = (int)($cell['pallet_count'] ?? 0);
        $cPt = trim((string)($cell['pallet_type'] ?? ''));
        $cSt = (string)($cell['status'] ?? 'free');
        if ($cSt !== 'free' || $cQty > 0 || $cPal > 0 || $cPt !== '' || $cRes > 0) {
            $detail = 'статус: ' . $cSt;
            if ($cQty > 0) {
                $detail .= ', в ячейке: ' . $cQty . ' шт';
            }
            if ($cPal > 0) {
                $detail .= ', паллет: ' . $cPal;
            }
            return $bad('busy', 'Ячейка ' . $code . ' уже занята (' . $detail . ') — перемещение запрещено');
        }
        // 6) Тип тары.
        $raw = trim((string)($cell['allowed_pallet_types'] ?? 'euro,fin'));
        if ($raw !== '' && $raw !== 'Все' && $raw !== '*') {
            $parts = array_map(fn($s) => CellPlacement::normalizePalletType($s),
                preg_split('/[,\s;\/]+/', $raw, -1, PREG_SPLIT_NO_EMPTY));
            if (!in_array($ptype, $parts, true)) {
                return $bad('pallet_type', 'Ячейка ' . $code . ' не принимает тару «'
                    . self::palletTypeLabel($ptype) . '» (разрешено: ' . $raw . ')');
            }
        }
        // 7) Высота паллеты.
        if ($pheight !== null && $pheight > 0
            && $cell['cell_height_mm'] !== null && (int)$cell['cell_height_mm'] > 0
            && (int)$cell['cell_height_mm'] < $pheight) {
            return $bad('height', 'Не проходит по высоте: ячейка ' . $code . ' '
                . (int)$cell['cell_height_mm'] . ' мм < паллеты ' . $pheight . ' мм');
        }
        // 8) Место по паллетам.
        if ($cell['max_pallets'] !== null && $cell['max_pallets'] !== '') {
            $max = (int)$cell['max_pallets'];
            if ($max <= 0) {
                return $bad('pallets', 'Ячейка ' . $code . ' не принимает паллеты (макс. паллет: 0) — перемещение запрещено');
            }
            if ($cPal + 1 > $max) {
                return $bad('pallets', 'Не хватает места по паллетам: в ячейке ' . $code
                    . ' занято ' . $cPal . ' из ' . $max . ', ещё 1 паллета не встанет');
            }
        }
        // 9) Место по объему/вместимости (шт). capacity=0 — безразмерная, без лимита.
        $cap = (float)($cell['capacity'] ?? 0);
        if ($cap > 0 && $qty > 0 && $cQty + $qty - $cap > 1e-9) {
            return $bad('capacity', 'Не хватает места по объему/вместимости: в ячейке ' . $code
                . ' свободно ' . round($cap - $cQty, 3) . ' шт из ' . $cap . ', а на паллете ' . $qty . ' шт');
        }
        // 10) Место по весу.
        if ($cell['max_weight_kg'] !== null && $cell['max_weight_kg'] !== ''
            && (float)$cell['max_weight_kg'] > 0 && $pweight !== null && $pweight > 0
            && $pweight - (float)$cell['max_weight_kg'] > 1e-9) {
            return $bad('weight', 'Превышен макс. вес ячейки ' . $code . ': паллета '
                . $pweight . ' кг > лимита ' . (float)$cell['max_weight_kg'] . ' кг');
        }
        // 11) Температурный режим: требование товара обязано входить в режим ячейки.
        $ctF = $cell['temp_from'] !== null && $cell['temp_from'] !== '' ? (float)$cell['temp_from'] : null;
        $ctT = $cell['temp_to'] !== null && $cell['temp_to'] !== '' ? (float)$cell['temp_to'] : null;
        $ptF = $pallet['temp_from'] ?? null;
        $ptT = $pallet['temp_to'] ?? null;
        $ptF = ($ptF !== null && $ptF !== '') ? (float)$ptF : null;
        $ptT = ($ptT !== null && $ptT !== '') ? (float)$ptT : null;
        if (($ctF !== null || $ctT !== null) && ($ptF !== null || $ptT !== null)) {
            $badTemp = false;
            if ($ctF !== null && $ptF !== null && $ptF < $ctF - 1e-9) {
                $badTemp = true;
            }
            if ($ctT !== null && $ptT !== null && $ptT > $ctT + 1e-9) {
                $badTemp = true;
            }
            if ($badTemp) {
                $fmt = static fn($v) => ($v !== null ? (($v > 0 ? '+' : '') . $v) : '—');
                return $bad('temp', 'Не подходит по температурному режиму: товар требует '
                    . $fmt($ptF) . '… ' . $fmt($ptT) . ' °C, ячейка ' . $code . ' '
                    . $fmt($ctF) . '… ' . $fmt($ctT) . ' °C');
            }
        }
        // 12) Разрешённые категории (allowed_goods): «Все» — пропускает всё.
        $ag = trim((string)($cell['allowed_goods'] ?? 'Все'));
        if ($ag !== '' && $ag !== 'Все' && $ag !== '*') {
            $allowed = array_map(fn($s) => function_exists('mb_strtolower')
                    ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s)),
                preg_split('/[,\s;\/]+/', $ag, -1, PREG_SPLIT_NO_EMPTY));
            foreach ((array)($pallet['categories'] ?? []) as $cat) {
                $c = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$cat), 'UTF-8') : strtolower(trim((string)$cat));
                if ($c === '') {
                    continue;
                }
                if (!in_array($c, $allowed, true)) {
                    return $bad('category', 'Категория товара «' . $cat
                        . '» запрещена в ячейке ' . $code . ' (разрешено: ' . $ag . ')');
                }
            }
        }
        // 13) Опасный груз.
        if (!empty($pallet['has_danger']) && (int)($cell['hazmat_allowed'] ?? 0) !== 1) {
            return $bad('hazmat', 'Опасный груз запрещён в ячейке ' . $code
                . ' (в настройках ячейки нет разрешения на опасный груз)');
        }
        // 14) Секционные правила (режим секции под евро/фин, вместимость яруса).
        if ($snap !== null && isset($snap['bySection'], $snap['byLevel'])) {
            $chk = CellPlacement::checkCell($cell, $ptype, $pheight, $snap['bySection'], $snap['byLevel']);
            if (!$chk['ok']) {
                return $bad('section', 'Ячейка ' . $code . ': ' . ($chk['error'] ?? 'не подходит по правилам секции'));
            }
        }
        return ['ok' => true];
    }
}
