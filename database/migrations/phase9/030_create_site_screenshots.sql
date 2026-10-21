-- Faz 9 / 030 — Site "Ekran Görüntüleri" + hero slider (GLOBAL, admin-düzenlenebilir/yüklenebilir).
-- slot: hero (üst slider) · big (öne çıkan tek) · gallery (galeri kartları). image_path asset() ile çözülür.
CREATE TABLE IF NOT EXISTS `site_screenshots` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slot`       enum('hero','big','gallery') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'gallery',
  `title`      varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `caption`    varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sshot_slot` (`slot`, `is_active`, `sort_order`),
  KEY `idx_sshot_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: mevcut 9 ekran görüntüsü (idempotent — tablo boşsa).
INSERT INTO `site_screenshots` (`slot`,`title`,`caption`,`image_path`,`sort_order`)
SELECT * FROM (
  SELECT 'hero' sl,'Siparişler' t,'' cp,'img/screenshots/orders.png' ip, 1 so UNION ALL
  SELECT 'hero','Ürünler','','img/screenshots/product.png',2 UNION ALL
  SELECT 'hero','Senkronizasyon','','img/screenshots/sync.png',3 UNION ALL
  SELECT 'hero','Pazaryerleri','','img/screenshots/pazaryerleri.png',4 UNION ALL
  SELECT 'hero','İadeler','','img/screenshots/returns.png',5 UNION ALL
  SELECT 'big','Sipariş Yönetimi','Tüm pazaryeri siparişleri tek ekranda','img/screenshots/orders.png',1 UNION ALL
  SELECT 'gallery','Stok & Fiyat Sync','8 pazaryerine anlık güncelleme','img/screenshots/sync.png',1 UNION ALL
  SELECT 'gallery','Pazaryeri Yönetimi','Tüm entegrasyonlar tek ekranda','img/screenshots/pazaryerleri.png',2 UNION ALL
  SELECT 'gallery','Ürün Kataloğu','Varyant, toplu düzenleme','img/screenshots/product.png',3 UNION ALL
  SELECT 'gallery','Kategori & Marka','Otomatik eşleştirme','img/screenshots/category.png',4 UNION ALL
  SELECT 'gallery','İade & İptal','Merkezi iade yönetimi','img/screenshots/returns.png',5 UNION ALL
  SELECT 'gallery','Müşteriler','Müşteri kayıtları tek panelde','img/screenshots/customer.png',6 UNION ALL
  SELECT 'gallery','Yetkilendirme','Rol & izin yönetimi','img/screenshots/auth.png',7 UNION ALL
  SELECT 'gallery','Giriş Ekranı','Güvenli oturum','img/screenshots/login.png',8
) seed
WHERE NOT EXISTS (SELECT 1 FROM `site_screenshots` LIMIT 1);
