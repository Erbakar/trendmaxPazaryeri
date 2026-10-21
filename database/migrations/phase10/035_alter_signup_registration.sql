-- Faz 10 / 035 — Kayıt onay modları (admin/email/auto): store_signup_requests
-- e-posta doğrulama token'ı + doğrulama zamanı + kaynak izi. Onay modu ayarı
-- `settings` tablosunda (registration_mode / registration_email_submode) → migration gerekmez.
-- Eklenen kolonlar NULLABLE/DEFAULT'lu → mevcut satırlar bozulmaz. Idempotent (IF NOT EXISTS).
ALTER TABLE `store_signup_requests`
  ADD COLUMN IF NOT EXISTS `verify_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ip`,
  ADD COLUMN IF NOT EXISTS `verified_at`  datetime DEFAULT NULL AFTER `verify_token`,
  ADD COLUMN IF NOT EXISTS `source`       varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public' AFTER `verified_at`;

ALTER TABLE `store_signup_requests`
  MODIFY COLUMN `status` enum('unverified','pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';

ALTER TABLE `store_signup_requests`
  ADD INDEX IF NOT EXISTS `idx_signup_verify` (`verify_token`);
