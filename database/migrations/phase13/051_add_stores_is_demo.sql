-- MySQL 5.7+/MariaDB: ADD COLUMN IF NOT EXISTS is MariaDB-specific.
SET @demo_column_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stores' AND column_name='is_demo'),
    'DO 0',
    'ALTER TABLE `stores` ADD COLUMN `is_demo` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''1=vitrin/demo (yazma işlemleri kapalı)'' AFTER `verified_at`'
);
PREPARE demo_column_stmt FROM @demo_column_sql;
EXECUTE demo_column_stmt;
DEALLOCATE PREPARE demo_column_stmt;
