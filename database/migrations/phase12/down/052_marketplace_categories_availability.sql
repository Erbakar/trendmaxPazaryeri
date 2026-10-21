-- ============================================================
-- Faz 12 / 052 · DOWN · marketplace_categories satış durumu kolonlarını kaldır
-- Idempotent: kolon yoksa hiçbir şey yapmaz ('DO 0' — sonuç kümesi döndürmeyen boş işlem).
-- ============================================================

SET @dbname = DATABASE();

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'is_available'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `marketplace_categories` DROP COLUMN `is_available`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'is_active'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `marketplace_categories` DROP COLUMN `is_active`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
