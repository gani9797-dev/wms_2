<?php
declare(strict_types=1);
/**
 * Генератор документации по интеграции:
 *   документация/ИНТЕГРАЦИЯ-API-ERP.docx
 *
 * DOCX собирается без ZipArchive: store-ZIP + OOXML (см. shared/XlsxWrite.php).
 * Запуск: php документация/build_integration_docs.php
 */

require_once __DIR__ . '/../shared/XlsxWrite.php';

function dx(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Inline-разметка: **жирный**, `код`. Переводы строк → <w:br/>. */
function d_runs(string $text, string $extraRpr = ''): string
{
    $parts = preg_split('/(\*\*[^*]+\*\*|`[^`]+`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
    $xml = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $bold = false;
        $code = false;
        if (strlen($part) > 4 && str_starts_with($part, '**') && str_ends_with($part, '**')) {
            $part = substr($part, 2, -2);
            $bold = true;
        } elseif (strlen($part) > 2 && $part[0] === '`' && str_ends_with($part, '`')) {
            $part = substr($part, 1, -1);
            $code = true;
        }
        $rpr = $extraRpr;
        if ($bold) {
            $rpr .= '<w:b/><w:bCs/>';
        }
        if ($code) {
            $rpr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:color w:val="7C3AED"/>';
        }
        $lines = preg_split('/\r\n|\r|\n/', $part) ?: [''];
        $first = true;
        foreach ($lines as $line) {
            if (!$first) {
                $xml .= '<w:r><w:rPr>' . ($extraRpr !== '' ? $extraRpr : '') . '</w:rPr><w:br/></w:r>';
            }
            $first = false;
            $xml .= '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">' . dx($line) . '</w:t></w:r>';
        }
    }
    return $xml;
}

function d_p(string $text, array $opt = []): string
{
    $style = (string)($opt['style'] ?? '');
    $ppr = '';
    if ($style !== '') {
        $ppr .= '<w:pStyle w:val="' . dx($style) . '"/>';
    }
    if (!empty($opt['shade'])) {
        $ppr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $opt['shade'] . '"/>';
    }
    if (isset($opt['spaceAfter'])) {
        $ppr .= '<w:spacing w:after="' . (int)$opt['spaceAfter'] . '"/>';
    }
    if (!empty($opt['bullet']) || !empty($opt['number'])) {
        $ppr .= '<w:ind w:left="720" w:hanging="360"/>';
    }
    if (!empty($opt['center'])) {
        $ppr .= '<w:jc w:val="center"/>';
    }
    $prefix = '';
    if (!empty($opt['bullet'])) {
        $prefix = '• ';
    } elseif (!empty($opt['number'])) {
        $prefix = $opt['number'] . '. ';
    }
    return '<w:p><w:pPr>' . $ppr . '</w:pPr>' . d_runs($prefix . $text) . '</w:p>';
}

function d_h(string $text, int $level = 1): string
{
    $style = 'Heading' . max(1, min(3, $level));
    return '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr>'
        . '<w:r><w:t xml:space="preserve">' . dx($text) . '</w:t></w:r></w:p>';
}

function d_code(string $text): string
{
    $xml = '';
    foreach (preg_split('/\r\n|\r|\n/', trim($text, "\r\n")) ?: [''] as $line) {
        $xml .= '<w:p><w:pPr><w:pStyle w:val="Code"/></w:pPr><w:r>'
            . '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="17"/></w:rPr>'
            . '<w:t xml:space="preserve">' . dx($line === '' ? ' ' : $line) . '</w:t></w:r></w:p>';
    }
    return $xml . '<w:p><w:pPr><w:spacing w:after="60"/></w:pPr></w:p>';
}

function d_table(array $headers, array $rows, array $widthsPct): string
{
    $widthsPct = array_values($widthsPct);
    $grid = '';
    foreach ($widthsPct as $w) {
        $grid .= '<w:gridCol w:w="' . (int)round(9000 * $w / 100) . '"/>';
    }
    $border = '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="D0D7E2"/>'
        . '<w:left w:val="single" w:sz="4" w:color="D0D7E2"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="D0D7E2"/>'
        . '<w:right w:val="single" w:sz="4" w:color="D0D7E2"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="E6E9EF"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="E6E9EF"/>'
        . '</w:tblBorders>';
    $xml = '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/>' . $border
        . '<w:tblCellMar><w:top w:w="60" w:type="dxa"/><w:left w:w="90" w:type="dxa"/>'
        . '<w:bottom w:w="60" w:type="dxa"/><w:right w:w="90" w:type="dxa"/></w:tblCellMar>'
        . '<w:tblLook w:val="04A0"/></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';
    $rowXml = static function (array $cells, bool $head) use ($widthsPct): string {
        $tr = '<w:tr><w:trPr>' . ($head ? '<w:tblHeader/>' : '') . '<w:cantSplit/></w:trPr>';
        foreach ($cells as $i => $cell) {
            $w = $widthsPct[$i] ?? $widthsPct[0];
            $shade = $head ? '<w:shd w:val="clear" w:color="auto" w:fill="EDE7FF"/>' : '';
            $p = '<w:p><w:pPr><w:pStyle w:val="TableText"/>' . ($head ? '<w:jc w:val="left"/>' : '') . '</w:pPr>'
                . d_runs((string)$cell, $head ? '<w:b/><w:color w:val="4C2A9E"/>' : '') . '</w:p>';
            $tr .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)round(5000 * $w / 100) . '" w:type="pct"/>' . $shade
                . '<w:vAlign w:val="center"/></w:tcPr>' . $p . '</w:tc>';
        }
        return $tr . '</w:tr>';
    };
    $xml .= $rowXml($headers, true);
    foreach ($rows as $row) {
        $xml .= $rowXml(array_values((array)$row), false);
    }
    return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="80"/></w:pPr></w:p>';
}

function d_break(): string
{
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

// ============================================================
// Содержимое
// ============================================================

$body = '';

$body .= '<w:p><w:pPr><w:pStyle w:val="Title"/><w:jc w:val="center"/></w:pPr>'
    . '<w:r><w:t>Интеграция WMS TiMLog с ERP и другими системами</w:t></w:r></w:p>';
$body .= '<w:p><w:pPr><w:pStyle w:val="Subtitle"/><w:jc w:val="center"/></w:pPr>'
    . '<w:r><w:t>REST API v1 · JSON · HTTPS · OAuth 2.0 (JWT) · вебхуки HMAC-SHA256</w:t></w:r></w:p>';
$body .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="120" w:after="360"/></w:pPr>'
    . d_runs('Версия 1.0 · 2026-11-06 · WMS TiMLog') . '</w:p>';

$body .= d_p('Документ описывает двусторонний обмен данными между WMS TiMLog и внешней системой (ERP, 1С, TMS, маркетплейс): приём заказов и номенклатуры, передачу статусов отгрузки, выгрузку остатков. Приведены настройки со стороны WMS и со стороны внешней системы, примеры запросов, коды ошибок, тестирование и диагностика.');
$body .= d_p('Спецификация API: api/v1/openapi.yaml (OpenAPI 3.1). Настройки и журналы: модуль 13 «Настройки» → «Интеграция и логирование». Схема обмена: файл ИНТЕГРАЦИЯ-API-ERP.drawio (3 страницы).');

$body .= d_h('1. Сценарии и схема обмена', 1);
$body .= d_p('Обмен идёт по HTTPS. WMS принимает запросы на https://<домен>/api/v1 и отправляет вебхуки на callback-URL внешней системы.');
$body .= d_table(
    ['№', 'Сценарий', 'Направление', 'Триггер', 'Механизм'],
    [
        ['1', 'Создание/обновление заказа', 'ERP → WMS', 'событие в ERP', 'POST /orders (external_id)'],
        ['2', 'Статус заказа: принят / сборка / отгружен', 'WMS → ERP', 'смена статуса', 'вебхук order.status (HMAC)'],
        ['3', 'Остатки по ячейкам', 'WMS → ERP', 'каждые 5 мин + событие', 'GET /stock (page/page_size)'],
        ['4', 'Номенклатура: SKU, штрихкоды', 'ERP → WMS', 'событие в ERP', 'POST /nomenclature (sku)'],
    ],
    [6, 30, 16, 20, 28]
);
$body .= d_p('Порядок вызовов: токен → заказы/номенклатура (ERP → WMS) → остатки (WMS → ERP) → вебхуки статусов (WMS → ERP). Повторная отправка с тем же external_id (для SKU — sku) не создаёт дубликат и возвращает результат первой обработки.');

$body .= d_break();

$body .= d_h('2. Подготовка', 1);
$body .= d_h('2.1. Со стороны WMS', 2);
$body .= d_p('Адрес API: https://<ваш-wms-домен>/api/v1 (в dev — http://127.0.0.1:8001/api/v1).', ['bullet' => 1]);
$body .= d_p('HTTPS с TLS 1.2+ на прокси/реверс-прокси (nginx, IIS, Cloudflare, Tailscale Funnel).', ['bullet' => 1]);
$body .= d_p('Пользователь WMS с доступом к модулю 13 «Настройки».', ['bullet' => 1]);
$body .= d_h('2.2. Со стороны внешней системы', 2);
$body .= d_p('Исходящий доступ к WMS по HTTPS и входящий доступ к callback-URL для вебхуков.', ['bullet' => 1]);
$body .= d_p('Хранилище секретов: Vault, Key Vault, secrets manager, переменные окружения.', ['bullet' => 1]);
$body .= d_p('Планировщик выгрузки остатков (каждые 5 минут).', ['bullet' => 1]);

$body .= d_h('3. Настройка на стороне WMS', 1);
$body .= d_p('Откройте 13 Настройки → «Интеграция и логирование» → вкладка «Настройки». Заполните:', ['number' => 1]);
$body .= d_p('«Интеграция включена» — Да.', ['number' => 2]);
$body .= d_p('client_id — идентификатор клиента, например erp-client.', ['number' => 3]);
$body .= d_p('client_secret — пароль клиента. Поле маскировано: чтобы задать или сменить, введите значение и нажмите «Сохранить» либо «Сменить client_secret». Новое значение показывается один раз — сохраните его в secrets manager.', ['number' => 4]);
$body .= d_p('JWT-секрет — подпись токенов; генерируется автоматически, менять не обязательно.', ['number' => 5]);
$body .= d_p('Callback URL для вебхуков — публичный HTTPS-адрес приёмника в ERP, например https://erp.example.com/integration/wms/webhook.', ['number' => 6]);
$body .= d_p('Секрет подписи вебхуков — то же значение передайте в ERP для проверки HMAC.', ['number' => 7]);
$body .= d_p('Rate limit — запросов/сек на токен (по умолчанию 100). TTL токена — 60–3600 секунд.', ['number' => 8]);
$body .= d_p('Нажмите «Сохранить».', ['number' => 9]);
$body .= d_p('Проверка: введите client_secret в блоке «Самопроверка» и нажмите «Проверить токен и здоровье интеграции». В ответе будет превью access_token, expires_in, sub и метрики здоровья.', ['number' => 10]);
$body .= d_p('Вкладка «Журнал API» — все входящие и исходящие запросы с X-Request-Id (хранятся 30 дней); секреты и персональные данные маскируются. Вкладка «Вебхуки (outbox)» — статусы PENDING / RETRY / SENT / DEAD, кнопки «Повторить» и «Отправить созревшие сейчас».', ['number' => 11]);
$body .= d_p('Алерты показываются при доле 5xx > 5% за последний час (при ≥ 20 запросах), наличии DEAD-вебхуков и росте очереди (PENDING/RETRY > 20).', ['shade' => 'FFF8E1']);
$body .= d_p('Секреты хранятся в таблице wms_api_settings. Не публикуйте дамп БД. В prod выдавайте отдельные client_id/client_secret каждой системе-интегратору и ротируйте их по регламенту.');

$body .= d_break();

$body .= d_h('4. Настройка на стороне внешней системы', 1);
$body .= d_h('4.1. Получение токена', 2);
$body .= d_p('POST /api/v1/oauth/token, Content-Type: application/json:');
$body .= d_code('{
  "grant_type": "client_credentials",
  "client_id": "erp-client",
  "client_secret": "ваш-секрет"
}');
$body .= d_p('Ответ:');
$body .= d_code('{ "access_token": "eyJ...", "token_type": "Bearer", "expires_in": 3600 }');
$body .= d_p('Кешируйте токен и обновляйте за 1–2 минуты до истечения. Обновление: повторный client_credentials или grant_type=refresh_token с refresh_token=<текущий access_token>. Токен передаётся в заголовке Authorization: Bearer <token>. После смены JWT-секрета все токены становятся недействительными — запросите новый.');

$body .= d_h('4.2. Передача заказов (ERP → WMS)', 2);
$body .= d_p('POST /api/v1/orders');
$body .= d_code('{
  "external_id": "ORDER-2026-000123",
  "number": "СП-123",
  "consignee": "ООО «Получатель»",
  "address": "Москва, ул. Ленина, 10",
  "phone": "+7 999 000-00-00",
  "warehouse_id": 1,
  "comment": "Доставка до 18:00",
  "items": [
    { "sku": "MK-ASM-1315", "qty": 10 },
    { "sku": "MK-ASM-1320", "qty": 4, "unit": "шт" }
  ]
}');
$body .= d_p('external_id — обязателен и уникален в ERP; ключ идемпотентности. Повтор возвращает 200 и результат первой обработки (created=false), первая обработка — 201 (created=true). number необязателен: если не задан или длиннее 20 символов, WMS формирует API-<hash>. Все sku должны существовать в номенклатуре WMS, иначе 400 VALIDATION_ERROR с details[]; qty > 0.');
$body .= d_p('Ответ:');
$body .= d_code('{
  "external_id": "ORDER-2026-000123",
  "order_id": 42,
  "doc_no": "СП-123",
  "status": "created",
  "created": true
}');
$body .= d_p('Статус заказа: GET /api/v1/orders/{external_id}.');
$body .= d_h('4.3. Передача номенклатуры (ERP → WMS)', 2);
$body .= d_p('POST /api/v1/nomenclature — upsert по sku:');
$body .= d_code('{
  "sku": "MK-ASM-1315",
  "name": "Коробка средняя",
  "unit": "шт",
  "code": "T-101",
  "barcodes": ["4601234567890", "MK-ASM-1315"]
}');
$body .= d_p('Ответ: 201 { "created": true } — новый SKU; 200 { "created": false } — обновлён; 409 — конфликт по code (код занят другим товаром).');

$body .= d_h('4.4. Приём вебхуков (WMS → ERP)', 2);
$body .= d_p('WMS отправляет POST на настроенный Callback URL:');
$body .= d_code('POST https://erp.example.com/integration/wms/webhook
Content-Type: application/json
X-Wms-Signature: sha256=<hex HMAC-SHA256 от сырого тела>
X-Wms-Event: order.status
X-Request-Id: <уникальный id>');
$body .= d_p('Тело события:');
$body .= d_code('{
  "event": "order.status",
  "external_id": "ORDER-2026-000123",
  "sent_at": "2026-11-05T19:38:01+00:00",
  "data": {
    "external_id": "ORDER-2026-000123",
    "shipment_id": 18,
    "shipment_doc_no": "S-205110",
    "wms_status": "assembling",
    "status": "assembling"
  }
}');
$body .= d_p('Требования к приёмнику:', ['bullet' => 0]);
$body .= d_p('Проверить HMAC: sha256= + hash_hmac("sha256", rawBody, webhook_secret); сравнивать за постоянное время (hash_equals / hmac.compare_digest).', ['bullet' => 1]);
$body .= d_p('Обработать событие идемпотентно (ключ: event + external_id + sent_at).', ['bullet' => 1]);
$body .= d_p('Ответить 2xx быстро (до 10 секунд) — тогда WMS помечает событие SENT.', ['bullet' => 1]);
$body .= d_p('При ошибке, таймауте или не-2xx WMS повторяет через 1, 5, 30 минут, затем DEAD. Данные не теряются: событие остаётся в outbox и повторяется вручную в модуле 13.', ['bullet' => 1]);

$body .= d_h('4.5. Выгрузка остатков (WMS → ERP)', 2);
$body .= d_p('GET /api/v1/stock?page=1&page_size=100 (максимум 100):');
$body .= d_code('{
  "items": [
    {
      "sku": "MK-ASM-1315", "product_code": "T-101", "name": "Коробка средняя",
      "unit": "шт", "owner": "ООО Клиент", "warehouse_id": 1,
      "cell": "B-02-03", "pallet": "19876543234", "pallet_type": "euro",
      "qty": 250, "quality": "годен", "receipt_no": "A-105101",
      "prod_date": "2026-10-01"
    }
  ],
  "page": 1, "page_size": 100, "total": 12, "pages": 1
}');
$body .= d_p('Опрос — каждые 5 минут; при событии (закрытие приёмки/отгрузки) можно запрашивать вне графика. Останавливайтесь, когда page >= pages.');
$body .= d_h('4.6. Здоровье и очередь', 2);
$body .= d_p('GET /api/v1/health — requests_1h, errors_1h, bad_share, webhooks_pending, webhooks_dead, alerts[].');
$body .= d_p('GET /api/v1/webhooks — последние 100 событий outbox.');

$body .= d_break();

$body .= d_h('5. Примеры запросов', 1);
$body .= d_h('5.1. curl', 2);
$body .= d_code('TOKEN=$(curl -s -X POST https://wms.example.com/api/v1/oauth/token \\
  -H "Content-Type: application/json" \\
  -d \'{"grant_type":"client_credentials","client_id":"erp-client","client_secret":"SECRET"}\' \\
  | python -c "import sys,json;print(json.load(sys.stdin)[\'access_token\'])")

curl -s -X POST https://wms.example.com/api/v1/orders \\
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \\
  -H "X-Request-Id: erp-order-123" -d @order.json');
$body .= d_h('5.2. PHP', 2);
$body .= d_code('$ctx = stream_context_create([\'http\' => [
    \'method\' => \'POST\',
    \'header\' => "Content-Type: application/json\\r\\nAuthorization: Bearer {$token}\\r\\n",
    \'content\' => json_encode($order, JSON_UNESCAPED_UNICODE),
    \'ignore_errors\' => true, \'timeout\' => 15,
]]);
$res = file_get_contents(\'https://wms.example.com/api/v1/orders\', false, $ctx);
$code = (int)substr($http_response_header[0], 9, 3);');
$body .= d_h('5.3. 1С (HTTP-запрос)', 2);
$body .= d_code('Заголовки = Новый Соответствие;
Заголовки.Вставить("Content-Type", "application/json");
Заголовки.Вставить("Authorization", "Bearer " + Токен);

HTTP = Новый HTTPСоединение("wms.example.com", 443, , , 15, Новый ЗащищенноеСоединениеOpenSSL());
Запрос = Новый HTTPЗапрос("/api/v1/orders", Заголовки);
Запрос.УстановитьТелоИзСтроки(СтрокаJSON, КодировкаТекста.UTF8);
Ответ = HTTP.ОтправитьДляОбработки(Запрос);
КодОтвета = Ответ.КодСостояния;
ТелоОтвета = Ответ.ПолучитьТелоКакСтроку(КодировкаТекста.UTF8);');
$body .= d_h('5.4. C# (.NET)', 2);
$body .= d_code('using var http = new HttpClient();
http.DefaultRequestHeaders.Authorization = new("Bearer", token);
var resp = await http.PostAsJsonAsync("https://wms.example.com/api/v1/orders", order);
var body = await resp.Content.ReadAsStringAsync(); // 200/201/400...');
$body .= d_h('5.5. Проверка подписи вебхука (Python)', 2);
$body .= d_code('import hmac, hashlib
expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
assert hmac.compare_digest(expected, headers["X-Wms-Signature"])');

$body .= d_h('6. Ошибки и коды ответов', 1);
$body .= d_p('Единый формат ошибки:');
$body .= d_code('{ "code": "VALIDATION_ERROR", "message": "Ошибка валидации заказа",
  "details": [{ "line": 2, "field": "sku", "message": "SKU не найден" }] }');
$body .= d_table(
    ['HTTP', 'code', 'Что делать'],
    [
        ['400', 'VALIDATION_ERROR', 'исправить данные по details[], отправить повторно'],
        ['401', 'UNAUTHORIZED / INVALID_CLIENT / INVALID_TOKEN', 'запросить новый токен, проверить секрет'],
        ['404', 'NOT_FOUND', 'проверить external_id / путь'],
        ['409', 'CONFLICT', 'конфликт данных (занятый код товара)'],
        ['429', 'RATE_LIMITED', 'подождать Retry-After секунд и повторить'],
        ['500', 'INTERNAL_ERROR', 'повторить с тем же external_id (идемпотентно), сообщить в поддержку'],
        ['503', 'SERVICE_DISABLED', 'интеграция выключена в настройках WMS'],
    ],
    [8, 42, 50]
);
$body .= d_p('Политика повторов ERP: экспоненциальная задержка (1, 2, 4, 8… сек) для 429/500/503, максимум 5 попыток. Для остальных 4xx повторы бессмысленны.');

$body .= d_h('7. Безопасность', 1);
$body .= d_p('Только HTTPS/TLS 1.2+; HTTP в prod не использовать.', ['bullet' => 1]);
$body .= d_p('Секреты — в secrets manager, не в коде и не в git; ротация по регламенту.', ['bullet' => 1]);
$body .= d_p('WMS логирует запросы с X-Request-Id 30 дней; поля client_secret, token, password, consignee, phone, address, email, fio маскируются.', ['bullet' => 1]);
$body .= d_p('Вебхуки подписаны HMAC-SHA256; приёмник обязан проверять подпись.', ['bullet' => 1]);
$body .= d_p('Ограничивайте callback-URL списком разрешённых адресов на стороне ERP.', ['bullet' => 1]);

$body .= d_break();

$body .= d_h('8. Тестирование и приёмка', 1);
$body .= d_p('Контракт: api/v1/openapi.yaml; расхождение схемы — баг.', ['number' => 1]);
$body .= d_p('Автотесты (dev-сервер запущен): python api/v1/tests/smoke.py --rate. Проверяются токены, идемпотентность, валидация, пагинация, HMAC, ретраи, 429 + Retry-After, health. Отчёт: api/v1/tests/report.md (25/25). Тест сам сохраняет и восстанавливает настройки, удаляет тестовые данные.', ['number' => 2]);
$body .= d_p('Чек-лист приёмки:', ['number' => 3]);
$body .= d_p('ERP получает токен, токен живёт ≤ 60 минут.', ['bullet' => 1]);
$body .= d_p('Заказ создаётся (201), повтор с тем же external_id — 200, дубля нет.', ['bullet' => 1]);
$body .= d_p('Несуществующий SKU → 400 с details.', ['bullet' => 1]);
$body .= d_p('Статус заказа меняется в WMS → ERP получает вебхук с валидной подписью.', ['bullet' => 1]);
$body .= d_p('ERP недоступен → событие в RETRY, после восстановления → SENT.', ['bullet' => 1]);
$body .= d_p('GET /stock отдаёт остатки с пагинацией.', ['bullet' => 1]);
$body .= d_p('Сквозной сценарий: заказ создан → собран → отгружен → остаток обновился.', ['bullet' => 1]);
$body .= d_p('Нагрузка: 100 запросов/сек × 5 минут, ошибок ≤ 0,5% (журнал API и health).', ['number' => 4]);

$body .= d_h('9. Диагностика', 1);
$body .= d_table(
    ['Симптом', 'Причина', 'Решение'],
    [
        ['401 на всех запросах', 'истёк токен / сменили JWT-секрет', 'получить новый токен'],
        ['401 INVALID_CLIENT', 'неверные client_id/secret', 'сверить в модуле 13, при необходимости сменить секрет'],
        ['429', 'превышен лимит', 'ждать Retry-After, увеличить rate_limit'],
        ['Вебхуки не приходят', 'не задан callback URL / ERP не отвечает 2xx', 'вкладка «Вебхуки», проверить URL и firewall'],
        ['События DEAD', '4 неудачные попытки (1/5/30 мин)', 'исправить приёмник, «Повторить» в outbox'],
        ['400 по SKU', 'товар не передан / не создан', 'сначала POST /nomenclature'],
        ['Пустые остатки', 'нет product_stock с qty > 0', 'проверить закрытие приёмки'],
    ],
    [22, 34, 44]
);
$body .= d_p('Журнал: 13 → Интеграция и логирование → Журнал API (фильтры по направлению, статусу, поиск по X-Request-Id). Ошибки интеграции также попадают в «Журнал ошибок» (колокольчик «🐞 Ошибки» у роли «Разработчик»).');

$body .= d_h('10. Таблицы и файлы', 1);
$body .= d_table(
    ['Объект', 'Назначение'],
    [
        ['wms_api_settings', 'настройки интеграции (одна строка, id=1)'],
        ['wms_api_log', 'журнал запросов/ответов (30 дней)'],
        ['wms_api_rate', 'счётчики rate limit'],
        ['wms_webhook_outbox', 'очередь исходящих вебхуков'],
        ['client_orders.external_id', 'ключ идемпотентности заказов (уникальный индекс)'],
        ['shared/IntegrationApi.php', 'движок: токены, лимиты, логи, вебхуки, health'],
        ['api/v1/index.php', 'HTTP-роутер /api/v1/*'],
        ['api/v1/openapi.yaml', 'контракт API (OpenAPI 3.1)'],
        ['api/v1/tests/*', 'smoke-тесты, отчёт, CLI-хелпер окружения'],
    ],
    [34, 66]
);

$body .= d_h('Приложение. Маппинг статусов', 1);
$body .= d_table(
    ['WMS', 'ERP (status)', 'Смысл'],
    [
        ['draft, created', 'accepted', 'заказ принят'],
        ['assembling, assembled, packing, packed, on_check, checked, ready', 'assembling', 'в сборке'],
        ['shipped, delivering, delivered', 'shipped', 'отгружен'],
        ['cancelled, archived', 'cancelled', 'отменён'],
    ],
    [42, 18, 40]
);

$body .= d_p('Документ сгенерирован автоматически: документация/build_integration_docs.php. Схема обмена: документация/ИНТЕГРАЦИЯ-API-ERP.drawio.', ['shade' => 'F3F4F6']);

// ============================================================
// OOXML
// ============================================================

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<w:body>' . $body
    . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
    . '<w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'
    . '</w:sectPr></w:body></w:document>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:docDefaults><w:rPrDefault><w:rPr>'
    . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
    . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:color w:val="1C2331"/><w:lang w:val="ru-RU"/>'
    . '</w:rPr></w:rPrDefault><w:pPrDefault><w:pPr>'
    . '<w:spacing w:after="120" w:line="276" w:lineRule="auto"/>'
    . '</w:pPr></w:pPrDefault></w:docDefaults>'
    . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
    . '<w:pPr><w:spacing w:before="0" w:after="120"/></w:pPr>'
    . '<w:rPr><w:rFonts w:ascii="Calibri Light" w:hAnsi="Calibri Light"/><w:b/><w:sz w:val="56"/><w:szCs w:val="56"/><w:color w:val="4C2A9E"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
    . '<w:pPr><w:spacing w:before="0" w:after="240"/></w:pPr>'
    . '<w:rPr><w:sz w:val="26"/><w:szCs w:val="26"/><w:color w:val="6B7280"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
    . '<w:pPr><w:keepNext/><w:spacing w:before="360" w:after="160"/><w:outlineLvl w:val="0"/></w:pPr>'
    . '<w:rPr><w:b/><w:sz w:val="34"/><w:szCs w:val="34"/><w:color w:val="6432FF"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
    . '<w:pPr><w:keepNext/><w:spacing w:before="260" w:after="120"/><w:outlineLvl w:val="1"/></w:pPr>'
    . '<w:rPr><w:b/><w:sz w:val="27"/><w:szCs w:val="27"/><w:color w:val="4C2A9E"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
    . '<w:pPr><w:keepNext/><w:spacing w:before="200" w:after="100"/><w:outlineLvl w:val="2"/></w:pPr>'
    . '<w:rPr><w:b/><w:sz w:val="24"/><w:szCs w:val="24"/><w:color w:val="1C2331"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="Code"><w:name w:val="Code"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/>'
    . '<w:pPr><w:keepLines/><w:shd w:val="clear" w:color="auto" w:fill="F3F4F6"/>'
    . '<w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/>'
    . '<w:ind w:left="120" w:right="120"/></w:pPr>'
    . '<w:rPr><w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="17"/><w:szCs w:val="17"/><w:color w:val="1F2937"/></w:rPr></w:style>'
    . '<w:style w:type="paragraph" w:styleId="TableText"><w:name w:val="Table Text"/><w:basedOn w:val="Normal"/>'
    . '<w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
    . '<w:rPr><w:sz w:val="19"/><w:szCs w:val="19"/></w:rPr></w:style>'
    . '</w:styles>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
    . '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
    . '</Relationships>';

$docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '</Relationships>';

$now = gmdate('Y-m-d\TH:i:s\Z');
$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
    . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
    . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<dc:title>Интеграция WMS TiMLog с ERP и другими системами</dc:title>'
    . '<dc:creator>WMS TiMLog</dc:creator><cp:lastModifiedBy>WMS TiMLog</cp:lastModifiedBy>'
    . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
    . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
    . '</cp:coreProperties>';

$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
    . '<Application>WMS TiMLog</Application><AppVersion>1.0</AppVersion></Properties>';

$bin = wms_zip_store([
    '[Content_Types].xml' => $contentTypes,
    '_rels/.rels' => $rels,
    'docProps/core.xml' => $core,
    'docProps/app.xml' => $app,
    'word/_rels/document.xml.rels' => $docRels,
    'word/document.xml' => $document,
    'word/styles.xml' => $styles,
]);

$out = __DIR__ . '/ИНТЕГРАЦИЯ-API-ERP.docx';
file_put_contents($out, $bin);
echo 'written: ' . $out . ' (' . strlen($bin) . " bytes)\n";
