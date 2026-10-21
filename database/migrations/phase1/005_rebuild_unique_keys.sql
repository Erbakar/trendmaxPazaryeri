-- Faz 1 / 005 — Global/composite UNIQUE key'leri store_id ile yeniden kur.
-- Böylece farklı mağazalar aynı SKU/marka/sipariş-no'yu kullanabilir; aynı mağaza içinde tekillik korunur.
-- MariaDB "DROP INDEX IF EXISTS" / "ADD ... IF NOT EXISTS" ile idempotent.
-- GÜVENLİ: bu UK'ların hepsi bugün GLOBAL benzersiz; tüm veri store=1'de olduğu için çakışma olmaz.
--
-- ÖNEMLİ (FK index bağımlılığı): Bazı junction tablolarında FK kolonunun TEK destekleyen index'i
-- bu unique. Onu düşürmeden ÖNCE FK kolonuna standalone index eklenir (yoksa MariaDB 1553 verir).
-- (Gerçek index/FK yapısı SHOW CREATE TABLE ile doğrulandı — varsayım değil.)

-- products.sku (FK yok) -> (store_id, sku)
ALTER TABLE `products` DROP INDEX IF EXISTS `sku`;
ALTER TABLE `products` ADD UNIQUE INDEX IF NOT EXISTS `uq_products_store_sku` (`store_id`, `sku`);

-- brands.name (FK yok) -> (store_id, name)
ALTER TABLE `brands` DROP INDEX IF EXISTS `name`;
ALTER TABLE `brands` ADD UNIQUE INDEX IF NOT EXISTS `uq_brands_store_name` (`store_id`, `name`);

-- brand_aliases.alias_name (FK alias_name'de değil) -> (store_id, alias_name)
ALTER TABLE `brand_aliases` DROP INDEX IF EXISTS `uq_brand_aliases_alias`;
ALTER TABLE `brand_aliases` ADD UNIQUE INDEX IF NOT EXISTS `uq_brand_aliases_store_alias` (`store_id`, `alias_name`);

-- invoices.invoice_uuid (FK yok) -> (store_id, invoice_uuid)
ALTER TABLE `invoices` DROP INDEX IF EXISTS `uq_invoices_uuid`;
ALTER TABLE `invoices` ADD UNIQUE INDEX IF NOT EXISTS `uq_invoices_store_uuid` (`store_id`, `invoice_uuid`);

-- orders (marketplace_id FK idx_orders_mp_archived ile destekli -> güvenli)
ALTER TABLE `orders` DROP INDEX IF EXISTS `uq_orders_mp_order`;
ALTER TABLE `orders` ADD UNIQUE INDEX IF NOT EXISTS `uq_orders_store_mp_order` (`store_id`, `marketplace_id`, `marketplace_order_id`);

-- returns (marketplace_id FK idx_returns_marketplace ile destekli -> güvenli)
ALTER TABLE `returns` DROP INDEX IF EXISTS `uq_returns_mp_return`;
ALTER TABLE `returns` ADD UNIQUE INDEX IF NOT EXISTS `uq_returns_store_mp_return` (`store_id`, `marketplace_id`, `marketplace_return_id`);

-- questions (marketplace_id FK idx_q_marketplace ile destekli -> güvenli)
ALTER TABLE `questions` DROP INDEX IF EXISTS `uk_q_mp_qid`;
ALTER TABLE `questions` ADD UNIQUE INDEX IF NOT EXISTS `uq_questions_store_mp_qid` (`store_id`, `marketplace_id`, `marketplace_question_id`);

-- marketplace_products: product_id FK'sinin TEK dayanağı uq_mp_product_mp -> önce standalone index
ALTER TABLE `marketplace_products` ADD INDEX IF NOT EXISTS `idx_mp_product` (`product_id`);
ALTER TABLE `marketplace_products` DROP INDEX IF EXISTS `uq_mp_product_mp`;
ALTER TABLE `marketplace_products` ADD UNIQUE INDEX IF NOT EXISTS `uq_mp_store_product_mp` (`store_id`, `product_id`, `marketplace_id`);
-- marketplace_sku/barcode arama yolu store_id ile (cross-tenant eşleşmeyi önler)
ALTER TABLE `marketplace_products` ADD INDEX IF NOT EXISTS `idx_mp_store_sku` (`store_id`, `marketplace_sku`);
ALTER TABLE `marketplace_products` ADD INDEX IF NOT EXISTS `idx_mp_store_barcode` (`store_id`, `marketplace_barcode`);

-- brand_marketplace_mapping: brand_id FK'sinin tek dayanağı uq_bmm_brand_mp -> standalone önce
ALTER TABLE `brand_marketplace_mapping` ADD INDEX IF NOT EXISTS `idx_bmm_brand` (`brand_id`);
ALTER TABLE `brand_marketplace_mapping` DROP INDEX IF EXISTS `uq_bmm_brand_mp`;
ALTER TABLE `brand_marketplace_mapping` ADD UNIQUE INDEX IF NOT EXISTS `uq_bmm_store_brand_mp` (`store_id`, `brand_id`, `marketplace_id`);

-- category_marketplace_mapping: category_id FK'sinin tek dayanağı uq_cmm_cat_mp -> standalone önce
ALTER TABLE `category_marketplace_mapping` ADD INDEX IF NOT EXISTS `idx_cmm_cat` (`category_id`);
ALTER TABLE `category_marketplace_mapping` DROP INDEX IF EXISTS `uq_cmm_cat_mp`;
ALTER TABLE `category_marketplace_mapping` ADD UNIQUE INDEX IF NOT EXISTS `uq_cmm_store_cat_mp` (`store_id`, `category_id`, `marketplace_id`);

-- category_attributes: category_id FK'sinin tek dayanağı uq_ca_category_attr -> standalone önce
ALTER TABLE `category_attributes` ADD INDEX IF NOT EXISTS `idx_ca_category` (`category_id`);
ALTER TABLE `category_attributes` DROP INDEX IF EXISTS `uq_ca_category_attr`;
ALTER TABLE `category_attributes` ADD UNIQUE INDEX IF NOT EXISTS `uq_ca_store_category_attr` (`store_id`, `category_id`, `attribute_id`);

-- product_attribute_values: product_id FK zaten idx_pav_product ile destekli -> güvenli
ALTER TABLE `product_attribute_values` DROP INDEX IF EXISTS `uq_pav_product_attr`;
ALTER TABLE `product_attribute_values` ADD UNIQUE INDEX IF NOT EXISTS `uq_pav_store_product_attr` (`store_id`, `product_id`, `attribute_id`);

-- categories.slug: global tekillik garanti edilemediğinden NON-UNIQUE store index
ALTER TABLE `categories` ADD INDEX IF NOT EXISTS `idx_categories_store_slug` (`store_id`, `slug`);

-- customers: dedup aramaları için store-prefix'li NON-UNIQUE index'ler
ALTER TABLE `customers` ADD INDEX IF NOT EXISTS `idx_customers_store_email` (`store_id`, `email`);
ALTER TABLE `customers` ADD INDEX IF NOT EXISTS `idx_customers_store_phone` (`store_id`, `phone`);
ALTER TABLE `customers` ADD INDEX IF NOT EXISTS `idx_customers_store_idno`  (`store_id`, `id_no`);

-- xml_feeds.token: feed URL'i mağaza bilinmeden çözülebilmeli -> GLOBAL benzersiz KALIR.
-- users.email: 1 e-posta = 1 hesap (D1) -> GLOBAL benzersiz KALIR.
