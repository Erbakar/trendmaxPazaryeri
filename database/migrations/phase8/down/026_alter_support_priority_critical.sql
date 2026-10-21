-- Faz 8 / 026 rollback — priority enum'unu 3 değere geri al.
-- NOT: 'critical' atanmış ticket varsa önce normal'e çekilmeli (rollback test ortamında sorun değil).
UPDATE `support_tickets` SET `priority`='high' WHERE `priority`='critical';
ALTER TABLE `support_tickets`
  MODIFY COLUMN `priority` enum('low','normal','high') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal';
