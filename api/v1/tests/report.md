# Отчёт о прохождении тестов интеграции (API v1)

- Дата: 2026-11-05 22:38:40
- Окружение: dev (http://127.0.0.1:8001), БД: WMS
- Набор: `api/v1/tests/smoke.py` (stdlib Python)
- Результат: **25/25** (успешно)

| # | Проверка | Результат |
|---|----------|-----------|
| 1 | token: выдача access_token | ✅ pass |
| 2 | token: TTL ≤ 60 минут | ✅ pass |
| 3 | token: неверный секрет → 401 | ✅ pass |
| 4 | auth: без токена → 401 | ✅ pass |
| 5 | orders: создание → 201 | ✅ pass |
| 6 | orders: X-Request-Id в ответе | ✅ pass |
| 7 | orders: повтор → 200, без дубля | ✅ pass |
| 8 | orders: несуществующий SKU → 400 + details | ✅ pass |
| 9 | orders: пустой items → 400 | ✅ pass |
| 10 | orders: битый JSON → 400 | ✅ pass |
| 11 | orders: статус по external_id → 200 | ✅ pass |
| 12 | orders: неизвестный external_id → 404 | ✅ pass |
| 13 | nomenclature: создание → 201 | ✅ pass |
| 14 | nomenclature: повтор → 200, без дубля | ✅ pass |
| 15 | nomenclature: пустые поля → 400 | ✅ pass |
| 16 | stock: страница ≤ 100 записей | ✅ pass |
| 17 | stock: page=2 работает | ✅ pass |
| 18 | webhook: order.created доставлен | ✅ pass |
| 19 | webhook: подпись HMAC-SHA256 верна | ✅ pass |
| 20 | webhook: X-Wms-Event = order.created | ✅ pass |
| 21 | webhook: заказ для проверки ретрая создан | ✅ pass |
| 22 | webhook: недоступность → RETRY, данные в очереди | ✅ pass |
| 23 | webhook: повтор после восстановления → SENT | ✅ pass |
| 24 | rate limit: 429 + Retry-After | ✅ pass |
| 25 | health: метрики и алерты | ✅ pass |

## Покрытие по ТЗ

- Аутентификация OAuth 2.0 client_credentials, JWT TTL ≤ 60 мин — проверено.
- Идемпотентность заказов по external_id и номенклатуры по SKU — проверено.
- Валидация (несуществующий SKU, пустой items, битый JSON) и единый формат ошибок — проверено.
- Пагинация stock (page/page_size, лимит 100) — проверено.
- Вебхуки: HMAC-SHA256, X-Wms-Event, outbox и повтор при недоступности — проверено.
- Rate limit 429 + Retry-After — проверено.
- Health/алерты (доля 5xx, очередь вебхуков) — проверено.

> Логи smoke-прогона: журнал «Настройки → Интеграция и логирование → Журнал API»
> (хранится 30 дней). Отчёт генерируется автоматически при запуске тестов.
