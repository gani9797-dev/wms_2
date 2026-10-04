-- Модуль 8.1 "Товарный запас" (WMS TiMLog), MySQL 8 / MariaDB
-- Реестр остатков по ячейкам и паллетам: склад / ячейка / паллета / артикул /
-- наименование / количество / приход. Своих таблиц у модуля нет — читает
-- product_stock (+products, warehouses) из БД номенклатуры.

CREATE TABLE IF NOT EXISTS product_stock (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  cell VARCHAR(50) NOT NULL COMMENT 'Ячейка: A-01-01 / B-02-03',
  pallet VARCHAR(50) NULL COMMENT 'Паллета',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  receipt_id BIGINT UNSIGNED NULL COMMENT 'Приход: id документа поступления',
  receipt_no VARCHAR(50) NULL COMMENT 'Приход: номер документа (для фильтра)',
  CONSTRAINT fk_ps_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  KEY idx_ps_prod (product_id),
  KEY idx_ps_wh (warehouse_id),
  KEY idx_ps_cell (cell)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
