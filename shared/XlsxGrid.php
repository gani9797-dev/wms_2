<?php
declare(strict_types=1);

// Общие «бескнижные» ридеры таблиц для импорта CSV/XLSX.
// Вынесены из ReceiptExcel.php, потому что те же форматы нужны расходу
// (shared/ShipmentExcel.php): приход и расход читаются одинаково —
// файл -> сетка строк -> поиск строки-заголовка таблицы -> строки позиций.
// Без внешних библиотек: .xlsx читаем через ZipArchive + XML (встроенные в PHP),
// при отсутствии ZipArchive — минимальный распаковщик zip ниже.

require_once __DIR__ . '/CsvUpload.php';

// ---------- Чтение .xlsx без библиотек ----------

/** Загрузить XML листа xlsx без пространств имен: убираем xmlns и префиксы тегов. */
function wms_xml_load(string $xmlStr): SimpleXMLElement
{
    $s = preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xmlStr);
    $s = preg_replace('/<(\/?)(\w+):/', '<$1', $s);
    return new SimpleXMLElement($s);
}

/** Индекс колонки из ссылки вида "B20" -> 1 (0-based). */
function wms_xlsx_col_index(string $ref): int
{
    $ref = strtoupper(trim($ref));
    if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
        return 0;
    }
    $letters = $m[1];
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = $idx * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $idx - 1;
}

/** Номер строки из ссылки вида "B20" -> 20. */
function wms_xlsx_row_no(string $ref): int
{
    if (preg_match('/(\d+)$/', trim((string)$ref), $m)) {
        return (int)$m[1];
    }
    return 0;
}

/** Excel serial -> Y-m-d (окно 1900, с учетом бага 29.02.1900). */
function wms_xlsx_serial_to_date(float $serial): ?string
{
    if ($serial < 20000 || $serial > 80000) {
        return null;
    }
    $days = (int)floor($serial);
    // 25569 = дней между 30.12.1899 и 01.01.1970
    $ts = ($days - 25569) * 86400;
    if ($ts < 0) {
        return null;
    }
    return gmdate('Y-m-d', $ts);
}

/**
 * Минимальный распаковщик zip без ZipArchive (только чтение, deflate/store).
 * .xlsx — обычный zip, шифрования там нет. Возвращает [имя => содержимое].
 */
function wms_zip_read_all(string $path): array
{
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Файл не найден');
    }
    $len = strlen($data);
    if ($len < 22 || substr($data, 0, 2) !== 'PK') {
        throw new RuntimeException('Не удалось открыть .xlsx (поврежден или это .xls старого формата — сохрани как .xlsx или CSV)');
    }
    // EOCD: ищем сигнатуру с конца (без учета многотомности — xlsx однотомный)
    $eocd = -1;
    for ($i = $len - 22; $i >= max(0, $len - 22 - 65557); $i--) {
        if (substr($data, $i, 4) === "PK\x05\x06") {
            $eocd = $i;
            break;
        }
    }
    if ($eocd < 0) {
        throw new RuntimeException('Не удалось открыть .xlsx (поврежден или это .xls старого формата — сохрани как .xlsx или CSV)');
    }
    $cdCount = unpack('v', substr($data, $eocd + 10, 2))[1];
    $cdOff = unpack('V', substr($data, $eocd + 16, 4))[1];
    $out = [];
    $p = $cdOff;
    for ($n = 0; $n < $cdCount; $n++) {
        if (substr($data, $p, 4) !== "PK\x01\x02") {
            break;
        }
        $method = unpack('v', substr($data, $p + 10, 2))[1];
        $csize = unpack('V', substr($data, $p + 20, 4))[1];
        $fnLen = unpack('v', substr($data, $p + 28, 2))[1];
        $exLen = unpack('v', substr($data, $p + 30, 2))[1];
        $fcLen = unpack('v', substr($data, $p + 32, 2))[1];
        $lho = unpack('V', substr($data, $p + 42, 4))[1];
        $name = substr($data, $p + 46, $fnLen);
        $p += 46 + $fnLen + $exLen + $fcLen;
        if ($name === '' || str_ends_with($name, '/')) {
            continue; // каталог / битая запись
        }
        // Local header -> данные
        if (substr($data, $lho, 4) !== "PK\x03\x04") {
            continue;
        }
        $lfFn = unpack('v', substr($data, $lho + 26, 2))[1];
        $lfEx = unpack('v', substr($data, $lho + 28, 2))[1];
        $start = $lho + 30 + $lfFn + $lfEx;
        $raw = substr($data, $start, $csize);
        if ($method === 0) {
            $out[$name] = $raw;
        } elseif ($method === 8) {
            $dec = @gzinflate($raw);
            if ($dec === false) {
                continue;
            }
            $out[$name] = $dec;
        }
    }
    return $out;
}

/**
 * Прочитать первый лист .xlsx в сетку строк (каждая строка — массив строковых ячеек).
 * Работает и без ZipArchive (встроенный распаковщик выше).
 * @return array{grid: string[][], sheets: string[], warnings: string[]}
 */
function wms_xlsx_read_grid(string $tmpPath): array
{
    $warnings = [];
    if (!is_file($tmpPath)) {
        throw new RuntimeException('Файл не найден');
    }
    $files = null; // имя внутри zip => содержимое
    $names = null; // список имен
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($tmpPath) === true) {
            $files = [];
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nm = (string)$zip->getNameIndex($i);
                $names[] = $nm;
                $files[$nm] = null; // лениво
            }
            $get = static function (string $inner) use ($zip, &$files) {
                if (array_key_exists($inner, $files) && $files[$inner] !== null) {
                    return $files[$inner];
                }
                $c = $zip->getFromName($inner);
                $files[$inner] = $c === false ? false : $c;
                return $files[$inner];
            };
            $close = static function () use ($zip) { $zip->close(); };
        }
    }
    if (!isset($get)) {
        // Fallback без расширения zip
        $all = wms_zip_read_all($tmpPath);
        $names = array_keys($all);
        $get = static fn(string $inner) => $all[$inner] ?? false;
        $close = static function () {};
        $hasXl = false;
        foreach ($names as $nm) {
            if (str_starts_with($nm, 'xl/')) {
                $hasXl = true;
                break;
            }
        }
        if (!$hasXl) {
            throw new RuntimeException('Не удалось открыть .xlsx (поврежден или это .xls старого формата — сохрани как .xlsx или CSV)');
        }
    }
    // Список листов (regex: устойчиво к любым префиксам/namespace)
    $sheets = [];
    $sheetFiles = []; // имя листа -> r:id
    $wbXml = $get('xl/workbook.xml');
    if ($wbXml !== false && $wbXml !== null && $wbXml !== '') {
        try {
            if (preg_match_all('/<sheet\b[^>]*name="([^"]*)"[^>]*>/u', $wbXml, $m)) {
                foreach ($m[0] as $i => $tag) {
                    $name = html_entity_decode($m[1][$i], ENT_QUOTES, 'UTF-8');
                    $rid = '';
                    if (preg_match('/(?:r:)?id="([^"]*)"/', $tag, $mm)) {
                        $rid = $mm[1];
                    }
                    if ($name !== '') {
                        $sheets[] = $name;
                        $sheetFiles[$name] = $rid;
                    }
                }
            }
        } catch (Throwable) {
        }
    }
    // rels workbook -> путь файла листа
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $relsXml = $get('xl/_rels/workbook.xml.rels');
    if ($relsXml !== false && $relsXml !== null && $relsXml !== '' && $sheetFiles) {
        try {
            $idToTarget = [];
            if (preg_match_all('/<Relationship\b[^>]*>/', $relsXml, $m)) {
                foreach ($m[0] as $tag) {
                    if (preg_match('/Id="([^"]*)"/', $tag, $a) && preg_match('/Target="([^"]*)"/', $tag, $b)) {
                        $idToTarget[$a[1]] = $b[1];
                    }
                }
            }
            $firstName = $sheets[0] ?? '';
            $firstRid = $firstName !== '' ? ($sheetFiles[$firstName] ?? '') : '';
            if ($firstRid !== '' && isset($idToTarget[$firstRid])) {
                $t = $idToTarget[$firstRid];
                $sheetPath = str_starts_with($t, 'xl/') ? $t : ('xl/' . ltrim($t, '/'));
            }
        } catch (Throwable) {
        }
    }
    // Shared strings
    $shared = [];
    $ssXml = $get('xl/sharedStrings.xml');
    if ($ssXml !== false && $ssXml !== null && $ssXml !== '') {
        try {
            $ss = wms_xml_load($ssXml);
            $siList = $ss->xpath('//si');
            if ($siList) {
                foreach ($siList as $si) {
                    $parts = [];
                    foreach ($si->xpath('.//t') ?: [] as $t) {
                        $parts[] = (string)$t;
                    }
                    $shared[] = implode('', $parts);
                }
            }
        } catch (Throwable) {
        }
    }
    // Стили: какие xf — даты (для серийных номеров дат)
    $dateXf = []; // xfId => true
    try {
        $stylesXml = $get('xl/styles.xml');
        if ($stylesXml !== false && $stylesXml !== null && $stylesXml !== '') {
            $st = wms_xml_load($stylesXml);
            $numFmts = []; // numFmtId => code
            foreach ($st->xpath('//numFmt') ?: [] as $nf) {
                $a = $nf->attributes();
                $numFmts[(string)($a['numFmtId'] ?? '')] = (string)($a['formatCode'] ?? '');
            }
            // Встроенные форматы дат: 14-22, 27-36, 45-47, 50, 57 + кастомные с d/m/y
            $isDateFmt = static function (string $id, string $code): bool {
                if (in_array($id, ['14','15','16','17','18','19','20','21','22','27','28','29','30','31','32','33','34','35','36','45','46','47','50','57','165'], true)) {
                    return true;
                }
                $c = strtolower($code);
                if ($c === '' || $c === 'general') {
                    return false;
                }
                // Дата/время если есть d/m/y и нет красных флагов; часы одни — тоже дата
                $hasDMY = str_contains($c, 'd') || str_contains($c, 'm') || str_contains($c, 'y');
                return $hasDMY;
            };
            $xfs = $st->xpath('//cellXfs/xf');
            if ($xfs) {
                foreach ($xfs as $i => $xf) {
                    $a = $xf->attributes();
                    $fid = (string)($a['numFmtId'] ?? '0');
                    $code = $numFmts[$fid] ?? '';
                    if ($isDateFmt($fid, $code)) {
                        $dateXf[(int)$i] = true;
                    }
                }
            }
        }
    } catch (Throwable) {
    }
    $sheetXml = $get($sheetPath);
    if ($sheetXml === false) {
        // fallback: первый попавшийся worksheet
        $sheetXml = false;
        foreach (($names ?? []) as $nm) {
            $nm = (string)$nm;
            if (str_starts_with($nm, 'xl/worksheets/sheet')) {
                $sheetXml = $get($nm);
                $sheetPath = $nm;
                if ($sheetXml !== false) {
                    break;
                }
            }
        }
    }
    $close();
    if ($sheetXml === false) {
        throw new RuntimeException('В .xlsx не найден лист с данными');
    }
    $grid = [];
    try {
        $xml = wms_xml_load($sheetXml);
        $rows = $xml->xpath('//sheetData/row') ?: [];
        foreach ($rows as $row) {
            $ra = $row->attributes();
            $rNo = (int)($ra['r'] ?? 0);
            $cells = [];
            $maxCol = -1;
            $vals = []; // col => string
            foreach ($row->xpath('./c') ?: [] as $c) {
                $ca = $c->attributes();
                $ref = (string)($ca['r'] ?? '');
                $t = (string)($ca['t'] ?? '');
                $s = isset($ca['s']) ? (int)$ca['s'] : null;
                $col = $ref !== '' ? wms_xlsx_col_index($ref) : ($maxCol + 1);
                $maxCol = max($maxCol, $col);
                $vNode = $c->v ?? null;
                $vRaw = $vNode !== null ? (string)$vNode : '';
                // inlineStr
                $isNode = $c->is ?? null;
                if ($t === 'inlineStr' && $isNode !== null) {
                    $parts = [];
                    foreach ($isNode->xpath('.//t') ?: [] as $tNode) {
                        $parts[] = (string)$tNode;
                    }
                    $vals[$col] = implode('', $parts);
                    continue;
                }
                if ($t === 's') {
                    $idx = is_numeric($vRaw) ? (int)$vRaw : -1;
                    $vals[$col] = ($idx >= 0 && isset($shared[$idx])) ? $shared[$idx] : '';
                    continue;
                }
                if ($t === 'str' || $t === 'e') {
                    $vals[$col] = $vRaw;
                    continue;
                }
                if ($t === 'b') {
                    $vals[$col] = ($vRaw === '1') ? '1' : '0';
                    continue;
                }
                // числа (t='' ) — либо число, либо дата-сериал
                if ($vRaw === '') {
                    $vals[$col] = '';
                    continue;
                }
                $num = is_numeric($vRaw) ? (float)$vRaw : null;
                if ($num !== null && $s !== null && isset($dateXf[$s])) {
                    $d = wms_xlsx_serial_to_date($num);
                    $vals[$col] = $d ?? rtrim(rtrim(number_format($num, 10, '.', ''), '0'), '.');
                } elseif ($num !== null) {
                    // Партии вида 202607052 должны остаться текстом без .0 и без экспоненты
                    if (abs($num) >= 1e15) {
                        $vals[$col] = $vRaw;
                    } else {
                        $txt = rtrim(rtrim(number_format($num, 10, '.', ''), '0'), '.');
                        $vals[$col] = ($txt === '-0') ? '0' : ($txt === '' ? '0' : $txt);
                    }
                } else {
                    $vals[$col] = $vRaw;
                }
            }
            if (!$vals) {
                continue;
            }
            ksort($vals);
            $last = max(array_keys($vals));
            $line = [];
            for ($i = 0; $i <= $last; $i++) {
                $line[] = isset($vals[$i]) ? trim((string)$vals[$i]) : '';
            }
            // Убрать пустой хвост
            while ($line && end($line) === '') {
                array_pop($line);
            }
            if ($rNo > 0) {
                $grid[$rNo] = $line;
            } else {
                $grid[] = $line;
            }
        }
        ksort($grid);
        $grid = array_values($grid);
    } catch (Throwable $e) {
        throw new RuntimeException('Не удалось разобрать лист .xlsx: ' . $e->getMessage());
    }
    if (!$grid) {
        throw new RuntimeException('Лист .xlsx пуст');
    }
    return ['grid' => $grid, 'sheets' => $sheets, 'warnings' => $warnings];
}

/** CSV/TXT файл -> сетка (как xlsx). */
function wms_csv_read_grid(string $tmpPath): array
{
    $raw = @file_get_contents($tmpPath);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('Файл пуст');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!preg_match('//u', $raw)) {
        if (function_exists('mb_convert_encoding')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
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
    // Делимитер: в файле-заявке первая строка — заголовок без разделителей,
    // поэтому смотрим не только на неё, а на несколько первых строк и берем тот
    // разделитель, который чаще всего дает одинаковое число колонок.
    $delim = ';';
    $bestFreq = 0;
    foreach ([';', ',', "\t"] as $d) {
        $counts = [];
        foreach (array_slice($lines, 0, 20) as $ln) {
            $c = count(str_getcsv($ln, $d));
            if ($c > 1) {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if (!$counts) {
            continue;
        }
        arsort($counts);
        $freq = (int)reset($counts);
        if ($freq > $bestFreq) {
            $bestFreq = $freq;
            $delim = $d;
        }
    }
    $grid = [];
    foreach ($lines as $ln) {
        if (trim($ln) === '') {
            $grid[] = [];
            continue;
        }
        $grid[] = array_map(static fn($c) => trim((string)$c), str_getcsv($ln, $delim));
    }
    return ['grid' => $grid, 'sheets' => ['csv'], 'warnings' => []];
}

// ---------- Разбор сетки: общие помощники ----------

/** Нормализация текста ячейки: регистр, ё, пунктуация. */
function wms_norm_cell(string $s): string
{
    $s = csv_upload_lower(trim($s));
    $s = str_replace('ё', 'е', $s);
    return str_replace(['_', ' ', '.', '-', ':', '"', "'", '«', '»', '(', ')'], '', $s);
}

/** Найти строку-заголовок таблицы по синонимам колонок (нужны артикул и количество). */
function wms_find_table_header(array $grid, array $syns): ?array
{
    $best = null;
    foreach ($grid as $ri => $row) {
        $norms = array_map('wms_norm_cell', $row);
        $map = [];
        foreach ($norms as $ci => $n) {
            foreach ($syns as $field => $list) {
                if (in_array($n, $list, true) && !isset($map[$field])) {
                    $map[$field] = $ci;
                }
            }
        }
        $hasSku = isset($map['sku']);
        $hasQty = isset($map['qty']);
        $hasName = isset($map['name']);
        if ($hasSku && $hasQty) {
            $score = 4 + ($hasName ? 2 : 0) + (isset($map['batch']) ? 2 : 0) + (isset($map['unit']) ? 1 : 0);
            if ($best === null || $score > $best['score']) {
                $best = ['row' => $ri, 'map' => $map, 'score' => $score];
            }
        }
    }
    return $best;
}

/** Разделить ячейку на метку и значение: «Номер заказа: ЗК-1001» -> ['Номер заказа', 'ЗК-1001']. */
function wms_split_label(string $cell): array
{
    $cell = trim($cell);
    $pos = strpos($cell, ':');
    if ($pos === false) {
        return [$cell, ''];
    }
    $label = trim(substr($cell, 0, $pos));
    $value = trim(substr($cell, $pos + 1));
    if ($label === '') {
        return [$cell, ''];
    }
    return [$label, $value];
}

/** Извлечь значение справа/снизу от ячейки-метки. */
function wms_meta_value(array $grid, int $ri, int $ci): string
{
    $row = $grid[$ri] ?? [];
    // справа в той же строке
    for ($c = $ci + 1; $c < count($row) + 3; $c++) {
        $v = trim((string)($row[$c] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    // снизу в том же столбце (до 3 строк)
    for ($r = $ri + 1; $r <= $ri + 3 && $r < count($grid); $r++) {
        $v = trim((string)($grid[$r][$ci] ?? ''));
        if ($v !== '') {
            return $v;
        }
        // иногда значение в соседнем столбце следующей строки
        $v2 = trim((string)($grid[$r][$ci + 1] ?? ''));
        if ($v2 !== '' && trim((string)($grid[$r][$ci] ?? '')) === '') {
            return $v2;
        }
    }
    return '';
}

/** Дата плана из текста: 14-16.10.2026 -> 2026-10-14; 14.10.2026; 2026-10-14; Excel serial уже строкой. */
function wms_parse_plan_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    // Диапазон 14-16.10.2026 или 14–16.10.2026 или 14/16.10.2026 — берем первую дату
    if (preg_match('/(\d{1,2})\s*[—–\-\/]\s*\d{1,2}\.(\d{1,2})\.(\d{4})/u', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
    }
    if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return csv_upload_norm_date($s);
}
