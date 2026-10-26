-- Модуль 9 Контрагенты и грузополучатели (WMS TiMLog), MySQL 8 / MariaDB
-- Настройки контрагента:
--   reserve_strategy       — принцип резервирования (впуск заданий на отбор): FIFO / FEFO / LIFO;
--   scan_serial_in/out     — глобальные флаги сканирования серийных номеров клиента
--                            (проставляются всем артикулам клиента и всем новым зашедшим товарам);
--   placement_zones        — клиентские зоны размещения (несколько кодов зон через запятую);
--   placement_cell_codes   — клиентские ячейки размещения (коды ячеек через запятую,
--                            приоритет «ближе к этому же клиенту»).

CREATE TABLE IF NOT EXISTS counterparties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  inn VARCHAR(20) NULL,
  ctype VARCHAR(30) NOT NULL DEFAULT 'supplier' COMMENT 'supplier/client/consignee',
  phone VARCHAR(50) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  reserve_strategy VARCHAR(10) NOT NULL DEFAULT 'FIFO' COMMENT 'FIFO/FEFO/LIFO — впуск заданий на отбор',
  scan_serial_in TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Глобальный флаг клиента: сканировать с/н на входе',
  scan_serial_out TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Глобальный флаг клиента: сканировать с/н на выходе',
  placement_zones VARCHAR(255) NULL COMMENT 'Зоны размещения клиента: коды через запятую',
  placement_cell_codes TEXT NULL COMMENT 'Ячейки размещения клиента: коды через запятую',
  FULLTEXT KEY ft_cp (name),
  KEY idx_cp_ctype (ctype),
  KEY idx_cp_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  counterparty_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL COMMENT 'грузополучатель',
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  KEY idx_csg_cp (counterparty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
