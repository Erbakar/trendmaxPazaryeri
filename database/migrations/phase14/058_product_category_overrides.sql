CREATE TABLE IF NOT EXISTS product_marketplace_category_overrides (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL,
 marketplace_id INT UNSIGNED NOT NULL,
 marketplace_category_id VARCHAR(100) NOT NULL,
 marketplace_category_name VARCHAR(255) NOT NULL,
 marketplace_category_path TEXT NULL,
 attribute_mapping_json LONGTEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_product_category_override (store_id, product_id, marketplace_id),
 CONSTRAINT fk_product_category_override_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
