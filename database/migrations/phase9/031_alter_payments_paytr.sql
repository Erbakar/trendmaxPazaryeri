-- Faz 9 / 031 — payments tablosuna PayTR desteği (additive; mevcut değerler korunur).
-- method enum'una 'paytr' ekle + merchant_oid (PayTR sipariş no) + provider_ref (PayTR payment_id).
ALTER TABLE `payments`
  MODIFY COLUMN `method` enum('havale','eft','manual','card','paytr') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'havale';

ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `merchant_oid` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `bank_ref`,
  ADD COLUMN IF NOT EXISTS `provider_ref` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `merchant_oid`;

-- merchant_oid benzersiz (callback idempotency); NULL'lar serbest.
ALTER TABLE `payments` ADD UNIQUE KEY IF NOT EXISTS `uq_pay_merchant_oid` (`merchant_oid`);
