-- down/044_add_shopify_marketplace.sql — geri alma
DELETE FROM `marketplaces` WHERE `slug` = 'shopify';
