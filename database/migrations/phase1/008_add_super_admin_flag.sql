-- Faz 1b / R1 — Super Admin (platform operatörü) bayrağı.
-- TENANT kullanıcıları (mağaza admini dahil) bu bayrağa SAHİP DEĞİLDİR (default 0).
-- Yalnızca platform operatörü = 1. Sistem yedek/geri-yükleme bu bayrağı gerektirir.
-- Faz 3'te admin_users tablosuna taşınacak; bu interim ileriye uyumlu.
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `is_super_admin` tinyint(1) NOT NULL DEFAULT 0 AFTER `role_id`;
