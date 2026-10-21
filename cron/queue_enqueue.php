<?php
/**
 * OzyConnect Panel — Kuyruk Enqueuer (Faz 6)
 *
 * Periyodik sync işlerini AKTİF mağaza başına kuyruğa ekler. Faz 4'teki inline-sync
 * cron'larının yeni rolü: artık sync'i kendileri çalıştırmaz; bu enqueuer per-store iş
 * üretir, worker'lar paralel tüketir (yüzlerce mağaza için ölçeklenir).
 *
 * Bir mağaza için iş YALNIZCA şunlar birlikte sağlanırsa eklenir (JobRunner::periodicSyncPlan):
 *  - platform anahtarı açık   (settings.sync_{tip}_enabled; yoksa açık)
 *  - mağaza anahtarı açık     (store_settings.sync_{tip}_enabled; yoksa açık)
 *  - mağazanın paketinde ilgili modül var (sipariş/iade/soru çekme senkronları)
 *  - mağaza aralığı dolmuş    (son tamamlanan koşu: sync_jobs 'done' / sync_logs)
 *
 * enqueueUnique → aynı (store, tip) için bekleyen/çalışan iş varsa yeni eklenmez
 * (her 5dk'da bir tetiklense bile kuyruk şişmez).
 *
 * Kullanım:
 *   php cron/queue_enqueue.php                    # tüm tipler
 *   php cron/queue_enqueue.php --type=order       # yalnız sipariş (order | order_sync | orders)
 *
 * Crontab örneği (worker ayrı çalışır):
 *   *\/5 * * * * php cron/queue_enqueue.php
 *
 * @package OzyConnect
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\JobRunner;
use App\Services\Queue\QueueService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    fwrite(STDERR, "[ERROR] Container yüklenemedi\n");
    exit(1);
}

// İsteğe bağlı DB override (staging/test). Verilmezse .env'deki DB kullanılır.
// KRİTİK: Database config'i ilk get()'te (config/database.php require) okunur → override
// MUTLAKA herhangi bir $container->get() çağrısından ÖNCE uygulanmalı.
$opts = getopt('', ['type::', 'database::']);
$onlyType = isset($opts['type']) ? (string) $opts['type'] : null;
if (isset($opts['database']) && $opts['database'] !== '') {
    $dbName = (string) $opts['database'];
    $_ENV['DB_NAME'] = $dbName; $_SERVER['DB_NAME'] = $dbName; putenv('DB_NAME=' . $dbName);
}

$selected = array_filter(
    JobRunner::PERIODIC_JOB_TYPES,
    static fn(string $syncType, string $jobType): bool => $onlyType === null
        || in_array($onlyType, [$jobType, str_replace('_sync', '', $jobType), $syncType], true),
    ARRAY_FILTER_USE_BOTH
);
if ($selected === []) {
    fwrite(STDERR, "[ERROR] Bilinmeyen --type: {$onlyType}\n");
    exit(1);
}

$queue  = $container->get(QueueService::class);
$db     = $container->get(\App\Core\Database::class);
$runner = $container->get(JobRunner::class);

// Aktif mağazalar (cross-tenant infra — TenantContext'siz, store_id açıkça taşınır).
$stores = $db->fetchAll("SELECT id FROM stores WHERE status = 'active' ORDER BY id");

echo "[" . date('Y-m-d H:i:s') . "] Enqueue başlıyor — " . count($stores) . " aktif mağaza\n";

$created  = 0;
$existing = 0;
$skipped  = ['platform_disabled' => 0, 'store_disabled' => 0, 'module' => 0, 'interval' => 0];
$errors   = 0;

foreach ($stores as $s) {
    $storeId = (int) $s['id'];
    try {
        // Bir mağazanın ayar/plan hatası diğer mağazaları durdurmaz.
        $plan = $runner->periodicSyncPlan($storeId, array_values($selected));
        foreach ($selected as $jobType => $syncType) {
            $decision = $plan[$syncType];
            if (!$decision['due']) {
                $skipped[$decision['reason']] = ($skipped[$decision['reason']] ?? 0) + 1;
                continue;
            }
            $r = $queue->enqueueUnique($storeId, $jobType);
            if ($r['created']) {
                $created++;
            } else {
                $existing++;
            }
        }
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, "  [HATA] store#{$storeId}: " . $e->getMessage() . "\n");
    }
}

printf(
    "[OK] Enqueue tamamlandı — %d yeni iş, %d zaten kuyrukta; atlanan: %d aralık dolmadı, %d mağazada kapalı, %d platformda kapalı, %d pakette modül yok; %d mağaza hatası.\n",
    $created,
    $existing,
    $skipped['interval'],
    $skipped['store_disabled'],
    $skipped['platform_disabled'],
    $skipped['module'],
    $errors
);
exit($errors > 0 && $errors === count($stores) ? 1 : 0);
