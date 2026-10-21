<?php
// OzyConnect Panel — Cron: Pazaryeri Delta Import
// Kullanım: php cron/sync_import_delta.php
// Crontab: Her 6 saatte bir
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Models\Marketplace;
use App\Services\Import\ProductImportService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) { echo "[ERROR] Container yüklenemedi\n"; exit(1); }

$mpModel = $container->get(Marketplace::class);
$importService = $container->get(ProductImportService::class);
$cronRunner = $container->get(\App\Services\TenantCronRunner::class);
$logger = $container->get(\App\Core\Logger::class);

echo "[" . date('Y-m-d H:i:s') . "] Delta import başlıyor (per-store)...\n";

// Faz 4 — PER-STORE: her mağaza kendi TenantContext'inde, yalnız entitlement'i olan pazaryerleri.
$storeResults = $cronRunner->runForActiveStores(function (int $storeId) use ($mpModel, $importService, $logger): array {
    $out = [];
    foreach ($mpModel->getActiveForStore($storeId) as $mp) {
        try {
            $r = $importService->importFromMarketplace((int) $mp['id'], $mp['slug'], 'delta', 0);
            $out[$mp['slug']] = "created={$r->created}, updated={$r->updated}, failed={$r->failed}";
        } catch (\Throwable $e) {
            $out[$mp['slug']] = 'ERROR - ' . $e->getMessage();
            $logger->error("Delta import [store#{$storeId}/{$mp['slug']}]: " . $e->getMessage(), 'Cron');
        }
    }
    return $out;
});

foreach ($storeResults as $storeId => $sr) {
    if (!($sr['ok'] ?? false)) { echo "  store#{$storeId}: [HATA] " . ($sr['error'] ?? '?') . "\n"; continue; }
    foreach (($sr['result'] ?? []) as $slug => $line) {
        echo "  store#{$storeId} {$slug}: {$line}\n";
    }
}
echo "[OK] Delta import tamamlandı (" . count($storeResults) . " mağaza).\n";
