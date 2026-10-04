-- Модуль 9 Контрагенты и грузополучатели

CREATE TABLE IF NOT EXISTS counterparties (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  inn VARCHAR(20) NULL,
  ctype VARCHAR(30) NOT NULL DEFAULT 'supplier' COMMENT 'supplier/buyer/carrier',
  phone VARCHAR(50) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FULLTEXT KEY ft_cp (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consignees (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  counterparty_id BIGINT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL COMMENT 'грузополучатель',
  address VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  KEY idx_csg_cp (counterparty_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
