-- Модуль 1.2 Карта ворот

CREATE TABLE IF NOT EXISTS gates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL COMMENT '11',
  name VARCHAR(150) NOT NULL COMMENT 'Ворота 11 (прием)',
  gate_type VARCHAR(30) NOT NULL DEFAULT 'in' COMMENT 'in/out/universal',
  warehouse_id BIGINT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_gate_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gate_slots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  gate_id BIGINT UNSIGNED NOT NULL,
  arrival_id BIGINT UNSIGNED NULL,
  slot_from DATETIME NOT NULL,
  slot_to DATETIME NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'busy',
  KEY idx_gs_gate (gate_id),
  KEY idx_gs_from (slot_from),
  CONSTRAINT fk_gs_gate FOREIGN KEY (gate_id) REFERENCES gates (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
