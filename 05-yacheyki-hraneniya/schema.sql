-- Модуль 5 "Ячейки хранения" (WMS TiMLog), MySQL 8 / MariaDB
-- По схеме "5 - TL Модуль Ячейки хранения (версия от 26.08.2026)":
-- реестр (Адрес/Зона/Тип/Категория/Статус/Заполненность/В ячейке/Резерв/Остаток/Паллет/Темп.режим/Блокировка)
-- + фильтры (зона, тип, статус, темп. режим, заполненность, разрешено, блокировка, есть товар, паллет, остаток)
-- + карточка: Основное / Параметры / Ограничения / Содержимое / История / Движения
-- + ПКМ (открыть/редактировать/копировать/печать/задания/переместить/блокировать/архивировать)
-- + массовое изменение + импорт/экспорт

CREATE TABLE IF NOT EXISTS warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fio VARCHAR(150) NOT NULL COMMENT 'Иванов И.И.',
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS storage_cells (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL COMMENT 'Адрес: A3-100-06-1',
  warehouse_id BIGINT UNSIGNED NOT NULL COMMENT 'Склад: Основной склад',
  zone VARCHAR(30) NOT NULL DEFAULT 'storage' COMMENT 'storage=Хранение, shipment=Отгрузка, receiving=Приёмка',
  cell_type VARCHAR(30) NOT NULL DEFAULT 'pallet' COMMENT 'pallet=Паллетная, box=Коробочная',
  category VARCHAR(100) NULL COMMENT 'КГТ / Спорт инвентарь / Косметика / Бытовая химия',
  status VARCHAR(30) NOT NULL DEFAULT 'free' COMMENT 'free=Свободна, busy=Занята, reserved=Зарезервирована, blocked=Заблокирована',
   capacity DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Вместимость, шт (0 = безразмерная, без лимита; база заполненности)',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'В ячейке, шт',
  reserved_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Резерв, шт',
  pallet_count INT NOT NULL DEFAULT 0 COMMENT 'Паллет, шт',
  building VARCHAR(20) NULL COMMENT 'Корпус / Секция: A3',
  row_no VARCHAR(20) NULL COMMENT 'Ряд: 10',
  level_no VARCHAR(20) NULL COMMENT 'Уровень: 06',
  place_no VARCHAR(20) NULL COMMENT 'Ячейка: 1',
  coord_x INT NULL COMMENT 'Координата X: 10',
  coord_y INT NULL COMMENT 'Координата Y: 25',
  temp_from DECIMAL(5,1) NULL COMMENT 'Темп. режим от: +2',
  temp_to DECIMAL(5,1) NULL COMMENT 'Темп. режим до: +6',
  is_locked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Блокировка: 1=Да',
  lock_reason VARCHAR(255) NULL COMMENT 'Причина блокировки',
  allowed_goods VARCHAR(150) NOT NULL DEFAULT 'Все' COMMENT 'Разрешено: Все / категория',
  max_weight_kg DECIMAL(12,3) NULL COMMENT 'Параметры: макс вес, кг',
  max_pallets INT NULL COMMENT 'Параметры: макс паллет',
  hazmat_allowed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Параметры: опасный груз разрешен',
  comment TEXT NULL COMMENT 'Комментарий: Провести инвент',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cell (code, warehouse_id),
  KEY idx_cell_wh (warehouse_id),
  KEY idx_cell_zone (zone),
  KEY idx_cell_type (cell_type),
  KEY idx_cell_status (status),
  KEY idx_cell_cat (category),
  KEY idx_cell_locked (is_locked),
  KEY idx_cell_arch (is_archived)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Содержимое ячейки (вкладка "Содержимое"): снапшот позиций Номенклатуры.
CREATE TABLE IF NOT EXISTS cell_content (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cell_id BIGINT UNSIGNED NOT NULL,
  product_code VARCHAR(50) NULL COMMENT 'Код: T-101',
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул: MK-ASM-1315',
  name VARCHAR(255) NOT NULL COMMENT 'Наименование',
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'В ячейке, шт',
  reserved_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Резерв',
  barcode VARCHAR(100) NULL COMMENT 'Штрихкод',
  pallet VARCHAR(50) NULL COMMENT 'Паллета',
  tasks_count INT NOT NULL DEFAULT 0 COMMENT 'Активные задания',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cc_cell FOREIGN KEY (cell_id) REFERENCES storage_cells (id) ON DELETE CASCADE,
  KEY idx_cc_cell (cell_id),
  KEY idx_cc_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- История изменений ячейки (вкладка "История").
CREATE TABLE IF NOT EXISTS cell_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cell_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_fio VARCHAR(150) NULL,
  action VARCHAR(50) NOT NULL COMMENT 'create,update,block,unblock,move,copy,archive,mass',
  field VARCHAR(50) NULL,
  field_label VARCHAR(100) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ch_cell FOREIGN KEY (cell_id) REFERENCES storage_cells (id) ON DELETE CASCADE,
  KEY idx_ch_cell (cell_id),
  KEY idx_ch_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Движения по ячейке (вкладка "Движения").
CREATE TABLE IF NOT EXISTS cell_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cell_id BIGINT UNSIGNED NOT NULL,
  doc_date DATETIME NOT NULL,
  doc_type VARCHAR(30) NOT NULL COMMENT 'place/move/shipment/receipt/inventory',
  doc_no VARCHAR(100) NOT NULL COMMENT 'Размещение №РЗ-12',
  sku VARCHAR(50) NULL,
  qty_change DECIMAL(14,3) NOT NULL COMMENT '+120 / -45',
  balance DECIMAL(14,3) NOT NULL COMMENT 'Остаток после операции',
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL,
  CONSTRAINT fk_cm_cell FOREIGN KEY (cell_id) REFERENCES storage_cells (id) ON DELETE CASCADE,
  KEY idx_cm_cell (cell_id),
  KEY idx_cm_date (doc_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
