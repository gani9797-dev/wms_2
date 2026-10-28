<?php
declare(strict_types=1);

require_once __DIR__ . '/CellStatus.php';
require_once __DIR__ . '/../shared/CellZones.php';

// Сервис ячеек: создание / редактирование / копирование / блокировка / архив / перемещение / экспорт / импорт.
// Каждое изменение значимого поля пишется в cell_history, движения товара — в cell_movements.

final class CellService
{
    public function __construct(private PDO $pdo)
    {
        wms_cell_extra_zones_ensure($pdo);
    }

    private const EDITABLE = [
        'code', 'warehouse_id', 'zone', 'cell_type', 'category', 'capacity',
        'building', 'row_no', 'level_no', 'place_no', 'coord_x', 'coord_y',
        'temp_from', 'temp_to', 'allowed_goods', 'max_weight_kg', 'max_pallets',
        'hazmat_allowed', 'comment',
        'beam_width', 'cell_height_mm', 'allowed_pallet_types', 'pallet_type',
        'allow_multi_pallet_types', 'allow_mixed_goods',
    ];

    private const LABELS = [
        'code' => 'Адрес', 'warehouse_id' => 'Склад', 'zone' => 'Зона',
        'cell_type' => 'Тип ячейки', 'category' => 'Категория', 'capacity' => 'Вместимость',
        'building' => 'Корпус / Секция', 'row_no' => 'Ряд', 'level_no' => 'Уровень',
        'place_no' => 'Ячейка', 'coord_x' => 'Координата X', 'coord_y' => 'Координата Y',
        'temp_from' => 'Темп. от', 'temp_to' => 'Темп. до', 'allowed_goods' => 'Разрешено',
        'max_weight_kg' => 'Макс вес', 'max_pallets' => 'Макс паллет',
        'hazmat_allowed' => 'Опасный груз', 'comment' => 'Комментарий',
        'beam_width' => 'Ширина балки', 'cell_height_mm' => 'Высота ячейки',
        'allowed_pallet_types' => 'Типы тары', 'pallet_type' => 'Тип паллеты',
        'allow_multi_pallet_types' => 'Несколько типов паллет',
        'allow_mixed_goods' => 'Разные товары в ячейке',
        'status' => 'Статус', 'is_locked' => 'Блокировка',
    ];

    public const BEAMS = [3.2, 3.6];

    /**
     * Допустимые коды зон ячейки: базовые + справочник storage_zones
     * (в нём, например, quarantine — «Карантин»).
     * @return string[]
     */
    public function zoneCodes(): array
    {
        $out = CellStatus::zones();
        try {
            $st = $this->pdo->query("SELECT code FROM storage_zones WHERE COALESCE(is_active,1)=1");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = trim((string)($r['code'] ?? ''));
                if ($c !== '' && !in_array($c, $out, true)) {
                    $out[] = $c;
                }
            }
        } catch (Throwable) {
        }
        return $out;
    }

    /** Проверка зоны ячейки (код из справочника storage_zones или базовый). */
    private function assertZone(string $zone): void
    {
        $z = trim($zone);
        if ($z === '' || !in_array($z, $this->zoneCodes(), true)) {
            throw new RuntimeException('Недопустимая зона: ' . implode('/', $this->zoneCodes()));
        }
    }

    public function extraZones(int $cellId): array
    {
        $map = wms_cell_extra_zones_map($this->pdo, [$cellId]);
        return $map[$cellId] ?? [];
    }

    private function normalizeZoneList(mixed $v): array
    {
        $raw = [];
        if (is_array($v)) {
            $raw = $v;
        } elseif (is_string($v) && trim($v) !== '') {
            $raw = preg_split('/[,\s;]+/u', trim($v)) ?: [];
        }
        $allowed = $this->zoneCodes();
        $out = [];
        foreach ($raw as $z) {
            $z = trim((string)$z);
            if ($z !== '' && in_array($z, $allowed, true) && !in_array($z, $out, true)) {
                $out[] = $z;
            }
        }
        return $out;
    }

    public function setZones(int $cellId, string $primary, mixed $allZones, int $userId, string $userFio = 'Иванов И.И.', bool $hist = true): void
    {
        $primary = trim($primary);
        $this->assertZone($primary);
        $zones = $this->normalizeZoneList($allZones);
        if (!$zones) {
            $zones = [$primary];
        }
        if (!in_array($primary, $zones, true)) {
            $zones[] = $primary;
        }
        $extra = array_values(array_filter($zones, static fn($z) => $z !== $primary));
        $oldExtra = $this->extraZones($cellId);
        wms_cell_extra_zones_replace($this->pdo, $cellId, $extra);
        if ($hist && array_values($oldExtra) !== array_values($extra)) {
            $this->hist($cellId, $userId, $userFio, 'zones', 'Зоны', implode(', ', $oldExtra), implode(', ', $extra));
        }
    }

    public static function normalizeBeam(mixed $v): float
    {
        $f = (float)($v ?? 3.2);
        return $f <= 3.4 ? 3.2 : 3.6;
    }

    public static function normalizePalletTypes(mixed $v): string
    {
        $raw = trim((string)($v ?? 'euro,fin'));
        if ($raw === '' || $raw === 'Все' || $raw === '*') {
            return 'euro,fin';
        }
        $parts = [];
        foreach (preg_split('/[,\s;\/]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            $s = function_exists('mb_strtolower') ? mb_strtolower(trim($p), 'UTF-8') : strtolower(trim($p));
            if (in_array($s, ['евро', 'euro', 'eur', 'е'], true)) {
                $parts[] = 'euro';
            } elseif (in_array($s, ['фин', 'финская', 'fin', 'fan', 'ф'], true)) {
                $parts[] = 'fin';
            }
        }
        $parts = array_values(array_unique($parts));
        if (!$parts) {
            throw new RuntimeException('Типы тары: euro (евро) и/или fin (фин)');
        }
        return implode(',', $parts);
    }

    /** Тип тары в нормализованном виде: euro | fin (по умолчанию — euro). */
    public static function normalizePalletType(mixed $v): string
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower(trim((string)($v ?? '')), 'UTF-8') : strtolower(trim((string)($v ?? '')));
        if (in_array($s, ['евро', 'euro', 'eur', 'е'], true)) {
            return 'euro';
        }
        if (in_array($s, ['фин', 'финская', 'fin', 'fan', 'ф'], true)) {
            return 'fin';
        }
        return $s !== '' ? $s : 'euro';
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'NOW()' : "datetime('now')";
    }

    public function create(array $b, int $userId, string $userFio = 'Иванов И.И.'): int
    {
        $code = trim((string)($b['code'] ?? ''));
        if ($code === '') {
            throw new RuntimeException('Адрес ячейки обязателен');
        }
        if (empty($b['warehouse_id'])) {
            throw new RuntimeException('Склад обязателен');
        }
        $zone = (string)($b['zone'] ?? 'storage');
        $this->assertZone($zone);
        $type = (string)($b['cell_type'] ?? 'pallet');
        if (!in_array($type, ['pallet', 'box'], true)) {
            throw new RuntimeException('Недопустимый тип: pallet/box');
        }
        // Вместимость 0 (или пусто) = безразмерная ячейка, без лимита. Отрицательная запрещена.
        $this->assertCapacity($b['capacity'] ?? 0);
        $b = $this->normalizePlacementFields($b);
        $cols = [
            'code' => $code, 'warehouse_id' => (int)$b['warehouse_id'], 'zone' => $zone,
            'cell_type' => $type, 'status' => 'free', 'is_locked' => 0, 'is_archived' => 0,
        ];
        foreach (self::EDITABLE as $c) {
            if ($c === 'code' || $c === 'warehouse_id' || $c === 'zone' || $c === 'cell_type') {
                continue;
            }
            if (array_key_exists($c, $b)) {
                if ($c === 'capacity') {
                    $cols[$c] = ($b[$c] === '' || $b[$c] === null) ? 0 : $b[$c];
                } else {
                    $cols[$c] = $b[$c] === '' ? null : $b[$c];
                }
            }
        }
        $cols = array_intersect_key($cols, array_flip($this->tableColumns()));
        $keys = array_keys($cols);
        $st = $this->pdo->prepare(
            'INSERT INTO storage_cells (' . implode(',', $keys) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", $keys)) . ')'
        );
        try {
            $st->execute($cols);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'uplicate')) {
                throw new RuntimeException('Дубликат: такая ячейка уже есть на этом складе');
            }
            throw $e;
        }
        $id = (int)$this->pdo->lastInsertId();
        $this->hist($id, $userId, $userFio, 'create', 'Создание', null, $code);
        $this->setZones($id, $zone, $b['zones'] ?? [$zone], $userId, $userFio, false);
        // Дополнительные зоны отбора (ручной отбор / отбор водителем) — несколько на ячейку.
        $this->setPickZones($id, CellStatus::normalizePickZones($b['pick_zones'] ?? []), $userId, $userFio);
        return $id;
    }

    /**
     * Зоны отбора ячейки (дополнительные): manual=ручной отбор, driver=отбор водителем.
     * @param string[] $zones
     */
    public function setPickZones(int $cellId, array $zones, int $userId, string $userFio = 'Иванов И.И.', bool $hist = true): void
    {
        $zones = array_values(array_filter($zones, fn($z) => in_array($z, CellStatus::pickZones(), true)));
        $cur = $this->pickZones($cellId);
        if (array_values($cur) === array_values($zones)) {
            return;
        }
        try {
            $this->pdo->prepare('DELETE FROM storage_cell_zones WHERE cell_id = :c')->execute([':c' => $cellId]);
            foreach ($zones as $z) {
                $this->pdo->prepare('INSERT INTO storage_cell_zones (cell_id, zone) VALUES (:c, :z)')
                    ->execute([':c' => $cellId, ':z' => $z]);
            }
        } catch (Throwable) {
            return;
        }
        if ($hist) {
            $oldRu = implode(', ', array_map([CellStatus::class, 'pickZoneLabel'], $cur));
            $newRu = implode(', ', array_map([CellStatus::class, 'pickZoneLabel'], $zones));
            $this->hist($cellId, $userId, $userFio, 'pick_zones', 'Зоны отбора', $oldRu, $newRu);
        }
    }

    /** @return string[] коды зон отбора ячейки */
    public function pickZones(int $cellId): array
    {
        try {
            $st = $this->pdo->prepare('SELECT zone FROM storage_cell_zones WHERE cell_id = :c ORDER BY id');
            $st->execute([':c' => $cellId]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $z) {
                $z = (string)$z;
                if (!in_array($z, $out, true)) {
                    $out[] = $z;
                }
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    public function update(int $id, array $b, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $cur = $this->pdo->prepare("SELECT * FROM storage_cells WHERE id = :id");
        $cur->execute([':id' => $id]);
        $old = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            throw new RuntimeException('Ячейка не найдена');
        }
        $realCols = array_flip($this->tableColumns());
        $b = $this->normalizePlacementFields($b, true, $old);
        if (array_key_exists('capacity', $b)) {
            $this->assertCapacity($b['capacity']);
        }
        if (array_key_exists('zone', $b)) {
            $this->assertZone((string)$b['zone']);
        }
        $set = [];
        $params = [':id' => $id];
        foreach (self::EDITABLE as $c) {
            if (!isset($realCols[$c])) {
                continue;
            }
            if (array_key_exists($c, $b)) {
                if ($c === 'capacity') {
                    $this->assertCapacity($b[$c]);
                    $nv = ($b[$c] === '' || $b[$c] === null) ? 0 : $b[$c];
                } else {
                    $nv = $b[$c] === '' ? null : $b[$c];
                }
                $ov = $old[$c] ?? null;
                if ((string)$nv !== (string)$ov) {
                    $set[] = "$c = :$c";
                    $params[":$c"] = $nv;
                    $this->hist($id, $userId, $userFio, (string)$c, self::LABELS[$c] ?? $c,
                        $ov === null ? null : (string)$ov, $nv === null ? null : (string)$nv);
                }
            }
        }
        if ($set) {
            $this->pdo->prepare('UPDATE storage_cells SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
        if (array_key_exists('zones', $b) || array_key_exists('zone', $b)) {
            $primary = array_key_exists('zone', $b)
                ? trim((string)$b['zone'])
                : trim((string)($old['zone'] ?? 'storage'));
            $all = array_key_exists('zones', $b)
                ? $b['zones']
                : array_merge($this->extraZones($id), [$primary]);
            $this->setZones($id, $primary, $all, $userId, $userFio);
        }
        // Дополнительные зоны отбора (несколько зон у ячейки).
        if (array_key_exists('pick_zones', $b)) {
            $this->setPickZones($id, CellStatus::normalizePickZones($b['pick_zones']), $userId, $userFio);
        }
    }

    public function copy(int $id, int $userId, string $userFio = 'Иванов И.И.'): int
    {
        $cur = $this->pdo->prepare("SELECT * FROM storage_cells WHERE id = :id");
        $cur->execute([':id' => $id]);
        $old = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            throw new RuntimeException('Ячейка не найдена');
        }
        unset($old['id']);
        $old['code'] = $old['code'] . '-COPY';
        $old['qty'] = 0;
        $old['reserved_qty'] = 0;
        $old['pallet_count'] = 0;
        $old['status'] = 'free';
        $old['is_locked'] = 0;
        // Тип паллеты — тоже сбрасываем: копия ячейки ПУСТАЯ. Если оставить
        // pallet_type, CellPlacement::isOccupied() считает копию занятой
        // (она «съедает» место на балке) и запрещает её как цель перемещения.
        $old['pallet_type'] = null;
        $old['lock_reason'] = null;
        unset($old['created_at'], $old['updated_at']);
        $old = array_intersect_key($old, array_flip($this->tableColumns()));
        $keys = array_keys($old);
        $st = $this->pdo->prepare(
            'INSERT INTO storage_cells (' . implode(',', $keys) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", $keys)) . ')'
        );
        $st->execute($old);
        $nid = (int)$this->pdo->lastInsertId();
        $this->hist($nid, $userId, $userFio, 'copy', 'Копирование', (string)$id, $old['code']);
        // Копия наследует зоны отбора и дополнительные зоны.
        $this->setPickZones($nid, $this->pickZones($id), $userId, $userFio, false);
        $this->setZones($nid, (string)($old['zone'] ?? 'storage'),
            array_merge($this->extraZones($id), [(string)($old['zone'] ?? 'storage')]), $userId, $userFio, false);
        return $nid;
    }

    public function block(int $id, int $userId, string $reason = '', string $userFio = 'Иванов И.И.'): void
    {
        $this->guardExists($id);
        $this->pdo->prepare("UPDATE storage_cells SET is_locked = 1, status = 'blocked', lock_reason = :r WHERE id = :id")
            ->execute([':r' => $reason !== '' ? $reason : null, ':id' => $id]);
        $this->hist($id, $userId, $userFio, 'is_locked', 'Блокировка', 'Нет', 'Да' . ($reason !== '' ? " ($reason)" : ''));
    }

    public function unblock(int $id, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $cur = $this->pdo->prepare("SELECT qty FROM storage_cells WHERE id = :id");
        $cur->execute([':id' => $id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Ячейка не найдена');
        }
        $st = ((float)$row['qty'] > 0) ? 'busy' : 'free';
        $this->pdo->prepare("UPDATE storage_cells SET is_locked = 0, lock_reason = NULL, status = :s WHERE id = :id")
            ->execute([':s' => $st, ':id' => $id]);
        $this->hist($id, $userId, $userFio, 'is_locked', 'Блокировка', 'Да', 'Нет');
    }

    public function archive(int $id, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $cur = $this->pdo->prepare("SELECT qty FROM storage_cells WHERE id = :id");
        $cur->execute([':id' => $id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Ячейка не найдена');
        }
        if ((float)$row['qty'] != 0.0) {
            throw new RuntimeException('Нельзя архивировать: в ячейке есть товар. Сначала переместите его.');
        }
        $this->pdo->prepare("UPDATE storage_cells SET is_archived = 1 WHERE id = :id")->execute([':id' => $id]);
        $this->hist($id, $userId, $userFio, 'is_archived', 'Архив', 'Нет', 'Да');
    }

    /**
     * Физическое удаление пустой ячейки (ПКМ "Удалить" / клавиша Delete).
     * Связанные содержимое/история/движения удаляются каскадом (FK ON DELETE CASCADE,
     * в SQLite — явными DELETE для старых баз без FK).
     */
    public function delete(int $id): void
    {
        $cur = $this->pdo->prepare("SELECT id, code, qty, reserved_qty FROM storage_cells WHERE id = :id");
        $cur->execute([':id' => $id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Ячейка не найдена');
        }
        if ((float)($row['qty'] ?? 0) != 0.0 || (float)($row['reserved_qty'] ?? 0) != 0.0) {
            throw new RuntimeException('Нельзя удалить: в ячейке есть товар или резерв (' . ($row['code'] ?? $id) . '). Сначала переместите его.');
        }
        foreach (['cell_content', 'cell_history', 'cell_movements', 'storage_cell_zones', 'storage_cell_extra_zones'] as $t) {
            try {
                $this->pdo->prepare("DELETE FROM $t WHERE cell_id = :id")->execute([':id' => $id]);
            } catch (Throwable) {
            }
        }
        $this->pdo->prepare("DELETE FROM storage_cells WHERE id = :id")->execute([':id' => $id]);
    }

    /**
     * Массовое удаление пустых ячеек (Delete по нескольким галкам).
     * @param int[] $ids
     * @return array{deleted:int[],errors:string[]}
     */
    public function deleteMany(array $ids): array
    {
        $deleted = [];
        $errors = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                $this->delete($id);
                $deleted[] = $id;
            } catch (Throwable $e) {
                $errors[] = "id $id: " . $e->getMessage();
            }
        }
        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * ПКМ "Переместить товар": списать qty с ячейки-источника, начислить на приемник.
     * Содержимое (cell_content) едет вместе с остатком; в Номенклатуру движение
     * пробрасывается best-effort через product_stock (не блокирует операцию).
     * @return array{nom_sync: array}
     */
    public function moveGoods(int $fromId, string $toCode, string $sku, float $qty, int $userId, string $docNo = '', string $userFio = 'Иванов И.И.'): array
    {
        if ($qty <= 0) {
            throw new RuntimeException('Количество должно быть > 0');
        }
        $from = $this->pdo->prepare("SELECT * FROM storage_cells WHERE id = :id");
        $from->execute([':id' => $fromId]);
        $src = $from->fetch(PDO::FETCH_ASSOC);
        if (!$src) {
            throw new RuntimeException('Ячейка-источник не найдена');
        }
        if ((int)($src['is_locked'] ?? 0) === 1) {
            throw new RuntimeException('Источник заблокирован');
        }
        $to = $this->pdo->prepare("SELECT * FROM storage_cells WHERE code = :c AND warehouse_id = :w LIMIT 1");
        $to->execute([':c' => $toCode, ':w' => $src['warehouse_id']]);
        $dst = $to->fetch(PDO::FETCH_ASSOC);
        if (!$dst) {
            throw new RuntimeException('Ячейка-приемник не найдена на этом складе: ' . $toCode);
        }
        if ((int)($dst['is_locked'] ?? 0) === 1) {
            throw new RuntimeException('Приемник заблокирован');
        }
        if ((float)($src['qty'] ?? 0) < $qty) {
            throw new RuntimeException('Недостаточно товара в источнике: ' . $src['qty']);
        }
        $docNo = $docNo !== '' ? $docNo : ('ПР-' . date('Ymd-His'));
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $newSrc = round((float)$src['qty'] - $qty, 3);
            $newDst = round((float)$dst['qty'] + $qty, 3);
            $this->pdo->prepare("UPDATE storage_cells SET qty = :q, status = :s WHERE id = :id")->execute([
                ':q' => $newSrc, ':s' => CellStatus::actual((string)$src['status'], $newSrc), ':id' => $src['id'],
            ]);
            $this->pdo->prepare("UPDATE storage_cells SET qty = :q, status = :s WHERE id = :id")->execute([
                ':q' => $newDst, ':s' => CellStatus::actual((string)$dst['status'], $newDst), ':id' => $dst['id'],
            ]);
            // Содержимое: списать/начислить строку SKU.
            $line = $this->pdo->prepare("SELECT * FROM cell_content WHERE cell_id = :c AND sku = :s LIMIT 1");
            $line->execute([':c' => $src['id'], ':s' => $sku]);
            $sLine = $line->fetch(PDO::FETCH_ASSOC);
            if (!$sLine) {
                // Нет позиции этого товара в содержимом источника — перемещение
                // не отразится в cell_content, и содержимое разойдётся с ячейкой.
                throw new RuntimeException('В содержимом ячейки-источника нет товара ' . $sku);
            }
            if ((float)$sLine['qty'] < $qty) {
                throw new RuntimeException('Недостаточно товара ' . $sku . ' в ячейке-источнике: ' . $sLine['qty']);
            }
            {
                $this->pdo->prepare("UPDATE cell_content SET qty = qty - :q WHERE id = :id")
                    ->execute([':q' => $qty, ':id' => $sLine['id']]);
                $line->execute([':c' => $dst['id'], ':s' => $sku]);
                $dLine = $line->fetch(PDO::FETCH_ASSOC);
                if ($dLine) {
                    $this->pdo->prepare("UPDATE cell_content SET qty = qty + :q WHERE id = :id")
                        ->execute([':q' => $qty, ':id' => $dLine['id']]);
                } else {
                    $this->pdo->prepare(
                        "INSERT INTO cell_content (cell_id,product_code,sku,name,unit,qty,barcode,pallet) VALUES (:c,:pc,:s,:n,:u,:q,:bc,:p)"
                    )->execute([
                        ':c' => $dst['id'], ':pc' => $sLine['product_code'] ?? null, ':s' => $sku,
                        ':n' => $sLine['name'] ?? $sku, ':u' => $sLine['unit'] ?? 'шт',
                        ':q' => $qty, ':bc' => $sLine['barcode'] ?? null, ':p' => $sLine['pallet'] ?? null,
                    ]);
                }
            }
            $mv = $this->pdo->prepare(
                "INSERT INTO cell_movements (cell_id,doc_date,doc_type,doc_no,sku,qty_change,balance,cell_from,cell_to)
                 VALUES (:c,$now,'move',:d,:s,:q,:b,:f,:t)"
            );
            $mv->execute([':c' => $src['id'], ':d' => $docNo, ':s' => $sku, ':q' => -$qty, ':b' => $newSrc, ':f' => $src['code'], ':t' => $dst['code']]);
            $mv->execute([':c' => $dst['id'], ':d' => $docNo, ':s' => $sku, ':q' => $qty, ':b' => $newDst, ':f' => $src['code'], ':t' => $dst['code']]);
            // Освободили ячейку полностью — сбрасываем тип паллеты и пересчитываем
            // временные блокировки яруса ("временно занята" при 3 финах на 3.6).
            if ($newSrc <= 0) {
                $this->pdo->prepare("UPDATE storage_cells SET pallet_type = NULL, pallet_count = 0 WHERE id = :id")
                    ->execute([':id' => $src['id']]);
                try {
                    require_once __DIR__ . '/CellPlacement.php';
                    CellPlacement::syncTempBlocks($this->pdo, (int)$src['warehouse_id'],
                        $src['building'] ?? null, $src['row_no'] ?? null, $src['level_no'] ?? null);
                } catch (Throwable) {
                }
            }
            $this->hist((int)$src['id'], $userId, $userFio, 'move', 'Перемещение', $src['code'], $dst['code'] . ' · ' . $sku . ' x' . $qty);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Журнал склада (модуль 14): перемещение паллеты/товара между ячейками.
        // Пишем рядом с cell_movements (там же смотрим источник движения),
        // но уже после фиксации перемещения в своей транзакции.
        try {
            require_once __DIR__ . '/../shared/Journal.php';
            WmsJournal::log(wms_journal_pdo(), [
                'op_type' => 'перемещение',
                'doc_type' => 'Перемещение',
                'doc_no' => $docNo,
                'sku' => $sku,
                'product_name' => (string)($sLine['name'] ?? ''),
                'qty' => $qty,
                'pallet' => (string)($sLine['pallet'] ?? ''),
                'cell_from' => (string)$src['code'],
                'cell_to' => (string)$dst['code'],
                'zone' => 'Хранение',
                'user_id' => $userId,
                'user_fio' => $userFio,
                'comment' => 'Перемещение товара между ячейками',
            ]);
        } catch (Throwable) {
        }

        // Best-effort: отразить смену ячейки в Номенклатуре (product_stock.cell).
        $nomSync = ['synced' => false, 'note' => 'sku не найден в номенклатуре или БД недоступна'];
        try {
            require_once __DIR__ . '/../shared/db.php';
            $nom = wms_nomenclature_pdo();
            $p = $nom->prepare("SELECT id FROM products WHERE sku = :s LIMIT 1");
            $p->execute([':s' => $sku]);
            $pid = $p->fetchColumn();
            if ($pid) {
                // Переносим ТОЛЬКО перемещённое количество: строки запаса этого
                // SKU в исходной ячейке по очереди (по паллетам), а не все строки
                // целиком — иначе перемещение 3 шт из ячейки уводило в приёмник
                // ВСЕ паллеты этого товара.
                $rows = $nom->prepare(
                    "SELECT id, pallet, pallet_type, receipt_id, qty FROM product_stock
                     WHERE product_id = :p AND warehouse_id = :w AND cell = :f ORDER BY id"
                );
                $rows->execute([':p' => $pid, ':w' => $src['warehouse_id'], ':f' => $src['code']]);
                $rest = (float)$qty;
                $moved = 0;
                foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    if ($rest <= 1e-9) {
                        break;
                    }
                    $rq = (float)$r['qty'];
                    if ($rq <= 1e-9) {
                        continue;
                    }
                    $take = min($rq, $rest);
                    if ($take >= $rq - 1e-9) {
                        // Строка переезжает целиком.
                        $nom->prepare("UPDATE product_stock SET cell = :t WHERE id = :id")
                            ->execute([':t' => $dst['code'], ':id' => $r['id']]);
                    } else {
                        // Часть строки: в источнике убавляем, в приёмнике начисляем.
                        $nom->prepare("UPDATE product_stock SET qty = qty - :q WHERE id = :id")
                            ->execute([':q' => $take, ':id' => $r['id']]);
                        $d = $nom->prepare(
                            "SELECT id FROM product_stock WHERE product_id = :p AND warehouse_id = :w
                             AND cell = :c AND COALESCE(pallet,'') = :pal LIMIT 1"
                        );
                        $d->execute([':p' => $pid, ':w' => $src['warehouse_id'], ':c' => $dst['code'],
                            ':pal' => (string)($r['pallet'] ?? '')]);
                        if ($dId = $d->fetchColumn()) {
                            $nom->prepare("UPDATE product_stock SET qty = qty + :q WHERE id = :id")
                                ->execute([':q' => $take, ':id' => (int)$dId]);
                        } else {
                            $ins = $nom->prepare(
                                "INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,pallet_type,qty"
                                . ($r['receipt_id'] !== null ? ',receipt_id' : '') . ") VALUES (:p,:w,:c,:pal,:pt,:q"
                                . ($r['receipt_id'] !== null ? ',:r' : '') . ")"
                            );
                            $ip = [':p' => $pid, ':w' => $src['warehouse_id'], ':c' => $dst['code'],
                                ':pal' => $r['pallet'] ?? null, ':pt' => $r['pallet_type'] ?? null, ':q' => $take];
                            if ($r['receipt_id'] !== null) {
                                $ip[':r'] = $r['receipt_id'];
                            }
                            try {
                                $ins->execute($ip);
                            } catch (Throwable) {
                                $nom->prepare(
                                    "INSERT INTO product_stock (product_id,warehouse_id,cell,pallet,pallet_type,qty)
                                     VALUES (:p,:w,:c,:pal,:pt,:q)"
                                )->execute([':p' => $pid, ':w' => $src['warehouse_id'], ':c' => $dst['code'],
                                    ':pal' => $r['pallet'] ?? null, ':pt' => $r['pallet_type'] ?? null, ':q' => $take]);
                            }
                        }
                    }
                    $rest = round($rest - $take, 3);
                    $moved++;
                }
                $nomSync = ['synced' => true, 'updated' => $moved, 'qty' => round((float)$qty - max(0, $rest), 3)];
            }
        } catch (Throwable $e) {
            $nomSync = ['synced' => false, 'error' => $e->getMessage()];
        }
        return ['nom_sync' => $nomSync, 'doc_no' => $docNo];
    }

    /** @param int[] $ids */
    public function massUpdate(array $ids, array $patch, int $userId, string $userFio = 'Иванов И.И.'): int
    {
        $allowed = ['status', 'zone', 'cell_type', 'category', 'allowed_goods', 'is_locked',
            'beam_width', 'cell_height_mm', 'allowed_pallet_types', 'pick_zones',
            'allow_multi_pallet_types', 'allow_mixed_goods'];
        $patch = array_intersect_key($patch, array_flip($allowed));
        if (!$ids || !$patch) {
            return 0;
        }
        if (isset($patch['status']) && !in_array($patch['status'], CellStatus::values(), true)) {
            throw new RuntimeException('Недопустимый статус');
        }
        $n = 0;
        // Зоны отбора — отдельная таблица (несколько зон у ячейки).
        $hasPickZones = array_key_exists('pick_zones', $patch);
        $pickZones = $hasPickZones ? CellStatus::normalizePickZones($patch['pick_zones']) : [];
        if ($hasPickZones) {
            unset($patch['pick_zones']);
        }
        foreach ($ids as $id) {
            $id = (int)$id;
            $cur = $this->pdo->prepare('SELECT * FROM storage_cells WHERE id = :id');
            $cur->execute([':id' => $id]);
            $old = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$old) {
                continue;
            }
            if ($hasPickZones) {
                $this->setPickZones($id, $pickZones, $userId, $userFio);
            }
            $set = [];
            $params = [':id' => $id];
            foreach ($patch as $k => $v) {
                $nv = $v === '' ? null : $v;
                if ((string)($old[$k] ?? '') !== (string)$nv) {
                    $set[] = "$k = :$k";
                    $params[":$k"] = $nv;
                    $this->hist($id, $userId, $userFio, 'mass', self::LABELS[$k] ?? $k,
                        (string)($old[$k] ?? ''), (string)($nv ?? ''));
                }
            }
            if ($set) {
                $this->pdo->prepare('UPDATE storage_cells SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
            }
            $n++;
        }
        return $n;
    }

    public function printData(int $id, string $kind = 'label'): array
    {
        $st = $this->pdo->prepare("SELECT c.*, w.name AS warehouse FROM storage_cells c LEFT JOIN warehouses w ON w.id = c.warehouse_id WHERE c.id = :id");
        $st->execute([':id' => $id]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            throw new RuntimeException('Ячейка не найдена');
        }
        return ['kind' => $kind, 'code' => $c['code'], 'warehouse' => $c['warehouse'] ?? '',
            'zone' => CellStatus::zoneLabel($c['zone'] ?? null), 'type' => CellStatus::typeLabel($c['cell_type'] ?? null),
            'pick_zones' => implode(', ', array_map(fn($z) => CellStatus::pickZoneLabel($z), $this->pickZones((int)$id)))];
    }

    public function exportCsv(CellFilter $f, CellRepository $repo): string
    {
        $f->page = 1;
        $f->perPage = 5000;
        $data = $repo->paginate($f);
        $head = ['Адрес', 'Склад', 'Зона', 'Зоны отбора', 'Тип', 'Балка', 'Высота', 'Тара', 'Категория', 'Статус', 'Заполненность %', 'В ячейке', 'Резерв', 'Остаток', 'Паллет', 'Темп. режим', 'Блокировка'];
        $lines = ["\xEF\xBB\xBF" . implode(';', $head)];
        foreach ($data['rows'] as $r) {
            $lines[] = implode(';', [
                $r['code'] ?? '', str_replace(';', ',', (string)($r['warehouse'] ?? '')),
                $r['zone_label'] ?? '', $r['pick_zones_label'] ?? '', $r['type_label'] ?? '',
                $r['beam_label'] ?? '', $r['height_label'] ?? '', $r['pallet_types_label'] ?? '',
                str_replace(';', ',', (string)($r['category'] ?? '')),
                $r['status_label'] ?? '', !empty($r['is_unlimited']) ? '∞' : (string)($r['fill_pct'] ?? 0),
                (string)($r['qty'] ?? 0), (string)($r['reserved_qty'] ?? 0),
                (string)($r['qty'] ?? 0), (string)($r['pallet_count'] ?? 0),
                $r['temp_label'] ?? '', $r['lock_label'] ?? '',
            ]);
        }
        return implode("\n", $lines);
    }

    /**
     * Массовое создание ячеек по диапазонам (кнопка "+ Создать").
     * Вход: letter ('' или 1 буква), *_from/*_to — строки из 1-3 цифр ("01", "001").
     * Ширина блока с нулями = max(strlen(from), strlen(to)), код: [{L}ROW]-SEC-LVL-PLACE.
     * Пример: A + 01..02, 01..01, 01..01, 01..02 => A01-01-01-01 ... A02-01-01-02.
     * @return array{created:int,skipped:int,total:int}
     */
    public function bulkCreate(array $b, int $userId, string $userFio = 'Иванов И.И.'): array
    {
        $letter = trim((string)($b['letter'] ?? ''));
        $letter = function_exists('mb_strtoupper') ? mb_strtoupper($letter, 'UTF-8') : strtoupper($letter);
        if ($letter !== '' && !preg_match('/^[A-ZА-ЯЁ]$/u', $letter)) {
            throw new RuntimeException('Буква: одна буква A-Z / А-Я или пусто');
        }

        $ranges = [];
        foreach (['row' => 'ряда', 'sec' => 'секции', 'level' => 'уровня', 'place' => 'ячейки'] as $k => $label) {
            $f = trim((string)($b[$k . '_from'] ?? ''));
            $t = trim((string)($b[$k . '_to'] ?? ''));
            if ($f === '' || $t === '') {
                throw new RuntimeException("Код $label: укажите «от» и «до»");
            }
            if (!preg_match('/^\d{1,3}$/', $f) || !preg_match('/^\d{1,3}$/', $t)) {
                throw new RuntimeException("Код $label: только 1–3 цифры (0–999)");
            }
            $fi = (int)$f;
            $ti = (int)$t;
            if ($fi > $ti) {
                throw new RuntimeException("Код $label: «от» больше «до»");
            }
            $ranges[$k] = ['from' => $fi, 'to' => $ti, 'width' => max(strlen($f), strlen($t))];
        }

        $total = ($ranges['row']['to'] - $ranges['row']['from'] + 1)
            * ($ranges['sec']['to'] - $ranges['sec']['from'] + 1)
            * ($ranges['level']['to'] - $ranges['level']['from'] + 1)
            * ($ranges['place']['to'] - $ranges['place']['from'] + 1);
        if ($total < 1) {
            throw new RuntimeException('Пустой диапазон');
        }
        if ($total > 5000) {
            throw new RuntimeException("Слишком много ячеек: $total (лимит 5000 за раз). Сузьте диапазоны.");
        }

        if (empty($b['warehouse_id'])) {
            throw new RuntimeException('Склад обязателен');
        }
        $warehouseId = (int)$b['warehouse_id'];
        $zone = (string)($b['zone'] ?? 'storage');
        $this->assertZone($zone);
        $type = (string)($b['cell_type'] ?? 'pallet');
        if (!in_array($type, ['pallet', 'box'], true)) {
            throw new RuntimeException('Недопустимый тип: pallet/box');
        }

        $pad = static fn(int $v, int $w): string => str_pad((string)$v, $w, '0', STR_PAD_LEFT);
        $codes = [];
        for ($r = $ranges['row']['from']; $r <= $ranges['row']['to']; $r++) {
            $rs = $pad($r, $ranges['row']['width']);
            $first = $letter . $rs;
            $building = $first !== '' ? $first : $rs;
            for ($s = $ranges['sec']['from']; $s <= $ranges['sec']['to']; $s++) {
                $ss = $pad($s, $ranges['sec']['width']);
                for ($l = $ranges['level']['from']; $l <= $ranges['level']['to']; $l++) {
                    $ls = $pad($l, $ranges['level']['width']);
                    for ($p = $ranges['place']['from']; $p <= $ranges['place']['to']; $p++) {
                        $ps = $pad($p, $ranges['place']['width']);
                        $code = $first . '-' . $ss . '-' . $ls . '-' . $ps;
                        $codes[] = ['code' => $code, 'building' => $building,
                            'row_no' => $ss, 'level_no' => $ls, 'place_no' => $ps];
                    }
                }
            }
        }

        // Уже существующие коды на этом складе — пропустим как дубликаты.
        $existing = [];
        foreach (array_chunk(array_column($codes, 'code'), 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $this->pdo->prepare(
                "SELECT code FROM storage_cells WHERE warehouse_id = ? AND code IN ($in)"
            );
            $st->execute([$warehouseId, ...$chunk]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                $existing[$c] = true;
            }
        }

        // Вместимость 0 (или пусто) = безразмерная ячейка, без лимита.
        $this->assertCapacity($b['capacity'] ?? 0);
        // Ширина балки — одна на всё создание (около ряда): 3.2 или 3.6.
        $beam = self::normalizeBeam($b['beam_width'] ?? 3.2);
        $norm = $this->normalizePlacementFields($b, true);
        $realCols = array_flip($this->tableColumns());
        $base = [
            'warehouse_id' => $warehouseId, 'zone' => $zone, 'cell_type' => $type,
            'status' => 'free', 'is_locked' => 0, 'is_archived' => 0,
            'category' => isset($b['category']) && $b['category'] !== '' ? $b['category'] : null,
            'capacity' => isset($b['capacity']) && $b['capacity'] !== '' ? $b['capacity'] : 0,
            'temp_from' => isset($b['temp_from']) && $b['temp_from'] !== '' ? $b['temp_from'] : null,
            'temp_to' => isset($b['temp_to']) && $b['temp_to'] !== '' ? $b['temp_to'] : null,
            'comment' => isset($b['comment']) && $b['comment'] !== '' ? $b['comment'] : null,
            'beam_width' => $beam,
            'cell_height_mm' => $norm['cell_height_mm'] ?? null,
            'allowed_pallet_types' => $norm['allowed_pallet_types'] ?? 'euro,fin',
            'allow_multi_pallet_types' => $norm['allow_multi_pallet_types'] ?? 1,
            'allow_mixed_goods' => $norm['allow_mixed_goods'] ?? 1,
        ];
        $base = array_intersect_key($base, $realCols);

        $created = 0;
        $skipped = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($codes as $c) {
                if (isset($existing[$c['code']])) {
                    $skipped++;
                    continue;
                }
                $cols = $base + [
                    'code' => $c['code'], 'building' => $c['building'],
                    'row_no' => $c['row_no'], 'level_no' => $c['level_no'], 'place_no' => $c['place_no'],
                ];
                $cols = array_intersect_key($cols, $realCols);
                $keys = array_keys($cols);
                $st = $this->pdo->prepare(
                    'INSERT INTO storage_cells (' . implode(',', $keys) . ') VALUES ('
                    . implode(',', array_map(fn($k) => ":$k", $keys)) . ')'
                );
                try {
                    $st->execute($cols);
                } catch (PDOException $e) {
                    if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'uplicate')) {
                        $skipped++;
                        continue;
                    }
                    throw $e;
                }
                $created++;
                $newId = (int)$this->pdo->lastInsertId();
                $this->hist($newId, $userId, $userFio, 'create', 'Создание', null, $c['code']);
                $this->setZones($newId, $zone, $b['zones'] ?? [$zone], $userId, $userFio, false);
                $existing[$c['code']] = true;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['created' => $created, 'skipped' => $skipped, 'total' => $total];
    }

    /** Массовое создание из строк (кнопка "Импорт"): возвращает [created, errors]. */
    public function importRows(array $rows, int $userId): array
    {
        $ok = 0;
        $errs = [];
        foreach ($rows as $i => $r) {
            try {
                $this->create([
                    'code' => trim((string)($r['code'] ?? $r['адрес'] ?? '')),
                    'warehouse_id' => (int)($r['warehouse_id'] ?? 1),
                    'zone' => trim((string)($r['zone'] ?? 'storage')),
                    'cell_type' => trim((string)($r['cell_type'] ?? 'pallet')),
                    'category' => trim((string)($r['category'] ?? '')) ?: null,
                    'capacity' => (float)($r['capacity'] ?? 0),
                ], $userId);
                $ok++;
            } catch (Throwable $e) {
                $errs[] = 'Строка ' . ($i + 1) . ': ' . $e->getMessage();
            }
        }
        return ['created' => $ok, 'errors' => $errs];
    }

    private function guardExists(int $id): void
    {
        $st = $this->pdo->prepare("SELECT id FROM storage_cells WHERE id = :id");
        $st->execute([':id' => $id]);
        if (!$st->fetchColumn()) {
            throw new RuntimeException('Ячейка не найдена');
        }
    }

    /** Вместимость 0 (или пусто) = безразмерная ячейка, без лимита. Отрицательная запрещена. */
    private function assertCapacity(mixed $v): void
    {
        if ($v === null || $v === '') {
            return;
        }
        if (!is_numeric($v) || (float)$v < 0) {
            throw new RuntimeException('Вместимость не может быть отрицательной (0 — безразмерная)');
        }
    }

    /**
     * Нормализация полей размещения: ширина балки (3.2/3.6), высота ячейки (мм),
     * разрешенные типы тары (euro/fin), фактический тип паллеты, флаги настроек
     * ячейки (несколько типов паллет / разные товары в одной ячейке).
     * $partial=true — нормализовать только переданные ключи (для update).
     * $old — текущая строка ячейки (для update): нужна, чтобы галка «Несколько типов
     * паллет» знала текущее состояние и закреплённый тип тары.
     */
    private function normalizePlacementFields(array $b, bool $partial = false, ?array $old = null): array
    {
        $out = $b;
        if (!$partial || array_key_exists('beam_width', $b)) {
            $out['beam_width'] = self::normalizeBeam($b['beam_width'] ?? 3.2);
        }
        if (!$partial || array_key_exists('allowed_pallet_types', $b)) {
            if (!$partial || ($b['allowed_pallet_types'] ?? null) !== null) {
                $out['allowed_pallet_types'] = self::normalizePalletTypes($b['allowed_pallet_types'] ?? 'euro,fin');
            }
        }
        if (array_key_exists('cell_height_mm', $b)) {
            $v = $b['cell_height_mm'];
            if ($v === '' || $v === null) {
                $out['cell_height_mm'] = null;
            } else {
                if (!is_numeric($v) || (int)$v <= 0) {
                    throw new RuntimeException('Высота ячейки должна быть > 0 мм');
                }
                $out['cell_height_mm'] = (int)$v;
            }
        }
        if (array_key_exists('pallet_type', $b)) {
            $v = trim((string)($b['pallet_type'] ?? ''));
            if ($v === '' || $v === null) {
                $out['pallet_type'] = null;
            } else {
                $s = function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
                if (in_array($s, ['евро', 'euro', 'eur', 'е'], true)) {
                    $out['pallet_type'] = 'euro';
                } elseif (in_array($s, ['фин', 'финская', 'fin', 'fan', 'ф'], true)) {
                    $out['pallet_type'] = 'fin';
                } else {
                    throw new RuntimeException('Тип паллеты: euro (евро) или fin (фин)');
                }
            }
        }
        // Флаги настроек ячейки: 0/1 (для булевых значений из JSON).
        foreach (['allow_multi_pallet_types', 'allow_mixed_goods'] as $flag) {
            if (array_key_exists($flag, $out)) {
                $out[$flag] = ((int)$out[$flag] === 1) ? 1 : 0;
            }
        }
        // Связка «несколько типов паллет» ↔ allowed_pallet_types:
        //   галка ВКЛ  → в ячейке допустимы оба типа (euro,fin);
        //   галка ВЫКЛ → в ячейке только один тип: закреплённый (pallet_type) либо euro.
        $multi = null;
        if (array_key_exists('allow_multi_pallet_types', $out)) {
            $multi = ((int)$out['allow_multi_pallet_types'] === 1);
        } elseif ($old !== null) {
            $multi = ((int)($old['allow_multi_pallet_types'] ?? 1) === 1);
        }
        if ($multi !== null) {
            $out['allow_multi_pallet_types'] = $multi ? 1 : 0;
            if ($multi) {
                $out['allowed_pallet_types'] = 'euro,fin';
            } else {
                $single = '';
                if (array_key_exists('allowed_pallet_types', $out)
                    && $out['allowed_pallet_types'] !== null && $out['allowed_pallet_types'] !== '') {
                    $parts = explode(',', (string)$out['allowed_pallet_types']);
                    if (count($parts) === 1) {
                        $single = trim($parts[0]);
                    }
                }
                if ($single === '') {
                    // Текущий фактический тип паллеты — иначе euro по умолчанию.
                    $cur = array_key_exists('pallet_type', $out)
                        ? (string)($out['pallet_type'] ?? '')
                        : (string)($old['pallet_type'] ?? '');
                    $single = self::normalizePalletType($cur);
                    if ($single !== 'euro' && $single !== 'fin') {
                        $single = 'euro';
                    }
                }
                if ($single !== 'euro' && $single !== 'fin') {
                    throw new RuntimeException('Закреплённый тип тары: euro (евро) или fin (фин)');
                }
                $out['allowed_pallet_types'] = $single;
            }
        }
        return $out;
    }

    private ?array $colsCache = null;

    private function tableColumns(): array
    {
        if ($this->colsCache !== null) {
            return $this->colsCache;
        }
        try {
            $drv = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $out = [];
                foreach ($this->pdo->query("PRAGMA table_info(storage_cells)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $out[] = $c['name'];
                }
                return $this->colsCache = $out;
            }
            if ($drv === 'pgsql') {
                $st = $this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name='storage_cells'");
                $st->execute();
                return $this->colsCache = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
            }
            $st = $this->pdo->query("SHOW COLUMNS FROM storage_cells");
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $out[] = $c['Field'];
            }
            return $this->colsCache = $out;
        } catch (Throwable) {
            return $this->colsCache = array_merge(self::EDITABLE, ['status', 'is_locked', 'is_archived', 'qty', 'reserved_qty', 'pallet_count']);
        }
    }

    private function hist(int $cid, int $uid, string $fio, string $field, string $label, ?string $old, ?string $new): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO cell_history (cell_id, user_id, user_fio, action, field, field_label, old_value, new_value) VALUES (:c,:u,:f,'update',:fd,:fl,:o,:n)"
            )->execute([':c' => $cid, ':u' => $uid, ':f' => $fio, ':fd' => $field, ':fl' => $label, ':o' => $old, ':n' => $new]);
        } catch (Throwable) {
        }
    }
}
