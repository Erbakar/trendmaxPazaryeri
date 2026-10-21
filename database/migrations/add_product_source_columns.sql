-- ============================================================
-- Migration: products tablosuna main_image, source, source_id
--            kolonlarını ve idx_products_source index'ini ekle
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. main_image kolonu (AFTER images_json)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'products'
      AND COLUMN_NAME  = 'main_image'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `main_image` VARCHAR(500) NULL DEFAULT NULL AFTER `images_json`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. source kolonu (AFTER main_image)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'products'
      AND COLUMN_NAME  = 'source'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `source` VARCHAR(30) NULL DEFAULT NULL AFTER `main_image`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. source_id kolonu (AFTER source)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'products'
      AND COLUMN_NAME  = 'source_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `source_id` VARCHAR(200) NULL DEFAULT NULL AFTER `source`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. idx_products_source index'i (source kolonu üzerinde)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'products'
      AND INDEX_NAME   = 'idx_products_source'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `products` ADD INDEX `idx_products_source` (`source`)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
