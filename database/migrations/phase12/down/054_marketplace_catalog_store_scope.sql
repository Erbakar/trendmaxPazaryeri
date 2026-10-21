-- ============================================================
-- Faz 12 / 054 · DOWN · pazaryeri kategori/marka önbelleğinden mağaza kapsamını kaldır
-- Mağazaya özel satırlar (store_id > 0) SİLİNİR (önbellektir; eski benzersiz anahtar
-- (marketplace_id, mp_*_id) mağazalar arası aynı kimliklere izin vermez). Global satırlar kalır.
-- Idempotent: kolon/indeks yoksa hiçbir şey yapmaz ('DO 0').
-- ============================================================

SET @dbname = DATABASE();

-- ─── marketplace_categories ───────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@col_exists > 0,
    'DELETE FROM `marketplace_categories` WHERE `store_id` > 0',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'uq_mc_mp_catid'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `marketplace_categories` ADD UNIQUE KEY `uq_mc_mp_catid` (`marketplace_id`, `mp_category_id`)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'uq_mc_mp_store_catid'
);
SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE `marketplace_categories` DROP INDEX `uq_mc_mp_store_catid`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_has_store = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'idx_mc_parent' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@idx_has_store > 0,
    'ALTER TABLE `marketplace_categories` DROP INDEX `idx_mc_parent`, ADD KEY `idx_mc_parent` (`marketplace_id`, `parent_mp_id`(100))',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `marketplace_categories` DROP COLUMN `store_id`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── marketplace_brands ───────────────────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@col_exists > 0,
    'DELETE FROM `marketplace_brands` WHERE `store_id` > 0',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND INDEX_NAME = 'uq_mb_mp_brandid'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `marketplace_brands` ADD UNIQUE KEY `uq_mb_mp_brandid` (`marketplace_id`, `mp_brand_id`)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND INDEX_NAME = 'uq_mb_mp_store_brandid'
);
SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE `marketplace_brands` DROP INDEX `uq_mb_mp_store_brandid`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@col_exists > 0,
    'ALTER TABLE `marketplace_brands` DROP COLUMN `store_id`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
