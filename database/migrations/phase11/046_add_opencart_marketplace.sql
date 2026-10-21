-- 046_add_opencart_marketplace.sql — OpenCart pazaryeri kaydı (e-ticaret platform entegrasyonu Faz 3)
-- İdempotent: slug UNIQUE → ON DUPLICATE KEY UPDATE. is_active=0 (bağlanınca aktifleşir),
-- is_sellable=0 (şimdilik ücretli eklenti olarak satışta değil).
-- Bağlantı: OzyConnect OpenCart Connector (mağazaya kurulan token korumalı ozyconnect.php köprüsü).
INSERT INTO `marketplaces` (`slug`, `display_name`, `is_active`, `is_sellable`, `price_monthly`)
VALUES ('opencart', 'OpenCart', 0, 0, 0.00)
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`);
