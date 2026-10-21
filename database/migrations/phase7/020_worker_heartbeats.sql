-- Faz 7 / 020 — worker_heartbeats: worker durumu + son heartbeat + işlenen iş sayısı.
-- Cross-tenant altyapı tablosu (store_id YOK — worker'lar tüm mağazaların işini görür).
-- Worker her döngüde beat atar; ops dashboard "aktif worker" = son heartbeat eşik içinde olanlar.
CREATE TABLE IF NOT EXISTS `worker_heartbeats` (
  `id`                int(10) unsigned NOT NULL AUTO_INCREMENT,
  `worker_id`         varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `hostname`          varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pid`               int(10) unsigned DEFAULT NULL,
  `status`            enum('running','idle','stopped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `jobs_processed`    int(10) unsigned NOT NULL DEFAULT 0,
  `current_job_id`    int(10) unsigned DEFAULT NULL,
  `started_at`        datetime DEFAULT NULL,
  `last_heartbeat_at` datetime DEFAULT NULL,
  `stopped_at`        datetime DEFAULT NULL,
  `created_at`        datetime NOT NULL,
  `updated_at`        datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wh_worker` (`worker_id`),
  KEY `idx_wh_heartbeat` (`last_heartbeat_at`),
  KEY `idx_wh_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
