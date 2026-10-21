-- GİB e-Arşiv ayarları
INSERT IGNORE INTO `settings` (`key`, `value`, `type`, `group`, `label`) VALUES
('gib_username',  '', 'string', 'invoice', 'GİB Kullanıcı Adı'),
('gib_password',  '', 'string', 'invoice', 'GİB Şifre'),
('gib_test_mode', '1', 'bool',  'invoice', 'GİB Test Modu');
