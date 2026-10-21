-- DOWN 039 — Admin RBAC geri al.
ALTER TABLE `admin_users` DROP COLUMN IF EXISTS `admin_role_id`;
DROP TABLE IF EXISTS `admin_roles`;
