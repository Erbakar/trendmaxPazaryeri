<?php
/**
 * OzyConnect Panel — SystemHealthService (Faz 7)
 *
 * Sistem sağlığını TEK snapshot'ta toplar: DB bağlantısı, queue durumu, worker canlılığı,
 * cron tazeliği, API hata oranı. Ops dashboard + sağlık endpoint'i bunu kullanır.
 * Cross-tenant gözlem (store_id'siz) — yalnız okur, hiçbir tenant verisini değiştirmez.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\Queue\QueueService;
use App\Services\Queue\WorkerHeartbeatService;

final class SystemHealthService
{
    /** Eşikler. */
    private const CRON_STALE_SECONDS   = 1800;  // 30 dk içinde enqueue/sync yoksa "bayat"
    private const API_WINDOW_MINUTES   = 15;
    private const API_ERROR_RATE_WARN  = 5.0;   // %
    private const QUEUE_OLDEST_WARN_SEC = 600;  // bekleyen en eski iş 10 dk'dan eskiyse uyarı
    private const QUEUE_ERROR_RATE_WARN = 10.0; // %

    public function __construct(
        private readonly Database $db,
        private readonly QueueService $queue,
        private readonly WorkerHeartbeatService $workers,
    ) {}

    /**
     * Tam sağlık snapshot'ı.
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $db    = $this->dbHealth();
        $queue = $this->queueHealth();
        $work  = $this->workerHealth();
        $cron  = $this->cronHealth();
        $api   = $this->apiHealth();

        // Genel durum: bileşenlerin en kötüsü.
        $levels = [$db['level'], $queue['level'], $work['level'], $cron['level'], $api['level']];
        $overall = in_array('down', $levels, true) ? 'down'
                 : (in_array('degraded', $levels, true) ? 'degraded' : 'ok');

        return [
            'overall' => $overall,
            'db'      => $db,
            'queue'   => $queue,
            'workers' => $work,
            'cron'    => $cron,
            'api'     => $api,
        ];
    }

    private function dbHealth(): array
    {
        // Not: ping() statik instance null'ken (bağlantı tembel kurulmadan) false döner;
        // bu yüzden gerçek bir sorguyla doğrularız — sorgu başarılıysa bağlantı AÇIKTIR.
        try {
            $row = $this->db->fetchOne("SELECT VERSION() AS v, DATABASE() AS db");
            $connected = $row !== false;
            return [
                'level'    => $connected ? 'ok' : 'down',
                'connected'=> $connected,
                'version'  => $row['v'] ?? null,
                'database' => $row['db'] ?? null,
            ];
        } catch (\Throwable $e) {
            return ['level' => 'down', 'connected' => false, 'error' => $e->getMessage()];
        }
    }

    private function queueHealth(): array
    {
        $stats = $this->queue->stats();
        $oldestAgeSec = null;
        if (!empty($stats['oldest_pending'])) {
            $oldestAgeSec = max(0, time() - strtotime((string) $stats['oldest_pending']));
        }
        $level = 'ok';
        $reasons = [];
        if ($stats['error_rate_pct'] >= self::QUEUE_ERROR_RATE_WARN) {
            $level = 'degraded'; $reasons[] = "hata oranı %{$stats['error_rate_pct']}";
        }
        if ($stats['dead_letter'] > 0) {
            $level = 'degraded'; $reasons[] = "{$stats['dead_letter']} dead-letter";
        }
        if ($oldestAgeSec !== null && $oldestAgeSec >= self::QUEUE_OLDEST_WARN_SEC) {
            $level = 'degraded'; $reasons[] = "en eski bekleyen {$oldestAgeSec}s";
        }
        return [
            'level'            => $level,
            'by_status'        => $stats['by_status'],
            'pending_due_now'  => $stats['pending_due_now'],
            'dead_letter'      => $stats['dead_letter'],
            'error_rate_pct'   => $stats['error_rate_pct'],
            'oldest_pending_sec' => $oldestAgeSec,
            'reasons'          => $reasons,
        ];
    }

    private function workerHealth(): array
    {
        $active  = $this->workers->activeCount();
        $pending = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) n FROM sync_jobs WHERE status='pending' AND (next_run_at IS NULL OR next_run_at <= NOW())"
        )['n'] ?? 0);
        // İş var ama aktif worker yoksa: degraded (işler beklemede kalır).
        $level = ($pending > 0 && $active === 0) ? 'degraded' : 'ok';
        return [
            'level'        => $level,
            'active_count' => $active,
            'pending_jobs' => $pending,
            'list'         => $this->workers->allWorkers(),
            'reasons'      => $level === 'degraded' ? ['bekleyen iş var ama aktif worker yok'] : [],
        ];
    }

    private function cronHealth(): array
    {
        $lastEnqueue = $this->db->fetchOne("SELECT MAX(created_at) AS t FROM sync_jobs")['t'] ?? null;
        $lastSync    = $this->db->fetchOne("SELECT MAX(started_at) AS t FROM sync_logs")['t'] ?? null;
        $mostRecent  = max((int) strtotime((string) $lastEnqueue), (int) strtotime((string) $lastSync));
        // max(0,...): saat kayması (clock skew) negatif yaş üretmesin.
        $ageSec = $mostRecent > 0 ? max(0, time() - $mostRecent) : null;

        // Per-tip son sync (gözlem). NOT: sync_logs.status enum = running/success/partial/failed
        // ('error' YOK) — başarısızlık 'failed' VEYA records_failed>0 ile sayılır.
        $perType = $this->db->fetchAll(
            "SELECT sync_type, MAX(started_at) AS last_run,
                    SUM(status='failed' OR records_failed > 0) AS recent_errors, COUNT(*) AS runs
               FROM sync_logs
              WHERE started_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
              GROUP BY sync_type"
        );

        $level = 'ok'; $reasons = [];
        if ($ageSec === null) {
            // Hiç çalışmamış olabilir (yeni kurulum) — degraded değil, "bilinmiyor".
            $level = 'ok'; $reasons[] = 'henüz çalışma kaydı yok';
        } elseif ($ageSec >= self::CRON_STALE_SECONDS) {
            $level = 'degraded'; $reasons[] = "son aktivite {$ageSec}s önce (eşik " . self::CRON_STALE_SECONDS . "s)";
        }
        return [
            'level'           => $level,
            'last_enqueue'    => $lastEnqueue,
            'last_sync'       => $lastSync,
            'last_activity_sec' => $ageSec,
            'per_type'        => $perType,
            'reasons'         => $reasons,
        ];
    }

    private function apiHealth(): array
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(status_code >= 400) AS errors,
                    SUM(status_code >= 500) AS server_errors,
                    ROUND(AVG(response_time_ms)) AS avg_ms
               FROM api_logs
              WHERE created_at >= DATE_SUB(NOW(), INTERVAL :mins MINUTE)",
            ['mins' => self::API_WINDOW_MINUTES]
        );
        $total  = (int) ($row['total'] ?? 0);
        $errors = (int) ($row['errors'] ?? 0);
        $rate   = $total > 0 ? round($errors / $total * 100, 2) : 0.0;

        $level = 'ok'; $reasons = [];
        if ($total > 0 && $rate >= self::API_ERROR_RATE_WARN) {
            $level = 'degraded'; $reasons[] = "API hata oranı %{$rate} (son " . self::API_WINDOW_MINUTES . " dk)";
        }
        return [
            'level'          => $level,
            'window_minutes' => self::API_WINDOW_MINUTES,
            'total_requests' => $total,
            'errors'         => $errors,
            'server_errors'  => (int) ($row['server_errors'] ?? 0),
            'error_rate_pct' => $rate,
            'avg_response_ms'=> (int) ($row['avg_ms'] ?? 0),
            'reasons'        => $reasons,
        ];
    }
}
