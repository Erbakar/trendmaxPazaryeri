-- Faz 3 / 015 — admin_password_resets: şifre sıfırlama (TEK KULLANIMLIK + süreli; merchant'tan ayrı).
CREATE TABLE IF NOT EXISTS `admin_password_resets` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(10) UNSIGNED NOT NULL,
  `token_hash`    varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at`    datetime NOT NULL,
  `used_at`       datetime DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_apr_token` (`token_hash`),
  KEY `idx_apr_admin` (`admin_user_id`),
  CONSTRAINT `fk_apr_admin` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
