-- Faz 1 / 006 — Per-store pazaryeri yapılandırması (credential'lar şifreli).
-- `marketplaces` GLOBAL referans olarak kalır (D2/D5); per-store bağlantı bilgisi burada.
-- Gerçek per-store credential KULLANIMI Faz 4'te devreye girer (şu an iskelet).

CREATE TABLE IF NOT EXISTS `store_marketplace_configs` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`       int(10) UNSIGNED NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `is_enabled`     tinyint(1) NOT NULL DEFAULT 0,
  `credentials_encrypted` text COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'AES ile şifreli API anahtar/secret',
  `settings_json`  longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `last_sync_at`   datetime DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_smc_store_mp` (`store_id`, `marketplace_id`),
  KEY `idx_smc_marketplace` (`marketplace_id`),
  CONSTRAINT `fk_smc_store`       FOREIGN KEY (`store_id`)       REFERENCES `stores` (`id`)       ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_smc_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
