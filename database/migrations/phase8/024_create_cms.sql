-- Faz 8 / 024 — Kurumsal site CMS (GLOBAL içerik). cms_pages + blog_posts + testimonials + faqs + leads.
-- Admin-düzenlenebilir; public site bunlardan render eder. Soft-delete: blog/referans/SSS.
CREATE TABLE IF NOT EXISTS `cms_pages` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`          varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title`         varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sections_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_json`      text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `sort_order`    smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `updated_by`    int(10) UNSIGNED DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cms_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blog_posts` (
  `id`           int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`         varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title`        varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `excerpt`      varchar(400) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `body`         longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cover_url`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status`       enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL,
  `seo_json`     text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `author`       varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at`   datetime DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`   datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_slug` (`slug`),
  KEY `idx_blog_status_pub` (`status`, `published_at`),
  KEY `idx_blog_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `testimonials` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company`    varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `person`     varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role`       varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quote`      text COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_testi_active` (`is_active`, `sort_order`),
  KEY `idx_testi_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `faqs` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`   varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'genel',
  `question`   varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `answer`     text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_faq_cat_sort` (`category`, `sort_order`),
  KEY `idx_faq_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       enum('demo','contact') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'contact',
  `name`       varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company`    varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email`      varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone`      varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message`    text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status`     enum('new','contacted','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `source`     varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip`         varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leads_type_status` (`type`, `status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varsayılan SSS (idempotent: question benzersizliği yok, INSERT...SELECT WHERE NOT EXISTS ile koru).
INSERT INTO `faqs` (`category`,`question`,`answer`,`sort_order`,`is_active`)
SELECT 'genel','OzyConnect nedir?','Tüm pazaryerlerini (Trendyol, Hepsiburada, Amazon, n11 ve daha fazlası) tek panelden yöneten çok-kiracılı bir entegrasyon platformudur.',1,1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `faqs` WHERE `question`='OzyConnect nedir?');
INSERT INTO `faqs` (`category`,`question`,`answer`,`sort_order`,`is_active`)
SELECT 'genel','Ücretsiz deneme var mı?','Evet. Paketlere bağlı olarak ücretsiz deneme süresi sunulur; deneme süresince tüm paket özelliklerini kullanabilirsiniz.',2,1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `faqs` WHERE `question`='Ücretsiz deneme var mı?');
INSERT INTO `faqs` (`category`,`question`,`answer`,`sort_order`,`is_active`)
SELECT 'odeme','Ödeme nasıl yapılır?','Havale/EFT ile ödeme yapıp dekontunuzu panelden yükleyebilirsiniz; ödemeniz onaylandığında aboneliğiniz aktifleşir.',3,1
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `faqs` WHERE `question`='Ödeme nasıl yapılır?');
