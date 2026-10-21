<?php
// OzyConnect Panel — Cron: Sipariş Senkronizasyonu
// Kullanım: php cron/sync_orders.php [--force]   (--force: mağaza aralığını yok sayar, anahtarlara uyar)
// Crontab: Her 5 dakikada bir — her mağaza kendi /sync anahtarı ve aralığıyla çalışır.
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\JobRunner;
use App\Services\Sync\OrderSyncService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$syncService = $container->get(OrderSyncService::class);
$runner      = $container->get(JobRunner::class);
$force       = in_array('--force', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] Sipariş sync başlıyor (per-store)...\n";

// PER-STORE: TenantContext + entitlement'li pazaryerleri; platform VE mağaza anahtarı,
// mağaza aralığı ve tip kilidi JobRunner'da. Bir mağaza hatası diğerlerini durdurmaz.
$summary = $runner->runPeriodicForActiveStores(
    'orders',
    static fn(int $storeId): array => $syncService->syncAll('cron'),
    $force
);

foreach (JobRunner::summaryLines($summary) as $line) {
    echo $line, "\n";
}
exit(JobRunner::exitCode($summary));
