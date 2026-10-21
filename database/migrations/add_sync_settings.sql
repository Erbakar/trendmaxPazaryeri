INSERT INTO `settings` (`key`, `value`, `type`, `group`, `label`) VALUES
('sync_orders_enabled', '1', 'bool', 'sync', 'Sipariş senkronizasyonu'),
('sync_orders_interval', '5', 'int', 'sync', 'Sipariş çekme sıklığı (dk)'),
('sync_stock_enabled', '1', 'bool', 'sync', 'Stok senkronizasyonu'),
('sync_stock_interval', '15', 'int', 'sync', 'Stok gönderme sıklığı (dk)'),
('sync_price_enabled', '1', 'bool', 'sync', 'Fiyat senkronizasyonu'),
('sync_price_interval', '30', 'int', 'sync', 'Fiyat gönderme sıklığı (dk)'),
('sync_returns_enabled', '1', 'bool', 'sync', 'İade senkronizasyonu'),
('sync_returns_interval', '15', 'int', 'sync', 'İade çekme sıklığı (dk)'),
('sync_questions_enabled', '1', 'bool', 'sync', 'Soru senkronizasyonu'),
('sync_questions_interval', '30', 'int', 'sync', 'Soru çekme sıklığı (dk)'),
('sync_products_enabled', '0', 'bool', 'sync', 'Ürün senkronizasyonu'),
('sync_products_interval', '60', 'int', 'sync', 'Ürün çekme sıklığı (dk)')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);
