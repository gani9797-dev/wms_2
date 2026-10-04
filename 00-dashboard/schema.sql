-- Модуль 0 Дашборд (WMS TiMLog), MySQL 8 / MariaDB
-- Агрегатов нет: дашборд читает receipts/shipments/tasks. Здесь только кэш виджетов.

CREATE TABLE IF NOT EXISTS dashboard_kpi_cache (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  metric VARCHAR(80) NOT NULL COMMENT 'arrived_today, accepting, packing, tasks_work...',
  period DATE NOT NULL COMMENT 'дата расчета',
  value DECIMAL(14,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dash (metric, period),
  KEY idx_dash_period (period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
