-- Faz 10 / 039 — Admin RBAC: özel rol oluşturucu (admin_roles) + admin_users.admin_role_id.
--   admin_users.admin_role_id NULL = Süper Admin sayılır (geri uyum: rolsüz admin TAM yetkili → kilitlenme yok).
--   Yetki modeli kod tarafında: App\Models\AdminRole::PERMISSION_MAP.
CREATE TABLE IF NOT EXISTS `admin_roles` (
  `id`               int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name`             varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`             varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`      text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_system`        tinyint(1) NOT NULL DEFAULT 0,
  `permissions_json` longtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`       datetime DEFAULT current_timestamp(),
  `updated_at`       datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_roles_slug` (`slug`),
  UNIQUE KEY `uq_admin_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sistem rolü: Süper Admin (tüm yetkiler). Silinemez/düzenlenemez.
INSERT IGNORE INTO `admin_roles` (`name`, `slug`, `is_system`, `permissions_json`, `description`)
VALUES ('Süper Admin', 'super-admin', 1, '["*"]', 'Tüm yetkiler (sistem rolü).');

ALTER TABLE `admin_users`
  ADD COLUMN IF NOT EXISTS `admin_role_id` int(10) unsigned DEFAULT NULL AFTER `is_active`,
  ADD INDEX IF NOT EXISTS `idx_admin_users_role` (`admin_role_id`);
