-- Geri alma: demo mağazaya (store_id=1) kopyalanan fatura/GİB anahtarlarını store_settings'ten sil.
-- Global `settings` satırlarına dokunulmaz (up onları silmemişti).
DELETE FROM store_settings WHERE store_id = 1 AND skey IN (
  'gib_test_mode','gib_username','gib_password','invoice_series','default_tax_rate',
  'invoice_auto_create','auto_return_invoice','seller_company_name','seller_tax_no',
  'seller_tax_office','seller_mersis_no','seller_address','seller_city','seller_district',
  'seller_phone','seller_email'
);
