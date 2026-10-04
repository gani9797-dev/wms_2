-- Модуль 1 Реестр прибытия ТС, MySQL 8 / MariaDB

CREATE TABLE IF NOT EXISTS vehicle_arrivals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'ТС-000123',
  vehicle_id BIGINT UNSIGNED NULL,
  plate_no VARCHAR(30) NOT NULL COMMENT 'А221ВА77',
  driver VARCHAR(150) NULL,
  supplier_id BIGINT UNSIGNED NULL,
  arrival_plan DATETIME NULL,
  arrival_fact DATETIME NULL,
  gate VARCHAR(20) NULL COMMENT '11',
  status VARCHAR(30) NOT NULL DEFAULT 'expected' COMMENT 'expected/arrived/unloading/done/cancelled',
  comment TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_varr_no (doc_no),
  KEY idx_varr_plate (plate_no),
  KEY idx_varr_status (status),
  KEY idx_varr_plan (arrival_plan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vehicle_arrival_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arrival_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NULL,
  user_fio VARCHAR(150) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_varrh_arr (arrival_id),
  CONSTRAINT fk_varrh FOREIGN KEY (arrival_id) REFERENCES vehicle_arrivals (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
