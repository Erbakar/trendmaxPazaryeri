-- ============================================================
-- Migration: marketplace_products tablosuna
--            marketplace_category_id, marketplace_brand_id
--            kolonlarını ekle
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. marketplace_category_id kolonu (AFTER marketplace_barcode)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'marketplace_products'
      AND COLUMN_NAME  = 'marketplace_category_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplace_products` ADD COLUMN `marketplace_category_id` VARCHAR(200) NULL DEFAULT NULL AFTER `marketplace_barcode`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. marketplace_brand_id kolonu (AFTER marketplace_category_id)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'marketplace_products'
      AND COLUMN_NAME  = 'marketplace_brand_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplace_products` ADD COLUMN `marketplace_brand_id` VARCHAR(200) NULL DEFAULT NULL AFTER `marketplace_category_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
