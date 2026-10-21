-- Faz 1 / 001 — stores kök tablosu + varsayılan "Ana Mağaza" (store_id=1, backfill hedefi)
-- store_id kolonları int(10) unsigned olacağından stores.id de int(10) unsigned (FK uyumu).

CREATE TABLE IF NOT EXISTS `stores` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`       varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`     enum('active','suspended','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `plan`       varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stores_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Varsayılan mağaza: mevcut tek-tenant verinin tamamı buraya backfill edilir.
-- ON DUPLICATE KEY ile idempotent (yeniden çalıştırmada no-op).
INSERT INTO `stores` (`id`, `name`, `slug`, `status`)
VALUES (1, 'Ana Mağaza', 'ana-magaza', 'active')
ON DUPLICATE KEY UPDATE `id` = `id`;
