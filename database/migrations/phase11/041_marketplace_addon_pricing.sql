-- ============================================================
-- OzyConnect — Faz 11 / 041 · Pazaryeri add-on fiyatlandırması
-- marketplaces tablosuna add-on aylık fiyatı + satılabilirlik.
-- Modül kataloğunun (module_catalog) pazaryeri karşılığı; "Pazaryeri Kataloğu" ekranı bunu yönetir.
-- Güvenlik: idempotent (ADD COLUMN IF NOT EXISTS).
-- ============================================================
ALTER TABLE `marketplaces`
  ADD COLUMN IF NOT EXISTS `price_monthly` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `is_sellable`   tinyint(1)    NOT NULL DEFAULT 1    AFTER `price_monthly`;
