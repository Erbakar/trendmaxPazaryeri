-- ============================================================
-- OzyConnect — Faz 11 / 042 · store_addons pazaryeri add-on desteği
-- Eskiden yalnız modül add-on'u; artık pazaryeri add-on'u da (addon_type + marketplace_id).
-- module_key nullable yapıldı (pazaryeri satırlarında NULL). Idempotent.
-- ============================================================
ALTER TABLE `store_addons`
  ADD COLUMN IF NOT EXISTS `addon_type`     enum('module','marketplace') NOT NULL DEFAULT 'module' AFTER `store_id`,
  ADD COLUMN IF NOT EXISTS `marketplace_id` int(10) unsigned NULL AFTER `module_key`,
  MODIFY COLUMN `module_key` varchar(50) NULL;

-- Mağaza başına bir pazaryeri add-on'u (modül satırlarında marketplace_id NULL → çoklu NULL serbest).
ALTER TABLE `store_addons`
  ADD UNIQUE KEY IF NOT EXISTS `uq_addon_store_marketplace` (`store_id`, `marketplace_id`);
