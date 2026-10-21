-- ============================================================================
--  PazarConnect — Başvuruda şifre belirleme
--  25.08.2026
--
--  Mağaza başvurusu yapan firma artık şifresini FORMDA kendisi belirliyor.
--  Böylece hesap açıldığında e-posta beklemeye gerek kalmıyor; posta
--  ulaşmasa bile firma hemen giriş yapabiliyor.
--
--  Tekrar çalıştırılabilir, veri kaybetmez.
-- ============================================================================

-- ─── store_signup_requests.password_hash ────────────────────────────────────
SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE `store_signup_requests`
            ADD COLUMN `password_hash` VARCHAR(255) NULL DEFAULT NULL
                COMMENT ''Basvuruda firmanin belirledigi sifre (bcrypt)''
                AFTER `email`',
        'SELECT 1'
    )
    FROM `information_schema`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME`   = 'store_signup_requests'
      AND `COLUMN_NAME`  = 'password_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Şifre sıfırlama bağlantısının ömrü ─────────────────────────────────────
-- 15 dakika çok kısaydı: firma e-postayı açana kadar bağlantı ölüyor,
-- şifre belirlemeye çalışınca sessizce başarısız oluyor ve giriş
-- "e-posta veya şifre hatalı" veriyordu. Dakika cinsinden saklanır.
INSERT INTO `settings` (`key`, `value`, `type`, `group`, `label`, `updated_at`)
VALUES ('password_reset_lifetime_minutes', '1440', 'int', 'registration',
        'Şifre belirleme bağlantısının ömrü (dakika)', NOW())
ON DUPLICATE KEY UPDATE `key` = `key`;
