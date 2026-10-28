CREATE TABLE IF NOT EXISTS catalog_supplier_pages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL,
 profile_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 source_hash CHAR(64) NOT NULL,
 status VARCHAR(32) NOT NULL,
 page_json LONGTEXT NULL,
 facts_hash CHAR(64) NULL,
 applied_source_hash CHAR(64) NULL,
 applied_facts_hash CHAR(64) NULL,
 error_text TEXT NULL,
 product_seen_at DATETIME NULL,
 checked_at DATETIME NOT NULL,
 next_check_at DATETIME NOT NULL,
 UNIQUE KEY supplier_product (store_id,product_id),
 KEY supplier_due (store_id,profile_id,next_check_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
