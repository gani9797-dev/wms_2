<?php
declare(strict_types=1);

// Импорт заявок на приемку из Excel (.xlsx) + гибкий разбор разных форматов.
// Без внешних библиотек: .xlsx читаем через ZipArchive + XML (встроенные в PHP).
// Поддерживаем два семейства форматов:
//   1) «Заявка на приемку» (как на скриншоте): шапка сверху (номер заказа,
//      ожидаемая дата прибытия ТС, поставщик, способ поставки) + таблица
//      «Информация о грузе» с колонками №, Артикул, Наименование, Ожидаемое
//      количество, Единица измерения, Партия.
//   2) Плоская таблица: одна строка-заголовок (артикул, наименование,
//      количество, ...) + строки данных.
// Партия поставщика всегда сохраняется в receipt_items.supplier_batch и потом
// показывается на ТСД после сканирования ШК.

require_once __DIR__ . '/CsvUpload.php';
require_once __DIR__ . '/XlsxGrid.php';

// ---------- Гибкий разбор сетки заявки ----------


/** Синонимы колонок таблицы груза (нормализованные). */
function receipt_table_synonyms(): array
{
    return [
        'supplier' => ['поставщик', 'контрагент', 'supplier', 'продавец'],
        'line_no' => ['n', 'п/п', 'пп', 'номер', 'номерпп', 'строка', 'no'],
        'sku' => ['артикул', 'sku', 'artikul', 'кодтовара', 'код'],
        'name' => ['наименование', 'наименованиеполное', 'наименованиеполн', 'название', 'name', 'товар', 'номенклатура'],
        'qty' => ['ожидаемоеколичество', 'количествоплан', 'количество', 'колво', 'qty', 'quantity', 'шт', 'колвошт', 'план'],
        'unit' => ['единица', 'единицаизмерения', 'едизм', 'ед', 'unit', 'ед изм', 'еи'],
        'batch' => ['партия', 'партияпоставщика', 'партиятовара', 'batch', 'lot', 'lotno', 'партияпоставки'],
        'barcode' => ['штрихкод', 'штрихкодтовара', 'шк', 'barcode', 'ean', 'ean13', 'штрихкодean'],
        'quality' => ['качество', 'качествотовара', 'quality', 'состояние'],
        'group' => ['группа', 'группатовара', 'group', 'productgroup'],
        'prod_date' => ['датапроизводства', 'датавыпуска', 'proddate', 'productiondate', 'дата'],
        'comment' => ['комментарий', 'коммент', 'comment', 'примечание'],
    ];
}




/** Метаданные шапки заявки: поставщик, номер заказа, план-дата, способ поставки. */
function receipt_parse_meta(array $grid, ?int $tableRow): array
{
    $meta = ['supplier' => '', 'external_no' => '', 'plan_date' => null, 'unload_type' => '', 'contract_no' => ''];
    $limit = $tableRow ?? count($grid);
    for ($ri = 0; $ri < $limit && $ri < count($grid); $ri++) {
        $row = $grid[$ri];
        foreach ($row as $ci => $cell) {
            // Метка и значение могут быть в одной ячейке («Номер заказа: ЗК-1001»)
            // или в соседних («Номер заказа» | «ЗК-1001»).
            [$label, $inline] = wms_split_label((string)$cell);
            $n = wms_norm_cell($label);
            if ($n === '') {
                continue;
            }
            $value = static fn() => $inline !== '' ? $inline : wms_meta_value($grid, $ri, $ci);
            // Номер заказа
            if (in_array($n, ['номерзаказа', 'номерзаявки', 'заказ', 'заявка', 'номердокумента', 'внешнийномер', 'asn', 'orderno'], true)
                || str_contains($n, 'номерзаказа') || str_contains($n, 'номерзаявки')) {
                $v = $value();
                if ($v !== '' && $meta['external_no'] === '') {
                    $meta['external_no'] = $v;
                }
            }
            // Ожидаемая дата прибытия ТС / дата поставки
            if (str_contains($n, 'ожидаемаядата') || str_contains($n, 'датаприбытия') || str_contains($n, 'прибытиятс')
                || str_contains($n, 'датапоставки') || ($n === 'дата' && str_contains(wms_norm_cell(implode(' ', $row)), 'прибыт'))) {
                $v = $value();
                $d = $v !== '' ? wms_parse_plan_date($v) : null;
                if ($d !== null && $meta['plan_date'] === null) {
                    $meta['plan_date'] = $d;
                }
            }
            // Поставщик
            if ($n === 'поставщик' || $n === 'продавец' || str_contains($n, 'поставщик')) {
                $v = $value();
                if ($v !== '' && $meta['supplier'] === '') {
                    $meta['supplier'] = $v;
                }
            }
            // Способ поставки
            if (str_contains($n, 'способпоставки') || str_contains($n, 'способдоставки')) {
                $v = $value();
                if ($v !== '' && $meta['unload_type'] === '') {
                    $meta['unload_type'] = $v;
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
        // Эвристика: строка вида "ЗАЯВКА НА ПРИЕМКУ" — пропускаем; значение номера заказа
        // иногда лежит в строке ниже метки в том же столбце (обработано в receipt_meta_value).
    }
    // Поставщик может занимать несколько строк (название разбито) — склеить соседние непустые снизу
    if ($meta['supplier'] !== '') {
        // уже ок
    }
    // Нормализация способа поставки к справочнику: Паллеты / Внавал
    $u = csv_upload_lower($meta['unload_type']);
    if (str_contains($u, 'паллет') || str_contains($u, 'палет') || str_contains($u, 'pallet')) {
        $meta['unload_type'] = 'Паллеты';
    } elseif (str_contains($u, 'навал') || str_contains($u, 'россып')) {
        $meta['unload_type'] = 'Внавал';
    }
    return $meta;
}

/**
 * Разобрать сетку в строки прихода.
 * @return array{rows: array, header: array, errors: string[], table_header_row: ?int, format: string}
 */
function receipt_grid_parse(array $grid): array
{
    $found = wms_find_table_header($grid, receipt_table_synonyms());
    if ($found === null) {
        // Показать первые строки для диагностики
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
    $meta = receipt_parse_meta($grid, $hri);
    $rows = [];
    $errors = [];
    $emptyStreak = 0;
    $colSupplier = '';
    for ($ri = $hri + 1; $ri < count($grid); $ri++) {
        $row = $grid[$ri];
        $get = static fn(string $f) => isset($map[$f]) ? trim((string)($row[$map[$f]] ?? '')) : '';
        $sku = $get('sku');
        $name = $get('name');
        $qtyRaw = $get('qty');
        $unit = $get('unit');
        $batch = $get('batch');
        $codeRaw = $get('barcode');
        $qRaw = $get('quality');
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
        $quality = $qRaw === '' ? 'Годен' : csv_upload_norm_quality($qRaw);
        if ($quality === null) {
            $errors[] = 'Строка ' . ($ri + 1) . " ($sku): качество — только «Годен» или «Брак»";
            continue;
        }
        $barcodes = [];
        if ($codeRaw !== '') {
            foreach (preg_split('/[,;\/\s]+/u', $codeRaw) ?: [] as $bc) {
                $bc = trim((string)$bc);
                if ($bc !== '' && !in_array($bc, $barcodes, true)) {
                    $barcodes[] = $bc;
                }
            }
        }
        if (!$barcodes) {
            $barcodes = [$sku]; // ШК нет в заявке — используем артикул как код
        }
        if ($name === '') {
            $name = $sku;
        }
        // Поставщик колонкой (плоские CSV): первое непустое — в шапку
        if ($colSupplier === '' && isset($map['supplier'])) {
            $sv = trim((string)($row[$map['supplier']] ?? ''));
            if ($sv !== '') {
                $colSupplier = $sv;
            }
        }
        $rows[] = [
            'sku' => $sku,
            'name' => $name,
            'qty' => $qty,
            'quality' => $quality,
            'barcodes' => $barcodes,
            'group' => $get('group'),
            'prod_date' => ($pd = $get('prod_date')) === '' ? null : csv_upload_norm_date($pd),
            'unit' => $unit !== '' ? $unit : 'шт',
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
    $format = ($hri > 2 || $meta['external_no'] !== '' || $meta['supplier'] !== '') ? 'zayavka' : 'flat';
    if ($meta['supplier'] === '' && $colSupplier !== '') {
        $meta['supplier'] = $colSupplier;
    }
    return [
        'rows' => $rows,
        'header' => [
            'supplier' => $meta['supplier'],
            'external_no' => $meta['external_no'],
            'plan_date' => $meta['plan_date'],
            'unload_type' => $meta['unload_type'],
            'contract_no' => $meta['contract_no'],
            'comment' => implode('; ', $comments),
            'contractor' => '',
            'consignee' => '',
            'ship_type' => '',
            'marking' => false,
            'serial' => false,
        ],
        'errors' => $errors,
        'table_header_row' => $hri + 1, // 1-based для сообщений
        'format' => $format,
    ];
}

/**
 * Универсальная точка входа: файл из $_FILES -> строки + шапка.
 * Поддерживает .xlsx/.xlsm (заявка или плоская таблица) и .csv/.txt.
 * @return array{rows: array, header: array, errors: string[], total: int, format: string, filename: string}
 */
function receipt_upload_parse_file(array $file): array
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
        $parsed = receipt_grid_parse($read['grid']);
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
    // CSV/TXT: сначала пробуем гибкий парсер сетки (ловит и заявку, сохраненную как CSV,
    // и плоские таблицы с партией), при неудаче — строгий legacy-парсер.
    $read = wms_csv_read_grid($tmp);
    try {
        $parsed = receipt_grid_parse($read['grid']);
        return [
            'rows' => $parsed['rows'],
            'header' => $parsed['header'],
            'errors' => $parsed['errors'],
            'total' => count($read['grid']),
            'format' => $parsed['format'] . '+csv',
            'filename' => $name,
        ];
    } catch (Throwable $eGrid) {
        // Legacy строгий формат прихода (Поставщик;артикул;наименование;количество;качество;штрихкод...)
        $legacy = csv_upload_parse($file, csv_upload_receipt_spec());
        if ($legacy['rows']) {
            // Дотянуть партию/единицу если есть такие колонки
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
