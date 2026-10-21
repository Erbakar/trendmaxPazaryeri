-- ============================================================
-- Migration: brand_aliases tablosunu oluştur
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

CREATE TABLE IF NOT EXISTS `brand_aliases` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `brand_id`        INT UNSIGNED NOT NULL,
    `alias_name`      VARCHAR(200) NOT NULL,
    `normalized_name` VARCHAR(200) NOT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_brand_aliases_alias` (`alias_name`),
    INDEX `idx_brand_aliases_normalized` (`normalized_name`),
    INDEX `idx_brand_aliases_brand` (`brand_id`),
    CONSTRAINT `fk_brand_aliases_brand` FOREIGN KEY (`brand_id`)
        REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
