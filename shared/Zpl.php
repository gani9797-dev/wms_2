<?php
declare(strict_types=1);

// Этикетка товара для термопринтера (модуль 08, ПКМ -> Печать -> Штрихкод).
// Компоновка: вверху артикул, следующая строка — наименование, внизу CODE-128.
// Два тракта:
//   ZPL II  — для Zebra-совместимых термопринтеров (^XA...^XZ, CODE-128 через ^BC,
//               кириллица через ^CI28 UTF-8, размер через ^PW/^LL, копии через ^PQ).
//   HTML    — современный тракт: standalone-документ с @page <W>mm <H>mm,
//               CODE-128 рисуется серверным SVG (без внешних JS-библиотек),
//               печать через диалог браузера на любом принтере (включая термо).
// Один и тот же макет масштабируется под разные размеры этикетки (мм).

// ---------- CODE-128 B (штрихи — через WmsCode128 из shared/Pdf.php) ----------

/**
 * CODE-128 B кодирует только ASCII 32-126 (латиница/цифры).
 * @throws RuntimeException если кодировать нечего
 */
function wms_code128_sanitize(string $raw): string
{
    $s = preg_replace('/[^\x20-\x7E]/', '', $raw) ?? '';
    $s = trim($s);
    if ($s === '') {
        throw new RuntimeException('CODE-128: нужны символы латиницы/цифры, получено: ' . $raw);
    }
    return $s;
}

/** Битовая строка ('1' — штрих): Start B + данные + КЦ + Stop + терминал. */
function wms_code128_bits(string $raw): string
{
    if (!class_exists('WmsCode128')) {
        require_once __DIR__ . '/Pdf.php';
    }
    $s = wms_code128_sanitize($raw);
    $b = '';
    foreach (WmsCode128::modules($s) as $i => $w) {
        $b .= str_repeat(($i % 2 === 0) ? '1' : '0', $w);
    }
    return $b;
}

// ---------- ZPL II ----------

function wms_zpl_sanitize(string $s): string
{
    $s = str_replace(['^', '~', "\r", "\n"], [' ', ' ', ' ', ' '], $s);
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/**
 * ZPL-этикетка: артикул / наименование / CODE-128.
 * @param float $wMm ширина этикетки, мм
 * @param float $hMm высота этикетки, мм
 * @param int $copies число копий (^PQ)
 * @param int $dpi разрешение принтера (203 / 300)
 */
function wms_zpl_product_barcode(
    string $sku,
    string $name,
    string $barcode,
    float $wMm = 58.0,
    float $hMm = 40.0,
    int $copies = 1,
    int $dpi = 203
): string {
    $sku = wms_zpl_sanitize($sku) ?: '-';
    $name = wms_zpl_sanitize($name) ?: '-';
    $barcode = trim($barcode);
    if ($barcode === '') {
        throw new RuntimeException('У товара нет штрихкода');
    }
    $wMm = min(250.0, max(20.0, $wMm));
    $hMm = min(250.0, max(15.0, $hMm));
    $copies = min(999, max(1, $copies));
    $dpi = ($dpi === 300) ? 300 : 203;

    $k = $dpi / 25.4; // точек на мм
    $w = (int)round($wMm * $k);
    $h = (int)round($hMm * $k);
    $m = max(12, (int)round(3 * $k)); // поле ~3 мм
    $inner = max(60, $w - 2 * $m);

    // Кегли масштабируем от ширины этикетки.
    $skuH = (int)min(52, max(22, $w / 13));
    $nameH = (int)min(40, max(18, $w / 17));
    $capH = (int)min(36, max(18, $w / 18));

    $code = wms_code128_sanitize($barcode);
    // Модуль штрихкода: влезаем во внутреннюю ширину.
    // CODE-128: 11 модулей на символ + Stop 13 + терминал 2.
    $totalMods = 11 * (strlen($code) + 2) + 13 + 2;
    $by = max(1, min(4, (int)floor($inner / $totalMods)));
    // Высота штрихов: остаток места под текстом, но не выше 45% высоты этикетки
    // и не выше 250 точек (~31 мм при 203 dpi) — выше уже избыточно.
    $barH = (int)min(250, $h * 0.45, max(45, ($h - 2 * $m - $skuH - 2 * $nameH - $capH - 30) * 0.6));
    $barH = max(40, $barH);

    $y = $m;
    $out = [];
    $out[] = '^XA';
    $out[] = '^CI28'; // UTF-8 (кириллица в наименовании)
    $out[] = "^PW$w";
    $out[] = "^LL$h";
    $out[] = '^LH0,0';
    $out[] = '^LT0';
    // 1) Артикул вверху, по центру.
    $out[] = "^FO0,$y^A0N,$skuH,$skuH^FB$w,1,0,C,0^FD$sku^FS";
    $y += $skuH + 8;
    // 2) Наименование, до 2 строк, по центру.
    $out[] = "^FO0,$y^A0N,$nameH,$nameH^FB$w,2,0,C,0^FD$name^FS";
    $y += 2 * $nameH + 10;
    // 3) Штрихкод CODE-128 внизу (расшифровка под штрихами — Y).
    $out[] = "^FO0,$y^BY$by,3^BCN,$barH,Y,N,N^FD$code^FS";
    $y += $barH + 10;
    // Код дублируем текстом под штрихами.
    $out[] = "^FO0,$y^A0N,$capH,$capH^FB$w,1,0,C,0^FD$code^FS";
    $out[] = "^PQ$copies";
    $out[] = '^XZ';
    return implode("\n", $out) . "\n";
}

function wms_zpl_filename(string $sku, int $id = 0): string
{
    $s = preg_replace('/[^A-Za-z0-9]+/', '_', $sku) ?? '';
    $s = trim($s, '_') ?: 'barcode';
    if ($id > 0) {
        $s .= '_' . $id;
    }
    return substr($s, 0, 60) . '.zpl';
}

// ---------- HTML-этикетка (современный тракт, печать из браузера) ----------

/** SVG штрихов CODE-128 (тихая зона 10 модулей с каждой стороны). */
function wms_code128_svg(string $raw, float $widthMm, float $barMm): string
{
    $bits = wms_code128_bits($raw);
    $n = strlen($bits);
    $qz = 10;
    $total = $n + 2 * $qz;
    $H = 100;
    $rects = '';
    // Склеиваем подряд идущие чёрные модули в один rect (компактнее).
    $runStart = -1;
    $runLen = 0;
    for ($i = 0; $i < $n; $i++) {
        if ($bits[$i] === '1') {
            if ($runStart < 0) {
                $runStart = $i;
                $runLen = 1;
            } else {
                $runLen++;
            }
        } elseif ($runStart >= 0) {
            $rects .= '<rect x="' . ($qz + $runStart) . '" y="0" width="' . $runLen . '" height="' . $H . '"/>';
            $runStart = -1;
            $runLen = 0;
        }
    }
    if ($runStart >= 0) {
        $rects .= '<rect x="' . ($qz + $runStart) . '" y="0" width="' . $runLen . '" height="' . $H . '"/>';
    }
    $w = htmlspecialchars(number_format($widthMm, 1) . 'mm', ENT_QUOTES, 'UTF-8');
    $hh = htmlspecialchars(number_format($barMm, 1) . 'mm', ENT_QUOTES, 'UTF-8');
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 $total $H\" width=\"$w\" height=\"$hh\" preserveAspectRatio=\"xMidYMid meet\" shape-rendering=\"crispEdges\" fill=\"#000\">$rects</svg>";
}

/**
 * Standalone HTML-документ этикетки для печати из браузера.
 * @page size = Wmm Hmm, margin 0 — подходит и термопринтеру (через драйвер),
 * и обычному принтеру. Копии повторяются страницами.
 */
function wms_label_html(
    string $sku,
    string $name,
    string $barcode,
    float $wMm = 58.0,
    float $hMm = 40.0,
    int $copies = 1,
    bool $autoprint = false
): string {
    $wMm = min(250.0, max(20.0, $wMm));
    $hMm = min(250.0, max(15.0, $hMm));
    $copies = min(999, max(1, $copies));
    $sku = trim($sku) ?: '—';
    $name = trim($name) ?: '—';
    $barcode = trim($barcode);
    if ($barcode === '') {
        throw new RuntimeException('У товара нет штрихкода');
    }
    $eSku = htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
    $eName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $code = wms_code128_sanitize($barcode);
    $eCap = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    // Пропорции от ширины этикетки — один макет на все размеры.
    $fsSku = round($wMm * 0.095, 1);
    $fsName = round($wMm * 0.068, 1);
    $fsCap = round($wMm * 0.075, 1);
    $barW = round($wMm * 0.92, 1);
    $barH = round($hMm * 0.42, 1);
    $pad = round(min($wMm, $hMm) * 0.05, 1);

    $label = '<div class="label">'
        . '<div class="sku">' . $eSku . '</div>'
        . '<div class="nm">' . $eName . '</div>'
        . '<div class="bars">' . wms_code128_svg($code, $barW, $barH) . '</div>'
        . '<div class="cap">' . $eCap . '</div>'
        . '</div>';
    $labels = str_repeat($label, $copies);

    $w = number_format($wMm, 1, '.', '');
    $h = number_format($hMm, 1, '.', '');
    $auto = $autoprint ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print()},300)});</script>' : '';
    return '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
        . '<title>Этикетка ' . $eSku . ' ' . $w . 'x' . $h . '</title>'
        . '<style>@page{size:' . $w . 'mm ' . $h . 'mm;margin:0}'
        . 'html,body{margin:0;padding:0;background:#fff}'
        . '.label{width:' . $w . 'mm;height:' . $h . 'mm;page-break-after:always;break-after:page;'
        . 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
        . 'box-sizing:border-box;padding:' . $pad . 'mm;overflow:hidden;'
        . 'font-family:Arial,Helvetica,sans-serif;color:#000;text-align:center}'
        . '.label:last-child{page-break-after:auto;break-after:auto}'
        . '.sku{font-weight:700;font-size:' . $fsSku . 'mm;line-height:1.15;white-space:nowrap;'
        . 'max-width:100%;overflow:hidden;text-overflow:ellipsis}'
        . '.nm{font-size:' . $fsName . 'mm;line-height:1.2;margin-top:1mm;'
        . 'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;max-width:100%}'
        . '.bars{margin-top:1.2mm;display:flex;justify-content:center;max-width:100%}'
        . '.bars svg{max-width:100%}'
        . '.cap{font-size:' . $fsCap . 'mm;margin-top:.6mm;letter-spacing:.4mm;white-space:nowrap}</style>'
        . '</head><body>' . $labels . $auto . '</body></html>';
}
