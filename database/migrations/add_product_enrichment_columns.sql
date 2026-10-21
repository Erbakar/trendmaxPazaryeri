-- ============================================================
-- Migration: products tablosuna enrichment takip alanlarını ekle
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. enrichment_status (AFTER is_active)
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'enrichment_status';
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `enrichment_status` VARCHAR(20) NOT NULL DEFAULT ''pending'' AFTER `is_active`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. needs_category_mapping (AFTER enrichment_status)
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'needs_category_mapping';
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `needs_category_mapping` TINYINT(1) NOT NULL DEFAULT 0 AFTER `enrichment_status`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. needs_brand_mapping (AFTER needs_category_mapping)
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'needs_brand_mapping';
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `needs_brand_mapping` TINYINT(1) NOT NULL DEFAULT 0 AFTER `needs_category_mapping`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. missing_price (AFTER needs_brand_mapping)
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'missing_price';
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `missing_price` TINYINT(1) NOT NULL DEFAULT 0 AFTER `needs_brand_mapping`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. missing_images (AFTER missing_price)
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'missing_images';
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `missing_images` TINYINT(1) NOT NULL DEFAULT 0 AFTER `missing_price`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. missing_stock (AFTER missing_images)
SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'missing_stock';
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `missing_stock` TINYINT(1) NOT NULL DEFAULT 0 AFTER `missing_images`',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. idx_products_enrichment_status index
SELECT COUNT(*) INTO @idx_exists FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_enrichment_status';
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `products` ADD INDEX `idx_products_enrichment_status` (`enrichment_status`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
