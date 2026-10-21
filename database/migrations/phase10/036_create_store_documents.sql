-- Faz 10 / 036 — KYC belge altyapısı:
--   store_document_types  : admin-yönetilebilir belge TÜRÜ kataloğu (vergi levhası, banka dekontu vb.)
--   store_documents       : mağazanın yüklediği belgeler (güvenli; dosya webroot DIŞI storage/'da)
--   stores.verified_at    : tüm zorunlu türler onaylandığında doğrulama zamanı
CREATE TABLE IF NOT EXISTS `store_document_types` (
  `id`          int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`         varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label`       varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order`  int(10) NOT NULL DEFAULT 0,
  `is_active`   tinyint(1) NOT NULL DEFAULT 1,
  `created_at`  datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doctype_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_documents` (
  `id`                int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`          int(10) UNSIGNED NOT NULL,
  `doc_type_id`       int(10) UNSIGNED DEFAULT NULL,
  `file_path`         varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime`             varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size`             int(10) UNSIGNED DEFAULT NULL,
  `status`           enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `note`             varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by_user` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_by_admin` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at`      datetime DEFAULT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_doc_store` (`store_id`, `status`),
  KEY `idx_doc_type` (`doc_type_id`),
  CONSTRAINT `fk_doc_store` FOREIGN KEY (`store_id`)    REFERENCES `stores` (`id`)                ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_doc_type`  FOREIGN KEY (`doc_type_id`) REFERENCES `store_document_types` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `stores`
  ADD COLUMN IF NOT EXISTS `verified_at` datetime DEFAULT NULL AFTER `status`;

-- Varsayılan belge türleri (admin sonradan ekler/düzenler — örn. "Banka Dekontu").
INSERT IGNORE INTO `store_document_types` (`key`, `label`, `description`, `is_required`, `sort_order`, `is_active`) VALUES
('vergi_levhasi',  'Vergi Levhası',         'Vergi dairesi ve numarasını gösteren güncel resmi levha', 1, 1, 1),
('imza_sirkuleri', 'İmza Sirküleri',        'Şirket yetkilisinin imza beyanı',                          1, 2, 1),
('kimlik',         'Kimlik Fotokopisi',     'Yetkilinin T.C. kimlik veya pasaport kopyası',             1, 3, 1),
('ticaret_sicil',  'Ticaret Sicil Gazetesi','Şirket tescil / faaliyet belgesi',                         1, 4, 1);
