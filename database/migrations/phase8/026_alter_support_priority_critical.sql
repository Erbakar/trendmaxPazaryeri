-- Faz 8 / 026 — support_tickets.priority enum'una 'critical' ekle (additive; mevcut değerler korunur).
-- Re-apply güvenli (aynı enum'a MODIFY = no-op etki). Admin dashboard "Açık Kritik Ticket" widget'ı için.
ALTER TABLE `support_tickets`
  MODIFY COLUMN `priority` enum('low','normal','high','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal';
