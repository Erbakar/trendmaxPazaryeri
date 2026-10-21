-- ============================================================
-- Migration: enrichment_logs tablosunu oluştur
-- MySQL 5.7 uyumlu, idempotent (tekrar çalıştırılabilir)
-- © 2025 OziyWeb
-- ============================================================

CREATE TABLE IF NOT EXISTS `enrichment_logs` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `product_id`      INT UNSIGNED NOT NULL,
    `marketplace_id`  INT UNSIGNED NOT NULL,
    `step`            VARCHAR(50) NOT NULL,
    `field`           VARCHAR(50) NOT NULL,
    `status`          VARCHAR(20) NOT NULL,
    `old_value`       TEXT NULL DEFAULT NULL,
    `new_value`       TEXT NULL DEFAULT NULL,
    `message`         TEXT NULL DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_el_product` (`product_id`),
    INDEX `idx_el_marketplace` (`marketplace_id`),
    INDEX `idx_el_step_status` (`step`, `status`),
    INDEX `idx_el_created` (`created_at`),
    CONSTRAINT `fk_el_product` FOREIGN KEY (`product_id`)
        REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_el_marketplace` FOREIGN KEY (`marketplace_id`)
        REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
