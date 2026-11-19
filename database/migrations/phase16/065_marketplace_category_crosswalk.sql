-- Phase 16 / 065 — Pazaryerleri arası kategori karşılık tablosu (ör. Trendyol → Hepsiburada).
-- Kategori ağaçları tüm mağazalar için aynı olduğundan tablo GLOBALDİR: bir mağazada onaylanan
-- karşılık diğer mağazalara öneri olarak gelir. target_category_id = '' → "bu kategoriyi gönderme".
CREATE TABLE IF NOT EXISTS marketplace_category_crosswalk (
 source_marketplace_id INT UNSIGNED NOT NULL,
 source_category_id VARCHAR(100) NOT NULL,
 target_marketplace_id INT UNSIGNED NOT NULL,
 target_category_id VARCHAR(100) NOT NULL DEFAULT '',
 target_category_name VARCHAR(500) NOT NULL DEFAULT '',
 target_category_path TEXT NULL,
 method VARCHAR(20) NOT NULL DEFAULT 'oneri',
 confirmed TINYINT(1) NOT NULL DEFAULT 0,
 score SMALLINT NOT NULL DEFAULT 0,
 updated_by_store_id INT UNSIGNED NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (source_marketplace_id, source_category_id, target_marketplace_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
