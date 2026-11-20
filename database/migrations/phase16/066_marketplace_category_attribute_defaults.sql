CREATE TABLE IF NOT EXISTS marketplace_category_attribute_defaults (
 store_id INT UNSIGNED NOT NULL,
 marketplace_id INT UNSIGNED NOT NULL,
 marketplace_category_id VARCHAR(100) NOT NULL,
 attribute_id VARCHAR(191) NOT NULL,
 attribute_name VARCHAR(255) NOT NULL DEFAULT '',
 value VARCHAR(500) NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (store_id, marketplace_id, marketplace_category_id, attribute_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
