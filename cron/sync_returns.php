<?php
// OzyConnect Panel — Cron: İade Senkronizasyonu
// Kullanım: php cron/sync_returns.php [--force]   (--force: mağaza aralığını yok sayar, anahtarlara uyar)
// Crontab: Her 15 dakikada bir — her mağaza kendi /sync anahtarı ve aralığıyla çalışır.
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\JobRunner;
use App\Services\Sync\ReturnSyncService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$syncService = $container->get(ReturnSyncService::class);
$runner      = $container->get(JobRunner::class);
$force       = in_array('--force', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] İade sync başlıyor (per-store)...\n";

// PER-STORE: her aktif mağaza kendi TenantContext + entitlement'iyle; izole hata;
// platform VE mağaza anahtarı + mağaza aralığı JobRunner'da uygulanır.
$summary = $runner->runPeriodicForActiveStores(
    'returns',
    static fn(int $storeId): array => $syncService->syncAll('cron'),
    $force
);

foreach (JobRunner::summaryLines($summary) as $line) {
    echo $line, "\n";
}
exit(JobRunner::exitCode($summary));
