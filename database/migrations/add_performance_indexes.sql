-- Performance İyileştirme: Eksik İndeksler
-- Sık kullanılan sorgular için indeksler ekleniyor

-- Products tablosu
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_sku` (`sku`);
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_barcode` (`barcode`);
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_category` (`category_id`);
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_brand` (`brand_id`);
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_active_stock` (`is_active`, `stock_qty`);
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_updated` (`updated_at`);

-- Orders tablosu
ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_orders_marketplace_order_id` (`marketplace_order_id`);
ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_orders_customer` (`customer_id`);
ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_orders_status` (`status`);
ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_orders_marketplace` (`marketplace_id`);
ALTER TABLE `orders` ADD INDEX IF NOT EXISTS `idx_orders_created` (`created_at`);

-- API Tokens tablosu
ALTER TABLE `api_tokens` ADD INDEX IF NOT EXISTS `idx_api_tokens_key_active` (`api_key`, `is_active`);

-- Users tablosu
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_email` (`email`);
ALTER TABLE `users` ADD INDEX IF NOT EXISTS `idx_users_active` (`is_active`);

-- Categories tablosu
ALTER TABLE `categories` ADD INDEX IF NOT EXISTS `idx_categories_parent` (`parent_id`);
ALTER TABLE `categories` ADD INDEX IF NOT EXISTS `idx_categories_slug` (`slug`);

-- Brands tablosu
ALTER TABLE `brands` ADD INDEX IF NOT EXISTS `idx_brands_slug` (`slug`);

-- Marketplace Products tablosu
ALTER TABLE `marketplace_products` ADD INDEX IF NOT EXISTS `idx_mp_products_product` (`product_id`);
ALTER TABLE `marketplace_products` ADD INDEX IF NOT EXISTS `idx_mp_products_marketplace` (`marketplace_id`);
ALTER TABLE `marketplace_products` ADD INDEX IF NOT EXISTS `idx_mp_products_mp_sku` (`marketplace_product_id`);

-- API Logs tablosu (performans için)
ALTER TABLE `api_logs` ADD INDEX IF NOT EXISTS `idx_api_logs_created_token` (`created_at`, `api_token_id`);

-- Sync Logs tablosu
ALTER TABLE `sync_logs` ADD INDEX IF NOT EXISTS `idx_sync_logs_type_created` (`sync_type`, `created_at`);
