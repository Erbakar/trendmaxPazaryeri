<?php
/**
 * OzyConnect Panel — Migration: backups tablosu
 *
 * GÜVENLİK: _guard.php zorunlu (CLI-only + canlı DB yazma koruması, migrate.php ile aynı).
 * Test:  php database/migrations/add_backups_table.php --database=ozyconnect_tenant_test
 * Canlı: --allow-live (YEDEK + onay sonrası)
 */
declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = require __DIR__ . '/_guard.php';

echo "Creating backups table...\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS backups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    type ENUM('manual','auto') NOT NULL DEFAULT 'manual',
    status ENUM('creating','completed','failed','restoring') NOT NULL DEFAULT 'creating',
    tables_count INT UNSIGNED NOT NULL DEFAULT 0,
    files_count INT UNSIGNED NOT NULL DEFAULT 0,
    manifest_json JSON NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_backups_status (status),
    INDEX idx_backups_type (type),
    INDEX idx_backups_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

echo "Done! backups table created.\n";
