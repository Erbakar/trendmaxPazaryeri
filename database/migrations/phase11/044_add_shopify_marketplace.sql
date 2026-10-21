-- 044_add_shopify_marketplace.sql — Shopify pazaryeri kaydı (e-ticaret platform entegrasyonu Faz 1)
-- İdempotent: slug UNIQUE → ON DUPLICATE KEY UPDATE. is_active=0 (bağlanınca aktifleşir),
-- is_sellable=0 (şimdilik ücretli eklenti olarak satışta değil).
INSERT INTO `marketplaces` (`slug`, `display_name`, `is_active`, `is_sellable`, `price_monthly`)
VALUES ('shopify', 'Shopify', 0, 0, 0.00)
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);
