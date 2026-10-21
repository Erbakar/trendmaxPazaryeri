-- Faz 3 / 018 — system_audit_log admin_users referansı: admin_user_id + actor_type.
-- Admin işlemleri (backup/entitlement/admin auth) admin_users'ı referans alır; merchant user_id ayrı.
ALTER TABLE `system_audit_log`
  ADD COLUMN IF NOT EXISTS `admin_user_id` int(10) UNSIGNED DEFAULT NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `actor_type` enum('merchant','admin','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system' AFTER `admin_user_id`;
