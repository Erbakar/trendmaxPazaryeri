-- Faz 9 / 034 — subscription_invoices'a amaç (kind) + meta ekle (additive).
-- kind: subscription (normal yenileme/aktivasyon) · upgrade (paket yükseltme) · addon (modül add-on).
-- meta_json: upgrade→{"package_id":N} · addon→{"module_key":"orders"}. Onayda PaymentConfirmationService dispatch eder.
ALTER TABLE `subscription_invoices`
  ADD COLUMN IF NOT EXISTS `kind` enum('subscription','upgrade','addon') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'subscription' AFTER `subscription_id`,
  ADD COLUMN IF NOT EXISTS `meta_json` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `kind`;
