-- Phase 15 / 064 — Mağazalar arası ortak Trendyol özellik kütüphanesi ("tek kaynak").
-- Trendyol kategori kimliği başına en son kaydedilen kategori özellik seçimi (sabit değer, ürün alanı
-- adı, XML değer eşleştirmesi). cron/attribute_issues.php her mağazaya yalnız o mağazanın karar
-- vermediği özellikler için uygular; mağazanın kendi seçimleri değiştirilmez.
CREATE TABLE IF NOT EXISTS trendyol_attribute_library (
 marketplace_category_id VARCHAR(100) NOT NULL PRIMARY KEY,
 marketplace_category_name VARCHAR(500) NOT NULL DEFAULT '',
 template_json LONGTEXT NOT NULL,
 source_store_id INT UNSIGNED NULL,
 version BIGINT UNSIGNED NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY attribute_library_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
