# Интеграция WMS с ERP и другими системами (API v1)

Инструкция для внедрения двустороннего обмена между WMS TiMLog и внешней системой
(ERP, 1С, TMS, маркетплейс, WMS-агрегатор). Рассмотрены обе стороны: настройка WMS
и настройка внешней системы.

- Спецификация: [`api/v1/openapi.yaml`](../api/v1/openapi.yaml) (OpenAPI 3.1).
- Тесты: [`api/v1/tests/smoke.py`](../api/v1/tests/smoke.py), отчёт — [`api/v1/tests/report.md`](../api/v1/tests/report.md).
- Настройки и журналы: **Модуль 13 «Настройки» → «Интеграция и логирование»**.

## 1. Что синхронизируется

| № | Сценарий | Направление | Триггер | Механизм |
|---|----------|-------------|---------|----------|
| 1 | Создание/обновление заказа | ERP → WMS | событие в ERP | `POST /api/v1/orders` |
| 2 | Статус заказа (принят / сборка / отгружен) | WMS → ERP | смена статуса | вебхук `order.status` |
| 3 | Остатки по ячейкам | WMS → ERP | каждые 5 минут + событие | `GET /api/v1/stock` |
| 4 | Номенклатура (SKU, штрихкоды) | ERP → WMS | событие в ERP | `POST /api/v1/nomenclature` |

```
ERP / 1С / TMS                 WMS TiMLog
     │                              │
     │  POST /oauth/token           │
     │ ───────────────────────────► │  JWT (TTL ≤ 60 мин)
     │                              │
     │  POST /orders (external_id)  │
     │ ───────────────────────────► │  client_orders + client_order_items
     │ ◄─────────────────────────── │  201 создан / 200 повтор (без дубля)
     │                              │
     │  POST /nomenclature          │
     │ ───────────────────────────► │  products + product_barcodes (upsert по SKU)
     │                              │
     │ ◄────────── вебхук ───────── │  order.status + HMAC-SHA256
     │   (200 OK, иначе outbox      │  повторы 1 / 5 / 30 мин → DEAD
     │    повторит 1/5/30 минут)    │
     │                              │
     │  GET /stock?page=&page_size= │
     │ ───────────────────────────► │  product_stock (≤ 100 строк/страница)
```

## 2. Подготовка

### 2.1. Что нужно от WMS
- Адрес API: `https://<ваш-wms-домен>/api/v1` (в dev — `http://127.0.0.1:8001/api/v1`).
- HTTPS с TLS 1.2+ на прокси/реверс-прокси (nginx, IIS, Cloudflare, Tailscale Funnel).
- Роль пользователя WMS с доступом к модулю 13 (настройка интеграции).

### 2.2. Что нужно от внешней системы
- Исходящий доступ к WMS по HTTPS и **входящий** доступ к callback-URL (для вебхуков).
- Хранилище секретов (Vault, Key Vault, secrets manager, переменные окружения).
- Планировщик для выгрузки остатков (каждые 5 минут).

## 3. Настройка на стороне WMS

1. Войдите в WMS под администратором → **13 Настройки → Интеграция и логирование**.
2. Вкладка **«Настройки»**:
   - **Интеграция включена** — `Да`.
   - **client_id** — идентификатор клиента, например `erp-client`.
   - **client_secret** — пароль клиента. Поле маскировано; чтобы задать/сменить —
     введите значение и нажмите «Сохранить» или «Сменить client_secret»
     (новое значение показывается один раз — сохраните его в secrets manager).
   - **JWT-секрет** — подпись токенов; генерируется автоматически, менять не обязательно.
   - **Callback URL для вебхуков** — публичный HTTPS-адрес приёмника в ERP,
     например `https://erp.example.com/integration/wms/webhook`.
   - **Секрет подписи вебхуков** — передайте то же значение в ERP для проверки HMAC.
   - **Rate limit** — запросов/сек на токен (по умолчанию 100).
   - **TTL токена** — 60–3600 секунд (по умолчанию 3600).
3. Нажмите **«Сохранить»**.
4. Проверка: введите `client_secret` в блоке **«Самопроверка»** и нажмите
   **«Проверить токен и здоровье интеграции»**. В ответе будет `access_token`
   (превью), `expires_in`, `sub` и метрики здоровья.
5. Вкладка **«Журнал API»** — все входящие/исходящие запросы с `X-Request-Id`
   (хранятся 30 дней). Секреты и персональные данные маскируются.
6. Вкладка **«Вебхуки (outbox)»** — очередь исходящих событий, статусы
   `PENDING / RETRY / SENT / DEAD`, кнопки «Повторить» и «Отправить созревшие сейчас».
7. Баннеры алертов появляются при:
   - доле 5xx > 5% за последний час (при ≥ 20 запросах);
   - наличии недоставленных вебхуков `DEAD`;
   - росте очереди (`PENDING/RETRY` > 20).

> Секреты хранятся в таблице `wms_api_settings`. Не публикуйте дамп БД.
> Для prod рекомендуется выдавать отдельные `client_id/client_secret` на каждую
> систему-интегратора и ротировать их по регламенту.

## 4. Настройка на стороне внешней системы

### 4.1. Получение токена

`POST /api/v1/oauth/token`, `Content-Type: application/json`:

```json
{
  "grant_type": "client_credentials",
  "client_id": "erp-client",
  "client_secret": "ваш-секрет"
}
```

Ответ:

```json
{ "access_token": "eyJ...", "token_type": "Bearer", "expires_in": 3600 }
```

- Кешируйте токен и обновляйте за 1–2 минуты до истечения.
- Обновление: повторный запрос `client_credentials` или
  `grant_type=refresh_token` с `refresh_token=<текущий access_token>`.
- Токен передаётся в заголовке `Authorization: Bearer <token>`.

> Токен привязан к `jwt_secret` WMS. После смены JWT-секрета все токены становятся
> недействительными — запросите новый.

### 4.2. Передача заказов (ERP → WMS)

`POST /api/v1/orders`

```json
{
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
}
```

- `external_id` — обязателен и уникален в ERP. Это ключ идемпотентности:
  повторная отправка возвращает `200` и результат первой обработки (`created=false`).
  Первая обработка — `201 created=true`.
- `number` — необязателен; если не задан или длиннее 20 символов, WMS формирует
  `API-<hash>`.
- Все `sku` должны существовать в номенклатуре WMS, иначе `400 VALIDATION_ERROR`
  с `details[]` (номер строки и причина).
- `qty` > 0.

Ответ:

```json
{
  "external_id": "ORDER-2026-000123",
  "order_id": 42,
  "doc_no": "СП-123",
  "status": "created",
  "created": true
}
```

Статус заказа: `GET /api/v1/orders/{external_id}`.

### 4.3. Передача номенклатуры (ERP → WMS)

`POST /api/v1/nomenclature` — upsert по `sku`:

```json
{
  "sku": "MK-ASM-1315",
  "name": "Коробка средняя",
  "unit": "шт",
  "code": "T-101",
  "barcodes": ["4601234567890", "MK-ASM-1315"]
}
```

Ответ: `201 { "created": true }` — новый SKU, `200 { "created": false }` — обновлён.
`409` — конфликт по `code` (код занят другим товаром).

### 4.4. Приём вебхуков (WMS → ERP)

WMS отправляет `POST` на настроенный **Callback URL**:

```
POST https://erp.example.com/integration/wms/webhook
Content-Type: application/json
X-Wms-Signature: sha256=<hex HMAC-SHA256 от сырого тела>
X-Wms-Event: order.status
X-Request-Id: <уникальный id>
```

Тело:

```json
{
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
}
```

Требования к приёмнику:
1. Проверить HMAC: `sha256=` + `hash_hmac('sha256', rawBody, webhook_secret)`.
   Сравнивать за постоянное время (`hash_equals`, `hmac.compare_digest`).
2. Обработать событие идемпотентно (ключ: `event + external_id + sent_at`).
3. Ответить `2xx` быстро (до 10 секунд). Тогда WMS помечает событие `SENT`.
4. При ошибке/таймауте/не-2xx WMS повторяет через **1, 5, 30 минут**, затем `DEAD`.
   Данные не теряются: событие остаётся в outbox, его можно повторить вручную
   в модуле 13.

Маппинг статусов:

| WMS | ERP (`status`) | Смысл |
|---|---|---|
| draft, created | `accepted` | заказ принят |
| assembling, assembled, packing, packed, on_check, checked, ready | `assembling` | в сборке |
| shipped, delivering, delivered | `shipped` | отгружен |
| cancelled, archived | `cancelled` | отменён |

### 4.5. Выгрузка остатков (WMS → ERP)

`GET /api/v1/stock?page=1&page_size=100` (максимум 100):

```json
{
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
}
```

Опрос — каждые 5 минут; при событии (закрытие приёмки/отгрузки) можно запрашивать
вне графика. Останавливайтесь, когда `page >= pages`.

### 4.6. Здоровье и очередь

- `GET /api/v1/health` — `requests_1h`, `errors_1h`, `bad_share`,
  `webhooks_pending`, `webhooks_dead`, `alerts[]`.
- `GET /api/v1/webhooks` — последние 100 событий outbox.

## 5. Примеры запросов

### curl

```bash
TOKEN=$(curl -s -X POST https://wms.example.com/api/v1/oauth/token \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"client_credentials","client_id":"erp-client","client_secret":"SECRET"}' \
  | python -c "import sys,json;print(json.load(sys.stdin)['access_token'])")

curl -s -X POST https://wms.example.com/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "X-Request-Id: erp-order-123" \
  -d @order.json
```

### PHP

```php
$ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}\r\n",
    'content' => json_encode($order, JSON_UNESCAPED_UNICODE),
    'ignore_errors' => true, 'timeout' => 15,
]]);
$res = file_get_contents('https://wms.example.com/api/v1/orders', false, $ctx);
$code = (int)substr($http_response_header[0], 9, 3);
```

### 1С (HTTP-запрос)

```bsl
Заголовки = Новый Соответствие;
Заголовки.Вставить("Content-Type", "application/json");
Заголовки.Вставить("Authorization", "Bearer " + Токен);

HTTP = Новый HTTPСоединение("wms.example.com", 443, , , 15, Новый ЗащищенноеСоединениеOpenSSL());
Запрос = Новый HTTPЗапрос("/api/v1/orders", Заголовки);
Запрос.УстановитьТелоИзСтроки(СтрокаJSON, КодировкаТекста.UTF8);
Ответ = HTTP.ОтправитьДляОбработки(Запрос);
КодОтвета = Ответ.КодСостояния;
ТелоОтвета = Ответ.ПолучитьТелоКакСтроку(КодировкаТекста.UTF8);
```

### C# (.NET)

```csharp
using var http = new HttpClient();
http.DefaultRequestHeaders.Authorization = new("Bearer", token);
var resp = await http.PostAsJsonAsync("https://wms.example.com/api/v1/orders", order);
var body = await resp.Content.ReadAsStringAsync(); // 200/201/400...
```

### Проверка подписи вебхука (Python)

```python
import hmac, hashlib
expected = "sha256=" + hmac.new(secret.encode(), raw_body, hashlib.sha256).hexdigest()
assert hmac.compare_digest(expected, headers["X-Wms-Signature"])
```

## 6. Ошибки и коды ответов

Единый формат:

```json
{ "code": "VALIDATION_ERROR", "message": "Ошибка валидации заказа",
  "details": [{ "line": 2, "field": "sku", "message": "SKU не найден" }] }
```

| HTTP | code | Что делать |
|---|---|---|
| 400 | `VALIDATION_ERROR` | исправить данные по `details[]`, отправить повторно |
| 401 | `UNAUTHORIZED`, `INVALID_CLIENT`, `INVALID_TOKEN` | запросить новый токен / проверить секрет |
| 404 | `NOT_FOUND` | проверить `external_id`/путь |
| 409 | `CONFLICT` | конфликт данных (занятый код товара) |
| 429 | `RATE_LIMITED` | подождать `Retry-After` секунд и повторить |
| 500 | `INTERNAL_ERROR` | повторить с тем же `external_id` (идемпотентно), сообщить в поддержку |
| 503 | `SERVICE_DISABLED` | интеграция выключена в настройках WMS |

Рекомендуемая политика повторов ERP: экспоненциальная задержка (1, 2, 4, 8… сек)
для 429/500/503, максимум 5 попыток. Для 4xx (кроме 429) повторы бессмысленны.

## 7. Безопасность

- Только HTTPS/TLS 1.2+; HTTP в prod не использовать.
- Секреты — в secrets manager, не в коде и не в git; ротация по регламенту.
- WMS логирует запросы с `X-Request-Id` 30 дней; поля `client_secret`, `token`,
  `password`, `consignee`, `phone`, `address`, `email`, `fio` маскируются.
- Вебхуки подписаны HMAC-SHA256; приёмник обязан проверять подпись.
- Ограничивайте callback-URL списком разрешённых адресов на стороне ERP.

## 8. Тестирование и приёмка

1. **Контракт**: спецификация `api/v1/openapi.yaml`; расхождение схемы = баг.
2. **Автотесты** (dev-сервер уже запущен):

   ```bash
   cd <корень WMS>
   python api/v1/tests/smoke.py --rate
   ```

   Проверяются: токены, идемпотентность, валидация, пагинация, HMAC, ретраи,
   429 + Retry-After, health. Отчёт — `api/v1/tests/report.md` (25/25).
   Тест сам сохраняет и восстанавливает настройки, удаляет тестовые данные.

3. **Ручной чек-лист приёмки**:
   - [ ] ERP получает токен, токен живёт ≤ 60 минут.
   - [ ] Заказ создаётся (`201`), повтор с тем же `external_id` — `200`, дубля нет.
   - [ ] Несуществующий SKU → `400` с `details`.
   - [ ] Статус заказа меняется в WMS → ERP получает вебхук с валидной подписью.
   - [ ] ERP недоступен → событие в `RETRY`, после восстановления → `SENT`.
   - [ ] `GET /stock` отдаёт остатки с пагинацией.
   - [ ] Сквозной сценарий: заказ создан → собран → отгружен → остаток обновился.

4. **Нагрузка**: 100 запросов/сек × 5 минут, ошибок ≤ 0,5% (проверять по журналу API
   и health).

## 9. Диагностика

| Симптом | Причина | Решение |
|---|---|---|
| `401` на всех запросах | истёк токен / сменили JWT-секрет | получить новый токен |
| `401 INVALID_CLIENT` | неверные `client_id/secret` | сверить в модуле 13, при необходимости сменить секрет |
| `429` | превышен лимит | ждать `Retry-After`, увеличить `rate_limit` |
| Вебхуки не приходят | не задан callback URL / ERP не отвечает 2xx | вкладка «Вебхуки», проверить URL и firewall |
| События `DEAD` | 4 неудачные попытки (1/5/30 мин) | исправить приёмник, «Повторить» в outbox |
| `400` по SKU | товар не передан/не создан | сначала `POST /nomenclature` |
| Пустые остатки | нет `product_stock` с `qty>0` | проверить закрытие приёмки |

Журнал: **13 → Интеграция и логирование → Журнал API** (фильтры по направлению,
статусу, поиск по `X-Request-Id`). Ошибки интеграции также попадают в «Журнал ошибок»
(колокольчик «🐞 Ошибки» у роли «Разработчик»).

## 10. Таблицы и файлы

| Объект | Назначение |
|---|---|
| `wms_api_settings` | настройки интеграции (одна строка, id=1) |
| `wms_api_log` | журнал запросов/ответов (30 дней) |
| `wms_api_rate` | счётчики rate limit |
| `wms_webhook_outbox` | очередь исходящих вебхуков |
| `client_orders.external_id` | ключ идемпотентности заказов (уникальный индекс) |
| `shared/IntegrationApi.php` | движок: токены, лимиты, логи, вебхуки, health |
| `api/v1/index.php` | HTTP-роутер `/api/v1/*` |
| `api/v1/openapi.yaml` | контракт API |
| `api/v1/tests/*` | smoke-тесты, отчёт, CLI-хелпер окружения |
