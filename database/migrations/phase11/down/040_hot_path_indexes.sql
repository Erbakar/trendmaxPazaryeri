-- ============================================================
-- OzyConnect — Faz 11 / 040 · DOWN · Sıcak yol index'lerini kaldır
-- ============================================================

ALTER TABLE `marketplace_products`
  DROP INDEX IF EXISTS `idx_mp_marketplace_sku_active`,
  DROP INDEX IF EXISTS `idx_mp_created_at`;

ALTER TABLE `sync_logs`
  DROP INDEX IF EXISTS `idx_sl_marketplace_status_started`;

ALTER TABLE `api_logs`
  DROP INDEX IF EXISTS `idx_al_status_created`;

ALTER TABLE `stock_movements`
  DROP INDEX IF EXISTS `idx_sm_product_created`;
