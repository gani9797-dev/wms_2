<?php
declare(strict_types=1);

// CSV-загрузка прихода/расхода (кнопка «Загрузить данные» на дашборде) + обмеры на ТСД.
// Переносимо между MySQL (прод) и SQLite (демо): без диалектных функций в SQL,
// даты генерируем в PHP, недостающие колонки дотягиваем ensure-миграциями.

require_once __DIR__ . '/ProductCode.php';
require_once __DIR__ . '/DocNo.php';
require_once __DIR__ . '/pg.php';

// ---------- Утилиты ----------

function csv_upload_is_mysql(PDO $pdo): bool
{
    return function_exists('wms_is_mysql') ? wms_is_mysql($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
}

function csv_upload_is_pgsql(PDO $pdo): bool
{
    return function_exists('wms_is_pgsql') ? wms_is_pgsql($pdo) : $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}

function csv_upload_now(): string
{
    return date('Y-m-d H:i:s');
}

/** Дотянуть колонки (name => [mysql_ddl, sqlite_ddl, pg_ddl?]). Безопасно при повторе. */
function csv_upload_ensure_columns(PDO $pdo, string $table, array $cols): void
{
    try {
        if (csv_upload_is_mysql($pdo)) {
            $have = [];
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[] = $c['Field'];
            }
            foreach ($cols as $name => $ddl) {
                if (!in_array($name, $have, true)) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN " . $ddl[0]);
                }
            }
        } elseif (csv_upload_is_pgsql($pdo)) {
            $have = function_exists('wms_table_columns') ? wms_table_columns($pdo, $table) : [];
            foreach ($cols as $name => $ddl) {
                if (!in_array($name, $have, true)) {
                    $pgDdl = $ddl[2] ?? $ddl[1];
                    // sqlite-дефолт datetime('now') недопустим в PG
                    $pgDdl = str_replace("datetime('now')", 'NOW()', $pgDdl);
                    $pdo->exec("ALTER TABLE \"$table\" ADD COLUMN " . $pgDdl);
                }
            }
        } else {
            $have = [];
            foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $have[] = $c['name'];
            }
            foreach ($cols as $name => $ddl) {
                if (!in_array($name, $have, true)) {
                    $pdo->exec("ALTER TABLE $table ADD COLUMN " . $ddl[1]);
                }
            }
        }
    } catch (Throwable) {
    }
}

/** Нижний регистр с поддержкой кириллицы даже без mbstring. */
function csv_upload_lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    $s = strtr($s, [
        'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё',
        'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м',
        'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у',
        'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ',
        'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я',
    ]);
    return strtolower($s);
}

function csv_upload_norm_header(string $s): string
{
    $s = csv_upload_lower(trim($s));
    return str_replace(['_', ' '], '', $s);
}

/** Нормализация количества: пробелы/апострофы прочь, запятая -> точка. */
function csv_upload_norm_qty(string $s): ?float
{
    $s = str_replace([' ', "'", "\xC2\xA0"], '', trim($s));
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) {
        return null;
    }
    return (float)$s;
}

/** Нормализация качества: только Годен/Брак. */
function csv_upload_norm_quality(string $s): ?string
{
    $s = csv_upload_norm_header($s);
    if (in_array($s, ['годен', 'годный', 'good', 'ок', 'ok'], true)) {
        return 'Годен';
    }
    if (in_array($s, ['брак', 'бракованный', 'defect', 'bad', 'бой'], true)) {
        return 'Брак';
    }
    return null;
}

/** Признак да/нет: да, д, yes, 1, +, true, есть, требуется... */
function csv_upload_bool_flag(string $s): bool
{
    $s = csv_upload_lower(trim($s));
    return in_array($s, ['да', 'д', 'yes', 'y', '1', '+', 'true', 'истина', 'есть', 'требуется', 'нужно', 'v', 'x'], true);
}

/** Нормализация даты производства: YYYY-MM-DD или DD.MM.YYYY -> YYYY-MM-DD. */
function csv_upload_norm_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $s;
    }
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return null;
}

// ---------- Парсинг загруженного CSV ----------

/**
 * @param array $file элемент $_FILES
 * @param array $spec ['fields' => [field => [синонимы...]], 'required' => [fields], 'kind' => 'receipt'|'shipment']
 * @return array{rows: array, errors: array, total: int}
 */
function csv_upload_parse(array $file, array $spec): array
{
    $err = static function (string $t): array {
        return ['rows' => [], 'errors' => [$t], 'total' => 0];
    };
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
        return $err('Файл не загружен');
    }
    if (($file['error'] ?? 0) !== 0) {
        return $err('Ошибка загрузки файла (код ' . (int)$file['error'] . ')');
    }
    $raw = @file_get_contents($tmp);
    if ($raw === false || $raw === '') {
        return $err('Файл пуст');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!preg_match('//u', $raw)) {
        if (function_exists('mb_convert_encoding')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        } elseif (function_exists('iconv')) {
            $conv = @iconv('Windows-1251', 'UTF-8//IGNORE', $raw);
            if ($conv !== false) {
                $raw = $conv;
            }
        }
    }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    // Убрать пустые строки по краям
    while ($lines && trim((string)$lines[0]) === '') {
        array_shift($lines);
    }
    while ($lines && trim((string)end($lines)) === '') {
        array_pop($lines);
    }
    if (count($lines) < 2) {
        return $err('В файле нет данных (нужны строка заголовка и хотя бы одна строка)');
    }
    $head = array_shift($lines);
    $delim = ';';
    if (!str_contains($head, ';')) {
        $delim = str_contains($head, "\t") ? "\t" : ',';
    }
    $headCells = str_getcsv($head, $delim);
    $map = []; // field => index
    foreach ($headCells as $i => $cell) {
        $n = csv_upload_norm_header((string)$cell);
        foreach ($spec['fields'] as $field => $syns) {
            if (in_array($n, $syns, true) && !isset($map[$field])) {
                $map[$field] = $i;
            }
        }
    }
    $missing = [];
    $ru = ['contractor' => 'Контрагент', 'supplier' => 'Поставщик', 'consignee' => 'Получатель',
        'ship_type' => 'Тип отгрузки', 'sku' => 'артикул', 'name' => 'наименование',
        'qty' => 'количество', 'quality' => 'качество', 'barcode' => 'штрихкод',
        'marking' => 'Признак сканирования марки', 'serial' => 'Признак сканирования с/н',
        'comment' => 'Комментарий', 'unit' => 'Ед. изм.', 'batch' => 'Партия',
        'plan_date' => 'Дата отгрузки', 'external_no' => 'Номер заказа',
        'contract_no' => 'Договор'];
    foreach ($spec['required'] as $req) {
        if (!isset($map[$req])) {
            $missing[] = $ru[$req] ?? $req;
        }
    }
    if ($missing) {
        return $err('В заголовке нет обязательных колонок: ' . implode(', ', $missing)
            . '. Заголовок файла: ' . trim($head));
    }
    $rows = [];
    $errors = [];
    // Шапка документа: текстовые поля — первое непустое, флаги — ИЛИ по строкам.
    $hdr = ['contractor' => '', 'supplier' => '', 'consignee' => '', 'ship_type' => '',
        'marking' => false, 'serial' => false, 'comments' => [],
        'external_no' => '', 'contract_no' => '', 'plan_date' => null];
    $firstNonEmpty = static function (string $cur, string $v): string {
        return $cur !== '' ? $cur : trim($v);
    };
    foreach ($lines as $k => $ln) {
        $n = $k + 2; // номер строки в файле (с учетом заголовка)
        if (trim($ln) === '') {
            continue;
        }
        $cells = str_getcsv($ln, $delim);
        $get = static fn(string $f) => isset($map[$f]) ? trim((string)($cells[$map[$f]] ?? '')) : '';
        if ($spec['kind'] === 'receipt') {
            $sku = $get('sku');
            $name = $get('name');
            $qty = csv_upload_norm_qty($get('qty'));
            $qRaw = $get('quality');
            $quality = $qRaw === '' ? 'Годен' : csv_upload_norm_quality($qRaw);
            $bcRaw = $get('barcode');
            $barcodes = [];
            if ($bcRaw !== '') {
                foreach (explode(',', $bcRaw) as $bc) {
                    $bc = trim($bc);
                    if ($bc !== '' && !in_array($bc, $barcodes, true)) {
                        $barcodes[] = $bc;
                    }
                }
            }
            $pdRaw = $get('prod_date');
            $prodDate = $pdRaw === '' ? null : csv_upload_norm_date($pdRaw);
            if ($sku === '' || $name === '') {
                $errors[] = "Строка $n: пустые артикул или наименование";
                continue;
            }
            if ($qty === null || $qty <= 0) {
                $errors[] = "Строка $n ($sku): количество должно быть числом > 0";
                continue;
            }
            if ($quality === null) {
                $errors[] = "Строка $n ($sku): качество — только «Годен» или «Брак»";
                continue;
            }
            if (!$barcodes) {
                $barcodes = [$sku]; // ШК не обязателен (заявки без штрихкодов) — кодом служит артикул
            }
            if ($pdRaw !== '' && $prodDate === null) {
                $errors[] = "Строка $n ($sku): дата производства — формат ГГГГ-ММ-ДД или ДД.ММ.ГГГГ";
                continue;
            }
            $hdr['supplier'] = $firstNonEmpty($hdr['supplier'], $get('supplier'));
            $cmt = trim($get('comment'));
            if ($cmt !== '' && !in_array($cmt, $hdr['comments'], true)) {
                $hdr['comments'][] = $cmt;
            }
            $batchRaw = trim((string)$get('batch'));
            $rows[] = ['sku' => $sku, 'name' => $name, 'qty' => $qty, 'quality' => $quality,
                'barcodes' => $barcodes, 'group' => $get('group'), 'prod_date' => $prodDate,
                'batch' => $batchRaw !== '' ? $batchRaw : null,
                'unit' => trim((string)$get('unit')) !== '' ? trim((string)$get('unit')) : 'шт'];
        } else {
            $sku = $get('sku');
            $qty = csv_upload_norm_qty($get('qty'));
            $pdRaw = $get('prod_date');
            $prodDate = $pdRaw === '' ? null : csv_upload_norm_date($pdRaw);
            if ($sku === '') {
                $errors[] = "Строка $n: пустой артикул";
                continue;
            }
            if ($qty === null || $qty <= 0) {
                $errors[] = "Строка $n ($sku): количество должно быть числом > 0";
                continue;
            }
            if ($pdRaw !== '' && $prodDate === null) {
                $errors[] = "Строка $n ($sku): дата производства — формат ГГГГ-ММ-ДД или ДД.ММ.ГГГГ";
                continue;
            }
            $hdr['contractor'] = $firstNonEmpty($hdr['contractor'], $get('contractor'));
            $hdr['consignee'] = $firstNonEmpty($hdr['consignee'], $get('consignee'));
            $hdr['ship_type'] = $firstNonEmpty($hdr['ship_type'], $get('ship_type'));
            $hdr['external_no'] = $firstNonEmpty($hdr['external_no'], $get('external_no'));
            $hdr['contract_no'] = $firstNonEmpty($hdr['contract_no'], $get('contract_no'));
            $planRaw = $get('plan_date');
            if ($hdr['plan_date'] === null && $planRaw !== '') {
                $pd = csv_upload_norm_date($planRaw);
                if ($pd !== null) {
                    $hdr['plan_date'] = $pd;
                }
            }
            if (csv_upload_bool_flag($get('marking'))) {
                $hdr['marking'] = true;
            }
            if (csv_upload_bool_flag($get('serial'))) {
                $hdr['serial'] = true;
            }
            $cmt = trim($get('comment'));
            if ($cmt !== '' && !in_array($cmt, $hdr['comments'], true)) {
                $hdr['comments'][] = $cmt;
            }
            $unitRaw = trim($get('unit'));
            $batchRaw = trim($get('batch'));
            $rows[] = ['sku' => $sku, 'name' => $get('name'), 'qty' => $qty, 'prod_date' => $prodDate,
                'unit' => $unitRaw !== '' ? $unitRaw : 'шт',
                'batch' => $batchRaw !== '' ? $batchRaw : null];
        }
    }
    $hdr['comment'] = implode('; ', $hdr['comments']);
    unset($hdr['comments']);
    return ['rows' => $rows, 'errors' => $errors, 'total' => count($lines), 'header' => $hdr];
}

function csv_upload_receipt_spec(): array
{
    return [
        'kind' => 'receipt',
        'required' => ['supplier', 'sku', 'name', 'qty'],
        'fields' => [
            'supplier' => ['поставщик', 'контрагент', 'supplier', 'продавец'],
            'sku' => ['артикул', 'sku', 'artikul'],
            'name' => ['наименование', 'название', 'name', 'наименованиетовара', 'товар'],
            'qty' => ['количество', 'колво', 'кол-во', 'qty', 'quantity', 'шт'],
            'quality' => ['качество', 'качествотовара', 'quality', 'состояние'],
            'barcode' => ['штрихкод', 'штрихкодтовара', 'шк', 'barcode', 'ean', 'ean13'],
            'batch' => ['партия', 'партияпоставщика', 'партиятовара', 'batch', 'lot', 'партияпоставки'],
            'unit' => ['единица', 'единицаизмерения', 'едизм', 'ед', 'unit'],
            'group' => ['группа', 'группатовара', 'group', 'productgroup'],
            'prod_date' => ['датапроизводства', 'датавыпуска', 'proddate', 'productiondate', 'дата'],
            'comment' => ['комментарий', 'коммент', 'comment', 'примечание'],
        ],
    ];
}

function csv_upload_shipment_spec(): array
{
    return [
        'kind' => 'shipment',
        'required' => ['contractor', 'sku', 'qty'],
        'fields' => [
            'contractor' => ['контрагент', 'contractor', 'клиент', 'покупатель', 'заказчик'],
            'consignee' => ['получатель', 'грузополучатель', 'consignee', 'адресат'],
            'ship_type' => ['типотгрузки', 'тип', 'типдоставки', 'способдоставки', 'deliverytype',
                'shipmenttype', 'видотгрузки', 'доставка'],
            'sku' => ['артикул', 'sku', 'artikul'],
            'name' => ['наименование', 'название', 'name', 'номенклатура', 'товар'],
            'qty' => ['количество', 'колво', 'кол-во', 'qty', 'quantity', 'шт'],
            'unit' => ['единица', 'единицаизмерения', 'едизм', 'ед', 'ед.', 'ед изм', 'ед.изм',
                'ед.изм.', 'unit', 'uom'],
            'batch' => ['партия', 'партияпоставщика', 'партиятовара', 'batch', 'lot', 'партияпоставки'],
            'prod_date' => ['датапроизводства', 'датавыпуска', 'proddate', 'productiondate', 'дата'],
            'plan_date' => ['датаотгрузки', 'датадоставки', 'датаотправки', 'планируемаядата',
                'датаотгрузкипланируемая', 'shipdate', 'plandate'],
            'external_no' => ['номерзаказа', 'номерзаявки', 'номердокумента', 'внешнийномер',
                'orderno', 'asn'],
            'contract_no' => ['договор', 'номердоговора', 'contractno'],
            'marking' => ['признаксканированиямарки', 'сканированиемарки', 'маркировка', 'marking', 'чз'],
            'serial' => ['признаксканированиясн', 'сканированиесн', 'серийники', 'serial', 'сн'],
            'comment' => ['комментарий', 'коммент', 'comment', 'примечание'],
        ],
    ];
}

// ---------- Номера документов и коды (переносимо) ----------

function csv_upload_next_receipt_no(PDO $pdo): string
{
    // Номер поступления: A-105101, A-105102, ... (см. DocNo.php).
    return wms_next_receipt_no($pdo);
}

function csv_upload_next_shipment_no(PDO $pdo): string
{
    // Номер отгрузки: S-205101, S-205102, ... (см. DocNo.php).
    return wms_next_shipment_no($pdo);
}

/**
 * Случайный внутренний номер наряда: or_ + цифры. Проверяем уникальность.
 */
function csv_upload_next_order_no(PDO $pdo): string
{
    for ($i = 0; $i < 50; $i++) {
        try {
            $no = 'or_' . random_int(100000, 999999);
        } catch (Throwable) {
            $no = 'or_' . (100000 + ($i * 7919) % 900000);
        }
        try {
            $st = $pdo->prepare('SELECT 1 FROM client_orders WHERE doc_no = :n LIMIT 1');
            $st->execute([':n' => $no]);
            if (!$st->fetchColumn()) {
                return $no;
            }
        } catch (Throwable) {
            return $no; // таблицы нет — номер всё равно отдаём
        }
    }
    try {
        return 'or_' . random_int(10000000, 99999999);
    } catch (Throwable) {
        return 'or_' . time();
    }
}

/** Гарантировать таблицу строк нарядов (переносимо MySQL/SQLite). */
function csv_upload_ensure_order_items_table(PDO $pdo): void
{
    try {
        if (csv_upload_is_mysql($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS client_order_items (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
              sku VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL,
              unit VARCHAR(10) NOT NULL DEFAULT 'шт', qty DECIMAL(12,3) NOT NULL,
              supplier_batch VARCHAR(100) NULL, prod_date DATE NULL,
              KEY idx_coi_order (order_id), KEY idx_coi_sku (sku)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS client_order_items (
              id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL,
              sku TEXT NOT NULL, name TEXT NOT NULL, unit TEXT DEFAULT 'шт',
              qty REAL NOT NULL, supplier_batch TEXT, prod_date TEXT)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_coi_order ON client_order_items(order_id)");
        }
        wms_ensure_column($pdo, 'client_order_items', 'supplier_batch', 'VARCHAR(100) NULL', 'supplier_batch TEXT', 'VARCHAR(100) NULL');
    } catch (Throwable) {
    }
}

/**
 * Поля шапки отгрузки из CSV (получатель, тип, признаки сканирования).
 * По одному UPDATE — best effort для старых БД без этих колонок.
 */
function csv_upload_apply_shipment_header(PDO $shp, int $sid, array $header): void
{
    $extra = [];
    if (trim((string)($header['consignee'] ?? '')) !== '') {
        $extra['consignee'] = trim((string)$header['consignee']);
    }
    if (trim((string)($header['ship_type'] ?? '')) !== '') {
        $extra['delivery_type'] = trim((string)$header['ship_type']);
    }
    if (trim((string)($header['contract_no'] ?? '')) !== '') {
        $extra['contract_no'] = trim((string)$header['contract_no']);
    }
    $planDate = trim((string)($header['plan_date'] ?? ''));
    if ($planDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $planDate)) {
        $extra['plan_shipment_date'] = substr($planDate, 0, 10);
    }
    if (!empty($header['marking'])) {
        $extra['marking_scan'] = 1;
    }
    if (!empty($header['serial'])) {
        $extra['serial_scan'] = 1;
    }
    foreach ($extra as $col => $val) {
        try {
            $shp->prepare("UPDATE shipments SET $col=:v WHERE id=:id")
                ->execute([':v' => $val, ':id' => $sid]);
        } catch (Throwable) {
        }
    }
}

/**
 * Контрагент по имени из CSV: ищем (точное совпадение, затем без учёта регистра
 * и пробелов), если новый — добавляем запись в contractors и присваиваем id.
 * @return array{id: int, name: string, created: bool}
 */
function csv_upload_resolve_contractor(PDO $pdo, string $name): array
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Заполни колонку «Контрагент» в CSV-файле');
    }
    $norm = static function (string $s): string {
        $s = (string)preg_replace('/\s+/', ' ', trim($s));
        return csv_upload_lower($s);
    };
    $want = $norm($name);
    try {
        foreach ($pdo->query('SELECT id, name FROM contractors')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($norm((string)$r['name']) === $want) {
                return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'created' => false];
            }
        }
    } catch (Throwable) {
    }
    $st = $pdo->prepare('INSERT INTO contractors (name) VALUES (:n)');
    $st->execute([':n' => $name]);
    return ['id' => (int)$pdo->lastInsertId(), 'name' => $name, 'created' => true];
}

function csv_upload_next_product_code(PDO $pdo): string
{
    // Внутренний код WMS: T-101..T-999, далее T-1001..T-1999, T-2001.. (см. ProductCode.php).
    return wms_next_product_code($pdo);
}

/** Накопить штрихкоды товара в product_barcodes (дубли молча пропускаем). Возвращает число добавленных. */
function csv_upload_add_barcodes(PDO $nom, int $productId, array $barcodes): int
{
    $added = 0;
    try {
        $ins = $nom->prepare("INSERT INTO product_barcodes (product_id, barcode) VALUES (:p, :b)");
    } catch (Throwable) {
        return 0; // нет таблицы product_barcodes
    }
    foreach ($barcodes as $bc) {
        $bc = trim((string)$bc);
        if ($bc === '') {
            continue;
        }
        try {
            $ins->execute([':p' => $productId, ':b' => $bc]);
            $added++;
        } catch (Throwable) {
            // Дубликат (у этого или чужого товара) — просто копим дальше
        }
    }
    return $added;
}

/** Группа «Легкая промышленность» (сравнение нечувствительно к регистру, ё/е и пробелам). */
function csv_upload_is_light_industry(?string $s): bool
{
    if ($s === null) {
        return false;
    }
    $s = csv_upload_lower(trim($s));
    $s = str_replace('ё', 'е', $s);
    $s = (string)preg_replace('/\s+/', ' ', $s);
    return $s === 'легкая промышленность';
}

/**
 * Требуется ли сканирование Честного знака: группа из документа прихода
 * или группа из номенклатуры — «Легкая промышленность».
 */
function csv_upload_chz_required(?string $itemGroup, ?string $productGroup): bool
{
    return csv_upload_is_light_industry($itemGroup) || csv_upload_is_light_industry($productGroup);
}

// ---------- Приход ----------

/**
 * Создает документ поступления из строк CSV + заводит новые артикулы в номенклатуру.
 * @return array{id, doc_no, items, new_skus}
 */
function csv_upload_import_receipt(PDO $rcp, PDO $nom, array $rows, int $supplierId, int $warehouseId, int $userId, string $fileName, array $header = []): array
{
    csv_upload_ensure_columns($rcp, 'receipt_items', [
        'quality' => ['VARCHAR(20) NULL', 'quality TEXT'],
        'prod_date' => ['DATE NULL', 'prod_date TEXT'],
        'prod_group' => ['VARCHAR(100) NULL', 'prod_group TEXT'],
        'supplier_batch' => ['VARCHAR(100) NULL', 'supplier_batch TEXT'],
    ]);
    csv_upload_ensure_columns($nom, 'products', [
        'need_measure' => ['TINYINT(1) NOT NULL DEFAULT 0', 'need_measure INTEGER DEFAULT 0'],
    ]);
    if ($supplierId <= 0) {
        throw new RuntimeException('Не найден поставщик из CSV (таблица contractors недоступна)');
    }
    if ($warehouseId <= 0) {
        throw new RuntimeException('Выбери склад');
    }
    if (!$rows) {
        throw new RuntimeException('Нет корректных строк для загрузки');
    }
    $now = csv_upload_now();
    $externalNo = trim((string)($header['external_no'] ?? ''));
    $planDate = ($header['plan_date'] ?? null) ? trim((string)$header['plan_date']) : null;
    if ($planDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}/', $planDate)) {
        $planDate = null;
    }
    if ($planDate !== null && strlen($planDate) === 10) {
        $planDate .= ' 00:00:00';
    }
    $unloadType = trim((string)($header['unload_type'] ?? ''));
    $contractNo = trim((string)($header['contract_no'] ?? ''));
    $rcp->beginTransaction();
    try {
        $docNo = csv_upload_next_receipt_no($rcp);
        // Статус expected (а не draft): документ сразу виден на ТСД «Приход»
        // (prihod.php показывает expected..accepting) и готов к приемке.
        $st = $rcp->prepare(
            "INSERT INTO receipts (doc_no,external_no,doc_date,plan_delivery_date,supplier_id,contractor_id,warehouse_id,status,
              receipt_type,cargo_places_plan,created_by,creator_id,comment,unload_type,contract_no)
             VALUES (:no,:ext,:dt,:plan,:s,:s,:w,'expected','supply',:cp,:u,:u,:cmt,:ut,:cn)"
        );
        $cmt = 'Загружено из файла: ' . $fileName;
        if (trim((string)($header['comment'] ?? '')) !== '') {
            $cmt .= ' · ' . trim((string)$header['comment']);
        }
        $st->execute([
            ':no' => $docNo, ':ext' => $externalNo !== '' ? $externalNo : null, ':dt' => $now,
            ':plan' => $planDate, ':s' => $supplierId, ':w' => $warehouseId,
            ':cp' => count($rows), ':u' => $userId, ':cmt' => $cmt,
            ':ut' => $unloadType !== '' ? $unloadType : null, ':cn' => $contractNo !== '' ? $contractNo : null,
        ]);
        $rid = (int)$rcp->lastInsertId();
        $ins = $rcp->prepare(
            "INSERT INTO receipt_items (receipt_id,line_no,code,sku,name,unit,qty,quality,prod_group,prod_date,supplier_batch)
             VALUES (:r,:ln,:code,:sku,:name,:unit,:qty,:q,:pg,:pd,:batch)"
        );
        $ln = 0;
        $qtyTotal = 0.0;
        $skus = [];
        foreach ($rows as $r) {
            $batch = isset($r['batch']) && trim((string)$r['batch']) !== '' ? trim((string)$r['batch']) : null;
            $unit = isset($r['unit']) && trim((string)$r['unit']) !== '' ? trim((string)$r['unit']) : 'шт';
            $ins->execute([
                ':r' => $rid, ':ln' => ++$ln, ':code' => $r['barcodes'][0] ?? null, ':sku' => $r['sku'],
                ':name' => function_exists('mb_substr') ? mb_substr($r['name'], 0, 255, 'UTF-8') : substr($r['name'], 0, 255),
                ':unit' => function_exists('mb_substr') ? mb_substr($unit, 0, 10, 'UTF-8') : substr($unit, 0, 10),
                ':qty' => $r['qty'], ':q' => $r['quality'] ?? 'Годен',
                ':pg' => ($r['group'] ?? '') !== '' ? $r['group'] : null, ':pd' => $r['prod_date'] ?? null,
                ':batch' => $batch,
            ]);
            $qtyTotal += (float)$r['qty'];
            if (!isset($skus[$r['sku']])) {
                $skus[$r['sku']] = ['name' => $r['name'], 'barcodes' => [], 'group' => (string)($r['group'] ?? '')];
            }
            if ($skus[$r['sku']]['group'] === '' && ($r['group'] ?? '') !== '') {
                $skus[$r['sku']]['group'] = $r['group'];
            }
            foreach ($r['barcodes'] as $bc) {
                if (!in_array($bc, $skus[$r['sku']]['barcodes'], true)) {
                    $skus[$r['sku']]['barcodes'][] = $bc;
                }
            }
        }
        $rcp->prepare("UPDATE receipts SET items_count=:c, sku_count=:s, qty_total=:q WHERE id=:id")
            ->execute([':c' => $ln, ':s' => count($skus), ':q' => $qtyTotal, ':id' => $rid]);
        try {
            $rcp->prepare("INSERT INTO receipt_history (receipt_id,user_id,action,field,old_value,new_value) VALUES (:r,:u,'create','Документ',NULL,:n)")
                ->execute([':r' => $rid, ':u' => $userId, ':n' => 'Загружено из файла: ' . $fileName . ' (' . $ln . ' строк)']);
        } catch (Throwable) {
        }
        $rcp->commit();
    } catch (Throwable $e) {
        if ($rcp->inTransaction()) {
            $rcp->rollBack();
        }
        throw $e;
    }
    // Номенклатура: новые артикулы — черновик с флагом «требуются обмеры».
    // Штрихкоды копим в product_barcodes (и новым, и существующим).
    $newSkus = [];
    $barcodesAdded = 0;
    $find = $nom->prepare("SELECT id, chz_group FROM products WHERE sku = :s LIMIT 1");
    foreach ($skus as $sku => $info) {
        $name = $info['name'];
        $group = $info['group'] !== '' ? $info['group'] : null;
        $find->execute([':s' => $sku]);
        $ex = $find->fetch(PDO::FETCH_ASSOC);
        $pid = $ex ? $ex['id'] : null;
        if (!$pid) {
            $code = csv_upload_next_product_code($nom);
            try {
                $nom->prepare(
                    "INSERT INTO products (code,sku,name,unit,category,chz_group,warehouse_id,need_measure,is_active,is_archived,created_at,updated_at)
                     VALUES (:code,:sku,:name,'шт','Импорт',:g,:w,1,1,0,:now,:now)"
                )->execute([
                    ':code' => $code, ':sku' => $sku,
                    ':name' => function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255),
                    ':g' => $group, ':w' => $warehouseId, ':now' => $now,
                ]);
                $pid = (int)$nom->lastInsertId();
                try {
                    $nom->prepare("INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value) VALUES (:p,:u,'Загрузка CSV','need_measure','Обмеры',NULL,'Требуются обмеры (новый товар из прихода)')")
                        ->execute([':p' => $pid, ':u' => $userId]);
                } catch (Throwable) {
                }
                $newSkus[] = $sku;
            } catch (Throwable) {
                // Гонка/дубликат — считаем существующим
                $find->execute([':s' => $sku]);
                $row = $find->fetch(PDO::FETCH_ASSOC);
                $pid = $row ? $row['id'] : null;
            }
        } elseif ($group !== null && trim((string)($ex['chz_group'] ?? '')) === '') {
            // У существующего группа пуста — заполняем из документа (заведенную вручную не трогаем)
            try {
                $nom->prepare("UPDATE products SET chz_group = :g WHERE id = :id")
                    ->execute([':g' => $group, ':id' => $pid]);
            } catch (Throwable) {
            }
        }
        if ($pid) {
            $barcodesAdded += csv_upload_add_barcodes($nom, (int)$pid, $info['barcodes']);
        }
    }
    return ['id' => $rid, 'doc_no' => $docNo, 'items' => $ln, 'new_skus' => $newSkus, 'barcodes_added' => $barcodesAdded];
}

// ---------- Расход ----------

/**
 * Создает документ отгрузки из строк CSV.
 * $header (из парсинга): consignee, ship_type, marking, serial, comment.
 * @return array{id, doc_no, items}
 */
function csv_upload_import_shipment(PDO $shp, ?PDO $nom, array $rows, int $contractorId, int $warehouseId, int $userId, string $fileName, array $header = []): array
{
    csv_upload_ensure_columns($shp, 'shipment_items', [
        'prod_date' => ['DATE NULL', 'prod_date TEXT'],
        'supplier_batch' => ['VARCHAR(100) NULL', 'supplier_batch TEXT'],
    ]);
    if ($contractorId <= 0) {
        throw new RuntimeException('Не найден контрагент из CSV (таблица contractors недоступна)');
    }
    if ($warehouseId <= 0) {
        throw new RuntimeException('Выбери склад');
    }
    if (!$rows) {
        throw new RuntimeException('Нет корректных строк для загрузки');
    }
    $now = csv_upload_now();
    // Имена товаров подтянем из номенклатуры, если доступна
    $names = [];
    if ($nom !== null) {
        try {
            $skus = array_values(array_unique(array_column($rows, 'sku')));
            $in = implode(',', array_fill(0, count($skus), '?'));
            $st = $nom->prepare("SELECT sku, name FROM products WHERE sku IN ($in)");
            $st->execute($skus);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[$r['sku']] = $r['name'];
            }
        } catch (Throwable) {
        }
    }
    $shp->beginTransaction();
    try {
        $docNo = csv_upload_next_shipment_no($shp);
        $st = $shp->prepare(
            "INSERT INTO shipments (doc_no,doc_date,contractor_id,warehouse_id,status,responsible_id,created_by,comment)
             VALUES (:no,:dt,:c,:w,'assembling',:u,:u,:cmt)"
        );
        $cmt = 'Загружено из CSV: ' . $fileName;
        if (trim((string)($header['external_no'] ?? '')) !== '') {
            $cmt .= ' · Заказ № ' . trim((string)$header['external_no']);
        }
        if (trim((string)($header['comment'] ?? '')) !== '') {
            $cmt .= ' · ' . trim((string)$header['comment']);
        }
        $st->execute([
            ':no' => $docNo, ':dt' => $now, ':c' => $contractorId, ':w' => $warehouseId,
            ':u' => $userId, ':cmt' => $cmt,
        ]);
        $sid = (int)$shp->lastInsertId();
        $ins = $shp->prepare(
            "INSERT INTO shipment_items (shipment_id,sku,name,unit,qty,price," . wms_quote_ident($shp, 'sum') . ",prod_date,supplier_batch)
             VALUES (:s,:sku,:name,:unit,:qty,0,0,:pd,:b)"
        );
        $qtyTotal = 0.0;
        $skus = [];
        foreach ($rows as $r) {
            // Наименование: номенклатура — источник правды, наименование из файла — запасной вариант.
            $name = (string)($names[$r['sku']] ?? '');
            if ($name === '') {
                $name = trim((string)($r['name'] ?? ''));
            }
            if ($name === '') {
                $name = (string)$r['sku'];
            }
            $unit = trim((string)($r['unit'] ?? ''));
            if ($unit === '') {
                $unit = 'шт';
            }
            $ins->execute([
                ':s' => $sid, ':sku' => $r['sku'],
                ':name' => function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255),
                ':unit' => function_exists('mb_substr') ? mb_substr($unit, 0, 10, 'UTF-8') : substr($unit, 0, 10),
                ':qty' => $r['qty'], ':pd' => $r['prod_date'] ?? null,
                ':b' => trim((string)($r['batch'] ?? '')) ?: null,
            ]);
            $qtyTotal += (float)$r['qty'];
            $skus[$r['sku']] = true;
        }
        $shp->prepare("UPDATE shipments SET items_count=:c, sku_count=:s, qty_total=:q WHERE id=:id")
            ->execute([':c' => count($rows), ':s' => count($skus), ':q' => $qtyTotal, ':id' => $sid]);
        csv_upload_apply_shipment_header($shp, $sid, $header);
        try {
            $shp->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,old_status,new_status) VALUES (:s,:u,'create',NULL,'assembling')")
                ->execute([':s' => $sid, ':u' => $userId]);
        } catch (Throwable) {
        }
        $shp->commit();
    } catch (Throwable $e) {
        if ($shp->inTransaction()) {
            $shp->rollBack();
        }
        throw $e;
    }
    return ['id' => $sid, 'doc_no' => $docNo, 'items' => count($rows)];
}

// ---------- Наряды (заказы) из расхода ----------

/**
 * Создает наряд (заказ) из строк CSV расхода. Номер — случайный внутренний or_XXXXXX.
 * Наряд потом выбирается в мастере "Формирование отгрузки" (кандидаты).
 * $header (из парсинга): consignee (Получатель из CSV — приоритет), comment.
 * @return array{id, order_no, doc_no, items}
 */
function csv_upload_import_orders(PDO $shp, ?PDO $nom, array $rows, int $contractorId, int $warehouseId, int $userId, string $fileName, ?string $docNo = null, array $header = []): array
{
    csv_upload_ensure_order_items_table($shp);
    if ($contractorId <= 0) {
        throw new RuntimeException('Не найден контрагент из CSV (таблица contractors недоступна)');
    }
    if ($warehouseId <= 0) {
        throw new RuntimeException('Выбери склад');
    }
    if (!$rows) {
        throw new RuntimeException('Нет корректных строк для загрузки');
    }
    $now = csv_upload_now();
    // Имена товаров подтянем из номенклатуры, если доступна
    $names = [];
    if ($nom !== null) {
        try {
            $skus = array_values(array_unique(array_column($rows, 'sku')));
            $in = implode(',', array_fill(0, count($skus), '?'));
            $st = $nom->prepare("SELECT sku, name FROM products WHERE sku IN ($in)");
            $st->execute($skus);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $names[$r['sku']] = $r['name'];
            }
        } catch (Throwable) {
        }
    }
    // Получатель из CSV — приоритет; иначе имя контрагента (для кандидатов).
    $consignee = trim((string)($header['consignee'] ?? ''));
    if ($consignee === '') {
        $consignee = null;
        try {
            $cs = $shp->prepare('SELECT name FROM contractors WHERE id = :id');
            $cs->execute([':id' => $contractorId]);
            $consignee = $cs->fetchColumn() ?: null;
        } catch (Throwable) {
        }
    }
    $shp->beginTransaction();
    try {
        $docNo = $docNo ?? csv_upload_next_order_no($shp);
        try {
            $dup = $shp->prepare('SELECT 1 FROM client_orders WHERE doc_no = :n LIMIT 1');
            $dup->execute([':n' => $docNo]);
            if ($dup->fetchColumn()) {
                throw new RuntimeException('Наряд с номером ' . $docNo . ' уже существует');
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
        }
        $st = $shp->prepare(
            "INSERT INTO client_orders (doc_no,contractor_id,consignee,warehouse_id,status,comment,created_at)
             VALUES (:no,:c,:csg,:w,'created',:cmt,:now)"
        );
        $cmt = 'Наряд, загружено из CSV: ' . $fileName;
        if (trim((string)($header['external_no'] ?? '')) !== '') {
            $cmt .= ' · Заказ № ' . trim((string)$header['external_no']);
        }
        if (trim((string)($header['comment'] ?? '')) !== '') {
            $cmt .= ' · ' . trim((string)$header['comment']);
        }
        $st->execute([
            ':no' => $docNo, ':c' => $contractorId, ':csg' => $consignee, ':w' => $warehouseId,
            ':cmt' => $cmt, ':now' => $now,
        ]);
        $oid = (int)$shp->lastInsertId();
        $ins = $shp->prepare(
            "INSERT INTO client_order_items (order_id,sku,name,unit,qty,supplier_batch,prod_date)
             VALUES (:o,:sku,:name,:unit,:qty,:b,:pd)"
        );
        $qtyTotal = 0.0;
        foreach ($rows as $r) {
            // Наименование: номенклатура — источник правды, наименование из файла — запасной вариант.
            $name = (string)($names[$r['sku']] ?? '');
            if ($name === '') {
                $name = trim((string)($r['name'] ?? ''));
            }
            if ($name === '') {
                $name = (string)$r['sku'];
            }
            $unit = trim((string)($r['unit'] ?? ''));
            if ($unit === '') {
                $unit = 'шт';
            }
            $ins->execute([
                ':o' => $oid, ':sku' => $r['sku'],
                ':name' => function_exists('mb_substr') ? mb_substr($name, 0, 255, 'UTF-8') : substr($name, 0, 255),
                ':unit' => function_exists('mb_substr') ? mb_substr($unit, 0, 10, 'UTF-8') : substr($unit, 0, 10),
                ':qty' => $r['qty'], ':b' => trim((string)($r['batch'] ?? '')) ?: null,
                ':pd' => $r['prod_date'] ?? null,
            ]);
            $qtyTotal += (float)$r['qty'];
        }
        $shp->commit();
    } catch (Throwable $e) {
        if ($shp->inTransaction()) {
            $shp->rollBack();
        }
        throw $e;
    }
    return ['id' => $oid, 'order_no' => $docNo, 'doc_no' => $docNo, 'items' => count($rows), 'qty_total' => $qtyTotal];
}

// ---------- Файл марок ЧЗ на документ ----------

/** Гарантировать таблицу реестра марок (переносимо MySQL/SQLite). */
function csv_upload_ensure_receipt_marks(PDO $pdo): void
{
    try {
        $pdo->query("SELECT 1 FROM receipt_marks LIMIT 1")->fetchColumn();
        return;
    } catch (Throwable) {
    }
    try {
        if (csv_upload_is_mysql($pdo)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_marks (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, receipt_id BIGINT UNSIGNED NOT NULL,
              sku VARCHAR(50) NOT NULL, marking_code VARCHAR(255) NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'expected', accepted_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_receipt_mark (receipt_id, marking_code),
              KEY idx_rm_receipt (receipt_id), KEY idx_rm_sku (sku)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS receipt_marks (
              id INTEGER PRIMARY KEY AUTOINCREMENT, receipt_id INTEGER NOT NULL, sku TEXT NOT NULL,
              marking_code TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'expected',
              accepted_at TEXT, created_at TEXT DEFAULT (datetime('now')))");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_receipt_mark ON receipt_marks(receipt_id, marking_code)");
        }
    } catch (Throwable) {
    }
}

/**
 * Парсинг файла марок: CSV с заголовком `артикул;марка`, разделитель «;».
 * @return array{rows: array{sku,mark}[], errors: string[], total: int}
 */
function csv_upload_parse_marks(array $file): array
{
    $err = static function (string $t): array {
        return ['rows' => [], 'errors' => [$t], 'total' => 0];
    };
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
        return $err('Файл не загружен');
    }
    if (($file['error'] ?? 0) !== 0) {
        return $err('Ошибка загрузки файла (код ' . (int)$file['error'] . ')');
    }
    $raw = @file_get_contents($tmp);
    if ($raw === false || trim($raw) === '') {
        return $err('Файл пуст');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!preg_match('//u', $raw)) {
        if (function_exists('mb_convert_encoding')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        } elseif (function_exists('iconv')) {
            $conv = @iconv('Windows-1251', 'UTF-8//IGNORE', $raw);
            if ($conv !== false) {
                $raw = $conv;
            }
        }
    }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);
    while ($lines && trim((string)$lines[0]) === '') {
        array_shift($lines);
    }
    while ($lines && trim((string)end($lines)) === '') {
        array_pop($lines);
    }
    if (count($lines) < 2) {
        return $err('В файле нет данных (нужны строка заголовка и хотя бы одна строка)');
    }
    $head = array_shift($lines);
    if (!str_contains($head, ';') && !str_contains($head, "\t")) {
        return $err('Не найден разделитель «;». Формат: артикул;марка');
    }
    $delim = str_contains($head, ';') ? ';' : "\t";
    $map = [];
    foreach (str_getcsv($head, $delim) as $i => $cell) {
        $n = csv_upload_norm_header((string)$cell);
        if (in_array($n, ['артикул', 'sku', 'artikul'], true) && !isset($map['sku'])) {
            $map['sku'] = $i;
        }
        if (in_array($n, ['марка', 'кодмарки', 'маркировка', 'mark', 'markingcode', 'ки'], true) && !isset($map['mark'])) {
            $map['mark'] = $i;
        }
    }
    if (!isset($map['sku']) || !isset($map['mark'])) {
        return $err('В заголовке нужны колонки «артикул» и «марка». Заголовок файла: ' . trim($head));
    }
    $rows = [];
    $errors = [];
    $seen = [];
    foreach ($lines as $k => $ln) {
        $n = $k + 2;
        if (trim($ln) === '') {
            continue;
        }
        $cells = str_getcsv($ln, $delim);
        $sku = trim((string)($cells[$map['sku']] ?? ''));
        $mark = trim((string)($cells[$map['mark']] ?? ''));
        if ($sku === '' || $mark === '') {
            $errors[] = "Строка $n: пустые артикул или марка";
            continue;
        }
        $key = $sku . "\x00" . $mark;
        if (isset($seen[$key])) {
            continue; // дубль внутри файла — молча
        }
        $seen[$key] = true;
        $rows[] = ['sku' => $sku, 'mark' => $mark];
    }
    return ['rows' => $rows, 'errors' => $errors, 'total' => count($lines)];
}

/**
 * Записать марки в реестр документа. Марки чужих артикулов отклоняются построчно.
 * @return array{loaded, skipped_dup, by_sku, row_errors}
 */
function csv_upload_import_marks(PDO $rcp, int $rid, array $rows, int $userId, string $fileName): array
{
    csv_upload_ensure_receipt_marks($rcp);
    $h = $rcp->prepare("SELECT status FROM receipts WHERE id = :id");
    $h->execute([':id' => $rid]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        throw new RuntimeException('Документ не найден');
    }
    if (in_array($head['status'], ['closed', 'closed_short', 'cancelled', 'archived'], true)) {
        throw new RuntimeException('Документ уже закрыт/отменен — марки не загружаются');
    }
    if (!$rows) {
        throw new RuntimeException('Нет корректных строк для загрузки');
    }
    $skus = [];
    $q = $rcp->prepare("SELECT DISTINCT sku FROM receipt_items WHERE receipt_id = :r");
    $q->execute([':r' => $rid]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $skus[$s] = true;
    }
    $ins = $rcp->prepare("INSERT INTO receipt_marks (receipt_id, sku, marking_code, status) VALUES (:r, :s, :m, 'expected')");
    $loaded = 0;
    $dups = 0;
    $bySku = [];
    $rowErrors = [];
    foreach ($rows as $i => $r) {
        if (!isset($skus[$r['sku']])) {
            $rowErrors[] = 'Строка ' . ($i + 2) . ' (' . $r['sku'] . '): артикула нет в документе';
            continue;
        }
        try {
            $ins->execute([':r' => $rid, ':s' => $r['sku'], ':m' => $r['mark']]);
            $loaded++;
            $bySku[$r['sku']] = ($bySku[$r['sku']] ?? 0) + 1;
        } catch (Throwable) {
            $dups++; // уже есть в реестре
        }
    }
    try {
        $rcp->prepare("INSERT INTO receipt_history (receipt_id,user_id,action,field,old_value,new_value) VALUES (:r,:u,'marks','Марки ЧЗ',NULL,:n)")
            ->execute([':r' => $rid, ':u' => $userId, ':n' => 'Загружено из файла ' . $fileName . ': ' . $loaded . ' марок']);
    } catch (Throwable) {
    }
    return ['loaded' => $loaded, 'skipped_dup' => $dups, 'by_sku' => $bySku, 'row_errors' => $rowErrors];
}

// ---------- Файл марок ЧЗ на документ отгрузки ----------

/** Гарантировать таблицу реестра марок отгрузки (переносимо MySQL/SQLite). */
function csv_upload_ensure_shipment_marks(PDO $pdo): void
{
    try {
        $pdo->query("SELECT 1 FROM shipment_marks LIMIT 1")->fetchColumn();
    } catch (Throwable) {
        try {
            if (csv_upload_is_mysql($pdo)) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_marks (
                  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, shipment_id BIGINT UNSIGNED NOT NULL,
                  sku VARCHAR(50) NOT NULL, marking_code TEXT NOT NULL, prod_date DATE NULL,
                  status VARCHAR(20) NOT NULL DEFAULT 'expected', accepted_at DATETIME NULL,
                  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  UNIQUE KEY uq_shipment_mark (shipment_id, marking_code(191)),
                  KEY idx_sm_shipment (shipment_id), KEY idx_sm_sku (sku)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } else {
                $pdo->exec("CREATE TABLE IF NOT EXISTS shipment_marks (
                  id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INTEGER NOT NULL, sku TEXT NOT NULL,
                  marking_code TEXT NOT NULL, prod_date TEXT, status TEXT NOT NULL DEFAULT 'expected',
                  accepted_at TEXT, created_at TEXT DEFAULT (datetime('now')))");
                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_shipment_mark ON shipment_marks(shipment_id, marking_code)");
            }
        } catch (Throwable) {
        }
    }
    // Дата производства марки — по ней подбирается «подходящая» марка при сортировке.
    csv_upload_ensure_columns($pdo, 'shipment_marks', [
        'prod_date' => ['DATE NULL', 'prod_date TEXT', 'prod_date DATE NULL'],
    ]);
}

/**
 * Записать марки в реестр отгрузки. Марки чужих артикулов отклоняются построчно.
 * @return array{loaded, skipped_dup, by_sku, row_errors}
 */
function csv_upload_import_shipment_marks(PDO $pdo, int $shipmentId, array $rows, ?int $userId, string $fileName): array
{
    csv_upload_ensure_shipment_marks($pdo);
    $h = $pdo->prepare("SELECT status FROM shipments WHERE id = :id");
    $h->execute([':id' => $shipmentId]);
    $head = $h->fetch(PDO::FETCH_ASSOC);
    if (!$head) {
        throw new RuntimeException('Документ не найден');
    }
    if (in_array((string)($head['status'] ?? ''), ['cancelled', 'archived', 'delivered'], true)) {
        throw new RuntimeException('Документ закрыт — марки не загружаются');
    }
    if (!$rows) {
        throw new RuntimeException('Нет корректных строк для загрузки');
    }
    $skus = [];
    $q = $pdo->prepare("SELECT DISTINCT sku FROM shipment_items WHERE shipment_id = :r");
    $q->execute([':r' => $shipmentId]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $s) {
        $skus[$s] = true;
    }
    $ins = $pdo->prepare("INSERT INTO shipment_marks (shipment_id, sku, marking_code, status) VALUES (:r, :s, :m, 'expected')");
    $loaded = 0;
    $dups = 0;
    $bySku = [];
    $rowErrors = [];
    // Марка честного знака уникальна в системе маркировки: та же марка не может
    // стоять в двух отгрузках. Проверяем ДО вставки, иначе дубль проходил бы,
    // а расхождения вскрылись бы только при сортировке.
    $findOther = $pdo->prepare(
        "SELECT shipment_id FROM shipment_marks WHERE marking_code = :m AND shipment_id <> :r LIMIT 1"
    );
    foreach ($rows as $i => $r) {
        if (!isset($skus[$r['sku']])) {
            $rowErrors[] = 'Строка ' . ($i + 2) . ' (' . $r['sku'] . '): артикула нет в документе';
            continue;
        }
        try {
            $findOther->execute([':m' => $r['mark'], ':r' => $shipmentId]);
            $other = $findOther->fetchColumn();
            if ($other !== false && $other !== null) {
                $rowErrors[] = 'Строка ' . ($i + 2) . ': марка ' . $r['mark'] . ' уже загружена в другую отгрузку ( #' . (int)$other . ')';
                continue;
            }
            $ins->execute([':r' => $shipmentId, ':s' => $r['sku'], ':m' => $r['mark']]);
            $loaded++;
            $bySku[$r['sku']] = ($bySku[$r['sku']] ?? 0) + 1;
        } catch (Throwable) {
            $dups++; // уже есть в реестре
        }
    }
    try {
        $pdo->prepare("INSERT INTO shipment_history (shipment_id,user_id,action,field,old_value,new_value) VALUES (:s,:u,'marks','Марки ЧЗ',NULL,:n)")
            ->execute([':s' => $shipmentId, ':u' => $userId, ':n' => 'Загружено из файла ' . $fileName . ': ' . $loaded . ' марок']);
    } catch (Throwable) {
    }
    return ['loaded' => $loaded, 'skipped_dup' => $dups, 'by_sku' => $bySku, 'row_errors' => $rowErrors];
}

// ---------- Обмеры для ТСД ----------

/**
 * Карточка обмеров по артикулу: null — товара нет в номенклатуре.
 * $rcp (БД поступлений, опционально): свежие приёмки (операции «Принято» за 2 мес)
 * тоже считаются активностью — товар реально был в руках, обмеры не требуем.
 */
function csv_upload_measure(PDO $nom, string $sku, ?PDO $rcp = null): ?array
{
    csv_upload_ensure_columns($nom, 'products', [
        'need_measure' => ['TINYINT(1) NOT NULL DEFAULT 0', 'need_measure INTEGER DEFAULT 0'],
    ]);
    $st = $nom->prepare("SELECT id, sku, name, length_mm, width_mm, height_mm, weight_g, need_measure, chz_group FROM products WHERE sku = :s LIMIT 1");
    $st->execute([':s' => $sku]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $hasDims = $row['length_mm'] !== null && $row['width_mm'] !== null
        && $row['height_mm'] !== null && $row['weight_g'] !== null;
    // Новый товар = флаг из прихода / нет габаритов / никогда не было на складе
    // (нет движений и остатков) / последнее движение старше 2 месяцев.
    $need = false;
    $reason = null;
    if ((int)($row['need_measure'] ?? 0) === 1) {
        $need = true;
        $reason = 'flag';
    } elseif (!$hasDims) {
        $need = true;
        $reason = 'nodims';
    } else {
        try {
            $lm = $nom->prepare("SELECT MAX(doc_date) FROM product_movements WHERE product_id = :p");
            $lm->execute([':p' => $row['id']]);
            $last = $lm->fetchColumn();
            $hs = $nom->prepare("SELECT COUNT(*) FROM product_stock WHERE product_id = :p");
            $hs->execute([':p' => $row['id']]);
            $hasStock = (int)$hs->fetchColumn() > 0;
            if ((!$last && !$hasStock) || ($last && $last < date('Y-m-d H:i:s', strtotime('-2 months')))) {
                $need = true;
                $reason = !$last ? 'new' : 'stale';
            }
        } catch (Throwable) {
        }
    }
    // Свежая приёмка (в т.ч. в незакрытом документе) — товар был в руках:
    // снимаем требование по причинам new/stale. Флаг из прихода и отсутствие
    // габаритов требуют обмеров без исключений.
    if ($need && ($reason === 'new' || $reason === 'stale') && $rcp !== null) {
        try {
            $op = $rcp->prepare("SELECT MAX(accept_date) FROM receipt_operations WHERE sku = :s AND accept_status = 'Принято'");
            $op->execute([':s' => $sku]);
            $lastOp = $op->fetchColumn();
            if ($lastOp && $lastOp >= date('Y-m-d H:i:s', strtotime('-2 months'))) {
                $need = false;
                $reason = null;
            }
        } catch (Throwable) {
        }
    }
    return [
        'sku' => $row['sku'],
        'name' => $row['name'],
        'chz_group' => $row['chz_group'] ?? null,
        'need' => $need,
        'measure_reason' => $reason,
        'length_mm' => $row['length_mm'] === null ? null : (int)$row['length_mm'],
        'width_mm' => $row['width_mm'] === null ? null : (int)$row['width_mm'],
        'height_mm' => $row['height_mm'] === null ? null : (int)$row['height_mm'],
        'weight_g' => $row['weight_g'] === null ? null : (int)$row['weight_g'],
    ];
}

/** Сохранить обмеры с ТСД (новый товар) — снимает флаг need_measure. */
function csv_upload_save_measure(PDO $nom, string $sku, int $l, int $w, int $h, int $wt, ?int $userId, string $fio = 'ТСД'): array
{
    foreach (['length_mm' => $l, 'width_mm' => $w, 'height_mm' => $h, 'weight_g' => $wt] as $k => $v) {
        if ($v <= 0) {
            throw new RuntimeException('Все обмеры должны быть больше нуля');
        }
    }
    $st = $nom->prepare("SELECT id FROM products WHERE sku = :s LIMIT 1");
    $st->execute([':s' => $sku]);
    $pid = $st->fetchColumn();
    if (!$pid) {
        throw new RuntimeException('Товар не найден в номенклатуре: ' . $sku);
    }
    $vol = round(($l / 1000) * ($w / 1000) * ($h / 1000), 6);
    $nom->prepare("UPDATE products SET length_mm=:l, width_mm=:w, height_mm=:h, weight_g=:wt, volume_m3=:v, need_measure=0 WHERE id=:id")
        ->execute([':l' => $l, ':w' => $w, ':h' => $h, ':wt' => $wt, ':v' => $vol, ':id' => $pid]);
    try {
        $nom->prepare("INSERT INTO product_history (product_id,user_id,user_fio,field,field_label,old_value,new_value) VALUES (:p,:u,:f,'measure','Обмеры',NULL,:n)")
            ->execute([':p' => $pid, ':u' => $userId, ':f' => $fio,
                ':n' => "Д×Ш×В: {$l}×{$w}×{$h} мм, вес: {$wt} г (замер ТСД)"]);
    } catch (Throwable) {
    }
    return ['ok' => true, 'length_mm' => $l, 'width_mm' => $w, 'height_mm' => $h, 'weight_g' => $wt];
}
