-- ─────────────────────────────────────────────────────────
-- Pazaryeri Kategori/Marka Cache Tabloları
-- Her pazaryerinin kendi kategori/marka listesini saklar
-- Yerel havuz kategorileri ile mapping yapılır
-- ─────────────────────────────────────────────────────────

-- Pazaryeri kategorileri cache
CREATE TABLE IF NOT EXISTS `marketplace_categories` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `marketplace_id`    INT UNSIGNED  NOT NULL,
    `mp_category_id`    VARCHAR(200)  NOT NULL COMMENT 'Pazaryerindeki kategori ID',
    `name`              VARCHAR(500)  NOT NULL,
    `parent_mp_id`      VARCHAR(200)  NULL DEFAULT NULL COMMENT 'Pazaryerindeki üst kategori ID',
    `full_path`         TEXT          NULL DEFAULT NULL,
    `is_leaf`           TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Alt kategorisi olmayan (yaprak)',
    `synced_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_mc_mp_catid` (`marketplace_id`, `mp_category_id`),
    INDEX `idx_mc_name` (`name`(100)),
    CONSTRAINT `fk_mc_marketplace` FOREIGN KEY (`marketplace_id`)
        REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pazaryeri markaları cache
CREATE TABLE IF NOT EXISTS `marketplace_brands` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `marketplace_id`    INT UNSIGNED  NOT NULL,
    `mp_brand_id`       VARCHAR(200)  NOT NULL COMMENT 'Pazaryerindeki marka ID',
    `name`              VARCHAR(300)  NOT NULL,
    `synced_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_mb_mp_brandid` (`marketplace_id`, `mp_brand_id`),
    INDEX `idx_mb_name` (`name`(100)),
    CONSTRAINT `fk_mb_marketplace` FOREIGN KEY (`marketplace_id`)
        REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
