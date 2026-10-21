-- Faz 6 / 019 geri al — queue kolonlarını/indexleri kaldır, status'u eski enum'a döndür.
-- Not: 'dead' statüsündeki satırlar varsa MODIFY öncesi 'failed'e çekilir (veri kaybı yok).
UPDATE `sync_jobs` SET `status`='failed' WHERE `status`='dead';
-- Per-store (NULL marketplace) kuyruk işleri legacy modelde yoktu → NOT NULL'a dönmeden önce 0'la (FK yok).
UPDATE `sync_jobs` SET `marketplace_id`=0 WHERE `marketplace_id` IS NULL;
ALTER TABLE `sync_jobs` DROP INDEX IF EXISTS `idx_sj_claim`;
ALTER TABLE `sync_jobs` DROP INDEX IF EXISTS `idx_sj_worker`;
ALTER TABLE `sync_jobs`
  DROP COLUMN IF EXISTS `worker_id`,
  DROP COLUMN IF EXISTS `next_run_at`,
  MODIFY `marketplace_id` int(10) unsigned NOT NULL,
  MODIFY `status` enum('pending','running','done','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';
