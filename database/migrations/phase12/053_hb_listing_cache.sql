-- ============================================================
-- Migration: hb_listing_cache — Hepsiburada ilan eşleştirme önbelleği (mağaza bazlı)
--
-- "Hepsiburada'dan Yenile" salt-okuma çekimi (MATCHED katalog + listings) bu tabloyu
-- doldurur; ilan eşleştirme ekranı ve yayın öncesi mükerrer koruması
-- (ProductPublishService::findHepsiburadaExistingListing) buradan okur.
-- ÇOK KİRACILI: her satır store_id taşır; tüm okuma/yazmalar store_id ile filtrelenir.
-- Mağaza silinince satırlar FK ile silinir; MarketplacePurgeService de temizler.
-- Metin kolonları NOT NULL DEFAULT '' — boş değer için '' yazılır (NULL değil).
-- MySQL 5.7 / MariaDB uyumlu, idempotent (tekrar çalıştırılabilir)
-- Geri alma: down/053_hb_listing_cache.sql
-- © 2026 OziyWeb
-- ============================================================

CREATE TABLE IF NOT EXISTS `hb_listing_cache` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `store_id` INT(10) UNSIGNED NOT NULL,
    `hb_sku` VARCHAR(64) NOT NULL,
    `merchant_sku` VARCHAR(200) NOT NULL DEFAULT '',
    `barcode` VARCHAR(100) NOT NULL DEFAULT '',
    `product_name` VARCHAR(500) NOT NULL DEFAULT '',
    `catalog_name` VARCHAR(500) NOT NULL DEFAULT '',
    `quantity` VARCHAR(40) NOT NULL DEFAULT '',
    `product_type` VARCHAR(120) NOT NULL DEFAULT '',
    `variant_group_id` VARCHAR(64) NOT NULL DEFAULT '',
    `image_url` VARCHAR(500) NOT NULL DEFAULT '',
    `search_text` VARCHAR(900) NOT NULL DEFAULT '',
    `in_catalog` TINYINT(1) NOT NULL DEFAULT 0,
    `has_listing` TINYINT(1) NOT NULL DEFAULT 0,
    `is_salable` TINYINT(1) NOT NULL DEFAULT 0,
    `price` DECIMAL(12,2) NULL DEFAULT NULL,
    `stock` INT(11) NULL DEFAULT NULL,
    `refresh_round` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `listing_round` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hlc_store_sku` (`store_id`, `hb_sku`),
    KEY `idx_hlc_store_msku` (`store_id`, `merchant_sku`),
    KEY `idx_hlc_store_barcode` (`store_id`, `barcode`),
    CONSTRAINT `fk_hlc_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
