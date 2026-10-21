<?php
/**
 * OzyConnect Panel — Kuyruk Worker'ı (Faz 6)
 *
 * `sync_jobs` kuyruğundan işleri ATOMİK claim edip JobRunner ile çalıştırır.
 * PARALEL: birden çok worker süreci aynı anda çalıştırılabilir (atomik claim çift
 * işlemeyi engeller). FAIL RETRY + exponential backoff + dead-letter QueueService'te.
 *
 * Kullanım:
 *   php cron/queue_worker.php                       # sürekli (poll 5sn)
 *   php cron/queue_worker.php --once                # tek tur (boşsa hemen çıkar; cron-driven)
 *   php cron/queue_worker.php --max-jobs=50         # 50 iş sonra çık
 *   php cron/queue_worker.php --max-seconds=300     # 5dk sonra çık (cron + supervisor için ideal)
 *   php cron/queue_worker.php --poll=2 --worker-id=w1
 *
 * Paralel için (ör. systemd/supervisor ya da basit crontab):
 *   * * * * * php cron/queue_worker.php --max-seconds=55 --worker-id=w1
 *   * * * * * php cron/queue_worker.php --max-seconds=55 --worker-id=w2
 *
 * @package OzyConnect
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/public/index.php';

use App\Services\Queue\QueueService;
use App\Services\Queue\JobRunner;
use App\Services\Queue\JobEntitlementException;
use App\Services\Queue\WorkerHeartbeatService;

$container = $GLOBALS['container'] ?? null;
if ($container === null) {
    fwrite(STDERR, "[ERROR] Container yüklenemedi\n");
    exit(1);
}

// ── Argümanlar ──
$opts = getopt('', ['once', 'max-jobs::', 'max-seconds::', 'poll::', 'worker-id::', 'recover-after::', 'database::']);

// İsteğe bağlı DB override (staging/test için). VERİLMEZSE .env'deki DB kullanılır.
// Database::__construct config/database.php'yi okur; ilk DB-get'ten ÖNCE env'i set ediyoruz.
if (isset($opts['database']) && $opts['database'] !== '') {
    $dbName = (string) $opts['database'];
    $_ENV['DB_NAME'] = $dbName; $_SERVER['DB_NAME'] = $dbName; putenv('DB_NAME=' . $dbName);
}

$once        = isset($opts['once']);
$maxJobs     = isset($opts['max-jobs']) ? (int) $opts['max-jobs'] : 0;       // 0 = sınırsız
$maxSeconds  = isset($opts['max-seconds']) ? (int) $opts['max-seconds'] : 0; // 0 = sınırsız
$poll        = isset($opts['poll']) ? max(1, (int) $opts['poll']) : 5;
$recoverAfter = isset($opts['recover-after']) ? (int) $opts['recover-after'] : 900;
$workerId    = (string) ($opts['worker-id'] ?? (gethostname() . '-' . getmypid()));

$queue     = $container->get(QueueService::class);
$runner    = $container->get(JobRunner::class);
$heartbeat = $container->get(WorkerHeartbeatService::class);
$logger    = $container->get(\App\Core\Logger::class);

$hostname  = gethostname() ?: null;
$pid       = getmypid() ?: null;
$startedAt = time();
$processed = 0;
$idleCycles = 0;

fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] Worker '{$workerId}' başladı"
    . ($once ? " (--once)" : " (poll={$poll}s)") . "\n");

// İlk heartbeat (dashboard'da hemen görünsün).
$heartbeat->beat($workerId, $hostname, $pid, 0, null, 'running');

// Graceful shutdown: SIGTERM/SIGINT (systemd/supervisor stop) → durdurulmuş işaretle.
$shuttingDown = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$shuttingDown) { $shuttingDown = true; };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

// Başlangıçta çökmüş worker'lardan kalan takılı işleri kurtar.
$recovered = $queue->recoverStuck($recoverAfter);
if ($recovered > 0) {
    fwrite(STDOUT, "  [RECOVER] {$recovered} takılı iş yeniden kuyruğa alındı\n");
}

while (true) {
    if ($shuttingDown) {
        fwrite(STDOUT, "  [STOP] sinyal alındı — graceful kapanış\n");
        break;
    }
    if ($maxSeconds > 0 && (time() - $startedAt) >= $maxSeconds) {
        fwrite(STDOUT, "  [STOP] max-seconds ({$maxSeconds}) doldu\n");
        break;
    }

    $job = $queue->claim($workerId);

    if ($job === null) {
        if ($once) {
            fwrite(STDOUT, "  [IDLE] kuyruk boş (--once) — çıkılıyor\n");
            break;
        }
        $idleCycles++;
        $heartbeat->beat($workerId, $hostname, $pid, $processed, null, 'idle'); // boşta da canlı
        // Boştayken ara sıra takılı işleri kurtar.
        if ($idleCycles % 12 === 0) {
            $queue->recoverStuck($recoverAfter);
        }
        sleep($poll);
        continue;
    }
    $idleCycles = 0;

    $jobId   = (int) $job['id'];
    $storeId = (int) $job['store_id'];
    $type    = (string) $job['job_type'];
    $heartbeat->beat($workerId, $hostname, $pid, $processed, $jobId, 'running'); // çalıştığı işi bildir

    try {
        $result = $runner->run($job);
        $queue->markDone($jobId);
        $summary = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string) $result;
        fwrite(STDOUT, "  [DONE] #{$jobId} store#{$storeId} {$type} → " . mb_substr($summary, 0, 200) . "\n");
    } catch (JobEntitlementException $e) {
        // TERMİNAL: entitlement reddi retry edilmez → doğrudan dead-letter.
        $queue->markDead($jobId, $e->getMessage());
        $logger->warning("Kuyruk entitlement reddi #{$jobId} store#{$storeId} {$type}: " . $e->getMessage(), 'Queue');
        fwrite(STDOUT, "  [DEAD] #{$jobId} store#{$storeId} {$type} (entitlement yok)\n");
    } catch (\Throwable $e) {
        $outcome = $queue->markFailed($jobId, $e->getMessage());
        $logger->error("Kuyruk iş hatası #{$jobId} store#{$storeId} {$type}: " . $e->getMessage(), 'Queue');
        if ($outcome['status'] === 'dead') {
            fwrite(STDOUT, "  [DEAD] #{$jobId} store#{$storeId} {$type} ({$outcome['attempts']} deneme tükendi)\n");
        } else {
            fwrite(STDOUT, "  [RETRY] #{$jobId} store#{$storeId} {$type} → deneme {$outcome['attempts']}, sonraki: {$outcome['next_run_at']}\n");
        }
    }

    $processed++;
    $heartbeat->beat($workerId, $hostname, $pid, $processed, null, 'running'); // iş bitti sayacı güncelle
    if ($maxJobs > 0 && $processed >= $maxJobs) {
        fwrite(STDOUT, "  [STOP] max-jobs ({$maxJobs}) doldu\n");
        break;
    }
}

// Graceful shutdown — dashboard'da "stopped" görünsün (ölü/çökmüş ayrımı için).
$heartbeat->markStopped($workerId, $processed);
fwrite(STDOUT, "[" . date('Y-m-d H:i:s') . "] Worker '{$workerId}' bitti — {$processed} iş işlendi.\n");
exit(0);
