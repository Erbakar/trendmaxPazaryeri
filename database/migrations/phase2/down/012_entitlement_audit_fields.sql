-- DOWN 012
ALTER TABLE `store_marketplaces`
  DROP COLUMN IF EXISTS `granted_by`, DROP COLUMN IF EXISTS `revoked_by`,
  DROP COLUMN IF EXISTS `revoked_at`, DROP COLUMN IF EXISTS `reason`, DROP COLUMN IF EXISTS `notes`;
ALTER TABLE `store_modules`
  DROP COLUMN IF EXISTS `granted_by`, DROP COLUMN IF EXISTS `revoked_by`,
  DROP COLUMN IF EXISTS `revoked_at`, DROP COLUMN IF EXISTS `reason`, DROP COLUMN IF EXISTS `notes`;
