-- Faz 1b / R1 — Sistem denetim günlüğü (GLOBAL; tenant verisi değil — platform operasyonları).
-- Özellikle backup/restore gibi sistem-seviyesi işlemler buraya yazılır (kim, ne zaman, sonuç, IP).
CREATE TABLE IF NOT EXISTS `system_audit_log` (
  `id`          bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) UNSIGNED DEFAULT NULL COMMENT 'işlemi yapan (CLI ise NULL)',
  `actor_email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action`      varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'orn: backup.restore, backup.create, backup.restore.denied',
  `target`      varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'orn: backup #12 / dosya adı',
  `result`      enum('success','denied','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `detail_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `ip_address`  varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sal_action` (`action`),
  KEY `idx_sal_created` (`created_at`),
  KEY `idx_sal_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
