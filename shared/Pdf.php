<?php
declare(strict_types=1);

// Минимальный генератор PDF с кириллицей, без зависимостей.
// Шрифт: TTF ищется в shared/fonts, затем Windows (arial), затем Linux (DejaVu);
// встраивается в PDF целиком (FontFile2), текст кодируется GID через Identity-H + ToUnicode.

function wms_pdf_font_files(): array
{
    $cands = [
        [__DIR__ . '/fonts/DejaVuSans.ttf', __DIR__ . '/fonts/DejaVuSans-Bold.ttf'],
        ['C:\\Windows\\Fonts\\arial.ttf', 'C:\\Windows\\Fonts\\arialbd.ttf'],
        ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
        ['/usr/share/fonts/TTF/DejaVuSans.ttf', '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf'],
    ];
    foreach ($cands as [$r, $b]) {
        if (is_file($r) && is_readable($r)) {
            return [$r, (is_file($b) && is_readable($b)) ? $b : $r];
        }
    }
    throw new RuntimeException('Не найден TTF-шрифт с кириллицей (положи *.ttf в shared/fonts)');
}

// ---------- Читатель TTF (только нужное: upm, advances, cmap, имя) ----------

final class WmsTtf
{
    private string $d;
    private array $tables = [];
    public int $upm = 1000;
    public int $numGlyphs = 0;
    public int $ascent = 800;
    public int $descent = -200;
    public array $bbox = [0, 0, 1000, 1000];
    public string $psName = 'WmsSans';
    private array $adv = [1000];
    private array $cmap = [];

    public function __construct(string $file)
    {
        $d = @file_get_contents($file);
        if ($d === false || strlen($d) < 12) {
            throw new RuntimeException('Не читается шрифт: ' . $file);
        }
        $this->d = $d;
        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $tag = substr($d, 12 + $i * 16, 4);
            $this->tables[$tag] = [$this->u32(12 + $i * 16 + 8), $this->u32(12 + $i * 16 + 12)];
        }
        foreach (['head', 'maxp', 'hhea', 'hmtx', 'cmap', 'name'] as $t) {
            if (!isset($this->tables[$t])) {
                throw new RuntimeException("В шрифте нет таблицы $t: $file");
            }
        }
        [$o] = $this->tables['head'];
        $this->upm = $this->u16($o + 18) ?: 1000;
        $this->bbox = [$this->s16($o + 36), $this->s16($o + 38), $this->u16($o + 40), $this->u16($o + 42)];
        [$o] = $this->tables['maxp'];
        $this->numGlyphs = $this->u16($o + 4);
        [$o] = $this->tables['hhea'];
        $this->ascent = $this->s16($o + 4);
        $this->descent = $this->s16($o + 6);
        $nH = $this->u16($o + 34);
        [$o] = $this->tables['hmtx'];
        $this->adv = [];
        for ($i = 0; $i < $nH; $i++) {
            $this->adv[$i] = $this->u16($o + $i * 4);
        }
        $last = $this->adv ? end($this->adv) : $this->upm;
        for ($i = $nH; $i < $this->numGlyphs; $i++) {
            $this->adv[$i] = $last;
        }
        $this->psName = $this->readPsName();
        $this->cmap = $this->readCmap();
    }

    private function u16(int $p): int
    {
        return (ord($this->d[$p]) << 8) + ord($this->d[$p + 1]);
    }

    private function s16(int $p): int
    {
        $v = $this->u16($p);
        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $p): int
    {
        return (ord($this->d[$p]) << 24) + (ord($this->d[$p + 1]) << 16) + (ord($this->d[$p + 2]) << 8) + ord($this->d[$p + 3]);
    }

    private function readPsName(): string
    {
        [$o] = $this->tables['name'];
        $count = $this->u16($o + 2);
        $strOff = $this->u16($o + 4);
        $best = null;
        for ($i = 0; $i < $count; $i++) {
            $r = $o + 6 + $i * 12;
            $plat = $this->u16($r);
            $enc = $this->u16($r + 2);
            $id = $this->u16($r + 6);
            if ($id !== 6) {
                continue;
            }
            $raw = substr($this->d, $o + $strOff + $this->u16($r + 10), $this->u16($r + 8));
            $txt = ($plat === 3 || $plat === 0) ? $this->utf16beAscii($raw) : $raw;
            if ($plat === 3 && $enc === 1) {
                return $this->cleanPs($txt);
            }
            if ($best === null) {
                $best = $txt;
            }
        }
        return $this->cleanPs($best ?? 'WmsSans');
    }

    private function utf16beAscii(string $s): string
    {
        $out = '';
        for ($i = 0; $i + 1 < strlen($s); $i += 2) {
            $c = (ord($s[$i]) << 8) + ord($s[$i + 1]);
            $out .= $c < 128 ? chr($c) : '?';
        }
        return $out;
    }

    private function cleanPs(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9-]/', '-', $s);
        $s = trim((string)$s, '-');
        return $s !== '' ? substr($s, 0, 60) : 'WmsSans';
    }

    private function readCmap(): array
    {
        [$o] = $this->tables['cmap'];
        $n = $this->u16($o + 2);
        $subs = [];
        for ($i = 0; $i < $n; $i++) {
            $subs[] = [$this->u16($o + 4 + $i * 8), $this->u16($o + 6 + $i * 8), $o + $this->u32($o + 8 + $i * 8)];
        }
        // Приоритет: полный юникод, затем BMP.
        foreach ([[3, 10], [0, 4], [3, 1], [0, 3]] as [$pp, $ee]) {
            foreach ($subs as [$p, $e, $off]) {
                if ($p !== $pp || $e !== $ee) {
                    continue;
                }
                $fmt = $this->u16($off);
                if ($fmt === 12) {
                    $m = $this->cmap12($off);
                    if ($m) {
                        return $m;
                    }
                } elseif ($fmt === 4) {
                    $m = $this->cmap4($off);
                    if ($m) {
                        return $m;
                    }
                }
            }
        }
        return [];
    }

    private function cmap4(int $b): array
    {
        $segCount = (int)($this->u16($b + 6) / 2);
        if ($segCount <= 0 || $segCount > 256) {
            return [];
        }
        $map = [];
        // endCode[b+14+i*2], reservedPad(2), startCode[b+16+segCount*2+i*2],
        // idDelta[b+16+segCount*4+i*2], idRangeOffset[b+16+segCount*6+i*2].
        $roStart = $b + 16 + $segCount * 6;
        for ($i = 0; $i < $segCount; $i++) {
            $end = $this->u16($b + 14 + $i * 2);
            $start = $this->u16($b + 16 + $segCount * 2 + $i * 2);
            $delta = $this->s16($b + 16 + $segCount * 4 + $i * 2);
            $roOff = $roStart + $i * 2;
            $ro = $this->u16($roOff);
            if ($ro === 0) {
                for ($c = $start; $c <= $end && $c <= 0xFFFF; $c++) {
                    if ($c === 0xFFFF) {
                        break;
                    }
                    $map[$c] = ($c + $delta) & 0xFFFF;
                }
            } else {
                for ($c = $start; $c <= $end && $c <= 0xFFFF; $c++) {
                    if ($c === 0xFFFF) {
                        break;
                    }
                    $g = $this->u16($roOff + $ro + 2 * ($c - $start));
                    if ($g !== 0) {
                        $map[$c] = ($g + $delta) & 0xFFFF;
                    }
                }
            }
        }
        return $map;
    }

    private function cmap12(int $b): array
    {
        $n = $this->u32($b + 12);
        if ($n <= 0 || $n > 100000) {
            return [];
        }
        $map = [];
        for ($i = 0; $i < $n; $i++) {
            $s = $this->u32($b + 16 + $i * 12);
            $e = $this->u32($b + 20 + $i * 12);
            $g = $this->u32($b + 24 + $i * 12);
            for ($c = $s; $c <= $e && $c - $s < 100000; $c++) {
                $map[$c] = $g + ($c - $s);
            }
        }
        return $map;
    }

    public function gid(int $cp): int
    {
        $g = $this->cmap[$cp] ?? 0;
        return ($g >= 0 && $g < $this->numGlyphs) ? $g : 0;
    }

    /** Ширина глифа в единицах PDF (тысячные em). */
    public function pdfW(int $gid): float
    {
        return ($this->adv[$gid] ?? $this->upm) * 1000.0 / $this->upm;
    }

    /** Юникод-точки UTF-8 строки. */
    public static function cps(string $s): array
    {
        $out = [];
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $out[] = $b;
                $i++;
            } elseif (($b & 0xE0) === 0xC0 && $i + 1 < $len) {
                $out[] = (($b & 0x1F) << 6) + (ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($b & 0xF0) === 0xE0 && $i + 2 < $len) {
                $out[] = (($b & 0x0F) << 12) + ((ord($s[$i + 1]) & 0x3F) << 6) + (ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($b & 0xF8) === 0xF0 && $i + 3 < $len) {
                $out[] = (($b & 0x07) << 18) + ((ord($s[$i + 1]) & 0x3F) << 12) + ((ord($s[$i + 2]) & 0x3F) << 6) + (ord($s[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $i++;
            }
        }
        return $out;
    }

    /** Ширина строки в пунктах при кегле $size. */
    public function strW(string $s, float $size): float
    {
        $w = 0.0;
        foreach (self::cps($s) as $cp) {
            $w += $this->pdfW($this->gid($cp));
        }
        return $w * $size / 1000.0;
    }

    public static function utf16be(int $cp): string
    {
        if ($cp < 0x10000) {
            return chr(($cp >> 8) & 0xFF) . chr($cp & 0xFF);
        }
        $cp -= 0x10000;
        $hi = 0xD800 + ($cp >> 10);
        $lo = 0xDC00 + ($cp & 0x3FF);
        return chr(($hi >> 8) & 0xFF) . chr($hi & 0xFF) . chr(($lo >> 8) & 0xFF) . chr($lo & 0xFF);
    }
}

// ---------- Конструктор PDF ----------

final class WmsPdf
{
    public array $objs = [];

    public function add(string $body): int
    {
        $this->objs[] = $body;
        return count($this->objs);
    }

    public function render(): string
    {
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offs = [];
        foreach ($this->objs as $i => $b) {
            $offs[$i + 1] = strlen($out);
            $out .= ($i + 1) . " 0 obj\n" . $b . "\nendobj\n";
        }
        // Корень — объект каталога (он добавляется последним, а не первым).
        $root = 1;
        foreach ($this->objs as $i => $b) {
            if (strpos($b, '/Type /Catalog') !== false) {
                $root = $i + 1;
                break;
            }
        }
        $xref = strlen($out);
        $n = count($this->objs);
        $out .= "xref\n0 " . ($n + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offs[$i]);
        }
        $out .= "trailer\n<< /Size " . ($n + 1) . " /Root $root 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}

// ---------- Штрихкод Code128-B (векторный, для номера документа) ----------

final class WmsCode128
{
    // Code128: ширины штрих/пробел (бит 1/0) по таблице code128.
    // 0-102 данные, 103/104/105 Start A/B/C, 106 Stop (без терминального штриха 11).
    private const C128 = [
        0 => [2,1,2,2,2,2],
        1 => [2,2,2,1,2,2],
        2 => [2,2,2,2,2,1],
        3 => [1,2,1,2,2,3],
        4 => [1,2,1,3,2,2],
        5 => [1,3,1,2,2,2],
        6 => [1,2,2,2,1,3],
        7 => [1,2,2,3,1,2],
        8 => [1,3,2,2,1,2],
        9 => [2,2,1,2,1,3],
        10 => [2,2,1,3,1,2],
        11 => [2,3,1,2,1,2],
        12 => [1,1,2,2,3,2],
        13 => [1,2,2,1,3,2],
        14 => [1,2,2,2,3,1],
        15 => [1,1,3,2,2,2],
        16 => [1,2,3,1,2,2],
        17 => [1,2,3,2,2,1],
        18 => [2,2,3,2,1,1],
        19 => [2,2,1,1,3,2],
        20 => [2,2,1,2,3,1],
        21 => [2,1,3,2,1,2],
        22 => [2,2,3,1,1,2],
        23 => [3,1,2,1,3,1],
        24 => [3,1,1,2,2,2],
        25 => [3,2,1,1,2,2],
        26 => [3,2,1,2,2,1],
        27 => [3,1,2,2,1,2],
        28 => [3,2,2,1,1,2],
        29 => [3,2,2,2,1,1],
        30 => [2,1,2,1,2,3],
        31 => [2,1,2,3,2,1],
        32 => [2,3,2,1,2,1],
        33 => [1,1,1,3,2,3],
        34 => [1,3,1,1,2,3],
        35 => [1,3,1,3,2,1],
        36 => [1,1,2,3,1,3],
        37 => [1,3,2,1,1,3],
        38 => [1,3,2,3,1,1],
        39 => [2,1,1,3,1,3],
        40 => [2,3,1,1,1,3],
        41 => [2,3,1,3,1,1],
        42 => [1,1,2,1,3,3],
        43 => [1,1,2,3,3,1],
        44 => [1,3,2,1,3,1],
        45 => [1,1,3,1,2,3],
        46 => [1,1,3,3,2,1],
        47 => [1,3,3,1,2,1],
        48 => [3,1,3,1,2,1],
        49 => [2,1,1,3,3,1],
        50 => [2,3,1,1,3,1],
        51 => [2,1,3,1,1,3],
        52 => [2,1,3,3,1,1],
        53 => [2,1,3,1,3,1],
        54 => [3,1,1,1,2,3],
        55 => [3,1,1,3,2,1],
        56 => [3,3,1,1,2,1],
        57 => [3,1,2,1,1,3],
        58 => [3,1,2,3,1,1],
        59 => [3,3,2,1,1,1],
        60 => [3,1,4,1,1,1],
        61 => [2,2,1,4,1,1],
        62 => [4,3,1,1,1,1],
        63 => [1,1,1,2,2,4],
        64 => [1,1,1,4,2,2],
        65 => [1,2,1,1,2,4],
        66 => [1,2,1,4,2,1],
        67 => [1,4,1,1,2,2],
        68 => [1,4,1,2,2,1],
        69 => [1,1,2,2,1,4],
        70 => [1,1,2,4,1,2],
        71 => [1,2,2,1,1,4],
        72 => [1,2,2,4,1,1],
        73 => [1,4,2,1,1,2],
        74 => [1,4,2,2,1,1],
        75 => [2,4,1,2,1,1],
        76 => [2,2,1,1,1,4],
        77 => [4,1,3,1,1,1],
        78 => [2,4,1,1,1,2],
        79 => [1,3,4,1,1,1],
        80 => [1,1,1,2,4,2],
        81 => [1,2,1,1,4,2],
        82 => [1,2,1,2,4,1],
        83 => [1,1,4,2,1,2],
        84 => [1,2,4,1,1,2],
        85 => [1,2,4,2,1,1],
        86 => [4,1,1,2,1,2],
        87 => [4,2,1,1,1,2],
        88 => [4,2,1,2,1,1],
        89 => [2,1,2,1,4,1],
        90 => [2,1,4,1,2,1],
        91 => [4,1,2,1,2,1],
        92 => [1,1,1,1,4,3],
        93 => [1,1,1,3,4,1],
        94 => [1,3,1,1,4,1],
        95 => [1,1,4,1,1,3],
        96 => [1,1,4,3,1,1],
        97 => [4,1,1,1,1,3],
        98 => [4,1,1,3,1,1],
        99 => [1,1,3,1,4,1],
        100 => [1,1,4,1,3,1],
        101 => [3,1,1,1,4,1],
        102 => [4,1,1,1,3,1],
        103 => [2,1,1,4,1,2],
        104 => [2,1,1,2,1,4],
        105 => [2,1,1,2,3,2],
        106 => [2,3,3,1,1,1],
    ];
    /** Модули штрих/пробел для Code128-B: Start B + данные + checksum + Stop + терминал. */
    public static function modules(string $s): array
    {
        $vals = [];
        $n = strlen($s);
        if ($n === 0) {
            throw new RuntimeException('Code128: пустая строка');
        }
        for ($i = 0; $i < $n; $i++) {
            $o = ord($s[$i]);
            if ($o < 32 || $o > 126) {
                throw new RuntimeException('Code128: только ASCII 32-126');
            }
            $vals[] = $o - 32;
        }
        $chk = 104;
        foreach ($vals as $i => $v) {
            $chk += $v * ($i + 1);
        }
        $out = [];
        foreach (array_merge([104], $vals, [$chk % 103, 106]) as $v) {
            foreach (self::C128[$v] as $w) {
                $out[] = $w;
            }
        }
        $out[] = 2; // терминальный штрих
        return $out;
    }

    /** Битовая строка ('1' — штрих): для тестов/сверки с эталоном. */
    public static function bits(string $s): string
    {
        $b = '';
        foreach (self::modules($s) as $i => $w) {
            $b .= str_repeat(($i % 2 === 0) ? '1' : '0', $w);
        }
        return $b;
    }
}

// ---------- Табличный документ ----------

function wms_pdf_clean(string $s): string
{
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
}

function wms_pdf_cell(mixed $v): string
{
    if ($v === null) {
        return '';
    }
    if (is_bool($v)) {
        return $v ? 'Да' : 'Нет';
    }
    if (is_float($v)) {
        $s = number_format($v, 3, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
    return wms_pdf_clean((string)$v);
}

/** Первый UTF-8 символ строки (без mbstring). */
function wms_pdf_first_char(string $s): string
{
    if ($s === '') {
        return '';
    }
    $b = ord($s[0]);
    $l = $b < 0x80 ? 1 : ((($b & 0xE0) === 0xC0 ? 2 : ((($b & 0xF0) === 0xE0 ? 3 : 4))));
    return substr($s, 0, $l);
}

/** Перенос строки по ширине (длинные слова без пробелов рвем по символам). */
function wms_pdf_wrap(WmsTtf $f, string $s, float $size, float $maxW): array
{
    $s = $s === '' ? '—' : $s;
    $words = preg_split('/\s+/u', (string)$s);
    $lines = [];
    $cur = '';
    $push = static function () use (&$lines, &$cur) {
        if ($cur !== '') {
            $lines[] = $cur;
            $cur = '';
        }
    };
    foreach ($words as $w) {
        // Слово шире колонки — бьем по символам.
        while ($w !== '' && $f->strW($w, $size) > $maxW) {
            $cut = '';
            $rest = $w;
            while ($rest !== '') {
                $ch = wms_pdf_first_char($rest);
                if ($cut !== '' && $f->strW($cut . $ch, $size) > $maxW) {
                    break;
                }
                $cut .= $ch;
                $rest = substr($rest, strlen($ch));
            }
            if ($cut === '') {
                // Один символ шире колонки — кладем как есть, чтобы не зациклиться.
                $cut = wms_pdf_first_char($w);
                $rest = substr($w, strlen($cut));
            }
            if ($cur !== '') {
                $push();
            }
            $lines[] = $cut;
            $w = $rest;
        }
        $t = $cur === '' ? $w : $cur . ' ' . $w;
        if ($f->strW($t, $size) > $maxW) {
            $push();
            $cur = $w;
        } else {
            $cur = $t;
        }
    }
    $push();
    return $lines ?: ['—'];
}

/**
 * @param string $title заголовок
 * @param array $meta пары [подпись, значение]
 * @param array $cols [ключ => подпись колонки]
 * @param array $rows строки-ассоциативные массивы
 * @param string|null $barcode текст штрихкода Code128 справа вверху первой страницы
 */
function wms_pdf_table(string $title, array $meta, array $cols, array $rows, ?string $barcode = null): string
{
    [$regFile, $boldFile] = wms_pdf_font_files();
    $reg = new WmsTtf($regFile);
    $bold = new WmsTtf($boldFile);
    $regBytes = (string)file_get_contents($regFile);
    $boldBytes = (string)file_get_contents($boldFile);

    $PW = 595.28;
    $PH = 841.89;
    $ML = 36.0;
    $MR = 36.0;
    $MT = 46.0;
    $MB = 44.0;
    $W = $PW - $ML - $MR;
    $fsTitle = 14.0;
    $fsMeta = 9.5;
    $fsCell = 9.0;
    $lh = static fn(float $fs): float => $fs * 1.4;
    $pad = 4.0;

    $title = wms_pdf_clean($title);
    // Штрихкод: валидируем заранее; при ошибке документ печатается без него.
    $barMods = null;
    if ($barcode !== null && $barcode !== '') {
        try {
            $barMods = WmsCode128::modules($barcode);
        } catch (Throwable) {
            $barMods = null;
        }
    }
    $metaLines = [];
    foreach ($meta as [$label, $val]) {
        $val = wms_pdf_cell($val);
        if ($val === '' && $label === '') {
            continue;
        }
        $metaLines[] = [$label, $val];
    }

    // Ширины колонок по содержимому.
    $keys = array_keys($cols);
    $cw = [];
    foreach ($keys as $k) {
        $w = $bold->strW((string)$cols[$k], $fsCell);
        foreach ($rows as $r) {
            $w = max($w, $reg->strW(wms_pdf_cell($r[$k] ?? ''), $fsCell));
        }
        $cw[$k] = $w + $pad * 2;
    }
    $sum = array_sum($cw);
    if ($sum > 0) {
        $f = $W / $sum;
        foreach ($cw as $k => $v) {
            $cw[$k] = max(28.0, $v * $f);
        }
        // Нормируем ровно под ширину (погрешности округления).
        $cw[$keys[count($keys) - 1]] += $W - array_sum($cw);
    }

    // Раскладка по страницам.
    $pages = [];
    $cur = [];
    $y = $MT;
    $needHead = true;
    $titleBlock = $lh($fsTitle) + 6 + count($metaLines) * $lh($fsMeta) + 10;
    $headH = $lh($fsCell) + 4;
    $flush = static function () use (&$pages, &$cur) {
        if ($cur) {
            $pages[] = $cur;
            $cur = [];
        }
    };
    if (!$rows) {
        $pages[] = [];
    } else {
        foreach ($rows as $r) {
            $rh = $lh($fsCell);
            $cellLines = [];
            foreach ($keys as $k) {
                $ls = wms_pdf_wrap($reg, wms_pdf_cell($r[$k] ?? ''), $fsCell, max(10.0, $cw[$k] - $pad * 2));
                $cellLines[$k] = $ls;
                $rh = max($rh, count($ls) * $lh($fsCell));
            }
            $rh += 4;
            $top = ($needHead || !$pages && !$cur) ? $titleBlock : 0;
            if ($y + $top + $headH + $rh > $PH - $MB && $cur) {
                $flush();
                $y = $MT;
                $needHead = true;
            }
            $cur[] = [$r, $cellLines, $rh];
            $y += ($needHead ? $headH : 0) + $rh;
            $needHead = false;
        }
        $flush();
    }

    // Используемые символы по шрифтам.
    $useR = [];
    $useB = [];
    $mark = static function (string $s, bool $b) use ($reg, $bold, &$useR, &$useB) {
        $f = $b ? $bold : $reg;
        $u = $b ? $useB : $useR;
        foreach (WmsTtf::cps($s) as $cp) {
            $u[$cp] = true;
        }
        if ($b) {
            $useB = $u;
        } else {
            $useR = $u;
        }
    };
    $mark($title, true);
    if ($barMods !== null) {
        $mark($barcode, false);
    }
    foreach ($metaLines as [$l, $v]) {
        $mark($l . ': ' . $v, false);
    }
    foreach ($pages as $pg) {
        foreach ($keys as $k) {
            $mark((string)$cols[$k], true);
        }
        foreach ($pg as [, $cellLines]) {
            foreach ($cellLines as $ls) {
                foreach ($ls as $ln) {
                    $mark($ln, false);
                }
            }
        }
    }
    // Служебные строки дописываются при эмиссии — их символы нужны заранее.
    $mark('— · 0123456789.:', false);
    $mark('Страница из', false);
    $mark('Нет строк', false);

    $pdf = new WmsPdf();
    $fontObjs = [];
    foreach ([['F1', $reg, $regBytes, $useR], ['F2', $bold, $boldBytes, $useB]] as [$tag, $f, $bytes, $used]) {
        $gids = [];
        foreach (array_keys($used) as $cp) {
            $gids[$f->gid($cp)] = true;
        }
        $gids = array_keys($gids);
        sort($gids);
        // /W runs.
        $w = '';
        $run = null;
        foreach ($gids as $g) {
            $ww = (int)round($f->pdfW($g));
            if ($run === null || $g !== $run[1] + 1) {
                if ($run !== null) {
                    $w .= $run[0] . ' [' . implode(' ', $run[2]) . '] ';
                }
                $run = [$g, $g, []];
            }
            $run[1] = $g;
            $run[2][] = $ww;
        }
        if ($run !== null) {
            $w .= $run[0] . ' [' . implode(' ', $run[2]) . '] ';
        }
        // ToUnicode.
        $tu = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . count($used) . " beginbfchar\n";
        foreach (array_keys($used) as $cp) {
            $tu .= sprintf('<%04X> <%s>', $f->gid($cp), strtoupper(bin2hex(WmsTtf::utf16be($cp)))) . "\n";
        }
        $tu .= "endbfchar\nendcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n";
        $ps = $f->psName;
        $bb = $f->bbox;
        $asc = (int)round($f->ascent * 1000 / $f->upm);
        $desc = (int)round($f->descent * 1000 / $f->upm);
        $fileId = $pdf->add("<< /Length " . strlen($bytes) . " /Length1 " . strlen($bytes) . " >>\nstream\n" . $bytes . "\nendstream");
        $descId = $pdf->add("<< /Type /FontDescriptor /FontName /$ps /Flags 32 "
            . "/FontBBox [$bb[0] $bb[1] $bb[2] $bb[3]] /ItalicAngle 0 /Ascent $asc /Descent $desc /StemV 80 /FontFile2 $fileId 0 R >>");
        $dw = (int)round($f->pdfW(0));
        $cidId = $pdf->add("<< /Type /Font /Subtype /CIDFontType2 /BaseFont /$ps "
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> "
            . "/DW $dw /W [$w] /FontDescriptor $descId 0 R >>");
        $tuId = $pdf->add("<< /Length " . strlen($tu) . " >>\nstream\n" . $tu . "endstream");
        $t0Id = $pdf->add("<< /Type /Font /Subtype /Type0 /BaseFont /$ps /Encoding /Identity-H "
            . "/DescendantFonts [$cidId 0 R] /ToUnicode $tuId 0 R >>");
        $fontObjs[$tag] = $t0Id;
    }

    $enc = static function (WmsTtf $f, string $s): string {
        $h = '';
        foreach (WmsTtf::cps($s) as $cp) {
            $h .= sprintf('%04X', $f->gid($cp));
        }
        return $h;
    };
    $T = static function (string $tag, float $size, float $x, float $y, string $hex): string {
        return "BT /$tag " . rtrim(rtrim(sprintf('%.2F', $size), '0'), '.') . " Tf 1 0 0 1 "
            . sprintf('%.2F %.2F', $x, $y) . " Tm <$hex> Tj ET\n";
    };

    $contentIds = [];
    $pageKids = [];
    foreach ($pages as $pi => $pg) {
        $s = '';
        $y = $PH - $MT;
        if ($pi === 0) {
            $s .= $T('F2', $fsTitle, $ML, $y - $fsTitle, $enc($bold, $title));
            $y -= $lh($fsTitle) + 6;
            foreach ($metaLines as [$l, $v]) {
                $txt = $l !== '' ? $l . ': ' . $v : $v;
                $s .= $T('F1', $fsMeta, $ML, $y - $fsMeta, $enc($reg, $txt));
                $y -= $lh($fsMeta);
            }
            $y -= 6;
            $s .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $ML, $y, $ML + $W, $y);
            $y -= 4;
            if ($barMods !== null) {
                // Штрихкод номера документа справа вверху (тихая зона — белый фон).
                $modW = min(1.0, ($W * 0.55) / max(1, array_sum($barMods)));
                $barH = 34.0;
                $totW = array_sum($barMods) * $modW;
                $bx = $ML + $W - 10 * $modW - $totW;
                $by = $PH - $MT;
                $s .= "0 g\n";
                $cx = $bx;
                foreach ($barMods as $mi => $mw) {
                    if ($mi % 2 === 0) {
                        $s .= sprintf('%.2F %.2F %.2F %.2F re f' . "\n", $cx, $by - $barH, $mw * $modW, $barH);
                    }
                    $cx += $mw * $modW;
                }
                $capW = $reg->strW($barcode, 8);
                $s .= $T('F1', 8, $bx + max(0.0, ($totW - $capW) / 2), $by - $barH - 10, $enc($reg, $barcode));
            }
        }
        if ($keys && ($pg || $pi === 0)) {
            // Шапка таблицы.
            $x = $ML;
            $s .= "0.92 0.92 0.92 rg\n";
            foreach ($keys as $k) {
                $s .= sprintf('%.2F %.2F %.2F %.2F re f' . "\n", $x, $y - $headH, $cw[$k], $headH);
                $x += $cw[$k];
            }
            $s .= "0 g\n";
            $x = $ML;
            foreach ($keys as $k) {
                $s .= sprintf('%.2F %.2F %.2F %.2F re S' . "\n", $x, $y - $headH, $cw[$k], $headH);
                $s .= $T('F2', $fsCell, $x + $pad, $y - $fsCell - 2, $enc($bold, (string)$cols[$k]));
                $x += $cw[$k];
            }
            $y -= $headH;
            foreach ($pg as [, $cellLines, $rh]) {
                $x = $ML;
                foreach ($keys as $k) {
                    $s .= sprintf('%.2F %.2F %.2F %.2F re S' . "\n", $x, $y - $rh, $cw[$k], $rh);
                    $ly = $y - $fsCell - 2;
                    foreach ($cellLines[$k] as $ln) {
                        $s .= $T('F1', $fsCell, $x + $pad, $ly, $enc($reg, $ln));
                        $ly -= $lh($fsCell);
                    }
                    $x += $cw[$k];
                }
                $y -= $rh;
            }
            if (!$pg && $pi === 0 && !$rows) {
                $s .= $T('F1', $fsCell, $ML, $y - $fsCell, $enc($reg, 'Нет строк'));
            }
        }
        // Футер.
        $s .= $T('F1', 8, $ML, 24, $enc($reg, 'Страница ' . ($pi + 1) . ' из ' . count($pages) . ' · ' . date('d.m.Y H:i')));
        $contentIds[] = $pdf->add("<< /Length " . strlen($s) . " >>\nstream\n" . $s . "endstream");
    }

    $pagesId = $pdf->add('@@KIDS@@');
    foreach ($contentIds as $ci) {
        $pageKids[] = $pdf->add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 $PW $PH] "
            . "/Contents $ci 0 R /Resources << /Font << /F1 {$fontObjs['F1']} 0 R /F2 {$fontObjs['F2']} 0 R >> >> >>");
    }
    $kids = implode(' ', array_map(static fn($k): string => "$k 0 R", $pageKids));
    $pdf->objs[$pagesId - 1] = "<< /Type /Pages /Kids [$kids] /Count " . count($pageKids) . " >>";
    $pdf->add("<< /Type /Catalog /Pages $pagesId 0 R >>");
    return $pdf->render();
}

// ---------- Автомаппинг данных печати ----------

function wms_pdf_labels(): array
{
    return [
        'doc_no' => 'Документ №', 'external_no' => 'Внешний №', 'doc_date' => 'Дата документа',
        'plan_delivery_date' => 'Поставка (план)', 'arrival_date' => 'Прибытие',
        'accept_date' => 'Приемка', 'vehicle' => 'ТС', 'gate' => 'Ворота',
        'supplier' => 'Поставщик', 'supplier_name' => 'Поставщик',
        'contractor' => 'Контрагент', 'warehouse' => 'Склад', 'warehouse_name' => 'Склад',
        'status' => 'Статус', 'status_label' => 'Статус', 'total' => 'Сумма',
        'total_with_vat' => 'Итого с НДС', 'sku_count' => 'SKU', 'qty_total' => 'Штук',
        'items_count' => 'Позиций', 'accepted_pct' => 'Принято %', 'placed_pct' => 'Размещено %',
        'line_no' => 'п/п', 'code' => 'Код', 'barcode' => 'Штрихкод', 'sku' => 'Артикул',
        'name' => 'Наименование', 'unit' => 'Ед.', 'qty' => 'Кол-во', 'sscc' => 'SSCC',
        'plan_qty' => 'План', 'fact_qty' => 'Факт', 'price' => 'Цена', 'sum' => 'Сумма',
        'type' => 'Тип', 'qty_diff' => 'Отклонение', 'created_at' => 'Дата',
        'flag_date' => 'Дата', 'pallet' => 'Паллета', 'cell' => 'Ячейка',
        'zone' => 'Зона', 'check_label' => 'Этикетка', 'contract_no' => 'Договор',
        'priority' => 'Приоритет', 'comment' => 'Комментарий', 'category' => 'Категория',
        'brand' => 'Бренд', 'owner' => 'Владелец', 'qty_in_box' => 'Шт в коробе',
        'title' => 'Документ', 'quality' => 'Качество', 'prod_group' => 'Группа',
        'prod_date' => 'Дата произв.', 'temp_label' => 'Темп. режим',
    ];
}

function wms_pdf_skip_key(string $k): bool
{
    if ($k === 'id' || $k === 'kind' || $k === 'title') {
        return true;
    }
    if (preg_match('/(_id|_by)$/', $k)) {
        return true;
    }
    return in_array($k, ['is_paid', 'is_locked', 'is_archived', 'is_active', 'is_posted', 'password', 'pin'], true);
}

/**
 * Собрать PDF из массива printData: title/meta/таблица выводятся автоматически.
 * @param array|null $forceCols [ключ => подпись] — явные колонки вместо авто
 * @param string|null $barcodeText текст для штрихкода Code128 справа вверху (первая страница)
 */
function wms_pdf_auto(array $data, ?array $forceCols = null, ?string $barcodeText = null): string
{
    $labels = wms_pdf_labels();
    $kinds = ['act' => 'Акт приема-передачи', 'act_rasx' => 'Акт о расхождениях',
        'receiver_doc' => 'Документ приемщика', 'label' => 'Этикетка', 'barcode' => 'Штрихкод',
        'list' => 'Документ', 'torg12' => 'ТОРГ-12', 'torg2' => 'ТОРГ-2', 'mx1' => 'МХ-1',
        'pick_list' => 'Сборочный лист', 'pack_list' => 'Упаковочный лист',
        'shk' => 'Штрихкоды', 're' => 'Реестр', 'pal' => 'Паллетный лист'];
    $title = (string)($data['title'] ?? $kinds[$data['kind'] ?? ''] ?? 'Документ');
    $meta = [];
    $metaKeys = ['doc_no', 'doc_date', 'external_no', 'supplier_name', 'supplier', 'warehouse_name', 'warehouse',
        'status_label', 'status', 'total', 'total_with_vat', 'gate', 'vehicle', 'contract_no',
        'code', 'zone', 'type', 'sku', 'name', 'barcode', 'unit', 'sscc'];
    $head = $data['head'] ?? null;
    if (is_array($head)) {
        foreach ($metaKeys as $k) {
            if (array_key_exists($k, $head) && $head[$k] !== null && $head[$k] !== '') {
                $meta[] = [$labels[$k] ?? $k, wms_pdf_cell($head[$k])];
            }
        }
    } else {
        foreach ($data as $k => $v) {
            if (is_scalar($v) && !wms_pdf_skip_key((string)$k) && $v !== '' && $v !== null) {
                $meta[] = [$labels[$k] ?? $k, wms_pdf_cell($v)];
            }
        }
    }
    $rows = $data['rows'] ?? $data['items'] ?? [];
    if (!is_array($rows)) {
        $rows = [];
    }
    $cols = $forceCols;
    if ($cols === null) {
        $cols = [];
        if ($rows) {
            $first = reset($rows);
            if (is_array($first)) {
                foreach ($first as $k => $v) {
                    if (!wms_pdf_skip_key((string)$k)) {
                        $cols[$k] = $labels[$k] ?? $k;
                    }
                }
            }
        }
    }
    return wms_pdf_table($title, $meta, $cols, array_values($rows), $barcodeText);
}

/**
 * Подготовка данных отгрузки к печати: нумерация строк + фиксированные колонки.
 * Без этого SELECT * отдавал бы ~14 технических колонок (id, warehouse_id, ...) —
 * таблица сжималась в кашу, а при пустых позициях не было даже шапки.
 * Для сборочного/упаковочного листов — свои наборы колонок по kind.
 * @return array{0:array,1:array} [$data, $forceCols]
 */
function wms_shipment_print(array $data, ?array $force = null): array
{
    $rows = array_values($data['items'] ?? $data['rows'] ?? []);
    foreach ($rows as $i => &$r) {
        if (is_array($r)) {
            $r = ['line_no' => $i + 1] + $r;
        }
    }
    unset($r);
    $data['items'] = $rows;
    if ($force === null) {
        $k = $data['kind'] ?? '';
        if ($k === 'pick_list') {
            $force = ['line_no' => 'п/п', 'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование',
                'unit' => 'Ед.', 'plan_qty' => 'План', 'fact_qty' => 'Отобрано'];
        } elseif ($k === 'pack_list') {
            $force = ['line_no' => 'п/п', 'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование',
                'unit' => 'Ед.', 'qty' => 'Кол-во'];
        } else {
            $force = ['line_no' => 'п/п', 'code' => 'Код', 'sku' => 'Артикул', 'name' => 'Наименование',
                'unit' => 'Ед.', 'qty' => 'Кол-во', 'price' => 'Цена', 'sum' => 'Сумма'];
        }
    }
    return [$data, $force];
}

function wms_pdf_filename(string $title, int $id = 0): string
{
    // Явные карты обоих регистров (без mbstring).
    $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'Yo',
        'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
        'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
        'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch',
        'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'];
    $s = strtr($title, $map);
    $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s);
    $s = trim((string)$s, '_');
    if ($s === '') {
        $s = 'document';
    }
    if ($id > 0) {
        $s .= '_' . $id;
    }
    return substr($s, 0, 80) . '.pdf';
}

/** Отдать PDF браузеру (открывается во вкладке). */
function wms_pdf_output(string $bytes, string $filename): void
{
    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private');
    }
    echo $bytes;
}
