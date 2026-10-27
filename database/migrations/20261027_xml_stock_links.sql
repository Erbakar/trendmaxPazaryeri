-- Inventory ownership only: never changes listing prices, categories or images.
CREATE TABLE IF NOT EXISTS marketplace_stock_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    store_id INT UNSIGNED NOT NULL,
    marketplace_id INT UNSIGNED NOT NULL,
    barcode VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    marketplace_sku VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    xml_profile_id INT UNSIGNED NOT NULL,
    source_sku VARCHAR(255) COLLATE utf8mb4_bin NOT NULL,
    legacy_product_id INT UNSIGNED NULL,
    match_method VARCHAR(60) NOT NULL,
    evidence_json LONGTEXT NULL,
    observed_quantity INT NULL,
    observed_on_sale TINYINT NULL,
    observed_at DATETIME NULL,
    blocked_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stock_link (store_id, marketplace_id, barcode),
    KEY ix_stock_source (store_id, xml_profile_id),
    KEY ix_stock_legacy (store_id, legacy_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trendyol_stock_receipts (
    store_id INT UNSIGNED NOT NULL,
    barcode VARCHAR(191) COLLATE utf8mb4_bin NOT NULL,
    quantity INT NOT NULL,
    batch_id VARCHAR(191) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_at DATETIME NULL,
    PRIMARY KEY (store_id, barcode),
    KEY ix_stock_receipt (store_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
