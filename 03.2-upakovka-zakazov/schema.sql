-- Модуль 3.2 Упаковка заказов

CREATE TABLE IF NOT EXISTS packing_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_no VARCHAR(20) NOT NULL COMMENT 'УПАК-0001',
  order_id BIGINT UNSIGNED NULL,
  shipment_id BIGINT UNSIGNED NULL,
  packer_id BIGINT UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new' COMMENT 'new/work/done',
  parcels INT NOT NULL DEFAULT 0 COMMENT 'мест',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pack_no (doc_no),
  KEY idx_pack_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS packing_task_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  packing_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  parcel_no VARCHAR(30) NULL,
  KEY idx_pti_pack (packing_id),
  CONSTRAINT fk_pti FOREIGN KEY (packing_id) REFERENCES packing_tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
