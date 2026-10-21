-- Faz 1 / 004 — store_id NOT NULL (DEFAULT YOK: eksik tenant'lı ham INSERT gürültüyle patlasın)
-- + stores(id)'ye FOREIGN KEY (ON DELETE RESTRICT: tenant verisi varken mağaza silinemez; arşivleme status ile).
-- FK, store_id üzerinde uygun index yoksa otomatik index oluşturur.
-- NOT: 003 tüm NULL'ları doldurmuş olmalı; aksi halde MODIFY ... NOT NULL hata verir (kasıtlı emniyet).

-- store_id NOT NULL
ALTER TABLE `products`                     MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `orders`                       MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `order_items`                  MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `customers`                    MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `invoices`                     MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `returns`                      MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `questions`                    MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `stock_movements`              MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `marketplace_products`         MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `brand_marketplace_mapping`    MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `category_marketplace_mapping` MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `category_attributes`          MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `product_attribute_values`     MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `xml_feeds`                    MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `product_import_jobs`          MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `financial_transactions`       MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `price_rules`                  MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `sync_logs`                    MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `sync_jobs`                    MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `enrichment_logs`              MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `brands`                       MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `categories`                   MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `brand_aliases`                MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `api_logs`                     MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `api_tokens`                   MODIFY `store_id` int(10) UNSIGNED NOT NULL;
ALTER TABLE `users`                        MODIFY `store_id` int(10) UNSIGNED NOT NULL;

-- FOREIGN KEY -> stores(id)
ALTER TABLE `products`                     ADD CONSTRAINT `fk_products_store`            FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `orders`                       ADD CONSTRAINT `fk_orders_store`              FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `order_items`                  ADD CONSTRAINT `fk_order_items_store`         FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `customers`                    ADD CONSTRAINT `fk_customers_store`           FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `invoices`                     ADD CONSTRAINT `fk_invoices_store`            FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `returns`                      ADD CONSTRAINT `fk_returns_store`             FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `questions`                    ADD CONSTRAINT `fk_questions_store`           FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `stock_movements`              ADD CONSTRAINT `fk_stock_movements_store`     FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `marketplace_products`         ADD CONSTRAINT `fk_mp_products_store`         FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `brand_marketplace_mapping`    ADD CONSTRAINT `fk_bmm_store`                FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `category_marketplace_mapping` ADD CONSTRAINT `fk_cmm_store`                FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `category_attributes`          ADD CONSTRAINT `fk_category_attributes_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `product_attribute_values`     ADD CONSTRAINT `fk_pav_store`                FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `xml_feeds`                    ADD CONSTRAINT `fk_xml_feeds_store`           FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `product_import_jobs`          ADD CONSTRAINT `fk_import_jobs_store`         FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `financial_transactions`       ADD CONSTRAINT `fk_fin_tx_store`              FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `price_rules`                  ADD CONSTRAINT `fk_price_rules_store`         FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `sync_logs`                    ADD CONSTRAINT `fk_sync_logs_store`           FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `sync_jobs`                    ADD CONSTRAINT `fk_sync_jobs_store`           FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `enrichment_logs`              ADD CONSTRAINT `fk_enrichment_logs_store`     FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `brands`                       ADD CONSTRAINT `fk_brands_store`              FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `categories`                   ADD CONSTRAINT `fk_categories_store`          FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `brand_aliases`                ADD CONSTRAINT `fk_brand_aliases_store`       FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `api_logs`                     ADD CONSTRAINT `fk_api_logs_store`            FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `api_tokens`                   ADD CONSTRAINT `fk_api_tokens_store`          FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `users`                        ADD CONSTRAINT `fk_users_store`               FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
