-- OzyConnect — Güncelleme (changelog) sistemi tabloları
-- app_updates: GLOBAL (tenant-scoped DEĞİL) — platform admini yayınlar, tüm mağazalar görür.
-- update_reads: kişi-başı "görüldü" takibi (user_id) → nav'da okunmamış rozeti.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS `app_updates` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`      VARCHAR(40) NULL,
  `title`        VARCHAR(200) NOT NULL,
  `body`         MEDIUMTEXT NULL,
  `type`         ENUM('feature','fix','improvement','announcement') NOT NULL DEFAULT 'announcement',
  `is_published` TINYINT(1) NOT NULL DEFAULT 1,
  `published_at` DATETIME NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_au_pub` (`is_published`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `update_reads` (
  `user_id`      BIGINT UNSIGNED NOT NULL,
  `last_seen_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `seen_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
