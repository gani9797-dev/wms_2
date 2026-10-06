-- Модуль 13.1 Автоформирование накладных (TL, версия от 04.09.2026)
-- Экран: реестр правил (ID / Тип / Наименование / День недели / Время /
-- Интервал, ч / min-max объем м3 / min-max вес / Max вложение / До доставки / Дата создания / Статус)
-- + фильтры, модалки создания/просмотра/редактирования, история изменений, анализ.

CREATE TABLE IF NOT EXISTS auto_invoice_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL COMMENT 'Грузополучатель / Контрагент',
  name VARCHAR(255) NOT NULL COMMENT 'ПЭК, Почта России, ИП Смирнова, Яндекс.Маркет ...',
  days VARCHAR(20) NOT NULL DEFAULT '1,3,5' COMMENT 'дни недели 1=ПН..7=ВС через запятую',
  times VARCHAR(255) NOT NULL DEFAULT '15:00,21:00' COMMENT 'времена запуска через запятую',
  interval_h INT NULL COMMENT 'Интервал, ч (NULL = без повтора)',
  min_vol DECIMAL(10,2) NULL COMMENT 'Min объем, м3',
  min_weight DECIMAL(10,2) NULL COMMENT 'Min вес заказа',
  max_vol DECIMAL(10,2) NULL COMMENT 'Max объем, м3',
  max_weight DECIMAL(10,2) NULL COMMENT 'Max вес заказа',
  max_attach INT NULL COMMENT 'Мах вложение в заказ',
  days_before INT NULL COMMENT 'Дней до доставки (не более)',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active / inactive',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата создания',
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Дата последнего изменения',
  last_run_at DATETIME NULL COMMENT 'Дата последнего формирования',
  KEY idx_air_kind (kind),
  KEY idx_air_name (name),
  KEY idx_air_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auto_invoice_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id BIGINT UNSIGNED NOT NULL,
  doc_ref VARCHAR(50) NULL COMMENT 'номер созданной накладной, напр. 10004378О',
  status VARCHAR(30) NOT NULL DEFAULT 'ok',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_air_rule (rule_id),
  CONSTRAINT fk_air_rule FOREIGN KEY (rule_id) REFERENCES auto_invoice_rules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auto_invoice_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id BIGINT UNSIGNED NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Дата',
  user_fio VARCHAR(150) NOT NULL DEFAULT 'Ермолов Михаил' COMMENT 'Пользователь',
  field_name VARCHAR(150) NOT NULL COMMENT 'Что изменилось',
  old_value TEXT NULL COMMENT 'Было',
  new_value TEXT NULL COMMENT 'Стало',
  KEY idx_aih_rule (rule_id),
  KEY idx_aih_at (changed_at),
  CONSTRAINT fk_aih_rule FOREIGN KEY (rule_id) REFERENCES auto_invoice_rules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
