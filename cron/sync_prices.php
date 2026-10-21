<?php
// OzyConnect Panel — Cron: Fiyat Senkronizasyonu
// Kullanım: php cron/sync_prices.php [--force]   (--force: mağaza aralığını yok sayar, anahtarlara uyar)
// Crontab: Her 15 dakikada bir — her mağaza kendi /sync anahtarı ve aralığıyla çalışır.
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\JobRunner;
use App\Services\Sync\PriceSyncService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) { echo "[ERROR] Container yüklenemedi\n"; exit(1); }

$priceSync = $container->get(PriceSyncService::class);
$runner    = $container->get(JobRunner::class);
$force     = in_array('--force', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] Fiyat sync başlıyor (per-store)...\n";

// PER-STORE: izole hata, entitlement'li pazaryerleri, mağaza anahtarı + aralığı (JobRunner).
$summary = $runner->runPeriodicForActiveStores(
    'price',
    static fn(int $storeId): array => $priceSync->syncAll('cron'),
    $force
);

foreach (JobRunner::summaryLines($summary) as $line) {
    echo $line, "\n";
}
exit(JobRunner::exitCode($summary));
