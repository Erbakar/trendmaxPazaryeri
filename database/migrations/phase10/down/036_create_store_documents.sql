-- Faz 10 / 036 geri alma.
ALTER TABLE `stores` DROP COLUMN IF EXISTS `verified_at`;
DROP TABLE IF EXISTS `store_documents`;
DROP TABLE IF EXISTS `store_document_types`;
