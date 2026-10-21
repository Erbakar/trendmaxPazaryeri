-- DOWN 017 — kolonu geri ekle (rollback).
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_super_admin` tinyint(1) NOT NULL DEFAULT 0 AFTER `role_id`;
