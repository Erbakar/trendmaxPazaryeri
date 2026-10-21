-- Fix orders table: add customer_id if missing
-- Migration: fix_orders_customer_id
-- Date: 2026-05-01

-- Check if customer_id column exists, if not add it
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND COLUMN_NAME = 'customer_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `orders` ADD COLUMN `customer_id` int(10) unsigned DEFAULT NULL AFTER `marketplace_order_id`',
    'SELECT "Column customer_id already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key if it doesn't exist
SET @fk_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND CONSTRAINT_NAME = 'fk_orders_customer'
);

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `orders` ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "Foreign key fk_orders_customer already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index if it doesn't exist
SET @idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'orders'
    AND INDEX_NAME = 'idx_orders_customer'
);

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `orders` ADD KEY `idx_orders_customer` (`customer_id`)',
    'SELECT "Index idx_orders_customer already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
