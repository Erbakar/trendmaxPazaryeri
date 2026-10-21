-- Faz 8 / 027 rollback — store_id kolon + index'lerini kaldır.
ALTER TABLE `backups`          DROP INDEX IF EXISTS `idx_backups_store`;
ALTER TABLE `backups`          DROP COLUMN IF EXISTS `store_id`;
ALTER TABLE `system_audit_log` DROP INDEX IF EXISTS `idx_sal_store`;
ALTER TABLE `system_audit_log` DROP COLUMN IF EXISTS `store_id`;
