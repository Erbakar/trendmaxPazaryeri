<?php
/**
 * OziyGO pazaryerini ve ona bağlı TÜM kayıtları (siparişler + alt kalemler dahil) HARD DELETE eder.
 * Kullanıcı onayı: "Siparişleri de sil, oziygo'yu tamamen kaldır."
 *
 * FK-child tablolar information_schema'dan bulunur; FK kontrolü kapatılıp transaction içinde silinir.
 * Canlı: php database/migrations/delete_oziygo_marketplace.php --allow-live
 */
declare(strict_types=1);

/** @var \PDO $pdo */
$pdo = require __DIR__ . '/_guard.php';

$id = $pdo->query("SELECT id FROM marketplaces WHERE slug='oziygo' LIMIT 1")->fetchColumn();
if ($id === false) { echo "oziygo zaten yok.\n"; return; }
$id = (int) $id;

// oziygo siparişleri
$orderIds = $pdo->query("SELECT id FROM orders WHERE marketplace_id={$id}")->fetchAll(PDO::FETCH_COLUMN);
$orderIds = array_map('intval', $orderIds);
$inList = $orderIds ? implode(',', $orderIds) : '';

// FK-child tablolar
$ordersChildren = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE REFERENCED_TABLE_NAME='orders' AND REFERENCED_COLUMN_NAME='id' AND TABLE_SCHEMA=DATABASE()"
)->fetchAll(PDO::FETCH_ASSOC);
$mpChildren = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
     WHERE REFERENCED_TABLE_NAME='marketplaces' AND REFERENCED_COLUMN_NAME='id' AND TABLE_SCHEMA=DATABASE()"
)->fetchAll(PDO::FETCH_ASSOC);

echo "oziygo id={$id} · sipariş=" . count($orderIds) . " · orders-child=" . count($ordersChildren) . " · mp-child=" . count($mpChildren) . "\n";

$pdo->beginTransaction();
try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // 1) oziygo siparişlerinin alt kayıtları (order_items, vb.)
    if ($inList !== '') {
        foreach ($ordersChildren as $c) {
            $n = $pdo->exec("DELETE FROM `{$c['TABLE_NAME']}` WHERE `{$c['COLUMN_NAME']}` IN ({$inList})");
            if ($n) { echo "  - {$c['TABLE_NAME']}.{$c['COLUMN_NAME']} (sipariş alt): {$n} silindi\n"; }
        }
    }

    // 2) marketplaces'e bağlı tüm child satırlar (orders dahil → 2 sipariş burada silinir)
    foreach ($mpChildren as $c) {
        $n = $pdo->exec("DELETE FROM `{$c['TABLE_NAME']}` WHERE `{$c['COLUMN_NAME']}` = {$id}");
        if ($n) { echo "  - {$c['TABLE_NAME']}.{$c['COLUMN_NAME']}: {$n} silindi\n"; }
    }

    // 3) pazaryeri satırı
    $del = $pdo->exec("DELETE FROM marketplaces WHERE id={$id}");

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->commit();
    echo "OK: oziygo + bağlı tüm kayıtlar SİLİNDİ (marketplaces: {$del} satır).\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo "HATA (geri alındı): " . $e->getMessage() . "\n";
}

// Doğrula
$check = $pdo->query("SELECT COUNT(*) FROM marketplaces WHERE slug='oziygo'")->fetchColumn();
echo "Doğrulama: marketplaces'te oziygo = {$check} (0 olmalı).\n";
