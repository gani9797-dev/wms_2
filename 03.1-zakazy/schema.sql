-- Модуль 3.1 "Заказы" (WMS TiMLog), MySQL 8 / MariaDB
-- Реестр заказов/нарядов на отгрузку. Сюда попадают наряды or_XXXXXX,
-- загруженные из CSV расхода (дашборд → «Загрузить расход», один файл = один наряд).
-- Из выбранных заказов мастером формируется отгрузка (см. 03-otgruzki).
-- Своих таблиц у модуля нет: заказы живут в БД отгрузок (client_orders +
-- client_order_items), связь с отгрузками — через shipment_orders.

CREATE TABLE IF NOT EXISTS client_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'TML317889621 / or_123456 (наряд из расхода)',
  contractor_id BIGINT UNSIGNED NULL,
  consignee VARCHAR(255) NULL COMMENT 'Грузополучатель',
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'created' COMMENT 'Статус заказа',
  warehouse_id BIGINT UNSIGNED NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_no (doc_no),
  KEY idx_order_contractor (contractor_id),
  KEY idx_order_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Строки наряда: загружаются из CSV расхода (см. shared/CsvUpload.php).
-- При формировании отгрузки копируются в shipment_items.
CREATE TABLE IF NOT EXISTS client_order_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL COMMENT 'Наряд client_orders.id',
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул',
  name VARCHAR(255) NOT NULL COMMENT 'Наименование',
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty DECIMAL(12,3) NOT NULL COMMENT 'Количество',
  prod_date DATE NULL COMMENT 'Дата производства',
  CONSTRAINT fk_coi_order FOREIGN KEY (order_id)
    REFERENCES client_orders (id) ON DELETE CASCADE,
  KEY idx_coi_order (order_id),
  KEY idx_coi_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
