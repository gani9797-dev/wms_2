-- WMS TiMLog — единая схема PostgreSQL 14+ (пересборка).
-- Одна БД `wms` вместо разрозненных sqlite-файлов и 30+ модульных schema.sql (MySQL-диалект).
-- Покрывает ВСЕ модули: 00-dashboard, 01/01.1/01.2 (ТС, ворота), 02/02.1 (поступления+приемка),
-- 03/03.1/03.2/03.3 (отгрузки, заказы, упаковка, проверка), 04 (внутр. операции),
-- 05/05.1 (ячейки, 3D-карта), 06 (инвентаризация), 07+tasks (задания демо+прод),
-- 08/08.1 (номенклатура+запас), 09/09.1 (контрагенты+биллинг), 10/10.1/10.2 (сотрудники/KPI/сделки),
-- 11 (отчеты), 12 (уведомления), 13/13.1-13.4 (настройки и автоматизация), tsd-* (ТСД).
--
-- Применение (пересборка с нуля):
--   docker compose up -d
--   psql -h 127.0.0.1 -U wms -d wms -f db/schema-pg.sql
--   php db/migrate-sqlite-to-pg.php --fresh   # опционально: залить данные из *.sqlite
--
-- Повторный прогон безопасен (идемпотентно):
--   CREATE TABLE/INDEX IF NOT EXISTS + ALTER TABLE ... ADD COLUMN IF NOT EXISTS + сиды ON CONFLICT DO NOTHING.
-- Конверсия типов из MySQL: BIGINT UNSIGNED AUTO_INCREMENT -> BIGSERIAL, DATETIME -> TIMESTAMP,
-- TINYINT(1) -> SMALLINT, JSON -> JSONB, FULLTEXT KEY -> gin_trgm-индекс.

CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- ---------- совместимость с SQLite-диалектом (чтобы старый код работал без правок) ----------
-- datetime('now') -> NOW().
CREATE OR REPLACE FUNCTION datetime(x text) RETURNS timestamptz AS $$
  SELECT CASE WHEN lower(x) IN ('now', 'now,localtime') THEN NOW() ELSE x::timestamptz END
$$ LANGUAGE sql STABLE;
-- strftime('%d.%m.%Y %H:%M', ts) -> to_char. Покрыты форматы, реально используемые в tsd/*.
CREATE OR REPLACE FUNCTION strftime(fmt text, ts timestamptz) RETURNS text AS $$
  SELECT to_char(ts, replace(replace(replace(replace(replace(fmt,
    '%d', 'DD'), '%m', 'MM'), '%Y', 'YYYY'), '%H', 'HH24'), '%M', 'MI'))
$$ LANGUAGE sql STABLE;
CREATE OR REPLACE FUNCTION strftime(fmt text, ts text) RETURNS text AS $$
  SELECT strftime(fmt, ts::timestamptz)
$$ LANGUAGE sql STABLE;
-- printf('%04d', n) — только этот кейс используется (pin-коды).
CREATE OR REPLACE FUNCTION printf(fmt text, n integer) RETURNS text AS $$
  SELECT CASE WHEN fmt = '%04d' THEN LPAD(n::text, 4, '0') ELSE n::text END
$$ LANGUAGE sql IMMUTABLE;

-- ============================================================
-- 0. ОБЩИЕ СПРАВОЧНИКИ (суперсет sqlite + 13-nastroyki)
-- ============================================================
-- warehouses: суперсет (sqlite: id,name; 13-nastroyki: +code/wh_type/address/timezone/status).
CREATE TABLE IF NOT EXISTS warehouses (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(20) NULL,
  name VARCHAR(255) NOT NULL,
  wh_type VARCHAR(100) NOT NULL DEFAULT 'Распределительный',
  address VARCHAR(500) NULL,
  timezone VARCHAR(50) NOT NULL DEFAULT '(UTC+3) Москва',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (code)
);

CREATE TABLE IF NOT EXISTS contractors (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_contractors_name ON contractors USING gin (name gin_trgm_ops);

-- users: суперсет (sqlite местами только id,fio,role; поступления: +position+pin).
CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  fio VARCHAR(150) NOT NULL,
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper',
  position VARCHAR(100),
  pin VARCHAR(10)
);

-- ---------- 09: контрагенты и грузополучатели ----------
CREATE TABLE IF NOT EXISTS counterparties (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  inn VARCHAR(20) NULL,
  ctype VARCHAR(30) NOT NULL DEFAULT 'supplier',
  phone VARCHAR(50) NULL,
  is_active SMALLINT NOT NULL DEFAULT 1,
  -- Принцип резервирования (впуск заданий на отбор): FIFO / FEFO / LIFO.
  reserve_strategy VARCHAR(10) NOT NULL DEFAULT 'FIFO',
  -- Глобальные флаги сканирования серийных номеров клиента.
  scan_serial_in SMALLINT NOT NULL DEFAULT 0,
  scan_serial_out SMALLINT NOT NULL DEFAULT 0,
  -- Зоны размещения клиента (несколько — через запятую).
  placement_zones VARCHAR(255) NULL,
  -- Ячейки клиента (коды через запятую) — приоритет размещения «ближе к клиенту».
  placement_cell_codes TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_cp_name ON counterparties USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_cp_type ON counterparties (ctype);

CREATE TABLE IF NOT EXISTS consignees (
  id BIGSERIAL PRIMARY KEY,
  counterparty_id BIGINT NULL REFERENCES counterparties (id) ON DELETE SET NULL,
  name VARCHAR(255) NOT NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL
);
CREATE INDEX IF NOT EXISTS idx_csg_cp ON consignees (counterparty_id);

-- ---------- 10: сотрудники ----------
-- employees: id = ID_user (идентификатор сотрудника в WMS).
CREATE TABLE IF NOT EXISTS employees (
  id BIGSERIAL PRIMARY KEY,
  fio VARCHAR(150) NOT NULL,
  last_name VARCHAR(100) NULL,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  position VARCHAR(100) NULL,
  code VARCHAR(10) NULL,
  department VARCHAR(100) NULL,
  personnel_no VARCHAR(30) NULL,
  pin VARCHAR(10) NULL UNIQUE,
  photo_path VARCHAR(255) NULL,
  phone_internal VARCHAR(50) NULL,
  phone_mobile VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  birth_date DATE NULL,
  hired_at DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper',
  zone VARCHAR(50) NOT NULL DEFAULT 'Все зоны',
  warehouse_id BIGINT NULL,
  tsd_access SMALLINT NOT NULL DEFAULT 0,
  is_active SMALLINT NOT NULL DEFAULT 1,
  is_blocked SMALLINT NOT NULL DEFAULT 0,
  allow_unplanned_exit SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_emp_fio ON employees USING gin (fio gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_emp_department ON employees (department);
CREATE INDEX IF NOT EXISTS idx_emp_status ON employees (status);
-- Дотягиваем карточку на уже созданной БД (идемпотентно).
ALTER TABLE employees ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS middle_name VARCHAR(100) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS code VARCHAR(10) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS personnel_no VARCHAR(30) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS phone_internal VARCHAR(50) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS phone_mobile VARCHAR(50) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS birth_date DATE NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS hired_at DATE NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active';
ALTER TABLE employees ADD COLUMN IF NOT EXISTS role VARCHAR(50) NOT NULL DEFAULT 'storekeeper';
ALTER TABLE employees ADD COLUMN IF NOT EXISTS zone VARCHAR(50) NOT NULL DEFAULT 'Все зоны';
ALTER TABLE employees ADD COLUMN IF NOT EXISTS warehouse_id BIGINT NULL;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS tsd_access SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS is_blocked SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS allow_unplanned_exit SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE employees ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW();

-- Графики сотрудника (вкладка «График»).
CREATE TABLE IF NOT EXISTS employee_schedules (
  id BIGSERIAL PRIMARY KEY,
  employee_id BIGINT NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  pattern VARCHAR(10) NOT NULL,
  shift VARCHAR(30) NOT NULL,
  work_from TIME NULL,
  work_to TIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_esch_emp ON employee_schedules (employee_id);

-- Права и роли (вкладка «Права и роли»).
CREATE TABLE IF NOT EXISTS employee_access (
  id BIGSERIAL PRIMARY KEY,
  employee_id BIGINT NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
  scope VARCHAR(10) NOT NULL DEFAULT 'wms',
  module_key VARCHAR(50) NOT NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'none',
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (employee_id, scope, module_key)
);

-- История выходов по СКУД (вкладка «История выходов»).
CREATE TABLE IF NOT EXISTS employee_exits (
  id BIGSERIAL PRIMARY KEY,
  employee_id BIGINT NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
  event_at TIMESTAMP NOT NULL,
  checkpoint VARCHAR(100) NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'in',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_eex_emp ON employee_exits (employee_id);

-- Присутствие за смену (Начало/Конец/Работает/Отработано).
CREATE TABLE IF NOT EXISTS employee_attendance (
  id BIGSERIAL PRIMARY KEY,
  employee_id BIGINT NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
  work_date DATE NOT NULL,
  start_at TIME NULL,
  end_at TIME NULL,
  worked_minutes INTEGER NOT NULL DEFAULT 0,
  on_shift SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_eatt_emp ON employee_attendance (employee_id);
CREATE INDEX IF NOT EXISTS idx_eatt_date ON employee_attendance (work_date);

-- История изменений карточки (вкладка «История изменений»).
CREATE TABLE IF NOT EXISTS employee_history (
  id BIGSERIAL PRIMARY KEY,
  employee_id BIGINT NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  field VARCHAR(50) NOT NULL,
  field_label VARCHAR(100) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ehist_emp ON employee_history (employee_id);

-- ============================================================
-- 1. ТС И ВОРОТА (01, 01.1, 01.2)
-- ============================================================
CREATE TABLE IF NOT EXISTS vehicles (
  id BIGSERIAL PRIMARY KEY,
  plate_no VARCHAR(30) NOT NULL UNIQUE,
  vehicle_type VARCHAR(50) NULL,
  capacity_kg NUMERIC(12,2) NOT NULL DEFAULT 0,
  capacity_m3 NUMERIC(12,3) NOT NULL DEFAULT 0,
  driver VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  is_active SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_veh_active ON vehicles (is_active);

CREATE TABLE IF NOT EXISTS vehicle_arrivals (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  vehicle_id BIGINT NULL REFERENCES vehicles (id) ON DELETE SET NULL,
  plate_no VARCHAR(30) NOT NULL,
  driver VARCHAR(150) NULL,
  supplier_id BIGINT NULL,
  arrival_plan TIMESTAMP NULL,
  arrival_fact TIMESTAMP NULL,
  gate VARCHAR(20) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'expected',
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_varr_plate ON vehicle_arrivals (plate_no);
CREATE INDEX IF NOT EXISTS idx_varr_status ON vehicle_arrivals (status);
CREATE INDEX IF NOT EXISTS idx_varr_plan ON vehicle_arrivals (arrival_plan);

CREATE TABLE IF NOT EXISTS vehicle_arrival_history (
  id BIGSERIAL PRIMARY KEY,
  arrival_id BIGINT NOT NULL REFERENCES vehicle_arrivals (id) ON DELETE CASCADE,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  user_fio VARCHAR(150) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_varrh_arr ON vehicle_arrival_history (arrival_id);

-- gates: каноническая таблица из 01.2 (code/name/type). Бронирование из 13 — settings_gates (ниже).
CREATE TABLE IF NOT EXISTS gates (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  gate_type VARCHAR(30) NOT NULL DEFAULT 'in',
  warehouse_id BIGINT NULL REFERENCES warehouses (id) ON DELETE SET NULL,
  is_active SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS gate_slots (
  id BIGSERIAL PRIMARY KEY,
  gate_id BIGINT NOT NULL REFERENCES gates (id) ON DELETE CASCADE,
  arrival_id BIGINT NULL REFERENCES vehicle_arrivals (id) ON DELETE SET NULL,
  slot_from TIMESTAMP NOT NULL,
  slot_to TIMESTAMP NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'busy'
);
CREATE INDEX IF NOT EXISTS idx_gs_gate ON gate_slots (gate_id);
CREATE INDEX IF NOT EXISTS idx_gs_from ON gate_slots (slot_from);

-- ============================================================
-- 2. ПОСТУПЛЕНИЯ (02) + ПРИЕМКА (02.1)
-- ============================================================
CREATE TABLE IF NOT EXISTS receipts (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  external_no VARCHAR(50),
  doc_date TIMESTAMP NOT NULL,
  plan_delivery_date TIMESTAMP NULL,
  fact_delivery_date TIMESTAMP NULL,
  arrival_date TIMESTAMP NULL,
  accept_date TIMESTAMP NULL,
  place_date TIMESTAMP NULL,
  vehicle_arrival_plan TIMESTAMP NULL,
  vehicle_arrival_fact TIMESTAMP NULL,
  unload_date TIMESTAMP NULL,
  supplier_id BIGINT NULL,
  contractor_id BIGINT NULL,
  contract_no VARCHAR(150) NULL,
  warehouse_id BIGINT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  receipt_type VARCHAR(30) NOT NULL DEFAULT 'supply',
  accept_type VARCHAR(30) NULL,
  unload_type VARCHAR(30) NULL,
  vehicle_plan VARCHAR(30) NULL,
  vehicle_fact VARCHAR(30) NULL,
  vehicle VARCHAR(30) NULL,
  gate VARCHAR(20) NULL,
  cargo_places_plan INTEGER NOT NULL DEFAULT 0,
  cargo_places_fact INTEGER NOT NULL DEFAULT 0,
  total NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_rate SMALLINT NOT NULL DEFAULT 20,
  vat_sum NUMERIC(14,2) NOT NULL DEFAULT 0,
  total_with_vat NUMERIC(14,2) NOT NULL DEFAULT 0,
  paid NUMERIC(14,2) NOT NULL DEFAULT 0,
  debt NUMERIC(14,2) NOT NULL DEFAULT 0,
  items_count INTEGER NOT NULL DEFAULT 0,
  sku_count INTEGER NOT NULL DEFAULT 0,
  qty_total NUMERIC(14,3) NOT NULL DEFAULT 0,
  boxes INTEGER NOT NULL DEFAULT 0,
  accepted_pct SMALLINT NOT NULL DEFAULT 0,
  placed_pct SMALLINT NOT NULL DEFAULT 0,
  accepted_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  placed_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  deviation_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  -- Отклонения при закрытии: излишек / недостача (заполняются при закрытии документа).
  excess_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  shortage_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  volume NUMERIC(12,3) NOT NULL DEFAULT 0,
  quarantine SMALLINT NOT NULL DEFAULT 0,
  has_diff SMALLINT NOT NULL DEFAULT 0,
  serial_scan SMALLINT NOT NULL DEFAULT 0,
  marking_scan SMALLINT NOT NULL DEFAULT 0,
  creator_id BIGINT NULL,
  unloader_id BIGINT NULL,
  responsible_id BIGINT NULL,
  is_posted SMALLINT NOT NULL DEFAULT 0,
  comment TEXT NULL,
  created_by BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_receipts_status ON receipts (status);
CREATE INDEX IF NOT EXISTS idx_receipts_wh ON receipts (warehouse_id);
CREATE INDEX IF NOT EXISTS idx_receipts_docdate ON receipts (doc_date);
CREATE INDEX IF NOT EXISTS idx_receipts_supplier ON receipts (supplier_id);
CREATE INDEX IF NOT EXISTS idx_receipts_contractor ON receipts (contractor_id);
CREATE INDEX IF NOT EXISTS idx_receipts_external ON receipts (external_no);

CREATE TABLE IF NOT EXISTS receipt_items (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  line_no INTEGER NOT NULL DEFAULT 0,
  code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty NUMERIC(12,3) NOT NULL,
  boxes INTEGER NOT NULL DEFAULT 0,
  price NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_rate SMALLINT NOT NULL DEFAULT 20,
  vat_sum_unit NUMERIC(14,2) NOT NULL DEFAULT 0,
  price_wvat NUMERIC(14,2) NOT NULL DEFAULT 0,
  sum_novat NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_sum NUMERIC(14,2) NOT NULL DEFAULT 0,
  sum_vat NUMERIC(14,2) NOT NULL DEFAULT 0,
  qty_fact NUMERIC(12,3) NOT NULL DEFAULT 0,
  line_status VARCHAR(20) NOT NULL DEFAULT 'ok',
  quality VARCHAR(20) NULL,
  prod_group VARCHAR(100) NULL,
  prod_date DATE NULL,
  supplier_batch VARCHAR(100) NULL,
  -- Отметка решения оператора по позиции: shortage | excess (акт формируется при закрытии).
  deviation VARCHAR(20) NULL
);
CREATE INDEX IF NOT EXISTS idx_ri_receipt ON receipt_items (receipt_id);
CREATE INDEX IF NOT EXISTS idx_ri_sku ON receipt_items (sku);
CREATE INDEX IF NOT EXISTS idx_ri_code ON receipt_items (code);

CREATE TABLE IF NOT EXISTS receipt_operations (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  line_no INTEGER NOT NULL DEFAULT 0,
  sku VARCHAR(50) NULL,
  supplier_batch VARCHAR(100) NULL,
  accept_status VARCHAR(30) NULL,
  accept_date TIMESTAMP NULL,
  receiver_id BIGINT NULL,
  pallet VARCHAR(50) NULL,
  sku_qty INTEGER NOT NULL DEFAULT 0,
  qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  place_status VARCHAR(30) NULL,
  place_date TIMESTAMP NULL,
  storekeeper_id BIGINT NULL,
  cell VARCHAR(50) NULL,
  reason TEXT NULL,
  -- Приёмка без серийных номеров (в форме ТСД вводили «000»).
  no_serial SMALLINT NOT NULL DEFAULT 0,
  -- Причина отбраковки строки (комментарий по строке приёмки).
  reject_reason TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_ro_receipt ON receipt_operations (receipt_id);

CREATE TABLE IF NOT EXISTS receipt_documents (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  name VARCHAR(255) NOT NULL,
  format VARCHAR(10) NOT NULL DEFAULT 'pdf',
  pages INTEGER NOT NULL DEFAULT 1,
  file_path VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS receipt_flags (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  item_id BIGINT NULL,
  flag_type VARCHAR(50) NOT NULL,
  serial_no VARCHAR(100) NULL,
  marking_code TEXT NULL,
  sku VARCHAR(50) NULL,
  receiver VARCHAR(150) NULL,
  flag_date TIMESTAMP NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'годный',
  reason TEXT NULL,
  -- Паллета, на которую серийные номера приняты (для журнала серийных номеров).
  pallet VARCHAR(50) NULL,
  qty NUMERIC(14,3) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS receipt_history (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  position VARCHAR(100) NULL,
  action VARCHAR(50) NOT NULL,
  field VARCHAR(100) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_rh_receipt ON receipt_history (receipt_id);

CREATE TABLE IF NOT EXISTS receipt_marks (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  marking_code VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'expected',
  accepted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (receipt_id, marking_code)
);
CREATE INDEX IF NOT EXISTS idx_rm_receipt ON receipt_marks (receipt_id);
CREATE INDEX IF NOT EXISTS idx_rm_sku ON receipt_marks (sku);

CREATE TABLE IF NOT EXISTS receipt_stock_sync (
  receipt_id BIGINT PRIMARY KEY,
  synced_at TIMESTAMP NOT NULL DEFAULT NOW(),
  payload TEXT NOT NULL
);

-- 02.1: операции приемки (шапка живет в receipts.receipts)
CREATE TABLE IF NOT EXISTS receipt_acceptance (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  acceptor_id BIGINT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  started_at TIMESTAMP NULL,
  finished_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ra_receipt ON receipt_acceptance (receipt_id);
CREATE INDEX IF NOT EXISTS idx_ra_status ON receipt_acceptance (status);

CREATE TABLE IF NOT EXISTS receipt_acceptance_items (
  id BIGSERIAL PRIMARY KEY,
  acceptance_id BIGINT NOT NULL REFERENCES receipt_acceptance (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  plan_qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  diff_qty NUMERIC(12,3) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_rai_acc ON receipt_acceptance_items (acceptance_id);
CREATE INDEX IF NOT EXISTS idx_rai_sku ON receipt_acceptance_items (sku);

-- ТСД-расширения поступлений
CREATE TABLE IF NOT EXISTS tsd_pallets (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL REFERENCES receipts (id) ON DELETE CASCADE,
  sscc VARCHAR(50) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'Заполняется',
  qty_total NUMERIC(12,3) NOT NULL DEFAULT 0,
  weight NUMERIC(12,3) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  closed_at TIMESTAMP NULL,
  UNIQUE (receipt_id, sscc)
);
CREATE TABLE IF NOT EXISTS tsd_crossdock (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL,
  gate_id VARCHAR(50) NULL,
  sscc VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'создана',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS tsd_act_rasx (
  id BIGSERIAL PRIMARY KEY,
  receipt_id BIGINT NOT NULL,
  sku VARCHAR(50) NOT NULL,
  supplier_batch VARCHAR(100) NOT NULL DEFAULT '',
  type VARCHAR(50) NOT NULL,
  qty_diff NUMERIC(12,3) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
-- tsd_stock УДАЛЕНА (дубль receipt_operations): живой факт — SUM(receipt_operations),
-- остаток — product_stock. См. shared/db.php.

-- ============================================================
-- 3. НОМЕНКЛАТУРА (08) + ТОВАРНЫЙ ЗАПАС (08.1)
-- ============================================================
-- products: суперсет MySQL-схемы + дрейф sqlite (need_measure/quality уже были;
-- pallet_type/pallet_height_mm — из реального wms_nomenclature.sqlite).
CREATE TABLE IF NOT EXISTS products (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  sku VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  category VARCHAR(100) NOT NULL DEFAULT '',
  brand VARCHAR(100) NULL,
  owner VARCHAR(150) NULL,
  warehouse_id BIGINT NULL,
  description TEXT NULL,
  image_path VARCHAR(255) NULL,
  length_mm INTEGER NULL,
  width_mm INTEGER NULL,
  height_mm INTEGER NULL,
  weight_g INTEGER NULL,
  volume_m3 NUMERIC(12,6) NULL,
  qty_in_box INTEGER NULL,
  boxes_per_pallet INTEGER NULL,
  storage_strategy VARCHAR(20) NOT NULL DEFAULT 'FEFO',
  pick_strategy VARCHAR(20) NOT NULL DEFAULT 'FEFO',
  placement_strategy VARCHAR(20) NOT NULL DEFAULT 'FEFO',
  temp_from NUMERIC(5,1) NULL,
  temp_to NUMERIC(5,1) NULL,
  shelf_life_days INTEGER NULL,
  marking_start DATE NULL,
  chz_group VARCHAR(100) NULL,
  max_expiry_pct NUMERIC(5,2) NULL,
  is_batch SMALLINT NOT NULL DEFAULT 0,
  is_expiry SMALLINT NOT NULL DEFAULT 0,
  is_serial SMALLINT NOT NULL DEFAULT 0,
  -- Сканирование серийных номеров на входе/выходе (новые правила).
  -- *_override — точечное решение на артикуле: NULL = наследовать глобальный
  -- флаг контрагента, 0 = снять флаг точечно, 1 = включить принудительно.
  scan_serial_in SMALLINT NOT NULL DEFAULT 0,
  scan_serial_out SMALLINT NOT NULL DEFAULT 0,
  scan_serial_in_override SMALLINT NULL,
  scan_serial_out_override SMALLINT NULL,
  -- Контроль даты производства на приёмке (ТСД) и вывод в товарном запасе.
  control_prod_date SMALLINT NOT NULL DEFAULT 0,
  -- Артикул принадлежит контрагенту (его глобальные флаги наследуются).
  contractor_id BIGINT NULL REFERENCES counterparties (id) ON DELETE SET NULL,
  is_chz SMALLINT NOT NULL DEFAULT 0,
  is_danger SMALLINT NOT NULL DEFAULT 0,
  allow_partial_pick SMALLINT NOT NULL DEFAULT 0,
  need_check SMALLINT NOT NULL DEFAULT 0,
  need_pack SMALLINT NOT NULL DEFAULT 0,
  auto_replenish SMALLINT NOT NULL DEFAULT 0,
  need_measure SMALLINT NOT NULL DEFAULT 0,
  quality VARCHAR(20) NULL,
  pallet_type VARCHAR(10) NULL,
  pallet_height_mm INTEGER NULL,
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  reserved_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  is_active SMALLINT NOT NULL DEFAULT 1,
  is_archived SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_prod_cat ON products (category);
CREATE INDEX IF NOT EXISTS idx_prod_brand ON products (brand);
CREATE INDEX IF NOT EXISTS idx_prod_active ON products (is_active);
CREATE INDEX IF NOT EXISTS idx_prod_name ON products USING gin (name gin_trgm_ops);
CREATE INDEX IF NOT EXISTS idx_prod_sku ON products (sku);

CREATE TABLE IF NOT EXISTS product_barcodes (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products (id) ON DELETE CASCADE,
  barcode VARCHAR(50) NOT NULL UNIQUE
);

-- product_stock: +pallet_type (из 08.1/sqlite-дрейфа).
CREATE TABLE IF NOT EXISTS product_stock (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products (id) ON DELETE CASCADE,
  warehouse_id BIGINT NOT NULL,
  cell VARCHAR(50) NOT NULL,
  pallet VARCHAR(50) NULL,
  pallet_type VARCHAR(10) NULL,
  supplier_batch VARCHAR(100) NULL,
  pallet_date DATE NULL,
  -- Дата производства партии (заполняется на приёмке при контроле даты).
  prod_date DATE NULL,
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  receipt_id BIGINT NULL,
  receipt_no VARCHAR(50) NULL
);
CREATE INDEX IF NOT EXISTS idx_ps_prod ON product_stock (product_id);
CREATE INDEX IF NOT EXISTS idx_ps_wh ON product_stock (warehouse_id);
CREATE INDEX IF NOT EXISTS idx_ps_cell ON product_stock (cell);

CREATE TABLE IF NOT EXISTS product_history (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  field VARCHAR(50) NULL,
  field_label VARCHAR(100) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ph_prod ON product_history (product_id);

CREATE TABLE IF NOT EXISTS product_movements (
  id BIGSERIAL PRIMARY KEY,
  product_id BIGINT NOT NULL REFERENCES products (id) ON DELETE CASCADE,
  doc_date TIMESTAMP NOT NULL,
  doc_type VARCHAR(30) NOT NULL,
  doc_no VARCHAR(50) NOT NULL,
  cell VARCHAR(50) NULL,
  qty_change NUMERIC(14,3) NOT NULL,
  balance NUMERIC(14,3) NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_pm_prod ON product_movements (product_id);

CREATE TABLE IF NOT EXISTS product_defaults (
  id BIGSERIAL PRIMARY KEY,
  field_name VARCHAR(50) NOT NULL UNIQUE,
  field_value VARCHAR(255) NULL,
  updated_by BIGINT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS notifications (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NULL,
  kind VARCHAR(30) NOT NULL DEFAULT 'nomenclature',
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  sku VARCHAR(50) NULL,
  is_important SMALLINT NOT NULL DEFAULT 0,
  is_read SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ntf_user ON notifications (user_id);
CREATE INDEX IF NOT EXISTS idx_ntf_kind ON notifications (kind);
CREATE INDEX IF NOT EXISTS idx_ntf_read ON notifications (is_read);

CREATE TABLE IF NOT EXISTS notification_subscriptions (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL DEFAULT 1,
  event_kind VARCHAR(30) NOT NULL,
  ch_web SMALLINT NOT NULL DEFAULT 0,
  ch_email SMALLINT NOT NULL DEFAULT 0,
  ch_tg SMALLINT NOT NULL DEFAULT 0,
  cond_label VARCHAR(100) NULL,
  is_on SMALLINT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (user_id, event_kind)
);

-- ============================================================
-- 4. ЯЧЕЙКИ ХРАНЕНИЯ (05) + 3D-КАРТА (05.1)
-- ============================================================
CREATE TABLE IF NOT EXISTS storage_cells (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  warehouse_id BIGINT NOT NULL,
  zone VARCHAR(30) NOT NULL DEFAULT 'storage',
  cell_type VARCHAR(30) NOT NULL DEFAULT 'pallet',
  category VARCHAR(100) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'free',
  capacity NUMERIC(14,3) NOT NULL DEFAULT 0,
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  reserved_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  pallet_count INTEGER NOT NULL DEFAULT 0,
  building VARCHAR(20) NULL,
  row_no VARCHAR(20) NULL,
  level_no VARCHAR(20) NULL,
  place_no VARCHAR(20) NULL,
  coord_x INTEGER NULL,
  coord_y INTEGER NULL,
  temp_from NUMERIC(5,1) NULL,
  temp_to NUMERIC(5,1) NULL,
  is_locked SMALLINT NOT NULL DEFAULT 0,
  lock_reason VARCHAR(255) NULL,
  allowed_goods VARCHAR(150) NOT NULL DEFAULT 'Все',
  max_weight_kg NUMERIC(12,3) NULL,
  max_pallets INTEGER NULL,
  beam_width NUMERIC(3,1) NOT NULL DEFAULT 3.2,
  cell_height_mm INTEGER NULL,
  allowed_pallet_types VARCHAR(30) NOT NULL DEFAULT 'euro,fin',
  pallet_type VARCHAR(10) NULL,
  -- Разрешено ли хранить разные товары в одной ячейке.
  allow_mixed_goods SMALLINT NOT NULL DEFAULT 1,
  -- Разрешено ли в ячейке несколько типов паллет (euro/fin).
  allow_multi_pallet_types SMALLINT NOT NULL DEFAULT 1,
  hazmat_allowed SMALLINT NOT NULL DEFAULT 0,
  comment TEXT NULL,
  is_archived SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (code, warehouse_id)
);

CREATE TABLE IF NOT EXISTS cell_content (
  id BIGSERIAL PRIMARY KEY,
  cell_id BIGINT NOT NULL REFERENCES storage_cells (id) ON DELETE CASCADE,
  product_code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  reserved_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  barcode VARCHAR(100) NULL,
  pallet VARCHAR(50) NULL,
  tasks_count INTEGER NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS cell_history (
  id BIGSERIAL PRIMARY KEY,
  cell_id BIGINT NOT NULL REFERENCES storage_cells (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  action VARCHAR(50) NOT NULL,
  field VARCHAR(50) NULL,
  field_label VARCHAR(100) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS cell_movements (
  id BIGSERIAL PRIMARY KEY,
  cell_id BIGINT NOT NULL REFERENCES storage_cells (id) ON DELETE CASCADE,
  doc_date TIMESTAMP NOT NULL,
  doc_type VARCHAR(30) NOT NULL,
  doc_no VARCHAR(100) NOT NULL,
  sku VARCHAR(50) NULL,
  qty_change NUMERIC(14,3) NOT NULL,
  balance NUMERIC(14,3) NOT NULL,
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL
);

-- Дополнительные зоны ячейки (ТСД «Отбор по зоне»). У ячейки несколько зон:
-- manual = Ручной отбор, driver = Отбор водителем.
CREATE TABLE IF NOT EXISTS storage_cell_zones (
  id BIGSERIAL PRIMARY KEY,
  cell_id BIGINT NOT NULL REFERENCES storage_cells (id) ON DELETE CASCADE,
  zone VARCHAR(30) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (cell_id, zone)
);

CREATE TABLE IF NOT EXISTS warehouse_zones (
  id BIGSERIAL PRIMARY KEY,
  warehouse_id BIGINT NOT NULL REFERENCES warehouses (id) ON DELETE CASCADE,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(150) NOT NULL,
  UNIQUE (warehouse_id, code)
);
CREATE TABLE IF NOT EXISTS warehouse_map_objects (
  id BIGSERIAL PRIMARY KEY,
  warehouse_id BIGINT NOT NULL REFERENCES warehouses (id) ON DELETE CASCADE,
  obj_type VARCHAR(30) NOT NULL,
  ref_code VARCHAR(50) NOT NULL,
  x NUMERIC(10,2) NOT NULL DEFAULT 0,
  y NUMERIC(10,2) NOT NULL DEFAULT 0,
  z NUMERIC(10,2) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_wmo_wh ON warehouse_map_objects (warehouse_id);

-- ============================================================
-- 5. ОТГРУЗКИ (03 / 03.1) + УПАКОВКА (03.2) + ПРОВЕРКА (03.3)
-- ============================================================
CREATE TABLE IF NOT EXISTS client_orders (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  contractor_id BIGINT NULL,
  consignee VARCHAR(255) NULL,
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'created',
  warehouse_id BIGINT NULL,
  total NUMERIC(14,2) NOT NULL DEFAULT 0,
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_order_contractor ON client_orders (contractor_id);
CREATE INDEX IF NOT EXISTS idx_order_status ON client_orders (status);

CREATE TABLE IF NOT EXISTS client_order_items (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL REFERENCES client_orders (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty NUMERIC(12,3) NOT NULL,
  prod_date DATE NULL
);
CREATE INDEX IF NOT EXISTS idx_coi_order ON client_order_items (order_id);
CREATE INDEX IF NOT EXISTS idx_coi_sku ON client_order_items (sku);

CREATE TABLE IF NOT EXISTS shipments (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  doc_date TIMESTAMP NOT NULL,
  order_id BIGINT NULL,
  contractor_id BIGINT NOT NULL,
  consignee VARCHAR(255) NULL,
  contract_no VARCHAR(100) NULL,
  warehouse_id BIGINT NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'assembling',
  total NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat_rate SMALLINT NOT NULL DEFAULT 20,
  vat_sum NUMERIC(14,2) NOT NULL DEFAULT 0,
  total_with_vat NUMERIC(14,2) NOT NULL DEFAULT 0,
  paid NUMERIC(14,2) NOT NULL DEFAULT 0,
  debt NUMERIC(14,2) NOT NULL DEFAULT 0,
  items_count INTEGER NOT NULL DEFAULT 0,
  sku_count INTEGER NOT NULL DEFAULT 0,
  qty_total NUMERIC(14,3) NOT NULL DEFAULT 0,
  plan_shipment_date DATE NULL,
  fact_shipment_date DATE NULL,
  plan_delivery_date DATE NULL,
  fact_delivery_date DATE NULL,
  vehicle_arrival TIMESTAMP NULL,
  vehicle_departure TIMESTAMP NULL,
  loading_date TIMESTAMP NULL,
  assembly_start TIMESTAMP NULL,
  assembly_end TIMESTAMP NULL,
  packing_start TIMESTAMP NULL,
  packing_end TIMESTAMP NULL,
  check_start TIMESTAMP NULL,
  check_end TIMESTAMP NULL,
  vehicle VARCHAR(30) NULL,
  gate VARCHAR(20) NULL,
  gates_extra VARCHAR(100) NULL,
  loading_type VARCHAR(30) NULL,
  delivery_type VARCHAR(50) NULL,
  delivery_method VARCHAR(50) NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  loader_id BIGINT NULL,
  ttn VARCHAR(50) NULL,
  responsible_id BIGINT NULL,
  volume NUMERIC(12,3) NOT NULL DEFAULT 0,
  weight NUMERIC(12,3) NOT NULL DEFAULT 0,
  cargo_places INTEGER NOT NULL DEFAULT 0,
  has_diff SMALLINT NOT NULL DEFAULT 0,
  readiness SMALLINT NOT NULL DEFAULT 0,
  recommended_pick VARCHAR(30) NULL,
  launched_pick VARCHAR(30) NULL,
  need_check SMALLINT NOT NULL DEFAULT 0,
  need_pack SMALLINT NOT NULL DEFAULT 0,
  is_kgt SMALLINT NOT NULL DEFAULT 0,
  is_danger SMALLINT NOT NULL DEFAULT 0,
  serial_scan SMALLINT NOT NULL DEFAULT 0,
  marking_scan SMALLINT NOT NULL DEFAULT 0,
  is_crossdock SMALLINT NOT NULL DEFAULT 0,
  is_posted SMALLINT NOT NULL DEFAULT 0,
  comment TEXT NULL,
  created_by BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS shipment_orders (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  order_id BIGINT NOT NULL REFERENCES client_orders (id) ON DELETE RESTRICT,
  status VARCHAR(30) NULL,
  qty_total NUMERIC(12,3) NOT NULL DEFAULT 0,
  collected INTEGER NOT NULL DEFAULT 0,
  checked INTEGER NOT NULL DEFAULT 0,
  packed INTEGER NOT NULL DEFAULT 0,
  shipped INTEGER NOT NULL DEFAULT 0,
  sum_novat NUMERIC(14,2) NOT NULL DEFAULT 0,
  vat NUMERIC(14,2) NOT NULL DEFAULT 0,
  sum_vat NUMERIC(14,2) NOT NULL DEFAULT 0,
  UNIQUE (shipment_id, order_id)
);
-- shipment_items: +prod_date (дрейф реального sqlite).
CREATE TABLE IF NOT EXISTS shipment_items (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  order_id BIGINT NULL,
  code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  qty NUMERIC(12,3) NOT NULL,
  price NUMERIC(14,2) NOT NULL,
  sum NUMERIC(14,2) NOT NULL,
  check_label VARCHAR(50) NULL,
  warehouse_id BIGINT NULL,
  comment TEXT NULL,
  prod_date DATE NULL
);
CREATE TABLE IF NOT EXISTS tsd_picks (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  qty NUMERIC(12,3) NOT NULL DEFAULT 1,
  user_id BIGINT NULL,
  sscc VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS shipment_flags (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  order_id BIGINT NULL,
  item_id BIGINT NULL,
  flag_type VARCHAR(50) NOT NULL,
  serial_no VARCHAR(100) NULL,
  marking_code TEXT NULL,
  aggregation VARCHAR(50) NULL,
  stock_left INTEGER NULL,
  -- Паллета, с которой серийный номер отгружен (для журнала серийных номеров).
  pallet VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS shipment_documents (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  name VARCHAR(255) NOT NULL,
  format VARCHAR(10) NOT NULL DEFAULT 'pdf',
  pages INTEGER NOT NULL DEFAULT 1,
  file_path VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS shipment_history (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  position VARCHAR(100) NULL,
  action VARCHAR(50) NOT NULL,
  field VARCHAR(50) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Реестр марок честного знака по отгрузке (загрузка файлом на документ).
-- Статусы: expected (загружена), accepted (принята), circulated (введена в оборот).
CREATE TABLE IF NOT EXISTS shipment_marks (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  marking_code TEXT NOT NULL,
  prod_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'expected',
  accepted_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (shipment_id, marking_code)
);
CREATE INDEX IF NOT EXISTS idx_sm_shipment ON shipment_marks (shipment_id);
CREATE INDEX IF NOT EXISTS idx_sm_sku ON shipment_marks (sku);

-- Лог операций сортировки отгрузки (ТСД). Хранит только идентификаторы:
-- паллета (SSCC), сотрудник, товар + дата создания SSCC, серийник, честный знак.
CREATE TABLE IF NOT EXISTS shipment_sort_log (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NOT NULL REFERENCES shipments (id) ON DELETE CASCADE,
  pallet_id VARCHAR(50) NULL COMMENT 'SSCC/номер паллеты',
  pallet_created_at TIMESTAMP NULL COMMENT 'Дата создания SSCC',
  user_id BIGINT NULL COMMENT 'id сотрудника',
  item_id BIGINT NULL COMMENT 'id товара (shipment_items.id)',
  serial_no VARCHAR(100) NULL COMMENT 'Серийный номер',
  marking_code TEXT NULL COMMENT 'Честный знак (марка)',
  qty NUMERIC(12,3) NOT NULL DEFAULT 1,
  operation VARCHAR(30) NOT NULL DEFAULT 'sort' COMMENT 'sort|crossdock|mark|serial',
  reason VARCHAR(100) NULL COMMENT 'Причина: марка не принята / не введена в оборот и т.п.',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_ssl_shipment ON shipment_sort_log (shipment_id);
CREATE INDEX IF NOT EXISTS idx_ssl_pallet ON shipment_sort_log (pallet_id);
CREATE INDEX IF NOT EXISTS idx_ssl_user ON shipment_sort_log (user_id);

CREATE TABLE IF NOT EXISTS packing_tasks (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  order_id BIGINT NULL REFERENCES client_orders (id) ON DELETE SET NULL,
  shipment_id BIGINT NULL REFERENCES shipments (id) ON DELETE SET NULL,
  packer_id BIGINT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  parcels INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pack_status ON packing_tasks (status);

CREATE TABLE IF NOT EXISTS packing_task_items (
  id BIGSERIAL PRIMARY KEY,
  packing_id BIGINT NOT NULL REFERENCES packing_tasks (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  parcel_no VARCHAR(30) NULL
);
CREATE INDEX IF NOT EXISTS idx_pti_pack ON packing_task_items (packing_id);

CREATE TABLE IF NOT EXISTS order_checks (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  order_id BIGINT NULL REFERENCES client_orders (id) ON DELETE SET NULL,
  checker_id BIGINT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS order_check_items (
  id BIGSERIAL PRIMARY KEY,
  check_id BIGINT NOT NULL REFERENCES order_checks (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  plan_qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(12,3) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_ochi_chk ON order_check_items (check_id);

-- ============================================================
-- 6. ВНУТРИСКЛАДСКИЕ ОПЕРАЦИИ (04) + ИНВЕНТАРИЗАЦИЯ (06)
-- ============================================================
CREATE TABLE IF NOT EXISTS internal_ops (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  op_type VARCHAR(30) NOT NULL DEFAULT 'move',
  warehouse_id BIGINT NULL REFERENCES warehouses (id) ON DELETE SET NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_io_type ON internal_ops (op_type);

CREATE TABLE IF NOT EXISTS internal_op_items (
  id BIGSERIAL PRIMARY KEY,
  op_id BIGINT NOT NULL REFERENCES internal_ops (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL
);
CREATE INDEX IF NOT EXISTS idx_ioi_op ON internal_op_items (op_id);

CREATE TABLE IF NOT EXISTS inventories (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  warehouse_id BIGINT NOT NULL REFERENCES warehouses (id) ON DELETE RESTRICT,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS inventory_items (
  id BIGSERIAL PRIMARY KEY,
  inventory_id BIGINT NOT NULL REFERENCES inventories (id) ON DELETE CASCADE,
  sku VARCHAR(50) NOT NULL,
  cell VARCHAR(50) NULL,
  plan_qty NUMERIC(12,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(12,3) NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_invi_inv ON inventory_items (inventory_id);

-- ============================================================
-- 7. ЗАДАНИЯ: демо-имена (tasks/*) + прод-имена (wms_*)
-- ============================================================
-- tasks: суперсет (MySQL-черновик + дрейф sqlite: pick_mode/gate/shipment_ids; field_label).
CREATE TABLE IF NOT EXISTS tasks (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  type VARCHAR(20) NOT NULL DEFAULT 'сбор',
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  warehouse_id BIGINT NOT NULL,
  doc_ref VARCHAR(50) NULL,
  order_ref VARCHAR(50) NULL,
  executor_id BIGINT NULL,
  executor_fio VARCHAR(150) NULL,
  plan_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  progress INTEGER NOT NULL DEFAULT 0,
  cells_count INTEGER NOT NULL DEFAULT 0,
  volume NUMERIC(12,3) NOT NULL DEFAULT 0,
  weight NUMERIC(12,3) NOT NULL DEFAULT 0,
  equipment VARCHAR(50) NULL,
  flags TEXT NULL,
  need_check SMALLINT NOT NULL DEFAULT 0,
  comment TEXT NULL,
  due_date DATE NULL,
  done_at TIMESTAMP NULL,
  paused_at TIMESTAMP NULL,
  pick_mode VARCHAR(30) NULL,
  gate VARCHAR(20) NULL,
  shipment_ids VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS task_items (
  id BIGSERIAL PRIMARY KEY,
  task_id BIGINT NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
  code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL,
  pallet VARCHAR(50) NULL,
  plan_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  state VARCHAR(30) NULL,
  executor_fio VARCHAR(150) NULL
);
CREATE TABLE IF NOT EXISTS task_history (
  id BIGSERIAL PRIMARY KEY,
  task_id BIGINT NOT NULL REFERENCES tasks (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  action VARCHAR(50) NOT NULL,
  field VARCHAR(50) NULL,
  field_label VARCHAR(100) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS tsd_task_locks (
  task_id BIGINT PRIMARY KEY,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'work',
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS tsd_task_logs (
  id BIGSERIAL PRIMARY KEY,
  task_id BIGINT NULL,
  cell VARCHAR(50) NULL,
  sku VARCHAR(50) NULL,
  user_id BIGINT NULL,
  reason TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS tsd_otbor_locks (
  shipment_id BIGINT PRIMARY KEY,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'work',
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS tsd_otbor_logs (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NULL,
  cell VARCHAR(50) NULL,
  sku VARCHAR(50) NULL,
  user_id BIGINT NULL,
  reason TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Списки заданий (ТСД «Отбор по зоне»): код PK-000001, PK-000002 (буквы не меняются).
-- В списке — одна отгрузка (нарядов может быть несколько);
-- type = manual (ручной отбор) | driver (отбор водителем) — по зоне ячеек.
CREATE TABLE IF NOT EXISTS task_lists (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(255) NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'manual',
  shipment_id BIGINT NULL,
  shipment_doc_no VARCHAR(50) NULL,
  zone VARCHAR(30) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  created_by BIGINT NULL,
  created_by_fio VARCHAR(150) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS task_list_items (
  id BIGSERIAL PRIMARY KEY,
  list_id BIGINT NOT NULL REFERENCES task_lists (id) ON DELETE CASCADE,
  task_id BIGINT NOT NULL,
  seq INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (list_id, task_id)
);

CREATE TABLE IF NOT EXISTS wms_stock (
  id BIGSERIAL PRIMARY KEY,
  sku VARCHAR(50) NOT NULL,
  code VARCHAR(50) NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  warehouse_id BIGINT NOT NULL,
  cell VARCHAR(50) NULL,
  pallet VARCHAR(50) NULL,
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  reserved_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (sku, warehouse_id, cell, pallet)
);
CREATE INDEX IF NOT EXISTS idx_wms_stock_sku ON wms_stock (sku);
CREATE TABLE IF NOT EXISTS wms_tasks (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL UNIQUE,
  type VARCHAR(20) NOT NULL DEFAULT 'отбор',
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  warehouse_id BIGINT NOT NULL,
  consignee VARCHAR(255) NULL,
  gate VARCHAR(20) NULL,
  doc_ref VARCHAR(50) NULL,
  order_ref VARCHAR(50) NULL,
  executor_id BIGINT NULL,
  executor_fio VARCHAR(150) NULL,
  plan_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  progress SMALLINT NOT NULL DEFAULT 0,
  cells_count INTEGER NOT NULL DEFAULT 0,
  volume NUMERIC(12,3) NOT NULL DEFAULT 0,
  weight NUMERIC(12,3) NOT NULL DEFAULT 0,
  equipment VARCHAR(50) NULL,
  flags VARCHAR(255) NULL,
  need_check SMALLINT NOT NULL DEFAULT 0,
  comment TEXT NULL,
  due_date DATE NULL,
  done_at TIMESTAMP NULL,
  paused_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_wms_tasks_status ON wms_tasks (status);
CREATE TABLE IF NOT EXISTS wms_task_items (
  id BIGSERIAL PRIMARY KEY,
  task_id BIGINT NOT NULL REFERENCES wms_tasks (id) ON DELETE CASCADE,
  code VARCHAR(50) NULL,
  sku VARCHAR(50) NOT NULL,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(10) NOT NULL DEFAULT 'шт',
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL,
  pallet VARCHAR(50) NULL,
  plan_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  fact_qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  state VARCHAR(30) NULL,
  executor_fio VARCHAR(150) NULL
);
CREATE TABLE IF NOT EXISTS wms_task_history (
  id BIGSERIAL PRIMARY KEY,
  task_id BIGINT NOT NULL REFERENCES wms_tasks (id) ON DELETE CASCADE,
  user_id BIGINT NULL,
  action VARCHAR(50) NOT NULL,
  field VARCHAR(50) NULL,
  old_value VARCHAR(255) NULL,
  new_value VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ============================================================
-- 8. БИЛЛИНГ (09.1), СДЕЛКИ (10.2), KPI (10.1, 13.4)
-- ============================================================
CREATE TABLE IF NOT EXISTS billing_accounts (
  id BIGSERIAL PRIMARY KEY,
  counterparty_id BIGINT NOT NULL REFERENCES counterparties (id) ON DELETE RESTRICT,
  balance NUMERIC(14,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (counterparty_id)
);

CREATE TABLE IF NOT EXISTS billing_transactions (
  id BIGSERIAL PRIMARY KEY,
  account_id BIGINT NOT NULL REFERENCES billing_accounts (id) ON DELETE CASCADE,
  amount NUMERIC(14,2) NOT NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_bt_acc ON billing_transactions (account_id);

CREATE TABLE IF NOT EXISTS deals (
  id BIGSERIAL PRIMARY KEY,
  doc_no VARCHAR(30) NOT NULL UNIQUE,
  counterparty_id BIGINT NULL REFERENCES counterparties (id) ON DELETE SET NULL,
  manager_id BIGINT NULL,
  stage VARCHAR(30) NOT NULL DEFAULT 'new',
  total NUMERIC(14,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS kpi_accruals (
  id BIGSERIAL PRIMARY KEY,
  employee_id BIGINT NOT NULL REFERENCES employees (id) ON DELETE CASCADE,
  period DATE NOT NULL,
  indicator VARCHAR(80) NOT NULL,
  amount NUMERIC(14,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_kpia_emp ON kpi_accruals (employee_id);
CREATE INDEX IF NOT EXISTS idx_kpia_period ON kpi_accruals (period);

CREATE TABLE IF NOT EXISTS kpi_indicators (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS kpi_rules (
  id BIGSERIAL PRIMARY KEY,
  indicator_id BIGINT NOT NULL REFERENCES kpi_indicators (id) ON DELETE CASCADE,
  formula VARCHAR(255) NOT NULL DEFAULT '',
  is_enabled SMALLINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_kpir_ind ON kpi_rules (indicator_id);

-- ============================================================
-- 9. ОТЧЕТЫ (11) + ДАШБОРД (00)
-- ============================================================
CREATE TABLE IF NOT EXISTS reports (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS report_runs (
  id BIGSERIAL PRIMARY KEY,
  report_id BIGINT NOT NULL REFERENCES reports (id) ON DELETE CASCADE,
  params JSONB NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'done',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_rr_rep ON report_runs (report_id);

CREATE TABLE IF NOT EXISTS dashboard_kpi_cache (
  id BIGSERIAL PRIMARY KEY,
  metric VARCHAR(80) NOT NULL,
  period DATE NOT NULL,
  value NUMERIC(14,2) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  UNIQUE (metric, period)
);
CREATE INDEX IF NOT EXISTS idx_dash_period ON dashboard_kpi_cache (period);

-- ============================================================
-- 10. НАСТРОЙКИ (13)
-- ============================================================
CREATE TABLE IF NOT EXISTS organization (
  id BIGINT PRIMARY KEY DEFAULT 1,
  short_name VARCHAR(255) NOT NULL DEFAULT 'ООО "Лучшее решение"',
  full_name VARCHAR(500) NOT NULL DEFAULT 'Общество с ограниченной ответственностью Лучшее решение',
  ogrn_text VARCHAR(500) NULL,
  bank_name VARCHAR(255) NULL,
  inn VARCHAR(20) NULL,
  okpo VARCHAR(20) NULL,
  kpp VARCHAR(20) NULL,
  oktmo VARCHAR(20) NULL,
  bik VARCHAR(20) NULL,
  okato VARCHAR(20) NULL,
  rs VARCHAR(30) NULL,
  ks VARCHAR(30) NULL,
  ur_addr VARCHAR(500) NULL,
  post_addr VARCHAR(500) NULL,
  fact_addr VARCHAR(500) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  director VARCHAR(500) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS warehouse_schedule (
  id BIGSERIAL PRIMARY KEY,
  warehouse_id BIGINT NOT NULL REFERENCES warehouses (id) ON DELETE CASCADE,
  weekday SMALLINT NOT NULL,
  work_from TIME NULL,
  work_to TIME NULL,
  break_from TIME NULL,
  break_to TIME NULL,
  is_closed SMALLINT NOT NULL DEFAULT 0,
  UNIQUE (warehouse_id, weekday)
);

CREATE TABLE IF NOT EXISTS settings_history (
  id BIGSERIAL PRIMARY KEY,
  changed_at TIMESTAMP NOT NULL DEFAULT NOW(),
  user_fio VARCHAR(150) NOT NULL DEFAULT 'Половинкин Сергей',
  entity VARCHAR(100) NOT NULL,
  field_name VARCHAR(150) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_sh_at ON settings_history (changed_at);
CREATE INDEX IF NOT EXISTS idx_sh_entity ON settings_history (entity);

CREATE TABLE IF NOT EXISTS storage_zones (
  id BIGSERIAL PRIMARY KEY,
  warehouse_id BIGINT NULL REFERENCES warehouses (id) ON DELETE SET NULL,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  is_active SMALLINT NOT NULL DEFAULT 1,
  purpose VARCHAR(255) NULL,
  affects_placement SMALLINT NOT NULL DEFAULT 0,
  affects_reservation SMALLINT NOT NULL DEFAULT 0,
  contractor_id BIGINT NULL,
  is_virtual SMALLINT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
ALTER TABLE storage_zones ADD COLUMN IF NOT EXISTS purpose VARCHAR(255) NULL;
ALTER TABLE storage_zones ADD COLUMN IF NOT EXISTS affects_placement SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE storage_zones ADD COLUMN IF NOT EXISTS affects_reservation SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE storage_zones ADD COLUMN IF NOT EXISTS contractor_id BIGINT NULL;
ALTER TABLE storage_zones ADD COLUMN IF NOT EXISTS is_virtual SMALLINT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS cell_types (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  is_active SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS pallet_types (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  length_mm INTEGER NULL,
  width_mm INTEGER NULL,
  height_mm INTEGER NULL,
  max_weight_kg NUMERIC(10,2) NULL,
  is_active SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS lpn_categories (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  is_active SMALLINT NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS storage_rules (
  id BIGSERIAL PRIMARY KEY,
  kind VARCHAR(30) NOT NULL,
  name VARCHAR(255) NOT NULL,
  payload JSONB NULL,
  is_enabled SMALLINT NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS idx_rule_kind ON storage_rules (kind);

-- Ворота (бронирование) из 13-nastroyki. Переименованы в settings_*,
-- чтобы не конфликтовать с каноническими gates/gate_slots из 01.2.
CREATE TABLE IF NOT EXISTS settings_gates (
  id BIGSERIAL PRIMARY KEY,
  warehouse VARCHAR(100) NOT NULL DEFAULT 'Основной склад',
  gate_no INTEGER NOT NULL,
  slot_interval INTEGER NOT NULL DEFAULT 1,
  slot_unit VARCHAR(10) NOT NULL DEFAULT 'час',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  UNIQUE (warehouse, gate_no)
);
CREATE INDEX IF NOT EXISTS idx_sgate_wh ON settings_gates (warehouse);

CREATE TABLE IF NOT EXISTS settings_gate_docks (
  id BIGSERIAL PRIMARY KEY,
  gate_id BIGINT NOT NULL REFERENCES settings_gates (id) ON DELETE CASCADE,
  dock_no VARCHAR(20) NOT NULL,
  UNIQUE (gate_id, dock_no)
);

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(100) PRIMARY KEY,
  v TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- ============================================================
-- 11. АВТОМАТИЗАЦИЯ (13.1, 13.2) + ПЕЧАТЬ (13.3)
-- ============================================================
CREATE TABLE IF NOT EXISTS auto_invoice_rules (
  id BIGSERIAL PRIMARY KEY,
  kind VARCHAR(30) NOT NULL,
  name VARCHAR(255) NOT NULL,
  days VARCHAR(20) NOT NULL DEFAULT '1,3,5',
  times VARCHAR(255) NOT NULL DEFAULT '15:00,21:00',
  interval_h INTEGER NULL,
  min_vol NUMERIC(10,2) NULL,
  min_weight NUMERIC(10,2) NULL,
  max_vol NUMERIC(10,2) NULL,
  max_weight NUMERIC(10,2) NULL,
  max_attach INTEGER NULL,
  days_before INTEGER NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  last_run_at TIMESTAMP NULL
);
CREATE INDEX IF NOT EXISTS idx_air_kind ON auto_invoice_rules (kind);
CREATE INDEX IF NOT EXISTS idx_air_name ON auto_invoice_rules (name);
CREATE INDEX IF NOT EXISTS idx_air_status ON auto_invoice_rules (status);

CREATE TABLE IF NOT EXISTS auto_invoice_runs (
  id BIGSERIAL PRIMARY KEY,
  rule_id BIGINT NOT NULL REFERENCES auto_invoice_rules (id) ON DELETE CASCADE,
  doc_ref VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ok',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_air_rule ON auto_invoice_runs (rule_id);

CREATE TABLE IF NOT EXISTS auto_invoice_history (
  id BIGSERIAL PRIMARY KEY,
  rule_id BIGINT NOT NULL REFERENCES auto_invoice_rules (id) ON DELETE CASCADE,
  changed_at TIMESTAMP NOT NULL DEFAULT NOW(),
  user_fio VARCHAR(150) NOT NULL DEFAULT 'Ермолов Михаил',
  field_name VARCHAR(150) NOT NULL,
  old_value TEXT NULL,
  new_value TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_aih_rule ON auto_invoice_history (rule_id);

CREATE TABLE IF NOT EXISTS auto_bill_rules (
  id BIGSERIAL PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  is_enabled SMALLINT NOT NULL DEFAULT 1,
  payload JSONB NULL
);

CREATE TABLE IF NOT EXISTS auto_bill_runs (
  id BIGSERIAL PRIMARY KEY,
  rule_id BIGINT NOT NULL REFERENCES auto_bill_rules (id) ON DELETE CASCADE,
  doc_ref VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'ok',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_abr_rule ON auto_bill_runs (rule_id);

CREATE TABLE IF NOT EXISTS print_templates (
  id BIGSERIAL PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS print_jobs (
  id BIGSERIAL PRIMARY KEY,
  template_id BIGINT NOT NULL REFERENCES print_templates (id) ON DELETE RESTRICT,
  doc_ref VARCHAR(50) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pj_tpl ON print_jobs (template_id);

-- ============================================================
-- 12. ПОЛЬЗОВАТЕЛЬСКИЕ КОЛОНКИ
-- ============================================================
CREATE TABLE IF NOT EXISTS user_columns (
  user_key VARCHAR(100) NOT NULL,
  form VARCHAR(50) NOT NULL,
  cols TEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
  PRIMARY KEY (user_key, form)
);

-- ============================================================
-- 13. ДОТЯЖКА СТАРЫХ БД (дрейф sqlite/MySQL) — безопасно на повторный прогон
-- ============================================================
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS code VARCHAR(20);
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS wh_type VARCHAR(100) NOT NULL DEFAULT 'Распределительный';
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS address VARCHAR(500);
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS timezone VARCHAR(50) NOT NULL DEFAULT '(UTC+3) Москва';
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active';
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT NOW();
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT NOW();
ALTER TABLE users ADD COLUMN IF NOT EXISTS position VARCHAR(100);
ALTER TABLE users ADD COLUMN IF NOT EXISTS pin VARCHAR(10);
ALTER TABLE products ADD COLUMN IF NOT EXISTS need_measure SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS quality VARCHAR(20);
ALTER TABLE products ADD COLUMN IF NOT EXISTS pallet_type VARCHAR(10);
ALTER TABLE products ADD COLUMN IF NOT EXISTS pallet_height_mm INTEGER;
ALTER TABLE product_stock ADD COLUMN IF NOT EXISTS pallet_type VARCHAR(10);
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS pick_mode VARCHAR(30);
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS gate VARCHAR(20);
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS shipment_ids VARCHAR(255);
-- task_history.field_label уже в CREATE выше; для старых БД:
ALTER TABLE task_history ADD COLUMN IF NOT EXISTS field_label VARCHAR(100);
ALTER TABLE shipment_items ADD COLUMN IF NOT EXISTS prod_date DATE;
ALTER TABLE shipments ADD COLUMN IF NOT EXISTS is_crossdock SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS qty_fact NUMERIC(12,3) NOT NULL DEFAULT 0;
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS line_status VARCHAR(20) NOT NULL DEFAULT 'ok';
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS quality VARCHAR(20);
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS prod_group VARCHAR(100);
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS prod_date DATE;
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS supplier_batch VARCHAR(100);
ALTER TABLE receipt_operations ADD COLUMN IF NOT EXISTS supplier_batch VARCHAR(100);
ALTER TABLE receipt_operations ADD COLUMN IF NOT EXISTS reason TEXT;
ALTER TABLE receipt_items ADD COLUMN IF NOT EXISTS deviation VARCHAR(20);
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS excess_qty NUMERIC(14,3) NOT NULL DEFAULT 0;
ALTER TABLE receipts ADD COLUMN IF NOT EXISTS shortage_qty NUMERIC(14,3) NOT NULL DEFAULT 0;
ALTER TABLE receipt_flags ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'годный';
ALTER TABLE receipt_flags ADD COLUMN IF NOT EXISTS reason TEXT;
ALTER TABLE storage_cells ADD COLUMN IF NOT EXISTS beam_width NUMERIC(3,1) NOT NULL DEFAULT 3.2;
ALTER TABLE storage_cells ADD COLUMN IF NOT EXISTS cell_height_mm INTEGER;
ALTER TABLE storage_cells ADD COLUMN IF NOT EXISTS allowed_pallet_types VARCHAR(30) NOT NULL DEFAULT 'euro,fin';
ALTER TABLE storage_cells ADD COLUMN IF NOT EXISTS pallet_type VARCHAR(10);

-- --- Сканирование серийных номеров, дата производства, клиент ---
ALTER TABLE products ADD COLUMN IF NOT EXISTS scan_serial_in SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS scan_serial_out SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS scan_serial_in_override SMALLINT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS scan_serial_out_override SMALLINT NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS control_prod_date SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS contractor_id BIGINT NULL;
ALTER TABLE counterparties ADD COLUMN IF NOT EXISTS reserve_strategy VARCHAR(10) NOT NULL DEFAULT 'FIFO';
ALTER TABLE counterparties ADD COLUMN IF NOT EXISTS scan_serial_in SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE counterparties ADD COLUMN IF NOT EXISTS scan_serial_out SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE counterparties ADD COLUMN IF NOT EXISTS placement_zones VARCHAR(255) NULL;
ALTER TABLE counterparties ADD COLUMN IF NOT EXISTS placement_cell_codes TEXT NULL;
ALTER TABLE storage_cells ADD COLUMN IF NOT EXISTS allow_mixed_goods SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE storage_cells ADD COLUMN IF NOT EXISTS allow_multi_pallet_types SMALLINT NOT NULL DEFAULT 1;
ALTER TABLE product_stock ADD COLUMN IF NOT EXISTS prod_date DATE NULL;
ALTER TABLE receipt_operations ADD COLUMN IF NOT EXISTS no_serial SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE receipt_operations ADD COLUMN IF NOT EXISTS reject_reason TEXT NULL;
ALTER TABLE receipt_flags ADD COLUMN IF NOT EXISTS pallet VARCHAR(50) NULL;
ALTER TABLE receipt_flags ADD COLUMN IF NOT EXISTS qty NUMERIC(14,3) NULL;
ALTER TABLE shipment_flags ADD COLUMN IF NOT EXISTS pallet VARCHAR(50) NULL;

-- ============================================================
-- 13.1. ЖУРНАЛ СКЛАДА + ЖУРНАЛ СЕРИЙНЫХ НОМЕРОВ (модуль 14)
-- ============================================================
-- Журнал склада: все движения паллет и товаров.
CREATE TABLE IF NOT EXISTS wms_journal (
  id BIGSERIAL PRIMARY KEY,
  doc_date TIMESTAMP NOT NULL DEFAULT NOW(),
  op_type VARCHAR(30) NOT NULL,           -- приход/отбор/перемещение/размещение/пополнение/отгрузка
  doc_type VARCHAR(30) NULL,
  doc_no VARCHAR(100) NULL,
  sku VARCHAR(50) NULL,
  product_name VARCHAR(255) NULL,
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  pallet VARCHAR(50) NULL,
  sscc VARCHAR(50) NULL,
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL,
  zone VARCHAR(30) NULL,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  comment TEXT NULL,
  quality VARCHAR(20) NULL DEFAULT 'новый',
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
ALTER TABLE wms_journal ADD COLUMN IF NOT EXISTS quality VARCHAR(20) NULL DEFAULT 'новый';
CREATE INDEX IF NOT EXISTS idx_journal_date ON wms_journal (doc_date);
CREATE INDEX IF NOT EXISTS idx_journal_op ON wms_journal (op_type);
CREATE INDEX IF NOT EXISTS idx_journal_sku ON wms_journal (sku);

-- Журнал серийных номеров: что приняли (Приход) и что отгрузили (Отгрузка).
-- Паллета фиксируется та, на которую серийные номера приняты/отгружены
-- ИЗНАЧАЛЬНО: при расформировании паллеты данные не переписываются.
CREATE TABLE IF NOT EXISTS wms_serial_journal (
  id BIGSERIAL PRIMARY KEY,
  serial_no VARCHAR(100) NOT NULL,
  sku VARCHAR(50) NULL,
  product_name VARCHAR(255) NULL,
  op_type VARCHAR(20) NOT NULL DEFAULT 'Приход',
  receipt_doc_no VARCHAR(100) NULL,
  shipment_doc_no VARCHAR(100) NULL,
  pallet VARCHAR(50) NULL,
  qty NUMERIC(14,3) NOT NULL DEFAULT 1,
  cell VARCHAR(50) NULL,
  doc_date TIMESTAMP NOT NULL DEFAULT NOW(),
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_sj_serial ON wms_serial_journal (serial_no);
CREATE INDEX IF NOT EXISTS idx_sj_op ON wms_serial_journal (op_type);
CREATE INDEX IF NOT EXISTS idx_sj_date ON wms_serial_journal (doc_date);

-- Буфер отбора ТСД: виртуальная промежуточная ячейка пользователя и
-- сборочная паллета (SSCC). Следующий отбор невозможен, пока товар
-- не перемещён на ворота и не собран на сборочной паллете.
CREATE TABLE IF NOT EXISTS tsd_pick_buffers (
  id BIGSERIAL PRIMARY KEY,
  shipment_id BIGINT NULL,
  task_id BIGINT NULL,
  sku VARCHAR(50) NULL,
  product_name VARCHAR(255) NULL,
  qty NUMERIC(14,3) NOT NULL DEFAULT 0,
  user_id BIGINT NULL,
  user_fio VARCHAR(150) NULL,
  buffer_cell VARCHAR(100) NOT NULL,
  assembly_sscc VARCHAR(50) NULL,
  gate VARCHAR(30) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'picked',   -- picked / at_gate / assembled
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pbuf_ship ON tsd_pick_buffers (shipment_id);

-- ============================================================
-- 14. МИНИМАЛЬНЫЕ СИДЫ (идемпотентно)
-- ============================================================
INSERT INTO warehouses (id, name) VALUES (1, 'Основной склад'), (2, 'Холодильный склад')
ON CONFLICT (id) DO NOTHING;

INSERT INTO users (id, fio, role, position) VALUES
  (1, 'Иванов И.И.', 'Администратор', 'Администратор'),
  (2, 'Петров А.С.', 'Кладовщик', 'Приемщик')
ON CONFLICT (id) DO NOTHING;

INSERT INTO contractors (id, name) VALUES (1, 'ООО «СпортОпт»'), (2, 'ООО «Мяч-Сервис»')
ON CONFLICT (id) DO NOTHING;

INSERT INTO organization (id) VALUES (1) ON CONFLICT (id) DO NOTHING;

INSERT INTO settings (k, v) VALUES
  ('wms.db_version', '2'),
  ('wms.seed', 'base')
ON CONFLICT (k) DO NOTHING;

INSERT INTO pallet_types (code, name, length_mm, width_mm, height_mm, max_weight_kg) VALUES
  ('euro', 'EUR 1200x800', 1200, 800, 144, 1500),
  ('fin', 'FIN 1200x1000', 1200, 1000, 138, 1500)
ON CONFLICT (code) DO NOTHING;

INSERT INTO cell_types (code, name) VALUES
  ('pallet', 'Паллетная'), ('shelf', 'Полка'), ('floor', 'Напольная')
ON CONFLICT (code) DO NOTHING;

INSERT INTO storage_zones (code, name) VALUES
  ('storage', 'Хранение'), ('accept', 'Приемка'), ('ship', 'Отгрузка'), ('quarantine', 'Карантин')
ON CONFLICT (code) DO NOTHING;

-- Ячейки приёмки (ворота) DOK-01…DOK-10 — та же таблица ячеек хранения, зона receiving.
INSERT INTO storage_cells (code, warehouse_id, zone, cell_type, status, capacity, comment)
SELECT 'DOK-' || lpad(g::text, 2, '0'),
       (SELECT COALESCE(MIN(id), 1) FROM warehouses),
       'receiving', 'pallet', 'free', 0, 'Ячейка приёмки (ворота)'
FROM generate_series(1, 10) AS g
WHERE NOT EXISTS (
  SELECT 1 FROM storage_cells c WHERE c.code = 'DOK-' || lpad(g::text, 2, '0')
);

-- pin для старых пользователей (как в shared/db.php)
UPDATE users SET pin = LPAD((1000 + id)::text, 4, '0') WHERE pin IS NULL OR pin = '';
