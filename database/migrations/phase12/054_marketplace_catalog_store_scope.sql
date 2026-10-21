-- ============================================================
-- Migration: pazaryeri kategori/marka önbelleğine mağaza kapsamı (store_id)
--
-- marketplace_categories ve marketplace_brands GLOBAL katalogdur (Trendyol, Hepsiburada …
-- tüm mağazalarda aynı). Kendi mağazası olan platformlarda (WooCommerce, Shopify, OpenCart)
-- ise kategori/marka ağacı her mağazanın KENDİ sitesine aittir: aynı kimlik (ör. WooCommerce
-- kategori #17) mağazadan mağazaya başka kategoridir. Önceden bu satırlar global tabloya
-- store_id'siz yazılıyordu → başka mağazalar görüyordu, ikinci mağazanın senkronu birincinin
-- adlarını/yollarını eziyordu.
--
--   store_id = 0  → global katalog (değişmez)
--   store_id > 0  → o mağazaya özel satır (WooCommerce / Shopify / OpenCart)
-- Benzersiz anahtar (marketplace_id, store_id, mp_*_id). Kod kolonun varlığına bakar
-- (App\Models\MarketplaceCatalogScope); migration uygulanana kadar eski global davranış sürer.
--
-- Veri: bu platformların eski (store_id = 0) satırları, o pazaryeri için yapılandırması olan
-- TEK mağaza varsa ona taşınır; birden çok/hiç mağaza varsa silinir (önbellektir — mağaza
-- Kategoriler/Markalar ekranından yeniden çeker; eşleştirmeler etkilenmez). WooCommerce'te
-- marka ADINI kimlik olarak taşıyan eski satırlar silinir; ada göre kurulmuş eski marka
-- eşleştirmeleri aynı adlı sayısal kayıt varsa ona çevrilir.
--
-- MySQL 5.7 / MariaDB 10.3 uyumlu, idempotent (tekrar çalıştırılabilir). Boş işlem 'DO 0'.
-- Geri alma: down/054_marketplace_catalog_store_scope.sql
-- © 2026 OziyWeb
-- ============================================================

SET @dbname = DATABASE();

-- ─── 1. marketplace_categories.store_id ───────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplace_categories` ADD COLUMN `store_id` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''0: global katalog; >0: magazaya ozel (WooCommerce/Shopify/OpenCart)'' AFTER `marketplace_id`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Yeni benzersiz anahtar ÖNCE eklenir (FK fk_mc_marketplace her an bir indekse dayansın)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'uq_mc_mp_store_catid'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `marketplace_categories` ADD UNIQUE KEY `uq_mc_mp_store_catid` (`marketplace_id`, `store_id`, `mp_category_id`)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'uq_mc_mp_catid'
);
SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE `marketplace_categories` DROP INDEX `uq_mc_mp_catid`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ağaç listesi indeksi mağaza kapsamını da kapsasın
SET @idx_has_store = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'idx_mc_parent' AND COLUMN_NAME = 'store_id'
);
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'idx_mc_parent'
);
SET @sql = IF(@idx_exists > 0 AND @idx_has_store = 0,
    'ALTER TABLE `marketplace_categories` DROP INDEX `idx_mc_parent`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_categories' AND INDEX_NAME = 'idx_mc_parent'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `marketplace_categories` ADD KEY `idx_mc_parent` (`marketplace_id`, `store_id`, `parent_mp_id`(100))',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. marketplace_brands.store_id ───────────────────────────
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND COLUMN_NAME = 'store_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `marketplace_brands` ADD COLUMN `store_id` INT(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT ''0: global katalog; >0: magazaya ozel (WooCommerce/Shopify/OpenCart)'' AFTER `marketplace_id`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND INDEX_NAME = 'uq_mb_mp_store_brandid'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `marketplace_brands` ADD UNIQUE KEY `uq_mb_mp_store_brandid` (`marketplace_id`, `store_id`, `mp_brand_id`)',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'marketplace_brands' AND INDEX_NAME = 'uq_mb_mp_brandid'
);
SET @sql = IF(@idx_exists > 0,
    'ALTER TABLE `marketplace_brands` DROP INDEX `uq_mb_mp_brandid`',
    'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 3. Eski (store_id = 0) mağaza-platformu satırları ────────
-- Yapılandırması olan TEK mağaza varsa ona taşı (çakışan satır varsa IGNORE ile atlanır)
UPDATE IGNORE `marketplace_categories` mc
  JOIN `marketplaces` m ON m.id = mc.marketplace_id AND m.slug IN ('woocommerce', 'shopify', 'opencart')
  JOIN (
        SELECT marketplace_id, MIN(store_id) AS sid
          FROM `store_marketplace_configs`
         GROUP BY marketplace_id
        HAVING COUNT(DISTINCT store_id) = 1
       ) one ON one.marketplace_id = mc.marketplace_id
   SET mc.store_id = one.sid
 WHERE mc.store_id = 0;

DELETE mc FROM `marketplace_categories` mc
  JOIN `marketplaces` m ON m.id = mc.marketplace_id AND m.slug IN ('woocommerce', 'shopify', 'opencart')
 WHERE mc.store_id = 0;

-- WooCommerce marka kimliği sayısal terim ID'sidir: adı kimlik olarak taşıyan eski satırlar geçersiz
DELETE mb FROM `marketplace_brands` mb
  JOIN `marketplaces` m ON m.id = mb.marketplace_id AND m.slug = 'woocommerce'
 WHERE mb.mp_brand_id NOT REGEXP '^[0-9]+$';

UPDATE IGNORE `marketplace_brands` mb
  JOIN `marketplaces` m ON m.id = mb.marketplace_id AND m.slug IN ('woocommerce', 'shopify', 'opencart')
  JOIN (
        SELECT marketplace_id, MIN(store_id) AS sid
          FROM `store_marketplace_configs`
         GROUP BY marketplace_id
        HAVING COUNT(DISTINCT store_id) = 1
       ) one ON one.marketplace_id = mb.marketplace_id
   SET mb.store_id = one.sid
 WHERE mb.store_id = 0;

DELETE mb FROM `marketplace_brands` mb
  JOIN `marketplaces` m ON m.id = mb.marketplace_id AND m.slug IN ('woocommerce', 'shopify', 'opencart')
 WHERE mb.store_id = 0;

-- ─── 4. WooCommerce: ada göre kurulmuş eski marka eşleştirmeleri → sayısal kimlik ──
UPDATE `brand_marketplace_mapping` bmm
  JOIN `marketplaces` m ON m.id = bmm.marketplace_id AND m.slug = 'woocommerce'
  JOIN `marketplace_brands` mb
       ON mb.marketplace_id = bmm.marketplace_id
      AND mb.store_id = bmm.store_id
      AND LOWER(mb.name) = LOWER(COALESCE(NULLIF(bmm.marketplace_brand_name, ''), bmm.marketplace_brand_id))
      AND mb.mp_brand_id REGEXP '^[0-9]+$'
   SET bmm.marketplace_brand_id = mb.mp_brand_id
 WHERE bmm.marketplace_brand_id NOT REGEXP '^[0-9]+$';
