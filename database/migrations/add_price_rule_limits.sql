-- ============================================================
-- Migration: price_rules tablosuna min_price, max_price
--            kolonlarını ekle
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. min_price kolonu (AFTER value)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'price_rules'
      AND COLUMN_NAME  = 'min_price'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `price_rules` ADD COLUMN `min_price` DECIMAL(12,2) NULL DEFAULT NULL AFTER `value`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. max_price kolonu (AFTER min_price)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'price_rules'
      AND COLUMN_NAME  = 'max_price'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `price_rules` ADD COLUMN `max_price` DECIMAL(12,2) NULL DEFAULT NULL AFTER `min_price`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
