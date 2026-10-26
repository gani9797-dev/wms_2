-- Модуль "Отгрузки" (WMS TiMLog), MySQL 8 / MariaDB
-- Алгоритм по макету Drawio: реестр + карточка + фильтры + ПКМ + импорт/экспорт
-- Индексы рассчитаны на большие объемы: все поля фильтров покрыты индексами.

CREATE TABLE IF NOT EXISTS warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contractors (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  FULLTEXT KEY ft_contractor (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fio VARCHAR(150) NOT NULL COMMENT 'Иванов И.И.',
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'TML317889621 / ЗАК-001245',
  contractor_id BIGINT UNSIGNED NULL,
  consignee VARCHAR(255) NULL COMMENT 'Грузополучатель: Почта России',
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

-- Строки наряда (заказа): загружаются из CSV расхода, номера нарядов — or_XXXXXX.
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

CREATE TABLE IF NOT EXISTS shipments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'S-205107 / S-205108',
  doc_date DATETIME NOT NULL COMMENT 'Дата документа = Дата создания',
  order_id BIGINT UNSIGNED NULL COMMENT 'legacy: главный заказ, реально связь через shipment_orders',
  contractor_id BIGINT UNSIGNED NOT NULL COMMENT 'Контрагент: ООО Ромашка',
  consignee VARCHAR(255) NULL COMMENT 'Грузополучатель: Почта России / ТК КИТ / ПЭК',
  contract_no VARCHAR(100) NULL COMMENT 'Договор с ТК №813 от 01.01.2025',
  warehouse_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'assembling'
    COMMENT 'draft,created,assembling,assembled,awaiting_payment,delivering,delivered,on_check,packing,on_pack,cancelled,archived + На упаковке/На проверке/Готов к отгрузке',
  total DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма, ₽',
  vat_rate TINYINT NOT NULL DEFAULT 20,
  vat_sum DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_with_vat DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  debt DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'total_with_vat - paid',
  items_count INT NOT NULL DEFAULT 0 COMMENT 'Позиций',
  sku_count INT NOT NULL DEFAULT 0 COMMENT 'SKU',
  qty_total DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'штук',
  plan_shipment_date DATE NULL COMMENT 'Плановая дата отгрузки',
  fact_shipment_date DATE NULL,
  plan_delivery_date DATE NULL,
  fact_delivery_date DATE NULL COMMENT 'Дата доставки',
  vehicle_arrival DATETIME NULL COMMENT 'Дата прибытия ТС',
  vehicle_departure DATETIME NULL COMMENT 'Дата отгрузки ТС',
  loading_date DATETIME NULL COMMENT 'Дата загрузки ТС',
  assembly_start DATETIME NULL COMMENT 'Дата начала сборки',
  assembly_end DATETIME NULL COMMENT 'Дата окончания сборки',
  packing_start DATETIME NULL COMMENT 'Дата начала упаковки',
  packing_end DATETIME NULL COMMENT 'Дата окончания упаковки',
  check_start DATETIME NULL COMMENT 'Дата начала проверки',
  check_end DATETIME NULL COMMENT 'Дата окончания проверки',
  vehicle VARCHAR(30) NULL COMMENT 'ТС: А221ВА77',
  gate VARCHAR(20) NULL COMMENT 'Ворота: 11',
  gates_extra VARCHAR(100) NULL COMMENT 'Дополнительные ворота 8.1,8.2',
  loading_type VARCHAR(30) NULL COMMENT 'Тип загрузки: Внавал / Паллеты',
  delivery_type VARCHAR(50) NULL COMMENT 'Самовывоз / Доставка',
  delivery_method VARCHAR(50) NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'low/normal/high',
  loader_id BIGINT UNSIGNED NULL COMMENT 'Загрузил',
  ttn VARCHAR(50) NULL COMMENT 'ТТН для поиска',
  responsible_id BIGINT UNSIGNED NULL COMMENT 'Создан / Ответственный',
  volume DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Объем, м3',
  weight DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Вес, кг',
  cargo_places INT NOT NULL DEFAULT 0 COMMENT 'Грузовые места',
  has_diff TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Наличие расхождений',
  readiness TINYINT NOT NULL DEFAULT 0 COMMENT 'Готовность, %',
  recommended_pick VARCHAR(30) NULL COMMENT 'Рекомендованный сбор: Волна',
  launched_pick VARCHAR(30) NULL COMMENT 'Запущенный сбор: Волна',
  need_check TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Требуется проверка после сбора',
  need_pack TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Требуется упаковка',
  is_kgt TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'КГТ',
  is_danger TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Опасный груз',
  serial_scan TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Сканирование серийных номеров',
  marking_scan TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Сканирование маркировки ЧЗ',
  is_crossdock TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Кроссдок: сортировка паллетами',
  is_posted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Проведен',
  comment TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_shipment_no (doc_no),
  KEY idx_doc_date (doc_date),
  KEY idx_status (status),
  KEY idx_contractor (contractor_id),
  KEY idx_warehouse (warehouse_id),
  KEY idx_responsible (responsible_id),
  KEY idx_loader (loader_id),
  KEY idx_order (order_id),
  KEY idx_plan_ship (plan_shipment_date),
  KEY idx_fact_ship (fact_shipment_date),
  KEY idx_fact_delivery (fact_delivery_date),
  KEY idx_vehicle_arrival (vehicle_arrival),
  KEY idx_assembly (assembly_start),
  KEY idx_packing (packing_start),
  KEY idx_check (check_start),
  KEY idx_vehicle (vehicle),
  KEY idx_gate (gate),
  KEY idx_loading_type (loading_type),
  KEY idx_priority (priority),
  KEY idx_total (total_with_vat),
  KEY idx_posted (is_posted),
  KEY idx_volume (volume),
  FULLTEXT KEY ft_search (doc_no, ttn, contract_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Заказы в отгрузке (вкладка Заказы (9)): один документ = N заказов TML*
CREATE TABLE IF NOT EXISTS shipment_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NULL COMMENT 'Статус заказа на момент включения',
  qty_total DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Кол-во в заказе',
  collected INT NOT NULL DEFAULT 0 COMMENT 'Собрано',
  checked INT NOT NULL DEFAULT 0 COMMENT 'Проверено',
  packed INT NOT NULL DEFAULT 0 COMMENT 'Упаковано',
  shipped INT NOT NULL DEFAULT 0 COMMENT 'Отгружено',
  sum_novat DECIMAL(14,2) NOT NULL DEFAULT 0,
  vat DECIMAL(14,2) NOT NULL DEFAULT 0,
  sum_vat DECIMAL(14,2) NOT NULL DEFAULT 0,
  CONSTRAINT fk_so_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  CONSTRAINT fk_so_order FOREIGN KEY (order_id)
    REFERENCES client_orders (id) ON DELETE RESTRICT,
  UNIQUE KEY uq_so (shipment_id, order_id),
  KEY idx_so_shipment (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL COMMENT 'Заказ TML*',
  code VARCHAR(50) NULL COMMENT 'Код: T-101',
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул: MK-ASM-1315',
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty DECIMAL(12,3) NOT NULL,
  price DECIMAL(14,2) NOT NULL COMMENT 'Цена с НДС',
  sum DECIMAL(14,2) NOT NULL,
  check_label VARCHAR(50) NULL COMMENT 'Этикетка проверки: EXP317889621-1',
  warehouse_id BIGINT UNSIGNED NULL,
  comment TEXT NULL,
  prod_date DATE NULL COMMENT 'Дата производства из CSV',
  CONSTRAINT fk_items_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  KEY idx_shipment (shipment_id),
  KEY idx_item_order (order_id),
  KEY idx_item_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Факт сортировки через ТСД (tsd/otbor.php confirm): SSCC указывается при отборе.
-- Упаковочный лист строится по одной SSCC: для отгрузки их может быть несколько.
CREATE TABLE IF NOT EXISTS tsd_picks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  user_id BIGINT UNSIGNED NULL,
  sscc VARCHAR(50) NULL COMMENT 'Номер SSCC паллеты, указанный при сортировке',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_picks_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  KEY idx_picks_shipment (shipment_id),
  KEY idx_picks_sscc (sscc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Флаги (вкладка Флаги (12)): серийники, маркировка ЧЗ, агрегация
CREATE TABLE IF NOT EXISTS shipment_flags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  item_id BIGINT UNSIGNED NULL,
  flag_type VARCHAR(50) NOT NULL COMMENT 'serial|marking|aggregation|danger|kgt|need_check|need_pack',
  serial_no VARCHAR(100) NULL COMMENT 'Серийный номер: A1B2C3D4E5F6',
  marking_code TEXT NULL COMMENT 'Маркировка КИ',
  aggregation VARCHAR(50) NULL COMMENT 'Агрегация: 04640288910507',
  stock_left INT NULL COMMENT 'Остаток СГ',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_flag_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  KEY idx_flag_shipment (shipment_id),
  KEY idx_flag_serial (serial_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Лог операций сортировки отгрузки (ТСД). Хранит только идентификаторы:
-- паллета (SSCC), сотрудник, товар + дата создания SSCC, серийник, честный знак.
CREATE TABLE IF NOT EXISTS shipment_sort_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  pallet_id VARCHAR(50) NULL COMMENT 'SSCC/номер паллеты',
  pallet_created_at DATETIME NULL COMMENT 'Дата создания SSCC',
  user_id BIGINT UNSIGNED NULL COMMENT 'id сотрудника',
  item_id BIGINT UNSIGNED NULL COMMENT 'id товара (shipment_items.id)',
  serial_no VARCHAR(100) NULL COMMENT 'Серийный номер',
  marking_code TEXT NULL COMMENT 'Честный знак (марка)',
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  operation VARCHAR(30) NOT NULL DEFAULT 'sort' COMMENT 'sort|crossdock|mark|serial',
  reason VARCHAR(100) NULL COMMENT 'Причина: марка не принята / не введена в оборот',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ssl_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  KEY idx_ssl_shipment (shipment_id),
  KEY idx_ssl_pallet (pallet_id),
  KEY idx_ssl_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Реестр марок честного знака по отгрузке (загрузка файлом на документ).
-- Статусы: expected (загружена), accepted (принята), circulated (введена в оборот).
CREATE TABLE IF NOT EXISTS shipment_marks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул',
  marking_code TEXT NOT NULL COMMENT 'Маркировка КИ',
  prod_date DATE NULL COMMENT 'Дата производства марки',
  status VARCHAR(20) NOT NULL DEFAULT 'expected',
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sm_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  UNIQUE KEY uq_shipment_mark (shipment_id, marking_code(191)),
  KEY idx_sm_shipment (shipment_id),
  KEY idx_sm_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Вложенные документы (вкладка Документы (4))
CREATE TABLE IF NOT EXISTS shipment_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL COMMENT 'Экспедиторская расписка / Путевой лист / ТТН / Доверенность',
  format VARCHAR(10) NOT NULL DEFAULT 'pdf' COMMENT 'pdf/xsls/jpeg',
  pages INT NOT NULL DEFAULT 1,
  file_path VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE,
  KEY idx_doc_shipment (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  shipment_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_fio VARCHAR(150) NULL,
  position VARCHAR(100) NULL COMMENT 'Должность: Менеджер / Начальник смены / Робот',
  action VARCHAR(50) NOT NULL COMMENT 'create,update,status,post,unpost,cancel,delete,archive,print,pack,check',
  field VARCHAR(50) NULL COMMENT 'Статус / Приоритет / Начало сборки...',
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_hist_shipment (shipment_id),
  KEY idx_hist_date (created_at),
  CONSTRAINT fk_hist_shipment FOREIGN KEY (shipment_id)
    REFERENCES shipments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
