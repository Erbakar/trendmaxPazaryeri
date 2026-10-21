-- Faz 3 / 013 — admin_users: platform operatörü kimliği (merchant `users`'tan TAMAMEN AYRI).
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email`         varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `totp_secret`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'base32 TOTP secret',
  `totp_enabled`  tinyint(1) NOT NULL DEFAULT 0,
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
