-- Модуль "Задания" (WMS TiMLog), MySQL 8 / MariaDB
-- Поток: поступления -> wms_stock (товарный запас) -> отгрузка выпускает задание
--   -> реестр "Задания" -> ТСД "Отбор" (только задания типа "отбор").
-- Типы (схема 7 - TL Модуль Задания + уточнение): отбор, размещение, перемещение, пополнение.
-- Статусы: new (Новый), work (В работе), paused (На паузе / в ожидании), done (Выполнен), cancelled (Отменено).

CREATE TABLE IF NOT EXISTS wms_stock (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул',
  code VARCHAR(50) NULL COMMENT 'Код/ШК',
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  warehouse_id BIGINT UNSIGNED NOT NULL,
  cell VARCHAR(50) NULL COMMENT 'Ячейка хранения',
  pallet VARCHAR(50) NULL COMMENT 'Паллета',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Остаток',
  reserved_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'В резерве под задания',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stock (sku, warehouse_id, cell, pallet),
  KEY idx_stock_sku (sku),
  KEY idx_stock_wh (warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wms_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'TASK-202506-02456',
  type VARCHAR(20) NOT NULL DEFAULT 'отбор' COMMENT 'отбор/размещение/перемещение/пополнение',
  status VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT 'new/work/paused/done/cancelled',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'lowest/low/normal/high/critical',
  warehouse_id BIGINT UNSIGNED NOT NULL,
  consignee VARCHAR(255) NULL COMMENT 'Клиент (для отбора)',
  gate VARCHAR(20) NULL COMMENT 'Ворота',
  doc_ref VARCHAR(50) NULL COMMENT 'Связанный документ: № отгрузки',
  order_ref VARCHAR(50) NULL COMMENT 'Связанный заказ',
  executor_id BIGINT UNSIGNED NULL,
  executor_fio VARCHAR(150) NULL,
  plan_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  fact_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  progress TINYINT NOT NULL DEFAULT 0 COMMENT 'Готовность, %',
  cells_count INT NOT NULL DEFAULT 0 COMMENT 'Ячеек',
  volume DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Объем, м3',
  weight DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Вес, кг',
  equipment VARCHAR(50) NULL COMMENT 'Тип техники',
  flags VARCHAR(255) NULL,
  need_check TINYINT(1) NOT NULL DEFAULT 0,
  comment TEXT NULL,
  due_date DATE NULL COMMENT 'Дата выполнения (план)',
  done_at DATETIME NULL,
  paused_at DATETIME NULL COMMENT 'Начало паузы/ожидания (автовозврат через 2 мин)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_task_no (doc_no),
  KEY idx_task_type (type),
  KEY idx_task_status (status),
  KEY idx_task_priority (priority),
  KEY idx_task_wh (warehouse_id),
  KEY idx_task_exec (executor_id),
  KEY idx_task_docref (doc_ref),
  KEY idx_task_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wms_task_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  cell_from VARCHAR(50) NULL COMMENT 'Откуда (ячейка отбора)',
  cell_to VARCHAR(50) NULL COMMENT 'Куда',
  pallet VARCHAR(50) NULL,
  plan_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  fact_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  state VARCHAR(30) NULL COMMENT 'Состояние строки',
  executor_fio VARCHAR(150) NULL,
  CONSTRAINT fk_ti_task FOREIGN KEY (task_id) REFERENCES wms_tasks (id) ON DELETE CASCADE,
  KEY idx_ti_task (task_id),
  KEY idx_ti_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wms_task_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL COMMENT 'create,status,priority,assign,pause,resume,pick,cell_block,move_to_sort,auto,send,print',
  field VARCHAR(50) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_th_task (task_id),
  KEY idx_th_date (created_at),
  CONSTRAINT fk_th_task FOREIGN KEY (task_id) REFERENCES wms_tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
