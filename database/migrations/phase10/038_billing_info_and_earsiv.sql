-- Faz 10 / 038 — SaaS faturalama için: mağaza fatura/vergi bilgileri + abonelik faturasında e-Arşiv izleme.
--   stores.*               : alıcı (mağaza) vergi bilgileri — e-Arşiv faturası kesmek için zorunlu alanlar
--   subscription_invoices.*: kesilen e-Arşiv faturasının izi (uuid/durum/tarih)
ALTER TABLE `stores`
  ADD COLUMN IF NOT EXISTS `legal_name`        varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `tax_id`            varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `legal_name`,
  ADD COLUMN IF NOT EXISTS `tax_office`        varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `tax_id`,
  ADD COLUMN IF NOT EXISTS `billing_address`   varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `tax_office`,
  ADD COLUMN IF NOT EXISTS `billing_district`  varchar(80)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `billing_address`,
  ADD COLUMN IF NOT EXISTS `billing_city`      varchar(80)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `billing_district`;

ALTER TABLE `subscription_invoices`
  ADD COLUMN IF NOT EXISTS `earsiv_uuid`   varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `earsiv_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `earsiv_uuid`,
  ADD COLUMN IF NOT EXISTS `earsiv_at`     datetime DEFAULT NULL AFTER `earsiv_status`;
