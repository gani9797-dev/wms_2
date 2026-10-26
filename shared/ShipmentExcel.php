<?php
declare(strict_types=1);

// Импорт заказа/заявки на отгрузку из Excel (.xlsx) + гибкий разбор форматов.
// Зеркало shared/ReceiptExcel.php (приход): те же семейства форматов, только
// смысл колонок другой.
// Поддерживаем два семейства форматов:
//   1) «Заявка на отгрузку»: шапка сверху (номер заказа, дата отгрузки,
//      получатель, контрагент, тип отгрузки) + таблица груза с колонками
//      №, Артикул, Наименование, Количество, Ед. изм., Партия.
//   2) Плоская таблица: одна строка-заголовок (артикул, количество, ...) + строки.
// Плюс строгий legacy-CSV (Контрагент;Получатель;Тип отгрузки;артикул;количество;...)
// как запасной вариант, если таблица груза не распозналась.

require_once __DIR__ . '/CsvUpload.php';
require_once __DIR__ . '/XlsxGrid.php';

// ---------- Синонимы колонок таблицы груза расхода (нормализованные) ----------

function shipment_table_synonyms(): array
{
    return [
        'contractor' => ['контрагент', 'покупатель', 'клиент', 'contractor', 'заказчик', 'получательзаказа'],
        'consignee' => ['получатель', 'грузополучатель', 'consignee', 'адресат', 'место доставки', 'местоДоставки'],
        'ship_type' => ['типотгрузки', 'типдоставки', 'тип', 'способдоставки', 'доставка', 'deliverytype',
            'shipmenttype', 'видотгрузки', 'условиядоставки'],
        'line_no' => ['n', 'п/п', 'пп', 'номер', 'номерпп', 'строка', 'no'],
        'sku' => ['артикул', 'sku', 'artikul', 'кодтовара', 'код'],
        'name' => ['наименование', 'наименованиеполное', 'наименованиеполн', 'название', 'name', 'товар', 'номенклатура'],
        'qty' => ['количество', 'количествоплан', 'колво', 'qty', 'quantity', 'шт', 'колвошт', 'план', 'объем', 'объём'],
        'unit' => ['единица', 'единицаизмерения', 'едизм', 'ед', 'ед изм', 'еи', 'unit'],
        'batch' => ['партия', 'партияпоставщика', 'партиятовара', 'batch', 'lot', 'lotno', 'партияпоставки'],
        'barcode' => ['штрихкод', 'штрихкодтовара', 'шк', 'barcode', 'ean', 'ean13', 'штрихкодean'],
        'prod_date' => ['датапроизводства', 'датавыпуска', 'proddate', 'productiondate'],
        'external_no' => ['номерзаказа', 'номерзаявки', 'номердокумента', 'внешнийномер',
            'orderno', 'asn'],
        'plan_date' => ['датаотгрузки', 'датадоставки', 'датаотправки', 'планируемаядата',
            'shipdate', 'plandate'],
        'marking' => ['признаксканированиямарки', 'сканированиемарки', 'признакмаркировки', 'маркировка',
            'маркачестногознака', 'marking', 'чз'],
        'serial' => ['признаксканированиясн', 'сканированиесн', 'признаксканированиясерийныхномеров',
            'серийники', 'серийныйномер', 'serial', 'сн'],
        'comment' => ['комментарий', 'коммент', 'comment', 'примечание'],
    ];
}

/** Метаданные шапки заявки на отгрузку: контрагент, номер заказа, дата, получатель, тип. */
function shipment_parse_meta(array $grid, ?int $tableRow): array
{
    $meta = ['contractor' => '', 'external_no' => '', 'plan_date' => null, 'consignee' => '',
        'ship_type' => '', 'contract_no' => ''];
    $limit = $tableRow ?? count($grid);
    for ($ri = 0; $ri < $limit && $ri < count($grid); $ri++) {
        $row = $grid[$ri];
        $rowText = wms_norm_cell(implode(' ', $row));
        foreach ($row as $ci => $cell) {
            // Метка и значение могут быть в одной ячейке («Дата отгрузки: 14.10.2026»)
            // или в соседних («Дата отгрузки» | «14.10.2026»).
            [$label, $inline] = wms_split_label((string)$cell);
            $n = wms_norm_cell($label);
            if ($n === '') {
                continue;
            }
            $value = static fn() => $inline !== '' ? $inline : wms_meta_value($grid, $ri, $ci);
            // Номер заказа клиента
            if (in_array($n, ['номерзаказа', 'номерзаявки', 'заказ', 'заявка', 'номердокумента',
                'внешнийномер', 'asn', 'orderno', 'номерзаказаклиента'], true)
                || str_contains($n, 'номерзаказа') || str_contains($n, 'номерзаявки')) {
                $v = $value();
                if ($v !== '' && $meta['external_no'] === '') {
                    $meta['external_no'] = $v;
                }
            }
            // Дата отгрузки / доставки
            if (str_contains($n, 'датаотгрузки') || str_contains($n, 'датадоставки')
                || str_contains($n, 'датаотправки') || str_contains($n, 'планируемаядата')
                || ($n === 'дата' && (str_contains($rowText, 'отгруз') || str_contains($rowText, 'достав')))) {
                $v = $value();
                $d = $v !== '' ? wms_parse_plan_date($v) : null;
                if ($d !== null && $meta['plan_date'] === null) {
                    $meta['plan_date'] = $d;
                }
            }
            // Контрагент (покупатель)
            if ($n === 'контрагент' || $n === 'покупатель' || $n === 'клиент' || $n === 'заказчик'
                || str_contains($n, 'контрагент') || str_contains($n, 'покупатель')) {
                $v = $value();
                if ($v !== '' && $meta['contractor'] === '') {
                    $meta['contractor'] = $v;
                }
            }
            // Получатель (грузополучатель)
            if ($n === 'получатель' || $n === 'грузополучатель' || $n === 'адресат'
                || str_contains($n, 'грузополучатель')) {
                $v = $value();
                if ($v !== '' && $meta['consignee'] === '') {
                    $meta['consignee'] = $v;
                }
            }
            // Тип отгрузки / способ доставки
            if (str_contains($n, 'типотгрузки') || str_contains($n, 'типдоставки')
                || str_contains($n, 'способдоставки') || str_contains($n, 'видотгрузки')) {
                $v = $value();
                if ($v !== '' && $meta['ship_type'] === '') {
                    $meta['ship_type'] = $v;
                }
            }
            // Договор/приложение
            if (str_contains($n, 'договор') || str_contains($n, 'приложение')) {
                $v = $value();
                if ($v !== '' && $meta['contract_no'] === '') {
                    $meta['contract_no'] = $label . ': ' . $v;
                }
            }
        }
    }
    return $meta;
}

/**
 * Разобрать сетку в строки расхода.
 * @return array{rows: array, header: array, errors: string[], table_header_row: ?int, format: string}
 */
function shipment_grid_parse(array $grid): array
{
    $found = wms_find_table_header($grid, shipment_table_synonyms());
    if ($found === null) {
        $preview = [];
        foreach (array_slice($grid, 0, 8) as $r) {
            $preview[] = implode(' | ', array_slice($r, 0, 8));
        }
        throw new RuntimeException(
            'Не найдена таблица груза: нужна строка заголовка с колонками «Артикул» и «Количество». ' .
            'Первые строки файла: ' . implode(' / ', $preview)
        );
    }
    $map = $found['map'];
    $hri = $found['row'];
    $meta = shipment_parse_meta($grid, $hri);
    $rows = [];
    $errors = [];
    $emptyStreak = 0;
    $colContractor = '';
    $colConsignee = '';
    $colShipType = '';
    $colExternalNo = '';
    $colPlanDate = '';
    $marking = false;
    $serial = false;
    for ($ri = $hri + 1; $ri < count($grid); $ri++) {
        $row = $grid[$ri];
        $get = static fn(string $f) => isset($map[$f]) ? trim((string)($row[$map[$f]] ?? '')) : '';
        $sku = $get('sku');
        $name = $get('name');
        $qtyRaw = $get('qty');
        $unit = $get('unit');
        $batch = $get('batch');
        $pdRaw = $get('prod_date');
        // Пустая строка
        if ($sku === '' && $name === '' && $qtyRaw === '' && $batch === '') {
            $emptyStreak++;
            if ($emptyStreak >= 5) {
                break;
            }
            continue;
        }
        $emptyStreak = 0;
        // Строка итога
        $joined = wms_norm_cell(implode('', $row));
        if (str_contains($joined, 'итого') || str_contains($joined, 'всего')) {
            continue;
        }
        if ($sku === '') {
            // Строка без артикула — пропуск с предупреждением (не ошибка импорта)
            continue;
        }
        $qty = csv_upload_norm_qty($qtyRaw);
        if ($qty === null || $qty <= 0) {
            $errors[] = 'Строка ' . ($ri + 1) . " ($sku): количество должно быть числом > 0 (значение: «$qtyRaw»)";
            continue;
        }
        $prodDate = $pdRaw === '' ? null : csv_upload_norm_date($pdRaw);
        if ($pdRaw !== '' && $prodDate === null) {
            $errors[] = 'Строка ' . ($ri + 1) . " ($sku): дата производства — формат ГГГГ-ММ-ДД или ДД.ММ.ГГГГ";
            continue;
        }
        if ($name === '') {
            $name = $sku;
        }
        // Шапка из колонок плоской таблицы: первое непустое значение
        if ($colContractor === '' && isset($map['contractor'])) {
            $colContractor = trim((string)($row[$map['contractor']] ?? ''));
        }
        if ($colConsignee === '' && isset($map['consignee'])) {
            $colConsignee = trim((string)($row[$map['consignee']] ?? ''));
        }
        if ($colShipType === '' && isset($map['ship_type'])) {
            $colShipType = trim((string)($row[$map['ship_type']] ?? ''));
        }
        if ($colExternalNo === '' && isset($map['external_no'])) {
            $colExternalNo = trim((string)($row[$map['external_no']] ?? ''));
        }
        if ($colPlanDate === '' && isset($map['plan_date'])) {
            $colPlanDate = trim((string)($row[$map['plan_date']] ?? ''));
        }
        if (csv_upload_bool_flag($get('marking'))) {
            $marking = true;
        }
        if (csv_upload_bool_flag($get('serial'))) {
            $serial = true;
        }
        $rows[] = [
            'sku' => $sku,
            'name' => $name,
            'qty' => $qty,
            'unit' => $unit !== '' ? $unit : 'шт',
            'prod_date' => $prodDate,
            'batch' => $batch !== '' ? $batch : null,
            'comment' => $get('comment'),
        ];
    }
    if (!$rows) {
        $msg = 'В таблице груза нет строк с артикулом и количеством';
        if ($errors) {
            $msg .= ': ' . implode('; ', array_slice($errors, 0, 3));
        }
        throw new RuntimeException($msg);
    }
    // Комментарии строк -> шапка
    $comments = [];
    foreach ($rows as $r) {
        if (($r['comment'] ?? '') !== '' && !in_array($r['comment'], $comments, true)) {
            $comments[] = $r['comment'];
        }
    }
    // Формат: заявка (шапка над таблицей) или плоская таблица.
    $format = ($hri > 2 || $meta['external_no'] !== '' || $meta['contractor'] !== '') ? 'zayavka' : 'flat';
    if ($meta['contractor'] === '' && $colContractor !== '') {
        $meta['contractor'] = $colContractor;
    }
    if ($meta['consignee'] === '' && $colConsignee !== '') {
        $meta['consignee'] = $colConsignee;
    }
    if ($meta['ship_type'] === '' && $colShipType !== '') {
        $meta['ship_type'] = $colShipType;
    }
    if ($meta['external_no'] === '' && $colExternalNo !== '') {
        $meta['external_no'] = $colExternalNo;
    }
    if ($meta['plan_date'] === null && $colPlanDate !== '') {
        $d = csv_upload_norm_date($colPlanDate);
        if ($d !== null) {
            $meta['plan_date'] = $d;
        }
    }
    return [
        'rows' => $rows,
        'header' => [
            'contractor' => $meta['contractor'],
            'consignee' => $meta['consignee'],
            'ship_type' => $meta['ship_type'],
            'marking' => $marking,
            'serial' => $serial,
            'external_no' => $meta['external_no'],
            'plan_date' => $meta['plan_date'],
            'contract_no' => $meta['contract_no'],
            'comment' => implode('; ', $comments),
        ],
        'errors' => $errors,
        'table_header_row' => $hri + 1, // 1-based для сообщений
        'format' => $format,
    ];
}

/**
 * Универсальная точка входа расхода: файл из $_FILES -> строки + шапка.
 * Поддерживает .xlsx/.xlsm (заявка или плоская таблица) и .csv/.txt.
 * @return array{rows: array, header: array, errors: string[], total: int, format: string, filename: string}
 */
function shipment_upload_parse_file(array $file): array
{
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
        throw new RuntimeException('Файл не загружен');
    }
    if (($file['error'] ?? 0) !== 0) {
        throw new RuntimeException('Ошибка загрузки файла (код ' . (int)$file['error'] . ')');
    }
    $name = (string)($file['name'] ?? 'upload');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['xlsx', 'xlsm'], true)) {
        $read = wms_xlsx_read_grid($tmp);
        $parsed = shipment_grid_parse($read['grid']);
        return [
            'rows' => $parsed['rows'],
            'header' => $parsed['header'],
            'errors' => $parsed['errors'],
            'total' => count($read['grid']),
            'format' => $parsed['format'],
            'filename' => $name,
        ];
    }
    if (in_array($ext, ['xls'], true)) {
        throw new RuntimeException('Формат .xls (Excel 97-2003) не поддерживается — сохрани файл как .xlsx или CSV');
    }
    // CSV/TXT: сначала гибкий парсер сетки (ловит и заявку, сохраненную как CSV,
    // и плоские таблицы с наименованием/партией/ед. изм.), при неудаче — строгий legacy.
    $read = wms_csv_read_grid($tmp);
    try {
        $parsed = shipment_grid_parse($read['grid']);
        return [
            'rows' => $parsed['rows'],
            'header' => $parsed['header'],
            'errors' => $parsed['errors'],
            'total' => count($read['grid']),
            'format' => $parsed['format'] . '+csv',
            'filename' => $name,
        ];
    } catch (Throwable $eGrid) {
        // Legacy строгий формат расхода (Контрагент;Получатель;Тип отгрузки;артикул;количество;...)
        $legacy = csv_upload_parse($file, csv_upload_shipment_spec());
        if ($legacy['rows']) {
            return [
                'rows' => $legacy['rows'],
                'header' => $legacy['header'] ?? [],
                'errors' => $legacy['errors'],
                'total' => $legacy['total'],
                'format' => 'legacy-csv',
                'filename' => $name,
            ];
        }
        throw new RuntimeException($eGrid->getMessage() . ' / ' . implode('; ', array_slice($legacy['errors'], 0, 2)));
    }
}
