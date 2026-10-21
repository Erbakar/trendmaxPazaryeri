-- phpMyAdmin SQL Dump
-- version 4.9.2
-- https://www.phpmyadmin.net/
--
-- Anamakine: 127.0.0.1:3306
-- Üretim Zamanı: 01 May 2026, 17:37:50
-- Sunucu sürümü: 10.3.20-MariaDB
-- PHP Sürümü: 8.2.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `ozyconnect`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `api_logs`
--

DROP TABLE IF EXISTS `api_logs`;
CREATE TABLE IF NOT EXISTS `api_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_token_id` int(10) UNSIGNED DEFAULT NULL,
  `method` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `endpoint` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status_code` smallint(5) UNSIGNED NOT NULL DEFAULT 200,
  `response_time_ms` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_body` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `response_summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_api_logs_token` (`api_token_id`),
  KEY `idx_api_logs_created` (`created_at`),
  KEY `idx_api_logs_endpoint` (`endpoint`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Token aÃ§Ä±klamasÄ±',
  `api_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Public API Key (hex)',
  `api_secret_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Bcrypt hashed secret',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Ä°zin listesi',
  `rate_limit` int(10) UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Dakikadaki max istek',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `ip_whitelist` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Ä°zin verilen IP listesi (null = tÃ¼mÃ¼)',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = sÃ¼resiz',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_key` (`api_key`),
  KEY `idx_api_tokens_key` (`api_key`),
  KEY `idx_api_tokens_active` (`is_active`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `attribute_values`
--

DROP TABLE IF EXISTS `attribute_values`;
CREATE TABLE IF NOT EXISTS `attribute_values` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `attribute_id` int(10) UNSIGNED NOT NULL,
  `value` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `color_hex` char(7) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_av_attribute` (`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `backups`
--

DROP TABLE IF EXISTS `backups`;
CREATE TABLE IF NOT EXISTS `backups` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `type` enum('manual','auto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` enum('creating','completed','failed','restoring') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'creating',
  `tables_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `files_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `manifest_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_backup_user` (`created_by`),
  KEY `idx_backups_status` (`status`),
  KEY `idx_backups_type` (`type`),
  KEY `idx_backups_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `brands`
--

DROP TABLE IF EXISTS `brands`;
CREATE TABLE IF NOT EXISTS `brands` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `logo_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `idx_brands_slug` (`slug`),
  KEY `idx_brands_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `brand_aliases`
--

DROP TABLE IF EXISTS `brand_aliases`;
CREATE TABLE IF NOT EXISTS `brand_aliases` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` int(10) UNSIGNED NOT NULL,
  `alias_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `normalized_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_brand_aliases_alias` (`alias_name`),
  KEY `idx_brand_aliases_normalized` (`normalized_name`),
  KEY `idx_brand_aliases_brand` (`brand_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `brand_marketplace_mapping`
--

DROP TABLE IF EXISTS `brand_marketplace_mapping`;
CREATE TABLE IF NOT EXISTS `brand_marketplace_mapping` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `brand_id` int(10) UNSIGNED NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `marketplace_brand_id` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marketplace_brand_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bmm_brand_mp` (`brand_id`,`marketplace_id`),
  KEY `idx_bmm_mp_mpbrand` (`marketplace_id`,`marketplace_brand_id`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `categories`
--

DROP TABLE IF EXISTS `categories`;
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `name` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_path` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_categories_parent` (`parent_id`),
  KEY `idx_categories_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `category_attributes`
--

DROP TABLE IF EXISTS `category_attributes`;
CREATE TABLE IF NOT EXISTS `category_attributes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` int(10) UNSIGNED NOT NULL,
  `attribute_id` int(10) UNSIGNED NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ca_category_attr` (`category_id`,`attribute_id`),
  KEY `fk_ca_attribute` (`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `category_marketplace_mapping`
--

DROP TABLE IF EXISTS `category_marketplace_mapping`;
CREATE TABLE IF NOT EXISTS `category_marketplace_mapping` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` int(10) UNSIGNED NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `marketplace_category_id` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marketplace_category_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_category_path` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attribute_mapping_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cmm_cat_mp` (`category_id`,`marketplace_id`),
  KEY `idx_cmm_mp_cat` (`marketplace_id`,`marketplace_category_id`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customers`
--

DROP TABLE IF EXISTS `customers`;
CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_office` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_spent` decimal(14,2) NOT NULL DEFAULT 0.00,
  `first_order_at` datetime DEFAULT NULL,
  `last_order_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_marketplace_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customers_email` (`email`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_id_no` (`id_no`),
  KEY `idx_source_mp` (`source_marketplace_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `enrichment_logs`
--

DROP TABLE IF EXISTS `enrichment_logs`;
CREATE TABLE IF NOT EXISTS `enrichment_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` int(10) UNSIGNED NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `step` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_value` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `new_value` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_el_product` (`product_id`),
  KEY `idx_el_marketplace` (`marketplace_id`),
  KEY `idx_el_step_status` (`step`,`status`),
  KEY `idx_el_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `financial_transactions`
--

DROP TABLE IF EXISTS `financial_transactions`;
CREATE TABLE IF NOT EXISTS `financial_transactions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `transaction_type` varchar(50) COLLATE utf8mb4_turkish_ci NOT NULL COMMENT 'sale,refund,commission,payment,stoppage,wire_transfer,invoice,other',
  `transaction_date` datetime NOT NULL,
  `order_number` varchar(100) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `description` varchar(500) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `gross_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(5) COLLATE utf8mb4_turkish_ci NOT NULL DEFAULT 'TRY',
  `status` varchar(30) COLLATE utf8mb4_turkish_ci DEFAULT 'completed',
  `external_id` varchar(100) COLLATE utf8mb4_turkish_ci DEFAULT NULL COMMENT 'Marketplace transaction ID',
  `extra_data_json` text COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ft_marketplace` (`marketplace_id`),
  KEY `idx_ft_type` (`transaction_type`),
  KEY `idx_ft_date` (`transaction_date`),
  KEY `idx_ft_external` (`external_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `invoices`
--

DROP TABLE IF EXISTS `invoices`;
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` int(10) UNSIGNED NOT NULL,
  `related_invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `return_request_id` int(10) UNSIGNED DEFAULT NULL,
  `type` enum('e-fatura','e-arsiv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'e-arsiv',
  `invoice_flow` enum('sale','return') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sale',
  `invoice_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `series` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OMR',
  `sequence_no` int(11) NOT NULL DEFAULT 0,
  `status` enum('draft','pending','sent','cancelled','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `provider_response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `customer_name` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_tax_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_tax_office` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `xml_content` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pdf_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invoices_uuid` (`invoice_uuid`),
  KEY `idx_invoices_order` (`order_id`),
  KEY `idx_invoices_status` (`status`),
  KEY `idx_invoices_type` (`type`),
  KEY `idx_invoices_number` (`invoice_number`),
  KEY `idx_invoices_issued` (`issued_at`),
  KEY `idx_invoices_flow` (`invoice_flow`),
  KEY `idx_invoices_related` (`related_invoice_id`),
  KEY `idx_invoices_return_request` (`return_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_success` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_login_ip` (`ip_address`,`attempted_at`),
  KEY `idx_login_email` (`email`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- login_attempts: Temiz kurulum — veri yok
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `marketplaces`
--

DROP TABLE IF EXISTS `marketplaces`;
CREATE TABLE IF NOT EXISTS `marketplaces` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `api_key_encrypted` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_secret_encrypted` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extra_config_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `supported_features_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `webhook_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_connected_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_marketplaces_slug` (`slug`),
  KEY `idx_marketplaces_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `marketplaces`
--

INSERT INTO `marketplaces` (`id`, `slug`, `display_name`, `is_active`, `api_key_encrypted`, `api_secret_encrypted`, `extra_config_json`, `supported_features_json`, `webhook_token`, `last_connected_at`, `created_at`, `updated_at`) VALUES
(1, 'trendyol', 'Trendyol', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-29 22:33:56', '2026-04-27 18:37:50'),
(2, 'hepsiburada', 'Hepsiburada', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-29 22:33:56', '2026-04-27 18:37:50'),
(3, 'n11', 'n11', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-29 22:33:56', '2026-04-27 18:37:50'),
(4, 'amazon', 'Amazon TR', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-29 22:33:56', '2026-04-27 18:37:50'),
(5, 'pazarama', 'Pazarama', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-29 22:33:56', '2026-04-27 18:37:50'),
(6, 'ciceksepeti', 'ÇiçekSepeti', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-29 22:33:56', '2026-04-27 18:37:50'),
(7, 'pttavm', 'PttAVM', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-30 18:59:51', '2026-04-27 18:37:50'),
(8, 'idefix', 'İdefix', 0, NULL, NULL, NULL, NULL, NULL, NULL, '2026-04-08 14:43:05', '2026-04-27 18:37:50');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `marketplace_brands`
--

DROP TABLE IF EXISTS `marketplace_brands`;
CREATE TABLE IF NOT EXISTS `marketplace_brands` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `mp_brand_id` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Pazaryerindeki marka ID',
  `name` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mb_mp_brandid` (`marketplace_id`,`mp_brand_id`),
  KEY `idx_mb_name` (`name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `marketplace_categories`
--

DROP TABLE IF EXISTS `marketplace_categories`;
CREATE TABLE IF NOT EXISTS `marketplace_categories` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `mp_category_id` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Pazaryerindeki kategori ID',
  `name` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_mp_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Pazaryerindeki ??st kategori ID',
  `full_path` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_leaf` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Alt kategorisi olmayan (yaprak)',
  `synced_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mc_mp_catid` (`marketplace_id`,`mp_category_id`),
  KEY `idx_mc_name` (`name`(100)),
  KEY `idx_mc_parent` (`marketplace_id`,`parent_mp_id`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `marketplace_products`
--

DROP TABLE IF EXISTS `marketplace_products`;
CREATE TABLE IF NOT EXISTS `marketplace_products` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `marketplace_sku` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_product_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_category_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_category_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_brand_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_brand_name` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `main_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_rule_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `price_rule_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock_offset` int(11) NOT NULL DEFAULT 0,
  `last_price_sent` decimal(12,2) DEFAULT NULL,
  `last_stock_sent` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_listed` tinyint(1) NOT NULL DEFAULT 0,
  `last_synced_at` datetime DEFAULT NULL,
  `sync_error` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mp_product_mp` (`product_id`,`marketplace_id`),
  KEY `idx_mp_marketplace` (`marketplace_id`),
  KEY `idx_mp_sku` (`marketplace_sku`),
  KEY `idx_mp_active` (`is_active`),
  KEY `idx_mpp_mp_active_listed` (`marketplace_id`,`is_active`,`is_listed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `orders`
--

DROP TABLE IF EXISTS `orders`;
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `marketplace_order_id` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int(10) UNSIGNED DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yeni',
  `customer_name` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id_no` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_tax_office` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_address_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `shipping_address_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `cargo_company` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cargo_tracking_no` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cargo_sent_at` datetime DEFAULT NULL,
  `invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `invoice_sent_to_marketplace` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `raw_data_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `ordered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_mp_order` (`marketplace_id`,`marketplace_order_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_created` (`created_at`),
  KEY `idx_orders_ordered` (`ordered_at`),
  KEY `idx_orders_invoice` (`invoice_id`),
  KEY `idx_orders_customer` (`customer_id`),
  KEY `idx_archived` (`is_archived`),
  KEY `idx_orders_archived_date` (`is_archived`,`ordered_at`),
  KEY `idx_orders_mp_archived` (`marketplace_id`,`is_archived`,`ordered_at`),
  KEY `idx_orders_archived_status` (`is_archived`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `order_items`
--

DROP TABLE IF EXISTS `order_items`;
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` int(10) UNSIGNED NOT NULL,
  `product_id` int(10) UNSIGNED DEFAULT NULL,
  `marketplace_product_id` int(10) UNSIGNED DEFAULT NULL,
  `marketplace_item_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 20.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pr_email` (`email`),
  KEY `idx_pr_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `price_rules`
--

DROP TABLE IF EXISTS `price_rules`;
CREATE TABLE IF NOT EXISTS `price_rules` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `rule_type` enum('percent','fixed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'percent',
  `direction` enum('increase','decrease') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'increase',
  `value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `min_price` decimal(12,2) DEFAULT NULL,
  `max_price` decimal(12,2) DEFAULT NULL,
  `apply_to` enum('all','category','product') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `reference_id` int(10) UNSIGNED DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 10,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pr_marketplace` (`marketplace_id`),
  KEY `idx_pr_apply` (`apply_to`,`reference_id`),
  KEY `idx_pr_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `products`
--

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `short_description` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` int(10) UNSIGNED DEFAULT NULL,
  `brand_id` int(10) UNSIGNED DEFAULT NULL,
  `cost_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `base_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `list_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `min_stock_alert` int(11) NOT NULL DEFAULT 5,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'adet',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 20.00,
  `weight` decimal(8,3) DEFAULT NULL,
  `desi` decimal(8,2) DEFAULT NULL,
  `dimensions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `images_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `main_image` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `has_variants` tinyint(1) NOT NULL DEFAULT 0,
  `parent_id` int(10) UNSIGNED DEFAULT NULL,
  `variant_attributes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `enrichment_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `needs_category_mapping` tinyint(1) NOT NULL DEFAULT 0,
  `needs_brand_mapping` tinyint(1) NOT NULL DEFAULT 0,
  `missing_price` tinyint(1) NOT NULL DEFAULT 0,
  `missing_images` tinyint(1) NOT NULL DEFAULT 0,
  `missing_stock` tinyint(1) NOT NULL DEFAULT 0,
  `seo_title` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `seo_description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `idx_products_sku` (`sku`),
  KEY `idx_products_barcode` (`barcode`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_brand` (`brand_id`),
  KEY `idx_products_parent` (`parent_id`),
  KEY `idx_products_active` (`is_active`),
  KEY `idx_products_stock` (`stock_qty`),
  KEY `idx_products_source` (`source`),
  KEY `idx_products_enrichment_status` (`enrichment_status`),
  KEY `idx_products_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_attributes`
--

DROP TABLE IF EXISTS `product_attributes`;
CREATE TABLE IF NOT EXISTS `product_attributes` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_type` enum('text','select','multi_select','color','number','boolean') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_variant_attribute` tinyint(1) NOT NULL DEFAULT 0,
  `is_filterable` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pa_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_attribute_values`
--

DROP TABLE IF EXISTS `product_attribute_values`;
CREATE TABLE IF NOT EXISTS `product_attribute_values` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` int(10) UNSIGNED NOT NULL,
  `attribute_id` int(10) UNSIGNED NOT NULL,
  `attribute_value_id` int(10) UNSIGNED DEFAULT NULL,
  `custom_value` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pav_product_attr` (`product_id`,`attribute_id`),
  KEY `idx_pav_product` (`product_id`),
  KEY `idx_pav_attribute` (`attribute_id`),
  KEY `fk_pav_value` (`attribute_value_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_import_jobs`
--

DROP TABLE IF EXISTS `product_import_jobs`;
CREATE TABLE IF NOT EXISTS `product_import_jobs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED DEFAULT NULL,
  `filename` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_filename` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full',
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `processed_rows` int(11) NOT NULL DEFAULT 0,
  `success_rows` int(11) NOT NULL DEFAULT 0,
  `failed_rows` int(11) NOT NULL DEFAULT 0,
  `error_log` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pij_marketplace` (`marketplace_id`),
  KEY `idx_pij_status` (`status`),
  KEY `fk_pij_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `questions`
--

DROP TABLE IF EXISTS `questions`;
CREATE TABLE IF NOT EXISTS `questions` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `marketplace_question_id` varchar(100) COLLATE utf8mb4_turkish_ci NOT NULL COMMENT 'Pazaryerindeki soru ID',
  `product_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Yerel ??r??n ID (e??le??irse)',
  `marketplace_product_id` varchar(100) COLLATE utf8mb4_turkish_ci DEFAULT NULL COMMENT 'Pazaryerindeki ??r??n ID',
  `product_name` varchar(500) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `product_image_url` varchar(1000) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `question_text` text COLLATE utf8mb4_turkish_ci NOT NULL,
  `question_date` datetime NOT NULL,
  `customer_name` varchar(200) COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `answer_text` text COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `answer_date` datetime DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_turkish_ci NOT NULL DEFAULT 'waiting' COMMENT 'waiting, answered, rejected',
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `extra_data_json` text COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_q_mp_qid` (`marketplace_id`,`marketplace_question_id`),
  KEY `idx_q_marketplace` (`marketplace_id`),
  KEY `idx_q_status` (`status`),
  KEY `idx_q_date` (`question_date`),
  KEY `idx_q_product` (`product_id`),
  KEY `idx_q_mp_qid` (`marketplace_question_id`),
  KEY `idx_q_mp_status_date` (`marketplace_id`,`status`,`question_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `returns`
--

DROP TABLE IF EXISTS `returns`;
CREATE TABLE IF NOT EXISTS `returns` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` int(10) UNSIGNED DEFAULT NULL,
  `marketplace_return_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `marketplace_order_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_sku` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `status` enum('requested','approved','rejected','completed','refunded','cancelled','pending','pended','manual_refund','created','waiting_in_action','waiting_fraud_check','unresolved','in_analysis','denied') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `status_raw` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason_code` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `items_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cargo_company` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cargo_tracking_no` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_url` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action_required` tinyint(1) NOT NULL DEFAULT 0,
  `request_date` datetime DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `denied_date` datetime DEFAULT NULL,
  `cancelled_date` datetime DEFAULT NULL,
  `cancel_invoice_id` int(10) UNSIGNED DEFAULT NULL,
  `marketplace_decision` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `raw_data_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_returns_mp_return` (`marketplace_id`,`marketplace_return_id`),
  KEY `idx_returns_order` (`order_id`),
  KEY `idx_returns_marketplace` (`marketplace_id`),
  KEY `idx_returns_status` (`status`),
  KEY `idx_returns_created` (`created_at`),
  KEY `fk_returns_cancel_invoice` (`cancel_invoice_id`),
  KEY `idx_returns_mp_order_id` (`marketplace_order_id`),
  KEY `idx_returns_synced_at` (`synced_at`),
  KEY `idx_returns_action_required` (`action_required`),
  KEY `idx_returns_mp_created` (`marketplace_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `permissions_json` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;

--
-- Tablo döküm verisi `roles`
--

INSERT INTO `roles` (`id`, `name`, `slug`, `description`, `is_system`, `permissions_json`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'admin', 'Tüm yetkilerle tam erişim', 1, '[\"*\"]', '2026-04-02 18:37:18', '2026-04-02 18:37:18'),
(2, 'Satış Danışmanı', 'satis', 'Sipariş ve müşteri yönetimi', 0, '[\"dashboard.view\",\"orders.view\",\"orders.ship\",\"returns.view\",\"customers.view\",\"products.view\"]', '2026-04-02 18:37:18', '2026-04-02 18:37:18'),
(3, 'Muhasebe', 'muhasebe', 'Finans, fatura ve sipariş erişimi', 0, '[\"dashboard.view\",\"orders.view\",\"invoices.view\",\"invoices.create\",\"invoices.send\",\"finance.view\",\"customers.view\"]', '2026-04-02 18:37:18', '2026-04-02 18:37:18'),
(4, 'Depocu', 'depocu', 'Ürün, stok ve kargo yönetimi', 0, '[\"dashboard.view\",\"orders.view\",\"orders.ship\",\"products.view\",\"products.edit\",\"categories.view\",\"brands.view\"]', '2026-04-02 18:37:18', '2026-04-02 18:37:18'),
(5, 'Görüntüleyici', 'viewer', 'Sadece okuma yetkisi', 0, '[\"dashboard.view\",\"orders.view\",\"products.view\",\"customers.view\",\"categories.view\",\"brands.view\"]', '2026-04-02 18:37:18', '2026-04-02 18:37:18');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `schema_migrations`
--

DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `checksum` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
  `duration_ms` int(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `filename` (`filename`),
  KEY `idx_applied_at` (`applied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `settings`
--

DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS `settings` (
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` enum('string','int','bool','json') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `group` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `label` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`key`),
  KEY `idx_settings_group` (`group`),
  KEY `fk_settings_user` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Tablo döküm verisi `settings`
--

INSERT INTO `settings` (`key`, `value`, `type`, `group`, `label`, `updated_at`, `updated_by`) VALUES
('admin_email', '', 'string', 'general', 'Admin E-posta', NOW(), NULL),
('auto_return_invoice', '0', 'bool', 'invoice', 'Otomatik Iade Faturasi', NOW(), NULL),
('cron_token', '', 'string', 'general', 'cron_token', NOW(), NULL),
('currency', 'TRY', 'string', 'general', 'Para Birimi', NOW(), NULL),
('default_tax_rate', '20', 'int', 'general', 'Varsayılan KDV Oranı (%)', NOW(), NULL),
('feed_cache_hours', '4', 'int', 'feed', 'Feed Önbellek Süresi (Saat)', NOW(), NULL),
('feed_token', '', 'string', 'general', 'feed_token', NOW(), NULL),
('gib_password', '', 'string', 'invoice', 'GIB Sifre', NOW(), NULL),
('gib_test_mode', '1', 'bool', 'invoice', 'GIB Test Modu', NOW(), NULL),
('gib_username', '', 'string', 'invoice', 'GIB Kullanici Adi', NOW(), NULL),
('install_date', '', 'string', 'general', 'Kurulum Tarihi', NOW(), NULL),
('invoice_auto_create', '0', 'bool', 'invoice', 'Otomatik Fatura Oluştur', NOW(), NULL),
('invoice_provider', 'GiB', 'string', 'invoice', 'Fatura Sağlayıcı', NOW(), NULL),
('invoice_series', 'OZY', 'string', 'invoice', 'Fatura Serisi', NOW(), NULL),
('invoice_start_no', '1', 'int', 'invoice', 'Fatura Başlangıç No', NOW(), NULL),
('min_stock_alert', '5', 'int', 'general', 'Minimum Stok Uyarı Eşiği', NOW(), NULL),
('notify_api_error', '1', 'bool', 'notification', 'API Hatası Bildirimi', NOW(), NULL),
('notify_new_return', '1', 'bool', 'notification', 'Yeni İade Bildirimi', NOW(), NULL),
('notify_stock_alert', '1', 'bool', 'notification', 'Stok Uyarısı Bildirimi', NOW(), NULL),
('panel_logo_path', '', 'string', 'general', 'Panel Logosu', NOW(), NULL),
('seller_address', '', 'string', 'invoice', 'Adres', NOW(), NULL),
('seller_city', '', 'string', 'invoice', 'İl', NOW(), NULL),
('seller_company_name', '', 'string', 'invoice', 'Şirket Unvanı', NOW(), NULL),
('seller_district', '', 'string', 'invoice', 'İlçe', NOW(), NULL),
('seller_email', '', 'string', 'invoice', 'E-posta', NOW(), NULL),
('seller_mersis_no', '', 'string', 'invoice', 'Mersis No', NOW(), NULL),
('seller_phone', '', 'string', 'invoice', 'Telefon', NOW(), NULL),
('seller_tax_no', '', 'string', 'invoice', 'Vergi No', NOW(), NULL),
('seller_tax_office', '', 'string', 'invoice', 'Vergi Dairesi', NOW(), NULL),
('site_name', 'OzyConnect Panel', 'string', 'general', 'Site Adı', NOW(), NULL),
('app_version', '1.0.0', 'string', 'general', 'Panel Versiyonu', NOW(), NULL),
('support_url', 'https://www.oziyweb.com.tr/destek', 'string', 'general', 'Destek URL', NOW(), NULL),
('support_email', 'destek@oziyweb.com.tr', 'string', 'general', 'Destek E-posta', NOW(), NULL),
('support_phone', '', 'string', 'general', 'Destek Telefon', NOW(), NULL),
('update_check_url', 'https://www.oziyweb.com.tr/api/version-check', 'string', 'general', 'Güncelleme Kontrol URL', NOW(), NULL),
('smtp_encryption', 'tls', 'string', 'notification', 'SMTP Şifreleme', NOW(), NULL),
('smtp_host', '', 'string', 'notification', 'SMTP Sunucu', NOW(), NULL),
('smtp_pass', '', 'string', 'notification', 'SMTP Şifre', NOW(), NULL),
('smtp_port', '587', 'int', 'notification', 'SMTP Port', NOW(), NULL),
('smtp_user', '', 'string', 'notification', 'SMTP Kullanıcı', NOW(), NULL),
('sync_orders_enabled', '1', 'bool', 'sync', 'Sipariş senkronizasyonu', NOW(), NULL),
('sync_orders_interval', '5', 'int', 'sync', 'Sipariş çekme sıklığı (dk)', NOW(), NULL),
('sync_price_enabled', '1', 'bool', 'sync', 'Fiyat senkronizasyonu', NOW(), NULL),
('sync_price_interval', '30', 'int', 'sync', 'Fiyat gönderme sıklığı (dk)', NOW(), NULL),
('sync_products_enabled', '0', 'bool', 'sync', 'Ürün senkronizasyonu', NOW(), NULL),
('sync_products_interval', '60', 'int', 'sync', 'Ürün çekme sıklığı (dk)', NOW(), NULL),
('sync_questions_enabled', '1', 'bool', 'sync', 'Soru senkronizasyonu', NOW(), NULL),
('sync_questions_interval', '30', 'int', 'sync', 'Soru çekme sıklığı (dk)', NOW(), NULL),
('sync_returns_enabled', '1', 'bool', 'sync', 'İade senkronizasyonu', NOW(), NULL),
('sync_returns_interval', '15', 'int', 'sync', 'İade çekme sıklığı (dk)', NOW(), NULL),
('sync_stock_enabled', '1', 'bool', 'sync', 'Stok senkronizasyonu', NOW(), NULL),
('sync_stock_interval', '15', 'int', 'sync', 'Stok gönderme sıklığı (dk)', NOW(), NULL),
('timezone', 'Europe/Istanbul', 'string', 'general', 'Zaman Dilimi', NOW(), NULL);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` int(10) UNSIGNED NOT NULL,
  `movement_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manuel',
  `reference_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty_change` int(11) NOT NULL DEFAULT 0,
  `qty_before` int(11) NOT NULL DEFAULT 0,
  `qty_after` int(11) NOT NULL DEFAULT 0,
  `note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sm_product` (`product_id`),
  KEY `idx_sm_type` (`movement_type`),
  KEY `idx_sm_created` (`created_at`),
  KEY `fk_sm_user` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sync_jobs`
--

DROP TABLE IF EXISTS `sync_jobs`;
CREATE TABLE IF NOT EXISTS `sync_jobs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `job_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `status` enum('pending','running','done','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
  `error_message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enqueued_by` int(10) UNSIGNED DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  `finished_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sync_jobs_status` (`status`,`created_at`),
  KEY `idx_sync_jobs_marketplace` (`marketplace_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sync_logs`
--

DROP TABLE IF EXISTS `sync_logs`;
CREATE TABLE IF NOT EXISTS `sync_logs` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `marketplace_id` int(10) UNSIGNED DEFAULT NULL,
  `sync_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('running','success','partial','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `records_fetched` int(11) NOT NULL DEFAULT 0,
  `records_processed` int(11) NOT NULL DEFAULT 0,
  `records_failed` int(11) NOT NULL DEFAULT 0,
  `error_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `triggered_by` enum('cron','manual','api','initial_import') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  PRIMARY KEY (`id`),
  KEY `idx_sl_marketplace` (`marketplace_id`),
  KEY `idx_sl_type` (`sync_type`),
  KEY `idx_sl_status` (`status`),
  KEY `idx_sl_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','staff') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staff',
  `role_id` int(10) UNSIGNED DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `remember_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_email` (`email`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- users: Installer tarafından oluşturulacak — veri yok
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `xml_feeds`
--

DROP TABLE IF EXISTS `xml_feeds`;
CREATE TABLE IF NOT EXISTS `xml_feeds` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `feed_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generic',
  `format` enum('xml','json','csv') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xml',
  `filter_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `field_mapping_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `include_variants` tinyint(1) NOT NULL DEFAULT 1,
  `include_inactive` tinyint(1) NOT NULL DEFAULT 0,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_generated_at` datetime DEFAULT NULL,
  `generated_count` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `marketplace_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_xf_token` (`token`),
  KEY `idx_xf_active` (`is_active`),
  KEY `fk_xf_marketplace` (`marketplace_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dökümü yapılmış tablolar için kısıtlamalar
--

--
-- Tablo kısıtlamaları `api_logs`
--
ALTER TABLE `api_logs`
  ADD CONSTRAINT `api_logs_ibfk_1` FOREIGN KEY (`api_token_id`) REFERENCES `api_tokens` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `attribute_values`
--
ALTER TABLE `attribute_values`
  ADD CONSTRAINT `fk_av_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `product_attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `backups`
--
ALTER TABLE `backups`
  ADD CONSTRAINT `fk_backup_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Tablo kısıtlamaları `brand_aliases`
--
ALTER TABLE `brand_aliases`
  ADD CONSTRAINT `fk_brand_aliases_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `brand_marketplace_mapping`
--
ALTER TABLE `brand_marketplace_mapping`
  ADD CONSTRAINT `fk_bmm_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bmm_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_parent` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `category_attributes`
--
ALTER TABLE `category_attributes`
  ADD CONSTRAINT `fk_ca_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `product_attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ca_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `category_marketplace_mapping`
--
ALTER TABLE `category_marketplace_mapping`
  ADD CONSTRAINT `fk_cmm_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cmm_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `enrichment_logs`
--
ALTER TABLE `enrichment_logs`
  ADD CONSTRAINT `fk_el_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_el_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD CONSTRAINT `fk_ft_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `fk_invoices_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_related_invoice` FOREIGN KEY (`related_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_invoices_return_request` FOREIGN KEY (`return_request_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `marketplace_brands`
--
ALTER TABLE `marketplace_brands`
  ADD CONSTRAINT `fk_mb_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `marketplace_categories`
--
ALTER TABLE `marketplace_categories`
  ADD CONSTRAINT `fk_mc_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `marketplace_products`
--
ALTER TABLE `marketplace_products`
  ADD CONSTRAINT `fk_mp_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_mp_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_oi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `price_rules`
--
ALTER TABLE `price_rules`
  ADD CONSTRAINT `fk_pr_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_parent` FOREIGN KEY (`parent_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `product_attribute_values`
--
ALTER TABLE `product_attribute_values`
  ADD CONSTRAINT `fk_pav_attribute` FOREIGN KEY (`attribute_id`) REFERENCES `product_attributes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pav_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pav_value` FOREIGN KEY (`attribute_value_id`) REFERENCES `attribute_values` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `product_import_jobs`
--
ALTER TABLE `product_import_jobs`
  ADD CONSTRAINT `fk_pij_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pij_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `questions`
--
ALTER TABLE `questions`
  ADD CONSTRAINT `fk_q_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE;

--
-- Tablo kısıtlamaları `returns`
--
ALTER TABLE `returns`
  ADD CONSTRAINT `fk_returns_cancel_invoice` FOREIGN KEY (`cancel_invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_returns_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_returns_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `fk_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `fk_sm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_sm_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `sync_logs`
--
ALTER TABLE `sync_logs`
  ADD CONSTRAINT `fk_sl_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Tablo kısıtlamaları `xml_feeds`
--
ALTER TABLE `xml_feeds`
  ADD CONSTRAINT `fk_xf_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

