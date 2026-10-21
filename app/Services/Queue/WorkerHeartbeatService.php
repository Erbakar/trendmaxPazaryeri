<?php
/**
 * OzyConnect Panel — WorkerHeartbeatService (Faz 7)
 *
 * Worker süreçlerinin canlılık/durum kaydı. Worker her döngüde beat() çağırır; ops
 * dashboard "aktif worker" = son heartbeat eşik içinde olanlar. Cross-tenant altyapı
 * (store_id yok) — QueueService gibi Database'i doğrudan kullanır.
 *
 * worker_id = STABİL kimlik (--worker-id ya da hostname-pid). Dikkat: bu, QueueService'in
 * claim'de ürettiği benzersiz claim TOKEN'ından FARKLIDIR (o her claim'de değişir).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Database;

final class WorkerHeartbeatService
{
    /** Aktif sayılmak için son heartbeat'in en fazla bu kadar saniye önce olması gerekir. */
    public const ACTIVE_THRESHOLD_SECONDS = 60;

    public function __construct(
        private readonly Database $db,
    ) {}

    /**
     * Heartbeat kaydı (UPSERT). started_at yalnız ilk kayıtta set edilir.
     */
    public function beat(
        string $workerId,
        ?string $hostname = null,
        ?int $pid = null,
        int $jobsProcessed = 0,
        ?int $currentJobId = null,
        string $status = 'running',
    ): void {
        $this->db->query(
            "INSERT INTO worker_heartbeats
                (worker_id, hostname, pid, status, jobs_processed, current_job_id,
                 started_at, last_heartbeat_at, created_at, updated_at)
             VALUES
                (:wid, :host, :pid, :status, :jp, :cj, NOW(), NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                pid = VALUES(pid),
                status = VALUES(status),
                jobs_processed = VALUES(jobs_processed),
                current_job_id = VALUES(current_job_id),
                last_heartbeat_at = NOW(),
                updated_at = NOW()",
            [
                'wid' => $workerId, 'host' => $hostname, 'pid' => $pid,
                'status' => $status, 'jp' => $jobsProcessed, 'cj' => $currentJobId,
            ]
        );
    }

    /** Worker'ı düzgün durduruldu işaretle (graceful shutdown). */
    public function markStopped(string $workerId, int $jobsProcessed = 0): void
    {
        $this->db->query(
            "UPDATE worker_heartbeats
                SET status='stopped', stopped_at=NOW(), last_heartbeat_at=NOW(),
                    jobs_processed=:jp, current_job_id=NULL, updated_at=NOW()
              WHERE worker_id=:wid",
            ['jp' => $jobsProcessed, 'wid' => $workerId]
        );
    }

    /**
     * Aktif worker'lar: durdurulmamış + son heartbeat eşik içinde.
     * @return array<int,array<string,mixed>>
     */
    public function activeWorkers(int $thresholdSeconds = self::ACTIVE_THRESHOLD_SECONDS): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM worker_heartbeats
              WHERE status <> 'stopped'
                AND last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL :secs SECOND)
              ORDER BY last_heartbeat_at DESC",
            ['secs' => $thresholdSeconds]
        );
    }

    /** Tüm worker kayıtları (durdurulmuş/ölü dahil) — son heartbeat'e göre. */
    public function allWorkers(): array
    {
        return $this->db->fetchAll(
            "SELECT *,
                    TIMESTAMPDIFF(SECOND, last_heartbeat_at, NOW()) AS seconds_since_heartbeat
               FROM worker_heartbeats
              ORDER BY last_heartbeat_at DESC"
        );
    }

    public function activeCount(int $thresholdSeconds = self::ACTIVE_THRESHOLD_SECONDS): int
    {
        return (int) ($this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM worker_heartbeats
              WHERE status <> 'stopped' AND last_heartbeat_at >= DATE_SUB(NOW(), INTERVAL :secs SECOND)",
            ['secs' => $thresholdSeconds]
        )['n'] ?? 0);
    }

    /** Eski durdurulmuş/ölü kayıtları temizle (tablo şişmesin). */
    public function prune(int $olderThanSeconds = 86400): int
    {
        $stmt = $this->db->query(
            "DELETE FROM worker_heartbeats
              WHERE last_heartbeat_at < DATE_SUB(NOW(), INTERVAL :secs SECOND)",
            ['secs' => $olderThanSeconds]
        );
        return $stmt->rowCount();
    }
}
