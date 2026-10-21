-- Faz 8 / 028 — Marka varsayılanları + REGRESYON KALKANI (store#1 bozulmaz).
-- (a) Setting group='branding' temel anahtarlar — admin değeri varsa EZİLMEZ (ON DUPLICATE no-op).
-- (b) Internal/Unlimited paket (id=1, hidden, tüm limitler NULL, package_modules/marketplaces BOŞ).
-- (c) store#1'e active subscription (2099) → lifecycle cron asla expire etmez, PackageEntitlementSync hiç
--     çağrılmaz (paket tanımı boş), mevcut elle-verilmiş entitlement'lar korunur, UsageService sınırsız.

INSERT INTO `settings` (`key`,`value`,`type`,`group`,`label`) VALUES
 ('site_name','OzyConnect','string','branding','Sistem Adı'),
 ('legal_name','OzyConnect','string','branding','Firma Ünvanı'),
 ('brand_color','91 92 235','string','branding','Marka Rengi (RGB)')
ON DUPLICATE KEY UPDATE `key`=`key`;

INSERT INTO `packages` (`id`,`name`,`slug`,`description`,`price_monthly`,`price_yearly`,`currency`,`trial_days`,`is_internal`,`is_active`,`status`,`sort_order`)
VALUES (1,'Internal / Unlimited','internal','Dahili sınırsız paket (store#1).',0.00,0.00,'TRY',0,1,0,'active',0)
ON DUPLICATE KEY UPDATE `id`=`id`;

INSERT INTO `package_limits` (`package_id`,`max_products`,`max_orders_month`,`max_users`,`max_api_tokens`,`api_rate_per_min`)
VALUES (1,NULL,NULL,NULL,NULL,NULL)
ON DUPLICATE KEY UPDATE `package_id`=`package_id`;

INSERT INTO `subscriptions` (`id`,`store_id`,`package_id`,`status`,`billing_cycle`,`started_at`,`current_period_start`,`current_period_end`)
VALUES (1,1,1,'active','yearly',NOW(),NOW(),'2099-12-31 00:00:00')
ON DUPLICATE KEY UPDATE `store_id`=`store_id`;
