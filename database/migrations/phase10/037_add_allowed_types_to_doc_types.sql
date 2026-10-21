-- Faz 10 / 037 — belge TÜRÜ başına kabul edilen dosya biçimleri.
--   store_document_types.allowed_types : virgülle ayrık format anahtarları
--   (pdf,jpg,png,gif,webp,word,excel). Mevcut satırlar önceki global set ile başlar.
ALTER TABLE `store_document_types`
  ADD COLUMN IF NOT EXISTS `allowed_types` varchar(255)
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pdf,jpg,png,webp' AFTER `description`;
