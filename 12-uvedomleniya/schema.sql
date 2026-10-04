-- Модуль 12 "Уведомления" (WMS TiMLog), MySQL 8 / MariaDB
-- По схеме 8 v31: колокол в шапке, панель (Все / Непрочитанные / Важные),
-- "Настройка уведомлений" (Мои подписки / Каналы и расписание), стр. "Уведомления"
-- с группировкой Сегодня / Вчера и панелью "Детали уведомления".
-- Фактическое хранилище демо — таблицы в БД модуля 8
-- (08-nomenklatura/schema.sql: notifications, notification_subscriptions),
-- здесь зеркало структуры для prod-развёртывания.
-- Каналы Web / Email / Telegram пока только хранят предпочтения подписок.

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL COMMENT 'NULL = всем',
  kind VARCHAR(30) NOT NULL DEFAULT 'nomenclature' COMMENT 'receipt/shipment/task/nomenclature',
  title VARCHAR(255) NOT NULL,
  body TEXT NULL,
  sku VARCHAR(50) NULL COMMENT 'Артикул для связки с номенклатурой',
  is_important TINYINT(1) NOT NULL DEFAULT 0,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ntf_user (user_id),
  KEY idx_ntf_kind (kind),
  KEY idx_ntf_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notification_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  event_kind VARCHAR(30) NOT NULL COMMENT 'receipt/shipment/task/nomenclature',
  ch_web TINYINT(1) NOT NULL DEFAULT 0,
  ch_email TINYINT(1) NOT NULL DEFAULT 0,
  ch_tg TINYINT(1) NOT NULL DEFAULT 0,
  cond_label VARCHAR(100) NULL COMMENT 'Все статусы / Расхождение, Отменено / Просрочено / Любое изменение',
  is_on TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_nsub (user_id, event_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
