<?php
declare(strict_types=1);
/**
 * Печать этикеток: «Термо» (ZPL прямо на термопринтер) и «PDF».
 *
 * Термо-тракт: описание этикетки → shared/render-label.ps1 (System.Drawing
 * рисует этикетку шрифтом Arial и отдаёт ZPL ^GFA-растром). Поэтому кириллица
 * печатается корректно на любом ZPL-принтере — встроенные шрифты ZPL русский
 * не поддерживают.
 * PDF-тракт: shared/Pdf.php::wms_pdf_label — страница ровно по размеру этикетки.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/Zpl.php';

/** Запуск внешней команды с UTF-8-нормализацией вывода и верным кодом выхода. */
function wms_proc_run(array $cmd, int $timeout = 30): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Не удалось запустить системную команду');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $started = microtime(true);
    $lastStatus = null;
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        $lastStatus = $status;
        if (!$status['running']) {
            break;
        }
        if (microtime(true) - $started > $timeout) {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            throw new RuntimeException('Превышено время ожидания системной команды');
        }
        usleep(50000);
    }
    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $code = ($lastStatus !== null && isset($lastStatus['exitcode']) && (int)$lastStatus['exitcode'] !== -1)
        ? (int)$lastStatus['exitcode']
        : (int)proc_close($process);
    return [$code, wms_label_utf8($out), wms_label_utf8($err)];
}

/** Текст из процесса (PowerShell без консоли пишет в OEM-кодировке). */
function wms_label_utf8(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (preg_match('//u', $s) === 1) {
        return $s;
    }
    foreach (['CP866', 'Windows-1251'] as $enc) {
        $c = @iconv($enc, 'UTF-8//IGNORE', $s);
        if ($c !== false && $c !== '') {
            return $c;
        }
    }
    return (string)preg_replace('/[^\x20-\x7E\r\n\t]/', '?', $s);
}

/** Доступна ли прямая печать (Windows-сервер). */
function wms_label_can_print_direct(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}

/** Список принтеров Windows: [{name, default}] */
function wms_label_printers(): array
{
    if (!wms_label_can_print_direct()) {
        return [];
    }
    $script = '$ErrorActionPreference="Stop"; [Console]::OutputEncoding=[Text.Encoding]::UTF8; '
        . '@(Get-Printer | Select-Object Name,Default) | ConvertTo-Json -Compress';
    [$code, $out, $err] = wms_proc_run([
        'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $script,
    ], 20);
    if ($code !== 0) {
        throw new RuntimeException('Не удалось получить список принтеров: ' . trim($err ?: $out));
    }
    $out = trim($out);
    if ($out === '' || $out === 'null' || $out === '[]') {
        return [];
    }
    $data = json_decode($out, true);
    if (!is_array($data)) {
        throw new RuntimeException('Не удалось разобрать список принтеров');
    }
    if (isset($data['Name'])) {
        $data = [$data];
    }
    $rows = [];
    foreach ($data as $p) {
        if (!is_array($p) || trim((string)($p['Name'] ?? '')) === '') {
            continue;
        }
        $rows[] = ['name' => (string)$p['Name'], 'default' => !empty($p['Default'])];
    }
    return $rows;
}

/** Строка описания этикетки для render-label.ps1 (поля через TAB). */
function wms_label_spec_clean(string $s): string
{
    return trim(str_replace(["\t", "\r", "\n"], ' ', $s));
}

/**
 * Описание стандартной этикетки: заголовок (+подзаголовок), штрихкод Code128,
 * подпись под штрихкодом. Размеры в точках принтера (dpi).
 * @return array{text:string,width:int,height:int,dpi:int}
 */
function wms_label_spec(
    string $title,
    string $code,
    string $barcode = '',
    float $wMm = 58.0,
    float $hMm = 40.0,
    int $dpi = 203,
    int $copies = 1,
    string $subtitle = ''
): array {
    $dpi = $dpi === 300 ? 300 : 203;
    $wMm = min(250.0, max(20.0, $wMm));
    $hMm = min(250.0, max(15.0, $hMm));
    $copies = min(999, max(1, $copies));
    $k = $dpi / 25.4;
    $w = (int)round($wMm * $k);
    $h = (int)round($hMm * $k);
    // Базовая геометрия — этикетка 58×40 при 203 dpi (464×320 точек);
    // остальные размеры/разрешения масштабируются коэффициентом sc.
    $sc = $w / 464.0;
    $px = static function (float $v) use ($sc): int {
        return (int)max(1, round($v * $sc));
    };
    $title = wms_label_spec_clean($title);
    $subtitle = wms_label_spec_clean($subtitle);
    $code = wms_label_spec_clean($code);
    $barsText = trim($barcode) !== '' ? trim($barcode) : $code;

    $lines = [];
    $y = $px(8);
    $titleH = $px(32);
    if ($title !== '') {
        $lines[] = "T\t0\t$y\t$w\t$titleH\t" . max(10, $px(28)) . "\t1\tC\t" . $title;
        $y += $titleH + $px(4);
    }
    if ($subtitle !== '') {
        $subH = $px(24);
        $lines[] = "T\t0\t$y\t$w\t$subH\t" . max(9, $px(20)) . "\t0\tC\t" . $subtitle;
    }

    // Низ: подпись под штрихкодом, над ней — штрихкод.
    $capSize = max(9, $px(22));
    $capH = $px(26);
    $capY = $h - $capH - $px(6);
    $barH = $px(110);
    $barY = $capY - $px(4) - $barH;
    if ($barY < $y + $px(2)) {
        $barY = max(0, $y + $px(2));
        $barH = max(20, $capY - $px(4) - $barY);
    }
    if ($code !== '' && $barsText !== '') {
        try {
            $bits = wms_code128_bits($barsText);
        } catch (Throwable $e) {
            $bits = '';
        }
        if ($bits !== '') {
            $n = strlen($bits);
            $mod = max(1, (int)floor(($w - 2 * $px(8)) / max(1, $n)));
            $barW = $n * $mod;
            $bx = (int)max(0, round(($w - $barW) / 2));
            $lines[] = "B\t$bx\t$barY\t$barH\t$mod\t$bits";
            $lines[] = "T\t0\t$capY\t$w\t$capH\t$capSize\t0\tC\t" . $code;
        }
    }    $text = 'SIZE' . "\t" . $w . "\t" . $h . "\t" . $dpi . "\t" . $copies . "\n" . implode("\n", $lines) . "\n";
    return ['text' => $text, 'width' => $w, 'height' => $h, 'dpi' => $dpi, 'copies' => $copies];
}

/**
 * Пустая (произвольная) этикетка: строки текста и, по желанию, ШК сверху
 * с подписью под ним. Размер выбирает пользователь.
 * @return array{text:string,width:int,height:int,dpi:int,copies:int}
 */
function wms_label_spec_custom(
    string $text,
    string $barcode,
    float $wMm = 70.0,
    float $hMm = 48.0,
    int $dpi = 203,
    int $copies = 1,
    bool $barcodeTop = false
): array {
    $dpi = $dpi === 300 ? 300 : 203;
    $wMm = min(250.0, max(20.0, $wMm));
    $hMm = min(250.0, max(15.0, $hMm));
    $copies = min(999, max(1, $copies));
    $k = $dpi / 25.4;
    $w = (int)round($wMm * $k);
    $h = (int)round($hMm * $k);
    $sc = $w / 464.0;
    $px = static function (float $v) use ($sc): int {
        return (int)max(1, round($v * $sc));
    };

    $lines = [];
    $topUsed = $px(8);
    $barcode = trim($barcode);
    if ($barcodeTop && $barcode !== '') {
        try {
            $bits = wms_code128_bits($barcode);
        } catch (Throwable $e) {
            $bits = '';
        }
        if ($bits !== '') {
            $n = strlen($bits);
            $mod = max(1, (int)floor(($w - 2 * $px(8)) / max(1, $n)));
            $bx = (int)max(0, round(($w - $n * $mod) / 2));
            $barH = $px(90);
            $barY = $px(10);
            $lines[] = "B\t$bx\t$barY\t$barH\t$mod\t$bits";
            $capH = $px(24);
            $capY = $barY + $barH + $px(2);
            $lines[] = "T\t0\t$capY\t$w\t$capH\t" . max(9, $px(20)) . "\t0\tC\t" . $barcode;
            $topUsed = $capY + $capH + $px(4);
        }
    }

    // Текст: до 10 строк, кегль подгоняется под свободную высоту.
    $rows = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
    $rows = array_values(array_filter(array_map(fn($s) => trim((string)$s), $rows), fn($s) => $s !== ''));
    $rows = array_slice($rows, 0, 10);
    if ($rows) {
        $avail = max(20, $h - $topUsed - $px(6));
        $lineH = (int)max(12, min((int)round($avail / count($rows)), $px(34)));
        $size = (int)max(9, round($lineH * 0.74));
        foreach ($rows as $i => $row) {
            $lines[] = "T\t0\t" . ($topUsed + $i * $lineH) . "\t$w\t$lineH\t$size\t0\tC\t" . $row;
        }
    }

    $specText = 'SIZE' . "\t" . $w . "\t" . $h . "\t" . $dpi . "\t" . $copies . "\n" . implode("\n", $lines) . "\n";
    return ['text' => $specText, 'width' => $w, 'height' => $h, 'dpi' => $dpi, 'copies' => $copies];
}
/** Секундный временный файл с описанием этикетки. */
function wms_label_spec_file(string $specText): string
{
    $f = tempnam(sys_get_temp_dir(), 'wms_lbl_');
    if ($f === false) {
        throw new RuntimeException('Не удалось создать временный файл этикетки');
    }
    if (file_put_contents($f, $specText) === false) {
        throw new RuntimeException('Не удалось записать описание этикетки');
    }
    return $f;
}

function wms_label_script(): string
{
    return dirname(__DIR__) . '/shared/render-label.ps1';
}

/** Отрендерить ZPL (^GFA) для этикетки. */
function wms_label_zpl_thermal(array $spec): string
{
    if (!wms_label_can_print_direct()) {
        throw new RuntimeException('Термопечать доступна только на Windows-сервере');
    }
    $specFile = wms_label_spec_file((string)$spec['text']);
    $outFile = tempnam(sys_get_temp_dir(), 'wms_zpl_');
    if ($outFile === false) {
        @unlink($specFile);
        throw new RuntimeException('Не удалось создать временный файл ZPL');
    }
    @unlink($outFile);
    try {
        [$code, $out, $err] = wms_proc_run([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', wms_label_script(), '-Spec', $specFile, '-Out', $outFile,
        ], 40);
        if ($code !== 0) {
            throw new RuntimeException('Ошибка подготовки этикетки: ' . trim($err ?: $out));
        }
        $zpl = (string)@file_get_contents($outFile);
        if (trim($zpl) === '') {
            throw new RuntimeException('Пустой ZPL после рендера этикетки');
        }
        return $zpl;
    } finally {
        @unlink($specFile);
        @unlink($outFile);
    }
}

/**
 * Прямая печать этикетки на принтер (Термо).
 * @return array{ok:bool,printer:string,bytes:int}
 */
function wms_label_print_thermal(string $printer, array $spec): array
{
    $printer = trim($printer);
    if ($printer === '') {
        throw new RuntimeException('Не выбран принтер');
    }
    if (!wms_label_can_print_direct()) {
        throw new RuntimeException('Прямая печать доступна только на Windows-сервере');
    }
    $names = array_column(wms_label_printers(), 'name');
    if (!in_array($printer, $names, true)) {
        throw new RuntimeException('Принтер не найден: ' . $printer);
    }
    $specFile = wms_label_spec_file((string)$spec['text']);
    try {
        [$code, $out, $err] = wms_proc_run([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', wms_label_script(), '-Spec', $specFile, '-Printer', $printer,
        ], 60);
        if ($code !== 0) {
            throw new RuntimeException('Ошибка печати: ' . trim($err ?: $out));
        }
    } finally {
        @unlink($specFile);
    }
    return ['ok' => true, 'printer' => $printer, 'bytes' => (int)($spec['width'] * $spec['height'] / 8)];
}

/**
 * Кому разрешено печатать этикетки и смотреть коды: только «Администратор»
 * и «Разработчик» (право labels_print). Остальным — ни кнопок, ни API.
 */
function wms_labels_can_print(): bool
{
    try {
        require_once __DIR__ . '/Auth.php';
        if (function_exists('wms_auth_can_permission') && wms_auth_can_permission('labels_print')) {
            return true;
        }
        $u = function_exists('wms_auth_user') ? wms_auth_user() : null;
        $role = is_array($u) ? trim((string)($u['role'] ?? '')) : '';
        return $role === 'Администратор' || $role === 'Разработчик';
    } catch (Throwable) {
        return false;
    }
}

/** Ответ 403 для запрещённой печати (JSON). */
function wms_labels_deny_json(): void
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Печать этикеток доступна только администратору и разработчику'], JSON_UNESCAPED_UNICODE);
    exit;
}
