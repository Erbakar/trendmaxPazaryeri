CREATE TABLE IF NOT EXISTS catalog_audit_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL, marketplace_id INT UNSIGNED NOT NULL,
 status VARCHAR(32) NOT NULL DEFAULT 'preparing', cursor_pos INT NOT NULL DEFAULT 0,
 total INT NOT NULL DEFAULT 0, summary_json LONGTEXT NULL, error_text TEXT NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, finished_at DATETIME NULL,
 KEY audit_run_store (store_id,marketplace_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS catalog_audit_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL, marketplace_id INT UNSIGNED NOT NULL,
 run_id BIGINT UNSIGNED NOT NULL, entity_key VARCHAR(191) NOT NULL,
 product_id INT UNSIGNED NULL, source_product_id INT UNSIGNED NULL,
 sku VARCHAR(255) NOT NULL DEFAULT '', barcode VARCHAR(255) NOT NULL DEFAULT '',
 name VARCHAR(500) NOT NULL DEFAULT '', status VARCHAR(32) NOT NULL,
 checks_json LONGTEXT NOT NULL, expected_json LONGTEXT NULL, remote_json LONGTEXT NULL,
 reviewed_fingerprint CHAR(64) NULL, checked_at DATETIME NOT NULL,
 UNIQUE KEY audit_item_identity (store_id,marketplace_id,entity_key),
 KEY audit_item_status (store_id,marketplace_id,status), KEY audit_item_run (store_id,run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS catalog_content_reviews (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL, marketplace_id INT UNSIGNED NOT NULL,
 product_id INT UNSIGNED NOT NULL, fingerprint CHAR(64) NOT NULL,
 evidence_json LONGTEXT NOT NULL, reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NOT NULL,
 UNIQUE KEY catalog_review (store_id,marketplace_id,product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS catalog_audit_actions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL, marketplace_id INT UNSIGNED NOT NULL,
 run_id BIGINT UNSIGNED NOT NULL, entity_key VARCHAR(191) NOT NULL,
 product_id INT UNSIGNED NULL, operation VARCHAR(32) NOT NULL,
 payload_json LONGTEXT NOT NULL, payload_hash CHAR(64) NOT NULL,
 status VARCHAR(32) NOT NULL, result_json LONGTEXT NULL,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 KEY audit_action_pending (store_id,marketplace_id,entity_key,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
