-- Модуль "Задания" (WMS TiMLog), MySQL 8 / MariaDB
-- ВАЖНО: имена таблиц — tasks / task_items / task_history.
--   Именно эти имена использует весь код модуля (TaskRepository, TaskService),
--   ТСД (tsd/tasks.php) и список отбора (shared/PickLists.php), а также
--   каноническая схема PostgreSQL (db/schema-pg.sql).
--   Раньше здесь были wms_tasks/wms_task_items/wms_task_history — модуль
--   на этой схеме полностью падал («table doesn't exist»).
-- Поток: поступления -> product_stock (товарный запас, модуль 8.1) -> отгрузка
--   выпускает задание -> реестр "Задания" -> ТСД "Отбор".
-- Типы (схема 7 - TL Модуль Задания + уточнение): отбор, размещение, перемещение, пополнение.
-- Статусы: new (Новый), work (В работе), paused (На паузе / в ожидании), done (Выполнен), cancelled (Отменено).

-- ОСТОРОЖНО: таблица wms_stock не используется кодом.
--   Реальный товарный запас — product_stock в БД номенклатуры (модуль 8.1),
--   его наполняет shared/StockSync.php при закрытии поступления.
--   Оставлена для совместимости с ранее развёрнутыми базами.
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

CREATE TABLE IF NOT EXISTS tasks (
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
  pick_mode VARCHAR(30) NULL COMMENT 'Режим отбора: wave (волной) / line (построчно)',
  shipment_ids VARCHAR(255) NULL COMMENT 'Отгрузки задания (через запятую)',
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

CREATE TABLE IF NOT EXISTS task_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  cell_from VARCHAR(50) NULL COMMENT 'Откуда (ячейка отбора)',
  cell_to VARCHAR(50) NULL COMMENT 'Куда',
  pallet VARCHAR(50) NULL,
  supplier_batch VARCHAR(100) NULL COMMENT 'Партия поставщика',
  plan_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  fact_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  state VARCHAR(30) NULL COMMENT 'Состояние строки',
  executor_fio VARCHAR(150) NULL,
  CONSTRAINT fk_ti_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE,
  KEY idx_ti_task (task_id),
  KEY idx_ti_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL COMMENT 'create,status,priority,assign,pause,resume,pick,cell_block,move_to_sort,auto,send,print',
  field VARCHAR(50) NULL,
  field_label VARCHAR(100) NULL COMMENT 'Подпись поля (для истории)',
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_th_task (task_id),
  KEY idx_th_date (created_at),
  CONSTRAINT fk_th_task FOREIGN KEY (task_id) REFERENCES tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Закрепление заданий за сотрудниками ТСД + журнал проблем/сортировки.
-- Пишет tsd/tasks.php; таблицы обязательны — без них ТСД «Задания» не работает.
CREATE TABLE IF NOT EXISTS tsd_task_locks (
  task_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_fio VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'work' COMMENT 'work/waiting',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (task_id),
  KEY idx_ttl_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tsd_task_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  task_id BIGINT UNSIGNED NULL,
  cell VARCHAR(50) NULL,
  sku VARCHAR(50) NULL,
  user_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ttl_task (task_id),
  KEY idx_ttl_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Списки заданий (ТСД «Отбор по зоне»).
-- Код списка: PK-000001, PK-000002 ... (буквы не меняются).
-- В списке — одна отгрузка (нарядов может быть несколько).
-- type: manual = Ручной отбор (роль Кладовщик), driver = Отбор водителем (роль Водитель).
CREATE TABLE IF NOT EXISTS task_lists (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL COMMENT 'PK-000001',
  name VARCHAR(255) NULL COMMENT 'Название списка',
  type VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual=Ручной отбор, driver=Отбор водителем',
  shipment_id BIGINT UNSIGNED NULL COMMENT 'Отгрузка списка (одна)',
  shipment_doc_no VARCHAR(50) NULL COMMENT '№ отгрузки',
  zone VARCHAR(30) NULL COMMENT 'Зона отбора ячеек',
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  created_by BIGINT UNSIGNED NULL,
  created_by_fio VARCHAR(150) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_task_list_code (code),
  KEY idx_task_list_type (type),
  KEY idx_task_list_ship (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_list_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  list_id BIGINT UNSIGNED NOT NULL,
  task_id BIGINT UNSIGNED NOT NULL,
  seq INT NOT NULL DEFAULT 0 COMMENT 'Порядок в списке',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tli_list FOREIGN KEY (list_id) REFERENCES task_lists (id) ON DELETE CASCADE,
  UNIQUE KEY uq_tli (list_id, task_id),
  KEY idx_tli_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
