-- Faz 1 / 002 — 26 tenant-kapsamlı tabloya store_id ekle (önce NULLABLE; backfill 003'te).
-- store_id INT olduğu için kolon collation'ı yok (sadece char tipleri collation taşır).
-- MariaDB "ADD COLUMN IF NOT EXISTS" ile idempotent.

-- Kök tablolar
ALTER TABLE `products`                     ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `orders`                       ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `customers`                    ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `brands`                       ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `categories`                   ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `invoices`                     ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `returns`                      ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `questions`                    ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `xml_feeds`                    ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `price_rules`                  ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `financial_transactions`       ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `sync_jobs`                     ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `sync_logs`                     ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `enrichment_logs`              ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `product_import_jobs`          ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `marketplace_products`         ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `api_tokens`                   ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `api_logs`                     ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `users`                        ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;

-- Denormalize bağımlılar (store_id parent'tan 003'te miras alınır)
ALTER TABLE `order_items`                  ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `stock_movements`              ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `product_attribute_values`     ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `category_attributes`          ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `brand_aliases`                ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `brand_marketplace_mapping`    ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `category_marketplace_mapping` ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED NULL AFTER `id`;
