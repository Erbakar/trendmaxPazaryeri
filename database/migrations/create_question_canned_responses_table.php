<?php
/**
 * Hazır Yanıt (canned response) tablosu — mağaza-bazlı soru cevap şablonları.
 * Tenant-scoped (store_id). Soru cevaplarken hızlı ekleme için.
 *
 * GÜVENLİK: _guard.php (CLI-only + canlı DB yazma koruması). Idempotent.
 * Test:  php database/migrations/create_question_canned_responses_table.php --database=ozyconnect_tenant_test
 * Canlı: php database/migrations/create_question_canned_responses_table.php --allow-live
 */
declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = require __DIR__ . '/_guard.php';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS `question_canned_responses` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `store_id` INT UNSIGNED NOT NULL,
        `title` VARCHAR(120) NOT NULL,
        `body` TEXT NOT NULL,
        `sort_order` INT NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_qcr_store` (`store_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "OK: question_canned_responses tablosu hazır.\n";
