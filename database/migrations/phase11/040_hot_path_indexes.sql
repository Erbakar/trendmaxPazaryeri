-- ============================================================
-- OzyConnect — Faz 11 / 040 · Sıcak yol kompozit index'leri
-- Tarih: 2026-06-18 (denetim Sprint 1 / Q10)
-- Amaç: Yaygın liste/dashboard sorgularında EXPLAIN'de gözlemlenen
--       tam tablo taramalarını kompozit index'lerle hızlandır.
-- Güvenlik: Tümü idempotent (ADD INDEX IF NOT EXISTS); birden çok
--           kez çalıştırılabilir; mevcut index'leri etkilemez.
-- ============================================================

-- ─── marketplace_products ──────────────────────────────────
-- "Aktif yayınları marketplace bazında ara" sorguları:
--   WHERE marketplace_id = ? AND marketplace_sku = ? AND is_active = 1
-- ve "son eklenen ürünler" gösterimi.
ALTER TABLE `marketplace_products`
  ADD INDEX IF NOT EXISTS `idx_mp_marketplace_sku_active` (`marketplace_id`, `marketplace_sku`, `is_active`),
  ADD INDEX IF NOT EXISTS `idx_mp_created_at` (`created_at`);

-- ─── sync_logs ─────────────────────────────────────────────
-- "Son N başarısız/başarılı senkronizasyon" panel sorgusu:
--   WHERE marketplace_id = ? AND status = ? ORDER BY started_at DESC
ALTER TABLE `sync_logs`
  ADD INDEX IF NOT EXISTS `idx_sl_marketplace_status_started` (`marketplace_id`, `status`, `started_at`);

-- ─── api_logs ──────────────────────────────────────────────
-- "Son 5xx hataları" + "Status dağılımı" sorguları:
--   WHERE status_code >= 500 ORDER BY created_at DESC
ALTER TABLE `api_logs`
  ADD INDEX IF NOT EXISTS `idx_al_status_created` (`status_code`, `created_at`);

-- ─── stock_movements ───────────────────────────────────────
-- Ürün detay > stok defteri sayfası:
--   WHERE product_id = ? ORDER BY created_at DESC
ALTER TABLE `stock_movements`
  ADD INDEX IF NOT EXISTS `idx_sm_product_created` (`product_id`, `created_at`);
