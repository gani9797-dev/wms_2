-- Модуль 4 Внутрискладские операции

CREATE TABLE IF NOT EXISTS internal_ops (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'ВП-0001',
  op_type VARCHAR(30) NOT NULL DEFAULT 'move' COMMENT 'move/replenish/writeoff',
  warehouse_id BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_io_no (doc_no),
  KEY idx_io_type (op_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS internal_op_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  op_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  cell_from VARCHAR(50) NULL,
  cell_to VARCHAR(50) NULL,
  KEY idx_ioi_op (op_id),
  CONSTRAINT fk_ioi FOREIGN KEY (op_id) REFERENCES internal_ops (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
