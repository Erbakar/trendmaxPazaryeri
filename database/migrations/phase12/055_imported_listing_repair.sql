-- ============================================================
-- Migration (VERİ): içe aktarılmış varyant grupları + kendi mağaza platformu ilanları
--
-- 1) Varyant grubu ana ürünü — ProductImportService::groupVariantsFromImport eskiden grubun
--    "ana" ürününe (kendisi de pazaryerinde satılan bir varyant, kendi ilanı var) grubun
--    TOPLAM stoğunu yazıyordu; stok senkronu bu toplamı o ilana gönderiyordu. Yeni kod ana
--    ürünün kendi stoğunu korur (Product::isImportedVariantGroup). Burada yalnız KESİN izi olan
--    kayıtlar onarılır: içe aktarmadan beri dokunulmamış (ana ürün stoğu = varyantların toplamı +
--    kendi ilanının içe aktarma stoğu [last_stock_sent]) → stok = last_stock_sent. İzi tutmayan
--    gruplar (sipariş/elle değişiklik sonrası) DEĞİŞTİRİLMEZ; bunlar elle kontrol edilmeli.
--
-- 2) WooCommerce / Shopify / OpenCart'tan içe aktarılan ilanlar platformda zaten satışta:
--    is_listed = 1 (aksi halde zamanlanmış stok/fiyat senkronu bu ürünleri hiç göndermez; yeni
--    içe aktarımlar da artık 1 yazar). Ana ürünü olan ve stoğu kendi ilanının son stoğuyla
--    tutmayan varyantlı ürünler HARİÇ tutulur (toplam stok ilana gönderilmesin).
--
-- MySQL 5.7 / MariaDB 10.3 uyumlu, idempotent (tekrar çalıştırılabilir; ikinci çalıştırmada
-- koşullar artık tutmaz). Geri alma: down/055_imported_listing_repair.sql (veri — geri alınmaz).
-- © 2026 OziyWeb
-- ============================================================

-- ─── 1. İçe aktarılmış varyant grubu ana ürününün kendi stoğu ──
UPDATE `products` p
  JOIN (
        SELECT par.id AS parent_id, MIN(mp.last_stock_sent) AS own_stock
          FROM `products` par
          JOIN `marketplaces` m ON m.slug = par.source
          JOIN `marketplace_products` mp
               ON mp.product_id = par.id AND mp.marketplace_id = m.id AND mp.store_id = par.store_id
          JOIN (
                SELECT ch.parent_id, ch.store_id,
                       SUM(ch.stock_qty) AS total,
                       SUM(CASE WHEN COALESCE(ch.variant_attributes, '') IN ('', '[]', '{}', 'null') THEN 0 ELSE 1 END) AS attr_cnt
                  FROM `products` ch
                 WHERE ch.parent_id IS NOT NULL AND ch.is_active = 1
                 GROUP BY ch.parent_id, ch.store_id
               ) kids ON kids.parent_id = par.id AND kids.store_id = par.store_id
         WHERE par.has_variants = 1
           AND par.parent_id IS NULL
           AND par.source IN ('trendyol', 'hepsiburada', 'n11', 'amazon', 'pazarama', 'ciceksepeti',
                              'idefix', 'pttavm', 'shopify', 'woocommerce', 'opencart')
           AND COALESCE(par.variant_attributes, '') IN ('', '[]', '{}', 'null')
           AND kids.attr_cnt = 0
           AND kids.total > 0
           AND mp.last_stock_sent IS NOT NULL
           AND par.stock_qty = kids.total + mp.last_stock_sent
         GROUP BY par.id
       ) fix ON fix.parent_id = p.id
   SET p.stock_qty = fix.own_stock;

-- ─── 2. Kendi mağaza platformundan içe aktarılan ilanlar: yayında ──
UPDATE `marketplace_products` mp
  JOIN `marketplaces` m ON m.id = mp.marketplace_id AND m.slug IN ('woocommerce', 'shopify', 'opencart')
  JOIN `products` p ON p.id = mp.product_id AND p.store_id = mp.store_id AND p.source = m.slug
   SET mp.is_listed = 1
 WHERE mp.is_listed = 0
   AND mp.marketplace_product_id IS NOT NULL AND mp.marketplace_product_id <> ''
   AND mp.sync_error IS NULL
   AND NOT (p.has_variants = 1 AND p.parent_id IS NULL
            AND (mp.last_stock_sent IS NULL OR p.stock_qty <> mp.last_stock_sent));
