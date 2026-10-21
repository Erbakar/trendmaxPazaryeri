-- DOWN 018
ALTER TABLE `system_audit_log`
  DROP COLUMN IF EXISTS `admin_user_id`,
  DROP COLUMN IF EXISTS `actor_type`;
