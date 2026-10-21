-- Faz 8 / 021 — Paket sistemi (GLOBAL ticari tablolar; tenantScoped=false modeller).
-- packages + package_marketplaces + package_modules + package_limits.
-- Paket = pazaryeri + modül + limit demeti. EntitlementService satır-bazlı çalıştığı için
-- JSON yerine junction tablolar (FK bütünlüğü + admin checkbox UI). Limit NULL = sınırsız.

CREATE TABLE IF NOT EXISTS `packages` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`          varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`   text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_monthly` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_yearly`  decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency`      char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `trial_days`    smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `is_internal`   tinyint(1) NOT NULL DEFAULT 0,
  `is_active`     tinyint(1) NOT NULL DEFAULT 1,
  `status`        enum('draft','active','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `sort_order`    smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `deleted_at`    datetime DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_packages_slug` (`slug`),
  KEY `idx_packages_active` (`is_active`, `status`, `sort_order`),
  KEY `idx_packages_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `package_marketplaces` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id`     int(10) UNSIGNED NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pkg_mp` (`package_id`, `marketplace_id`),
  KEY `idx_pkg_mp_mp` (`marketplace_id`),
  CONSTRAINT `fk_pkg_mp_package`     FOREIGN KEY (`package_id`)     REFERENCES `packages` (`id`)     ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_pkg_mp_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE CASCADE  ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `package_modules` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id` int(10) UNSIGNED NOT NULL,
  `module_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pkg_mod` (`package_id`, `module_key`),
  CONSTRAINT `fk_pkg_mod_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `package_limits` (
  `id`               int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `package_id`       int(10) UNSIGNED NOT NULL,
  `max_products`     int(10) UNSIGNED DEFAULT NULL,
  `max_orders_month` int(10) UNSIGNED DEFAULT NULL,
  `max_users`        int(10) UNSIGNED DEFAULT NULL,
  `max_api_tokens`   int(10) UNSIGNED DEFAULT NULL,
  `api_rate_per_min` int(10) UNSIGNED DEFAULT NULL,
  `created_at`       datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`       datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pkg_limits` (`package_id`),
  CONSTRAINT `fk_pkg_limits_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
