-- Модуль "Поступления" (WMS TiMLog), MySQL 8 / MariaDB
-- По макету "2 - TL Модуль Поступления (версия от 01.09.2026)":
-- реестр + карточка (Основное/Товары/Документы/История/Флаги) + фильтры + ПКМ + импорт/экспорт
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
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper',
  position VARCHAR(100) NULL COMMENT 'Должность: Приемщик / Кладовщик / Менеджер'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'A-105107',
  external_no VARCHAR(50) NULL COMMENT '№ внешний: 317889621 / АСН223697',
  doc_date DATETIME NOT NULL COMMENT 'Дата документа = Дата создания',
  plan_delivery_date DATETIME NULL COMMENT 'Дата поставки, план *',
  fact_delivery_date DATETIME NULL COMMENT 'Дата поставки, факт',
  arrival_date DATETIME NULL COMMENT 'Дата прибытия',
  accept_date DATETIME NULL COMMENT 'Дата приемки (фактическая)',
  place_date DATETIME NULL COMMENT 'Дата размещения',
  vehicle_arrival_plan DATETIME NULL COMMENT 'Дата прибытия ТС, план',
  vehicle_arrival_fact DATETIME NULL COMMENT 'Дата прибытия ТС, факт',
  unload_date DATETIME NULL COMMENT 'Дата разгрузки ТС',
  supplier_id BIGINT UNSIGNED NULL COMMENT 'Поставщик: ООО Мяч-Сервис',
  contractor_id BIGINT UNSIGNED NULL COMMENT 'Контрагент: ИП Смирнов М.А.',
  contract_no VARCHAR(150) NULL COMMENT 'Договор поставки №15 от 01.01.2025',
  warehouse_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft'
    COMMENT 'draft,expected,arrived,unloading,on_stock,ready_accept,accepting,ready_place,placing,closed,closed_short,cancelled,archived',
  receipt_type VARCHAR(30) NOT NULL DEFAULT 'supply' COMMENT 'supply=Поставка на склад, return=Возврат',
  accept_type VARCHAR(30) NULL COMMENT 'recount=С пересчетом вложений, no_recount=Без пересчета',
  unload_type VARCHAR(30) NULL COMMENT 'Тип разгрузки: Внавал / Паллеты',
  vehicle_plan VARCHAR(30) NULL COMMENT 'Номер ТС, план: А457АР799',
  vehicle_fact VARCHAR(30) NULL COMMENT 'Номер ТС, факт',
  vehicle VARCHAR(30) NULL COMMENT 'ТС для реестра (факт/план)',
  gate VARCHAR(20) NULL COMMENT 'Ворота: 11',
  cargo_places_plan INT NOT NULL DEFAULT 0 COMMENT 'Кол-во грузовых мест, план *',
  cargo_places_fact INT NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма без НДС, ₽',
  vat_rate TINYINT NOT NULL DEFAULT 20,
  vat_sum DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма НДС, ₽',
  total_with_vat DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма с НДС, ₽',
  paid DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Оплачено, ₽',
  debt DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Долг, ₽',
  items_count INT NOT NULL DEFAULT 0 COMMENT 'Итого позиций',
  sku_count INT NOT NULL DEFAULT 0 COMMENT 'SKU / Кол-во SKU',
  qty_total DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'штук',
  boxes INT NOT NULL DEFAULT 0 COMMENT 'коробов',
  accepted_pct TINYINT NOT NULL DEFAULT 0 COMMENT 'Принято, %',
  placed_pct TINYINT NOT NULL DEFAULT 0 COMMENT 'Размещено, %',
  accepted_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Принято (шт)',
  placed_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Размещено (шт)',
  deviation_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Отклонение (шт)',
  excess_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Излишек при закрытии (шт)',
  shortage_qty DECIMAL(14,3) NOT NULL DEFAULT 0 COMMENT 'Недостача при закрытии (шт)',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal' COMMENT 'low/normal/high',
  volume DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Объем, м3',
  quarantine TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Карантин',
  has_diff TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Наличие расхождений',
  serial_scan TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Сканирование серийных номеров',
  marking_scan TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Сканирование маркировки ЧЗ',
  creator_id BIGINT UNSIGNED NULL COMMENT 'Создал',
  unloader_id BIGINT UNSIGNED NULL COMMENT 'Разгрузил',
  responsible_id BIGINT UNSIGNED NULL COMMENT 'Ответственный / Назначенный приемщик',
  is_posted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Проведен',
  comment TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipt_no (doc_no),
  KEY idx_external (external_no),
  KEY idx_doc_date (doc_date),
  KEY idx_status (status),
  KEY idx_supplier (supplier_id),
  KEY idx_contractor (contractor_id),
  KEY idx_warehouse (warehouse_id),
  KEY idx_plan_delivery (plan_delivery_date),
  KEY idx_arrival (arrival_date),
  KEY idx_accept (accept_date),
  KEY idx_place (place_date),
  KEY idx_vehicle_arrival (vehicle_arrival_fact),
  KEY idx_vehicle (vehicle),
  KEY idx_gate (gate),
  KEY idx_unload_type (unload_type),
  KEY idx_receipt_type (receipt_type),
  KEY idx_accept_type (accept_type),
  KEY idx_priority (priority),
  KEY idx_total (total_with_vat),
  KEY idx_creator (creator_id),
  KEY idx_responsible (responsible_id),
  KEY idx_posted (is_posted),
  FULLTEXT KEY ft_search (doc_no, external_no, contract_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Товары по поступлению (вкладка Товары)
CREATE TABLE IF NOT EXISTS receipt_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  line_no INT NOT NULL DEFAULT 0 COMMENT 'п/п',
  code VARCHAR(50) NULL COMMENT 'Код WMS: T-101',
  barcode VARCHAR(100) NULL COMMENT 'Штрихкод',
  scan_serial TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Контроль с/н',
  scan_chz TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Контроль ч/з',
  owner_contractor_id BIGINT UNSIGNED NULL COMMENT 'Владелец',
  owner_name VARCHAR(255) NULL,
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул: MK-ASM-1315',
  name VARCHAR(255) NOT NULL COMMENT 'Наименование',
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty DECIMAL(12,3) NOT NULL COMMENT 'Кол-во шт.',
  boxes INT NOT NULL DEFAULT 0,
  price DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Цена без НДС, ₽',
  vat_rate TINYINT NOT NULL DEFAULT 20 COMMENT 'НДС, %',
  vat_sum_unit DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'НДС на единицу, ₽',
  price_wvat DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Цена с НДС, ₽',
  sum_novat DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма без НДС, ₽',
  vat_sum DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма НДС, ₽',
  sum_vat DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Сумма с НДС, ₽',
  quality VARCHAR(20) NULL COMMENT 'Качество из CSV: Годен/Брак',
  prod_group VARCHAR(100) NULL COMMENT 'Группа товара из CSV (для логики ЧЗ, не выводится)',
  prod_date DATE NULL COMMENT 'Дата производства из CSV',
  supplier_batch VARCHAR(100) NULL COMMENT 'Партия поставщика из заявки/Excel',
  deviation VARCHAR(20) NULL COMMENT 'Решение оператора по позиции: shortage|excess (акт — при закрытии)',
  CONSTRAINT fk_ri_receipt FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE,
  KEY idx_ri_receipt (receipt_id),
  KEY idx_ri_sku (sku),
  KEY idx_ri_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Текущие операции по поступлению (приемка + размещение в одной строке)
CREATE TABLE IF NOT EXISTS receipt_operations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  line_no INT NOT NULL DEFAULT 0 COMMENT '№',
  sku VARCHAR(50) NULL COMMENT 'Артикул',
  supplier_batch VARCHAR(100) NULL COMMENT 'Партия поставщика (выбрана на ТСД при приемке)',
  accept_status VARCHAR(30) NULL COMMENT 'Статус приемки: Принято',
  accept_date DATETIME NULL,
  receiver_id BIGINT UNSIGNED NULL COMMENT 'Приемщик',
  pallet VARCHAR(50) NULL COMMENT 'Паллета: MEZ223895',
  sku_qty INT NOT NULL DEFAULT 0 COMMENT 'Кол-во SKU',
  qty DECIMAL(12,3) NOT NULL DEFAULT 0 COMMENT 'Кол-во шт.',
  place_status VARCHAR(30) NULL COMMENT 'Статус размещения: Размещено / В размещении',
  place_date DATETIME NULL COMMENT 'Дата размещения',
  storekeeper_id BIGINT UNSIGNED NULL COMMENT 'Кладовщик',
  cell VARCHAR(50) NULL COMMENT 'Ячейка: М33-106-3-10',
  CONSTRAINT fk_ro_receipt FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE,
  KEY idx_ro_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Вложенные документы по поступлению (вкладка Документы)
CREATE TABLE IF NOT EXISTS receipt_documents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(255) NOT NULL COMMENT 'Экспедиторская расписка / Путевой лист / ТТН / Доверенность',
  format VARCHAR(10) NOT NULL DEFAULT 'pdf' COMMENT 'pdf/xsls/jpeg',
  pages INT NOT NULL DEFAULT 1,
  file_path VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rd_receipt FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE,
  KEY idx_rd_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Флаги (вкладка Флаги): серийные номера + маркировка ЧЗ
CREATE TABLE IF NOT EXISTS receipt_flags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  item_id BIGINT UNSIGNED NULL,
  flag_type VARCHAR(50) NOT NULL COMMENT 'serial|marking',
  serial_no VARCHAR(100) NULL COMMENT 'Серийный номер',
  marking_code TEXT NULL COMMENT 'Маркировка КИ: 01046...',
  sku VARCHAR(50) NULL COMMENT 'Артикул',
  receiver VARCHAR(150) NULL COMMENT 'Приемщик',
  flag_date DATETIME NULL COMMENT 'Дата',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rf_receipt FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE,
  KEY idx_rf_receipt (receipt_id),
  KEY idx_rf_serial (serial_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Реестр ожидаемых марок ЧЗ (загрузка файлом на документ; ТСД сверяет сканы с ним)
CREATE TABLE IF NOT EXISTS receipt_marks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL COMMENT 'Документ прихода',
  sku VARCHAR(50) NOT NULL COMMENT 'Артикул, к которому относится марка',
  marking_code VARCHAR(255) NOT NULL COMMENT 'Код маркировки КИ',
  status VARCHAR(20) NOT NULL DEFAULT 'expected' COMMENT 'expected|accepted',
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rm_receipt FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE,
  UNIQUE KEY uq_receipt_mark (receipt_id, marking_code),
  KEY idx_rm_receipt (receipt_id),
  KEY idx_rm_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_fio VARCHAR(150) NULL,
  position VARCHAR(100) NULL COMMENT 'Должность: Менеджер / Кладовщик / Робот',
  action VARCHAR(50) NOT NULL COMMENT 'create,update,status,post,unpost,cancel,delete,archive,print,accept,place',
  field VARCHAR(100) NULL COMMENT 'Что изменилось: Статус / Склад / Дата поставки...',
  old_value VARCHAR(255) NULL COMMENT 'Было',
  new_value VARCHAR(255) NULL COMMENT 'Стало',
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rh_receipt (receipt_id),
  KEY idx_rh_date (created_at),
  CONSTRAINT fk_rh_receipt FOREIGN KEY (receipt_id)
    REFERENCES receipts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Гард идемпотентного проброса прихода в Номенклатуру (shared/StockSync.php).
-- Без этой таблицы повторное закрытие документа прибавляло бы остаток дважды.
CREATE TABLE IF NOT EXISTS receipt_stock_sync (
  receipt_id BIGINT UNSIGNED NOT NULL,
  synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  payload TEXT NOT NULL,
  PRIMARY KEY (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Акты расхождений (shared/ReceiptClose.php): недостача / излишек / брак / не заявлен.
CREATE TABLE IF NOT EXISTS tsd_act_rasx (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL,
  supplier_batch VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Партия поставщика: акт относится к конкретной партии позиции',
  type VARCHAR(30) NOT NULL COMMENT 'недостача|излишек|брак|не_заявлен',
  qty_diff DECIMAL(14,3) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ar_receipt (receipt_id),
  KEY idx_ar_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Паллеты ТСД «Приход» (sscc) и кросс-док.
CREATE TABLE IF NOT EXISTS tsd_pallets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  sscc VARCHAR(64) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Заполняется',
  qty_total DECIMAL(14,3) NOT NULL DEFAULT 0,
  weight DECIMAL(14,3) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_pallet_receipt_sscc (receipt_id, sscc),
  KEY idx_tp_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tsd_crossdock (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL,
  gate_id VARCHAR(50) NULL,
  sscc VARCHAR(64) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'создана',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tcd_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
