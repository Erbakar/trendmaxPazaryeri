-- DOWN 005 — store-prefix'li unique key'leri kaldır, ORİJİNAL (global) unique key'leri geri yükle.
-- FK-güvenli sıra: rollback ters sırada (007->001) gittiğinden, store FK'leri burada (004-down'dan
-- ÖNCE) düşürülür; aksi halde store_id-prefix'li composite unique FK'ye bağlı kaldığı için 1553 verir.
-- store FK'leri düşünce store_id-prefix'li unique'ler serbestçe düşürülebilir.

-- 0) ÖNCE tüm store FK'lerini düşür (idempotent; 004-down'da tekrar IF EXISTS no-op olur)
ALTER TABLE `products`                     DROP FOREIGN KEY IF EXISTS `fk_products_store`;
ALTER TABLE `orders`                       DROP FOREIGN KEY IF EXISTS `fk_orders_store`;
ALTER TABLE `order_items`                  DROP FOREIGN KEY IF EXISTS `fk_order_items_store`;
ALTER TABLE `customers`                    DROP FOREIGN KEY IF EXISTS `fk_customers_store`;
ALTER TABLE `invoices`                     DROP FOREIGN KEY IF EXISTS `fk_invoices_store`;
ALTER TABLE `returns`                      DROP FOREIGN KEY IF EXISTS `fk_returns_store`;
ALTER TABLE `questions`                    DROP FOREIGN KEY IF EXISTS `fk_questions_store`;
ALTER TABLE `stock_movements`              DROP FOREIGN KEY IF EXISTS `fk_stock_movements_store`;
ALTER TABLE `marketplace_products`         DROP FOREIGN KEY IF EXISTS `fk_mp_products_store`;
ALTER TABLE `brand_marketplace_mapping`    DROP FOREIGN KEY IF EXISTS `fk_bmm_store`;
ALTER TABLE `category_marketplace_mapping` DROP FOREIGN KEY IF EXISTS `fk_cmm_store`;
ALTER TABLE `category_attributes`          DROP FOREIGN KEY IF EXISTS `fk_category_attributes_store`;
ALTER TABLE `product_attribute_values`     DROP FOREIGN KEY IF EXISTS `fk_pav_store`;
ALTER TABLE `xml_feeds`                    DROP FOREIGN KEY IF EXISTS `fk_xml_feeds_store`;
ALTER TABLE `product_import_jobs`          DROP FOREIGN KEY IF EXISTS `fk_import_jobs_store`;
ALTER TABLE `financial_transactions`       DROP FOREIGN KEY IF EXISTS `fk_fin_tx_store`;
ALTER TABLE `price_rules`                  DROP FOREIGN KEY IF EXISTS `fk_price_rules_store`;
ALTER TABLE `sync_logs`                    DROP FOREIGN KEY IF EXISTS `fk_sync_logs_store`;
ALTER TABLE `sync_jobs`                    DROP FOREIGN KEY IF EXISTS `fk_sync_jobs_store`;
ALTER TABLE `enrichment_logs`              DROP FOREIGN KEY IF EXISTS `fk_enrichment_logs_store`;
ALTER TABLE `brands`                       DROP FOREIGN KEY IF EXISTS `fk_brands_store`;
ALTER TABLE `categories`                   DROP FOREIGN KEY IF EXISTS `fk_categories_store`;
ALTER TABLE `brand_aliases`                DROP FOREIGN KEY IF EXISTS `fk_brand_aliases_store`;
ALTER TABLE `api_logs`                     DROP FOREIGN KEY IF EXISTS `fk_api_logs_store`;
ALTER TABLE `api_tokens`                   DROP FOREIGN KEY IF EXISTS `fk_api_tokens_store`;
ALTER TABLE `users`                        DROP FOREIGN KEY IF EXISTS `fk_users_store`;

-- products
ALTER TABLE `products` DROP INDEX IF EXISTS `uq_products_store_sku`;
ALTER TABLE `products` ADD UNIQUE INDEX IF NOT EXISTS `sku` (`sku`);

-- brands
ALTER TABLE `brands` DROP INDEX IF EXISTS `uq_brands_store_name`;
ALTER TABLE `brands` ADD UNIQUE INDEX IF NOT EXISTS `name` (`name`);

-- brand_aliases
ALTER TABLE `brand_aliases` DROP INDEX IF EXISTS `uq_brand_aliases_store_alias`;
ALTER TABLE `brand_aliases` ADD UNIQUE INDEX IF NOT EXISTS `uq_brand_aliases_alias` (`alias_name`);

-- invoices
ALTER TABLE `invoices` DROP INDEX IF EXISTS `uq_invoices_store_uuid`;
ALTER TABLE `invoices` ADD UNIQUE INDEX IF NOT EXISTS `uq_invoices_uuid` (`invoice_uuid`);

-- orders
ALTER TABLE `orders` DROP INDEX IF EXISTS `uq_orders_store_mp_order`;
ALTER TABLE `orders` ADD UNIQUE INDEX IF NOT EXISTS `uq_orders_mp_order` (`marketplace_id`, `marketplace_order_id`);

-- returns
ALTER TABLE `returns` DROP INDEX IF EXISTS `uq_returns_store_mp_return`;
ALTER TABLE `returns` ADD UNIQUE INDEX IF NOT EXISTS `uq_returns_mp_return` (`marketplace_id`, `marketplace_return_id`);

-- questions
ALTER TABLE `questions` DROP INDEX IF EXISTS `uq_questions_store_mp_qid`;
ALTER TABLE `questions` ADD UNIQUE INDEX IF NOT EXISTS `uk_q_mp_qid` (`marketplace_id`, `marketplace_question_id`);

-- marketplace_products (standalone idx_mp_product orijinal unique geri gelince temizlenir)
ALTER TABLE `marketplace_products` DROP INDEX IF EXISTS `idx_mp_store_sku`;
ALTER TABLE `marketplace_products` DROP INDEX IF EXISTS `idx_mp_store_barcode`;
ALTER TABLE `marketplace_products` DROP INDEX IF EXISTS `uq_mp_store_product_mp`;
ALTER TABLE `marketplace_products` ADD UNIQUE INDEX IF NOT EXISTS `uq_mp_product_mp` (`product_id`, `marketplace_id`);
ALTER TABLE `marketplace_products` DROP INDEX IF EXISTS `idx_mp_product`;

-- brand_marketplace_mapping
ALTER TABLE `brand_marketplace_mapping` DROP INDEX IF EXISTS `uq_bmm_store_brand_mp`;
ALTER TABLE `brand_marketplace_mapping` ADD UNIQUE INDEX IF NOT EXISTS `uq_bmm_brand_mp` (`brand_id`, `marketplace_id`);
ALTER TABLE `brand_marketplace_mapping` DROP INDEX IF EXISTS `idx_bmm_brand`;

-- category_marketplace_mapping
ALTER TABLE `category_marketplace_mapping` DROP INDEX IF EXISTS `uq_cmm_store_cat_mp`;
ALTER TABLE `category_marketplace_mapping` ADD UNIQUE INDEX IF NOT EXISTS `uq_cmm_cat_mp` (`category_id`, `marketplace_id`);
ALTER TABLE `category_marketplace_mapping` DROP INDEX IF EXISTS `idx_cmm_cat`;

-- category_attributes
ALTER TABLE `category_attributes` DROP INDEX IF EXISTS `uq_ca_store_category_attr`;
ALTER TABLE `category_attributes` ADD UNIQUE INDEX IF NOT EXISTS `uq_ca_category_attr` (`category_id`, `attribute_id`);
ALTER TABLE `category_attributes` DROP INDEX IF EXISTS `idx_ca_category`;

-- product_attribute_values (idx_pav_product orijinaldi, korunur)
ALTER TABLE `product_attribute_values` DROP INDEX IF EXISTS `uq_pav_store_product_attr`;
ALTER TABLE `product_attribute_values` ADD UNIQUE INDEX IF NOT EXISTS `uq_pav_product_attr` (`product_id`, `attribute_id`);

-- non-unique store index'leri kaldır
ALTER TABLE `categories` DROP INDEX IF EXISTS `idx_categories_store_slug`;
ALTER TABLE `customers`  DROP INDEX IF EXISTS `idx_customers_store_email`;
ALTER TABLE `customers`  DROP INDEX IF EXISTS `idx_customers_store_phone`;
ALTER TABLE `customers`  DROP INDEX IF EXISTS `idx_customers_store_idno`;
