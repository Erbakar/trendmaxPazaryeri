-- Faz 9 / 033 — Mağaza modül add-on'ları (aylık ek; pakette OLMAYAN modülün satın alımı).
-- EntitlementResolver::addonsFor bunları okur → PackageEntitlementSync hedef kümeye ekler → modül açılır.
CREATE TABLE IF NOT EXISTS `store_addons` (
  `id`            int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`      int(10) UNSIGNED NOT NULL,
  `module_key`    varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price_monthly` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency`      char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `billing_cycle` enum('monthly','yearly') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly',
  `status`        enum('active','cancelled','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `purchased_at`  datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at`    datetime DEFAULT NULL,
  `cancelled_at`  datetime DEFAULT NULL,
  `created_at`    datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`    datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_addon_store_module` (`store_id`, `module_key`),
  KEY `idx_addon_status` (`store_id`, `status`),
  CONSTRAINT `fk_addon_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
