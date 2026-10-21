-- Faz 8 / 028 rollback — seed verilerini geri al (ters FK sırası).
DELETE FROM `subscriptions`  WHERE `id`=1 AND `store_id`=1 AND `package_id`=1;
DELETE FROM `package_limits` WHERE `package_id`=1;
DELETE FROM `packages`       WHERE `id`=1 AND `slug`='internal';
DELETE FROM `settings`       WHERE `group`='branding' AND `key` IN ('site_name','legal_name','brand_color');
