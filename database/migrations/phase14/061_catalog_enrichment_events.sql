CREATE TABLE IF NOT EXISTS catalog_enrichment_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL,
 marketplace_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 before_json LONGTEXT NULL,
 after_json LONGTEXT NOT NULL,
 evidence_json LONGTEXT NOT NULL,
 created_at DATETIME NOT NULL,
 KEY enrichment_product (store_id,marketplace_id,product_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
