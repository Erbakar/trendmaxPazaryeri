-- Faz 8 / 025 — Mail şablonları + in-app bildirim merkezi.
-- email_templates: admin-düzenlenebilir; NotificationService DB sürümünü render eder, yoksa kod-içi fallback.
-- notifications: in-app bildirim (DUYURUDAN AYRI). Navbar zili buradan beslenir.
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`        varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject`    varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html`  longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `vars_hint`  varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active`  tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emailtpl_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`         int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `store_id`   int(10) UNSIGNED NOT NULL,
  `user_id`    int(10) UNSIGNED DEFAULT NULL,
  `type`       enum('subscription','payment','support','system') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `title`      varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body`       varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `link`       varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read`    tinyint(1) NOT NULL DEFAULT 0,
  `read_at`    datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_store_read` (`store_id`, `is_read`, `created_at`),
  CONSTRAINT `fk_notif_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6 varsayılan mail şablonu (idempotent: key UNIQUE → ON DUPLICATE KEY no-op, admin düzenlemesini ezmez).
INSERT INTO `email_templates` (`key`,`subject`,`body_html`,`vars_hint`,`is_active`) VALUES
 ('welcome','{site_name} ailesine hoş geldiniz','<p>Merhaba {name},</p><p>{site_name} hesabınız oluşturuldu. Şifrenizi belirlemek için: <a href="{reset_url}">{reset_url}</a></p>','{site_name},{name},{reset_url}',1),
 ('password_reset','{site_name} — Şifre sıfırlama','<p>Merhaba {name},</p><p>Şifrenizi sıfırlamak için: <a href="{reset_url}">{reset_url}</a></p><p>Bu isteği siz yapmadıysanız yok sayın.</p>','{site_name},{name},{reset_url}',1),
 ('subscription_ended','{site_name} — Aboneliğiniz sona erdi','<p>Merhaba {name},</p><p>{package} paketi aboneliğiniz {date} tarihinde sona erdi. Devam etmek için ödeme yapabilirsiniz.</p>','{site_name},{name},{package},{date}',1),
 ('trial_ending','{site_name} — Deneme süreniz bitiyor','<p>Merhaba {name},</p><p>Deneme süreniz {days} gün sonra ({date}) sona erecek. Kesintisiz devam için paketinizi etkinleştirin.</p>','{site_name},{name},{days},{date}',1),
 ('payment_confirmed','{site_name} — Ödemeniz onaylandı','<p>Merhaba {name},</p><p>{amount} tutarındaki ödemeniz onaylandı, aboneliğiniz aktif. Teşekkürler.</p>','{site_name},{name},{amount}',1),
 ('support_replied','{site_name} — Destek talebinize yanıt geldi','<p>Merhaba {name},</p><p>#{ticket} numaralı destek talebinize yanıt verildi. Görüntülemek için panele giriş yapın.</p>','{site_name},{name},{ticket}',1)
ON DUPLICATE KEY UPDATE `key`=`key`;
