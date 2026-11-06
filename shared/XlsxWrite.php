<?php
declare(strict_types=1);

// Минимальный генератор .xlsx без ZipArchive (на сервере нет расширения zip).
// ZIP собирается методом store (без сжатия), XLSX — обычный набор XML-частей.
// Использование:
//   $bin = wms_xlsx_write([
//     ['name' => 'Сводный', 'rows' => [['Клиент','Штук'], ['ООО «Эталон»', 10]]],
//     ['name' => 'Детализация', 'rows' => [...]],
//   ]);

/** Экранирование текста для XML. */
function wms_xlsx_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Имя колонки Excel по индексу (0 → A, 25 → Z, 26 → AA). */
function wms_xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $i--;
        $s = chr(65 + ($i % 26)) . $s;
        $i = intdiv($i, 26);
    }
    return $s;
}

/** Собрать zip (store) из [имя => содержимое]. */
function wms_zip_store(array $files): string
{
    $out = '';
    $central = '';
    foreach ($files as $name => $content) {
        $content = (string)$content;
        $crc = crc32($content);
        $size = strlen($content);
        $nameLen = strlen($name);
        $offset = strlen($out);
        $out .= "PK\x03\x04"
            . pack('v', 20) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $size) . pack('V', $size)
            . pack('v', $nameLen) . pack('v', 0) . $name . $content;
        $central .= "PK\x01\x02"
            . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $size) . pack('V', $size)
            . pack('v', $nameLen) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
            . pack('V', 0) . pack('V', $offset) . $name;
    }
    $eocd = "PK\x05\x06" . pack('v', 0) . pack('v', 0)
        . pack('v', count($files)) . pack('v', count($files))
        . pack('V', strlen($central)) . pack('V', strlen($out)) . pack('v', 0);
    return $out . $central . $eocd;
}

/** XML листа: строки значений (string|int|float|null). */
function wms_xlsx_sheet_xml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';
    $rn = 0;
    foreach ($rows as $row) {
        $rn++;
        $xml .= '<row r="' . $rn . '">';
        $ci = 0;
        foreach ((array)$row as $val) {
            $ref = wms_xlsx_col($ci) . $rn;
            $ci++;
            if ($val === null || $val === '') {
                continue;
            }
            if (is_int($val) || is_float($val)) {
                $xml .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . wms_xlsx_esc((string)$val) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }
    return $xml . '</sheetData></worksheet>';
}

/**
 * Собрать .xlsx с несколькими листами.
 * @param array<int,array{name:string,rows:array<int,array>}> $sheets
 */
function wms_xlsx_write(array $sheets): string
{
    // Excel: имя листа ≤ 31 символа, без []:*?/\
    $clean = [];
    $seen = [];
    foreach ($sheets as $i => $sh) {
        $name = trim((string)($sh['name'] ?? ('Лист' . ($i + 1))));
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $name) ?? $name;
        $name = function_exists('mb_substr') ? mb_substr($name, 0, 31, 'UTF-8') : substr($name, 0, 31);
        if ($name === '') {
            $name = 'Лист' . ($i + 1);
        }
        $base = $name;
        $k = 2;
        while (isset($seen[$name])) {
            $name = (function_exists('mb_substr') ? mb_substr($base, 0, 28, 'UTF-8') : substr($base, 0, 28)) . ' ' . $k;
            $k++;
        }
        $seen[$name] = true;
        $clean[] = ['name' => $name, 'rows' => (array)($sh['rows'] ?? [])];
    }

    $n = count($clean);
    $overrides = '';
    $sheetEntries = '';
    $rels = '';
    $files = [];
    foreach ($clean as $i => $sh) {
        $k = $i + 1;
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetEntries .= '<sheet name="' . wms_xlsx_esc($sh['name']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
        $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
        $files['xl/worksheets/sheet' . $k . '.xml'] = wms_xlsx_sheet_xml($sh['rows']);
    }
    $stylesRid = 'rId' . ($n + 1);

    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . $overrides
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetEntries . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . $rels
        . '<Relationship Id="' . $stylesRid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs>'
        . '</styleSheet>';

    return wms_zip_store($files);
}
