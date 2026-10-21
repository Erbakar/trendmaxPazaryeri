-- Faz 8 / 023 — Mağaza-oluştur başvuruları (GLOBAL, pre-tenant: başvuru anında henüz store yok;
-- password_resets ile aynı pre-tenant mantığı). Admin onaylayınca StoreProvisioningService store_id'yi doldurur.
CREATE TABLE IF NOT EXISTS `store_signup_requests` (
  `id`                   int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `company`              varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name`         varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone`                varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email`                varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requested_package_id` int(10) UNSIGNED DEFAULT NULL,
  `billing_cycle`        enum('monthly','yearly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `status`               enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `store_id`             int(10) UNSIGNED DEFAULT NULL,
  `reviewed_by`          int(10) UNSIGNED DEFAULT NULL,
  `notes`                text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip`                   varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`           datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`           datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_signup_status` (`status`, `created_at`),
  KEY `idx_signup_email` (`email`),
  KEY `idx_signup_store` (`store_id`),
  CONSTRAINT `fk_signup_package` FOREIGN KEY (`requested_package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_signup_store`   FOREIGN KEY (`store_id`)             REFERENCES `stores` (`id`)   ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
