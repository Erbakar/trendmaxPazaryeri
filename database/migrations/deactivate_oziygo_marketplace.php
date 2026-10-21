<?php
/**
 * OziyGO pazaryerini PASİFLEŞTİRİR (is_active=0) — kullanıcı isteği.
 * Pazaryeri kataloğundan/dashboard'dan/listelerden düşer; sipariş FK'ları KORUNUR (hard delete yok).
 *
 * GÜVENLİK: _guard.php zorunlu (CLI-only + canlı DB yazma koruması).
 * Test:  php database/migrations/deactivate_oziygo_marketplace.php --database=ozyconnect_tenant_test
 * Canlı: php database/migrations/deactivate_oziygo_marketplace.php --allow-live
 */
declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = require __DIR__ . '/_guard.php';

$row = $pdo->query("SELECT id, slug, display_name, is_active FROM `marketplaces` WHERE `slug` = 'oziygo' LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "oziygo pazaryeri bulunamadı (zaten yok).\n";
    return;
}

echo "ÖNCE: id={$row['id']} display_name={$row['display_name']} is_active={$row['is_active']}\n";

if ((int) $row['is_active'] === 0) {
    echo "oziygo zaten pasif (is_active=0) — değişiklik yok.\n";
    return;
}

$pdo->prepare("UPDATE `marketplaces` SET `is_active` = 0, `updated_at` = NOW() WHERE `id` = :id")
    ->execute(['id' => (int) $row['id']]);

$after = $pdo->query("SELECT is_active FROM `marketplaces` WHERE `slug` = 'oziygo'")->fetchColumn();
echo "SONRA: is_active={$after} — oziygo pasifleştirildi (dashboard/listelerden düştü).\n";
