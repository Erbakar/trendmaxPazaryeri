-- Faz 2 / 013 — Fatura & GİB ayarlarını GLOBAL settings'ten PER-STORE store_settings'e taşı.
-- Her mağaza kendi GİB hesabıyla fatura keser. Mevcut global değerler demo mağaza (store_id=1)
-- için kopyalanır; global `settings` satırları KORUNUR (geri-dönüş kolay olsun diye).
-- Idempotent: ON DUPLICATE KEY UPDATE (UNIQUE(store_id, skey)).
INSERT INTO store_settings (store_id, skey, svalue, created_at, updated_at)
SELECT 1, s.`key`, s.`value`, NOW(), NOW()
FROM settings s
WHERE s.`key` IN (
  'gib_test_mode','gib_username','gib_password','invoice_series','default_tax_rate',
  'invoice_auto_create','auto_return_invoice','seller_company_name','seller_tax_no',
  'seller_tax_office','seller_mersis_no','seller_address','seller_city','seller_district',
  'seller_phone','seller_email'
)
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), updated_at = NOW();
