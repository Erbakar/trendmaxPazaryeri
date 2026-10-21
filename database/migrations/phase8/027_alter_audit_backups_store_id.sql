-- Faz 8 / 027 — Mağaza-bazlı gözlem: system_audit_log + backups tablolarına store_id (additive, nullable).
-- "Hangi mağazada hangi hata / hangi mağazanın yedeği" admin filtresi için. NULL = global/tam-sistem.
ALTER TABLE `system_audit_log`
  ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED DEFAULT NULL AFTER `admin_user_id`;
ALTER TABLE `system_audit_log`
  ADD INDEX IF NOT EXISTS `idx_sal_store` (`store_id`);

ALTER TABLE `backups`
  ADD COLUMN IF NOT EXISTS `store_id` int(10) UNSIGNED DEFAULT NULL AFTER `id`;
ALTER TABLE `backups`
  ADD INDEX IF NOT EXISTS `idx_backups_store` (`store_id`);
-- FK ayrı statement (idempotent değil; var olabilir → IF kontrolü için information_schema; basit tutmak adına
-- FK eklenmez, yalnız index. Silinen mağazada backups satırı kalsa da store_id sadece etiket/filtre amaçlı).
