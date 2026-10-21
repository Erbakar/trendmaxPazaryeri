-- Faz 9 / 032 — Satılabilir modül kataloğu (admin-fiyatlı add-on'lar). module_key = ENTITLEMENT_MODULES.
CREATE TABLE IF NOT EXISTS `module_catalog` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key`    varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label`         varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_monthly` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency`      char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `is_sellable`   tinyint(1) NOT NULL DEFAULT 1,
  `sort_order`    smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `updated_by`    int(10) UNSIGNED DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modcat_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: 13 entitlement modülü (admin fiyat/satılabilirlik düzenler). Idempotent (tablo boşsa).
INSERT INTO `module_catalog` (`module_key`,`label`,`description`,`price_monthly`,`is_sellable`,`sort_order`)
SELECT * FROM (
  SELECT 'orders' mk,'Sipariş Yönetimi' l,'Tüm pazaryeri siparişleri tek kuyrukta.' d, 149.00 p, 1 s, 1 o UNION ALL
  SELECT 'invoices','E-Arşiv Fatura','GİB uyumlu e-Arşiv fatura.',149.00,1,2 UNION ALL
  SELECT 'shipping','Kargo & Etiket','Otomatik kargo etiketi ve takip.',99.00,1,3 UNION ALL
  SELECT 'sync','Stok & Fiyat Senkron','7/24 stok/fiyat eşitleme.',129.00,1,4 UNION ALL
  SELECT 'returns','İade & İptal','Merkezi iade yönetimi.',79.00,1,5 UNION ALL
  SELECT 'questions','Müşteri Soruları','Tüm kanallardan tek gelen kutusu.',79.00,1,6 UNION ALL
  SELECT 'products','Ürün Kataloğu','Varyant, toplu düzenleme.',99.00,1,7 UNION ALL
  SELECT 'categories','Kategoriler','Kategori eşleştirme.',49.00,1,8 UNION ALL
  SELECT 'brands','Markalar','Marka eşleştirme.',49.00,1,9 UNION ALL
  SELECT 'customers','Müşteriler','Müşteri kayıt yönetimi.',69.00,1,10 UNION ALL
  SELECT 'finance','Finans','Günlük finans raporları.',99.00,1,11 UNION ALL
  SELECT 'feeds','XML Feed','Google/Meta ürün feedleri.',69.00,1,12 UNION ALL
  SELECT 'import','İçe & Dışa Aktarma','CSV/XML import, Excel export.',69.00,1,13
) seed
WHERE NOT EXISTS (SELECT 1 FROM `module_catalog` LIMIT 1);
