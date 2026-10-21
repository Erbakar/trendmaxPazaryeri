-- ──────────────────────────────────────────────────────
-- Şifre Sıfırlama Tablosu — password_resets
-- Bu migration, tablo yoksa oluşturur.
-- Tablo zaten ozyconnect.sql'de tanımlı; bu dosya
-- güncelleme/yeni kurulum güvencesi olarak tutulur.
-- ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pr_email` (`email`),
  KEY `idx_pr_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
