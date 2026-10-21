-- Faz 9 / 029 — Site "Modüller" bölümü (pazarlama kartları, GLOBAL, admin-düzenlenebilir).
-- Entitlement modüllerinden AYRI; yalnız kurumsal site görünümü. Mevcut 22 kart seed edilir.
CREATE TABLE IF NOT EXISTS `site_modules` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `icon`        varchar(60)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'box',
  `color`       varchar(9)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#2563eb',
  `title`       varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order`  smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at`  datetime DEFAULT NULL,
  `updated_by`  int(10) UNSIGNED DEFAULT NULL,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`  datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_smod_active` (`is_active`, `sort_order`),
  KEY `idx_smod_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: mevcut 22 modül (idempotent — title benzersizliğiyle korunur).
INSERT INTO `site_modules` (`icon`,`color`,`title`,`description`,`sort_order`)
SELECT * FROM (
  SELECT 'package' i,'#2563eb' c,'Sipariş Yönetimi' t,'Toplu kargolama, durum güncelleme.' d, 1 s UNION ALL
  SELECT 'file-check','#059669','E-Arşiv Fatura','GİB entegrasyonu, toplu faturalama.',2 UNION ALL
  SELECT 'truck','#d97706','Kargo Etiketi','Otomatik etiket, toplu yazdırma.',3 UNION ALL
  SELECT 'refresh-cw','#7c3aed','Stok Senkronizasyonu','8 pazaryerine anlık stok güncelleme.',4 UNION ALL
  SELECT 'tag','#2563eb','Fiyat Yönetimi','Dinamik kurallar, toplu güncelleme.',5 UNION ALL
  SELECT 'rotate-ccw','#dc2626','İade & İptal','Merkezi iade yönetimi, kargo takibi.',6 UNION ALL
  SELECT 'message-circle','#7c3aed','Müşteri Soruları','Tüm kanallardan tek panelde yanıt.',7 UNION ALL
  SELECT 'box','#2563eb','Ürün Kataloğu','Varyant, resim, toplu düzenleme.',8 UNION ALL
  SELECT 'git-branch','#059669','Kategori & Marka','Otomatik eşleştirme, senkronizasyon.',9 UNION ALL
  SELECT 'upload','#d97706','İçe & Dışa Aktarma','CSV/XML import, Excel export.',10 UNION ALL
  SELECT 'rss','#2563eb','XML Feed','Dinamik ürün feedleri, token koruma.',11 UNION ALL
  SELECT 'bar-chart-2','#7c3aed','Dashboard & Raporlar','Ciro grafiği, pazaryeri dağılımı.',12 UNION ALL
  SELECT 'wallet','#059669','Finans','Günlük finans verileri, raporlama.',13 UNION ALL
  SELECT 'shield','#d97706','Rol & Yetkilendirme','RBAC, modül izinleri, çoklu kullanıcı.',14 UNION ALL
  SELECT 'database-backup','#2563eb','Otomatik Yedekleme','DB + dosya, cron, ZIP arşiv.',15 UNION ALL
  SELECT 'lock','#dc2626','AES-256 Güvenlik','Şifreli API anahtarları, CSRF koruması.',16 UNION ALL
  SELECT 'volume-2','#d97706','Sesli Bildirimler','Yeni sipariş, iade, soru — her olay için farklı ses.',17 UNION ALL
  SELECT 'bell-ring','#2563eb','Anlık Bildirimler','Gerçek zamanlı zil bildirimleri, toast popup.',18 UNION ALL
  SELECT 'sparkles','#7c3aed','Ürün Enrichment','Eksik ürün verilerini otomatik zenginleştirme.',19 UNION ALL
  SELECT 'download-cloud','#059669','Otomatik Güncelleme','Tek tıkla panel güncellemesi, versiyon takibi.',20 UNION ALL
  SELECT 'key-round','#2563eb','API Token Yönetimi','Dış sisteme güvenli REST API erişimi.',21 UNION ALL
  SELECT 'palette','#dc2626','White-Label','Logo, site adı ve marka kimliği özelleştirme.',22
) seed
WHERE NOT EXISTS (SELECT 1 FROM `site_modules` LIMIT 1);
