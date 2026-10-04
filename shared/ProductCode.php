<?php
declare(strict_types=1);

// Единый генератор внутреннего кода товара (поле products.code).
//
// Схема: T-101, T-102, ... T-999, затем T-1001 ... T-1999, затем T-2001 ... T-2999 и т.д.
// Числа, кратные 1000 (T-1000, T-2000, ...), пропускаются; отсчет начинается со 101.
// Буква T — латинская.
//
// Старые числовые коды вида 21788999521 считаются legacy: они остаются валидными
// (карточки, документы, штрихкоды не меняются), но на генерацию не влияют —
// счетчик T-кодов ведется независимо от них.

/** Следующий свободный код товара по схеме T-101... */
function wms_next_product_code(PDO $pdo): string
{
    $max = null;
    try {
        $rows = $pdo->query("SELECT code FROM products WHERE code LIKE 'T-%'")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable) {
        return 'T-101';
    }
    foreach ($rows as $c) {
        if (preg_match('/^T-(\d+)$/', trim((string)$c), $m)) {
            $n = (int)$m[1];
            if ($max === null || $n > $max) {
                $max = $n;
            }
        }
    }
    $n = ($max === null) ? 101 : $max + 1;
    if ($n < 101) {
        $n = 101;
    }
    // Пропуск круглых тысяч + защита от дублей (следующий номер мог занять ручной ввод).
    try {
        $chk = $pdo->prepare("SELECT 1 FROM products WHERE code = :c LIMIT 1");
        for ($guard = 0; $guard < 100000; $guard++) {
            if ($n % 1000 === 0) {
                $n++;
                continue;
            }
            $chk->execute([':c' => 'T-' . $n]);
            if (!$chk->fetchColumn()) {
                return 'T-' . $n;
            }
            $n++;
        }
    } catch (Throwable) {
        if ($n % 1000 === 0) {
            $n++;
        }
        return 'T-' . $n;
    }
    throw new RuntimeException('Не удалось подобрать свободный код товара');
}
