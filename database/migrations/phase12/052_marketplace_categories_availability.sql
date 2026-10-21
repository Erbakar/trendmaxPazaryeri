-- ============================================================
-- Migration: marketplace_categories satış durumu kolonları
--   is_active    — pazaryeri kategori durumu ACTIVE mi (NULL: bilinmiyor)
--   is_available — pazaryerinde satışa açık mı (NULL: bilinmiyor)
--
-- Hepsiburada kategori senkronu (InitialImportService::saveFlatCategories →
-- MarketplaceCategory::bulkUpsert) doldurur; okuma tarafı NULL'u "uygun" sayar
-- (MarketplaceCategory::sellableCondition). Tablo GLOBAL katalogdur (store_id yok).
-- Çalışma anında ALTER YAPILMAZ — şema yalnız bu migration ile değişir.
-- MySQL 5.7 / MariaDB uyumlu, idempotent (tekrar çalıştırılabilir)
-- Kolon zaten varsa boş işlem 'DO 0' çalışır ('SELECT 1' sonuç kümesi döndürür;
-- PDO::exec ile ardışık çalıştıran migrate.php / installer.php'de 2014 hatası verirdi).
-- Geri alma: down/052_marketplace_categories_availability.sql
-- © 2026 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. is_active
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'is_active'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplace_categories` ADD COLUMN `is_active` TINYINT(1) NULL DEFAULT NULL COMMENT ''Kategori durumu ACTIVE mi (NULL: bilinmiyor)'' AFTER `is_leaf`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. is_available
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'is_available'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplace_categories` ADD COLUMN `is_available` TINYINT(1) NULL DEFAULT NULL COMMENT ''Pazaryerinde satisa acik mi (NULL: bilinmiyor)'' AFTER `is_active`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
