<?php
/**
 * OziyGO pazaryeri kurulum migration'ı (yalnız marketplaces satırını ekler/günceller;
 * credential'lar panelden girilir).
 *
 * GÜVENLİK: _guard.php zorunlu (CLI-only + canlı DB yazma koruması, migrate.php ile aynı).
 * Test:  php database/migrations/add_oziygo_marketplace.php --database=ozyconnect_tenant_test
 * Canlı: --allow-live (YEDEK + onay sonrası)
 */
declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = require __DIR__ . '/_guard.php';

$features = json_encode([
    'products' => true,
    'orders' => true,
    'stock' => true,
    'price' => true,
    'categories' => true,
    'brands' => true,
    'returns' => false,
    'questions' => false,
], JSON_UNESCAPED_UNICODE);

$stmt = $pdo->prepare("SELECT id FROM `marketplaces` WHERE `slug` = 'oziygo' LIMIT 1");
$stmt->execute();
$id = $stmt->fetchColumn();

if ($id === false) {
    $insert = $pdo->prepare("
        INSERT INTO `marketplaces`
            (`slug`, `display_name`, `is_active`, `supported_features_json`, `created_at`, `updated_at`)
        VALUES
            ('oziygo', 'OziyGO', 0, :features, NOW(), NOW())
    ");
    $insert->execute(['features' => $features]);
    echo "OK: OziyGO pazaryeri eklendi.\n";
} else {
    $update = $pdo->prepare("
        UPDATE `marketplaces`
        SET `display_name` = 'OziyGO',
            `supported_features_json` = :features,
            `updated_at` = NOW()
        WHERE `id` = :id
    ");
    $update->execute(['features' => $features, 'id' => (int) $id]);
    echo "OK: OziyGO pazaryeri zaten vardi, guncellendi.\n";
}
