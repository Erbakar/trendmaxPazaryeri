-- Faz 3 / 017 — interim users.is_super_admin TAMAMEN KALDIRILIYOR.
-- Admin kimliği artık admin_users tablosunda (Faz 3). Kod tarafında hiçbir referans kalmadı.
ALTER TABLE `users` DROP COLUMN IF EXISTS `is_super_admin`;
