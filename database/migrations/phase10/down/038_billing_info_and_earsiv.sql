-- Faz 10 / 038 geri alma.
ALTER TABLE `stores`
  DROP COLUMN IF EXISTS `legal_name`,
  DROP COLUMN IF EXISTS `tax_id`,
  DROP COLUMN IF EXISTS `tax_office`,
  DROP COLUMN IF EXISTS `billing_address`,
  DROP COLUMN IF EXISTS `billing_district`,
  DROP COLUMN IF EXISTS `billing_city`;
ALTER TABLE `subscription_invoices`
  DROP COLUMN IF EXISTS `earsiv_uuid`,
  DROP COLUMN IF EXISTS `earsiv_status`,
  DROP COLUMN IF EXISTS `earsiv_at`;
