-- Модуль 2.1 Прием поступлений
-- Шапка поступлений живет в receipts.receipts, здесь — операции приемки.

CREATE TABLE IF NOT EXISTS receipt_acceptance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id BIGINT UNSIGNED NOT NULL COMMENT 'FK -> receipts.id',
  acceptor_id BIGINT UNSIGNED NULL COMMENT 'приемщик',
  status VARCHAR(30) NOT NULL DEFAULT 'new' COMMENT 'new/work/done',
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ra_receipt (receipt_id),
  KEY idx_ra_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS receipt_acceptance_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  acceptance_id BIGINT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL,
  plan_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  fact_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  diff_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  KEY idx_rai_acc (acceptance_id),
  KEY idx_rai_sku (sku),
  CONSTRAINT fk_rai FOREIGN KEY (acceptance_id) REFERENCES receipt_acceptance (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
