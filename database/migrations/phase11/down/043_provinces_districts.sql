-- down/043_provinces_districts.sql — geri alma
ALTER TABLE `stores`
  DROP COLUMN IF EXISTS `billing_postcode`,
  DROP COLUMN IF EXISTS `district_id`,
  DROP COLUMN IF EXISTS `province_id`;
DROP TABLE IF EXISTS `districts`;
DROP TABLE IF EXISTS `provinces`;
