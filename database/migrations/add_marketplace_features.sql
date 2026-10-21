-- ============================================================
-- Migration: marketplaces tablosuna supported_features_json
--            kolonu ekle
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- 1. supported_features_json kolonu (AFTER extra_config_json)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
      AND TABLE_NAME   = 'marketplaces'
      AND COLUMN_NAME  = 'supported_features_json'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplaces` ADD COLUMN `supported_features_json` JSON NULL DEFAULT NULL AFTER `extra_config_json`',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
