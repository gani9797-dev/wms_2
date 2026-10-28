-- Модуль 14 «Журнал» (WMS TiMLog), MySQL 8 / MariaDB
-- Таблицы: wms_journal (журнал склада) и wms_serial_journal (журнал серийных номеров).
-- Журнал склада пишется на каждое перемещение паллеты/товара:
--   приход, отбор, перемещение, размещение, пополнение, отгрузка, инвентаризация.
-- Журнал серийных номеров фиксирует ТОЛЬКО факт сканирования:
--   «Приход»  — серийные номера приняты (код документа приёмки);
--   «Отгрузка» — серийные номера отгружены (код отгрузки).
-- Паллета в журнале с/н записывается та, на которую номера приняты/отгружены ИЗНАЧАЛЬНО:
-- при расформировании паллеты журнал не переписывается.
-- Каноничная схема PostgreSQL — db/schema-pg.sql (те же таблицы в схеме public).

CREATE TABLE IF NOT EXISTS wms_journal (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время операции',
  op_type VARCHAR(30) NOT NULL COMMENT 'приход|отбор|перемещение|размещение|пополнение|отгрузка|инвентаризация|корректировка',
  doc_type VARCHAR(30) NULL COMMENT 'Тип документа: Поступление|Задание|Перемещение|Отгрузка',
  doc_no VARCHAR(100) NULL COMMENT 'Номер документа',
  sku VARCHAR(50) NULL COMMENT 'Артикул',
  product_name VARCHAR(255) NULL COMMENT 'Наименование товара',
  supplier_batch VARCHAR(100) NULL COMMENT 'Партия поставщика',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Количество',
  pallet VARCHAR(50) NULL COMMENT 'Номер паллеты',
  sscc VARCHAR(50) NULL COMMENT 'SSCC паллеты',
  cell_from VARCHAR(50) NULL COMMENT 'Ячейка-источник',
  cell_to VARCHAR(50) NULL COMMENT 'Ячейка-приёмник',
  zone VARCHAR(30) NULL COMMENT 'Зона склада',
  user_id BIGINT UNSIGNED NULL COMMENT 'Кто выполнил',
  user_fio VARCHAR(150) NULL COMMENT 'ФИО исполнителя',
  comment TEXT NULL COMMENT 'Комментарий к операции',
  quality VARCHAR(20) NULL DEFAULT 'новый' COMMENT 'Качество: новый / брак',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_journal_date (doc_date),
  KEY idx_journal_op (op_type),
  KEY idx_journal_sku (sku),
  KEY idx_journal_doc (doc_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wms_serial_journal (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  serial_no VARCHAR(100) NOT NULL COMMENT 'Серийный номер',
  sku VARCHAR(50) NULL COMMENT 'Артикул',
  product_name VARCHAR(255) NULL COMMENT 'Наименование товара',
  op_type VARCHAR(20) NOT NULL DEFAULT 'Приход' COMMENT 'Приход|Отгрузка',
  receipt_doc_no VARCHAR(100) NULL COMMENT 'Код документа приёмки',
  shipment_doc_no VARCHAR(100) NULL COMMENT 'Код отгрузки',
  pallet VARCHAR(50) NULL COMMENT 'Паллета, на которую приняты/отгружены ИЗНАЧАЛЬНО',
  qty DECIMAL(14,3) NOT NULL DEFAULT 1 COMMENT 'Количество',
  cell VARCHAR(50) NULL COMMENT 'Ячейка',
  doc_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата и время операции',
  user_id BIGINT UNSIGNED NULL COMMENT 'Кто выполнил',
  user_fio VARCHAR(150) NULL COMMENT 'ФИО исполнителя',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sj_serial (serial_no),
  KEY idx_sj_op (op_type),
  KEY idx_sj_date (doc_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
