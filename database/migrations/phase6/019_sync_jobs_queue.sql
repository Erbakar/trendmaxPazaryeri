-- Faz 6 / 019 — sync_jobs'ı tam kuyruğa çevir: next_run_at (backoff), worker_id (atomik claim),
-- status'a 'dead' (dead letter). Index: claim sorgusu (status, next_run_at).
ALTER TABLE `sync_jobs`
  ADD COLUMN IF NOT EXISTS `next_run_at` datetime DEFAULT NULL AFTER `max_attempts`,
  ADD COLUMN IF NOT EXISTS `worker_id`   varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `next_run_at`,
  -- Per-store job'lar (order/return/stock/price/question) belirli bir pazaryerine bağlı
  -- değildir → marketplace_id NULL olabilmeli. FK yok (yalnız store_id FK'li), güvenli.
  MODIFY `marketplace_id` int(10) unsigned DEFAULT NULL,
  MODIFY `status` enum('pending','running','done','failed','dead') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending';

ALTER TABLE `sync_jobs` ADD INDEX IF NOT EXISTS `idx_sj_claim` (`status`, `next_run_at`);
ALTER TABLE `sync_jobs` ADD INDEX IF NOT EXISTS `idx_sj_worker` (`worker_id`);
