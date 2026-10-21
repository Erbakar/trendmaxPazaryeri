-- Faz 1 / 003 — Mevcut tüm satırları varsayılan mağazaya (store_id=1) taşı.
-- Önce kök tablolar, sonra denormalize bağımlılar (parent'tan JOIN ile).
-- Idempotent: yalnızca store_id IS NULL satırları günceller.

-- 1) Kök tablolar -> store 1
UPDATE `products`               SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `orders`                 SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `customers`              SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `brands`                 SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `categories`             SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `invoices`               SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `returns`                SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `questions`              SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `xml_feeds`              SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `price_rules`            SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `financial_transactions` SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `sync_jobs`              SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `sync_logs`              SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `enrichment_logs`        SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `product_import_jobs`    SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `marketplace_products`   SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `api_tokens`             SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `api_logs`               SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `users`                  SET `store_id` = 1 WHERE `store_id` IS NULL;

-- 2) Denormalize bağımlılar -> parent'ın store_id'si
UPDATE `order_items` oi
  JOIN `orders` o ON oi.`order_id` = o.`id`
  SET oi.`store_id` = o.`store_id`
  WHERE oi.`store_id` IS NULL;

UPDATE `stock_movements` sm
  JOIN `products` p ON sm.`product_id` = p.`id`
  SET sm.`store_id` = p.`store_id`
  WHERE sm.`store_id` IS NULL;

UPDATE `product_attribute_values` pav
  JOIN `products` p ON pav.`product_id` = p.`id`
  SET pav.`store_id` = p.`store_id`
  WHERE pav.`store_id` IS NULL;

UPDATE `category_attributes` ca
  JOIN `categories` c ON ca.`category_id` = c.`id`
  SET ca.`store_id` = c.`store_id`
  WHERE ca.`store_id` IS NULL;

UPDATE `brand_aliases` ba
  JOIN `brands` b ON ba.`brand_id` = b.`id`
  SET ba.`store_id` = b.`store_id`
  WHERE ba.`store_id` IS NULL;

UPDATE `brand_marketplace_mapping` bmm
  JOIN `brands` b ON bmm.`brand_id` = b.`id`
  SET bmm.`store_id` = b.`store_id`
  WHERE bmm.`store_id` IS NULL;

UPDATE `category_marketplace_mapping` cmm
  JOIN `categories` c ON cmm.`category_id` = c.`id`
  SET cmm.`store_id` = c.`store_id`
  WHERE cmm.`store_id` IS NULL;

-- 3) Güvenlik süpürmesi: parent'ı bulunamayan (yetim) satır kaldıysa store 1'e sabitle
--    (004'teki NOT NULL kısıtının patlamaması için).
UPDATE `order_items`              SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `stock_movements`          SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `product_attribute_values` SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `category_attributes`      SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `brand_aliases`            SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `brand_marketplace_mapping`    SET `store_id` = 1 WHERE `store_id` IS NULL;
UPDATE `category_marketplace_mapping` SET `store_id` = 1 WHERE `store_id` IS NULL;
