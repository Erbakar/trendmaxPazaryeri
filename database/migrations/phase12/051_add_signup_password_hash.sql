-- Faz 12 / 051 — Mağaza başvurusunda formda belirlenen şifre hash'i
-- store_signup_requests tablosunda saklanır; provizyonda doğrudan kullanılır.
-- MySQL 5.7+ uyumlu, idempotent (tekrar çalıştırılabilir).

SET @dbname = DATABASE();

SELECT COUNT(*) INTO @col_exists FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'store_signup_requests' AND COLUMN_NAME = 'password_hash';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `store_signup_requests` ADD COLUMN `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `source`',
    'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
