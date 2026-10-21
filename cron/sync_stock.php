<?php
// OzyConnect Panel — Cron: Stok & Fiyat Senkronizasyonu
// Kullanım: php cron/sync_stock.php [--force]   (--force: mağaza aralığını yok sayar, anahtarlara uyar)
// Crontab: Her 10 dakikada bir — stok ve fiyat, her mağazada kendi /sync anahtarı ve aralığıyla.
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\JobRunner;
use App\Services\Sync\StockSyncService;
use App\Services\Sync\PriceSyncService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$stockSync = $container->get(StockSyncService::class);
$priceSync = $container->get(PriceSyncService::class);
$runner    = $container->get(JobRunner::class);
$force     = in_array('--force', $argv ?? [], true);

// Zamanlanmış stok senkronu "otomatik stok yayını" anahtarından ETKİLENMEZ (Karar 2);
// yalnız sync_stock_enabled / sync_stock_interval ayarlarına uyar.
$jobs = [
    'stock' => ['Stok', static fn(int $storeId): array => $stockSync->syncAll('cron')],
    'price' => ['Fiyat', static fn(int $storeId): array => $priceSync->syncAll('cron')],
];

// Stok ya da fiyat tipinden biri toplu kesintideyse (JobRunner::exitCode) çıkış kodu 1.
$exitCode = 0;
foreach ($jobs as $type => [$label, $job]) {
    echo "[" . date('Y-m-d H:i:s') . "] {$label} sync başlıyor (per-store)...\n";
    $summary = $runner->runPeriodicForActiveStores($type, $job, $force);
    foreach (JobRunner::summaryLines($summary) as $line) {
        echo $line, "\n";
    }
    $exitCode = max($exitCode, JobRunner::exitCode($summary));
}
exit($exitCode);
