-- Модуль 1.1 Справочник ТС

CREATE TABLE IF NOT EXISTS vehicles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plate_no VARCHAR(30) NOT NULL COMMENT 'госномер',
  vehicle_type VARCHAR(50) NULL COMMENT 'фура/газель/контейнер',
  capacity_kg DECIMAL(12,2) NOT NULL DEFAULT 0,
  capacity_m3 DECIMAL(12,3) NOT NULL DEFAULT 0,
  driver VARCHAR(150) NULL,
  phone VARCHAR(50) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_veh_plate (plate_no),
  KEY idx_veh_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
