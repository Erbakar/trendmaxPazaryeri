-- Faz 13 / 051 — Vitrin (demo) mağaza: panelde salt okunur önizleme modu.
ALTER TABLE `stores`
  ADD COLUMN IF NOT EXISTS `is_demo` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=vitrin/demo (yazma işlemleri kapalı)' AFTER `verified_at`;
