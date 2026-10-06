-- Модуль 13 Настройки (TL Модуль Настройки, версия от 11.09.2026)
-- Хаб: Организация и склады / Справочники / Номенклатура / Склад и хранение /
-- Поступления / Реестр прибытия ТС / Ворота и бронирование / Заказы / Отгрузки /
-- Задания / Инвентаризация / Персонал / KPI / Биллинг / Документы и нумерация /
-- Уведомления / ТСД и сканирование / Интеграция / Пользователи и права /
-- Автоматизация / Отчеты / Журнал изменений / Системные параметры

-- 1. Организация (одна запись, id=1)
CREATE TABLE IF NOT EXISTS organization (
  id BIGINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  short_name VARCHAR(255) NOT NULL DEFAULT 'ООО "Лучшее решение"',
  full_name VARCHAR(500) NOT NULL DEFAULT 'Общество с ограниченной ответственностью Лучшее решение',
  ogrn_text VARCHAR(500) NULL COMMENT 'ОГРН №, дата, кем выдано',
  bank_name VARCHAR(255) NULL,
  inn VARCHAR(20) NULL,
  okpo VARCHAR(20) NULL,
  kpp VARCHAR(20) NULL,
  oktmo VARCHAR(20) NULL,
  bik VARCHAR(20) NULL,
  okato VARCHAR(20) NULL,
  rs VARCHAR(30) NULL COMMENT 'р/с',
  ks VARCHAR(30) NULL COMMENT 'к/с',
  ur_addr VARCHAR(500) NULL COMMENT 'Юридический адрес',
  post_addr VARCHAR(500) NULL,
  fact_addr VARCHAR(500) NULL COMMENT 'Фактический адрес',
  phone VARCHAR(50) NULL,
  email VARCHAR(150) NULL,
  director VARCHAR(500) NULL COMMENT 'ФИО руководителя и должность',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Склады (Список складов: Код / Наименование / Тип / Адрес / Статус)
CREATE TABLE IF NOT EXISTS warehouses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL COMMENT 'BD2, BD6',
  name VARCHAR(255) NOT NULL COMMENT 'Холодный склад, Основной склад',
  wh_type VARCHAR(100) NOT NULL DEFAULT 'Распределительный' COMMENT 'Температурный / Распределительный',
  address VARCHAR(500) NULL,
  timezone VARCHAR(50) NOT NULL DEFAULT '(UTC+3) Москва',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active / inactive',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wh_code (code),
  KEY idx_wh_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Режим работы склада (ПН..ВС: Время работы / Перерыв / Закрыто)
CREATE TABLE IF NOT EXISTS warehouse_schedule (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id BIGINT UNSIGNED NOT NULL,
  weekday TINYINT NOT NULL COMMENT '1=ПН .. 7=ВС',
  work_from TIME NULL COMMENT 'на схеме 00:00',
  work_to TIME NULL COMMENT 'на схеме 23:59 (в макете опечатка 29:59)',
  break_from TIME NULL,
  break_to TIME NULL,
  is_closed TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sched_wh_day (warehouse_id, weekday),
  CONSTRAINT fk_sched_wh FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. История изменений (Дата / Пользователь / Что изменилось / Было / Стало)
CREATE TABLE IF NOT EXISTS settings_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_fio VARCHAR(150) NOT NULL DEFAULT 'Половинкин Сергей',
  entity VARCHAR(100) NOT NULL COMMENT 'Склад / Организация / Ворота ...',
  field_name VARCHAR(150) NOT NULL COMMENT 'Склад: Режим работы, Склад: Адрес ...',
  old_value TEXT NULL,
  new_value TEXT NULL,
  KEY idx_sh_at (changed_at),
  KEY idx_sh_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Склад и хранение: зоны / типы ячеек / типы паллет / категории LPN
CREATE TABLE IF NOT EXISTS storage_zones (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id BIGINT UNSIGNED NULL,
  code VARCHAR(20) NOT NULL,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_zone_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cell_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cell_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pallet_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(255) NOT NULL,
  length_mm INT NULL,
  width_mm INT NULL,
  height_mm INT NULL,
  max_weight_kg DECIMAL(10,2) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_pallet_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lpn_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_lpn_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS storage_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL COMMENT 'placement / move / replenish / pick / invent',
  name VARCHAR(255) NOT NULL,
  payload JSON NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_rule_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Ворота и бронирование (Ворота № / Доки / Интервал слотов / Ед.изм / Статус)
CREATE TABLE IF NOT EXISTS gates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse VARCHAR(100) NOT NULL DEFAULT 'Основной склад' COMMENT 'Основной склад / Холодный склад',
  gate_no INT NOT NULL,
  slot_interval INT NOT NULL DEFAULT 1,
  slot_unit VARCHAR(10) NOT NULL DEFAULT 'час' COMMENT 'час / минута',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active / inactive',
  UNIQUE KEY uq_gate_wh_no (warehouse, gate_no),
  KEY idx_gate_wh (warehouse)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gate_docks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gate_id BIGINT UNSIGNED NOT NULL,
  dock_no VARCHAR(20) NOT NULL COMMENT '1.1, 1.2',
  UNIQUE KEY uq_dock_gate_no (gate_id, dock_no),
  CONSTRAINT fk_dock_gate FOREIGN KEY (gate_id) REFERENCES gates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Простые key-value системные параметры (остальные разделы настроек)
CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(100) NOT NULL PRIMARY KEY COMMENT 'ключ',
  v TEXT NULL COMMENT 'значение',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
