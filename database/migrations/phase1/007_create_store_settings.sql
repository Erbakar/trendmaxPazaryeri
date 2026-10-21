-- Faz 1 / 007 — Per-store ayarlar. Global `settings` (key PK) DEĞİŞMEZ (D5);
-- mağaza-özel ayarlar burada tutulur.

CREATE TABLE IF NOT EXISTS `store_settings` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`   int(10) UNSIGNED NOT NULL,
  `skey`       varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `svalue`     longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_settings_store_key` (`store_id`, `skey`),
  CONSTRAINT `fk_store_settings_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
