-- Faz 2 / 012 — Entitlement tablolarına lisans/abonelik yönetimi alanları
-- (granted_by, revoked_by, reason, notes). İleride lisans/abonelik için.
ALTER TABLE `store_marketplaces`
  ADD COLUMN IF NOT EXISTS `granted_by` int(10) UNSIGNED DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `revoked_by` int(10) UNSIGNED DEFAULT NULL AFTER `granted_by`,
  ADD COLUMN IF NOT EXISTS `revoked_at` datetime DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `reason`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `revoked_at`,
  ADD COLUMN IF NOT EXISTS `notes`      text COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `reason`;

ALTER TABLE `store_modules`
  ADD COLUMN IF NOT EXISTS `granted_by` int(10) UNSIGNED DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `revoked_by` int(10) UNSIGNED DEFAULT NULL AFTER `granted_by`,
  ADD COLUMN IF NOT EXISTS `revoked_at` datetime DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN IF NOT EXISTS `reason`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `revoked_at`,
  ADD COLUMN IF NOT EXISTS `notes`      text COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `reason`;
