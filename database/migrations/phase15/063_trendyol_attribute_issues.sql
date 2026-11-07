-- Phase 15 / 063 — Zorunlu Trendyol özelliği eksik / XML değeri eşleşmeyen ürünlerin kategori bazlı özeti.
-- Rapor kalıcı bir önbellektir: kaynak veri category_marketplace_mapping + ürün özellikleridir; tablo
-- silinip yeniden hesaplanabilir. Destek paneli bu özeti salt okunur API ile çeker.
CREATE TABLE IF NOT EXISTS trendyol_attribute_issues (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 store_id INT UNSIGNED NOT NULL,
 marketplace_id INT UNSIGNED NOT NULL,
 mapping_key VARCHAR(64) NOT NULL,
 category_id INT UNSIGNED NULL,
 category_name VARCHAR(255) NOT NULL DEFAULT '',
 marketplace_category_id VARCHAR(100) NOT NULL DEFAULT '',
 marketplace_category_name VARCHAR(500) NOT NULL DEFAULT '',
 issue_type VARCHAR(20) NOT NULL,
 attribute_id VARCHAR(32) NOT NULL DEFAULT '',
 attribute_name VARCHAR(255) NOT NULL DEFAULT '',
 value_text VARCHAR(300) NOT NULL DEFAULT '',
 is_varianter TINYINT(1) NOT NULL DEFAULT 0,
 product_count INT UNSIGNED NOT NULL DEFAULT 0,
 checked_count INT UNSIGNED NOT NULL DEFAULT 0,
 sample_skus TEXT NULL,
 message VARCHAR(1000) NOT NULL DEFAULT '',
 refreshed_at DATETIME NOT NULL,
 KEY attribute_issue_mapping (store_id, marketplace_id, mapping_key),
 KEY attribute_issue_type (store_id, marketplace_id, issue_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
