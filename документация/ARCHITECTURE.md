# WMS TiMLog — архитектура, связи и результаты проверки

Дата проверки: 2026-10-28.  
Схемы: [`wms-overview.svg`](./wms-overview.svg) — обзор; [`wms-architecture.drawio`](./wms-architecture.drawio) — 7 страниц: обзор, приход, заказ/отгрузка, склад, БД прихода, БД отгрузки, этикетки.  
Отчёт smoke-теста: [`qa-report.json`](./qa-report.json).

## 1. Как работает система

1. Браузер обращается к `router.php`; router проверяет сессию, права роли и отдаёт `app.html` либо API.
2. Вход — по `employees.login` и `employees.password_hash` (`shared/Auth.php`). Сессия хранит `wms_employee_id`; при каждом запросе сотрудник и его роль перечитываются из БД.
3. Доступ к модулю — `role_access` (`full` / `view` / `none`). POST-запросы в модуль требуют `full`.
4. Отдельные действия — `role_permissions` (`doc_edit_receipt`, `doc_edit_order`), настраиваются в `13 → Персонал → Права`.
5. Все модули работают через `shared/db.php`. При `WMS_DB_DRIVER=pgsql` все `wms_*_pdo()` отдают одно persistent-подключение к PostgreSQL `wms`.
6. `shared/pg.php` кэширует готовность схемы и содержит общий PDO; `shared/table-tools.js` и `shared/auth-client.js` внедряются router-ом во все WMS-страницы.

### Вход и права

| Слой | Файлы / таблицы | Назначение |
|---|---|---|
| Вход | `/login`, `router.php`, `shared/Auth.php`, `employees` | логин/пароль сотрудника, сессия |
| Роли | `wms_roles`, `role_access` | доступ к модулям: full/view/none |
| Действия | `role_permissions` | отдельные права: редактирование приходов/заказов |
| Старый вход | `AUTH_USER`/`AUTH_HASH` в `router.php` | аварийный admin, пока у сотрудника с логином admin не задан пароль |
| Шапка | `shared/auth-client.js`, `shared/session.php` | ФИО, роль, логин, выход на каждой вкладке |

## 2. Карта модулей

| Модуль | Страница | Серверный API | Статус | Основные таблицы | Зависимости |
|---|---|---|---|---|---|
| 00 Дашборд | `00-dashboard/app.html` | — | UI/агрегаты | `dashboard_kpi_cache` | 02, 03, 08 |
| 01 Реестр ТС | `01-reestr-pribytiya-ts/app.html` | — | статический макет | — | — |
| 01.1 Справочник ТС | `01.1-spravochnik-ts/app.html` | — | статический макет | — | — |
| 01.2 Карта ворот | `01.2-karta-vorot/app.html` | — | статический макет | — | — |
| 02 Поступления | `02-postupleniya/app.html` | `02-postupleniya/demo_sqlite.php` | рабочий | `receipts`, `receipt_items`, `receipt_operations`, `receipt_history` | 08, 09, 10, 14, ТСД Приход |
| 02.1 Приём поступлений | `02.1-priem-postupleniy/app.html` | — | статический макет | — | 02 |
| 03 Отгрузки | `03-otgruzki/app.html` | `03-otgruzki/demo_sqlite.php` | рабочий | `shipments`, `shipment_items`, `shipment_orders`, `tsd_picks` | 03.1, 05, 08, ТСД Отбор |
| 03.1 Заказы | `03.1-zakazy/app.html` | `03.1-zakazy/demo_sqlite.php` | рабочий | `client_orders`, `client_order_items` | 03, 08 |
| 03.2 Упаковка заказов | `03.2-upakovka-zakazov/app.html` | — | статический макет | `packing_tasks` (пусто) | 03 |
| 03.3 Проверка заказов | `03.3-proverka-zakazov/app.html` | — | статический макет | `order_checks` (пусто) | 03 |
| 04 Внутрискладские операции | `04-vnutriskladskie-operatsii/app.html` | — | статический макет | `internal_ops` (пусто) | 05 |
| 05 Ячейки хранения | `05-yacheyki-hraneniya/app.html` | `05-yacheyki-hraneniya/demo_sqlite.php` | рабочий | `storage_cells`, `cell_content`, `cell_history`, `cell_movements` | 07, 08, ТСД |
| 05.1 Карта склада 3D | `05.1-karta-sklada-3d/app.html` | — | статический макет | `warehouse_map_objects` (пусто) | 05 |
| 06 Инвентаризация | `06-inventarizatsiya/app.html` | — | статический макет | `inventories` (пусто) | 05, 08 |
| 07 Задания | `07-zadaniya/app.html` | `07-zadaniya/demo_sqlite.php` | рабочий | `tasks`, `task_items`, `task_history` | 02, 03, 05, ТСД |
| 08 Номенклатура | `08-nomenklatura/app.html` | `08-nomenklatura/demo_sqlite.php` | рабочий | `products`, `product_stock`, `product_movements`, `notifications` | 02, 03, 05, 09 |
| 08.1 Товарный запас | `08.1-tovarny-zapas/app.html` | `08.1-tovarny-zapas/demo_sqlite.php` | рабочий | `product_stock` | 08 |
| 09 Контрагенты | `09-kontragenty-i-gruzopoluchateli/app.html` | `09-kontragenty-i-gruzopoluchateli/demo_sqlite.php` | рабочий | `counterparties`, `consignees` | 03, 08, 13 |
| 09.1 Биллинг | `09.1-billing/app.html` | — | статический макет | `billing_accounts` (пусто) | 09 |
| 10 Сотрудники | `10-sotrudniki/app.html` | `10-sotrudniki/demo_sqlite.php` | рабочий | `employees`, `employee_*`, `wms_roles` | авторизация всех модулей |
| 10.1 KPI | `10.1-nachisleniya-kpi/app.html` | — | статический макет | `kpi_*` (пусто) | 10 |
| 10.2 Просмотр сделки | `10.2-prosmotr-sdelki/app.html` | — | статический макет | `deals` (пусто) | 09 |
| 11 Отчёты | `11-otchety/app.html` | — | статический макет | `reports` (пусто) | 02, 03 |
| 12 Уведомления | `12-uvedomleniya/app.html` | 08 API (`notif_*`) | UI + API 08 | `notifications`, `notification_subscriptions` | 08 |
| 13 Настройки | `13-nastroyki/app.html` | `13-nastroyki/demo_sqlite.php` | рабочий | `wms_roles`, `role_access`, `role_permissions`, `settings` | 02, 03, 10, ТСД |
| 13.1 Авто-накладные | `13.1-avtoformirovanie-nakladnyh/app.html` | — | UI/local | `auto_invoice_*` (пусто) | 02, 03 |
| 13.2 Авто-счета | `13.2-avtoformirovanie-schetov/app.html` | — | статический макет | `auto_bill_*` (пусто) | 09 |
| 13.3 Печать линейки | `13.3-pechat-lineyki/app.html` | — | статический макет | `print_templates` (пусто) | 08 |
| 13.4 Настройка KPI | `13.4-nastroyka-kpi/app.html` | — | статический макет | `kpi_rules` (пусто) | 10 |
| 14 Журнал | `14-zhurnal/app.html` | `14-zhurnal/demo_sqlite.php` | рабочий | `wms_journal`, `wms_serial_journal` | все модули |
| 15 Этикетки | `15-etiketki/app.html` | `15-etiketki/demo_sqlite.php` | рабочий | `label_templates`, `label_sequences`, `label_print_log`, `label_codes` | 08.1, Auth, все модули |
| ТСД | `tsd/*.html`, `tsd/*.php` | `tsd/api.php`, `prihod.php`, `otbor.php`, `sort.php`, `ship.php`, `tasks.php`, `info.php` | рабочий | те же `receipts`, `shipments`, `storage_cells`, `tasks` | 02, 03, 05, 07, 14 |

> `с/app.html` — посторонняя копия 3.1 в каталоге `с`; в меню не используется.

## 3. Основные потоки данных

### Приёмка
`in/` (CSV/TXT/XLSX) → `ImportRobot` / `CsvUpload` → `receipts` + `receipt_items`  
02 UI или `tsd/prihod.php` → `receipt_operations` + `receipt_items.qty_fact`  
Закрытие → `StockSync` → `products` / `product_stock` / `product_movements` / `wms_journal`.

### Отгрузка
03.1 UI → `client_orders` + `client_order_items`  
03 UI → `ShipmentService::formFromOrders()` → `shipments` + `shipment_orders` + `shipment_items`  
ТСД `otbor/sort/ship` → `tsd_picks`, `shipment_history`, `shipment_sort_log`  
Факт отбора виден в 03 (`readiness`, `fact_qty`), журнал — в 14.

### Этикетки
15 UI → `label_templates` / `label_sequences` → `label_codes` + `label_print_log`. ZPL 58×40 мм, максимум 500 шт. за печать. 08.1 → ПКМ «Печатать SSCC» → `print_sscc` (существующий SSCC строки запаса).

### Склад и задания
05 UI → `storage_cells`, `cell_content`, `cell_history`, `cell_movements`  
07 UI / ТСД → `tasks`, `task_items`, `task_history`  
Задания ссылаются на документы через `tasks.doc_ref` / `shipment_ids` (без FK), на SKU — через `task_items.sku` (без FK).

## 4. Связи таблиц

### Жёсткие FK (основные)
- `receipts (1) → receipt_items / receipt_operations / receipt_documents / receipt_flags / receipt_marks / receipt_history (N)`
- `client_orders (1) → client_order_items (N)`
- `shipments (1) → shipment_items / shipment_orders / shipment_history / shipment_flags / shipment_documents / shipment_marks / shipment_sort_log / tsd_picks (N)`
- `shipment_orders` — связь N:N между `shipments` и `client_orders`
- `employees (1) → employee_access / employee_schedules / employee_history / employee_exits / employee_attendance (N)`
- `storage_cells (1) → cell_content / cell_history / cell_movements / storage_cell_zones / storage_cell_extra_zones (N)`
- `products (1) → product_barcodes / product_stock / product_movements / product_history (N)`
- `tasks (1) → task_items / task_history (N)`

### Логические связи без FK
- `receipt_items.qty_fact` и `receipt_operations` — факт приёмки; `qty_fact` не может быть меньше обработанного при редактировании.
- `shipment_items.order_id` → `client_orders.id` (поле есть, FK нет).
- `tsd_picks` → фактический отбор по `shipment_id + sku`; используется как «обработано» в 03.1.
- `product_stock.receipt_id` → `receipts.id`; `cell_content.product_code` → `products.code`; `cell_content.sku` → `products.sku`.
- `tasks.doc_ref` → `shipments.doc_no` / `receipts.doc_no`; `tasks.shipment_ids` — текстовый список ID.
- `task_items.sku` → `products.sku`; `cell_from/cell_to` → `storage_cells.code`.
- `wms_journal.doc_no` / `wms_serial_journal.*_doc_no` → документы; `user_id` → пользователи.
- `notifications.user_id` / `notification_subscriptions.user_id` → пользователь (FK нет).

### Важные архитектурные несоответствия
1. **Две модели контрагентов.** `receipts`, `client_orders`, `shipments` используют `contractors`; `products` и `storage_zones` — `counterparties`. Модуль 09 редактирует `counterparties`. Автосинхронизации нет; возможны расхождения.
2. **Два справочника людей.** Авторизация и карточки — `employees`; многие модули/истории используют `users`. FK между ними нет; ФИО/роли могут расходиться.
3. **Задания и документы** связаны текстовыми `doc_ref`/`shipment_ids`, а не FK; при удалении документа остаются «висячие» задания.

## 5. Все таблицы и количество строк

| Группа | Таблица | Строк | FK |
|---|---|---:|---:|
| Пользователи и права | `employees` | 16 | 0 |
| Пользователи и права | `users` | 16 | 0 |
| Пользователи и права | `wms_roles` | 5 | 0 |
| Пользователи и права | `role_access` | 51 | 0 |
| Пользователи и права | `role_permissions` | 2 | 0 |
| Пользователи и права | `employee_access` | 158 | 1 |
| Пользователи и права | `employee_schedules` | 8 | 1 |
| Пользователи и права | `employee_history` | 10 | 1 |
| Пользователи и права | `employee_exits` | 8 | 1 |
| Пользователи и права | `employee_attendance` | 6 | 1 |
| Приёмка | `receipts` | 13 | 0 |
| Приёмка | `receipt_items` | 48 | 1 |
| Приёмка | `receipt_operations` | 34 | 1 |
| Приёмка | `receipt_documents` | 6 | 1 |
| Приёмка | `receipt_flags` | 39 | 1 |
| Приёмка | `receipt_marks` | 0 | 1 |
| Приёмка | `receipt_history` | 140 | 1 |
| Приёмка | `receipt_stock_sync` | 7 | 0 |
| Приёмка | `receipt_acceptance` | 0 | 1 |
| Приёмка | `receipt_acceptance_items` | 0 | 1 |
| Приёмка | `tsd_act_rasx` | 9 | 0 |
| Приёмка | `tsd_pallets` | 25 | 1 |
| Заказы и отгрузки | `client_orders` | 11 | 0 |
| Заказы и отгрузки | `client_order_items` | 5 | 1 |
| Заказы и отгрузки | `shipments` | 10 | 0 |
| Заказы и отгрузки | `shipment_orders` | 11 | 2 |
| Заказы и отгрузки | `shipment_items` | 17 | 1 |
| Заказы и отгрузки | `shipment_history` | 11 | 1 |
| Заказы и отгрузки | `shipment_flags` | 9 | 1 |
| Заказы и отгрузки | `shipment_documents` | 4 | 1 |
| Заказы и отгрузки | `shipment_marks` | 0 | 1 |
| Заказы и отгрузки | `shipment_sort_log` | 0 | 1 |
| Заказы и отгрузки | `tsd_picks` | 4 | 1 |
| Заказы и отгрузки | `tsd_pick_buffers` | 0 | 0 |
| Заказы и отгрузки | `tsd_crossdock` | 0 | 0 |
| Заказы и отгрузки | `order_checks` | 0 | 1 |
| Заказы и отгрузки | `order_check_items` | 0 | 1 |
| Заказы и отгрузки | `packing_tasks` | 0 | 2 |
| Заказы и отгрузки | `packing_task_items` | 0 | 1 |
| Склад и номенклатура | `warehouses` | 1 | 0 |
| Склад и номенклатура | `storage_zones` | 6 | 1 |
| Склад и номенклатура | `storage_cell_zones` | 6 | 1 |
| Склад и номенклатура | `storage_cell_extra_zones` | 3 | 1 |
| Склад и номенклатура | `storage_cells` | 57 | 0 |
| Склад и номенклатура | `cell_content` | 2 | 1 |
| Склад и номенклатура | `cell_history` | 123 | 1 |
| Склад и номенклатура | `cell_movements` | 6 | 1 |
| Склад и номенклатура | `cell_types` | 3 | 0 |
| Склад и номенклатура | `pallet_types` | 2 | 0 |
| Склад и номенклатура | `lpn_categories` | 0 | 0 |
| Склад и номенклатура | `storage_rules` | 0 | 0 |
| Склад и номенклатура | `warehouse_schedule` | 0 | 1 |
| Склад и номенклатура | `warehouse_zones` | 0 | 1 |
| Склад и номенклатура | `warehouse_map_objects` | 0 | 1 |
| Склад и номенклатура | `products` | 29 | 1 |
| Склад и номенклатура | `product_barcodes` | 44 | 1 |
| Склад и номенклатура | `product_stock` | 12 | 1 |
| Склад и номенклатура | `product_movements` | 30 | 1 |
| Склад и номенклатура | `product_history` | 41 | 1 |
| Склад и номенклатура | `product_defaults` | 6 | 0 |
| Склад и номенклатура | `wms_stock` | 0 | 0 |
| Задания и ТСД | `tasks` | 13 | 0 |
| Задания и ТСД | `task_items` | 8 | 1 |
| Задания и ТСД | `task_history` | 18 | 1 |
| Задания и ТСД | `task_lists` | 0 | 0 |
| Задания и ТСД | `task_list_items` | 0 | 1 |
| Задания и ТСД | `wms_tasks` | 0 | 0 |
| Задания и ТСД | `wms_task_items` | 0 | 1 |
| Задания и ТСД | `wms_task_history` | 0 | 1 |
| Задания и ТСД | `tsd_task_locks` | 1 | 0 |
| Задания и ТСД | `tsd_task_logs` | 0 | 0 |
| Задания и ТСД | `tsd_otbor_locks` | 3 | 0 |
| Задания и ТСД | `tsd_otbor_logs` | 0 | 0 |
| Журнал, уведомления, настройки | `wms_journal` | 27 | 0 |
| Журнал, уведомления, настройки | `wms_serial_journal` | 4 | 0 |
| Журнал, уведомления, настройки | `wms_import_log` | 3 | 0 |
| Журнал, уведомления, настройки | `notifications` | 11 | 0 |
| Журнал, уведомления, настройки | `notification_subscriptions` | 4 | 0 |
| Журнал, уведомления, настройки | `user_columns` | 3 | 0 |
| Журнал, уведомления, настройки | `settings` | 2 | 0 |
| Журнал, уведомления, настройки | `settings_history` | 0 | 0 |
| Журнал, уведомления, настройки | `settings_gates` | 0 | 0 |
| Журнал, уведомления, настройки | `settings_gate_docks` | 0 | 1 |
| Журнал, уведомления, настройки | `organization` | 1 | 0 |
| Контрагенты и справочники | `counterparties` | 5 | 0 |
| Контрагенты и справочники | `contractors` | 12 | 0 |
| Контрагенты и справочники | `consignees` | 4 | 1 |
| Контрагенты и справочники | `deals` | 0 | 1 |
| Контрагенты и справочники | `billing_accounts` | 0 | 1 |
| Контрагенты и справочники | `billing_transactions` | 0 | 1 |
| KPI, отчёты, автоматизация | `kpi_accruals` | 0 | 1 |
| KPI, отчёты, автоматизация | `kpi_indicators` | 0 | 0 |
| KPI, отчёты, автоматизация | `kpi_rules` | 0 | 1 |
| KPI, отчёты, автоматизация | `reports` | 0 | 0 |
| KPI, отчёты, автоматизация | `report_runs` | 0 | 1 |
| KPI, отчёты, автоматизация | `auto_bill_rules` | 0 | 0 |
| KPI, отчёты, автоматизация | `auto_bill_runs` | 0 | 1 |
| KPI, отчёты, автоматизация | `auto_invoice_rules` | 0 | 0 |
| KPI, отчёты, автоматизация | `auto_invoice_runs` | 0 | 1 |
| KPI, отчёты, автоматизация | `auto_invoice_history` | 0 | 1 |
| KPI, отчёты, автоматизация | `print_templates` | 0 | 0 |
| KPI, отчёты, автоматизация | `print_jobs` | 0 | 1 |
| KPI, отчёты, автоматизация | `dashboard_kpi_cache` | 0 | 0 |
| Этикетки | `label_templates` | 4 | 0 |
| Этикетки | `label_sequences` | 0 | 0 |
| Этикетки | `label_print_log` | 0 | 0 |
| Этикетки | `label_codes` | 0 | 0 |
| Прочее / пустые | `gate_slots` | 0 | 2 |
| Прочее / пустые | `gates` | 0 | 1 |
| Прочее / пустые | `internal_op_items` | 0 | 1 |
| Прочее / пустые | `internal_ops` | 0 | 1 |
| Прочее / пустые | `inventories` | 0 | 1 |
| Прочее / пустые | `inventory_items` | 0 | 1 |
| Прочее / пустые | `vehicle_arrival_history` | 0 | 1 |
| Прочее / пустые | `vehicle_arrivals` | 0 | 1 |
| Прочее / пустые | `vehicles` | 0 | 0 |

## 6. Результаты проверки

### PHP и HTTP smoke
- PHP-lint: **0 ошибок** по всем PHP-файлам.
- Smoke-тест: **136 проверок, 0 ошибок**.
- Проверены: 35 HTML-страниц WMS/ТСД, `session.php`, `table-tools.js`, `auth-client.js`, чтение всех demo API, TSD read API, создание/редактирование/удаление тестовых прихода, заказа, сотрудника и роли.
- Тестовые записи удалены; временный QA-пользователь удалён.

### Целостность БД
Проверено 40 связей (FK и логические). Выполнена очистка:

1. **`tsd_otbor_locks`** — удалены 3 устаревшие блокировки (`shipment_id` 2, 3, 7). Сейчас orphan-блокировок нет.
2. **`receipt_items`** — синхронизированы 3 строки: `qty` увеличено до `qty_fact`, пересчитаны суммы и шапки приходов:
   - `A-105106`, строка 3, SKU `G`: qty 1;
   - `A-105105`, строка 1, `MK-ASM-1315`: qty 23;
   - `A-105108`, строка 6, `MK-ASM-1320`: qty 70.
3. **`tasks`** — по решению пользователя удалены 7 устаревших задач «сбор» `TASK-202610-02463…02469` и их `task_items` / `task_history`.

Резервная копия удалённых/изменённых строк: `документация/cleanup-backup-2026-10-28.json`.  
Остальные проверки — 0 нарушений: нет orphan-строк по FK, нет дублей `receipts.doc_no`, `shipments.doc_no`, `client_orders.doc_no`, `products.sku`, `products.code`, `storage_cells.code`, нет дублей логинов, нет отрицательных остатков в `cell_content` / `product_stock`, нет переотбора в `shipment_items` относительно `tsd_picks`.

### Статические модули
`01`, `01.1`, `01.2`, `02.1`, `03.2`, `03.3`, `04`, `05.1`, `06`, `09.1`, `10.1`, `10.2`, `11`, `13.2`, `13.3`, `13.4` — страницы-заглушки (≈6 КБ, без серверного API).  
`13.1` — UI без серверного API; `12` использует API модуля 08; `00` — дашборд-агрегатор.  
`с/app.html` — посторонняя копия 3.1, не входит в меню.

## 7. Как открыть схему
- `документация/wms-overview.svg` — обзорная схема, открывается в браузере; SVG можно импортировать в Visio.
- `документация/wms-architecture.drawio` — открыть в draw.io / diagrams.net / VS Code Draw.io Integration.
- Страница 1 — обзор модулей и потоков.
- Страница 2 — приход.
- Страница 3 — заказы и отгрузка.
- Страница 4 — склад, задания и справочники.
- Страница 5 — БД прихода.
- Страница 6 — БД заказов и отгрузки.
- Страница 7 — этикетки: шаблоны, последовательности, коды и лог.

## 8. Публикация и доступ

- Репозиторий: <https://github.com/gani9797-dev/wms_2> (публичный, ветка `master`).
- Публичный портал: <https://desktop-ldl27p8.tail2c1fbe.ts.net> → `http://127.0.0.1:8001`.
- Вход: `/login`, проверка по `employees.login` и `employees.password_hash`. Без сессии доступны только страница входа и `shared/session.php`; корень перенаправляет на `/login`.
- Публикация идёт через Tailscale Funnel. Локальный PHP-сервер должен быть запущен, а ПК — не уходить в сон; иначе ссылка перестанет отвечать.
- GitHub Pages не используется: приложение на PHP работает на локальном сервере.
- SQLite-базы и `cleanup-backup-*.json` исключены из репозитория через `.gitignore`; данные PostgreSQL остаются на сервере и в GitHub не публикуются.
