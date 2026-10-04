-- Модуль 10.1 Начисления KPI

CREATE TABLE IF NOT EXISTS kpi_accruals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_id BIGINT UNSIGNED NOT NULL,
  period DATE NOT NULL COMMENT 'месяц',
  indicator VARCHAR(80) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kpia_emp (employee_id),
  KEY idx_kpia_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
