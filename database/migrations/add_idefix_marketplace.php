<?php
/**
 * İdefix pazaryeri ekleme migration'ı.
 *
 * GÜVENLİK: _guard.php zorunlu (CLI-only + canlı DB yazma koruması, migrate.php ile aynı).
 * Test:  php database/migrations/add_idefix_marketplace.php --database=ozyconnect_tenant_test
 * Canlı: --allow-live (YEDEK + onay sonrası)
 */
declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = require __DIR__ . '/_guard.php';

// İdefix zaten var mı kontrol et
$stmt = $pdo->prepare("SELECT COUNT(*) FROM `marketplaces` WHERE `slug` = 'idefix'");
$stmt->execute();

if ((int) $stmt->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO `marketplaces` (`slug`, `display_name`, `is_active`) VALUES ('idefix', 'İdefix', 0)");
    echo "OK: Idefix pazaryeri eklendi.\n";
} else {
    echo "Bilgi: Idefix zaten mevcut.\n";
}
