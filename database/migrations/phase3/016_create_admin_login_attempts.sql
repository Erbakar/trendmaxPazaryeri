-- Faz 3 / 016 — admin_login_attempts: giriş/IP/cihaz/başarısız-giriş logları (admin).
CREATE TABLE IF NOT EXISTS `admin_login_attempts` (
  `id`            bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_user_id` int(10) UNSIGNED DEFAULT NULL,
  `email`         varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address`    varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent`    varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'cihaz/tarayıcı',
  `success`       tinyint(1) NOT NULL DEFAULT 0,
  `reason`        varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'orn: bad_password, bad_2fa, ok',
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ala_email` (`email`),
  KEY `idx_ala_created` (`created_at`),
  KEY `idx_ala_admin` (`admin_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
