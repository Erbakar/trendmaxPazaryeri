<?php
// OzyConnect Panel — Cron: Soru & Cevap Senkronizasyonu
// Kullanım: php cron/sync_questions.php [--force]   (--force: mağaza aralığını yok sayar, anahtarlara uyar)
// Crontab: Her 15 dakikada bir — her mağaza kendi /sync anahtarı ve aralığıyla çalışır.
// Faz 6: Mantık QuestionSyncService'e taşındı (cron + kuyruk tek kaynaktan çağırır).
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\JobRunner;
use App\Services\Sync\QuestionSyncService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    echo "[ERROR] Container yüklenemedi\n";
    exit(1);
}

$questionSync = $container->get(QuestionSyncService::class);
$runner       = $container->get(JobRunner::class);
$force        = in_array('--force', $argv ?? [], true);

echo "[" . date('Y-m-d H:i:s') . "] Soru sync başlıyor (per-store)...\n";

// PER-STORE: her aktif mağaza kendi TenantContext + entitlement'li pazaryerleriyle; izole hata.
$summary = $runner->runPeriodicForActiveStores(
    'questions',
    static fn(int $storeId): array => $questionSync->syncAll('cron'),
    $force
);

foreach (JobRunner::summaryLines($summary) as $line) {
    echo $line, "\n";
}
exit(JobRunner::exitCode($summary));
