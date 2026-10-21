-- OzyConnect — Destek (Support) ticket sistemi tabloları
-- Tenant-scoped: her ticket bir store_id'ye aittir; merchant yalnız kendi store'unu görür (BaseModel zorlar).
-- Admin tüm store'ların ticketlarını görür (admin controller ham sorgu kullanır).
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`      BIGINT UNSIGNED NOT NULL,
  `user_id`       BIGINT UNSIGNED NULL,
  `subject`       VARCHAR(200) NOT NULL,
  `status`        ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
  `priority`      ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  `last_reply_at` DATETIME NULL,
  `last_reply_by` ENUM('merchant','admin') NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_st_store` (`store_id`),
  KEY `idx_st_store_status` (`store_id`, `status`),
  KEY `idx_st_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   BIGINT UNSIGNED NOT NULL,
  `store_id`    BIGINT UNSIGNED NOT NULL,
  `sender_type` ENUM('merchant','admin') NOT NULL,
  `sender_id`   BIGINT UNSIGNED NULL,
  `sender_name` VARCHAR(120) NULL,
  `body`        TEXT NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stm_ticket` (`ticket_id`),
  KEY `idx_stm_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
