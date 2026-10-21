-- Faz 9 / 031 down
ALTER TABLE `payments` DROP KEY IF EXISTS `uq_pay_merchant_oid`;
ALTER TABLE `payments` DROP COLUMN IF EXISTS `provider_ref`, DROP COLUMN IF EXISTS `merchant_oid`;
ALTER TABLE `payments` MODIFY COLUMN `method` enum('havale','eft','manual','card') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'havale';
