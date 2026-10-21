-- Faz 2 / 011 — store_modules (ENTITLEMENT: mağaza hangi modülü SATIN ALDI/yetkili).
-- module_key örn: orders, products, shipping (kargo), finance (finans), questions, returns,
--   invoices (fatura), reports (raporlama), feeds, import.
-- Etkin modül erişimi = status='active' AND (expires_at IS NULL OR expires_at > NOW()).
CREATE TABLE IF NOT EXISTS `store_modules` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`   int(10) UNSIGNED NOT NULL,
  `module_key` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`     enum('active','passive','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_smod_store_module` (`store_id`, `module_key`),
  KEY `idx_smod_store_status` (`store_id`, `status`),
  CONSTRAINT `fk_smod_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
