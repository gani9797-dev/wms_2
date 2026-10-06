-- Модуль 8.1 "Товарный запас" (WMS TiMLog), MySQL 8 / MariaDB
-- Реестр остатков по ячейкам и паллетам: склад / ячейка / паллета / артикул /
-- наименование / количество / приход. Своих таблиц у модуля нет — читает
-- product_stock (+products, warehouses) из БД номенклатуры.

CREATE TABLE IF NOT EXISTS product_stock (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  cell VARCHAR(50) NOT NULL COMMENT 'Ячейка: A-01-01 / B-02-03',
  pallet VARCHAR(50) NULL COMMENT 'Паллета: номер/SSCC (клик — смена тары)',
  pallet_type VARCHAR(10) NULL COMMENT 'Тара: euro (1200x800x144) / fin (1200x1000x138); fallback — products.pallet_type',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  receipt_id BIGINT UNSIGNED NULL COMMENT 'Приход: id документа поступления',
  receipt_no VARCHAR(50) NULL COMMENT 'Приход: номер документа (для фильтра)',
  CONSTRAINT fk_ps_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  KEY idx_ps_prod (product_id),
  KEY idx_ps_wh (warehouse_id),
  KEY idx_ps_cell (cell)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Витрина 8.1 считается на лету (demo_sqlite.php?action=list), линейные габариты — в метрах:
--   Тара из product_stock.pallet_type (fallback products.pallet_type, default euro).
--   Д/Ш — из справочника тары (euro 1.2x0.8, fin 1.2x1.0).
--   Выс. паллеты — высота товара (products.height_mm) + высота паллеты (0.144 euro / 0.138 fin), м.
--   Объем — Д×Ш×В, м3. Вес — qty × products.weight_g + тара 25 кг (если есть номер паллеты).
--   Отгрузка — номера отгрузок из резерва (задания на отбор), через запятую.
--   Резерв — сумма qty резерва по всем отгрузкам.
-- Смена тары POST ?action=pallet_update сверяет storage_cells.allowed_pallet_types:
--   несовпадение => 400 «Изменение паллеты невозможно, ячейка не предназначена для данного типа паллеты.»
--
-- ПРАВИЛО «одна паллета — одна ячейка»: один номер паллеты (product_stock.pallet)
-- не может находиться в разных ячейках. Несколько строк с одним номером в ОДНОЙ
-- ячейке — норма (разные SKU на одной паллете), поэтому UNIQUE-индекс не применим —
-- контроль программный: ?action=list возвращает conflicts [{pallet, cells}],
-- строка помечается pallet_conflict=1, а ?action=pallet_update для конфликтной
-- паллеты отвечает 400 «Паллета ... находится в нескольких ячейках ... сначала
-- устраните дубль» и блокирует любые изменения до устранения дубля.
