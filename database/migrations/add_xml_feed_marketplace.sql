-- ============================================================
-- Migration: xml_feeds tablosuna marketplace_id kolonu ve
--            fk_xf_marketplace FK ekle
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. marketplace_id kolonu (AFTER is_active)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'xml_feeds'
      AND COLUMN_NAME  = 'marketplace_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `xml_feeds` ADD COLUMN `marketplace_id` INT UNSIGNED NULL DEFAULT NULL AFTER `is_active`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. fk_xf_marketplace FK (FK yoksa ekle)
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA    = @dbname
      AND TABLE_NAME      = 'xml_feeds'
      AND CONSTRAINT_NAME = 'fk_xf_marketplace'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `xml_feeds` ADD CONSTRAINT `fk_xf_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
