-- ============================================================
-- Migration: XML Profilleri + Döviz (TCMB) altyapısı
--   1. xml_profiles       — içe/dışa aktarım profilleri (Ticimax tarzı)
--   2. xml_profile_logs   — profil çalışma kayıtları ("Kayıtlar")
--   3. exchange_rates     — TCMB satış kurları (global, cron ile güncellenir)
--   4. products           — döviz kolonları (currency_code, currency_price,
--                           currency_cost, xml_profile_id)
-- MySQL 5.7 / MariaDB uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2026 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- ─── 1. xml_profiles ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `xml_profiles` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `store_id` INT(10) UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `direction` ENUM('import','export') NOT NULL DEFAULT 'import',
    `source_type` ENUM('url','file') NOT NULL DEFAULT 'url',
    `source_url` VARCHAR(1000) NULL DEFAULT NULL,
    `token` VARCHAR(64) NULL DEFAULT NULL,
    `format` VARCHAR(30) NOT NULL DEFAULT 'auto',
    `field_mapping_json` LONGTEXT NULL,
    `period_hours` INT(11) NOT NULL DEFAULT 0,
    `update_existing` TINYINT(1) NOT NULL DEFAULT 1,
    `update_prices` TINYINT(1) NOT NULL DEFAULT 1,
    `update_stock` TINYINT(1) NOT NULL DEFAULT 1,
    `create_categories` TINYINT(1) NOT NULL DEFAULT 1,
    `create_brands` TINYINT(1) NOT NULL DEFAULT 1,
    `import_images` TINYINT(1) NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_run_at` DATETIME NULL DEFAULT NULL,
    `last_run_status` VARCHAR(20) NOT NULL DEFAULT '',
    `last_run_summary` VARCHAR(500) NOT NULL DEFAULT '',
    `total_products` INT(11) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_xp_token` (`token`),
    KEY `idx_xp_store` (`store_id`),
    KEY `idx_xp_active` (`is_active`),
    CONSTRAINT `fk_xp_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 2. xml_profile_logs ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `xml_profile_logs` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `store_id` INT(10) UNSIGNED NOT NULL,
    `profile_id` INT(10) UNSIGNED NOT NULL,
    `action` VARCHAR(30) NOT NULL DEFAULT 'sync',
    `status` ENUM('running','success','partial','failed') NOT NULL DEFAULT 'running',
    `total_rows` INT(11) NOT NULL DEFAULT 0,
    `created_rows` INT(11) NOT NULL DEFAULT 0,
    `updated_rows` INT(11) NOT NULL DEFAULT 0,
    `skipped_rows` INT(11) NOT NULL DEFAULT 0,
    `failed_rows` INT(11) NOT NULL DEFAULT 0,
    `message` VARCHAR(1000) NOT NULL DEFAULT '',
    `error_details` LONGTEXT NULL,
    `triggered_by` VARCHAR(20) NOT NULL DEFAULT 'manual',
    `started_at` DATETIME NULL DEFAULT NULL,
    `finished_at` DATETIME NULL DEFAULT NULL,
    `duration_ms` INT(11) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_xpl_store` (`store_id`),
    KEY `idx_xpl_profile` (`profile_id`),
    CONSTRAINT `fk_xpl_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_xpl_profile` FOREIGN KEY (`profile_id`) REFERENCES `xml_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3. exchange_rates (global — store_id YOK) ──────────────
CREATE TABLE IF NOT EXISTS `exchange_rates` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `currency_code` VARCHAR(10) NOT NULL,
    `rate` DECIMAL(12,6) NOT NULL DEFAULT 0.000000,
    `source` VARCHAR(30) NOT NULL DEFAULT 'tcmb',
    `fetched_at` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_er_code` (`currency_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `exchange_rates` (`currency_code`, `rate`, `source`) VALUES
    ('USD', 0, 'tcmb'), ('EUR', 0, 'tcmb'), ('GBP', 0, 'tcmb');

-- ─── 4. products döviz kolonları ────────────────────────────

-- 4a. currency_code
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'currency_code'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `currency_code` VARCHAR(10) NOT NULL DEFAULT ''TRY'' AFTER `list_price`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4b. currency_price (satış fiyatının döviz tutarı)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'currency_price'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `currency_price` DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER `currency_code`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4c. currency_cost (alış fiyatının döviz tutarı)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'currency_cost'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `currency_cost` DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER `currency_price`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4d. xml_profile_id (ürünü içe aktaran profil — Stok Sıfırla / Ürünleri Sil için)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'xml_profile_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `products` ADD COLUMN `xml_profile_id` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `source_id`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4e. idx_products_currency
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_currency'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `products` ADD INDEX `idx_products_currency` (`currency_code`)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4f. idx_products_xml_profile
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND INDEX_NAME = 'idx_products_xml_profile'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `products` ADD INDEX `idx_products_xml_profile` (`xml_profile_id`)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
