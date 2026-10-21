-- Faz 2 / 010 — store_marketplaces (ENTITLEMENT: mağaza hangi pazaryerini SATIN ALDI/yetkili).
-- NOT: phase1'deki store_marketplace_configs = bağlantı/credential (ayrı kavram).
-- Etkin pazaryeri erişimi = status='active' AND (expires_at IS NULL OR expires_at > NOW()).
CREATE TABLE IF NOT EXISTS `store_marketplaces` (
  `id`             int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`       int(10) UNSIGNED NOT NULL,
  `marketplace_id` int(10) UNSIGNED NOT NULL,
  `status`         enum('active','passive','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `granted_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at`     datetime DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at`     datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sm_store_mp` (`store_id`, `marketplace_id`),
  KEY `idx_sm_marketplace` (`marketplace_id`),
  KEY `idx_sm_store_status` (`store_id`, `status`),
  CONSTRAINT `fk_sm_store`       FOREIGN KEY (`store_id`)       REFERENCES `stores` (`id`)       ON DELETE CASCADE  ON UPDATE CASCADE,
  CONSTRAINT `fk_sm_marketplace` FOREIGN KEY (`marketplace_id`) REFERENCES `marketplaces` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
