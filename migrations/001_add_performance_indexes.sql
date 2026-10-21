-- ============================================================
-- OzyConnect Panel — Performans Index Migration
-- Oluşturma: 01.05.2026
-- Açıklama: Kritik tablolara composite index ekleme
-- ============================================================

-- ─── marketplace_products ───────────────────────────────────
-- Stok sync ve fiyat hesaplama sorgularında kullanılır
ALTER TABLE `marketplace_products`
  ADD INDEX IF NOT EXISTS `idx_mp_product_marketplace` (`product_id`, `marketplace_id`),
  ADD INDEX IF NOT EXISTS `idx_mp_marketplace_active` (`marketplace_id`, `is_active`),
  ADD INDEX IF NOT EXISTS `idx_mp_marketplace_sku` (`marketplace_sku`);

-- ─── order_items ────────────────────────────────────────────
-- Sipariş detayı ve SKU eşleştirme sorgularında kullanılır
ALTER TABLE `order_items`
  ADD INDEX IF NOT EXISTS `idx_oi_order_id` (`order_id`),
  ADD INDEX IF NOT EXISTS `idx_oi_sku` (`sku`),
  ADD INDEX IF NOT EXISTS `idx_oi_product_id` (`product_id`);

-- ─── stock_movements ────────────────────────────────────────
-- Stok hareket raporları ve iptal/iade işlemlerinde kullanılır
ALTER TABLE `stock_movements`
  ADD INDEX IF NOT EXISTS `idx_sm_product_type` (`product_id`, `movement_type`),
  ADD INDEX IF NOT EXISTS `idx_sm_reference` (`reference_id`),
  ADD INDEX IF NOT EXISTS `idx_sm_created` (`created_at`);

-- ─── financial_transactions ─────────────────────────────────
-- Finans raporları ve duplicate kontrolünde kullanılır
ALTER TABLE `financial_transactions`
  ADD INDEX IF NOT EXISTS `idx_ft_marketplace_date` (`marketplace_id`, `transaction_date`),
  ADD INDEX IF NOT EXISTS `idx_ft_external` (`external_id`);

-- ─── orders ─────────────────────────────────────────────────
-- Sipariş listeleme, filtreleme ve senkronizasyonda kullanılır
ALTER TABLE `orders`
  ADD INDEX IF NOT EXISTS `idx_orders_marketplace_status` (`marketplace_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_orders_mp_order_id` (`marketplace_order_id`),
  ADD INDEX IF NOT EXISTS `idx_orders_created` (`created_at`);

-- ─── returns ────────────────────────────────────────────────
-- İade listeleme ve eşleştirmede kullanılır
ALTER TABLE `returns`
  ADD INDEX IF NOT EXISTS `idx_returns_marketplace` (`marketplace_id`, `status`),
  ADD INDEX IF NOT EXISTS `idx_returns_order` (`order_id`);

-- ─── questions ──────────────────────────────────────────────
-- Soru listeleme ve filtrelemede kullanılır
ALTER TABLE `questions`
  ADD INDEX IF NOT EXISTS `idx_questions_marketplace` (`marketplace_id`, `status`);

-- ─── login_attempts ─────────────────────────────────────────
-- Rate limiting sorgularında kullanılır
ALTER TABLE `login_attempts`
  ADD INDEX IF NOT EXISTS `idx_la_ip_time` (`ip_address`, `attempted_at`);

-- ─── sync_logs ──────────────────────────────────────────────
ALTER TABLE `sync_logs`
  ADD INDEX IF NOT EXISTS `idx_sl_marketplace_type` (`marketplace_id`, `sync_type`);
