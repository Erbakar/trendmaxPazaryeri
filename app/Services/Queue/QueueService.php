<?php
/**
 * OzyConnect Panel — QueueService (Faz 6)
 *
 * `sync_jobs` kuyruğunu yönetir: enqueue + ATOMİK claim + done/failed + exponential
 * backoff + dead-letter + gözlemlenebilirlik (stats).
 *
 * MİMARİ NOTU — bu servis bilinçli olarak CROSS-TENANT altyapıdır (migration runner /
 * TenantCronRunner gibi). `store_id`'yi HER zaman AÇIKÇA yazar/okur; tenant-scoped
 * BaseModel KULLANMAZ (worker tüm mağazaların işlerini görüp claim eder, sonra job'ın
 * store_id'siyle TenantContext kurar). Tenant izolasyonu JOB ÇALIŞIRKEN (JobRunner)
 * TenantContext + entitlement ile uygulanır — kuyruğun kendisi taşıyıcıdır.
 *
 * Atomik claim (MariaDB 10.3, SKIP LOCKED yok): tek-satır UPDATE ile benzersiz claim
 * token; affectedRows=1 ise o satır bu worker'ındır. İki worker aynı anda → InnoDB satır
 * kilidi serileştirir, ÇİFT İŞLEME OLMAZ.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Queue;

use App\Core\Database;

final class QueueService
{
    /** Desteklenen job tipleri (JobRunner ile senkron). */
    public const JOB_TYPES = [
        'order_sync',
        'return_sync',
        'stock_sync',
        'price_sync',
        'question_sync',
        'import',
        'saas_invoice_earsiv',
    ];

    /** Backoff taban (saniye) ve tavan. attempt N → base * 2^(N-1), tavanla sınırlı. */
    private const BACKOFF_BASE_SECONDS = 60;
    private const BACKOFF_MAX_SECONDS  = 3600;

    public function __construct(
        private readonly Database $db,
    ) {}

    /**
     * Bir mağaza için job kuyruğa ekler (status=pending, hemen çalışmaya hazır).
     *
     * @param array<string,mixed> $payload  job'a özel veri (ör. import için marketplace slug)
     * @return int  oluşturulan job id
     */
    public function enqueue(
        int $storeId,
        string $jobType,
        ?int $marketplaceId = null,
        array $payload = [],
        int $maxAttempts = 3,
        ?int $enqueuedBy = null,
    ): int {
        $this->assertStore($storeId);
        $this->assertJobType($jobType);
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts >= 1 olmalı');
        }

        $this->db->query(
            "INSERT INTO sync_jobs
                (store_id, marketplace_id, job_type, payload_json, status, attempts, max_attempts,
                 next_run_at, worker_id, enqueued_by, created_at, updated_at)
             VALUES
                (:store_id, :marketplace_id, :job_type, :payload, 'pending', 0, :max_attempts,
                 NOW(), NULL, :enqueued_by, NOW(), NOW())",
            [
                'store_id'       => $storeId,
                'marketplace_id' => $marketplaceId,
                'job_type'       => $jobType,
                'payload'        => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'max_attempts'   => $maxAttempts,
                'enqueued_by'    => $enqueuedBy,
            ]
        );
        return (int) $this->db->lastInsertId();
    }

    /**
     * Aynı (store, job_type[, marketplace]) için bekleyen/çalışan job VARSA ekleme yapmaz
     * (cron her tetiklemede iş yığmasın). Mevcut job id'sini, yoksa yeni id'yi döner.
     *
     * @return array{id:int, created:bool}
     */
    public function enqueueUnique(
        int $storeId,
        string $jobType,
        ?int $marketplaceId = null,
        array $payload = [],
        int $maxAttempts = 3,
        ?int $enqueuedBy = null,
        bool $afterRunning = false,
    ): array {
        $this->assertStore($storeId);
        $this->assertJobType($jobType);

        // A fresh XML snapshot must get a follow-up even if a stock job has
        // already read the previous snapshot. Reuse a pending follow-up only.
        $statuses = $afterRunning ? "'pending'" : "'pending','running'";
        $existing = $this->db->fetchOne(
            "SELECT id FROM sync_jobs
              WHERE store_id = :store_id AND job_type = :job_type
                AND status IN ({$statuses})
                AND ((:mp IS NULL AND marketplace_id IS NULL) OR marketplace_id = :mp2)
              ORDER BY id LIMIT 1",
            ['store_id' => $storeId, 'job_type' => $jobType, 'mp' => $marketplaceId, 'mp2' => $marketplaceId]
        );
        if ($existing !== false) {
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        return ['id' => $this->enqueue($storeId, $jobType, $marketplaceId, $payload, $maxAttempts, $enqueuedBy), 'created' => true];
    }

    /**
     * Sıradaki çalışmaya hazır (pending + next_run_at geçmiş) işi ATOMİK olarak claim eder.
     * Benzersiz claim token → iki worker asla aynı işi almaz.
     *
     * @param string $workerId  worker kimliği (log/izleme için; token'a önek olur)
     * @return array<string,mixed>|null  claim edilen job satırı, yoksa null
     */
    public function claim(string $workerId): ?array
    {
        $claimToken = substr($workerId, 0, 24) . '-' . bin2hex(random_bytes(6)); // benzersiz, <=40 char

        $this->db->query(
            "UPDATE sync_jobs
                SET status = 'running', worker_id = :token, started_at = NOW(), updated_at = NOW()
              WHERE status = 'pending' AND (next_run_at IS NULL OR next_run_at <= NOW())
              ORDER BY id
              LIMIT 1",
            ['token' => $claimToken]
        );

        $row = $this->db->fetchOne(
            "SELECT * FROM sync_jobs WHERE worker_id = :token AND status = 'running' LIMIT 1",
            ['token' => $claimToken]
        );
        return $row === false ? null : $row;
    }

    /** İşi başarıyla tamamlandı işaretle. */
    public function markDone(int $jobId): void
    {
        $this->db->query(
            "UPDATE sync_jobs SET status = 'done', finished_at = NOW(), updated_at = NOW(), error_message = NULL
              WHERE id = :id",
            ['id' => $jobId]
        );
    }

    /**
     * İşi başarısız işaretle: attempt++ → max'a ulaşmadıysa backoff ile yeniden 'pending',
     * ulaştıysa 'dead' (dead-letter). worker_id serbest bırakılır.
     *
     * @return array{status:string, attempts:int, next_run_at:?string}
     */
    public function markFailed(int $jobId, string $error): array
    {
        $job = $this->db->fetchOne("SELECT attempts, max_attempts FROM sync_jobs WHERE id = :id", ['id' => $jobId]);
        if ($job === false) {
            return ['status' => 'missing', 'attempts' => 0, 'next_run_at' => null];
        }
        $attempts = (int) $job['attempts'] + 1;
        $max      = (int) $job['max_attempts'];
        $err      = mb_substr($error, 0, 2000);

        if ($attempts >= $max) {
            $this->db->query(
                "UPDATE sync_jobs
                    SET status='dead', attempts=:a, worker_id=NULL, finished_at=NOW(), updated_at=NOW(), error_message=:e
                  WHERE id=:id",
                ['a' => $attempts, 'e' => $err, 'id' => $jobId]
            );
            return ['status' => 'dead', 'attempts' => $attempts, 'next_run_at' => null];
        }

        $backoff = $this->backoffSeconds($attempts);
        $this->db->query(
            "UPDATE sync_jobs
                SET status='pending', attempts=:a, worker_id=NULL, started_at=NULL,
                    next_run_at = DATE_ADD(NOW(), INTERVAL :secs SECOND), updated_at=NOW(), error_message=:e
              WHERE id=:id",
            ['a' => $attempts, 'secs' => $backoff, 'e' => $err, 'id' => $jobId]
        );
        $next = $this->db->fetchOne("SELECT next_run_at FROM sync_jobs WHERE id=:id", ['id' => $jobId]);
        return ['status' => 'pending', 'attempts' => $attempts, 'next_run_at' => $next['next_run_at'] ?? null];
    }

    /** İşi doğrudan dead-letter'a al (TERMİNAL — entitlement reddi gibi retry edilmeyecek durumlar). */
    public function markDead(int $jobId, string $error): void
    {
        $this->db->query(
            "UPDATE sync_jobs
                SET status='dead', worker_id=NULL, finished_at=NOW(), updated_at=NOW(), error_message=:e
              WHERE id=:id",
            ['e' => mb_substr($error, 0, 2000), 'id' => $jobId]
        );
    }

    /**
     * Dashboard için iş listesi (duruma göre). $status null ise tümü.
     * @return array<int,array<string,mixed>>
     */
    public function recentJobs(?string $status = null, int $limit = 50): array
    {
        // $limit tipli int + clamp + explicit (int) → LIMIT enjeksiyonu imkânsız (PDO LIMIT'i bind etmez).
        $limit = (int) max(1, min(500, $limit));
        if ($status !== null) {
            return $this->db->fetchAll(
                "SELECT * FROM sync_jobs WHERE status = :s ORDER BY updated_at DESC, id DESC LIMIT {$limit}",
                ['s' => $status]
            );
        }
        return $this->db->fetchAll("SELECT * FROM sync_jobs ORDER BY updated_at DESC, id DESC LIMIT {$limit}");
    }

    /** Dead-letter listesi (inceleme için). */
    public function deadJobs(int $limit = 100): array
    {
        return $this->recentJobs('dead', $limit);
    }

    /** Tamamlanmış eski işleri temizle (kuyruk tablosu şişmesin). @return int silinen sayısı */
    public function purgeCompleted(int $olderThanSeconds = 604800): int
    {
        $stmt = $this->db->query(
            "DELETE FROM sync_jobs
              WHERE status = 'done' AND finished_at < DATE_SUB(NOW(), INTERVAL :secs SECOND)",
            ['secs' => $olderThanSeconds]
        );
        return $stmt->rowCount();
    }

    /** Tüm dead-letter işlerini yeniden kuyruğa al. @return int yeniden kuyruğa alınan sayısı */
    public function retryAllDead(): int
    {
        $stmt = $this->db->query(
            "UPDATE sync_jobs
                SET status='pending', attempts=0, worker_id=NULL, started_at=NULL, finished_at=NULL,
                    next_run_at=NOW(), updated_at=NOW(), error_message=NULL
              WHERE status='dead'"
        );
        return $stmt->rowCount();
    }

    /** Dead-letter'daki işi yeniden kuyruğa al (attempts sıfırlanır). */
    public function retryDead(int $jobId): bool
    {
        $this->db->query(
            "UPDATE sync_jobs
                SET status='pending', attempts=0, worker_id=NULL, started_at=NULL, finished_at=NULL,
                    next_run_at=NOW(), updated_at=NOW(), error_message=NULL
              WHERE id=:id AND status='dead'",
            ['id' => $jobId]
        );
        return $this->db->fetchOne("SELECT status FROM sync_jobs WHERE id=:id", ['id' => $jobId])['status'] === 'pending';
    }

    /**
     * Çökmüş worker yüzünden 'running'de takılı işleri kurtar (eşikten eski → tekrar pending).
     * @return int  kurtarılan iş sayısı
     */
    public function recoverStuck(int $olderThanSeconds = 900): int
    {
        $stmt = $this->db->query(
            "UPDATE sync_jobs
                SET status='pending', worker_id=NULL, started_at=NULL, next_run_at=NOW(), updated_at=NOW()
              WHERE status='running' AND started_at < DATE_SUB(NOW(), INTERVAL :secs SECOND)",
            ['secs' => $olderThanSeconds]
        );
        return $stmt->rowCount();
    }

    /**
     * Gözlemlenebilirlik: durum sayıları + bekleyen yaş + hata oranı.
     * $storeId verilirse o mağazaya filtreler (tenant-scoped panel için).
     *
     * @return array<string,mixed>
     */
    public function stats(?int $storeId = null): array
    {
        $where = $storeId !== null ? "WHERE store_id = :store_id" : "";
        $params = $storeId !== null ? ['store_id' => $storeId] : [];

        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS n FROM sync_jobs {$where} GROUP BY status",
            $params
        );
        $byStatus = ['pending' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'dead' => 0];
        foreach ($rows as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }

        $dueWhere = $where === "" ? "WHERE" : "{$where} AND";
        $due = $this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM sync_jobs {$dueWhere} status='pending' AND (next_run_at IS NULL OR next_run_at <= NOW())",
            $params
        );
        $oldest = $this->db->fetchOne(
            "SELECT MIN(created_at) AS oldest FROM sync_jobs {$dueWhere} status='pending'",
            $params
        );

        $total     = array_sum($byStatus);
        $completed = $byStatus['done'] + $byStatus['dead'];
        $errorRate = $completed > 0 ? round($byStatus['dead'] / $completed * 100, 2) : 0.0;

        return [
            'by_status'        => $byStatus,
            'total'            => $total,
            'pending_due_now'  => (int) ($due['n'] ?? 0),
            'oldest_pending'   => $oldest['oldest'] ?? null,
            'dead_letter'      => $byStatus['dead'],
            'error_rate_pct'   => $errorRate, // dead / (done+dead)
        ];
    }

    /** Exponential backoff: attempt N → base * 2^(N-1), tavanla sınırlı. */
    public function backoffSeconds(int $attempt): int
    {
        $secs = self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt - 1));
        return (int) min($secs, self::BACKOFF_MAX_SECONDS);
    }

    private function assertStore(int $storeId): void
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException('Geçerli store_id zorunlu (job mutlaka bir mağazaya ait olmalı)');
        }
    }

    private function assertJobType(string $jobType): void
    {
        if (!in_array($jobType, self::JOB_TYPES, true)) {
            throw new \InvalidArgumentException("Bilinmeyen job_type: {$jobType}");
        }
    }
}
