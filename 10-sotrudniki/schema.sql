-- Модуль 10 Сотрудники
-- Реестр сотрудников, карточка (общие данные), графики, права и роли,
-- история выходов (СКУД) и история изменений.

-- ---------- Реестр сотрудников ----------
-- id = ID_user (идентификатор сотрудника в WMS).
CREATE TABLE IF NOT EXISTS employees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fio VARCHAR(150) NOT NULL COMMENT 'ФИО: Фамилия Имя Отчество',
  last_name VARCHAR(100) NULL,
  first_name VARCHAR(100) NULL,
  middle_name VARCHAR(100) NULL,
  position VARCHAR(100) NULL COMMENT 'Должность',
  code VARCHAR(10) NULL COMMENT 'Код: НС, К, У, Ш, С',
  department VARCHAR(100) NULL COMMENT 'Отдел',
  personnel_no VARCHAR(30) NULL COMMENT 'Табельный номер',
  pin VARCHAR(10) NULL COMMENT 'PIN для ТСД',
  photo_path VARCHAR(255) NULL,
  phone_internal VARCHAR(50) NULL COMMENT 'Телефон (внутренний)',
  phone_mobile VARCHAR(50) NULL COMMENT 'Мобильный телефон',
  email VARCHAR(150) NULL COMMENT 'Почта',
  birth_date DATE NULL,
  hired_at DATE NULL COMMENT 'Дата приема',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active/blocked/fired',
  role VARCHAR(50) NOT NULL DEFAULT 'storekeeper',
  zone VARCHAR(50) NOT NULL DEFAULT 'Все зоны',
  warehouse_id BIGINT UNSIGNED NULL,
  tsd_access TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Доступ в ТСД',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_blocked TINYINT(1) NOT NULL DEFAULT 0,
  allow_unplanned_exit TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Разрешён внеплановый выход',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_pin (pin),
  KEY idx_emp_fio (fio),
  KEY idx_emp_department (department),
  KEY idx_emp_status (status),
  KEY idx_emp_warehouse (warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Графики сотрудника (вкладка «График») ----------
CREATE TABLE IF NOT EXISTS employee_schedules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  date_from DATE NOT NULL COMMENT 'Начало графика',
  date_to DATE NOT NULL COMMENT 'Конец графика',
  pattern VARCHAR(10) NOT NULL COMMENT 'График: 1/3',
  shift VARCHAR(30) NOT NULL COMMENT 'Смена: 1 смена',
  work_from TIME NULL COMMENT 'Начало смены',
  work_to TIME NULL COMMENT 'Конец смены',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_esch_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Права и роли (вкладка «Права и роли») ----------
CREATE TABLE IF NOT EXISTS employee_access (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  scope VARCHAR(10) NOT NULL DEFAULT 'wms' COMMENT 'wms/tsd',
  module_key VARCHAR(50) NOT NULL COMMENT 'Ключ модуля WMS или tsd',
  level VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'full/view/none',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eacc (employee_id, scope, module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- История выходов по СКУД (вкладка «История выходов») ----------
CREATE TABLE IF NOT EXISTS role_access (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role VARCHAR(50) NOT NULL,
  scope VARCHAR(10) NOT NULL DEFAULT 'wms',
  module_key VARCHAR(50) NOT NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'none',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_access (role, scope, module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_exits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  event_at DATETIME NOT NULL COMMENT 'Дата и время события',
  checkpoint VARCHAR(100) NULL COMMENT 'СКУД: проход',
  direction VARCHAR(10) NOT NULL DEFAULT 'in' COMMENT 'in/exit',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eex_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Присутствие за смену (Начало/Конец/Работает/Отработано) ----------
CREATE TABLE IF NOT EXISTS employee_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  start_at TIME NULL,
  end_at TIME NULL,
  worked_minutes INT NOT NULL DEFAULT 0,
  on_shift TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eatt_emp (employee_id),
  KEY idx_eatt_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- История изменений карточки (вкладка «История изменений») ----------
CREATE TABLE IF NOT EXISTS employee_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  user_fio VARCHAR(150) NULL,
  field VARCHAR(50) NOT NULL,
  field_label VARCHAR(100) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ehist_emp (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
