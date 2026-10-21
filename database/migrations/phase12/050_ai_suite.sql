-- ════════════════════════════════════════════════════════════════════════
-- Faz 12 / 050 — AI Suite altyapısı (kredi cüzdanı, kullanım logu, danışman,
-- trend) + ürün/pazaryeri AI alanları + module_catalog AI modülleri.
-- Tamamen idempotent: CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS /
-- INSERT IGNORE. MariaDB 10.3+. utf8mb4_unicode_ci.
-- ════════════════════════════════════════════════════════════════════════

-- ── 1) Kredi cüzdanı (mağaza başına tek satır; tam-sayı kredi) ──
CREATE TABLE IF NOT EXISTS `store_credits` (
  `id`              int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`        int(10) UNSIGNED NOT NULL,
  `balance`         int(11) NOT NULL DEFAULT 0,
  `total_purchased` int(11) NOT NULL DEFAULT 0,
  `total_used`      int(11) NOT NULL DEFAULT 0,
  `created_at`      datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`      datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_store` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2) Kredi hareket defteri (satın alma / tahsis / kullanım / iade / düzeltme) ──
CREATE TABLE IF NOT EXISTS `store_credit_transactions` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`      int(10) UNSIGNED NOT NULL,
  `type`          enum('purchase','grant','usage','refund','adjust') NOT NULL,
  `amount`        int(11) NOT NULL,            -- + ekleme, - kullanım
  `balance_after` int(11) NOT NULL,
  `action`        varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- ör: ai_content
  `ref_type`      varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- ör: subscription_invoice / product
  `ref_id`        int(10) UNSIGNED DEFAULT NULL,
  `note`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by`    int(10) UNSIGNED DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_ctx_store` (`store_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3) AI kullanım logu (gerçek token/maliyet — operatör marjı) ──
CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`      int(10) UNSIGNED NOT NULL,
  `user_id`       int(10) UNSIGNED DEFAULT NULL,
  `module_key`    varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action`        varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider`      varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model`         varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `input_tokens`  int(10) UNSIGNED NOT NULL DEFAULT 0,
  `output_tokens` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `cost_usd`      decimal(12,6) NOT NULL DEFAULT 0.000000,
  `credits`       int(11) NOT NULL DEFAULT 0,
  `latency_ms`    int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status`        varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success',
  `error`         varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_aiusage_store` (`store_id`, `created_at`),
  KEY `ix_aiusage_module` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4) AI Satış Danışmanı sohbetleri (#6) ──
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`      int(10) UNSIGNED NOT NULL,
  `user_id`       int(10) UNSIGNED DEFAULT NULL,
  `title`         varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `messages_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- [{role,content,ts}]
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_aiconv_store` (`store_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5) AI Trend Merkezi önbelleği (#7 — cron'la günlük üretilir) ──
CREATE TABLE IF NOT EXISTS `ai_trends` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`      int(10) UNSIGNED NOT NULL,
  `trend_date`    date NOT NULL,
  `kind`          enum('rising','falling','seasonal','opportunity') NOT NULL,
  `payload_json`  longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,  -- hesaplanan liste + AI özeti
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aitrend` (`store_id`, `trend_date`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6) Ürün AI alanları ──
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `technical_specs` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `seo_description`,
  ADD COLUMN IF NOT EXISTS `ai_generated_at` datetime DEFAULT NULL AFTER `technical_specs`;

-- ── 7) Pazaryeri-özel AI açıklaması ──
ALTER TABLE `marketplace_products`
  ADD COLUMN IF NOT EXISTS `ai_description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `product_name`;

-- ── 8) Kredi paketi faturası için kind='credit' ──
ALTER TABLE `subscription_invoices`
  MODIFY `kind` enum('subscription','upgrade','addon','credit') NOT NULL DEFAULT 'subscription';

-- ── 9) Pakete aylık AI kredi tahsisi ──
ALTER TABLE `packages`
  ADD COLUMN IF NOT EXISTS `monthly_credits` int(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `trial_days`;

-- ── 10) module_catalog: 7 AI modülü (admin fiyat/satılabilirlik düzenler). Idempotent. ──
INSERT IGNORE INTO `module_catalog` (`module_key`,`label`,`description`,`price_monthly`,`is_sellable`,`sort_order`) VALUES
  ('ai_content',   'AI İçerik Asistanı',        'Ürün adından SEO, açıklama ve teknik özellik üretimi.', 199.00, 1, 20),
  ('ai_bulk',      'AI Toplu Doldurma',         'Yüzlerce ürünü tek tıkla AI ile doldurma.',             299.00, 1, 21),
  ('ai_vision',    'AI Görsel Analizi',         'Üründen kategori/marka/renk/özellik çıkarımı.',          199.00, 1, 22),
  ('ai_optimize',  'AI Pazaryeri Optimizasyonu','Neden satmıyor + iyileştirme önerileri.',                199.00, 1, 23),
  ('ai_competitor','AI Rakip Analizi',          'SKU bazlı rakip fiyat/başlık analizi.',                  249.00, 1, 24),
  ('ai_advisor',   'AI Satış Danışmanı',        'Satış/stok/finans sorularına AI yanıt.',                 299.00, 1, 25),
  ('ai_trends',    'AI Trend Merkezi',          'Yükselen/düşen/fırsat ürün trendleri.',                  149.00, 1, 26);
