-- Модуль 8 "Номенклатура" (WMS TiMLog), MySQL 8 / MariaDB
-- По макету "8 - TL Модуль Номенклатура (версия от 18.08.2026)":
-- реестр (Код/Артикул/Наименование/Ед.изм/Категория/Остаток/Зарезервировано/Статус/Штрихкод)
-- + поиск (артикул, штрихкод, наименование) + фильтры + ПКМ + импорт/экспорт
-- + карточка: вкладка 1 "Основное", вкладка 2 "Правила" (Хранение/Отбор), вкладка 3 "История" (История изменений + Движение)
-- + нижняя панель "Товарный запас" (ячейки хранения) + массовое редактирование через чек-бокс
-- Значения по умолчанию (уровень интерфейса, только Админ) — хранятся в product_defaults.

CREATE TABLE IF NOT EXISTS warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fio VARCHAR(150) NOT NULL COMMENT 'Иванов И.И.',
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL COMMENT 'Код: T-101',
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул: MK-ASM-1315',
  name VARCHAR(255) NOT NULL COMMENT 'Наименование: Мяч баскетбольный А',
  unit VARCHAR(10) NOT NULL DEFAULT 'шт' COMMENT 'шт/кор',
  category VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Категория: Мячи',
  brand VARCHAR(100) NULL COMMENT 'Бренд: Wilson',
  owner VARCHAR(150) NULL COMMENT 'Владелец: ООО Эталон',
  warehouse_id BIGINT UNSIGNED NULL COMMENT 'Основной склад (поле Склад* в карточке)',
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  length_mm INT NULL,
  width_mm INT NULL,
  height_mm INT NULL,
  weight_g INT NULL COMMENT 'Вес, гр',
  volume_m3 DECIMAL(12,6) NULL COMMENT 'Объем, м3 (считается, можно переопределить)',
  qty_in_box INT NULL COMMENT 'Кол-во штук в коробе',
  boxes_per_pallet INT NULL COMMENT 'Коробов на паллете',
  storage_strategy VARCHAR(20) NOT NULL DEFAULT 'FEFO' COMMENT 'FIFO/FEFO/LIFO',
  pick_strategy VARCHAR(20) NOT NULL DEFAULT 'FEFO' COMMENT 'FIFO/FEFO/LIFO',
  placement_strategy VARCHAR(20) NOT NULL DEFAULT 'FEFO' COMMENT 'Размещение: FIFO/FEFO/LIFO',
  temp_from DECIMAL(5,1) NULL COMMENT 'Температурный режим от, +5 C',
  temp_to DECIMAL(5,1) NULL COMMENT 'Температурный режим до, +25 C',
  shelf_life_days INT NULL COMMENT 'Срок годности, дни: 365',
  marking_start DATE NULL COMMENT 'Дата начала маркировки: 2025-01-01',
  chz_group VARCHAR(100) NULL COMMENT 'Группа ЧЗ: Легкая промышленность',
  max_expiry_pct DECIMAL(5,2) NULL COMMENT 'Макс % истечения срока годности',
  is_batch TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Партийный учет',
  is_expiry TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Учет сроков годности',
  is_serial TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Учет серийных номеров',
  is_chz TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Честный знак',
  is_danger TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Опасный груз',
  allow_partial_pick TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Разрешить частичный отбор',
  need_check TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Требуется проверка после отбора',
  need_pack TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Требуется упаковка заказа',
  auto_replenish TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Автопополнение',
  need_measure TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Требуются обмеры на ТСД (новый товар из CSV-прихода)',
  quality VARCHAR(20) NULL COMMENT 'Качество из приемки: Годен/Брак',
  qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Остаток (агрегат по stock)',
  reserved_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Зарезервировано',
  is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=Активный, 0=Не активный (заблокирован)',
  is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod_code (code),
  UNIQUE KEY uq_prod_sku (sku),
  KEY idx_prod_unit (unit),
  KEY idx_prod_cat (category),
  KEY idx_prod_brand (brand),
  KEY idx_prod_owner (owner),
  KEY idx_prod_wh (warehouse_id),
  KEY idx_prod_active (is_active),
  KEY idx_prod_created (created_at),
  KEY idx_prod_updated (updated_at),
  KEY idx_prod_qty (qty),
  KEY idx_prod_reserved (reserved_qty),
  FULLTEXT KEY ft_prod (sku, name, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_barcodes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  barcode VARCHAR(50) NOT NULL COMMENT 'ШК единицы/короба: 2178899952100',
  CONSTRAINT fk_pb_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  UNIQUE KEY uq_pb (barcode),
  KEY idx_pb_prod (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Товарный запас: нижняя панель при клике на строку (ячейки хранения с данным товаром).
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

-- История изменений карточки (вкладка 3, верхняя таблица).
CREATE TABLE IF NOT EXISTS product_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_fio VARCHAR(150) NULL COMMENT '1788 - Смирнов Николай',
  field VARCHAR(50) NULL COMMENT 'вес / срок_годности / temp',
  field_label VARCHAR(100) NULL COMMENT 'Вес / Срок годности / Температур. режим',
  old_value VARCHAR(255) NULL COMMENT '10 500',
  new_value VARCHAR(255) NULL COMMENT '12 000',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ph_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  KEY idx_ph_prod (product_id),
  KEY idx_ph_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Движение товара (вкладка 3, нижняя таблица).
CREATE TABLE IF NOT EXISTS product_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  doc_date DATETIME NOT NULL,
  doc_type VARCHAR(30) NOT NULL COMMENT 'receipt/move/shipment',
  doc_no VARCHAR(50) NOT NULL COMMENT 'Поступление №П-1287',
  cell VARCHAR(50) NULL,
  qty_change DECIMAL(14,3) NOT NULL COMMENT '+1000 / -500',
  balance DECIMAL(14,3) NOT NULL COMMENT 'Остаток после операции',
  CONSTRAINT fk_pm_prod FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
  KEY idx_pm_prod (product_id),
  KEY idx_pm_date (doc_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Значения по умолчанию для формы создания (только Админ, уровень интерфейса).
CREATE TABLE IF NOT EXISTS product_defaults (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_name VARCHAR(50) NOT NULL COMMENT 'unit/category/warehouse_id/storage_strategy/...',
  field_value VARCHAR(255) NULL,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pd_field (field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Уведомления (схема 8 v31: колокол в шапке, панель, настройки подписок, стр. 12).
-- Каналы Web/Email/Telegram пока только хранят предпочтения подписок.
CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL COMMENT 'NULL = всем',
  kind VARCHAR(30) NOT NULL DEFAULT 'nomenclature' COMMENT 'receipt/shipment/task/nomenclature',
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  sku VARCHAR(50) NULL,
  is_important TINYINT(1) NOT NULL DEFAULT 0,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ntf_user (user_id),
  KEY idx_ntf_kind (kind),
  KEY idx_ntf_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  event_kind VARCHAR(30) NOT NULL COMMENT 'receipt/shipment/task/nomenclature',
  ch_web TINYINT(1) NOT NULL DEFAULT 0,
  ch_email TINYINT(1) NOT NULL DEFAULT 0,
  ch_tg TINYINT(1) NOT NULL DEFAULT 0,
  cond_label VARCHAR(100) NULL COMMENT 'Все статусы / Расхождение, Отменено / Просрочено / Любое изменение',
  is_on TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nsub (user_id, event_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
