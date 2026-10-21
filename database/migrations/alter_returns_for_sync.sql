-- ─────────────────────────────────────────────────────────
-- Migration: returns tablosunu iade sync sistemi için genişlet
-- Tarih: 2026-04-04
-- Açıklama:
--   - order_id nullable (N11 iadelerinde yerel sipariş eşleşmeyebilir)
--   - status ENUM genişletildi (N11 + Trendyol tüm durumlar)
--   - Yeni alanlar: marketplace_order_id, customer_*, product_*, tarihler, synced_at
--   - UNIQUE index: (marketplace_id, marketplace_return_id)
-- ─────────────────────────────────────────────────────────

-- 1. FK constraint kaldır (order_id nullable yapabilmek için)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'returns' AND CONSTRAINT_NAME = 'fk_returns_order');

SET @sql = IF(@fk_exists > 0, 'ALTER TABLE `returns` DROP FOREIGN KEY `fk_returns_order`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. order_id nullable yap
ALTER TABLE `returns` MODIFY COLUMN `order_id` INT UNSIGNED NULL DEFAULT NULL;

-- 3. FK'yi tekrar ekle (nullable ile uyumlu)
ALTER TABLE `returns` ADD CONSTRAINT `fk_returns_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- 4. status ENUM genişlet
ALTER TABLE `returns` MODIFY COLUMN `status`
    ENUM('requested','approved','rejected','completed','refunded',
         'cancelled','pending','pended','manual_refund',
         'created','waiting_in_action','waiting_fraud_check',
         'unresolved','in_analysis','denied')
    NOT NULL DEFAULT 'requested';

-- 5. Yeni kolonlar ekle
ALTER TABLE `returns`
    ADD COLUMN `marketplace_order_id` VARCHAR(200) NULL DEFAULT NULL AFTER `marketplace_return_id`,
    ADD COLUMN `customer_name`        VARCHAR(255) NULL DEFAULT NULL AFTER `marketplace_order_id`,
    ADD COLUMN `customer_email`       VARCHAR(255) NULL DEFAULT NULL AFTER `customer_name`,
    ADD COLUMN `customer_phone`       VARCHAR(100) NULL DEFAULT NULL AFTER `customer_email`,
    ADD COLUMN `product_name`         VARCHAR(500) NULL DEFAULT NULL AFTER `customer_phone`,
    ADD COLUMN `product_sku`          VARCHAR(200) NULL DEFAULT NULL AFTER `product_name`,
    ADD COLUMN `quantity`             INT UNSIGNED NOT NULL DEFAULT 1 AFTER `product_sku`,
    ADD COLUMN `status_raw`           VARCHAR(100) NULL DEFAULT NULL AFTER `status`,
    ADD COLUMN `cargo_company`        VARCHAR(200) NULL DEFAULT NULL AFTER `refund_amount`,
    ADD COLUMN `cargo_tracking_no`    VARCHAR(200) NULL DEFAULT NULL AFTER `cargo_company`,
    ADD COLUMN `image_url`            TEXT         NULL DEFAULT NULL AFTER `cargo_tracking_no`,
    ADD COLUMN `action_required`      TINYINT(1)   NOT NULL DEFAULT 0 AFTER `image_url`,
    ADD COLUMN `request_date`         DATETIME     NULL DEFAULT NULL AFTER `action_required`,
    ADD COLUMN `approved_date`        DATETIME     NULL DEFAULT NULL AFTER `request_date`,
    ADD COLUMN `denied_date`          DATETIME     NULL DEFAULT NULL AFTER `approved_date`,
    ADD COLUMN `cancelled_date`       DATETIME     NULL DEFAULT NULL AFTER `denied_date`,
    ADD COLUMN `synced_at`            DATETIME     NULL DEFAULT NULL AFTER `raw_data_json`;

-- 6. UNIQUE index ekle (aynı iade tekrar kaydedilmesin)
ALTER TABLE `returns`
    ADD UNIQUE INDEX `uq_returns_mp_return` (`marketplace_id`, `marketplace_return_id`);

-- 7. marketplace_order_id ve synced_at indexleri
ALTER TABLE `returns`
    ADD INDEX `idx_returns_mp_order_id` (`marketplace_order_id`),
    ADD INDEX `idx_returns_synced_at` (`synced_at`),
    ADD INDEX `idx_returns_action_required` (`action_required`);
