-- Faz 3 / 014 — admin_recovery_codes: 2FA kurtarma kodları (TEK KULLANIMLIK; used_at set -> geçersiz).
CREATE TABLE IF NOT EXISTS `admin_recovery_codes` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(10) UNSIGNED NOT NULL,
  `code_hash`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `used_at`       datetime DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_arc_admin` (`admin_user_id`),
  CONSTRAINT `fk_arc_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
