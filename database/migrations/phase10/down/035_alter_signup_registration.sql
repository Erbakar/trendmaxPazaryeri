-- Faz 10 / 035 geri alma — eklenen kolonlar + index + enum daraltma.
ALTER TABLE `store_signup_requests` DROP INDEX IF EXISTS `idx_signup_verify`;

ALTER TABLE `store_signup_requests`
  DROP COLUMN IF EXISTS `verify_token`,
  DROP COLUMN IF EXISTS `verified_at`,
  DROP COLUMN IF EXISTS `source`;

ALTER TABLE `store_signup_requests`
  MODIFY COLUMN `status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';
