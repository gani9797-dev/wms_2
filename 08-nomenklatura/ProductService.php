<?php
declare(strict_types=1);

require_once __DIR__ . '/../shared/ProductCode.php';

// Сервис карточек номенклатуры: создание / редактирование / копирование / блокировка / архив / печать / экспорт / импорт.
// Каждое изменение значимого поля пишется в product_history (вкладка "История").
final class ProductService
{
    public function __construct(private PDO $pdo) {}

    private const EDITABLE = [
        'code', 'sku', 'name', 'unit', 'category', 'brand', 'owner', 'warehouse_id',
        'description', 'image_path', 'length_mm', 'width_mm', 'height_mm', 'weight_g',
        'volume_m3', 'qty_in_box', 'boxes_per_pallet', 'storage_strategy', 'pick_strategy',
        'placement_strategy',
        'temp_from', 'temp_to', 'shelf_life_days', 'marking_start', 'chz_group',
        'max_expiry_pct', 'is_batch', 'is_expiry', 'is_serial', 'is_chz', 'is_danger',
        'allow_partial_pick', 'need_check', 'need_pack', 'auto_replenish', 'need_measure', 'quality', 'qty', 'reserved_qty',
        'is_active',
    ];

    private const LABELS = [
        'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование', 'unit' => 'Ед. изм.',
        'category' => 'Категория', 'brand' => 'Бренд', 'owner' => 'Владелец',
        'warehouse_id' => 'Склад', 'description' => 'Описание', 'length_mm' => 'Длина',
        'width_mm' => 'Ширина', 'height_mm' => 'Высота', 'weight_g' => 'Вес',
        'volume_m3' => 'Объем', 'qty_in_box' => 'Штук в коробе', 'boxes_per_pallet' => 'Коробов на паллете',
        'storage_strategy' => 'Стратегия хранения', 'pick_strategy' => 'Стратегия отбора',
        'placement_strategy' => 'Стратегия размещения',
        'temp_from' => 'Темп. от', 'temp_to' => 'Темп. до', 'shelf_life_days' => 'Срок годности',
        'marking_start' => 'Начало маркировки', 'chz_group' => 'Группа ЧЗ',
        'max_expiry_pct' => 'Макс % истечения', 'is_batch' => 'Партийный учет',
        'is_expiry' => 'Учет сроков годности', 'is_serial' => 'Учет серийников',
        'is_chz' => 'Честный знак', 'is_danger' => 'Опасный груз',
        'allow_partial_pick' => 'Частичный отбор', 'need_check' => 'Проверка после отбора',
        'need_pack' => 'Упаковка заказа', 'auto_replenish' => 'Автопополнение',
        'need_measure' => 'Требуются обмеры',
        'quality' => 'Качество',
        'qty' => 'Остаток', 'reserved_qty' => 'Зарезервировано',
    ];

    public function create(array $b, int $userId, string $userFio = 'Иванов И.И.'): int
    {
        $b = $this->normalize($b);
        $sku = trim((string)($b['sku'] ?? ''));
        if ($sku === '') throw new RuntimeException('Артикул обязателен');
        foreach (['category', 'brand', 'owner', 'warehouse_id'] as $req) {
            if (empty($b[$req])) throw new RuntimeException("Поле обязательно: $req");
        }
        foreach (['length_mm', 'width_mm', 'height_mm', 'weight_g'] as $req) {
            if (!isset($b[$req]) || $b[$req] === '' || $b[$req] === null) throw new RuntimeException("Поле обязательно: $req");
        }
        if (empty($b['code'])) {
            $b['code'] = $this->nextCode();
        }
        // Объем: если не задан — считаем из габаритов (м3).
        if (empty($b['volume_m3']) && !empty($b['length_mm']) && !empty($b['width_mm']) && !empty($b['height_mm'])) {
            $b['volume_m3'] = ((float)$b['length_mm'] / 1000) * ((float)$b['width_mm'] / 1000) * ((float)$b['height_mm'] / 1000);
        }
        $cols = ['is_active' => 1, 'is_archived' => 0];
        foreach (self::EDITABLE as $c) {
            if (array_key_exists($c, $b)) $cols[$c] = $b[$c] === '' ? null : $b[$c];
        }
        $cols = array_intersect_key($cols, array_flip($this->tableColumns()));
        $keys = array_keys($cols);
        $st = $this->pdo->prepare(
            'INSERT INTO products (' . implode(',', $keys) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", $keys)) . ')'
        );
        try {
            $st->execute($cols);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'uplicate')) {
                throw new RuntimeException('Дубликат: такой Артикул или Код уже существует');
            }
            throw $e;
        }
        $id = (int)$this->pdo->lastInsertId();
        $this->saveBarcodes($id, $b['barcodes'] ?? $b['barcode_list'] ?? []);
        $this->hist($id, $userId, $userFio, 'create', 'Создание', null, $sku);
        if (class_exists('Notifications')) {
            Notifications::push($this->pdo, 'nomenclature',
                trim('Создана карточка товара ' . ($b['code'] ?? '') . " $sku " . ($b['name'] ?? '')),
                'Создал: ' . $userFio, $sku !== '' ? $sku : null);
        }
        return $id;
    }

    public function update(int $id, array $b, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $b = $this->normalize($b);
        $cur = $this->pdo->prepare("SELECT * FROM products WHERE id = :id");
        $cur->execute([':id' => $id]);
        $old = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new RuntimeException('Карточка не найдена');
        $realCols = array_flip($this->tableColumns());
        $set = []; $params = [':id' => $id]; $changed = [];
        foreach (self::EDITABLE as $c) {
            if (!isset($realCols[$c])) continue;
            if (array_key_exists($c, $b)) {
                $nv = $b[$c] === '' ? null : $b[$c];
                $ov = $old[$c] ?? null;
                if ((string)$nv !== (string)$ov) {
                    $set[] = "$c = :$c";
                    $params[":$c"] = $nv;
                    $this->hist($id, $userId, $userFio, (string)$c, self::LABELS[$c] ?? $c,
                        $ov === null ? null : (string)$ov, $nv === null ? null : (string)$nv);
                    $changed[] = (self::LABELS[$c] ?? $c) . ': ' . ($nv === null ? '—' : (string)$nv);
                }
            }
        }
        if ($set) {
            $this->pdo->prepare('UPDATE products SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }
        if (array_key_exists('barcodes', $b) || array_key_exists('barcode_list', $b)) {
            $this->saveBarcodes($id, $b['barcodes'] ?? $b['barcode_list'] ?? []);
        }
        if ($changed && class_exists('Notifications')) {
            $sku = (string)($b['sku'] ?? $old['sku'] ?? '');
            $nm = (string)($b['name'] ?? $old['name'] ?? '');
            $code = (string)($b['code'] ?? $old['code'] ?? '');
            Notifications::push($this->pdo, 'nomenclature',
                trim("Изменена карточка товара $code $sku $nm"),
                'Новое: ' . implode('; ', array_slice($changed, 0, 3)) . ', внес изменения: ' . $userFio,
                $sku !== '' ? $sku : null);
        }
    }

    public function copy(int $id, int $userId, string $userFio = 'Иванов И.И.'): int
    {
        $cur = $this->pdo->prepare("SELECT * FROM products WHERE id = :id");
        $cur->execute([':id' => $id]);
        $old = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new RuntimeException('Карточка не найдена');
        unset($old['id']);
        $old['sku'] = $old['sku'] . '-COPY';
        $old['code'] = $this->nextCode();
        $old['qty'] = 0; $old['reserved_qty'] = 0;
        unset($old['created_at'], $old['updated_at']);
        $old = array_intersect_key($old, array_flip($this->tableColumns()));
        $keys = array_keys($old);
        $st = $this->pdo->prepare(
            'INSERT INTO products (' . implode(',', $keys) . ') VALUES (' . implode(',', array_map(fn($k) => ":$k", $keys)) . ')'
        );
        $st->execute($old);
        $nid = (int)$this->pdo->lastInsertId();
        $this->hist($nid, $userId, $userFio, 'copy', 'Копирование', (string)$id, $old['sku']);
        return $nid;
    }

    public function block(int $id, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $this->pdo->prepare("UPDATE products SET is_active = 0 WHERE id = :id")->execute([':id' => $id]);
        $this->hist($id, $userId, $userFio, 'is_active', 'Статус', 'Активный', 'Не активный');
    }

    public function unblock(int $id, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $this->pdo->prepare("UPDATE products SET is_active = 1, is_archived = 0 WHERE id = :id")->execute([':id' => $id]);
        $this->hist($id, $userId, $userFio, 'is_active', 'Статус', 'Не активный', 'Активный');
    }

    public function archive(int $id, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $this->pdo->prepare("UPDATE products SET is_archived = 1, is_active = 0 WHERE id = :id")->execute([':id' => $id]);
        $this->hist($id, $userId, $userFio, 'is_archived', 'Архив', 'Нет', 'Да');
    }

    /** Удаление карточки (корзина в шапке). Только если нет остатков и резервов. */
    public function delete(int $id, int $userId, string $userFio = 'Иванов И.И.'): void
    {
        $st = $this->pdo->prepare("SELECT qty, reserved_qty, sku FROM products WHERE id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Карточка не найдена');
        if ((float)($row['qty'] ?? 0) != 0.0 || (float)($row['reserved_qty'] ?? 0) != 0.0) {
            throw new RuntimeException('Нельзя удалить: есть остаток или резерв. Сначала обнулите запас или архивируйте.');
        }
        $this->hist($id, $userId, $userFio, 'delete', 'Удаление', $row['sku'] ?? '', null);
        $this->pdo->prepare("DELETE FROM products WHERE id = :id")->execute([':id' => $id]);
    }

    /**
     * Значения по умолчанию для формы создания.
     * По схеме: задаются на уровне интерфейса и только Админом.
     * @throws RuntimeException если роль не admin/administrator/Администратор
     */
    public function saveDefaults(array $map, int $userId, string $role = 'admin'): array
    {
        $r = strtolower(trim($role));
        $isAdmin = in_array($r, ['admin', 'administrator', 'админ', 'администратор', 'Администратор', 'Админ'], true);
        if (!$isAdmin) throw new RuntimeException('Только Администратор может менять значения по умолчанию');
        $allowed = ['unit', 'category', 'brand', 'owner', 'warehouse_id',
            'storage_strategy', 'pick_strategy', 'placement_strategy',
            'shelf_life_days', 'chz_group', 'max_expiry_pct'];
        $saved = [];
        $st = $this->pdo->prepare(
            "INSERT INTO product_defaults (field_name, field_value, updated_by) VALUES (:f,:v,:u) " .
            "ON CONFLICT(field_name) DO UPDATE SET field_value = excluded.field_value, updated_by = excluded.updated_by"
        );
        try {
            foreach ($map as $k => $v) {
                if (!in_array($k, $allowed, true)) continue;
                $vv = ($v === '' || $v === null) ? null : (string)$v;
                try {
                    $st->execute([':f' => $k, ':v' => $vv, ':u' => $userId]);
                } catch (Throwable) {
                    // MySQL-совместимый fallback
                    $this->pdo->prepare("INSERT INTO product_defaults (field_name, field_value, updated_by) VALUES (:f,:v,:u) ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_by = VALUES(updated_by)")
                        ->execute([':f' => $k, ':v' => $vv, ':u' => $userId]);
                }
                $saved[$k] = $vv;
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Не удалось сохранить defaults: ' . $e->getMessage());
        }
        return $saved;
    }

    /** @param int[] $ids */
    public function massUpdate(array $ids, array $patch, int $userId, string $userFio = 'Иванов И.И.'): int
    {
        $allowed = array_intersect(array_keys($patch), self::EDITABLE);
        if (!$ids || !$allowed) return 0;
        $n = 0;
        foreach ($ids as $id) {
            $this->update((int)$id, array_intersect_key($patch, array_flip($allowed)), $userId, $userFio);
            $n++;
        }
        return $n;
    }

    public function printData(int $id, string $kind = 'barcode'): array
    {
        $st = $this->pdo->prepare("SELECT p.*, (SELECT barcode FROM product_barcodes WHERE product_id = p.id ORDER BY id LIMIT 1) AS main_bc FROM products p WHERE p.id = :id");
        $st->execute([':id' => $id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Карточка не найдена');
        return ['kind' => $kind, 'sku' => $p['sku'], 'name' => $p['name'], 'barcode' => $p['main_bc'], 'unit' => $p['unit']];
    }

    public function exportCsv(ProductFilter $f, ProductRepository $repo): string
    {
        $f->page = 1; $f->perPage = 5000;
        $data = $repo->paginate($f);
        $head = ['Код', 'Артикул', 'Наименование', 'Ед.изм', 'Категория', 'Бренд', 'Владелец', 'Остаток', 'Зарезервировано', 'Статус', 'Штрихкоды'];
        $lines = ["\xEF\xBB\xBF" . implode(';', $head)];
        foreach ($data['rows'] as $r) {
            $lines[] = implode(';', [
                $r['code'] ?? '', $r['sku'] ?? '', str_replace(';', ',', (string)($r['name'] ?? '')),
                $r['unit'] ?? '', $r['category'] ?? '', $r['brand'] ?? '', $r['owner'] ?? '',
                (string)($r['qty'] ?? 0), (string)($r['reserved_qty'] ?? 0),
                $r['status_label'] ?? '', str_replace(';', ',', (string)($r['barcodes'] ?? '')),
            ]);
        }
        return implode("\n", $lines);
    }

    /** Массовое создание из CSV (кнопка "Импорт"): возвращает [created, errors]. */
    public function importRows(array $rows, int $userId): array
    {
        $ok = 0; $errs = [];
        foreach ($rows as $i => $r) {
            try {
                $this->create([
                    'sku' => trim((string)($r['sku'] ?? $r['артикул'] ?? '')),
                    'code' => trim((string)($r['code'] ?? $r['код'] ?? '')) ?: null,
                    'name' => trim((string)($r['name'] ?? $r['наименование'] ?? '')) ?: ('Товар ' . ($r['sku'] ?? $i)),
                    'unit' => trim((string)($r['unit'] ?? 'шт')),
                    'category' => trim((string)($r['category'] ?? 'Мячи')),
                    'brand' => trim((string)($r['brand'] ?? '')) ?: 'Wilson',
                    'owner' => trim((string)($r['owner'] ?? '')) ?: 'ООО «Эталон»',
                    'warehouse_id' => (int)($r['warehouse_id'] ?? 1),
                    'length_mm' => (int)($r['length_mm'] ?? 100),
                    'width_mm' => (int)($r['width_mm'] ?? 100),
                    'height_mm' => (int)($r['height_mm'] ?? 100),
                    'weight_g' => (int)($r['weight_g'] ?? 500),
                    'barcodes' => isset($r['barcode']) ? [(string)$r['barcode']] : [],
                ], $userId);
                $ok++;
            } catch (Throwable $e) {
                $errs[] = 'Строка ' . ($i + 1) . ': ' . $e->getMessage();
            }
        }
        return ['created' => $ok, 'errors' => $errs];
    }

    private function saveBarcodes(int $productId, $list): void
    {
        if (is_string($list)) $list = preg_split('/[,\s;]+/', $list, -1, PREG_SPLIT_NO_EMPTY);
        $list = array_values(array_unique(array_filter(array_map(fn($v) => trim((string)$v), (array)$list))));
        if (!$list) return;
        $st = $this->pdo->prepare("INSERT OR IGNORE INTO product_barcodes (product_id, barcode) VALUES (:p, :b)");
        // Для MySQL INSERT OR IGNORE невалиден — пробуем обычный INSERT IGNORE через fallback.
        foreach ($list as $bc) {
            try {
                $st->execute([':p' => $productId, ':b' => $bc]);
            } catch (Throwable) {
                try {
                    $this->pdo->prepare("INSERT IGNORE INTO product_barcodes (product_id, barcode) VALUES (:p, :b)")
                        ->execute([':p' => $productId, ':b' => $bc]);
                } catch (Throwable) {}
            }
        }
    }

    private ?array $colsCache = null;

    private function tableColumns(): array
    {
        if ($this->colsCache !== null) return $this->colsCache;
        try {
            $drv = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($drv === 'sqlite') {
                $out = [];
                foreach ($this->pdo->query("PRAGMA table_info(products)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $out[] = $c['name'];
                }
                return $this->colsCache = $out;
            }
            $st = $this->pdo->query("SHOW COLUMNS FROM products");
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $out[] = $c['Field'];
            return $this->colsCache = $out;
        } catch (Throwable) {
            return $this->colsCache = array_merge(self::EDITABLE, ['is_active', 'is_archived']);
        }
    }

    private function normalize(array $b): array
    {
        // Температуры из селектов вида "+5", "+5 °C", "−10": приводим к числу.
        foreach (['temp_from', 'temp_to'] as $k) {
            if (array_key_exists($k, $b) && $b[$k] !== null && $b[$k] !== '') {
                $s = str_replace(['−', '–', ',', '°', 'C', 'С', ' '], ['-', '-', '.', '', '', '', ''], (string)$b[$k]);
                if ($s === '+' || $s === '-') $s = '';
                $b[$k] = ($s === '' ? null : (float)$s);
            }
        }
        foreach (['qty_in_box', 'boxes_per_pallet', 'shelf_life_days', 'warehouse_id', 'length_mm', 'width_mm', 'height_mm', 'weight_g'] as $k) {
            if (array_key_exists($k, $b) && ($b[$k] === '' || $b[$k] === null)) $b[$k] = null;
        }
        // Статус из карточки приходит строкой "1"/"0" — ок; защищаем от мусора.
        if (array_key_exists('is_active', $b) && $b['is_active'] !== null && $b['is_active'] !== '') {
            $b['is_active'] = ((string)$b['is_active'] === '0' || $b['is_active'] === 0 || $b['is_active'] === false) ? 0 : 1;
        }
        foreach (['storage_strategy', 'pick_strategy', 'placement_strategy'] as $k) {
            if (array_key_exists($k, $b) && $b[$k] !== null && $b[$k] !== '') {
                $v = strtoupper(trim((string)$b[$k]));
                if (!in_array($v, ['FIFO', 'FEFO', 'LIFO'], true)) {
                    throw new RuntimeException("Недопустимая стратегия $k: используйте FIFO/FEFO/LIFO");
                }
                $b[$k] = $v;
            }
        }
        return $b;
    }

    private function hist(int $pid, int $uid, string $fio, string $field, string $label, ?string $old, ?string $new): void
    {
        try {
            $this->pdo->prepare(
                "INSERT INTO product_history (product_id, user_id, user_fio, field, field_label, old_value, new_value) VALUES (:p,:u,:f,:fd,:fl,:o,:n)"
            )->execute([':p' => $pid, ':u' => $uid, ':f' => $fio, ':fd' => $field, ':fl' => $label, ':o' => $old, ':n' => $new]);
        } catch (Throwable) {}
    }

    private function nextCode(): string
    {
        // Внутренний код WMS: T-101..T-999, далее T-1001..T-1999, T-2001.. (см. shared/ProductCode.php).
        return wms_next_product_code($this->pdo);
    }
}
